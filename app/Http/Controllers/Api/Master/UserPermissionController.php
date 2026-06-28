<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserPermissionController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Direct Permission User
    |--------------------------------------------------------------------------
    | Hanya mengembalikan permission yang diberikan langsung kepada user.
    | Permission hasil role tidak dicampur ke endpoint master ini.
    |--------------------------------------------------------------------------
    */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => [
                    'required',
                    'integer',
                    Rule::exists('users', 'id'),
                ],
            ]);

            $userId = (int) $validated['user_id'];

            $user = User::query()
                ->select([
                    'id',
                    'name',
                    'username',
                    'email',
                    'cabang_id',
                    'departemen_id',
                    'is_active',
                ])
                ->findOrFail($userId);

            $userPermissions = UserPermission::query()
                ->with([
                    'permission' => function ($query) {
                        $query->select([
                            'id',
                            'module',
                            'action',
                            'code',
                            'name',
                            'description',
                            'is_active',
                        ]);
                    },

                    'departments' => function ($query) {
                        $query
                            ->select([
                                'departments.id',
                                'departments.kode',
                                'departments.nama',
                            ])
                            ->orderBy('departments.nama');
                    },
                ])
                ->where('user_id', $userId)
                ->get()
                ->sortBy(function (UserPermission $userPermission) {
                    $permission = $userPermission->permission;

                    $actionOrder = match (strtolower(
                        trim(
                            (string) $permission?->action,
                        ),
                    )) {
                        'view' => 1,
                        'create' => 2,
                        'update' => 3,
                        'delete' => 4,
                        'approve' => 5,
                        'submit' => 6,
                        default => 99,
                    };

                    return sprintf(
                        '%s|%02d|%s',
                        strtolower(
                            (string) $permission?->module,
                        ),
                        $actionOrder,
                        strtolower(
                            (string) $permission?->name,
                        ),
                    );
                })
                ->map(function (
                    UserPermission $userPermission,
                ) {
                    $permission = $userPermission->permission;

                    return [
                        'id' => $userPermission->id,
                        'user_id' => $userPermission->user_id,
                        'permission_id' => $userPermission->permission_id,

                        'scope' => strtoupper(
                            (string) $userPermission->scope,
                        ),

                        'is_active' => (bool) $userPermission->is_active,

                        'department_ids' => $userPermission
                            ->departments
                            ->pluck('id')
                            ->map(
                                fn($id): int => (int) $id,
                            )
                            ->values(),

                        'departments' => $userPermission
                            ->departments
                            ->map(function ($department) {
                                return [
                                    'id' => (int) $department->id,
                                    'kode' => $department->kode,
                                    'nama' => $department->nama,
                                ];
                            })
                            ->values(),

                        'created_at' => $userPermission->created_at,
                        'updated_at' => $userPermission->updated_at,

                        'permission' => $permission
                            ? [
                                'id' => (int) $permission->id,
                                'module' => $permission->module,
                                'action' => $permission->action,
                                'code' => $permission->code,
                                'name' => $permission->name,
                                'description' => $permission->description,
                                'is_active' => (bool) $permission->is_active,
                            ]
                            : null,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Data direct permission user berhasil dimuat.',
                'data' => [
                    'user' => [
                        'id' => (int) $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'email' => $user->email,
                        'cabang_id' => $user->cabang_id
                            ? (int) $user->cabang_id
                            : null,
                        'department_id' => $user->departemen_id
                            ? (int) $user->departemen_id
                            : null,
                        'is_active' => (bool) $user->is_active,
                    ],

                    'permissions' => $userPermissions,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error(
                '[User Permission] Index error',
                [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'request' => $request->all(),
                    'actor_user_id' => $request->user()?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat direct permission user.',
                'data' => [
                    'user' => null,
                    'permissions' => [],
                ],
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Simpan Direct Permission User
    |--------------------------------------------------------------------------
    |
    | Permission aktif:
    | - dibuat atau diperbarui pada user_permissions.
    |
    | Permission tidak aktif:
    | - record direct permission dihapus.
    | - permission efektif kembali menggunakan role.
    |
    | ASSIGNED_DEPARTMENTS:
    | - wajib memiliki minimal satu department.
    |--------------------------------------------------------------------------
    */
    public function bulkUpdate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => [
                    'required',
                    'integer',
                    Rule::exists('users', 'id'),
                ],

                'permissions' => [
                    'required',
                    'array',
                    'min:1',
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
                | Compatibility
                |--------------------------------------------------------------------------
                | Bila frontend masih mengirim is_allowed.
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
                        UserPermission::SCOPE_NONE,
                        UserPermission::SCOPE_OWN_DATA,
                        UserPermission::SCOPE_OWN_DEPARTMENT,
                        UserPermission::SCOPE_OWN_CABANG,
                        UserPermission::SCOPE_ASSIGNED_DEPARTMENTS,
                        UserPermission::SCOPE_ALL,
                    ]),
                ],

                'permissions.*.department_ids' => [
                    'sometimes',
                    'array',
                ],

                'permissions.*.department_ids.*' => [
                    'required',
                    'integer',
                    'distinct',
                    Rule::exists('departments', 'id'),
                ],
            ]);

            $userId = (int) $validated['user_id'];

            $permissionPayloads = collect(
                $validated['permissions'],
            );

            /*
            |--------------------------------------------------------------------------
            | Validasi ASSIGNED_DEPARTMENTS
            |--------------------------------------------------------------------------
            */
            foreach (
                $permissionPayloads as $index => $permissionPayload
            ) {
                $isActive = (bool) (
                    $permissionPayload['is_active']
                    ?? $permissionPayload['is_allowed']
                    ?? false
                );

                if (!$isActive) {
                    continue;
                }

                $scope = strtoupper(
                    trim(
                        (string) (
                            $permissionPayload['scope']
                            ?? UserPermission::SCOPE_NONE
                        ),
                    ),
                );

                $departmentIds = collect(
                    $permissionPayload['department_ids']
                        ?? [],
                )
                    ->map(
                        fn($id): int => (int) $id,
                    )
                    ->filter(
                        fn($id): bool => $id > 0,
                    )
                    ->unique()
                    ->values();

                if (
                    $scope
                    === UserPermission::SCOPE_ASSIGNED_DEPARTMENTS
                    && $departmentIds->isEmpty()
                ) {
                    throw ValidationException::withMessages([
                        "permissions.$index.department_ids" => [
                            'Pilih minimal satu department untuk scope Assigned Departments.',
                        ],
                    ]);
                }
            }

            $permissionIds = $permissionPayloads
                ->pluck('permission_id')
                ->map(
                    fn($id): int => (int) $id,
                )
                ->filter(
                    fn($id): bool => $id > 0,
                )
                ->unique()
                ->values();

            $permissions = Permission::query()
                ->whereIn('id', $permissionIds)
                ->get()
                ->keyBy(
                    fn(Permission $permission): int =>
                    (int) $permission->id,
                );

            foreach (
                $permissionPayloads as $index => $permissionPayload
            ) {
                $permissionId = (int) (
                    $permissionPayload['permission_id']
                    ?? 0
                );

                $isActive = (bool) (
                    $permissionPayload['is_active']
                    ?? $permissionPayload['is_allowed']
                    ?? false
                );

                $permission = $permissions->get(
                    $permissionId,
                );

                if (
                    $isActive
                    && (!$permission || !$permission->is_active)
                ) {
                    throw ValidationException::withMessages([
                        "permissions.$index.permission_id" => [
                            'Permission tidak aktif dan tidak dapat diberikan kepada user.',
                        ],
                    ]);
                }
            }

            $actorUserId = $request->user()?->id
                ? (int) $request->user()->id
                : null;

            $result = DB::transaction(
                function () use (
                    $userId,
                    $permissionPayloads,
                    $permissions,
                    $actorUserId,
                ) {
                    $savedCount = 0;
                    $deletedCount = 0;

                    foreach (
                        $permissionPayloads as $permissionPayload
                    ) {
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

                        $isActive = (bool) (
                            $permissionPayload['is_active']
                            ?? $permissionPayload['is_allowed']
                            ?? false
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | Permission tidak dicentang
                        |--------------------------------------------------------------------------
                        | Hapus direct permission supaya kembali fallback ke role.
                        |--------------------------------------------------------------------------
                        */
                        if (!$isActive) {
                            $existingUserPermission
                                = UserPermission::query()
                                ->where(
                                    'user_id',
                                    $userId,
                                )
                                ->where(
                                    'permission_id',
                                    $permissionId,
                                )
                                ->first();

                            if ($existingUserPermission) {
                                /*
                                | user_permission_departments otomatis terhapus
                                | karena foreign key cascadeOnDelete().
                                */
                                $existingUserPermission->delete();
                                $deletedCount++;
                            }

                            continue;
                        }

                        $scope = strtoupper(
                            trim(
                                (string) (
                                    $permissionPayload['scope']
                                    ?? UserPermission::SCOPE_NONE
                                ),
                            ),
                        );

                        $allowedScopes = [
                            UserPermission::SCOPE_NONE,
                            UserPermission::SCOPE_OWN_DATA,
                            UserPermission::SCOPE_OWN_DEPARTMENT,
                            UserPermission::SCOPE_OWN_CABANG,
                            UserPermission::SCOPE_ASSIGNED_DEPARTMENTS,
                            UserPermission::SCOPE_ALL,
                        ];

                        if (
                            !in_array(
                                $scope,
                                $allowedScopes,
                                true,
                            )
                        ) {
                            $scope = UserPermission::SCOPE_NONE;
                        }

                        $departmentIds = collect(
                            $permissionPayload['department_ids']
                                ?? [],
                        )
                            ->map(
                                fn($id): int => (int) $id,
                            )
                            ->filter(
                                fn($id): bool => $id > 0,
                            )
                            ->unique()
                            ->values()
                            ->all();

                        /*
                        |--------------------------------------------------------------------------
                        | Department hanya digunakan oleh ASSIGNED_DEPARTMENTS
                        |--------------------------------------------------------------------------
                        */
                        if (
                            $scope
                            !== UserPermission::SCOPE_ASSIGNED_DEPARTMENTS
                        ) {
                            $departmentIds = [];
                        }

                        $userPermission = UserPermission::query()
                            ->firstOrNew([
                                'user_id' => $userId,
                                'permission_id' => $permissionId,
                            ]);

                        if (!$userPermission->exists) {
                            $userPermission->created_by
                                = $actorUserId;
                        }

                        $userPermission->scope = $scope;
                        $userPermission->is_active = true;
                        $userPermission->updated_by = $actorUserId;

                        $userPermission->save();

                        /*
                        |--------------------------------------------------------------------------
                        | Sync department assignment
                        |--------------------------------------------------------------------------
                        | Scope selain ASSIGNED_DEPARTMENTS akan sync array kosong,
                        | sehingga assignment lama dibersihkan.
                        |--------------------------------------------------------------------------
                        */
                        $userPermission
                            ->departments()
                            ->sync($departmentIds);

                        $savedCount++;
                    }

                    return [
                        'saved_count' => $savedCount,
                        'deleted_count' => $deletedCount,
                    ];
                },
            );

            $user = User::query()
                ->select([
                    'id',
                    'name',
                    'username',
                    'email',
                ])
                ->findOrFail($userId);

            $activeDirectPermissionCount
                = UserPermission::query()
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->count();

            return response()->json([
                'success' => true,
                'message' => 'Direct permission user berhasil disimpan.',
                'data' => [
                    'user' => [
                        'id' => (int) $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'email' => $user->email,
                    ],

                    'saved_count' => $result['saved_count'],
                    'deleted_count' => $result['deleted_count'],

                    'active_direct_permissions'
                    => $activeDirectPermissionCount,
                ],
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error(
                '[User Permission] Bulk update error',
                [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'request' => $request->all(),
                    'actor_user_id' => $request->user()?->id,
                ],
            );

            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan direct permission user.',
                'debug' => app()->environment('local')
                    ? $e->getMessage()
                    : null,
            ], 500);
        }
    }
}
