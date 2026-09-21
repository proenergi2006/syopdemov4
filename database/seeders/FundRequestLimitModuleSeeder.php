<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Modul Master Batas Pengajuan FPU
|--------------------------------------------------------------------------
| Mendaftarkan permission module, permission, dan menu untuk halaman kelola
| batas pengajuan FPU.
|
| Wewenangnya berdiri sendiri. Menaikkan batas berarti melonggarkan kendali
| yang justru dipasang untuk memaksa realisasi -- itu bukan keputusan yang
| pantas dipegang orang yang sedang dibatasi olehnya.
|
| Aman dijalankan berulang -- seluruhnya memakai updateOrInsert.
|--------------------------------------------------------------------------
*/
class FundRequestLimitModuleSeeder extends Seeder
{
    private const MODULE_CODE = 'master_fund_request_limit';

    private const ROUTE_PREFIX = '/master/fund-request-limit';

    private const MENU_NAME = 'Batas Pengajuan FPU';

    public function run(): void
    {
        $now = now();

        $this->seedPermissionModule($now);
        $this->seedPermissions($now);
        $this->grantInitial($now);
        $this->seedMenu($now);
    }

    /**
     * Pemberian awal, meniru master keterangan transaksi.
     *
     * Permission yang baru dibuat tidak dipegang siapa pun, dan halaman yang
     * tidak bisa dibuka siapa pun sama saja dengan tidak ada. Acuannya sengaja
     * master lain di modul yang sama -- BUKAN permission Finance -- supaya
     * kemampuan menaikkan batas tidak otomatis jatuh ke tangan orang yang
     * dibatasi olehnya.
     *
     * Berjalan sekali: begitu sebuah permission sudah punya pemegang, seeder
     * ini tidak menyentuhnya lagi.
     */
    private function grantInitial($now): void
    {
        $acuan = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.code', 'fund_request_transaction_category.view')
            ->where('role_permissions.is_active', true)
            ->get(['role_permissions.role_id', 'role_permissions.scope'])
            ->unique('role_id');

        if ($acuan->isEmpty()) {
            $this->command?->warn('  Tidak ada role acuan; permission batas pengajuan belum diberikan ke siapa pun.');

            return;
        }

        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $permissionId = DB::table('permissions')
                ->where('code', self::MODULE_CODE . '.' . $action)
                ->value('id');

            if (!$permissionId) {
                continue;
            }

            $sudahAda = DB::table('role_permissions')
                ->where('permission_id', $permissionId)
                ->exists();

            if ($sudahAda) {
                continue;
            }

            foreach ($acuan as $grant) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $grant->role_id, 'permission_id' => $permissionId],
                    [
                        'scope' => $grant->scope,
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            $this->command?->info("  {$action}: diberikan ke {$acuan->count()} role.");
        }
    }

    private function seedPermissionModule($now): void
    {
        DB::table('permission_modules')->updateOrInsert(
            ['code' => self::MODULE_CODE],
            [
                'name' => 'Master Batas Pengajuan FPU',
                'description' => 'Module pengelolaan batas jumlah dan umur FPU berjalan per pemohon.',

                /*
                | Dipakai frontend untuk mencocokkan halaman dengan permission,
                | jadi harus sama persis dengan folder halaman Vue.
                */
                'route_prefix' => self::ROUTE_PREFIX,

                'sort_order' => 20,
                'is_active' => true,

                'approval_document_type' => null,
                'approval_document_label' => null,
                'approval_uses_area_matrix' => false,
                'approval_uses_transaction_category' => false,

                'updated_at' => $now,
            ],
        );

        DB::table('permission_modules')
            ->where('code', self::MODULE_CODE)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);
    }

    private function seedPermissions($now): void
    {
        /*
        | Master berlaku lintas cabang dan department, jadi tidak ada satu pun
        | permission yang memakai scope.
        */
        $permissions = [
            ['view', 'View Master Batas Pengajuan FPU', 'Melihat batas jumlah dan umur FPU berjalan yang berlaku.'],
            ['create', 'Create Master Batas Pengajuan FPU', 'Menambah batas baru, termasuk batas khusus per area atau department.'],
            ['update', 'Update Master Batas Pengajuan FPU', 'Mengubah jumlah FPU berjalan dan batas umur realisasi yang diizinkan.'],
            ['delete', 'Delete Master Batas Pengajuan FPU', 'Menghapus batas yang sudah tidak dipakai.'],
        ];

        foreach ($permissions as [$action, $name, $description]) {
            $code = self::MODULE_CODE . '.' . $action;

            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => self::MODULE_CODE,
                    'action' => $action,
                    'name' => $name,
                    'description' => $description,
                    'requires_scope' => false,
                    'is_active' => true,
                    'updated_at' => $now,
                ],
            );

            DB::table('permissions')
                ->where('code', $code)
                ->whereNull('created_at')
                ->update(['created_at' => $now]);
        }
    }

    private function seedMenu($now): void
    {
        $masterMenuId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Master')
            ->value('id');

        if (!$masterMenuId) {
            return;
        }

        DB::table('menus')->updateOrInsert(
            [
                'name' => self::MENU_NAME,
                'parent_id' => $masterMenuId,
            ],
            [
                'path' => self::ROUTE_PREFIX,
                'route_name' => 'master-fund-request-limit',
                'icon' => 'tabler-hand-stop',
                'order_no' => 19,
                'is_active' => true,
                'show_in_sidebar' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('menus')
            ->where('parent_id', $masterMenuId)
            ->where('name', self::MENU_NAME)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);

        $this->grantMenu($masterMenuId);
    }

    /**
     * Memetakan menunya ke role.
     *
     * Sidebar dibangun dari role_menus, bukan dari permission. Menu yang tidak
     * dipetakan ke satu role pun tidak terlihat oleh siapa pun -- termasuk
     * Super Administrator. Jadi mendaftarkan menu tanpa langkah ini sama saja
     * dengan tidak membuatnya.
     *
     * Acuannya SATU menu master yang sekeluarga -- Keterangan Transaksi, master
     * modul pengajuan dana yang sama. Bukan "role mana pun yang punya menu
     * Master lain": menu di bawah Master dilihat kelompok orang yang
     * berbeda-beda, dan meniru gabungannya akan membagikan halaman ini jauh
     * lebih luas daripada yang pantas.
     *
     * Berjalan sekali: begitu menunya sudah punya pemetaan, tidak disentuh
     * lagi -- sehingga pencabutan akses lewat pengaturan role tidak dikembalikan
     * diam-diam oleh seeder.
     */
    private function grantMenu(int $masterMenuId): void
    {
        $menuId = DB::table('menus')
            ->where('parent_id', $masterMenuId)
            ->where('name', self::MENU_NAME)
            ->value('id');

        if (!$menuId) {
            return;
        }

        if (DB::table('role_menus')->where('menu_id', $menuId)->exists()) {
            return;
        }

        $acuanId = DB::table('menus')
            ->where('parent_id', $masterMenuId)
            ->where('path', '/master/fund-request-transaction-category')
            ->value('id');

        $roleIds = $acuanId
            ? DB::table('role_menus')->where('menu_id', $acuanId)->distinct()->pluck('role_id')
            : collect();

        if ($roleIds->isEmpty()) {
            $this->command?->warn('  Tidak ada role acuan; menu batas pengajuan belum dipetakan.');

            return;
        }

        foreach ($roleIds as $roleId) {
            DB::table('role_menus')->updateOrInsert(
                ['role_id' => $roleId, 'menu_id' => $menuId],
                [],
            );
        }

        $this->command?->info("  menu: dipetakan ke {$roleIds->count()} role.");
    }
}
