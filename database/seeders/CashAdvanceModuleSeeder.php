<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Modul FPU (Form Pengajuan Uang)
|--------------------------------------------------------------------------
| Mendaftarkan permission module, permission, dan menu untuk modul Pengajuan
| Dana. Aman dijalankan berulang -- seluruhnya memakai updateOrInsert.
|--------------------------------------------------------------------------
*/
class CashAdvanceModuleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $this->seedPermissionModule($now);
        $this->seedPermissions($now);
        $this->seedMenus($now);
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
            ['code' => 'cash_advance'],
            [
                'name' => 'FPU (Form Pengajuan Uang)',
                'description' => 'Module pengajuan uang muka pada menu Pengajuan Dana.',
                'route_prefix' => '/fund_request/cash_advance',
                'sort_order' => 60,
                'is_active' => true,

                /*
                | Mendaftarkan FPU sebagai jenis dokumen Approval Flow. Kolom
                | inilah yang mengisi dropdown "Jenis Dokumen" -- daftarnya
                | tidak ada di kode.
                |
                | approval_uses_area_matrix true karena flow FPU dibedakan per
                | area + department + nominal, sama seperti PR.
                */
                'approval_document_type' => 'FPU',
                'approval_document_label' => 'FPU (Form Pengajuan Uang)',
                'approval_uses_area_matrix' => true,

                'updated_at' => $now,
            ],
        );

        DB::table('permission_modules')
            ->where('code', 'cash_advance')
            ->whereNull('created_at')
            ->update(['created_at' => $now]);
    }

    private function seedPermissions($now): void
    {
        $permissions = [
            [
                'action' => 'view',
                'name' => 'View FPU',
                'description' => 'Melihat daftar dan detail FPU sesuai scope akses.',
                'requires_scope' => true,
            ],
            [
                'action' => 'create',
                'name' => 'Create FPU',
                'description' => 'Membuat FPU baru.',
                'requires_scope' => false,
            ],
            [
                'action' => 'update',
                'name' => 'Update FPU',
                'description' => 'Mengubah FPU yang masih berstatus draft.',
                'requires_scope' => true,
            ],
            [
                'action' => 'delete',
                'name' => 'Delete FPU',
                'description' => 'Menghapus FPU yang masih berstatus draft.',
                'requires_scope' => true,
            ],
            [
                'action' => 'submit',
                'name' => 'Submit FPU',
                'description' => 'Mengajukan FPU ke proses approval.',
                'requires_scope' => true,
            ],
            [
                'action' => 'cancel',
                'name' => 'Cancel FPU',
                'description' => 'Membatalkan FPU yang sudah disetujui.',
                'requires_scope' => true,
            ],
            /*
            | Pencairan adalah wewenang Finance, terpisah dari approval. Tidak
            | memakai scope karena Finance melayani seluruh cabang.
            */
            /*
            | Penerimaan mendahului pencairan: PIC menandai dokumennya sudah
            | diterima untuk diproses, baru setelah itu Finance mencairkan.
            | Sama seperti pencairan, tidak memakai scope -- PIC-nya melayani
            | seluruh cabang.
            */
            [
                'action' => 'receive',
                'name' => 'Receive FPU',
                'description' => 'Menandai FPU yang sudah disetujui sudah diterima, sebelum dicairkan.',
                'requires_scope' => false,
            ],
            [
                'action' => 'disburse',
                'name' => 'Disburse FPU',
                'description' => 'Menandai FPU sudah dibayarkan oleh Finance.',
                'requires_scope' => false,
            ],
            /*
            | Export tidak memakai scope. Isi file sudah dibatasi visibility
            | permission view, jadi permission ini hanya menjawab boleh atau
            | tidaknya menarik data keluar sistem.
            */
            [
                'action' => 'export',
                'name' => 'Export FPU',
                'description' => 'Export data FPU ke Excel, sesuai data yang tampil pada daftar.',
                'requires_scope' => false,
            ],
        ];

        foreach ($permissions as $permission) {
            $code = 'cash_advance.' . $permission['action'];

            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => 'cash_advance',
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
    | Menu induk baru "Pengajuan Dana", sejajar dengan Non Stock. FPU dan modul
    | turunannya nanti bernaung di bawahnya.
    |--------------------------------------------------------------------------
    */
    private function seedMenus($now): void
    {
        /*
        | Pengajuan Dana ditempatkan tepat setelah Non Stock (order_no 3),
        | sehingga Auth dan Monitoring digeser satu posisi. Tanpa penggeseran
        | ini, urutan menu bernomor sama menjadi tidak menentu.
        */
        DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Auth')
            ->update(['order_no' => 5, 'updated_at' => $now]);

        DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Monitoring')
            ->update(['order_no' => 6, 'updated_at' => $now]);

        DB::table('menus')->updateOrInsert(
            [
                'name' => 'Pengajuan Dana',
                'parent_id' => null,
            ],
            [
                'path' => null,
                'route_name' => null,
                'icon' => 'tabler-cash',
                'order_no' => 4,
                'is_active' => true,
                'show_in_sidebar' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Pengajuan Dana')
            ->whereNull('created_at')
            ->update(['created_at' => $now]);

        $parentId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Pengajuan Dana')
            ->value('id');

        if (!$parentId) {
            return;
        }

        DB::table('menus')->updateOrInsert(
            [
                'name' => 'FPU',
                'parent_id' => $parentId,
            ],
            [
                'path' => '/fund_request/cash_advance',
                'route_name' => 'cash-advance',
                'icon' => 'tabler-cash-banknote',
                'order_no' => 1,
                'is_active' => true,
                'show_in_sidebar' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('menus')
            ->where('parent_id', $parentId)
            ->where('name', 'FPU')
            ->whereNull('created_at')
            ->update(['created_at' => $now]);
    }
}
