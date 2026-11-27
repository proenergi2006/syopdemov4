<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/*
|--------------------------------------------------------------------------
| Daftar lengkap seeder yang membentuk sistem ini
|--------------------------------------------------------------------------
| SATU TEMPAT YANG TAHU DAFTARNYA.
|
| Sebelumnya 25 dari 50 seeder tidak tercantum di sini. Berkasnya ada di git,
| tetapi tidak pernah dipanggil -- sehingga `db:seed` pada basis data baru
| menghasilkan sistem yang berbeda dari yang sedang berjalan, dan satu-satunya
| cara memasang modul yang terlewat adalah mengingat nama kelasnya satu per
| satu. Itu bukan perkara jumlah berkas, melainkan tidak adanya daftar.
|
| URUTANNYA BUKAN SELERA. Yang dicatat di tiap blok adalah apa yang harus
| sudah ada sebelumnya, bukan sekadar pengelompokan rapi.
|
| Seluruh seeder di sini aman dijalankan berulang -- semuanya updateOrInsert
| atau "buat bila belum ada".
|--------------------------------------------------------------------------
*/
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            /*
            |--------------------------------------------------------------------------
            | Master Data Dasar
            |--------------------------------------------------------------------------
            */
            MasterBankSeeder::class,
            MasterDokumenPendukungSeeder::class,
            UnitsSeeder::class,
            MasterKeteranganTransaksiSeeder::class,
            MasterMaterialGroupSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Master Cabang & Department
            |--------------------------------------------------------------------------
            | GroupCabang wajib sebelum Cabang.
            |
            | SpecialDocumentType mencari department lewat KODE, jadi ia menyusul
            | DepartmentSeeder.
            |--------------------------------------------------------------------------
            */
            GroupCabangSeeder::class,
            CabangSeeder::class,
            DepartmentSeeder::class,
            SpecialDocumentTypeSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Permission & Role Master
            |--------------------------------------------------------------------------
            | PermissionModule sebaiknya sebelum Permission.
            |
            | AuthPermissionSeeder mendaftarkan module auth_* -- termasuk auth_menu
            | dan auth_permission_module, dua izin yang menentukan apakah menu dan
            | permission module berikutnya masih bisa diurus dari layar Master.
            | Tanpa itu, satu-satunya jalan menambah modul adalah menulis seeder
            | baru, yang justru hendak dihindari.
            |
            | RoleSeeder wajib sebelum SuperAdminAccessSeeder dan InitialSetupSeeder.
            |
            | RoleMaster berisi 51 role hasil ekspor, berkunci KODE. RoleLevel
            | mengisi tingkat jabatannya dan dicocokkan lewat NAMA. Keduanya
            | menyusul RoleSeeder, dan harus mendahului apa pun yang membaca
            | roles.level -- aturan "bawahan tidak menyetujui atasan" membaca kolom
            | itu, dan bila kosong aturannya gugur tanpa pesan apa pun.
            |--------------------------------------------------------------------------
            */
            PermissionModuleSeeder::class,
            PermissionSeeder::class,
            AuthPermissionSeeder::class,
            GoodsReturnPermissionSeeder::class,
            RoleSeeder::class,
            RoleMasterSeeder::class,
            RoleLevelSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Module / Transaction Supporting Data
            |--------------------------------------------------------------------------
            | ApprovalFlow biasanya butuh role, cabang, department, dan permission
            | module.
            |--------------------------------------------------------------------------
            */
            ApprovalFlowSeeder::class,
            GoodsReturnReasonSeeder::class,
            DashboardModuleSeeder::class,
            ActivityLogSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Menu
            |--------------------------------------------------------------------------
            | MenuManagementSeeder dibuat setelah permission, role, dan module dasar
            | siap.
            |
            | MenuSectionSeeder MENYUSUL, bukan mendahului: ia membuat kelima section
            | sidebar DAN menempatkan menu induk yang sudah ada ke dalamnya. Menu
            | induk yang belum ada hanya dilewati dengan peringatan, jadi
            | menjalankannya lebih awal akan membuat separuh tugasnya tidak
            | terkerjakan.
            |
            | Seeder modul di bawah ini mencari section-nya lewat NAMA, jadi section
            | harus sudah terbentuk di sini -- itulah sebabnya ia tidak ditaruh di
            | paling akhir.
            |--------------------------------------------------------------------------
            */
            MenuManagementSeeder::class,
            MenuSectionSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Modul Pengajuan Dana
            |--------------------------------------------------------------------------
            | Mendaftarkan permission module, permission, dan menunya. Dijalankan
            | setelah MenuManagementSeeder karena ikut menggeser urutan menu induk.
            |--------------------------------------------------------------------------
            */
            CashAdvanceModuleSeeder::class,
            CashAdvanceRealizationModuleSeeder::class,
            ClaimModuleSeeder::class,
            PettyCashModuleSeeder::class,
            FundRequestTransactionCategoryModuleSeeder::class,
            FundRequestTransactionCategorySeeder::class,
            FundRequestLimitModuleSeeder::class,
            PaymentScheduleModuleSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Perjalanan Dinas
            |--------------------------------------------------------------------------
            | Membuat menu induknya sendiri, jadi tidak bergantung pada menu yang
            | sudah ada selain sebagai acuan urutan dan pemetaan role.
            |--------------------------------------------------------------------------
            */
            BusinessTripModuleSeeder::class,
            BusinessTripExpenseCategoryModuleSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Modul yang didaftarkan lewat registry
            |--------------------------------------------------------------------------
            | Satu berkas untuk banyak modul. Modul baru cukup menambah satu entri
            | di dalamnya -- tidak ada berkas seeder baru lagi.
            |
            | Penghuninya sekarang: BDV (Cash & Bank).
            |--------------------------------------------------------------------------
            */
            ModuleRegistrySeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Pemantauan Antrean
            |--------------------------------------------------------------------------
            | Menempel pada menu Monitoring, jadi harus setelah MenuManagementSeeder
            | yang membuat menu induknya.
            |--------------------------------------------------------------------------
            */
            QueueMonitorModuleSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Masuk Sebagai User
            |--------------------------------------------------------------------------
            | Menempel pada menu Auth. Seeder ini sengaja tidak memberikan
            | permission-nya kepada siapa pun, termasuk Super Administrator --
            | auth_impersonation.create adalah izin terkuat di aplikasi ini.
            |--------------------------------------------------------------------------
            */
            ImpersonationModuleSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Kartu Dashboard per modul
            |--------------------------------------------------------------------------
            | Menempel pada grup launcher yang dibuat DashboardModuleSeeder. FPU
            | membuat grup "Pengajuan Dana"; tiga sisanya menumpang grup itu dan
            | mencarinya lewat KODE, bukan id, jadi urutan ketiganya tidak mengikat.
            |--------------------------------------------------------------------------
            */
            CashAdvanceDashboardSeeder::class,
            CashAdvanceRealizationDashboardSeeder::class,
            ClaimDashboardSeeder::class,
            BusinessTripDashboardSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Super Admin Access
            |--------------------------------------------------------------------------
            | Role SA diberi semua permission dan semua menu aktif.
            |
            | PERHATIAN PADA BASIS DATA YANG SUDAH HIDUP: seeder ini menyetel SELURUH
            | permission SA ke scope ALL. Izin yang sengaja dimatikan untuk SA akan
            | ikut dinyalakan kembali. Pada basis data baru itu memang yang
            | diinginkan; pada yang sudah berjalan, jalankan seeder yang dibutuhkan
            | saja, jangan `db:seed` seluruhnya.
            |--------------------------------------------------------------------------
            */
            SuperAdminAccessSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Initial Admin User
            |--------------------------------------------------------------------------
            | Harus paling akhir.
            | Tugasnya hanya create/update akun admin.syop dan assign role SA.
            |--------------------------------------------------------------------------
            */
            InitialSetupSeeder::class,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | YANG SENGAJA TIDAK DIPANGGIL DI SINI
    |--------------------------------------------------------------------------
    | Bukan kelalaian. Sembilan seeder berikut ada di folder yang sama dan
    | tetap dijalankan dengan tangan saat dibutuhkan:
    |
    | SEKALI PAKAI -- membetulkan data yang terlanjur, bukan membentuk sistem:
    |
    |   ApprovalRowsOfInactiveUsersCleanupSeeder
    |       Membuang baris approval PENDING milik akun nonaktif. Generatornya
    |       sudah diperbaiki, jadi basis data baru tidak pernah punya baris itu.
    |
    |   RealisasiApprovalFlowModuleFixSeeder
    |       Membetulkan module_name 58 flow Realisasi yang tersalin dari FPU.
    |       Basis data baru tidak mengalami penyalinan itu.
    |
    | MEMBERI IZIN KE ROLE -- bukan mendaftarkan, melainkan memutuskan:
    |
    |   ReceiveStagePermissionGrantSeeder
    |   ReceiveStageDutySplitSeeder
    |       Keduanya membagikan permission penerimaan berkas ke role tertentu.
    |       Siapa memegang apa adalah keputusan yang harus terlihat di layar Role
    |       Permission, bukan sesuatu yang menyala karena seeder dijalankan.
    |
    | CUPLIKAN MATRIKS APPROVAL -- hasil ekspor pada satu tanggal:
    |
    |   ApprovalFlowMatrixSeeder          170 flow, ekspor 23 Okt 2026
    |   FundRequestApprovalFlowSeeder     143 flow, ekspor 23 Okt 2026
    |   BodApprovalFlowSeeder             alur department BOD
    |   PettyCashApprovalFlowSeeder       4 alur awal Petty Cash
    |       Isi matriksnya sudah berubah sejak tanggal ekspor itu. Menjalankannya
    |       otomatis akan memasang susunan approver yang kedaluwarsa -- kesalahan
    |       yang baru ketahuan saat dokumen pertama berjalan ke orang yang salah.
    |       Ekspor ulang lewat `php artisan approval-flow:export-seeder` lebih
    |       dulu.
    |
    | PERKAKAS DEPLOY -- memanggil seeder lain, jadi tidak boleh ikut otomatis:
    |
    |   FundRequestApprovalDeploySeeder
    |       Satu perintah untuk memasang matriks approval 4 modul ke production,
    |       dalam urutan yang benar. Ia memanggil RoleMasterSeeder dan
    |       FundRequestApprovalFlowSeeder; mencantumkannya di sini akan membuat
    |       keduanya jalan dua kali.
    |--------------------------------------------------------------------------
    */
}
