<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Modul Petty Cash
|--------------------------------------------------------------------------
| Tiga modul sekaligus, karena ketiganya memang tiga layar yang berbeda
| wewenangnya:
|
|   petty_cash                 formulir pembayaran claim kecil dari kas kecil
|   petty_cash_topup           pengisian kembali kas kecil
|   master_petty_cash_ceiling  plafon per cabang
|
| SEEDER INI TIDAK MEMBERIKAN PERMISSION KEPADA SIAPA PUN. Ia hanya
| mendaftarkan permission-nya supaya muncul di layar Role Permission. Siapa
| yang memegang kas kecil di tiap cabang adalah keputusan yang harus terlihat
| di layar itu, bukan keputusan yang terkubur di dalam seeder.
|
| Khususnya petty_cash.create: permission itulah yang menentukan siapa
| pemegang kas kecil sebuah cabang, dan karenanya siapa yang boleh menerima
| claim kecil di sana. Memberikannya ke semua role -- seperti yang terjadi
| pada claim.receive dan claim.pay, yang kini dipegang 40 role -- akan membuat
| aturan "claim kecil hanya diterima pemegang kas kecil" tidak berarti apa-apa.
|
| Aman dijalankan berulang: seluruhnya updateOrInsert.
|--------------------------------------------------------------------------
*/
class PettyCashModuleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $this->seedPermissionModules($now);
        $this->seedPermissions($now);
        $this->seedMenus($now);
    }

    /*
    |--------------------------------------------------------------------------
    | Permission module
    |--------------------------------------------------------------------------
    | route_prefix dipakai frontend untuk mencocokkan halaman dengan
    | permission. Nilainya harus sama persis dengan folder halaman Vue.
    |--------------------------------------------------------------------------
    */
    private function seedPermissionModules($now): void
    {
        $modules = [
            [
                'code' => 'petty_cash',
                'name' => 'Petty Cash',
                'description' => 'Pembayaran beberapa claim kecil sekaligus dari kas kecil cabang, pada menu Pengajuan Dana.',
                'route_prefix' => '/fund_request/petty_cash',
                'sort_order' => 63,

                /*
                | Alurnya dipilih dari area dan department, seperti Claim.
                | Nominal ikut dibandingkan oleh generatornya -- itu perilaku
                | generator, bukan matriks, jadi tidak ada penanda tersendiri
                | untuknya di sini.
                */
                'approval_document_type' => 'PETTY_CASH',
                'approval_document_label' => 'Petty Cash',
                'approval_uses_area_matrix' => true,
            ],
            [
                'code' => 'petty_cash_topup',
                'name' => 'Top Up Petty Cash',
                'description' => 'Pengisian kembali kas kecil cabang sampai plafonnya, pada menu Pengajuan Dana.',
                'route_prefix' => '/fund_request/petty_cash_topup',
                'sort_order' => 64,
                'approval_document_type' => 'PETTY_CASH_TOPUP',
                'approval_document_label' => 'Top Up Petty Cash',
                'approval_uses_area_matrix' => true,
            ],
            [
                'code' => 'master_petty_cash_ceiling',
                'name' => 'Master Plafon Petty Cash',
                'description' => 'Plafon kas kecil per cabang, ambang claim yang wajib lewat petty cash, dan ambang peringatan saldo.',
                'route_prefix' => '/master/petty-cash-ceiling',
                'sort_order' => 28,

                /* Master, bukan dokumen -- tidak punya approval flow. */
                'approval_document_type' => null,
                'approval_document_label' => null,
                'approval_uses_area_matrix' => false,
            ],
        ];

        foreach ($modules as $module) {
            DB::table('permission_modules')->updateOrInsert(
                ['code' => $module['code']],
                [
                    'name' => $module['name'],
                    'description' => $module['description'],
                    'route_prefix' => $module['route_prefix'],
                    'sort_order' => $module['sort_order'],
                    'is_active' => true,
                    'approval_document_type' => $module['approval_document_type'],
                    'approval_document_label' => $module['approval_document_label'],
                    'approval_uses_area_matrix' => $module['approval_uses_area_matrix'],
                    'updated_at' => $now,
                ],
            );

            DB::table('permission_modules')
                ->where('code', $module['code'])
                ->whereNull('created_at')
                ->update(['created_at' => $now]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    | Tidak ada permission "approve". Mengikuti keempat modul yang sudah ada:
    | yang boleh menyetujui adalah orang yang namanya tercatat pada baris
    | approval dokumen itu, bukan orang yang punya permission tertentu.
    |--------------------------------------------------------------------------
    */
    private function seedPermissions($now): void
    {
        $permissions = [
            /*
            |------------------------------------------------------------------
            | Petty Cash
            |------------------------------------------------------------------
            */
            ['petty_cash', 'view', 'View Petty Cash',
                'Melihat daftar dan detail Petty Cash sesuai scope akses.', true],

            /*
            | PERMISSION PENENTU PEMEGANG KAS KECIL.
            |
            | Bukan hanya izin membuat formulir: siapa pun yang memegangnya di
            | sebuah cabang adalah orang yang boleh menerima claim kecil di
            | cabang itu. Diberikan per orang, bukan per role, supaya HO dan
            | cabang yang belum punya ADH tetap bisa ditunjuk tanpa menambah
            | pemegang role Admin Head yang artinya lebih luas.
            */
            ['petty_cash', 'create', 'Create Petty Cash',
                'Membuat Petty Cash baru. Pemegang permission ini adalah pemegang kas kecil cabangnya, '
                . 'dan karena itu juga yang boleh menerima claim yang wajib lewat petty cash.', false],

            ['petty_cash', 'update', 'Update Petty Cash',
                'Mengubah Petty Cash yang masih berstatus draft.', true],
            ['petty_cash', 'delete', 'Delete Petty Cash',
                'Menghapus Petty Cash yang masih berstatus draft.', true],
            ['petty_cash', 'submit', 'Submit Petty Cash',
                'Mengajukan Petty Cash ke proses approval.', true],

            /*
            | Pembatalan hanya tersedia antara disetujui penuh dan pencairan.
            | Sesudah uangnya keluar, yang masuk akal bukan pembatalan
            | melainkan penyesuaian saldo yang tercatat alasannya.
            */
            ['petty_cash', 'cancel', 'Cancel Petty Cash',
                'Membatalkan Petty Cash yang sudah disetujui penuh tetapi belum dicairkan.', true],

            /*
            | Pencairan: inilah yang memotong plafon dan menandai claim-claim di
            | dalamnya sebagai PAID. Tanpa scope, karena yang mencairkan selalu
            | pemegang kas kecil cabang itu sendiri -- gerbang cabangnya
            | diperiksa terpisah, bukan lewat scope permission.
            */
            ['petty_cash', 'disburse', 'Disburse Petty Cash',
                'Mencairkan Petty Cash: uang tunai diserahkan, plafon terpotong, dan claim di dalamnya menjadi PAID.', false],

            ['petty_cash', 'export', 'Export Petty Cash',
                'Export data Petty Cash ke Excel, sesuai data yang tampil pada daftar.', false],

            /*
            |------------------------------------------------------------------
            | Top Up Petty Cash
            |------------------------------------------------------------------
            */
            ['petty_cash_topup', 'view', 'View Top Up Petty Cash',
                'Melihat daftar dan detail Top Up Petty Cash sesuai scope akses.', true],
            ['petty_cash_topup', 'create', 'Create Top Up Petty Cash',
                'Mengajukan pengisian kembali kas kecil. Nominalnya dihitung sistem dari plafon dikurangi saldo.', false],
            ['petty_cash_topup', 'update', 'Update Top Up Petty Cash',
                'Mengubah Top Up Petty Cash yang masih berstatus draft.', true],
            ['petty_cash_topup', 'delete', 'Delete Top Up Petty Cash',
                'Menghapus Top Up Petty Cash yang masih berstatus draft.', true],
            ['petty_cash_topup', 'submit', 'Submit Top Up Petty Cash',
                'Mengajukan Top Up Petty Cash ke proses approval.', true],
            ['petty_cash_topup', 'cancel', 'Cancel Top Up Petty Cash',
                'Membatalkan Top Up Petty Cash yang belum disetujui penuh.', true],
            ['petty_cash_topup', 'export', 'Export Top Up Petty Cash',
                'Export data Top Up Petty Cash ke Excel.', false],

            /*
            |------------------------------------------------------------------
            | Master plafon
            |------------------------------------------------------------------
            */
            ['master_petty_cash_ceiling', 'view', 'View Plafon Petty Cash',
                'Melihat plafon kas kecil per cabang beserta saldo berjalannya.', false],
            ['master_petty_cash_ceiling', 'create', 'Create Plafon Petty Cash',
                'Menetapkan plafon kas kecil sebuah cabang. Saldo awalnya langsung tercatat sebesar plafon.', false],
            ['master_petty_cash_ceiling', 'update', 'Update Plafon Petty Cash',
                'Mengubah plafon, ambang claim, atau ambang peringatan sebuah cabang. Tidak mengubah saldo berjalan.', false],
            ['master_petty_cash_ceiling', 'delete', 'Delete Plafon Petty Cash',
                'Menonaktifkan plafon kas kecil sebuah cabang.', false],

            /*
            | Satu-satunya cara mengubah saldo tanpa dokumen.
            |
            | Dipisah dari update karena bobotnya jauh berbeda: mengubah plafon
            | tidak memindahkan uang, menyesuaikan saldo iya. Alasannya wajib
            | diisi, dan barisnya masuk buku mutasi seperti pergerakan lain.
            */
            ['master_petty_cash_ceiling', 'adjust', 'Adjust Saldo Petty Cash',
                'Menyesuaikan saldo kas kecil secara manual, misalnya karena selisih hasil hitung fisik. Wajib beralasan dan tercatat di buku mutasi.', false],
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
    | Dua dokumen menempel pada "Pengajuan Dana", masternya pada "Master".
    | Keduanya menu induk yang sudah ada; bila salah satu tidak ditemukan,
    | bagian itu dilewati tanpa membatalkan sisanya -- menu bisa dirapikan
    | sendiri lewat Manajemen Menu.
    |--------------------------------------------------------------------------
    */
    private function seedMenus($now): void
    {
        $this->seedChildMenu($now, 'Pengajuan Dana', [
            [
                'name' => 'Petty Cash',
                'path' => '/fund_request/petty_cash',
                'route_name' => 'petty-cash',
                'icon' => 'tabler-cash',
                'order_no' => 4,
            ],
            [
                'name' => 'Top Up Petty Cash',
                'path' => '/fund_request/petty_cash_topup',
                'route_name' => 'petty-cash-topup',
                'icon' => 'tabler-cash-banknote',
                'order_no' => 5,
            ],
        ]);

        $this->seedChildMenu($now, 'Master', [
            [
                'name' => 'Plafon Petty Cash',
                'path' => '/master/petty-cash-ceiling',
                'route_name' => 'petty-cash-ceiling',
                'icon' => 'tabler-wallet',
                'order_no' => 20,
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     */
    private function seedChildMenu($now, string $parentName, array $children): void
    {
        $parentId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', $parentName)
            ->value('id');

        if (!$parentId) {
            $this->command?->warn(
                "Menu induk \"{$parentName}\" tidak ditemukan -- menu anaknya dilewati."
            );

            return;
        }

        foreach ($children as $child) {
            DB::table('menus')->updateOrInsert(
                [
                    'name' => $child['name'],
                    'parent_id' => $parentId,
                ],
                [
                    'path' => $child['path'],
                    'route_name' => $child['route_name'],
                    'icon' => $child['icon'],
                    'order_no' => $child['order_no'],
                    'is_active' => true,
                    'show_in_sidebar' => true,
                    'updated_at' => $now,
                ],
            );

            DB::table('menus')
                ->where('parent_id', $parentId)
                ->where('name', $child['name'])
                ->whereNull('created_at')
                ->update(['created_at' => $now]);
        }
    }
}
