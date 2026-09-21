<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
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
            |--------------------------------------------------------------------------
            */
            GroupCabangSeeder::class,
            CabangSeeder::class,
            DepartmentSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Permission & Role Master
            |--------------------------------------------------------------------------
            | PermissionModule sebaiknya sebelum Permission.
            | RoleSeeder wajib sebelum SuperAdminAccessSeeder dan InitialSetupSeeder.
            |--------------------------------------------------------------------------
            */
            PermissionModuleSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Module / Transaction Supporting Data
            |--------------------------------------------------------------------------
            | ApprovalFlow biasanya butuh role, cabang, department, dan permission module.
            |--------------------------------------------------------------------------
            */
            ApprovalFlowSeeder::class,
            GoodsReturnReasonSeeder::class,
            DashboardModuleSeeder::class,
            ActivityLogSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Menu Seeder
            |--------------------------------------------------------------------------
            | MenuManagementSeeder dibuat setelah permission, role, dan module dasar siap.
            | Dijalankan sebelum SuperAdminAccessSeeder supaya akses Super Admin ikut lengkap.
            |--------------------------------------------------------------------------
            */
            MenuManagementSeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Modul Pengajuan Dana
            |--------------------------------------------------------------------------
            | Mendaftarkan permission module, permission, dan menu FPU. Dijalankan
            | setelah MenuManagementSeeder karena ikut menggeser urutan menu induk.
            |--------------------------------------------------------------------------
            */
            CashAdvanceModuleSeeder::class,
            CashAdvanceRealizationModuleSeeder::class,
            ClaimModuleSeeder::class,
            FundRequestTransactionCategoryModuleSeeder::class,
            FundRequestTransactionCategorySeeder::class,

            /*
            |--------------------------------------------------------------------------
            | Perjalanan Dinas
            |--------------------------------------------------------------------------
            | Membuat menu induknya sendiri, jadi tidak bergantung pada menu yang
            | sudah ada selain sebagai acuan urutan dan pemetaan role.
            |--------------------------------------------------------------------------
            */
            BusinessTripModuleSeeder::class,

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
            | Super Admin Access
            |--------------------------------------------------------------------------
            | Role SA diberi semua permission dan semua menu aktif.
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
}
