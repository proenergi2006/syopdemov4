<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Mengaktifkan kartu dashboard Goods Receipt
|--------------------------------------------------------------------------
| Modulnya sudah terdaftar sejak awal tetapi ditandai belum tersedia, karena
| halamannya memang belum ada. Sekarang halaman dan endpoint-nya sudah jadi,
| jadi kartunya boleh dibuka dari Management Dashboard.
|
| Daftar fitur ikut disesuaikan supaya kartunya menjanjikan hal yang memang
| ada di halamannya: bukan sekadar "status receipt", melainkan juga kinerja
| vendor dan pengembalian barang.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('dashboard_modules')
            ->where('code', 'GOODS_RECEIPT')
            ->update([
                'is_available' => true,

                'features' => json_encode([
                    'Barang diterima',
                    'Outstanding PO',
                    'Kinerja vendor',
                    'Pengembalian barang',
                ]),

                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('dashboard_modules')
            ->where('code', 'GOODS_RECEIPT')
            ->update([
                'is_available' => false,

                'features' => json_encode([
                    'Barang diterima',
                    'Outstanding PO',
                    'Status receipt',
                ]),

                'updated_at' => now(),
            ]);
    }
};
