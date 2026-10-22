<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Keterangan transaksi sebuah Claim: tidak lagi satu
|--------------------------------------------------------------------------
| Mengikuti FPU. Headernya menyatakan HIMPUNAN keterangan transaksi yang boleh
| dipakai baris rinciannya; barisnya memilih dari himpunan itu, bukan dari
| seluruh master.
|
| CLAIM LEBIH SEDERHANA DARI FPU, DAN ITU BUKAN KELALAIAN.
|
| Claim tidak punya sifat pengajuan, jadi tidak ada aturan "Non Rutin hanya
| boleh satu" -- himpunannya boleh berisi berapa pun. Alur approval-nya juga
| tidak bergantung keterangan transaksi (permission_modules.
| approval_uses_transaction_category bernilai false untuk claim), sehingga
| pertanyaan "kategori mana yang menentukan alurnya" tidak pernah timbul di
| sini.
|
| Kolom lama claims.transaction_category_id tetap tinggal: ia masih memilih
| jadwal pembayaran, dan diisi anggota pertama himpunannya menurut urutan
| masternya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('claim_transaction_categories')) {
            return;
        }

        Schema::create('claim_transaction_categories', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('claim_id')
                ->constrained('claims')

                /*
                | Ikut terhapus bersama Claim-nya. Himpunan ini tidak punya arti
                | apa pun tanpa dokumen yang memilihnya.
                */
                ->cascadeOnDelete();

            $table->foreignId('transaction_category_id')
                ->constrained('fund_request_transaction_categories')

                /*
                | restrict, sama seperti pada FPU: keterangan transaksi yang masih
                | dipakai dokumen tidak boleh dihapus, karena hilangnya akan
                | mengosongkan himpunan dokumen yang sudah disetujui.
                */
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['claim_id', 'transaction_category_id'],
                'claim_transaction_category_unique',
            );

            $table->index('claim_id');
        });

        DB::statement(
            "COMMENT ON TABLE claim_transaction_categories IS "
            . "'Himpunan keterangan transaksi yang boleh dipakai baris rincian sebuah Claim.'",
        );

        /*
        | Dokumen yang sudah ada diisikan himpunan berisi satu: kategori yang
        | memang sudah dipilihnya. Tanpa ini, Claim lama dibuka di formulir
        | sunting dengan himpunan kosong, dan baris rinciannya kehilangan
        | seluruh pilihannya.
        */
        $baris = DB::table('claims')
            ->whereNotNull('transaction_category_id')
            ->whereNull('deleted_at')
            ->get(['id', 'transaction_category_id']);

        $sekarang = now();

        foreach ($baris as $satu) {
            DB::table('claim_transaction_categories')->insertOrIgnore([
                'claim_id' => $satu->id,
                'transaction_category_id' => $satu->transaction_category_id,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_transaction_categories');
    }
};
