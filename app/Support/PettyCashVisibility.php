<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Penyaring daftar untuk Petty Cash dan Top Up
|--------------------------------------------------------------------------
| Semantiknya SAMA PERSIS dengan penyaring daftar keempat modul lain, dan
| memang harus sama:
|
|   ALL            seluruh data
|   OWN_DEPARTMENT department sendiri, seluruh cabang
|   OWN_CABANG     cabang sendiri DAN department sendiri
|   OWN_DATA       dokumen buatan sendiri
|   NONE / asing   tidak ada
|
| Dan seperti di sana, PENYETUJU SELALU BISA MELIHAT dokumen yang masuk ke
| alur approval-nya walau di luar cakupannya -- ia memang harus bisa melihat
| apa yang harus ia putuskan. Melihat, bukan mengubah: untuk aksi yang
| mengubah dokumen, yang berlaku DocumentActionScope.
|
| Ditulis sebagai satu kelas yang dipakai dua controller, bukan disalin ke
| masing-masing. Blok yang sama sudah ada empat salinan di aplikasi ini, dan
| salinan kelima serta keenam hanya akan menambah tempat yang bisa menjauh
| diam-diam dari yang lain.
|--------------------------------------------------------------------------
*/
class PettyCashVisibility
{
    public const ALLOWED_SCOPES = ['ALL', 'OWN_DEPARTMENT', 'OWN_CABANG', 'OWN_DATA'];

    /**
     * Konteks visibilitas sebuah akun untuk satu permission view.
     *
     * @return array{scope: string, roleIds: Collection<int, int>, branchIds: Collection<int, int>, departmentIds: Collection<int, int>}
     */
    public static function context(User $user, string $permission): array
    {
        $scope = strtoupper(trim((string) ($user->getPermissionScope($permission) ?? 'NONE')));

        if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
            $scope = 'NONE';
        }

        $roleIds = collect();

        if ($user->getAttribute('id_role')) {
            $roleIds->push((int) $user->getAttribute('id_role'));
        }

        $aktif = $user->getActiveRoleId();

        if ($aktif) {
            $roleIds->push((int) $aktif);
        }

        $roleIds = $roleIds
            ->merge(DB::table('user_roles')->where('user_id', $user->id)->pluck('role_id'))
            ->filter(fn ($id): bool => $id !== null && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        return [
            'scope' => $scope,
            'roleIds' => $roleIds,
            'branchIds' => $user->accessibleBranchIds(),
            'departmentIds' => $user->accessibleDepartmentIds(),
        ];
    }

    /**
     * Memasang saringan pada kueri daftar.
     *
     * @param  array{scope: string, roleIds: Collection, branchIds: Collection, departmentIds: Collection}  $context
     * @param  string  $table            nama tabel dokumennya
     * @param  string  $approvalTable    tabel baris approval-nya
     * @param  string  $approvalRelation nama relasi approvals pada modelnya
     */
    public static function apply(
        Builder $query,
        User $user,
        array $context,
        string $table,
        string $approvalTable,
        string $approvalRelation = 'approvals',
    ): void {
        if ($context['scope'] === 'ALL') {
            return;
        }

        $query->where(function ($luar) use ($user, $context, $table, $approvalTable, $approvalRelation): void {
            $luar->where(function ($dalam) use ($user, $context, $table): void {
                self::applyScopeOnly($dalam, $user, $context, $table);
            });

            $luar->orWhereHas(
                $approvalRelation,
                fn ($q) => self::applyApproverMatch($q, $user, $context['roleIds'], $approvalTable),
            );
        });
    }

    /**
     * Saringan cakupan saja, TANPA jalur penyetuju.
     *
     * Dipakai di tempat yang memang tidak boleh melonggar karena seseorang
     * kebetulan penyetuju -- misalnya menghitung ringkasan milik sendiri.
     */
    public static function applyScopeOnly(
        $query,
        User $user,
        array $context,
        string $table,
    ): void {
        $scope = $context['scope'];

        if ($scope === 'ALL') {
            return;
        }

        if ($scope === 'OWN_DATA') {
            $user->id
                ? $query->where($table . '.created_by', $user->id)
                : $query->whereRaw('1 = 0');

            return;
        }

        if ($scope === 'OWN_DEPARTMENT') {
            $context['departmentIds']->isEmpty()
                ? $query->whereRaw('1 = 0')
                : $query->whereIn($table . '.department_id', $context['departmentIds']->all());

            return;
        }

        if ($scope === 'OWN_CABANG') {
            if ($context['branchIds']->isEmpty() || $context['departmentIds']->isEmpty()) {
                $query->whereRaw('1 = 0');

                return;
            }

            /*
            | Cabang dibandingkan sebagai teks: petty_cashes.branch bertipe
            | string, mengikuti claims dan cash_advances.
            */
            $query
                ->whereIn(
                    $table . '.branch',
                    $context['branchIds']->map(fn ($id): string => (string) $id)->all(),
                )
                ->whereIn($table . '.department_id', $context['departmentIds']->all());

            return;
        }

        // NONE atau scope tidak dikenal.
        $query->whereRaw('1 = 0');
    }

    /**
     * Baris approval yang ditujukan ke akun ini atau salah satu role-nya.
     */
    private static function applyApproverMatch(
        $query,
        User $user,
        Collection $roleIds,
        string $approvalTable,
    ): void {
        $query->where(function ($penyetuju) use ($user, $roleIds, $approvalTable): void {
            $penyetuju->where(function ($asUser) use ($user, $approvalTable): void {
                $asUser
                    ->where($approvalTable . '.approver_type', 'USER')
                    ->where($approvalTable . '.approver_id', $user->id);
            });

            if ($roleIds->isNotEmpty()) {
                $penyetuju->orWhere(function ($asRole) use ($roleIds, $approvalTable): void {
                    $asRole
                        ->where($approvalTable . '.approver_type', 'ROLE')
                        ->whereIn($approvalTable . '.approver_id', $roleIds->all());
                });
            }
        });
    }
}
