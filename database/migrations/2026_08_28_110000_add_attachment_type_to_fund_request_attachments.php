<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Jenis lampiran FPU dan Realisasi
    |--------------------------------------------------------------------------
    | Semula satu FPU hanya punya satu jenis lampiran: berkas pendukung yang
    | diunggah pemohon. Sekarang Finance juga bisa melampirkan bukti transfer
    | saat menandai FPU dibayarkan, dan bukti penyelesaian saat menutup selisih
    | realisasi.
    |
    | Keduanya disimpan pada tabel yang sama supaya tidak ada duplikasi struktur,
    | dan dibedakan lewat kolom ini. Tanpa pembeda, bukti transfer akan muncul
    | bercampur di daftar lampiran pengajuan dan ikut terhapus saat pemohon
    | menyunting draft-nya.
    |
    | Baris lama di-backfill sebagai REQUEST -- seluruhnya memang lampiran
    | pengajuan, karena fitur ini belum ada saat baris itu dibuat.
    |--------------------------------------------------------------------------
    */
    public function up(): void
    {
        Schema::table('cash_advance_attachments', function (Blueprint $table) {
            $table->string('attachment_type', 20)
                ->default('REQUEST')
                ->after('cash_advance_id');
        });

        Schema::table('cash_advance_realization_attachments', function (Blueprint $table) {
            $table->string('attachment_type', 20)
                ->default('REQUEST')
                ->after('cash_advance_realization_id');
        });

        DB::table('cash_advance_attachments')->update(['attachment_type' => 'REQUEST']);
        DB::table('cash_advance_realization_attachments')->update(['attachment_type' => 'REQUEST']);

        /*
        | Indeks gabungan: daftar lampiran selalu diambil per dokumen sekaligus
        | disaring per jenis.
        */
        Schema::table('cash_advance_attachments', function (Blueprint $table) {
            $table->index(
                ['cash_advance_id', 'attachment_type'],
                'cash_advance_attachments_document_type_index',
            );
        });

        Schema::table('cash_advance_realization_attachments', function (Blueprint $table) {
            $table->index(
                ['cash_advance_realization_id', 'attachment_type'],
                'ca_realization_attachments_document_type_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cash_advance_attachments', function (Blueprint $table) {
            $table->dropIndex('cash_advance_attachments_document_type_index');
            $table->dropColumn('attachment_type');
        });

        Schema::table('cash_advance_realization_attachments', function (Blueprint $table) {
            $table->dropIndex('ca_realization_attachments_document_type_index');
            $table->dropColumn('attachment_type');
        });
    }
};
