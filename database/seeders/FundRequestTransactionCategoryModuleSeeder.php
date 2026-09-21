<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Modul Master Keterangan Transaksi
|--------------------------------------------------------------------------
| Mendaftarkan permission module, permission, dan menu untuk halaman kelola
| master keterangan transaksi (modul Pengajuan Dana).
|
| Aman dijalankan berulang -- seluruhnya memakai updateOrInsert.
|--------------------------------------------------------------------------
*/
class FundRequestTransactionCategoryModuleSeeder extends Seeder
{
    private const MODULE_CODE = 'fund_request_transaction_category';

    private const ROUTE_PREFIX = '/master/fund-request-transaction-category';

    private const MENU_NAME = 'Keterangan Transaksi';

    public function run(): void
    {
        $now = now();

        $this->seedPermissionModule($now);
        $this->seedPermissions($now);
        $this->seedMenu($now);
    }

    private function seedPermissionModule($now): void
    {
        DB::table('permission_modules')->updateOrInsert(
            ['code' => self::MODULE_CODE],
            [
                'name' => 'Master Keterangan Transaksi',
                'description' => 'Module pengelolaan master keterangan transaksi untuk FPU.',

                /*
                | Dipakai frontend untuk mencocokkan halaman dengan permission,
                | jadi harus sama persis dengan folder halaman Vue.
                */
                'route_prefix' => self::ROUTE_PREFIX,

                'sort_order' => 18,
                'is_active' => true,

                /*
                | Module ini bukan jenis dokumen approval flow, jadi kolom
                | pendaftarannya sengaja dibiarkan kosong.
                */
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
            ['view', 'View Master Keterangan Transaksi', 'Melihat daftar master keterangan transaksi.'],
            ['create', 'Create Master Keterangan Transaksi', 'Menambah keterangan transaksi baru.'],
            ['update', 'Update Master Keterangan Transaksi', 'Mengubah dan mengaktifkan/menonaktifkan keterangan transaksi.'],
            ['delete', 'Delete Master Keterangan Transaksi', 'Menghapus keterangan transaksi yang belum dipakai.'],
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
                'route_name' => 'master-fund-request-transaction-category',
                'icon' => 'tabler-list-details',
                'order_no' => 17,
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
    }
}
