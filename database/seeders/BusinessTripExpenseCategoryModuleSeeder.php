<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Modul Master Kategori Biaya Perdin
|--------------------------------------------------------------------------
| Mendaftarkan permission module, permission, menu, dan tiga kategori
| awalnya: Transportasi, Penginapan, Uang Saku.
|
| Aman dijalankan berulang -- seluruhnya memakai updateOrInsert, dan
| kategorinya hanya dibuat bila memang belum ada. Kategori yang sudah
| disunting orang tidak dikembalikan ke bentuk semula.
|--------------------------------------------------------------------------
*/
class BusinessTripExpenseCategoryModuleSeeder extends Seeder
{
    private const MODULE_CODE = 'business_trip_expense_category';

    private const ROUTE_PREFIX = '/master/business-trip-expense-category';

    private const MENU_NAME = 'Kategori Biaya Perdin';

    public function run(): void
    {
        $now = now();

        $this->seedPermissionModule($now);
        $this->seedPermissions($now);
        $this->seedMenu($now);
        $this->seedCategories($now);
        $this->grantToNeighbourHolders($now);
    }

    private function seedPermissionModule($now): void
    {
        DB::table('permission_modules')->updateOrInsert(
            ['code' => self::MODULE_CODE],
            [
                'name' => 'Master Kategori Biaya Perdin',
                'description' => 'Module pengelolaan kategori biaya pada rincian FPU dan Realisasi perjalanan dinas.',

                /*
                | Dipakai frontend untuk mencocokkan halaman dengan permission,
                | jadi harus sama persis dengan folder halaman Vue.
                */
                'route_prefix' => self::ROUTE_PREFIX,

                'sort_order' => 19,
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
        | Master dipakai lintas cabang dan department, jadi tidak ada satu pun
        | permission yang memakai scope.
        */
        $permissions = [
            ['view', 'View Master Kategori Biaya Perdin', 'Melihat daftar kategori biaya perjalanan dinas.'],
            ['create', 'Create Master Kategori Biaya Perdin', 'Menambah kategori biaya perjalanan dinas.'],
            ['update', 'Update Master Kategori Biaya Perdin', 'Mengubah dan mengaktifkan/menonaktifkan kategori biaya.'],
            ['delete', 'Delete Master Kategori Biaya Perdin', 'Menghapus kategori biaya yang belum dipakai dokumen mana pun.'],
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
                'route_name' => 'master-business-trip-expense-category',
                'icon' => 'tabler-category',
                'order_no' => 20,
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

        /*
        | Menunya diberikan ke role yang sudah memegang master Keterangan
        | Transaksi -- tetangga terdekatnya, dan orang yang sama yang mengurus
        | keduanya.
        |
        | Sekali saja: begitu menunya punya pemegang mana pun, seeder tidak
        | menyentuhnya lagi. Pengaturan yang sudah sengaja diubah admin tidak
        | boleh dikembalikan tiap seeder dijalankan.
        */
        $menuId = DB::table('menus')
            ->where('parent_id', $masterMenuId)
            ->where('name', self::MENU_NAME)
            ->value('id');

        if (!$menuId || DB::table('role_menus')->where('menu_id', $menuId)->exists()) {
            return;
        }

        $tetanggaId = DB::table('menus')
            ->where('parent_id', $masterMenuId)
            ->where('name', 'Keterangan Transaksi')
            ->value('id');

        if (!$tetanggaId) {
            return;
        }

        foreach (DB::table('role_menus')->where('menu_id', $tetanggaId)->pluck('role_id') as $roleId) {
            DB::table('role_menus')->updateOrInsert(
                ['role_id' => $roleId, 'menu_id' => $menuId],
                [],
            );
        }
    }

    /**
     * Pemegang awal permission-nya.
     *
     * Diberikan ke role yang sudah memegang master Keterangan Transaksi --
     * tetangga terdekatnya, dan orang yang sama yang mengurus keduanya.
     *
     * Sekali saja: begitu sebuah permission punya pemegang mana pun, seeder
     * tidak menyentuhnya lagi. Pengaturan yang sudah sengaja diubah admin
     * tidak boleh dikembalikan tiap seeder dijalankan.
     */
    private function grantToNeighbourHolders($now): void
    {
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            $permissionId = DB::table('permissions')
                ->where('code', self::MODULE_CODE . '.' . $action)
                ->value('id');

            if (!$permissionId) {
                continue;
            }

            if (DB::table('role_permissions')->where('permission_id', $permissionId)->exists()) {
                continue;
            }

            $tetanggaId = DB::table('permissions')
                ->where('code', 'fund_request_transaction_category.' . $action)
                ->value('id');

            if (!$tetanggaId) {
                continue;
            }

            $roleIds = DB::table('role_permissions')
                ->where('permission_id', $tetanggaId)
                ->where('is_active', true)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    [
                        'scope' => 'NONE',
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }
        }
    }

    private function seedCategories($now): void
    {
        /*
        | Urutannya mengikuti formulir kertas: Transportasi, Penginapan, Uang
        | Saku. Jaraknya sepuluh supaya kategori baru bisa diselipkan di antara
        | tanpa menggeser yang lain.
        */
        $kategori = [
            ['Transportasi', 'BBM, tol, transport lokal, dan biaya perjalanan lainnya.', 10],
            ['Penginapan', 'Hotel atau tempat menginap selama perjalanan.', 20],
            ['Uang Saku', 'Uang saku harian untuk yang berangkat.', 30],
        ];

        foreach ($kategori as [$nama, $keterangan, $urutan]) {
            $ada = DB::table('business_trip_expense_categories')
                ->where('name', $nama)
                ->whereNull('deleted_at')
                ->exists();

            if ($ada) {
                continue;
            }

            DB::table('business_trip_expense_categories')->insert([
                'name' => $nama,
                'description' => $keterangan,
                'sort_order' => $urutan,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
