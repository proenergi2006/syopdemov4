<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Modul Bank Disbursement Voucher (BDV)
|--------------------------------------------------------------------------
| Dokumen pencairan lewat bank atas dokumen yang sudah disetujui dan berkasnya
| sudah diterima Finance -- untuk sekarang FPU dan Claim.
|
| SEEDER INI TIDAK MEMBERIKAN PERMISSION KEPADA SIAPA PUN. Ia hanya
| mendaftarkannya supaya muncul di layar Role Permission. Siapa yang boleh
| menerbitkan voucher pencairan adalah keputusan yang harus terlihat di layar
| itu, bukan sesuatu yang menyala diam-diam karena sebuah seeder dijalankan.
|
| Aman dijalankan berulang: seluruhnya updateOrInsert.
|--------------------------------------------------------------------------
*/
class BdvModuleSeeder extends Seeder
{
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
    | route_prefix dipakai frontend untuk mencocokkan halaman dengan
    | permission. Nilainya harus sama persis dengan folder halaman Vue.
    |
    | approval_document_type sudah diisi walau alurnya belum dibangun, supaya
    | BDV langsung bisa dipilih di layar Approval Flow begitu alurnya menyusul.
    |
    | approval_uses_area_matrix SENGAJA false: alur BDV berlaku global, tidak
    | dibatasi area maupun department. Konsekuensinya mengikat generator yang
    | menyusul nanti -- ia harus mencocokkan alur TANPA menyaring area_type,
    | seperti generator PO dan Vendor. Generator bermatriks membandingkan
    | area_type dengan kesamaan tegas, sehingga alur BDV yang area_type-nya
    | kosong tidak akan pernah cocok dengan apa pun bila disaring begitu.
    |--------------------------------------------------------------------------
    */
    private function seedPermissionModule($now): void
    {
        $code = 'bdv';

        DB::table('permission_modules')->updateOrInsert(
            ['code' => $code],
            [
                'name' => 'Bank Disbursement Voucher',
                'description' => 'Voucher pencairan lewat bank atas FPU dan Claim yang sudah disetujui dan berkasnya sudah diterima.',
                'route_prefix' => '/cash_bank/bdv',
                'sort_order' => 65,

                'approval_document_type' => 'BDV',
                'approval_document_label' => 'Bank Disbursement Voucher',
                'approval_uses_area_matrix' => false,

                'is_active' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('permission_modules')
            ->where('code', $code)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);
    }

    /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    | Tidak ada permission "approve", mengikuti seluruh modul pengajuan dana
    | yang sudah ada: yang boleh menyetujui adalah orang yang namanya tercatat
    | pada baris approval dokumen itu, bukan orang yang punya permission
    | tertentu.
    |--------------------------------------------------------------------------
    */
    private function seedPermissions($now): void
    {
        $permissions = [
            ['bdv', 'view', 'View BDV',
                'Melihat daftar dan detail Bank Disbursement Voucher sesuai scope akses.', true],

            ['bdv', 'create', 'Create BDV',
                'Menerbitkan BDV baru atas dokumen yang sudah disetujui dan berkasnya sudah diterima.', false],

            ['bdv', 'update', 'Update BDV',
                'Mengubah BDV yang masih berstatus draft.', true],

            ['bdv', 'delete', 'Delete BDV',
                'Menghapus BDV yang masih berstatus draft.', true],

            ['bdv', 'submit', 'Submit BDV',
                'Mengajukan BDV ke proses approval.', true],

            ['bdv', 'cancel', 'Cancel BDV',
                'Membatalkan BDV. Dokumen sumbernya kembali bisa diangkat pada BDV yang baru.', true],

            ['bdv', 'export', 'Export BDV',
                'Export data BDV ke Excel, sesuai data yang tampil pada daftar.', false],
        ];

        foreach ($permissions as [$module, $action, $name, $description, $requiresScope]) {
            $code = $module . '.' . $action;

            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => $module,
                    'action' => $action,
                    'name' => $name,
                    'description' => $description,
                    'requires_scope' => $requiresScope,
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
    | BDV TIDAK menempel pada "Pengajuan Dana", melainkan pada menu induk
    | tersendiri bernama "Cash & Bank", tepat di bawahnya dan masih di dalam
    | section Finance yang sama.
    |
    | Pemisahan itu ada artinya: "Pengajuan Dana" berisi permintaan uang,
    | "Cash & Bank" berisi pengeluarannya. Dua hal yang berbeda, dikerjakan
    | orang yang berbeda pula.
    |
    | Induknya dibuat di sini bila belum ada, karena BDV adalah penghuni
    | pertamanya. Isinya akan bertambah.
    |--------------------------------------------------------------------------
    */
    private function seedMenu($now): void
    {
        $sectionId = DB::table('menu_sections')
            ->where('name', 'Finance')
            ->value('id');

        $namaInduk = 'Cash & Bank';

        DB::table('menus')->updateOrInsert(
            [
                'name' => $namaInduk,
                'parent_id' => null,
            ],
            [
                /* Menu induk hanya wadah: tidak punya halaman sendiri. */
                'path' => null,
                'route_name' => null,
                'icon' => 'tabler-building-bank',

                /*
                | Tepat di bawah "Pengajuan Dana" yang bernomor 4. Nomor 5
                | memang dipakai juga oleh menu induk di section lain, dan itu
                | tidak bertabrakan karena urutannya dibaca per section.
                */
                'order_no' => 5,

                'menu_section_id' => $sectionId,
                'is_active' => true,
                'show_in_sidebar' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', $namaInduk)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);

        $parentId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', $namaInduk)
            ->value('id');

        if (!$parentId) {
            $this->command?->warn(
                'Menu induk "Cash & Bank" gagal dibuat -- menu BDV dilewati.',
            );

            return;
        }

        $name = 'BDV';

        DB::table('menus')->updateOrInsert(
            [
                'name' => $name,
                'parent_id' => $parentId,
            ],
            [
                'path' => '/cash_bank/bdv',
                'route_name' => 'bdv',
                'icon' => 'tabler-file-dollar',
                'order_no' => 1,
                'is_active' => true,
                'show_in_sidebar' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('menus')
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);

        /*
        | Menu BDV sempat dibuat menempel pada "Pengajuan Dana" sebelum
        | penempatannya ditentukan. Dibersihkan di sini supaya tidak tertinggal
        | sebagai menu kembar yang menuju halaman yang sama.
        */
        DB::table('menus')
            ->where('name', 'Bank Disbursement Voucher')
            ->where('route_name', 'bdv')
            ->delete();
    }
}
