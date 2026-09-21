<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Modul Perjalanan Dinas (Perdin)
|--------------------------------------------------------------------------
| Mendaftarkan permission module, permission, menu induk baru, dan pemetaan
| menunya ke role.
|
| Menu induknya BARU -- "Perjalanan Dinas", sejajar dengan Pengajuan Dana.
| Perdin bukan pengajuan dana: ia yang memicu FPU, bukan bagian darinya. Dan
| kalau nanti tumbuh (laporan perdin, master tujuan), tempatnya sudah ada.
|
| Perdin juga didaftarkan sebagai jenis dokumen Approval Flow, sehingga
| matriks penyetujunya bisa diatur dari master seperti FPU dan Claim.
|
| Aman dijalankan berulang -- seluruhnya memakai updateOrInsert.
|--------------------------------------------------------------------------
*/
class BusinessTripModuleSeeder extends Seeder
{
    private const MODULE_CODE = 'business_trip';

    private const ROUTE_PREFIX = '/business_trip/perdin';

    private const PARENT_MENU_NAME = 'Perjalanan Dinas';

    private const MENU_NAME = 'Perdin';

    /** Menu yang ditiru pemetaan role-nya: FPU, penghuni gedung yang sama. */
    private const ACUAN_MENU_PATH = '/fund_request/cash_advance';

    /** Permission yang ditiru pemberiannya. */
    private const ACUAN_PERMISSION = 'cash_advance.view';

    public function run(): void
    {
        $now = now();

        $this->seedPermissionModule($now);
        $this->seedPermissions($now);
        $this->grantInitial($now);
        $this->seedMenu($now);
    }

