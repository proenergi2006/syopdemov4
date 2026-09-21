<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Nominal yang benar-benar berpindah saat selisih diselesaikan
    |--------------------------------------------------------------------------
    | Penutup selisih kini mewajibkan pelakunya mengetikkan nominal yang ia
    | serahkan atau bayarkan, lalu sistem menolak bila tidak persis sama dengan
    | selisih dokumennya.
    |
    | Nilainya disimpan, bukan sekadar diperiksa lalu dibuang, karena ini fakta
    | transaksi tersendiri: berapa uang yang berpindah dan kapan. difference_amount
    | adalah angka hasil hitungan rincian, sedangkan kolom ini adalah angka yang
    | dikonfirmasi manusia saat uangnya benar-benar berpindah.
    |
    | Dokumen lama dibiarkan null -- fitur ini belum ada saat mereka ditutup, dan
    | mengisinya dengan tebakan justru membuat data audit tampak lebih meyakinkan
    | daripada kenyataannya.
    |--------------------------------------------------------------------------
    */
    public function up(): void
    {
        Schema::table('cash_advance_realizations', function (Blueprint $table) {
            $table->decimal('settlement_amount', 18, 2)
                ->nullable()
                ->after('settlement_notes');
        });
    }

    public function down(): void
    {
        Schema::table('cash_advance_realizations', function (Blueprint $table) {
            $table->dropColumn('settlement_amount');
        });
    }
};
