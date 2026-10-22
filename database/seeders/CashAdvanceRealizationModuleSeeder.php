<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Modul Realisasi FPU
|--------------------------------------------------------------------------
| Mendaftarkan permission module, permission, menu, dan jenis dokumen
| Approval Flow untuk Realisasi FPU.
|
| Aman dijalankan berulang -- seluruhnya memakai updateOrInsert.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealizationModuleSeeder extends Seeder
{
    private const MODULE_CODE = 'cash_advance_realization';

    private const ROUTE_PREFIX = '/fund_request/cash_advance_realization';

    private const MENU_NAME = 'Realisasi FPU';

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
                'name' => 'Realisasi FPU',
                'description' => 'Module pertanggungjawaban atas FPU yang sudah dibayarkan.',
                'route_prefix' => self::ROUTE_PREFIX,
                'sort_order' => 62,
                'is_active' => true,

                /*
                | Mendaftarkan Realisasi sebagai jenis dokumen Approval Flow.
                | Kolom inilah yang mengisi dropdown "Jenis Dokumen".
                |
                | Cabang, department, dan keterangan transaksi diwarisi dari
                | FPU induk, jadi flow-nya bisa diatur sedetail flow FPU.
                */
                'approval_document_type' => 'REALISASI',
                'approval_document_label' => 'Realisasi FPU',
                'approval_uses_area_matrix' => true,
                'approval_uses_transaction_category' => true,

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
            ['view', 'View Realisasi FPU', 'Melihat daftar dan detail Realisasi FPU sesuai scope akses.', true],
            ['create', 'Create Realisasi FPU', 'Membuat Realisasi dari FPU yang sudah dibayarkan.', false],
            ['update', 'Update Realisasi FPU', 'Mengubah Realisasi FPU yang masih berstatus draft.', true],
            ['delete', 'Delete Realisasi FPU', 'Menghapus Realisasi FPU yang masih berstatus draft.', true],
            ['submit', 'Submit Realisasi FPU', 'Mengajukan Realisasi FPU ke proses approval.', true],
            ['cancel', 'Cancel Realisasi FPU', 'Membatalkan Realisasi FPU yang sudah disetujui.', true],

            /*
            | Penerimaan mendahului penyelesaian: PIC menandai berkasnya sudah
            | diterima untuk diproses, baru setelah itu selisihnya diselesaikan.
            | Sama seperti penyelesaian, tanpa scope -- PIC-nya melayani seluruh
            | cabang.
            */
            ['receive', 'Receive Realisasi FPU', 'Menandai Realisasi FPU yang sudah disetujui sudah diterima, sebelum selisihnya diselesaikan.', false],

            /*
            | Jalan mundur dari meja penerimaan.
            |
            | Dipisah dari receive karena bobotnya lain: menerima berkas
            | menambah dokumen ke antrean, menariknya kembali mencabut tanggal
            | yang sudah dijanjikan ke pemohon lewat email.
            */
            ['unreceive', 'Undo Receive Realisasi FPU', 'Membatalkan penerimaan berkas Realisasi FPU sehingga dokumennya kembali ke status disetujui. Tanggal pembayaran yang sudah dijanjikan ikut dibatalkan.', false],

            /*
            | Pemeriksaan sebelum kekurangannya dibayarkan.
            |
            | Realisasi yang nilainya melebihi FPU-nya dikerjakan dua orang:
            | yang pertama memeriksa berkasnya, yang kedua membayarkan.
            | Izinnya dipisah supaya kedua meja itu bisa diberikan kepada
            | orang yang berbeda -- walau pada awalnya diberikan kepada
            | pemegang receive yang sudah ada.
            |
            | Tanpa scope, sama seperti receive: PIC-nya melayani seluruh
            | cabang.
            */
            ['verify', 'Verify Realisasi FPU', 'Memeriksa Realisasi FPU yang kekurangannya perlu dibayarkan, lalu meneruskannya ke pemegang izin pembayaran.', false],

            /*
            | Penyelesaian selisih dipecah dua, mengikuti arah uangnya:
            |
            | - return    : pemohon mengembalikan sisa dana ke Finance.
            | - reimburse : Finance membayar kekurangan kepada pemohon.
            |
            | Keduanya tanpa scope, sejalan dengan disburse FPU -- pencairan dan
            | penyelesaiannya terpusat di HO, bukan per cabang.
            */
            ['return', 'Kembalikan Selisih Realisasi FPU', 'Mencatat pengembalian sisa dana Realisasi FPU ke Finance, wajib melampirkan bukti.', false],
            ['reimburse', 'Bayar Kekurangan Realisasi FPU', 'Mencatat pembayaran kekurangan Realisasi FPU kepada pemohon.', false],

            /*
            | Export tidak memakai scope. Isi file sudah dibatasi visibility
            | permission view, jadi permission ini hanya menjawab boleh atau
            | tidaknya menarik data keluar sistem.
            */
            ['export', 'Export Realisasi FPU', 'Export data Realisasi FPU ke Excel, sesuai data yang tampil pada daftar.', false],
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

        /*
        | Izin .verify diberikan kepada pemegang .receive yang sudah ada.
        |
        | SEKALI SAJA, lewat insertOrIgnore. Peran yang sudah pernah menerima
        | -- atau sudah sengaja dicabut -- tidak ikut diubah lagi; penyalinan
        | yang berulang akan mengembalikan pencabutan itu diam-diam setiap
        | seeder dijalankan.
        |
        | Tujuannya hanya supaya alurnya langsung berjalan tanpa ada yang
        | perlu diatur dulu. Pemisahan kedua meja itu tetap bisa dilakukan
        | kapan saja lewat matriks izin.
        */
        $idVerify = DB::table('permissions')
            ->where('code', self::MODULE_CODE . '.verify')
            ->value('id');

        $idReceive = DB::table('permissions')
            ->where('code', self::MODULE_CODE . '.receive')
            ->value('id');

        if ($idVerify && $idReceive) {
            /*
            | Hanya pemegang yang AKTIF.
            |
            | Pencabutan izin di sini tersimpan sebagai is_active = false,
            | bukan sebagai baris yang hilang. Tanpa saringan ini, peran yang
            | izin menerimanya sudah dicabut pun ikut tersalin -- dan karena
            | kolom is_active memakai nilai bawaannya, salinannya justru
            | terpasang HIDUP. Peran yang tidak boleh menerima berkas akhirnya
            | boleh meneruskan pembayaran.
            */
            $peran = DB::table('role_permissions')
                ->where('permission_id', $idReceive)
                ->where('is_active', true)
                ->pluck('role_id');

            foreach ($peran as $roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $idVerify,

                    /* Disebut terang-terangan, bukan diserahkan ke nilai bawaan. */
                    'is_active' => true,

                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        /*
        | Permission lama 'settle' dinonaktifkan, bukan dihapus.
        |
        | Aksinya sudah digantikan return dan reimburse. Menghapus barisnya akan
        | ikut memutus role_permissions yang menunjuknya; dinonaktifkan saja
        | membuatnya hilang dari matriks tanpa merusak riwayat pemberian akses.
        */
        DB::table('permissions')
            ->where('code', self::MODULE_CODE . '.settle')
            ->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);
    }

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
                'name' => self::MENU_NAME,
                'parent_id' => $parentId,
            ],
            [
                'path' => self::ROUTE_PREFIX,
                'route_name' => 'cash-advance-realization',
                'icon' => 'tabler-receipt-2',
                'order_no' => 2,
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
    }
}
