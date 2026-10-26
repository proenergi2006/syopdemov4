<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\UserHomeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'current_password' => ['required', 'string'],
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
                'current_password.required' => __('account_messages.current_password_required'),
                'password.required' => __('account_messages.new_password_required'),
                'password.min' => __('account_messages.new_password_min'),
                'password.confirmed' => __('account_messages.new_password_confirmed'),
                'password.regex' => __('account_messages.new_password_regex'),
            ]);

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => __('account_messages.user_not_found'),
                ], 401);
            }

            if (!Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => [__('account_messages.current_password_incorrect')],
                ]);
            }

            if (Hash::check($validated['password'], $user->password)) {
                throw ValidationException::withMessages([
                    'password' => [__('account_messages.new_password_same_as_old')],
                ]);
            }

            $user->password = Hash::make($validated['password']);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => __('account_messages.password_changed'),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[Account] Change password error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('account_messages.password_change_failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Preferensi Bahasa
    |--------------------------------------------------------------------------
    | Dipanggil dari language switcher FE supaya backend tahu bahasa pilihan
    | user -- dibutuhkan untuk notifikasi/email yang dikirim ke user tersebut
    | secara async (bukan hanya untuk request yang sedang berjalan).
    |--------------------------------------------------------------------------
    */
    public function updateLocale(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'locale' => ['required', 'string', 'in:id,en'],
            ]);

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => __('account_messages.user_not_found'),
                ], 401);
            }

            $user->locale = $validated['locale'];
            $user->save();

            return response()->json([
                'success' => true,
                'message' => __('account_messages.locale_updated'),
                'data' => [
                    'locale' => $user->locale,
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[Account] Update locale error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __('account_messages.locale_update_failed'),
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function accessAssignments(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('account_messages.user_not_authenticated'),
                'data' => [
                    'assignments' => [],
                    'branches' => [],
                    'departments_by_branch' => [],
                ],
            ], 401);
        }

        /*
    |--------------------------------------------------------------------------
    | Ambil assignment aktif user
    |--------------------------------------------------------------------------
    */
        $assignments = DB::table('user_access_assignments as uaa')
            ->join('cabang as c', 'c.id', '=', 'uaa.branch_id')
            ->join('departments as d', 'd.id', '=', 'uaa.department_id')
            ->where('uaa.user_id', $user->id)
            ->where('uaa.is_active', true)
            ->select([
                'uaa.id',
                'uaa.branch_id',
                'uaa.department_id',
                'uaa.is_primary',

                'c.nama_cabang as branch_name',
                'c.inisial_cabang as branch_code',

                'd.kode as department_code',
                'd.nama as department_name',
            ])
            ->orderByDesc('uaa.is_primary')
            ->orderBy('c.nama_cabang')
            ->orderBy('d.kode')
            ->get();

        /*
    |--------------------------------------------------------------------------
    | Cabang dan department bawaan akun
    |--------------------------------------------------------------------------
    | SELALU ikut, bukan hanya ketika daftarnya kosong.
    |
    | Dulu bagian ini berbunyi "kalau user belum punya row sama sekali".
    | Akibatnya satu baris akses tambahan sudah cukup membuat cabang asal
    | pemohon lenyap dari pilihan -- menambah akses berubah menjadi
    | mengganti akses.
    |
    | Aturannya sekarang: bawaan akun berupa baris sungguhan yang tidak boleh
    | dinonaktifkan. Penggabungan di sini tinggal jaring pengaman untuk akun
    | yang barisnya belum sempat dibuat.
    |--------------------------------------------------------------------------
    */
        $bawaan = UserHomeAccess::kombinasi($user);

        $sudahAda = $bawaan !== null
            && $assignments->contains(
                fn($item) => (int) $item->branch_id === $bawaan['branch_id']
                    && (int) $item->department_id === $bawaan['department_id']
            );

        if ($bawaan !== null && !$sudahAda) {
            $barisBawaan = DB::table('cabang as c')
                ->crossJoin('departments as d')
                ->where('c.id', $bawaan['branch_id'])
                ->where('d.id', $bawaan['department_id'])
                ->select([
                    'c.id as branch_id',
                    'c.nama_cabang as branch_name',
                    'c.inisial_cabang as branch_code',

                    'd.id as department_id',
                    'd.kode as department_code',
                    'd.nama as department_name',
                ])
                ->first();

            if ($barisBawaan) {
                /*
                | Bertanda utama hanya bila belum ada baris lain yang
                | memegang tanda itu -- admin boleh memindahkannya ke akses
                | tambahan.
                */
                $adaUtama = $assignments->contains(fn($item) => (bool) $item->is_primary);

                $assignments = $assignments->push((object) [
                    'id' => null,
                    'branch_id' => (int) $barisBawaan->branch_id,
                    'department_id' => (int) $barisBawaan->department_id,
                    'is_primary' => !$adaUtama,

                    'branch_name' => $barisBawaan->branch_name,
                    'branch_code' => $barisBawaan->branch_code,

                    'department_code' => $barisBawaan->department_code,
                    'department_name' => $barisBawaan->department_name,
                ]);

                /*
                | Yang bertanda utama tetap paling depan, karena pilihan
                | pertamalah yang terbuka saat form dibuka.
                */
                $assignments = $assignments
                    ->sortByDesc(fn($item) => (bool) $item->is_primary)
                    ->values();
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Format assignments
    |--------------------------------------------------------------------------
    */
        $formattedAssignments = $assignments
            ->map(function ($item) {
                return [
                    'id' => $item->id ? (int) $item->id : null,

                    'branch_id' => (int) $item->branch_id,
                    'branch_name' => $item->branch_name ?? '-',
                    'branch_code' => $item->branch_code ?? '-',

                    'department_id' => (int) $item->department_id,
                    'department_code' => $item->department_code ?? '-',
                    'department_name' => $item->department_name ?? '-',

                    'is_primary' => (bool) $item->is_primary,
                ];
            })
            ->values();

        /*
    |--------------------------------------------------------------------------
    | Unique branches untuk dropdown Cabang
    |--------------------------------------------------------------------------
    */
        $branches = $formattedAssignments
            ->unique('branch_id')
            ->map(function ($item) {
                return [
                    'id' => $item['branch_id'],
                    'name' => $item['branch_name'],
                    'code' => $item['branch_code'],
                    'title' => trim(
                        ($item['branch_code'] !== '-' ? $item['branch_code'] . ' - ' : '')
                            . $item['branch_name']
                    ),
                ];
            })
            ->values();

        /*
    |--------------------------------------------------------------------------
    | Department berdasarkan branch
    |--------------------------------------------------------------------------
    */
        $departmentsByBranch = $formattedAssignments
            ->groupBy('branch_id')
            ->map(function ($items) {
                return $items
                    ->unique('department_id')
                    ->map(function ($item) {
                        return [
                            'id' => $item['department_id'],
                            'code' => $item['department_code'],
                            'name' => $item['department_name'],
                            'title' => trim(
                                ($item['department_code'] !== '-' ? $item['department_code'] . ' - ' : '')
                                    . $item['department_name']
                            ),
                        ];
                    })
                    ->values();
            });

        return response()->json([
            'success' => true,
            'message' => __('account_messages.access_assignments_loaded'),
            'data' => [
                'assignments' => $formattedAssignments,
                'branches' => $branches,
                'departments_by_branch' => $departmentsByBranch,
            ],
        ]);
    }
}
