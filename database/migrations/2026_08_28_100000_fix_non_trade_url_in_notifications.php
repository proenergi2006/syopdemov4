<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Rename Non Trade -> Non Stock: notifikasi yang tertinggal
    |--------------------------------------------------------------------------
    | Migration 2026_08_13_090000 sudah membereskan menus dan permission_modules,
    | tetapi tabel notifications ikut menyimpan path tujuan pada kolom url dan
    | waktu itu terlewat.
    |
    | Akibatnya tombol "Lihat" pada notifikasi lama mengarah ke /non_trade/...
    | yang sudah tidak ada route-nya, sehingga user mendapat halaman 404.
    | Notifikasi baru tidak terpengaruh -- service-nya sudah menulis /non_stock.
    |
    | Dibuat sebagai migration, bukan seeder, supaya perbaikan data ini ikut
    | terbawa saat deploy ke server yang sudah punya notifikasi lama.
    |--------------------------------------------------------------------------
    */
    public function up(): void
    {
        DB::table('notifications')
            ->where('url', 'LIKE', '/non_trade%')
            ->update([
                'url' => DB::raw("REPLACE(url, '/non_trade', '/non_stock')"),
            ]);
    }

    public function down(): void
    {
        DB::table('notifications')
            ->where('url', 'LIKE', '/non_stock%')
            ->update([
                'url' => DB::raw("REPLACE(url, '/non_stock', '/non_trade')"),
            ]);
    }
};
