<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Keterangan transaksi sebuah FPU: tidak lagi satu
|--------------------------------------------------------------------------
| Manajemen menghendaki keterangan transaksi menempel pada BARIS rincian,
| bukan pada dokumennya. Headernya tetap ada, tetapi berubah peran: ia kini
| menyatakan HIMPUNAN keterangan transaksi yang boleh dipakai baris-barisnya.
|
| Pilihan di baris dibatasi himpunan itu, bukan seluruh master. Tanpa batas
| itu, satu FPU bisa memuat baris dari keterangan transaksi mana pun, dan
| angka pada laporan per keterangan transaksi berhenti bisa ditelusuri ke
| dokumen yang menyetujuinya.
|
| KOLOM LAMA cash_advances.transaction_category_id TIDAK DIHAPUS.
|
| Ia masih memilih alur approval dan jadwal pembayaran -- dua hal yang hanya
| bisa satu per dokumen. Memindahkannya ke himpunan ini menuntut aturan baru
| ("kategori mana yang menentukan bila himpunannya lebih dari satu"), dan
| aturan itu sengaja ditunda. Kolomnya tetap terisi: pada Non Rutin ia satu-
| satunya pilihan, pada Rutin ia yang pertama menurut urutan masternya.
|
| Lihat catatan perubahan 12 Oktober 2026.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_advance_transaction_categories')) {
            return;
        }

        Schema::create('cash_advance_transaction_categories', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('cash_advance_id')
                ->constrained('cash_advances')

                /*
                | Ikut terhapus bersama FPU-nya. Himpunan ini tidak punya arti
                | apa pun tanpa dokumen yang memilihnya -- berbeda dari baris
                | rincian, yang angkanya masih bisa ditelusuri.
                */
                ->cascadeOnDelete();

            $table->foreignId('transaction_category_id')
                ->constrained('fund_request_transaction_categories')

                /*
                | restrict, BUKAN cascade maupun nullOnDelete.
                |
                | Keterangan transaksi yang masih dipakai FPU tidak boleh
                | dihapus: hilangnya akan mengosongkan himpunan dokumen yang
                | sudah disetujui, dan baris rinciannya menunjuk ke ketiadaan.
                | Masternya memang sudah menolak penghapusan yang terpakai --
                | ini penjaga terakhirnya, di tempat yang tidak bisa dilewati.
                */
                ->restrictOnDelete();

            $table->timestamps();

            /*
            | Satu kategori hanya boleh disebut sekali per FPU. Tanpa ini,
            | himpunan yang terkirim dua kali membuat kategori yang sama muncul
            | berulang pada pemilih di baris rinciannya.
            */
            $table->unique(
                ['cash_advance_id', 'transaction_category_id'],
                'cash_advance_transaction_category_unique',
            );

            $table->index('cash_advance_id');
        });

        DB::statement(
            "COMMENT ON TABLE cash_advance_transaction_categories IS "
            . "'Himpunan keterangan transaksi yang boleh dipakai baris rincian sebuah FPU. Baris rinciannya memilih dari himpunan ini, bukan dari seluruh master.'",
        );

        /*
        | Dokumen yang sudah ada diisikan himpunan berisi satu: kategori yang
        | memang sudah dipilihnya. Tanpa ini, FPU lama dibuka di formulir
        | sunting dengan himpunan kosong, dan baris rinciannya kehilangan
        | seluruh pilihannya.
        |
        | insertOrIgnore: aman dijalankan ulang, dan tidak peduli urutan.
        */
        $baris = DB::table('cash_advances')
            ->whereNotNull('transaction_category_id')
            ->whereNull('deleted_at')
            ->get(['id', 'transaction_category_id']);

        $sekarang = now();

        foreach ($baris as $satu) {
            DB::table('cash_advance_transaction_categories')->insertOrIgnore([
                'cash_advance_id' => $satu->id,
                'transaction_category_id' => $satu->transaction_category_id,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advance_transaction_categories');
    }
};
