<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Daftar modul: satu berkas untuk semuanya
|--------------------------------------------------------------------------
| Dua belas seeder modul sebelumnya menuliskan tiga langkah yang sama persis
| -- daftarkan permission module, daftarkan permission-nya, buat menunya --
| dengan hanya datanya yang berbeda. Di sini datanya menjadi daftar, dan
| langkahnya ditulis satu kali.
|
| MODUL BARU TIDAK PERLU BERKAS BARU. Cukup satu entri di dalam MODULES.
|
| YANG SENGAJA TIDAK DIKERJAKAN DI SINI: memberikan izin kepada role.
|
| Registry ini mendaftarkan, tidak memutuskan. Siapa yang boleh menerbitkan,
| menyetujui, atau membayarkan apa pun adalah keputusan yang harus terlihat di
| layar Role Permission -- bukan sesuatu yang menyala diam-diam karena sebuah
| seeder dijalankan. Konsekuensinya: sesudah seeder ini, modulnya terdaftar
| tetapi belum terlihat oleh siapa pun. Itu bukan kegagalan, itu memang
| langkah berikutnya, dan langkah itu milik manusia.
|
| AMAN DIJALANKAN BERULANG -- seluruhnya updateOrInsert.
|--------------------------------------------------------------------------
*/
class ModuleRegistrySeeder extends Seeder
{
    /*
    |--------------------------------------------------------------------------
    | Daftar modulnya
    |--------------------------------------------------------------------------
    | Bentuk tiap entri:
    |
    |   'kode_module' => [
    |       'name'         nama yang dibaca manusia
    |       'description'  keterangan di layar Role Permission
    |       'route_prefix' HARUS sama persis dengan folder halaman Vue-nya
    |       'sort_order'   urutan tampil di layar Role Permission
    |
    |       'approval'     null bila modul ini tidak punya approval flow
    |           'type'              kode dokumen di layar Approval Flow
    |           'label'             namanya di layar itu
    |           'uses_area_matrix'  lihat catatan pada BDV di bawah
    |
    |       'permissions'  action => [nama, keterangan, butuh scope]
    |           Kodenya disusun sendiri menjadi "module.action", persis seperti
    |           yang dilakukan layar Permission Modules.
    |
    |       'menu'         null bila modul ini tidak punya menu sendiri
    |           'parent'   menu induknya; dibuat bila belum ada
    |           'child'    halamannya
    |   ]
    |--------------------------------------------------------------------------
    */
    private const MODULES = [
        /*
        |----------------------------------------------------------------------
        | Bank Disbursement Voucher
        |----------------------------------------------------------------------
        | Dokumen pencairan lewat bank atas dokumen yang sudah disetujui dan
        | berkasnya sudah diterima Finance -- untuk sekarang FPU dan Claim.
        |
        | approval_document_type sudah diisi walau alurnya belum dibangun,
        | supaya BDV langsung bisa dipilih di layar Approval Flow begitu
        | alurnya menyusul.
        |
        | uses_area_matrix SENGAJA false: alur BDV berlaku global, tidak
        | dibatasi area maupun department. Konsekuensinya mengikat generator
        | yang menyusul nanti -- ia harus mencocokkan alur TANPA menyaring
        | area_type, seperti generator PO dan Vendor. Generator bermatriks
        | membandingkan area_type dengan kesamaan tegas, sehingga alur BDV yang
        | area_type-nya kosong tidak akan pernah cocok dengan apa pun bila
        | disaring begitu.
        |
        | Tidak ada permission "approve", mengikuti seluruh modul pengajuan
        | dana: yang boleh menyetujui adalah orang yang namanya tercatat pada
        | baris approval dokumen itu, bukan pemegang permission tertentu.
        |----------------------------------------------------------------------
        */
        'bdv' => [
            'name' => 'Bank Disbursement Voucher',
            'description' => 'Voucher pencairan lewat bank atas FPU dan Claim yang sudah disetujui dan berkasnya sudah diterima.',
            'route_prefix' => '/cash_bank/bdv',
            'sort_order' => 65,

            'approval' => [
                'type' => 'BDV',
                'label' => 'Bank Disbursement Voucher',
                'uses_area_matrix' => false,
            ],

            'permissions' => [
                'view' => [
                    'View BDV',
                    'Melihat daftar dan detail Bank Disbursement Voucher sesuai scope akses.',
                    true,
                ],

                'create' => [
                    'Create BDV',
                    'Menerbitkan BDV baru atas dokumen yang sudah disetujui dan berkasnya sudah diterima.',
                    false,
                ],

                'update' => [
                    'Update BDV',
                    'Mengubah BDV yang masih berstatus draft.',
                    true,
                ],

                'delete' => [
                    'Delete BDV',
                    'Menghapus BDV yang masih berstatus draft.',
                    true,
                ],

                'submit' => [
                    'Submit BDV',
                    'Mengajukan BDV ke proses approval.',
                    true,
                ],

                /*
                | Tidak butuh scope, dan itu disengaja.
                |
                | Siapa yang boleh menyetujui dokumen MANA ditentukan matriks
                | approval, bukan cakupan izin. Memberi scope di sini akan
                | menyaring dua kali dengan dua aturan yang berbeda, dan
                | approver yang sudah ditunjuk matriks bisa kehilangan
                | dokumennya tanpa sebab yang kelihatan di layar mana pun.
                */
                'approve' => [
                    'Approve BDV',
                    'Menyetujui atau menolak BDV pada tahap approval yang sedang menunggu orang ini.',
                    false,
                ],

                'cancel' => [
                    'Cancel BDV',
                    'Membatalkan BDV. Dokumen sumbernya kembali bisa diangkat pada BDV yang baru.',
                    true,
                ],

                'export' => [
                    'Export BDV',
                    'Export data BDV ke Excel, sesuai data yang tampil pada daftar.',
                    false,
                ],
            ],

            /*
            | BDV TIDAK menempel pada "Pengajuan Dana", melainkan pada menu
            | induk tersendiri bernama "Cash & Bank", tepat di bawahnya dan
            | masih di dalam section Finance yang sama.
            |
            | Pemisahan itu ada artinya: "Pengajuan Dana" berisi permintaan
            | uang, "Cash & Bank" berisi pengeluarannya. Dua hal yang berbeda,
            | dikerjakan orang yang berbeda pula.
            |
            | Induknya dibuat di sini bila belum ada, karena BDV adalah
            | penghuni pertamanya. Isinya akan bertambah.
            */
            'menu' => [
                'parent' => [
                    'name' => 'Cash & Bank',
                    'section' => 'Finance',
                    'icon' => 'tabler-building-bank',
                    'order' => 5,
                ],

                'child' => [
                    'name' => 'BDV',
                    'path' => '/cash_bank/bdv',
                    'route_name' => 'bdv',
                    'icon' => 'tabler-file-dollar',
                    'order' => 1,
                ],
            ],
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::MODULES as $code => $spec) {
            $this->command?->info("Modul \"{$code}\":");

            $this->daftarkanModule($code, $spec, $now);
            $this->daftarkanPermission($code, $spec['permissions'] ?? [], $now);
            $this->daftarkanMenu($spec['menu'] ?? null, $now);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Permission module
    |--------------------------------------------------------------------------
    | route_prefix dipakai frontend untuk mencocokkan halaman dengan
    | permission. Nilainya harus sama persis dengan folder halaman Vue.
    |--------------------------------------------------------------------------
    */
    private function daftarkanModule(string $code, array $spec, $now): void
    {
        $approval = $spec['approval'] ?? null;

        DB::table('permission_modules')->updateOrInsert(
            ['code' => $code],
            [
                'name' => $spec['name'],
                'description' => $spec['description'] ?? null,
                'route_prefix' => $spec['route_prefix'],
                'sort_order' => $spec['sort_order'] ?? 0,

                'approval_document_type' => $approval['type'] ?? null,
                'approval_document_label' => $approval['label'] ?? null,
                'approval_uses_area_matrix' => (bool) ($approval['uses_area_matrix'] ?? false),

                'is_active' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('permission_modules')
            ->where('code', $code)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);

        $this->command?->info('  permission module terdaftar.');
    }

    /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    */
    private function daftarkanPermission(string $module, array $permissions, $now): void
    {
        foreach ($permissions as $action => [$name, $description, $requiresScope]) {
            $code = $module . '.' . $action;

            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => $module,
                    'action' => $action,
                    'name' => $name,
                    'description' => $description,
                    'requires_scope' => (bool) $requiresScope,
                    'is_active' => true,
                    'updated_at' => $now,
                ],
            );

            DB::table('permissions')
                ->where('code', $code)
                ->whereNull('created_at')
                ->update(['created_at' => $now]);
        }

        $this->command?->info('  ' . count($permissions) . ' permission terdaftar.');
    }

    /*
    |--------------------------------------------------------------------------
    | Menu
    |--------------------------------------------------------------------------
    */
    private function daftarkanMenu(?array $menu, $now): void
    {
        if (!$menu) {
            return;
        }

        $parentId = $this->pastikanInduk($menu['parent'], $now);

        if (!$parentId) {
            return;
        }

        $anak = $menu['child'];

        $sudahAda = DB::table('menus')
            ->where('parent_id', $parentId)
            ->where('name', $anak['name'])
            ->first(['id', 'order_no']);

        $urutan = $this->urutanAman(
            (int) $anak['order'],
            $parentId,
            $sudahAda?->id,
            $sudahAda ? (int) $sudahAda->order_no : null,
            $anak['name'],
        );

        DB::table('menus')->updateOrInsert(
            [
                'name' => $anak['name'],
                'parent_id' => $parentId,
            ],
            [
                'path' => $anak['path'] ?? null,
                'route_name' => $anak['route_name'] ?? null,
                'icon' => $anak['icon'] ?? null,
                'order_no' => $urutan,
                'is_active' => true,
                'show_in_sidebar' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('menus')
            ->where('parent_id', $parentId)
            ->where('name', $anak['name'])
            ->whereNull('created_at')
            ->update(['created_at' => $now]);

        $this->command?->info("  menu \"{$anak['name']}\" terdaftar pada \"{$menu['parent']['name']}\".");
    }

    /**
     * Menu induknya, dibuat bila belum ada. Mengembalikan null bila gagal.
     */
    private function pastikanInduk(array $induk, $now): ?int
    {
        $sectionId = DB::table('menu_sections')
            ->where('name', $induk['section'])
            ->value('id');

        if (!$sectionId) {
            $this->command?->warn(
                "  section \"{$induk['section']}\" tidak ada -- menunya dilewati."
                . ' Jalankan MenuSectionSeeder lebih dulu.',
            );

            return null;
        }

        $sudahAda = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', $induk['name'])
            ->first(['id', 'order_no']);

        $urutan = $this->urutanAman(
            (int) $induk['order'],
            null,
            $sudahAda?->id,
            $sudahAda ? (int) $sudahAda->order_no : null,
            $induk['name'],
        );

        DB::table('menus')->updateOrInsert(
            [
                'name' => $induk['name'],
                'parent_id' => null,
            ],
            [
                /* Menu induk hanya wadah: tidak punya halaman sendiri. */
                'path' => null,
                'route_name' => null,
                'icon' => $induk['icon'] ?? null,
                'order_no' => $urutan,
                'menu_section_id' => $sectionId,
                'is_active' => true,
                'show_in_sidebar' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', $induk['name'])
            ->whereNull('created_at')
            ->update(['created_at' => $now]);

        return DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', $induk['name'])
            ->value('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Nomor urut yang juga diterima layar Menu Management
    |--------------------------------------------------------------------------
    | Seeder menulis langsung ke tabel dan melewati validasi controller. Itulah
    | sebabnya basis data bisa memuat tiga menu induk yang sama-sama bernomor
    | 5, padahal MenuController::validateOrderNoIsUnique() menolak nomor kembar
    | -- dan akibatnya ketiga menu itu tidak bisa lagi DISUNTING dari layar,
    | meski nomornya tidak ikut diubah.
    |
    | Jadi yang dijaga di sini bukan kerapian, melainkan agar seeder tidak
    | pernah menuliskan keadaan yang layarnya sendiri menolak.
    |
    | Keunikannya dihitung per induk, dan untuk menu induk berarti LINTAS
    | SECTION -- sama seperti controllernya, yang menyaring whereNull
    | ('parent_id') tanpa melihat section sama sekali.
    |--------------------------------------------------------------------------
    */
    private function urutanAman(
        int $diminta,
        ?int $parentId,
        ?int $menuIdSekarang,
        ?int $urutanSekarang,
        string $namaMenu,
    ): int {
        $bentrok = function (int $nomor) use ($parentId, $menuIdSekarang): bool {
            $query = DB::table('menus')->where('order_no', $nomor);

            $parentId === null
                ? $query->whereNull('parent_id')
                : $query->where('parent_id', $parentId);

            if ($menuIdSekarang) {
                $query->where('id', '!=', $menuIdSekarang);
            }

            return $query->exists();
        };

        if (!$bentrok($diminta)) {
            return $diminta;
        }

        /*
        | Menunya sudah ada: nomornya DIBIARKAN. Menggesernya diam-diam akan
        | mengubah susunan sidebar yang mungkin sudah ditata orang lewat layar.
        */
        if ($menuIdSekarang !== null && $urutanSekarang !== null) {
            $this->command?->warn(
                "  nomor urut {$diminta} sudah dipakai menu lain --"
                . " \"{$namaMenu}\" dibiarkan pada nomor {$urutanSekarang}.",
            );

            return $urutanSekarang;
        }

        /* Menu baru: diberi nomor bebas terdekat supaya tidak gagal. */
        $nomor = $diminta;

        while ($bentrok($nomor) && $nomor < $diminta + 1000) {
            $nomor++;
        }

        $this->command?->warn(
            "  nomor urut {$diminta} sudah dipakai --"
            . " \"{$namaMenu}\" memakai nomor {$nomor}.",
        );

        return $nomor;
    }
}
