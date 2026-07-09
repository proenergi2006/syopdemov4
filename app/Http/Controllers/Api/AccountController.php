<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
                'current_password.required' => 'Current password wajib diisi.',
                'password.required' => 'Password baru wajib diisi.',
                'password.min' => 'Password baru minimal 8 karakter.',
                'password.confirmed' => 'Konfirmasi password baru tidak sesuai.',
                'password.regex' => 'Password baru wajib memiliki huruf besar, huruf kecil, angka, dan simbol.',
            ]);

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan.',
                ], 401);
            }

            if (!Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Password lama tidak sesuai.'],
                ]);
            }

            if (Hash::check($validated['password'], $user->password)) {
                throw ValidationException::withMessages([
                    'password' => ['Password baru tidak boleh sama dengan password lama.'],
                ]);
            }

            $user->password = Hash::make($validated['password']);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Password berhasil diubah.',
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
                'message' => 'Gagal mengubah password.',
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
                'message' => 'User tidak terautentikasi.',
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
    | Fallback dari master user
    |--------------------------------------------------------------------------
    | Untuk jaga-jaga kalau user belum punya row di user_access_assignments.
    |--------------------------------------------------------------------------
    */
        if ($assignments->isEmpty()) {
            $branchId = (int) ($user->cabang_id ?? 0);
            $departmentId = (int) ($user->departemen_id ?? 0);

            if ($branchId > 0 && $departmentId > 0) {
                $fallback = DB::table('cabang as c')
                    ->crossJoin('departments as d')
                    ->where('c.id', $branchId)
                    ->where('d.id', $departmentId)
                    ->select([
                        'c.id as branch_id',
                        'c.nama_cabang as branch_name',
                        'c.inisial_cabang as branch_code',

                        'd.id as department_id',
                        'd.kode as department_code',
                        'd.nama as department_name',
                    ])
                    ->first();

                if ($fallback) {
                    $assignments = collect([
                        (object) [
                            'id' => null,
                            'branch_id' => (int) $fallback->branch_id,
                            'department_id' => (int) $fallback->department_id,
                            'is_primary' => true,

                            'branch_name' => $fallback->branch_name,
                            'branch_code' => $fallback->branch_code,

                            'department_code' => $fallback->department_code,
                            'department_name' => $fallback->department_name,
                        ],
                    ]);
                }
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
            'message' => 'User access assignments loaded successfully.',
            'data' => [
                'assignments' => $formattedAssignments,
                'branches' => $branches,
                'departments_by_branch' => $departmentsByBranch,
            ],
        ]);
    }
}
