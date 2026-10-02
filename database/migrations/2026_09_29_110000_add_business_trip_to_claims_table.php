<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Claim ikut bisa menunjuk perjalanan dinas
|--------------------------------------------------------------------------
| Sebelumnya hanya FPU yang punya tautan ini. Sebagian perjalanan memang tidak
| diminta uang mukanya -- pegawainya menalangi dulu, lalu menagihnya lewat
| Claim. Tanpa kolom ini, perjalanan semacam itu tidak punya jejak sama sekali
| pada dokumen penggantiannya.
|
| SATU PERDIN, SATU DOKUMEN -- FPU ATAU CLAIM, TIDAK KEDUANYA.
|
| Aturannya tidak dipasang sebagai unique constraint, dan itu disengaja.
| "Terpakai" di sini berarti dipegang dokumen yang MASIH HIDUP: FPU yang
| ditolak atau dibatalkan melepaskan perdinnya kembali. Unique constraint
| tidak bisa membedakan itu -- ia akan mengunci perdin selamanya pada dokumen
| pertama yang menyentuhnya, termasuk yang gagal.
|
| Penjagaannya ada di BusinessTripController::eligibleQuery(), satu tempat
| yang dipakai pemilih di layar maupun pemeriksaan kiriman.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('claims', 'business_trip_id')) {
            return;
        }

        Schema::table('claims', function (Blueprint $table): void {
            $table->foreignId('business_trip_id')
                ->nullable()
                ->after('transaction_category_id')

                /*
                | nullOnDelete, bukan cascade. Perdin yang terhapus tidak boleh
                | ikut menghapus tagihan uangnya -- yang hilang cukup
                | tautannya, sedangkan angka dan buktinya tetap tinggal.
                */
                ->constrained('business_trips')
                ->nullOnDelete();
        });

        DB::statement(
            "COMMENT ON COLUMN claims.business_trip_id IS "
            . "'Perjalanan dinas yang ditagihkan lewat Claim ini. Null untuk keterangan transaksi yang tidak menuntut perdin. Satu perdin hanya boleh dipegang satu dokumen yang masih hidup -- FPU atau Claim, tidak keduanya.'",
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('claims', 'business_trip_id')) {
            return;
        }

        Schema::table('claims', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_trip_id');
        });
    }
};
