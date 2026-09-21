<?php

namespace App\Services\Permission;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Penerima berdasarkan kepemilikan permission
|--------------------------------------------------------------------------
| Dipakai pemberitahuan yang berada di LUAR approval flow -- pencairan FPU dan
| penyelesaian selisih Realisasi. Keduanya tidak punya baris approval yang bisa
| dijadikan sumber penerima, jadi penerimanya adalah siapa pun yang memegang
| permission aksinya, persis seperti pemeriksaan tombolnya di controller.
|
| Aturannya disamakan dengan User::hasPermission():
|
| - permission langsung pada akun (user_permissions) yang aktif, ATAU
| - permission pada role aktif akun (user_roles -> role_permissions).
|
| Keduanya digabung, bukan berjenjang, karena permission langsung hanya pernah
| memberi akses dan tidak pernah mencabutnya. Baris nonaktif diperlakukan
| seolah tidak ada, sehingga akun itu masih bisa lolos lewat rolenya.
|--------------------------------------------------------------------------
*/
class PermissionRecipientService
{
    /**
     * Semua akun aktif yang memegang permission tersebut.
     *
     * @return Collection<int, User>
     */
    public function usersWithPermission(string $permissionCode): Collection
    {
        $permissionId = $this->resolvePermissionId($permissionCode);

        if (!$permissionId) {
            Log::warning('[Permission Recipient] Permission tidak ditemukan atau nonaktif', [
                'permission_code' => $permissionCode,
            ]);

            return collect();
        }

        $userIds = collect()
            ->merge($this->userIdsFromDirectPermission($permissionId))
            ->merge($this->userIdsFromRolePermission($permissionId))
            ->filter(fn ($id): bool => $id !== null && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        $query = User::query()->whereIn('id', $userIds);

        if (Schema::hasColumn('users', 'is_active')) {
            $query->where('is_active', true);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Sama seperti usersWithPermission(), tetapi hanya yang bisa dikirimi email.
     *
     * Deduplikasi kedua dilakukan per alamat email: dua akun berbeda bisa
     * memakai alamat yang sama, dan orang itu tidak perlu menerima email
     * yang sama dua kali.
     *
     * @return Collection<int, User>
     */
    public function mailableUsersWithPermission(string $permissionCode): Collection
    {
        return $this->usersWithPermission($permissionCode)
            ->filter(fn (User $user): bool => filled($user->email))
            ->unique(fn (User $user): string => strtolower(trim((string) $user->email)))
            ->values();
    }

    private function resolvePermissionId(string $permissionCode): ?int
    {
        $permissionCode = trim($permissionCode);

        if ($permissionCode === '') {
            return null;
        }

        $permissionId = Permission::query()
            ->where('code', $permissionCode)
            ->where('is_active', true)
            ->value('id');

        return $permissionId ? (int) $permissionId : null;
    }

    /**
     * @return Collection<int, int>
     */
    private function userIdsFromDirectPermission(int $permissionId): Collection
    {
        if (!Schema::hasTable('user_permissions')) {
            return collect();
        }

        return DB::table('user_permissions')
            ->where('permission_id', $permissionId)
            ->where('is_active', true)
            ->pluck('user_id');
    }

    /**
     * @return Collection<int, int>
     */
    private function userIdsFromRolePermission(int $permissionId): Collection
    {
        if (
            !Schema::hasTable('role_permissions')
            || !Schema::hasTable('user_roles')
        ) {
            return collect();
        }

        $roleIds = DB::table('role_permissions')
            ->where('permission_id', $permissionId)
            ->where('is_active', true)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return collect();
        }

        return DB::table('user_roles')
            ->whereIn('role_id', $roleIds)
            ->pluck('user_id');
    }
}
