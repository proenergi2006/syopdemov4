<?php

namespace App\Services\Auth;

use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Super admin masuk ke akun user lain tanpa password user tersebut.
 *
 * Seluruh aturan keamanan fitur ini ada di kelas ini dan tidak di tempat lain,
 * supaya tidak ada satu pun jalur yang bisa melewatinya.
 */
class ImpersonationService
{
    /** Izin untuk mulai menyamar. Diberikan dari layar Role Permission. */
    public const PERMISSION_MULAI = 'auth_impersonation.create';

    /** Izin untuk melihat riwayat penyamaran. */
    public const PERMISSION_LIHAT = 'auth_impersonation.view';

    /** Nama token penyamaran. Dipakai sebagai lapis pemeriksaan kedua. */
    public const NAMA_TOKEN = 'syop-v4-impersonation';

    /** Nama token biasa, sama seperti yang dipakai AuthController::login(). */
    public const NAMA_TOKEN_ADMIN = 'syop-v4';

    /** Kunci atribut request, agar sesi cukup dicari sekali per request. */
    private const ATRIBUT_REQUEST = 'impersonation_session';

    /*
    |--------------------------------------------------------------------------
    | Mulai menyamar
    |--------------------------------------------------------------------------
    */
    public function mulai(
        User $admin,
        int $targetId,
        string $alasan,
        Request $request,
    ): array {
        if (!$admin->hasPermission(self::PERMISSION_MULAI)) {
            abort(403, __('impersonation_messages.forbidden'));
        }

        /*
        | Sedang menyamar lalu menyamar lagi: ditolak. Tanpa ini, rantai
        | penyamaran membuat pertanyaan "siapa sebenarnya yang melakukan ini"
        | tidak punya jawaban tunggal.
        */
        if ($this->sesiUntukRequest($request) !== null) {
            $this->tolak('nested');
        }

        $target = User::query()->find($targetId);

        /*
        | Dilempar langsung, bukan lewat tolak(), supaya pembaca kode maupun
        | alat analisis tahu bahwa di bawah sini $target pasti terisi.
        */
        if (!$target) {
            throw ValidationException::withMessages([
                'user_id' => [__('impersonation_messages.target_not_found')],
            ]);
        }

        if ((int) $target->id === (int) $admin->id) {
            $this->tolak('target_is_self');
        }

        /*
        | Tidak boleh menyamar orang yang sendirinya boleh menyamar. Kalau
        | boleh, dua pemegang izin ini bisa saling bersembunyi di balik nama
        | satu sama lain dan jejaknya jadi berputar.
        */
        if ($target->hasPermission(self::PERMISSION_MULAI)) {
            $this->tolak('target_is_privileged');
        }

        /*
        | Sesi lama yang menggantung karena tokennya mati dibereskan dulu,
        | supaya partial unique index tidak menolak sesi baru yang sah.
        */
        $this->bersihkanSesiMenggantung($admin);

        return DB::transaction(function () use ($admin, $target, $alasan, $request) {
            $tokenBaru = $target->createToken(
                self::NAMA_TOKEN,
                ['*'],
                now()->addMinutes($this->umurMenit()),
            );

            $tokenId = (int) $tokenBaru->accessToken->id;

            /*
            | Baris sesi dibuat SESUDAH token, supaya token_id terisi sejak
            | awal dan tidak pernah ada sesi terbuka tanpa token. Kalau partial
            | unique index menolak di sini (admin sudah punya sesi terbuka),
            | transaksi membatalkan token tadi sekalian.
            */
            $sesi = ImpersonationSession::create([
                'admin_user_id' => $admin->id,
                'target_user_id' => $target->id,
                'token_id' => $tokenId,
                'reason' => $alasan,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                'started_at' => now(),
            ]);

            /*
            | Token admin dihapus: selama penyamaran hanya ada satu token hidup,
            | dan tidak ada apa pun di browser yang bisa dipakai melompat balik
            | ke hak super admin tanpa melewati stop().
            */
            $this->hapusTokenSaatIni($admin);

            $this->catatJejakIdle($tokenId);

            activity('auth')
                ->causedBy($admin)
                ->withProperties([
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'impersonation_session_id' => $sesi->id,
                    'target_user_id' => $target->id,
                    'target_username' => $target->username,
                    'reason' => $alasan,
                ])
                ->event('impersonation_started')
                ->log(sprintf(
                    'User "%s" mulai masuk sebagai "%s". Alasan: %s',
                    $admin->username,
                    $target->username,
                    $alasan,
                ));

            return [
                'session' => $sesi,
                'token' => $tokenBaru->plainTextToken,
                'target' => $target,
            ];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Kembali ke akun admin
    |--------------------------------------------------------------------------
    | Inilah satu-satunya jalan naik kembali ke hak super admin, jadi seluruh
    | keamanan fitur ini bertumpu pada pemeriksaan di bawah.
    |
    | Yang menentukan izin adalah ID TOKEN yang sedang dipakai, bukan siapa
    | user-nya. Dua cara yang terdengar wajar tapi fatal:
    |
    | - "kalau user ini target sesi aktif, pulihkan adminnya" -- maka user
    |   target yang login normal dengan tokennya sendiri bisa memanggil stop
    |   dan dialah yang jadi super admin.
    | - "ambil admin_user_id dari request" -- maka klien memilih mau jadi siapa.
    |
    | Karena id personal_access_tokens berasal dari sequence dan tidak pernah
    | dipakai ulang, dan token_id unique di tabel sesi, maka di seluruh sistem
    | hanya ada tepat SATU token yang bisa lolos di sini.
    |--------------------------------------------------------------------------
    */
    public function stop(Request $request): array
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (!$user || !$token) {
            abort(401, __('impersonation_messages.not_impersonating'));
        }

        $sesi = ImpersonationSession::query()
            ->whereNull('ended_at')
            ->where('token_id', (int) $token->id)
            ->first();

        if (!$sesi) {
            abort(403, __('impersonation_messages.not_impersonating'));
        }

        /* Lapis kedua: token penyamaran selalu bernama khusus. */
        if ((string) $token->name !== self::NAMA_TOKEN) {
            abort(403, __('impersonation_messages.not_impersonating'));
        }

        /* Tujuan diambil HANYA dari baris sesi, tidak pernah dari request. */
        $admin = User::query()->find($sesi->admin_user_id);

        $adminMasihBerhak = $admin
            && (!isset($admin->is_active) || $admin->is_active)
            && $admin->hasPermission(self::PERMISSION_MULAI);

        if (!$adminMasihBerhak) {
            /*
            | Hak admin dicabut atau akunnya dinonaktifkan di tengah sesi.
            | Sesi ditutup dan token dibuang, tapi TIDAK ada token admin yang
            | diterbitkan -- kalau tidak, hak yang baru dicabut bisa dipanen
            | kembali lewat pintu ini.
            */
            DB::transaction(function () use ($sesi, $token) {
                $this->tutup($sesi, ImpersonationSession::ENDED_MANUAL);
                $token->delete();
            });

            activity('auth')
                ->causedBy($admin)
                ->withProperties([
                    'ip' => $request->ip(),
                    'impersonation_session_id' => $sesi->id,
                ])
                ->event('impersonation_stop_denied')
                ->log('Penyamaran dihentikan tanpa memulihkan sesi admin: hak akses admin sudah tidak berlaku.');

            throw new HttpException(
                403,
                __('impersonation_messages.admin_no_longer_allowed'),
            );
        }

        return DB::transaction(function () use ($admin, $sesi, $token, $request) {
            $this->tutup($sesi, ImpersonationSession::ENDED_MANUAL);

            $tokenAdmin = $admin->createToken(
                self::NAMA_TOKEN_ADMIN,
                ['*'],
                now()->addMinutes(
                    (int) config('auth_session.absolute_timeout_minutes', 720),
                ),
            );

            $this->catatJejakIdle((int) $tokenAdmin->accessToken->id);

            /* Token penyamaran dibuang: satu token hanya bisa ditukar sekali. */
            $token->delete();

            activity('auth')
                ->causedBy($admin)
                ->withProperties([
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'impersonation_session_id' => $sesi->id,
                    'target_user_id' => $sesi->target_user_id,
                ])
                ->event('impersonation_stopped')
                ->log(sprintf(
                    'User "%s" kembali ke akunnya sendiri setelah masuk sebagai user id %d.',
                    $admin->username,
                    $sesi->target_user_id,
                ));

            return [
                'token' => $tokenAdmin->plainTextToken,
                'admin' => $admin,
            ];
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Sesi penyamaran untuk request ini, bila ada
    |--------------------------------------------------------------------------
    | Hasilnya disimpan di atribut request supaya middleware dan controller
    | yang membutuhkannya tidak mengulang query yang sama.
    |--------------------------------------------------------------------------
    */
    public function sesiUntukRequest(Request $request): ?ImpersonationSession
    {
        if ($request->attributes->has(self::ATRIBUT_REQUEST)) {
            return $request->attributes->get(self::ATRIBUT_REQUEST);
        }

        $token = $request->user()?->currentAccessToken();

        $sesi = null;

        if ($token && (string) $token->name === self::NAMA_TOKEN) {
            $sesi = ImpersonationSession::query()
                ->whereNull('ended_at')
                ->where('token_id', (int) $token->id)
                ->first();
        }

        $request->attributes->set(self::ATRIBUT_REQUEST, $sesi);

        return $sesi;
    }

    /*
    |--------------------------------------------------------------------------
    | Logout saat sedang menyamar
    |--------------------------------------------------------------------------
    | Keluar total, bukan kembali ke admin. Tombol Logout yang artinya
    | berubah-ubah tergantung keadaan lebih berbahaya daripada yang tetap.
    |--------------------------------------------------------------------------
    */
    public function tutupKarenaLogout(Request $request): ?ImpersonationSession
    {
        $sesi = $this->sesiUntukRequest($request);

        if (!$sesi) {
            return null;
        }

        $this->tutup($sesi, ImpersonationSession::ENDED_LOGOUT);

        activity('auth')
            ->causedBy($sesi->admin)
            ->withProperties([
                'ip' => $request->ip(),
                'impersonation_session_id' => $sesi->id,
                'target_user_id' => $sesi->target_user_id,
            ])
            ->event('impersonation_stopped')
            ->log('Penyamaran berakhir karena logout, bukan karena kembali ke akun admin.');

        return $sesi;
    }

    /*
    |--------------------------------------------------------------------------
    | Sesi menggantung
    |--------------------------------------------------------------------------
    | Token penyamaran bisa mati sendiri karena idle atau kedaluwarsa, dan
    | baris sesinya ikut tertinggal terbuka. Dibereskan di dua saat: ketika
    | admin login lagi, dan ketika ia mencoba mulai menyamar lagi.
    |--------------------------------------------------------------------------
    */
    public function bersihkanSesiMenggantung(User $admin): void
    {
        $terbuka = ImpersonationSession::query()
            ->whereNull('ended_at')
            ->where('admin_user_id', $admin->id)
            ->get();

        foreach ($terbuka as $sesi) {
            $tokenMasihHidup = $sesi->token_id
                && PersonalAccessToken::query()
                    ->whereKey($sesi->token_id)
                    ->exists();

            if ($tokenMasihHidup) {
                continue;
            }

            $this->tutup($sesi, ImpersonationSession::ENDED_EXPIRED);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */
    private function tutup(ImpersonationSession $sesi, string $sebab): void
    {
        $sesi->ended_at = now();
        $sesi->ended_reason = $sebab;

        /*
        | token_id dikosongkan supaya id token yang sudah mati tidak tertinggal
        | di sini, dan supaya unique index-nya bebas kembali.
        */
        $sesi->token_id = null;

        $sesi->save();
    }

    private function hapusTokenSaatIni(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token) {
            Cache::forget('auth:last_activity:' . $token->id);

            $token->delete();
        }
    }

    /*
    | Disamakan dengan AuthController::login(): idle timeout dihitung dari
    | catatan di Cache, jadi token baru perlu titik mulai yang sama.
    */
    private function catatJejakIdle(int $tokenId): void
    {
        Cache::put(
            'auth:last_activity:' . $tokenId,
            now()->toIso8601String(),
            now()->addMinutes(
                (int) config('auth_session.absolute_timeout_minutes', 720) + 60,
            ),
        );
    }

    private function umurMenit(): int
    {
        $menit = (int) config('auth_session.impersonation_minutes', 60);

        return $menit > 0 ? $menit : 60;
    }

    private function tolak(string $kunci): void
    {
        throw ValidationException::withMessages([
            'user_id' => [__('impersonation_messages.' . $kunci)],
        ]);
    }
}
