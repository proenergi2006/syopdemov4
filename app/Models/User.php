<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'cabang_id',
        'departemen_id',
        'is_active',
        'signature_path',
        'signature_uploaded_at',
        'username',
        'last_login_at',
        'last_logout_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'signature_uploaded_at' => 'datetime',
        'last_login_at' => 'datetime',
        'last_logout_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    | Relasi role user memakai pivot table user_roles.
    |--------------------------------------------------------------------------
    */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Cabang - Alias utama
    |--------------------------------------------------------------------------
    | Controller index memakai:
    | with('cabang:id,nama')
    |--------------------------------------------------------------------------
    */
    public function cabang()
    {
        return $this->belongsTo(Cabang::class, 'cabang_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Cabang - Alias lama
    |--------------------------------------------------------------------------
    | Dipertahankan supaya code existing yang pakai cabangData tetap aman.
    |--------------------------------------------------------------------------
    */
    public function cabangData()
    {
        return $this->belongsTo(Cabang::class, 'cabang_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Department - Alias utama
    |--------------------------------------------------------------------------
    | Controller index memakai:
    | with('departemen:id,nama')
    |--------------------------------------------------------------------------
    */
    public function departemen()
    {
        return $this->belongsTo(Department::class, 'departemen_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Department - Alias lama
    |--------------------------------------------------------------------------
    | Dipertahankan supaya code existing yang pakai departmentData tetap aman.
    |--------------------------------------------------------------------------
    */
    public function departmentData()
    {
        return $this->belongsTo(Department::class, 'departemen_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Cabang Pivot
    |--------------------------------------------------------------------------
    | Kalau nanti user bisa punya lebih dari satu cabang lewat table user_cabang.
    |--------------------------------------------------------------------------
    */
    public function cabangs()
    {
        return $this->belongsToMany(Cabang::class, 'user_cabang', 'user_id', 'cabang_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Direct Permission Records
    |--------------------------------------------------------------------------
    | Konfigurasi permission yang diberikan langsung kepada akun.
    |--------------------------------------------------------------------------
    */
    public function userPermissions(): HasMany
    {
        return $this->hasMany(
            UserPermission::class,
            'user_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Direct Permissions
    |--------------------------------------------------------------------------
    | Relasi langsung ke master permissions melalui user_permissions.
    |--------------------------------------------------------------------------
    */
    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'user_permissions',
            'user_id',
            'permission_id',
        )
            ->withPivot([
                'scope',
                'is_active',
                'created_by',
                'updated_by',
            ])
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | Active Direct Permission
    |--------------------------------------------------------------------------
    | Mengambil konfigurasi permission aktif yang diberikan langsung
    | kepada akun berdasarkan permission code.
    |--------------------------------------------------------------------------
    */
    public function getActiveDirectPermission(
        string $permissionCode,
    ): ?UserPermission {
        $permissionCode = trim($permissionCode);

        if ($permissionCode === '') {
            return null;
        }

        return UserPermission::query()
            ->with([
                'permission:id,code,name,is_active',
                'departments:id,nama',
            ])
            ->where('user_id', $this->id)
            ->where('is_active', true)
            ->whereHas(
                'permission',
                function ($query) use ($permissionCode) {
                    $query
                        ->where('code', $permissionCode)
                        ->where('is_active', true);
                },
            )
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Has Direct Permission
    |--------------------------------------------------------------------------
    | Hanya mengecek permission yang diberikan langsung kepada akun.
    | Belum melakukan fallback ke role.
    |--------------------------------------------------------------------------
    */
    public function hasDirectPermission(
        string $permissionCode,
    ): bool {
        return $this->getActiveDirectPermission(
            $permissionCode,
        ) !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Direct Permission Scope
    |--------------------------------------------------------------------------
    | Return null apabila direct permission tidak ditemukan.
    |
    | Null berbeda dengan NONE:
    | - null = tidak ada direct permission, nanti boleh fallback ke role
    | - NONE = direct permission ada tetapi tidak memiliki data scope
    |--------------------------------------------------------------------------
    */
    public function getDirectPermissionScope(
        string $permissionCode,
    ): ?string {
        $directPermission
            = $this->getActiveDirectPermission(
                $permissionCode,
            );

        if (!$directPermission) {
            return null;
        }

        return $this->normalizePermissionScope(
            $directPermission->scope,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Assigned Department IDs
    |--------------------------------------------------------------------------
    | Mengambil daftar department dari direct permission tertentu.
    | Hanya berlaku saat scope = ASSIGNED_DEPARTMENTS.
    |--------------------------------------------------------------------------
    */
    public function getAssignedDepartmentIds(
        string $permissionCode,
    ): array {
        $directPermission
            = $this->getActiveDirectPermission(
                $permissionCode,
            );

        if (!$directPermission) {
            return [];
        }

        $scope = $this->normalizePermissionScope(
            $directPermission->scope,
        );

        if ($scope !== UserPermission::SCOPE_ASSIGNED_DEPARTMENTS) {
            return [];
        }

        return $directPermission
            ->departments
            ->pluck('id')
            ->map(
                fn($departmentId): int =>
                (int) $departmentId,
            )
            ->unique()
            ->values()
            ->all();
    }

    /*
|--------------------------------------------------------------------------
| Allowed Department IDs for Permission
|--------------------------------------------------------------------------
|
| Return:
| - null     = seluruh department diizinkan
| - []       = tidak ada department yang diizinkan
| - [1,2,3]  = hanya department tersebut yang diizinkan
|
| Method ini menggunakan permission efektif:
| direct permission terlebih dahulu, kemudian fallback ke role.
|--------------------------------------------------------------------------
*/
    public function getAllowedDepartmentIdsForPermission(
        string $permissionCode,
    ): ?array {
        $permissionCode = trim($permissionCode);

        if (
            $permissionCode === ''
            || !$this->hasPermission($permissionCode)
        ) {
            return [];
        }

        $scope = $this->getPermissionScope(
            $permissionCode,
        );

        return match ($scope) {
            UserPermission::SCOPE_ALL => null,

            UserPermission::SCOPE_OWN_DEPARTMENT =>
            $this->departemen_id
                ? [(int) $this->departemen_id]
                : [],

            UserPermission::SCOPE_ASSIGNED_DEPARTMENTS =>
            $this->getAssignedDepartmentIds(
                $permissionCode,
            ),

            default => [],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Can Access Department for Permission
    |--------------------------------------------------------------------------
    |
    | Mengecek apakah permission efektif user mengizinkan akses
    | ke department tertentu.
    |--------------------------------------------------------------------------
    */
    public function canAccessDepartmentForPermission(
        string $permissionCode,
        int $departmentId,
    ): bool {
        if ($departmentId <= 0) {
            return false;
        }

        $allowedDepartmentIds
            = $this->getAllowedDepartmentIdsForPermission(
                $permissionCode,
            );

        /*
     * Null berarti scope ALL.
     */
        if ($allowedDepartmentIds === null) {
            return true;
        }

        return in_array(
            $departmentId,
            $allowedDepartmentIds,
            true,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Can Access Department by Direct Permission
    |--------------------------------------------------------------------------
    | Helper khusus untuk mengecek scope direct permission terhadap department.
    |
    | Belum melakukan fallback ke role.
    |--------------------------------------------------------------------------
    */
    public function canAccessDepartmentByDirectPermission(
        string $permissionCode,
        int $departmentId,
    ): bool {
        if ($departmentId <= 0) {
            return false;
        }

        $directPermission
            = $this->getActiveDirectPermission(
                $permissionCode,
            );

        if (!$directPermission) {
            return false;
        }

        $scope = $this->normalizePermissionScope(
            $directPermission->scope,
        );

        return match ($scope) {
            UserPermission::SCOPE_ALL => true,

            UserPermission::SCOPE_OWN_DEPARTMENT =>
            (int) $this->departemen_id
                === $departmentId,

            UserPermission::SCOPE_ASSIGNED_DEPARTMENTS =>
            in_array(
                $departmentId,
                $this->getAssignedDepartmentIds(
                    $permissionCode,
                ),
                true,
            ),

            default => false,
        };
    }

    // Permissions
    public function hasPermission(
        string $permissionCode,
    ): bool {
        $permissionCode = trim($permissionCode);

        if ($permissionCode === '') {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Prioritas 1: Direct Permission
        |--------------------------------------------------------------------------
        | Jika permission aktif diberikan langsung kepada akun,
        | permission tersebut langsung berlaku.
        |--------------------------------------------------------------------------
        */
        if ($this->hasDirectPermission($permissionCode)) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Prioritas 2: Role Permission Existing
        |--------------------------------------------------------------------------
        | Jika direct permission tidak ditemukan, gunakan mekanisme role lama.
        |--------------------------------------------------------------------------
        */
        $roleId = $this->getActiveRoleId();

        if (!$roleId) {
            return false;
        }

        return RolePermission::query()
            ->join(
                'permissions',
                'permissions.id',
                '=',
                'role_permissions.permission_id',
            )
            ->where(
                'role_permissions.role_id',
                $roleId,
            )
            ->where(
                'permissions.code',
                $permissionCode,
            )
            ->where(
                'role_permissions.is_active',
                true,
            )
            ->where(
                'permissions.is_active',
                true,
            )
            ->exists();
    }

    public function getActiveRoleId(): ?int
    {
        $roleId = DB::table('user_roles')
            ->where('user_id', $this->id)
            ->value('role_id');

        return $roleId !== null
            ? (int) $roleId
            : null;
    }

    public function getPermissionScope(
        string $permissionCode,
    ): string {
        $permissionCode = trim($permissionCode);

        if ($permissionCode === '') {
            return UserPermission::SCOPE_NONE;
        }

        /*
        |--------------------------------------------------------------------------
        | Prioritas 1: Direct Permission Scope
        |--------------------------------------------------------------------------
        | null berarti direct permission tidak ditemukan,
        | sehingga masih boleh fallback ke role.
        |
        | NONE berarti direct permission ditemukan dengan scope NONE,
        | sehingga hasilnya tetap NONE dan tidak fallback ke role.
        |--------------------------------------------------------------------------
        */
        $directScope = $this->getDirectPermissionScope(
            $permissionCode,
        );

        if ($directScope !== null) {
            return $directScope;
        }

        /*
        |--------------------------------------------------------------------------
        | Prioritas 2: Role Permission Existing
        |--------------------------------------------------------------------------
        */
        $roleId = $this->getActiveRoleId();

        if (!$roleId) {
            return UserPermission::SCOPE_NONE;
        }

        $roleScope = RolePermission::query()
            ->join(
                'permissions',
                'permissions.id',
                '=',
                'role_permissions.permission_id',
            )
            ->where(
                'role_permissions.role_id',
                $roleId,
            )
            ->where(
                'permissions.code',
                $permissionCode,
            )
            ->where(
                'role_permissions.is_active',
                true,
            )
            ->where(
                'permissions.is_active',
                true,
            )
            ->value('role_permissions.scope');

        return $this->normalizePermissionScope(
            $roleScope,
        );
    }

    public function getPermissionAbilities(): array
    {
        $permissions = [];

        /*
    |--------------------------------------------------------------------------
    | Load Role Permission dan Direct User Permission
    |--------------------------------------------------------------------------
    */
        $this->loadMissing([
            'roles.permissions' => function ($query) {
                $query
                    ->where(
                        'permissions.is_active',
                        true,
                    )
                    ->wherePivot(
                        'is_active',
                        true,
                    );
            },

            'userPermissions' => function ($query) {
                $query
                    ->where(
                        'is_active',
                        true,
                    )
                    ->with([
                        'permission' => function ($permissionQuery) {
                            $permissionQuery->where(
                                'permissions.is_active',
                                true,
                            );
                        },

                        'departments:id',
                    ]);
            },
        ]);

        /*
    |--------------------------------------------------------------------------
    | 1. Permission dari Role
    |--------------------------------------------------------------------------
    */
        foreach ($this->roles as $role) {
            foreach ($role->permissions as $permission) {
                $code = trim(
                    (string) $permission->code,
                );

                if ($code === '') {
                    continue;
                }

                $scope = $this->normalizePermissionScope(
                    $permission->pivot->scope
                        ?? UserPermission::SCOPE_NONE,
                );

                if (!isset($permissions[$code])) {
                    $permissions[$code] = [
                        'allowed' => true,
                        'scope' => $scope,
                        'department_ids' => [],
                    ];

                    continue;
                }

                $currentScope = $permissions[$code]['scope']
                    ?? UserPermission::SCOPE_NONE;

                if (
                    $this->getScopePriority($scope)
                    > $this->getScopePriority($currentScope)
                ) {
                    $permissions[$code]['scope'] = $scope;
                }
            }
        }

        /*
    |--------------------------------------------------------------------------
    | 2. Direct Permission dari User
    |--------------------------------------------------------------------------
    | Direct permission menimpa role permission dengan code yang sama.
    |--------------------------------------------------------------------------
    */
        foreach ($this->userPermissions as $userPermission) {
            $permission = $userPermission->permission;

            /*
         * Permission master mungkin sudah tidak aktif.
         */
            if (!$permission) {
                continue;
            }

            $code = trim(
                (string) $permission->code,
            );

            if ($code === '') {
                continue;
            }

            $scope = $this->normalizePermissionScope(
                $userPermission->scope
                    ?? UserPermission::SCOPE_NONE,
            );

            $departmentIds = [];

            if (
                $scope
                === UserPermission::SCOPE_ASSIGNED_DEPARTMENTS
            ) {
                $departmentIds = $userPermission
                    ->departments
                    ->pluck('id')
                    ->map(
                        fn($departmentId): int =>
                        (int) $departmentId,
                    )
                    ->unique()
                    ->values()
                    ->all();
            }

            $permissions[$code] = [
                'allowed' => true,
                'scope' => $scope,
                'department_ids' => $departmentIds,
            ];
        }

        ksort($permissions);

        return $permissions;
    }

    public function accessAssignments(): HasMany
    {
        return $this->hasMany(
            UserAccessAssignment::class,
            'user_id',
        );
    }

    public function activeAccessAssignments(): HasMany
    {
        return $this->accessAssignments()
            ->where('is_active', true);
    }

    public function primaryAccessAssignment()
    {
        return $this->hasOne(
            UserAccessAssignment::class,
            'user_id',
        )
            ->where('is_primary', true)
            ->where('is_active', true);
    }

    public function canAccessBranchDepartment(
        int|string|null $branchId,
        int|string|null $departmentId,
    ): bool {
        $branchId = (int) $branchId;
        $departmentId = (int) $departmentId;

        if ($branchId <= 0 || $departmentId <= 0) {
            return false;
        }

        return $this->activeAccessAssignments()
            ->where('branch_id', $branchId)
            ->where('department_id', $departmentId)
            ->exists();
    }

    public function accessibleBranchIds()
    {
        return $this->activeAccessAssignments()
            ->pluck('branch_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function accessibleDepartmentIds(?int $branchId = null)
    {
        $query = $this->activeAccessAssignments();

        if ($branchId !== null && $branchId > 0) {
            $query->where('branch_id', $branchId);
        }

        return $query
            ->pluck('department_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function normalizePermissionScope(?string $scope): string
    {
        $scope = strtoupper(trim((string) $scope));

        $allowedScopes = [
            'NONE',
            'OWN_DATA',
            'OWN_DEPARTMENT',
            'OWN_CABANG',
            'ASSIGNED_DEPARTMENTS',
            'ALL',
        ];

        return in_array($scope, $allowedScopes, true)
            ? $scope
            : 'NONE';
    }

    private function getScopePriority(string $scope): int
    {
        return match (strtoupper($scope)) {
            'ALL' => 4,
            'OWN_CABANG' => 3,
            'OWN_DEPARTMENT' => 2,
            'OWN_DATA' => 1,
            default => 0,
        };
    }
}
