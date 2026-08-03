<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\PermissionModule;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RolePermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'role_id' => [
                    'required',
                    'integer',
                    Rule::exists('roles', 'id'),
                ],
            ]);

            $roleId = (int) $validated['role_id'];

            $role = Role::query()
                ->with([
                    'permissions' => function ($query) {
                        $query
                            ->orderBy('module')
                            ->orderByRaw("
                                CASE action
                                    WHEN 'view' THEN 1
                                    WHEN 'create' THEN 2
                                    WHEN 'update' THEN 3
                                    WHEN 'delete' THEN 4
                                    WHEN 'approve' THEN 5
                                    ELSE 99
                                END
                            ")
                            ->orderBy('name');
                    },
                ])
                ->findOrFail($roleId);

            $data = $role->permissions->map(function ($permission) use ($roleId) {
                return [
                    'id' => $permission->pivot?->id,
                    'role_id' => $roleId,
                    'permission_id' => $permission->id,
                    'scope' => $permission->pivot?->scope ?? 'NONE',
                    'is_active' => (bool) ($permission->pivot?->is_active ?? false),
                    'created_at' => $permission->pivot?->created_at,
                    'updated_at' => $permission->pivot?->updated_at,
                    'permission' => [
                        'id' => $permission->id,
                        'module' => $permission->module,
                        'action' => $permission->action,
                        'code' => $permission->code,
                        'name' => $permission->name,
                        'description' => $permission->description,
                        'is_active' => (bool) $permission->is_active,
                    ],
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Data role permission berhasil dimuat.',
                'data' => $data,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('[Role Permission] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data role permission.',
                'data' => [],
            ], 500);
        }
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        try {
            /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */
            $validated = $request->validate([
                'role_ids' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'role_ids.*' => [
                    'required',
                    'integer',
                    'distinct',
                    Rule::exists('roles', 'id'),
                ],

                'permissions' => [
                    'required',
                    'array',
                ],

                'permissions.*.permission_id' => [
                    'required',
                    'integer',
                    'distinct',
                    Rule::exists('permissions', 'id'),
                ],

                'permissions.*.is_active' => [
                    'required',
                    'boolean',
                ],

                /*
            |--------------------------------------------------------------------------
            | Compatibility bila frontend masih ikut mengirim is_allowed
            |--------------------------------------------------------------------------
            */
                'permissions.*.is_allowed' => [
                    'sometimes',
                    'boolean',
                ],

                'permissions.*.scope' => [
                    'nullable',
                    'string',
                    Rule::in([
                        'NONE',
                        'OWN_DATA',
                        'OWN_DEPARTMENT',
                        'OWN_CABANG',
                        'ALL',
                    ]),
                ],
            ]);

            /*
        |--------------------------------------------------------------------------
        | Normalisasi role IDs
        |--------------------------------------------------------------------------
        */
            $roleIds = collect($validated['role_ids'])
                ->map(
                    fn($id) => (int) $id,
                )
                ->filter(
                    fn($id) => $id > 0,
                )
                ->unique()
                ->values();

            if ($roleIds->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pilih minimal satu role.',
                ], 422);
            }

            $permissionPayloads = collect(
                $validated['permissions'],
            );

            /*
        |--------------------------------------------------------------------------
        | Ambil seluruh permission sekali saja
        |--------------------------------------------------------------------------
        */
            $permissionIds = $permissionPayloads
                ->pluck('permission_id')
                ->map(
                    fn($id) => (int) $id,
                )
                ->filter(
                    fn($id) => $id > 0,
                )
                ->unique()
                ->values();

            $permissions = Permission::query()
                ->whereIn('id', $permissionIds)
                ->get()
                ->keyBy(
                    fn(Permission $permission) =>
                    (int) $permission->id,
                );

            /*
        |--------------------------------------------------------------------------
        | Permission Module adalah core dari Permission
        |--------------------------------------------------------------------------
        | Permission hanya boleh diberikan (is_active=true) ke role apabila
        | module induknya (permissions.module) sudah terdaftar dan aktif di
        | permission_modules. Menonaktifkan permission yang sudah terlanjur
        | diberikan tetap diperbolehkan, supaya data lama tetap bisa dibersihkan.
        |--------------------------------------------------------------------------
        */
            $activeModuleCodes = PermissionModule::query()
                ->where('is_active', true)
                ->pluck('code')
                ->map(
                    fn($code) => strtolower(trim((string) $code)),
                )
                ->all();

            /*
        |--------------------------------------------------------------------------
        | Pasangan role-permission yang sudah aktif sebelumnya
        |--------------------------------------------------------------------------
        | Dipakai supaya bulk save tidak diblokir hanya karena role tersebut
        | kebetulan sudah memiliki permission lama pada module yang sekarang
        | dinonaktifkan. Payload selalu mengirim seluruh baris permission yang
        | sedang ditampilkan di halaman (bukan hanya yang diubah), sehingga
        | permission lama tersebut tetap ikut terkirim dengan is_active=true.
        | Hanya PENAMBAHAN baru (role belum pernah memiliki permission itu)
        | yang perlu diblokir; menyimpan ulang permission yang sudah ada tidak.
        |--------------------------------------------------------------------------
        */
            $existingActivePairs = DB::table('role_permissions')
                ->whereIn('role_id', $roleIds)
                ->whereIn('permission_id', $permissionIds)
                ->where('is_active', true)
                ->get(['role_id', 'permission_id'])
                ->map(
                    fn($row) => $row->role_id . ':' . $row->permission_id,
                )
                ->flip();

            $invalidPermissionCodes = $permissionPayloads
                ->filter(
                    fn($payload) => (bool) (
                        $payload['is_active']
                        ?? $payload['is_allowed']
                        ?? false
                    ),
                )
                ->filter(function ($payload) use ($permissions, $activeModuleCodes) {
                    $permission = $permissions->get(
                        (int) ($payload['permission_id'] ?? 0),
                    );

                    if (!$permission) {
                        return false;
                    }

                    return !in_array(
                        strtolower(trim((string) $permission->module)),
                        $activeModuleCodes,
                        true,
                    );
                })
                ->filter(function ($payload) use ($roleIds, $existingActivePairs) {
                    $permissionId = (int) ($payload['permission_id'] ?? 0);

                    foreach ($roleIds as $roleId) {
                        if (!$existingActivePairs->has($roleId . ':' . $permissionId)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->map(
                    fn($payload) => $permissions->get(
                        (int) ($payload['permission_id'] ?? 0),
                    ),
                )
                ->filter()
                ->pluck('code')
                ->unique()
                ->values();

            if ($invalidPermissionCodes->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'permissions' => [
                        sprintf(
                            'Permission berikut tidak dapat diberikan karena Permission Module induknya belum terdaftar/aktif: %s. Daftarkan module tersebut terlebih dahulu di Kelola Permission Module.',
                            $invalidPermissionCodes->implode(', '),
                        ),
                    ],
                ]);
            }

            /*
        |--------------------------------------------------------------------------
        | Simpan untuk seluruh role
        |--------------------------------------------------------------------------
        */
            DB::transaction(function () use (
                $roleIds,
                $permissionPayloads,
                $permissions,
            ) {
                $now = now();

                foreach ($roleIds as $roleId) {
                    foreach ($permissionPayloads as $permissionPayload) {
                        $permissionId = (int) (
                            $permissionPayload['permission_id']
                            ?? 0
                        );

                        $permission = $permissions->get(
                            $permissionId,
                        );

                        if (!$permission) {
                            continue;
                        }

                        /*
                    |--------------------------------------------------------------------------
                    | Status permission
                    |--------------------------------------------------------------------------
                    */
                        $isActive = (bool) (
                            $permissionPayload['is_active']
                            ?? $permissionPayload['is_allowed']
                            ?? false
                        );

                        /*
                    |--------------------------------------------------------------------------
                    | Normalisasi scope
                    |--------------------------------------------------------------------------
                    */
                        $scope = strtoupper(
                            trim(
                                (string) (
                                    $permissionPayload['scope']
                                    ?? 'NONE'
                                ),
                            ),
                        );

                        $allowedScopes = [
                            'NONE',
                            'OWN_DATA',
                            'OWN_DEPARTMENT',
                            'OWN_CABANG',
                            'ALL',
                        ];

                        if (!in_array(
                            $scope,
                            $allowedScopes,
                            true,
                        )) {
                            $scope = 'NONE';
                        }

                        /*
                    |--------------------------------------------------------------------------
                    | Scope hanya untuk action VIEW
                    |--------------------------------------------------------------------------
                    |
                    | CREATE / UPDATE / DELETE / APPROVE:
                    | scope selalu NONE.
                    |--------------------------------------------------------------------------
                    */
                        if (
                            strtolower(
                                trim(
                                    (string) $permission->action,
                                ),
                            ) !== 'view'
                        ) {
                            $scope = 'NONE';
                        }

                        /*
                    |--------------------------------------------------------------------------
                    | Permission nonaktif
                    |--------------------------------------------------------------------------
                    */
                        if (!$isActive) {
                            $scope = 'NONE';
                        }

                        /*
                    |--------------------------------------------------------------------------
                    | Pertahankan created_at lama
                    |--------------------------------------------------------------------------
                    */
                        $existingRolePermission = DB::table(
                            'role_permissions',
                        )
                            ->where(
                                'role_id',
                                $roleId,
                            )
                            ->where(
                                'permission_id',
                                $permissionId,
                            )
                            ->first();

                        DB::table('role_permissions')
                            ->updateOrInsert(
                                [
                                    'role_id' => $roleId,

                                    'permission_id'
                                    => $permissionId,
                                ],
                                [
                                    'scope' => $scope,

                                    'is_active'
                                    => $isActive,

                                    'created_at'
                                    => $existingRolePermission
                                        ?->created_at
                                        ?? $now,

                                    'updated_at'
                                    => $now,
                                ],
                            );
                    }
                }
            });

            /*
        |--------------------------------------------------------------------------
        | Ambil informasi role setelah disimpan
        |--------------------------------------------------------------------------
        */
            $roles = Role::query()
                ->whereIn('id', $roleIds)
                ->get([
                    'id',
                    'nama',
                ]);

            $roleResults = $roles
                ->map(function (Role $role) {
                    return [
                        'role_id' => $role->id,

                        'role_name'
                        => $role->nama
                            ?? null,

                        'permissions_count'
                        => DB::table(
                            'role_permissions',
                        )
                            ->where(
                                'role_id',
                                $role->id,
                            )
                            ->where(
                                'is_active',
                                true,
                            )
                            ->count(),
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,

                'message' => sprintf(
                    'Permission berhasil diterapkan ke %d role.',
                    $roleIds->count(),
                ),

                'data' => [
                    'role_ids' => $roleIds->all(),

                    'total_roles'
                    => $roleIds->count(),

                    'roles'
                    => $roleResults,
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error(
                '[Role Permission] Bulk update error',
                [
                    'message'
                    => $e->getMessage(),

                    'file'
                    => $e->getFile(),

                    'line'
                    => $e->getLine(),

                    'request'
                    => $request->all(),

                    'user_id'
                    => $request->user()?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan permission role.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }
}
