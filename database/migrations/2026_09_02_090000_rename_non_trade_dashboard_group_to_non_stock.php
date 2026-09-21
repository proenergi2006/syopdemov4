<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Rename Non Trade -> Non Stock: grup modul dashboard yang tertinggal
|--------------------------------------------------------------------------
| Migrasi rename sebelumnya hanya menyentuh tabel menus dan permission_modules,
| sehingga kategori pada halaman Management Dashboard masih menampilkan label
| lama "Non Trade".
|
| Kode grup ikut diganti menjadi NON_STOCK supaya kode dan labelnya tidak
| saling bertentangan. Aman dilakukan karena dashboard_modules menunjuk
| grupnya lewat foreign key dashboard_module_group_id, bukan lewat kodenya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('dashboard_module_groups')
            ->where('code', 'NON_TRADE')
            ->update([
                'code' => 'NON_STOCK',
                'name' => 'Non Stock',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('dashboard_module_groups')
            ->where('code', 'NON_STOCK')
            ->update([
                'code' => 'NON_TRADE',
                'name' => 'Non Trade',
                'updated_at' => now(),
            ]);
    }
};
