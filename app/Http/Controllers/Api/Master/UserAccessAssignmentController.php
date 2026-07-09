<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAccessAssignmentController extends Controller
{
    public function index(int $userId): JsonResponse
    {
        $user = $this->findUser($userId);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan.',
                'data' => [
                    'user' => null,
                    'assignments' => [],
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'User access assignments loaded successfully.',
            'data' => [
                'user' => [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'assignments' => $this->getFormattedAssignments($userId),
            ],
        ]);
    }

    public function store(Request $request, int $userId): JsonResponse
    {
        $user = $this->findUser($userId);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:cabang,id'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $branchId = (int) $validated['branch_id'];
        $departmentId = (int) $validated['department_id'];

        $this->validateNotMasterDefaultAssignment(
            $user,
            $branchId,
            $departmentId,
        );

        $exists = DB::table('user_access_assignments')
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->where('department_id', $departmentId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'assignment' => [
                    'Kombinasi cabang dan department tersebut sudah ada pada user ini.',
                ],
            ]);
        }

        $authUserId = $request->user()?->id;

        $hasAnyAssignment = DB::table('user_access_assignments')
            ->where('user_id', $userId)
            ->exists();

        $isActive = $request->has('is_active')
            ? $request->boolean('is_active')
            : true;

        $isPrimary = $request->boolean('is_primary') || !$hasAnyAssignment;

        /*
        |--------------------------------------------------------------------------
        | Assignment primary harus aktif.
        |--------------------------------------------------------------------------
        */
        if ($isPrimary) {
            $isActive = true;
        }

        /*
        |--------------------------------------------------------------------------
        | Assignment inactive tidak boleh primary.
        |--------------------------------------------------------------------------
        */
        if (!$isActive) {
            $isPrimary = false;
        }

        DB::transaction(function () use (
            $userId,
            $branchId,
            $departmentId,
            $isPrimary,
            $isActive,
            $authUserId,
        ) {
            if ($isPrimary) {
                DB::table('user_access_assignments')
                    ->where('user_id', $userId)
                    ->update([
                        'is_primary' => false,
                        'updated_by' => $authUserId,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('user_access_assignments')->insert([
                'user_id' => $userId,
                'branch_id' => $branchId,
                'department_id' => $departmentId,
                'is_primary' => $isPrimary,
                'is_active' => $isActive,
                'created_by' => $authUserId,
                'updated_by' => $authUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->ensurePrimaryAssignment($userId, $authUserId);
        });

        return response()->json([
            'success' => true,
            'message' => 'User access assignment berhasil ditambahkan.',
            'data' => [
                'assignments' => $this->getFormattedAssignments($userId),
            ],
        ], 201);
    }

    public function update(
        Request $request,
        int $userId,
        int $assignmentId,
    ): JsonResponse {
        $user = $this->findUser($userId);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan.',
            ], 404);
        }

        $assignment = DB::table('user_access_assignments')
            ->where('id', $assignmentId)
            ->where('user_id', $userId)
            ->first();

        if (!$assignment) {
            return response()->json([
                'success' => false,
                'message' => 'User access assignment tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'branch_id' => ['sometimes', 'required', 'integer', 'exists:cabang,id'],
            'department_id' => ['sometimes', 'required', 'integer', 'exists:departments,id'],
            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $branchId = array_key_exists('branch_id', $validated)
            ? (int) $validated['branch_id']
            : (int) $assignment->branch_id;

        $departmentId = array_key_exists('department_id', $validated)
            ? (int) $validated['department_id']
            : (int) $assignment->department_id;

        $isActive = $request->has('is_active')
            ? $request->boolean('is_active')
            : (bool) $assignment->is_active;

        $isPrimary = $request->has('is_primary')
            ? $request->boolean('is_primary')
            : (bool) $assignment->is_primary;


        $this->validateNotMasterDefaultAssignment(
            $user,
            $branchId,
            $departmentId,
        );

        /*
        |--------------------------------------------------------------------------
        | Assignment primary harus aktif.
        |--------------------------------------------------------------------------
        */
        if ($isPrimary) {
            $isActive = true;
        }

        /*
        |--------------------------------------------------------------------------
        | Assignment inactive tidak boleh primary.
        |--------------------------------------------------------------------------
        */
        if (!$isActive) {
            $isPrimary = false;
        }

        $duplicate = DB::table('user_access_assignments')
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->where('department_id', $departmentId)
            ->where('id', '<>', $assignmentId)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'assignment' => [
                    'Kombinasi cabang dan department tersebut sudah ada pada user ini.',
                ],
            ]);
        }

        $authUserId = $request->user()?->id;

        DB::transaction(function () use (
            $userId,
            $assignmentId,
            $branchId,
            $departmentId,
            $isPrimary,
            $isActive,
            $authUserId,
        ) {
            if ($isPrimary) {
                DB::table('user_access_assignments')
                    ->where('user_id', $userId)
                    ->where('id', '<>', $assignmentId)
                    ->update([
                        'is_primary' => false,
                        'updated_by' => $authUserId,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('user_access_assignments')
                ->where('id', $assignmentId)
                ->where('user_id', $userId)
                ->update([
                    'branch_id' => $branchId,
                    'department_id' => $departmentId,
                    'is_primary' => $isPrimary,
                    'is_active' => $isActive,
                    'updated_by' => $authUserId,
                    'updated_at' => now(),
                ]);

            $this->ensurePrimaryAssignment($userId, $authUserId);
        });

        return response()->json([
            'success' => true,
            'message' => 'User access assignment berhasil diperbarui.',
            'data' => [
                'assignments' => $this->getFormattedAssignments($userId),
            ],
        ]);
    }

    private function validateNotMasterDefaultAssignment(
        object $user,
        int $branchId,
        int $departmentId,
    ): void {
        $masterBranchId = (int) ($user->cabang_id ?? 0);
        $masterDepartmentId = (int) ($user->departemen_id ?? 0);

        if (
            $masterBranchId > 0
            && $masterDepartmentId > 0
            && $masterBranchId === $branchId
            && $masterDepartmentId === $departmentId
        ) {
            throw ValidationException::withMessages([
                'assignment' => [
                    'Kombinasi cabang dan department tersebut sama dengan master data user, sehingga tidak perlu ditambahkan sebagai access assignment.',
                ],
            ]);
        }
    }

    private function findUser(int $userId): ?object
    {
        return DB::table('users')
            ->where('id', $userId)
            ->first([
                'id',
                'name',
                'email',
                'cabang_id',
                'departemen_id',
            ]);
    }

    private function getFormattedAssignments(int $userId)
    {
        return DB::table('user_access_assignments as uaa')
            ->leftJoin('cabang as c', 'c.id', '=', 'uaa.branch_id')
            ->leftJoin('departments as d', 'd.id', '=', 'uaa.department_id')
            ->where('uaa.user_id', $userId)
            ->select([
                'uaa.id',
                'uaa.user_id',
                'uaa.branch_id',
                'uaa.department_id',
                'uaa.is_primary',
                'uaa.is_active',
                'uaa.created_at',
                'uaa.updated_at',

                'c.nama_cabang as branch_name',
                'c.inisial_cabang as branch_code',

                'd.kode as department_code',
                'd.nama as department_name',
            ])
            ->orderByDesc('uaa.is_primary')
            ->orderByDesc('uaa.is_active')
            ->orderBy('c.nama_cabang')
            ->orderBy('d.kode')
            ->get()
            ->map(function ($item) {
                $branchCode = trim((string) ($item->branch_code ?? ''));
                $branchName = trim((string) ($item->branch_name ?? '-'));

                $departmentCode = trim((string) ($item->department_code ?? ''));
                $departmentName = trim((string) ($item->department_name ?? '-'));

                return [
                    'id' => (int) $item->id,
                    'user_id' => (int) $item->user_id,

                    'branch_id' => (int) $item->branch_id,
                    'branch_code' => $branchCode,
                    'branch_name' => $branchName,
                    'branch_title' => trim(
                        ($branchCode ? $branchCode . ' - ' : '')
                            . $branchName,
                    ),

                    'department_id' => (int) $item->department_id,
                    'department_code' => $departmentCode,
                    'department_name' => $departmentName,
                    'department_title' => trim(
                        ($departmentCode ? $departmentCode . ' - ' : '')
                            . $departmentName,
                    ),

                    'is_primary' => (bool) $item->is_primary,
                    'is_active' => (bool) $item->is_active,

                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];
            })
            ->values();
    }

    private function ensurePrimaryAssignment(
        int $userId,
        ?int $updatedBy = null,
    ): void {
        $hasActivePrimary = DB::table('user_access_assignments')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('is_primary', true)
            ->exists();

        if ($hasActivePrimary) {
            return;
        }

        $firstActiveAssignment = DB::table('user_access_assignments')
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$firstActiveAssignment) {
            return;
        }

        DB::table('user_access_assignments')
            ->where('id', $firstActiveAssignment->id)
            ->where('user_id', $userId)
            ->update([
                'is_primary' => true,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]);
    }
}
