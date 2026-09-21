<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Modul Claim
|--------------------------------------------------------------------------
| Mendaftarkan permission module, permission, dan menu untuk Claim pada menu
| Pengajuan Dana. Aman dijalankan berulang -- seluruhnya memakai
| updateOrInsert.
|
| Permission yang didaftarkan baru yang tahapnya sudah dibangun (CRUD).
| Submit, approval, pembayaran, dan export menyusul bersama tahapnya, supaya
| matriks Role Permission tidak dipenuhi akses yang belum berfungsi.
|--------------------------------------------------------------------------
*/
class ClaimModuleSeeder extends Seeder
{
    private const MODULE_CODE = 'claim';

    public function run(): void
    {
        $now = now();

        $this->seedPermissionModule($now);
        $this->seedPermissions($now);
        $this->seedMenu($now);
    }

    /*
    |--------------------------------------------------------------------------
    | Permission module
    |--------------------------------------------------------------------------
    | route_prefix dipakai frontend untuk mencocokkan halaman dengan permission.
    | Nilainya harus sama persis dengan folder halaman Vue.
    |--------------------------------------------------------------------------
    */
    private function seedPermissionModule($now): void
    {
        DB::table('permission_modules')->updateOrInsert(
            ['code' => self::MODULE_CODE],
            [
                'name' => 'Claim',
                'description' => 'Module penggantian uang yang sudah lebih dulu dikeluarkan pemohon, pada menu Pengajuan Dana.',
                'route_prefix' => '/fund_request/claim',
                'sort_order' => 62,
                'is_active' => true,

                /*
                | Mendaftarkan Claim sebagai jenis dokumen Approval Flow.
                |
                | approval_uses_area_matrix true karena flow Claim dipilih dari
                | area (HO / Cabang) dan department pemohon. Batas nilai dan
                | keterangan transaksi sengaja tidak dipakai: berapa pun
                | nilainya, approver-nya sama.
                */
                'approval_document_type' => 'CLAIM',
                'approval_document_label' => 'Claim',
                'approval_uses_area_matrix' => true,

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
        $permissions = [
            [
                'action' => 'view',
                'name' => 'View Claim',
                'description' => 'Melihat daftar dan detail Claim sesuai scope akses.',
                'requires_scope' => true,
            ],
            [
                'action' => 'create',
                'name' => 'Create Claim',
                'description' => 'Membuat Claim baru.',
                'requires_scope' => false,
            ],
            [
                'action' => 'update',
                'name' => 'Update Claim',
                'description' => 'Mengubah Claim yang masih berstatus draft.',
                'requires_scope' => true,
            ],
            [
                'action' => 'delete',
                'name' => 'Delete Claim',
                'description' => 'Menghapus Claim yang masih berstatus draft.',
                'requires_scope' => true,
            ],
            [
                'action' => 'submit',
                'name' => 'Submit Claim',
                'description' => 'Mengajukan Claim ke proses approval.',
                'requires_scope' => true,
            ],
            [
                'action' => 'cancel',
                'name' => 'Cancel Claim',
                'description' => 'Membatalkan Claim yang sudah disetujui tetapi belum dibayarkan.',
                'requires_scope' => true,
            ],
            /*
            | Penerimaan mendahului pembayaran: PIC menandai berkasnya sudah
            | diterima untuk diproses, baru setelah itu Finance membayarkan.
            | Sama seperti pembayaran, tidak memakai scope.
            */
            [
                'action' => 'receive',
                'name' => 'Receive Claim',
                'description' => 'Menandai Claim yang sudah disetujui sudah diterima, sebelum dibayarkan.',
                'requires_scope' => false,
            ],
            /*
            | Pembayaran adalah wewenang Finance, terpisah dari approval dan
            | menjadi tahap terakhir Claim -- tidak ada realisasi setelahnya.
            | Tidak memakai scope karena Finance melayani seluruh cabang.
            */
            [
                'action' => 'pay',
                'name' => 'Pay Claim',
                'description' => 'Menandai Claim sudah diganti/dibayarkan kepada pemohon.',
                'requires_scope' => false,
            ],
            /*
            | Export tidak memakai scope. Isi file sudah dibatasi visibility
            | permission view, jadi permission ini hanya menjawab boleh atau
            | tidaknya menarik data keluar sistem.
            */
            [
                'action' => 'export',
                'name' => 'Export Claim',
                'description' => 'Export data Claim ke Excel, sesuai data yang tampil pada daftar.',
                'requires_scope' => false,
            ],
        ];

        foreach ($permissions as $permission) {
            $code = self::MODULE_CODE . '.' . $permission['action'];

            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => self::MODULE_CODE,
                    'action' => $permission['action'],
                    'name' => $permission['name'],
                    'description' => $permission['description'],
                    'requires_scope' => $permission['requires_scope'],
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

    /*
    |--------------------------------------------------------------------------
    | Menu
    |--------------------------------------------------------------------------
    | Menempel pada menu induk "Pengajuan Dana" yang dibuat CashAdvanceModuleSeeder.
    |--------------------------------------------------------------------------
    */
    private function seedMenu($now): void
    {
        $parentId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Pengajuan Dana')
            ->value('id');

        if (!$parentId) {
            return;
        }

        DB::table('menus')->updateOrInsert(
            [
                'name' => 'Claim',
                'parent_id' => $parentId,
            ],
            [
                'path' => '/fund_request/claim',
                'route_name' => 'claim',
                'icon' => 'tabler-receipt-2',
                'order_no' => 3,
                'is_active' => true,
                'show_in_sidebar' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('menus')
            ->where('parent_id', $parentId)
            ->where('name', 'Claim')
            ->whereNull('created_at')
            ->update(['created_at' => $now]);
    }
}
