<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use App\Models\PermissionModule;
use App\Models\Cabang;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $validated = $request->validate([
                'username' => ['required', 'string'],
                'password' => ['required', 'string'],
            ], [
                'username.required' => 'Username wajib diisi.',
                'password.required' => 'Password wajib diisi.',
            ]);

            $username = trim($validated['username']);

            $user = User::query()
                ->where('username', $username)
                ->first();

            if (!$user) {
                activity('auth')
                    ->withProperties([
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'username' => $username,
                    ])
                    ->event('login_failed')
                    ->log("Percobaan login gagal: username \"{$username}\" tidak ditemukan.");

                return response()->json([
                    'success' => false,
                    'field' => 'username',
                    'message' => 'Username tidak ditemukan.',
                ], 422);
            }

            if (!Hash::check($validated['password'], $user->password)) {
                activity('auth')
                    ->causedBy($user)
                    ->withProperties([
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'username' => $username,
                    ])
                    ->event('login_failed')
                    ->log("Percobaan login gagal: password salah untuk username \"{$username}\".");

                return response()->json([
                    'success' => false,
                    'field' => 'password',
                    'message' => 'Password salah.',
                ], 422);
            }

            if (isset($user->is_active) && !$user->is_active) {
                activity('auth')
                    ->causedBy($user)
                    ->withProperties([
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'username' => $username,
                    ])
                    ->event('login_blocked')
                    ->log("Login ditolak: akun \"{$username}\" nonaktif.");

                return response()->json([
                    'success' => false,
                    'message' => 'User nonaktif.',
                ], 403);
            }

            $user->forceFill([
                'last_login_at' => now(),
            ])->save();

            $expiresAt = now()->addMinutes(
                (int) config('auth_session.absolute_timeout_minutes', 720),
            );

            $newToken = $user->createToken(
                'syop-v4',
                ['*'],
                $expiresAt,
            );

            Cache::put(
                'auth:last_activity:' . $newToken->accessToken->id,
                now()->toIso8601String(),
                now()->addMinutes(
                    (int) config('auth_session.absolute_timeout_minutes', 720) + 60,
                ),
            );

            $token = $newToken->plainTextToken;

            activity('auth')
                ->causedBy($user)
                ->withProperties([
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'username' => $username,
                ])
                ->event('login')
                ->log("User \"{$username}\" berhasil login.");

            return response()->json([
                'success' => true,
                'message' => 'Login berhasil.',
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[Auth] Login error', [
                'username' => $request->input('username'),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat login.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function me(Request $request)
    {
        // return response()->json($request->user());
        // $user = auth()->user()->load('roles');

        // return response()->json([
        //     'id' => $user->id,
        //     'name' => $user->name,
        //     'role' => $user->roles()->value('nama'), // atau 'code'
        // ]);
        $user = $request->user()->load([
            'roles:id,nama',
            'cabangData:id,nama_cabang,inisial_cabang',
            'departmentData:id,kode,nama',
        ]);

        $primaryRole = $user->roles->first();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,

                'role_id' => $primaryRole->id ?? null,
                'role' => $primaryRole->nama ?? null,
                'roles' => $user->roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->nama,
                    ];
                })->values(),

                'cabang_id' => $user->cabang_id,
                'cabang' => $user->cabangData
                    ? trim(($user->cabangData->inisial_cabang ?? '-') . ' - ' . ($user->cabangData->nama_cabang ?? '-'))
                    : null,

                'department_id' => $user->departemen_id,
                'department' => $user->departmentData
                    ? trim(($user->departmentData->kode ?? '-') . ' - ' . ($user->departmentData->nama ?? '-'))
                    : null,
            ],
        ]);
    }

    public function permissions(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi.',
                ], 401);
            }

            $modules = PermissionModule::query()
                ->where('is_active', true)
                ->whereNotNull('route_prefix')
                ->where('route_prefix', '<>', '')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get([
                    'id',
                    'code',
                    'name',
                    'route_prefix',
                    'sort_order',
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Permission user berhasil dimuat.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'permissions' => $user->getPermissionAbilities(),
                    'modules' => $modules,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[Auth Permission] Load error', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat permission user.',
            ], 500);
        }
    }


    public function logout(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan.',
                ], 401);
            }

            $user->forceFill([
                'last_logout_at' => now(),
            ])->save();

            $token = $user->currentAccessToken();

            if ($token) {
                $token->delete();
            }

            activity('auth')
                ->causedBy($user)
                ->withProperties([
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ])
                ->event('logout')
                ->log("User \"{$user->username}\" logout.");

            return response()->json([
                'success' => true,
                'message' => 'Logout berhasil.',
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Auth] Logout error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal logout.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email'],
            ], [
                'email.required' => 'Email wajib diisi.',
                'email.email' => 'Format email tidak valid.',
            ]);

            $email = trim($validated['email']);

            /*
            |--------------------------------------------------------------------------
            | Cek Email Terdaftar
            |--------------------------------------------------------------------------
            */
            $user = User::query()
                ->where('email', $email)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'field' => 'email',
                    'message' => 'Email tidak terdaftar.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Kirim Link Reset Password
            |--------------------------------------------------------------------------
            | Password::sendResetLink() sudah menangani throttle bawaan Laravel
            | (config('auth.passwords.users.throttle'), default 60 detik per email)
            | sebelum token baru benar-benar dibuat/dikirim.
            |--------------------------------------------------------------------------
            */
            $status = Password::sendResetLink([
                'email' => $email,
            ]);

            if ($status === Password::RESET_THROTTLED) {
                return response()->json([
                    'success' => false,
                    'throttled' => true,
                    'message' => 'Silakan tunggu beberapa saat sebelum mengirim ulang link reset password.',
                ], 429);
            }

            if ($status !== Password::RESET_LINK_SENT) {
                Log::warning('[Auth] Forgot password unexpected status', [
                    'email' => $email,
                    'status' => $status,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengirim link reset password. Silakan coba lagi.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Link reset password telah dikirim ke email Anda.',
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('[Auth] Forgot password error', [
                'email' => $request->input('email'),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses permintaan.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /**
     * Cek validitas token reset password TANPA mengonsumsinya, supaya
     * halaman reset-password bisa langsung tahu link sudah kedaluwarsa
     * begitu dibuka -- tidak perlu menunggu user submit form dulu.
     */
    public function verifyResetToken(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'token' => ['required', 'string'],
                'email' => ['required', 'string', 'email'],
            ], [
                'token.required' => 'Token reset password tidak valid.',
                'email.required' => 'Email wajib diisi.',
                'email.email' => 'Format email tidak valid.',
            ]);

            $user = User::query()
                ->where('email', trim($validated['email']))
                ->first();

            $isValid = $user
                ? Password::tokenExists($user, $validated['token'])
                : false;

            return response()->json([
                'success' => true,
                'valid' => $isValid,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('[Auth] Verify reset token error', [
                'email' => $request->input('email'),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => 'Terjadi kesalahan saat memeriksa link reset password.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'token' => ['required', 'string'],
                'email' => ['required', 'string', 'email'],
                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'confirmed',
                    'regex:/[a-z]/',
                    'regex:/[A-Z]/',
                    'regex:/[0-9]/',
                    'regex:/[^A-Za-z0-9]/',
                ],
            ], [
                'token.required' => 'Token reset password tidak valid.',
                'email.required' => 'Email wajib diisi.',
                'email.email' => 'Format email tidak valid.',
                'password.required' => 'Password baru wajib diisi.',
                'password.min' => 'Password baru minimal 8 karakter.',
                'password.confirmed' => 'Konfirmasi password baru tidak sesuai.',
                'password.regex' => 'Password baru wajib memiliki huruf besar, huruf kecil, angka, dan simbol.',
            ]);

            $status = Password::reset(
                $validated,
                function (User $user, string $password): void {
                    $user->password = Hash::make($password);
                    $user->save();

                    /*
                    |--------------------------------------------------------------------------
                    | Putuskan Seluruh Sesi Aktif
                    |--------------------------------------------------------------------------
                    | Setelah password direset, seluruh token akses lama tidak lagi
                    | valid supaya sesi yang mungkin dibajak ikut terputus.
                    |--------------------------------------------------------------------------
                    */
                    $user->tokens()->delete();
                },
            );

            if (
                $status === Password::INVALID_TOKEN
                || $status === Password::INVALID_USER
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Link reset password tidak valid atau sudah kedaluwarsa.',
                ], 422);
            }

            if ($status !== Password::PASSWORD_RESET) {
                Log::warning('[Auth] Reset password unexpected status', [
                    'email' => $validated['email'],
                    'status' => $status,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mereset password. Silakan coba lagi.',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Password berhasil direset. Silakan login dengan password baru Anda.',
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('[Auth] Reset password error', [
                'email' => $request->input('email'),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mereset password.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    public function sso(Request $request)
    {
        $validated = $request->validate([
            'token' => [
                'required',
                'string',
                'max:4096',
            ],
        ]);

        /*
    |--------------------------------------------------------------------------
    | Decrypt payload dari project lama
    |--------------------------------------------------------------------------
    */
        try {
            $payload = $this->decryptLegacySsoToken(
                $validated['token']
            );
        } catch (Throwable $error) {
            Log::warning(
                '[SSO] Token tidak valid',
                [
                    'message' => $error->getMessage(),
                    'ip' => $request->ip(),
                ]
            );

            return response()->json([
                'message' => 'Token SSO tidak valid.',
            ], 401);
        }

        /*
    |--------------------------------------------------------------------------
    | Validasi isi payload
    |--------------------------------------------------------------------------
    */
        $email = strtolower(trim(
            (string) ($payload['email'] ?? '')
        ));

        $wilayahId = (int) (
            $payload['wilayah_id'] ?? 0
        );

        $issuedAt = (int) (
            $payload['iat'] ?? 0
        );

        $expiresAt = (int) (
            $payload['exp'] ?? 0
        );

        $nonce = (string) (
            $payload['nonce'] ?? ''
        );

        $issuer = (string) (
            $payload['iss'] ?? ''
        );

        if (
            $issuer !== 'syop-v3'
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $wilayahId <= 0
            || $issuedAt <= 0
            || $expiresAt <= 0
            || $nonce === ''
        ) {
            return response()->json([
                'message' => 'Payload SSO tidak lengkap.',
            ], 401);
        }

        $now = time();

        /*
    |--------------------------------------------------------------------------
    | Token kedaluwarsa atau timestamp tidak wajar
    |--------------------------------------------------------------------------
    */
        if (
            $expiresAt <= $now
            || $issuedAt > ($now + 30)
            || ($expiresAt - $issuedAt) > 120
        ) {
            return response()->json([
                'message' => 'Token SSO sudah kedaluwarsa.',
            ], 401);
        }

        /*
    |--------------------------------------------------------------------------
    | Mencegah token yang sama digunakan dua kali
    |--------------------------------------------------------------------------
    */
        $nonceCacheKey = 'legacy_sso_nonce:'
            . hash('sha256', $nonce);

        $remainingLifetime = max(
            1,
            $expiresAt - $now
        );

        $nonceRegistered = Cache::add(
            $nonceCacheKey,
            true,
            $remainingLifetime
        );

        if (!$nonceRegistered) {
            return response()->json([
                'message' => 'Token SSO sudah pernah digunakan.',
            ], 409);
        }

        /*
    |--------------------------------------------------------------------------
    | Tentukan cabang_id
    |--------------------------------------------------------------------------
    |
    | PILIH SALAH SATU opsi di bawah.
    |
    */

        /*
     * OPSI A:
     * Jika id_wilayah project lama sama persis
     * dengan primary key table cabang SYOP V4.
     */
        $cabangId = $wilayahId;

        $cabangExists = Cabang::query()
            ->whereKey($cabangId)
            ->exists();

        if (!$cabangExists) {
            return response()->json([
                'message' => 'Cabang tidak ditemukan.',
            ], 404);
        }

        /*
     * OPSI B:
     * Jika ID project lama berbeda dengan ID cabang SYOP V4,
     * hapus Opsi A dan gunakan mapping berikut.
     *
     * Contoh table cabang mempunyai kolom:
     * legacy_wilayah_id
     */

        /*
    $cabang = Cabang::query()
        ->where('legacy_wilayah_id', $wilayahId)
        ->first();

    if (!$cabang) {
        return response()->json([
            'message' => 'Mapping wilayah ke cabang tidak ditemukan.',
        ], 404);
    }

    $cabangId = $cabang->id;
    */

        $normalizedEmail = strtolower(trim($email));

        /*
        |--------------------------------------------------------------------------
        | 1. Cari kecocokan persis email + cabang
        |--------------------------------------------------------------------------
        */
        $exactUsers = User::query()
            ->whereRaw(
                'LOWER(TRIM(email)) = ?',
                [$normalizedEmail]
            )
            ->where('cabang_id', $cabangId)
            ->where('is_active', true)
            ->limit(2)
            ->get();

        if ($exactUsers->count() > 1) {
            return response()->json([
                'message' => 'Terdapat user duplikat pada email dan cabang yang sama.',
            ], 409);
        }

        if ($exactUsers->count() === 1) {
            $user = $exactUsers->first();
        } else {
            /*
            |--------------------------------------------------------------------------
            | 2. Fallback berdasarkan email
            |--------------------------------------------------------------------------
            | Digunakan ketika beberapa akun wilayah SYOP v3 diarahkan
            | ke satu akun pusat/HO di SYOP v4.
            |--------------------------------------------------------------------------
            */
            $emailUsers = User::query()
                ->whereRaw(
                    'LOWER(TRIM(email)) = ?',
                    [$normalizedEmail]
                )
                ->where('is_active', true)
                ->limit(2)
                ->get();

            if ($emailUsers->isEmpty()) {
                return response()->json([
                    'message' => 'User belum terdaftar di SYOP v4.',
                ], 404);
            }

            /*
            * Jangan memilih sembarang user jika ternyata ada lebih
            * dari satu akun aktif dengan email yang sama di SYOP v4.
            */
            if ($emailUsers->count() > 1) {
                return response()->json([
                    'message' => 'Terdapat lebih dari satu akun SYOP v4 dengan email yang sama. Hubungi tim IT.',
                ], 409);
            }

            $user = $emailUsers->first();
        }

        /*
    |--------------------------------------------------------------------------
    | Generate token Sanctum SYOP V4
    |--------------------------------------------------------------------------
    */
        $user->forceFill([
            'last_login_at' => now(),
        ])->save();

        $expiresAt = now()->addMinutes(
            (int) config('auth_session.absolute_timeout_minutes', 720),
        );

        $newToken = $user->createToken(
            'syop-v4-sso',
            ['*'],
            $expiresAt,
        );

        $newToken->accessToken->forceFill([
            'last_used_at' => now(),
        ])->save();

        $token = $newToken->plainTextToken;

        return response()->json([
            'token' => $token,
        ]);
    }

    private function decryptLegacySsoToken(
        string $token
    ): array {
        $cipher = 'aes-256-gcm';
        $tagLength = 16;
        $ivLength = 12;

        $encodedKey = (string) config(
            'services.legacy_sso.key'
        );

        $aad = (string) config(
            'services.legacy_sso.aad',
            'syop-v3-to-v4'
        );

        $key = base64_decode(
            $encodedKey,
            true
        );

        if (
            $key === false
            || strlen($key) !== 32
        ) {
            throw new RuntimeException(
                'Konfigurasi SSO key tidak valid.'
            );
        }

        $binaryToken = $this->base64UrlDecode(
            $token
        );

        /*
     * Minimal berisi:
     * 12 byte IV + 16 byte TAG + ciphertext.
     */
        if (
            strlen($binaryToken)
            <= ($ivLength + $tagLength)
        ) {
            throw new RuntimeException(
                'Format token SSO tidak valid.'
            );
        }

        $iv = substr(
            $binaryToken,
            0,
            $ivLength
        );

        $tag = substr(
            $binaryToken,
            $ivLength,
            $tagLength
        );

        $ciphertext = substr(
            $binaryToken,
            $ivLength + $tagLength
        );

        /*
     * Panjang authentication tag wajib diperiksa.
     */
        if (strlen($tag) !== $tagLength) {
            throw new RuntimeException(
                'Authentication tag tidak valid.'
            );
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            $cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        if ($plaintext === false) {
            throw new RuntimeException(
                'Token SSO gagal didekripsi.'
            );
        }

        $payload = json_decode(
            $plaintext,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($payload)) {
            throw new RuntimeException(
                'Payload SSO tidak valid.'
            );
        }

        return $payload;
    }

    private function base64UrlDecode(
        string $value
    ): string {
        $base64 = strtr(
            $value,
            '-_',
            '+/'
        );

        $remainder = strlen($base64) % 4;

        if ($remainder !== 0) {
            $base64 .= str_repeat(
                '=',
                4 - $remainder
            );
        }

        $decoded = base64_decode(
            $base64,
            true
        );

        if ($decoded === false) {
            throw new RuntimeException(
                'Encoding token SSO tidak valid.'
            );
        }

        return $decoded;
    }
}
