<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Keterangan transaksi mana yang mewajibkan Perdin
|--------------------------------------------------------------------------
| FPU dengan keterangan transaksi perjalanan dinas wajib menunjuk dokumen
| Perdin yang sudah disetujui. Pertanyaannya: keterangan transaksi yang mana?
|
| Menanamkan id atau namanya di dalam kode adalah jawaban yang bertahan sampai
| seseorang menambah keterangan transaksi kedua untuk perjalanan -- "Perdin
| Luar Negeri", misalnya -- dan aturannya diam-diam tidak berlaku di sana.
| Kolom ini membuat jawabannya bisa diubah dari master, tanpa menyentuh kode.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_request_transaction_categories', function (Blueprint $table): void {
            $table->boolean('requires_business_trip')->default(false);
        });

        DB::statement(
            "COMMENT ON COLUMN fund_request_transaction_categories.requires_business_trip IS "
            . "'FPU dengan keterangan transaksi ini wajib menunjuk dokumen Perdin yang sudah disetujui.'",
        );

        /*
        | Keterangan transaksi perdin yang sudah ada dinyalakan sekali di sini,
        | supaya aturannya langsung berlaku tanpa menunggu seseorang ingat
        | membuka masternya. Dicocokkan dari namanya -- satu-satunya penanda
        | yang ada sebelum kolom ini lahir.
        */
        $terpengaruh = DB::table('fund_request_transaction_categories')
            ->where('name', 'ILIKE', 'perdin%')
            ->update(['requires_business_trip' => true]);

        if ($terpengaruh > 0) {
            echo "  {$terpengaruh} keterangan transaksi ditandai mewajibkan Perdin.\n";
        }
    }

    public function down(): void
    {
        Schema::table('fund_request_transaction_categories', function (Blueprint $table): void {
            $table->dropColumn('requires_business_trip');
        });
    }
};
