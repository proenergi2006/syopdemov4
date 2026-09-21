<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Keterangan Transaksi: pembeda FPU / Claim
|--------------------------------------------------------------------------
| Claim punya daftar jenis transaksinya sendiri, tetapi tetap ditampung pada
| tabel yang sama supaya master-nya satu pintu. Pembedanya kolom
| document_type -- satu baris melayani satu modul saja.
|
| Kolom baru lainnya mengikuti matriks Claim:
|
|   Dapat Diklaim?          -> claimable_status (kode angka)
|   Batasan / Ketentuan     -> description (kolom yang sudah ada)
|   Dokumen Pendukung Wajib -> required_documents
|
| Keduanya nullable karena baris FPU memang tidak memilikinya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_request_transaction_categories', function (Blueprint $table) {
            /*
            | ADVANCE / CLAIM. Disimpan sebagai string, bukan enum bawaan
            | PostgreSQL, supaya penambahan modul berikutnya tidak perlu
            | mengubah tipe kolomnya.
            */
            $table->string('document_type', 20)
                ->default('ADVANCE')
                ->after('code');

            /*
            | Kode "Dapat Diklaim?" pada matriks Claim:
            |
            |   1 = Ya
            |   2 = Tidak
            |   3 = Ya, terbatas
            |   4 = Sesuai kebijakan
            |
            | Disimpan sebagai angka, bukan teks, supaya penyaringan dan
            | pemeriksaannya nanti tidak bergantung pada ejaan.
            */
            $table->unsignedSmallInteger('claimable_status')
                ->nullable()
                ->after('description');

            // Kolom "Dokumen Pendukung Wajib" pada matriks Claim.
            $table->text('required_documents')
                ->nullable()
                ->after('claimable_status');

            $table->index('document_type');
        });

        /*
        | Seluruh baris yang sudah ada dibuat sebelum Claim ada, jadi semuanya
        | milik FPU. Ditulis eksplisit, tidak menyandarkan pada default kolom,
        | supaya maksudnya terbaca dan tetap benar bila defaultnya berubah.
        */
        DB::table('fund_request_transaction_categories')
            ->update(['document_type' => 'ADVANCE']);
    }

    public function down(): void
    {
        Schema::table('fund_request_transaction_categories', function (Blueprint $table) {
            $table->dropIndex(['document_type']);

            $table->dropColumn([
                'document_type',
                'claimable_status',
                'required_documents',
            ]);
        });
    }
};