    private function seedPermissionModule($now): void
    {
        DB::table('permission_modules')->updateOrInsert(
            ['code' => self::MODULE_CODE],
            [
                'name' => 'Perjalanan Dinas',
                'description' => 'Module formulir perjalanan dinas beserta rundown perjalanannya.',

                /*
                | Dipakai frontend untuk mencocokkan halaman dengan permission,
                | jadi harus sama persis dengan folder halaman Vue.
                */
                'route_prefix' => self::ROUTE_PREFIX,

                'sort_order' => 70,
                'is_active' => true,

                /*
                | Mendaftarkan Perdin sebagai jenis dokumen Approval Flow.
                | Kolom inilah yang mengisi dropdown "Jenis Dokumen".
                |
                | Memakai matriks area, TIDAK memakai keterangan transaksi:
                | yang disetujui perjalanannya, dan perjalanan tidak punya
                | keterangan transaksi -- itu milik FPU yang membiayainya.
                */
                'approval_document_type' => 'PERDIN',
                'approval_document_label' => 'Perjalanan Dinas (Perdin)',
                'approval_uses_area_matrix' => true,
                'approval_uses_transaction_category' => false,

                'updated_at' => $now,
            ],
        );

        DB::table('permission_modules')
            ->where('code', self::MODULE_CODE)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);
    }

    /**
     * TIDAK ADA permission 'approve', dan itu disengaja.
     *
     * Wewenang menyetujui datang dari baris approval flow: yang boleh
     * menyetujui hanyalah orang yang tercantum pada langkah yang sedang
     * aktif. Sebuah permission approve akan memberi hak menyetujui dokumen
     * mana pun kepada siapa pun yang memilikinya -- justru kebalikan dari
     * gunanya approval flow.
     */
    private function seedPermissions($now): void
    {
        /*
        | requires_scope hanya pada view.
        |
        | Membuat, mengubah, dan menghapus selalu bekerja pada dokumen yang
        | sudah lolos penyaringan view, jadi cakupannya sudah ditentukan di
        | sana. Memberi mereka cakupan sendiri berarti dua aturan untuk satu
        | pertanyaan, dan dua aturan yang bisa berbeda jawabannya.
        */
        $permissions = [
            ['view', 'View Perdin', 'Melihat daftar dan rincian Perjalanan Dinas sesuai scope akses.', true],
            ['create', 'Create Perdin', 'Membuat formulir Perjalanan Dinas beserta rundown-nya.', false],
            ['update', 'Update Perdin', 'Mengubah Perjalanan Dinas yang sudah dibuat.', false],
            ['delete', 'Delete Perdin', 'Menghapus Perjalanan Dinas yang masih draft.', false],
            ['submit', 'Submit Perdin', 'Mengajukan Perjalanan Dinas untuk disetujui.', false],
            ['cancel', 'Cancel Perdin', 'Membatalkan Perjalanan Dinas yang sudah diajukan.', false],
            ['print', 'Print Perdin', 'Mencetak formulir Perjalanan Dinas yang sudah disetujui.', false],
            ['export', 'Export Perdin', 'Menarik data Perjalanan Dinas ke berkas Excel.', false],
        ];

        foreach ($permissions as [$action, $name, $description, $requiresScope]) {
            $code = self::MODULE_CODE . '.' . $action;

            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => self::MODULE_CODE,
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

    /**
     * Pemberian awal, meniru permission FPU.
     *
     * Permission yang baru dibuat tidak dipegang siapa pun, dan halaman yang
     * tidak bisa dibuka siapa pun sama saja dengan tidak ada. Acuannya FPU
     * karena orang yang sama yang mengajukan biaya perjalanan dan yang
     * melakukan perjalanannya.
     *
     * Berjalan sekali: begitu sebuah permission sudah punya pemegang, seeder
     * ini tidak menyentuhnya lagi -- sehingga pencabutan akses lewat pengaturan
     * role tidak dikembalikan diam-diam.
     */
    private function grantInitial($now): void
    {
        $acuan = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.code', self::ACUAN_PERMISSION)
            ->where('role_permissions.is_active', true)
            ->get(['role_permissions.role_id', 'role_permissions.scope'])
            ->unique('role_id');

        if ($acuan->isEmpty()) {
            $this->command?->warn('  Tidak ada role acuan; permission perdin belum diberikan ke siapa pun.');

            return;
        }

        foreach (['view', 'create', 'update', 'delete', 'submit', 'cancel', 'print'] as $action) {
            $permissionId = DB::table('permissions')
                ->where('code', self::MODULE_CODE . '.' . $action)
                ->value('id');

            if (!$permissionId) {
                continue;
            }

            if (DB::table('role_permissions')->where('permission_id', $permissionId)->exists()) {
                continue;
            }

            foreach ($acuan as $grant) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $grant->role_id, 'permission_id' => $permissionId],
                    [
                        /*
                        | Cakupan hanya berarti pada view; sisanya disimpan
                        | NONE supaya tidak terbaca seolah punya aturan sendiri.
                        */
                        'scope' => $action === 'view' ? $grant->scope : 'NONE',
                        'is_active' => true,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            $this->command?->info("  {$action}: diberikan ke {$acuan->count()} role.");
        }

        $this->grantExport($now);
    }

    private function seedMenu($now): void
    {
        $parentId = $this->seedParentMenu($now);

        DB::table('menus')->updateOrInsert(
            [
                'name' => self::MENU_NAME,
                'parent_id' => $parentId,
            ],
            [
                'path' => self::ROUTE_PREFIX,
                'route_name' => 'business-trip-perdin',
                'icon' => 'tabler-route',
                'order_no' => 1,
                'is_active' => true,
                'show_in_sidebar' => true,
                'updated_at' => $now,
            ],
        );

        DB::table('menus')
            ->where('parent_id', $parentId)
            ->where('name', self::MENU_NAME)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);

        $this->grantMenu($parentId);
    }

    /**
     * Menu induk baru, diselipkan tepat setelah Pengajuan Dana.
     *
     * order_no berupa bilangan bulat, jadi tidak ada celah di antara 4 dan 5 --
     * menu di bawahnya harus digeser. Penggeserannya dikerjakan sekali, hanya
     * kalau induknya memang belum ada, supaya urutan yang sudah diatur ulang
     * oleh admin tidak dipaksa kembali setiap seeder dijalankan.
     */
    private function seedParentMenu($now): int
    {
        $adaId = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', self::PARENT_MENU_NAME)
            ->value('id');

        if ($adaId) {
            return (int) $adaId;
        }

        $sesudah = DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', 'Pengajuan Dana')
            ->value('order_no');

        $urutan = $sesudah ? ((int) $sesudah + 1) : 99;

        if ($sesudah) {
            DB::table('menus')
                ->whereNull('parent_id')
                ->where('order_no', '>=', $urutan)
                ->increment('order_no');
        }

        DB::table('menus')->insert([
            'parent_id' => null,
            'name' => self::PARENT_MENU_NAME,
            'path' => null,
            'route_name' => null,
            'icon' => 'tabler-plane',
            'order_no' => $urutan,
            'is_active' => true,
            'show_in_sidebar' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->command?->info("  menu induk '" . self::PARENT_MENU_NAME . "' dibuat pada urutan {$urutan}.");

        return (int) DB::table('menus')
            ->whereNull('parent_id')
            ->where('name', self::PARENT_MENU_NAME)
            ->value('id');
    }

    /**
     * Memetakan menunya ke role.
     *
     * Sidebar dibangun dari role_menus, bukan dari permission. Menu yang tidak
     * dipetakan ke satu role pun tidak terlihat oleh siapa pun -- termasuk
     * Super Administrator. Jadi mendaftarkan menu tanpa langkah ini sama saja
     * dengan tidak membuatnya.
     *
     * Induk DAN anaknya sama-sama dipetakan: induk yang tidak dipetakan
     * menyembunyikan seluruh cabangnya.
     *
     * Acuannya SATU menu -- FPU. Bukan gabungan beberapa menu: meniru gabungan
     * akan membagikan halaman ini jauh lebih luas daripada yang pantas.
     */
    private function grantMenu(int $parentId): void
    {
        $menuId = DB::table('menus')
            ->where('parent_id', $parentId)
            ->where('name', self::MENU_NAME)
            ->value('id');

        if (!$menuId) {
            return;
        }

        if (DB::table('role_menus')->where('menu_id', $menuId)->exists()) {
            return;
        }

        $acuanId = DB::table('menus')
            ->where('path', self::ACUAN_MENU_PATH)
            ->value('id');

        $roleIds = $acuanId
            ? DB::table('role_menus')->where('menu_id', $acuanId)->distinct()->pluck('role_id')
            : collect();

        if ($roleIds->isEmpty()) {
            $this->command?->warn('  Tidak ada role acuan; menu perdin belum dipetakan.');

            return;
        }

        foreach ($roleIds as $roleId) {
            foreach ([$parentId, (int) $menuId] as $id) {
                DB::table('role_menus')->updateOrInsert(
                    ['role_id' => $roleId, 'menu_id' => $id],
                    [],
                );
            }
        }

        $this->command?->info("  menu: dipetakan ke {$roleIds->count()} role.");
    }
    /**
     * Pemberian awal permission export, meniru pemegang export FPU.
     *
     * Sengaja TIDAK memakai acuan yang sama dengan permission lain. Acuan itu
     * adalah pemegang cash_advance.view, sedangkan menarik data keluar
     * pertanyaannya lain dari sekadar membukanya di layar -- dan modul FPU
     * sudah menjawabnya lebih dulu lewat cash_advance.export.
     *
     * Bedanya nyata: perdin bisa dilihat lebih banyak role daripada yang boleh
     * meng-export FPU. Menyamakannya dengan view berarti diam-diam memperluas
     * kewenangan yang sudah ditetapkan di sebelah.
     *
     * Berjalan sekali, seperti grantInitial(): begitu permission ini sudah
     * punya pemegang, seeder tidak menyentuhnya lagi.
     */
    private function grantExport($now): void
    {
        $permissionId = DB::table('permissions')
            ->where('code', self::MODULE_CODE . '.export')
            ->value('id');

        if (!$permissionId) {
            return;
        }

        if (DB::table('role_permissions')->where('permission_id', $permissionId)->exists()) {
            return;
        }

        $pemegang = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.code', 'cash_advance.export')
            ->where('role_permissions.is_active', true)
            ->pluck('role_permissions.role_id')
            ->unique();

        if ($pemegang->isEmpty()) {
            $this->command?->warn('  export: tidak ada pemegang export FPU; belum diberikan ke siapa pun.');

            return;
        }

        foreach ($pemegang as $roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                [
                    /* Export tidak memakai cakupan; isinya ditentukan view. */
                    'scope' => 'NONE',
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        $this->command?->info("  export: diberikan ke {$pemegang->count()} role pemegang export FPU.");
    }

}
