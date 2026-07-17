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
