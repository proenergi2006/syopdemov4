<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Rename Non Trade -> Non Stock
    |--------------------------------------------------------------------------
    | Menu dan modul permission menyimpan path di database, sehingga penggantian
    | direktori halaman (pages/non_trade -> pages/non_stock) tidak cukup hanya
    | di sisi kode.
    |
    | permission_modules.route_prefix adalah yang paling kritis: store permission
    | mencocokkan URL yang sedang dibuka dengan prefix ini untuk menentukan modul
    | mana yang berlaku. Bila prefix tertinggal di '/non_trade', tidak ada modul
    | yang cocok dan seluruh halaman Non Stock akan tertolak walaupun permission
    | user sudah benar.
    |
    | Dibuat sebagai migration (bukan hanya seeder) supaya perubahan data ini
    | ikut terbawa saat deploy, bukan hanya pada instalasi baru.
    |--------------------------------------------------------------------------
    */
    public function up(): void
    {
        DB::table('menus')
            ->where('path', 'LIKE', '/non_trade%')
            ->update([
                'path' => DB::raw("REPLACE(path, '/non_trade', '/non_stock')"),
            ]);

        DB::table('menus')
            ->where('name', 'Non Trade')
            ->update([
                'name' => 'Non Stock',
            ]);

        DB::table('permission_modules')
            ->where('route_prefix', 'LIKE', '/non_trade%')
            ->update([
                'route_prefix' => DB::raw("REPLACE(route_prefix, '/non_trade', '/non_stock')"),
            ]);
    }

    public function down(): void
    {
        DB::table('menus')
            ->where('path', 'LIKE', '/non_stock%')
            ->update([
                'path' => DB::raw("REPLACE(path, '/non_stock', '/non_trade')"),
            ]);

        DB::table('menus')
            ->where('name', 'Non Stock')
            ->update([
                'name' => 'Non Trade',
            ]);

        DB::table('permission_modules')
            ->where('route_prefix', 'LIKE', '/non_stock%')
            ->update([
                'route_prefix' => DB::raw("REPLACE(route_prefix, '/non_stock', '/non_trade')"),
            ]);
    }
};
