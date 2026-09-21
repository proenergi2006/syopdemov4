<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Lampiran dipindah ke tingkat baris rincian
    |--------------------------------------------------------------------------
    | Semula satu FPU punya satu tumpukan lampiran tanpa keterangan berkas mana
    | milik pengeluaran mana. Sekarang setiap baris rincian membawa buktinya
    | sendiri, sehingga saat dibaca ulang jelas bukti mana untuk pengeluaran
    | yang mana.
    |
    | Kolomnya nullable, dan itu disengaja:
    |
    | - Lampiran lama dibuat sebelum aturan ini ada, jadi tidak punya baris
    |   induk. Memaksakan NOT NULL berarti menebak-nebak pemiliknya.
    | - Bukti transfer (DISBURSEMENT) dan bukti penyelesaian (SETTLEMENT) memang
    |   milik dokumen, bukan milik satu baris rincian.
    |
    | Foreign key-nya cascade sebagai jaring pengaman terakhir, tetapi JANGAN
    | diandalkan untuk pembersihan sehari-hari: baris rincian memakai soft
    | delete, sehingga barisnya tidak benar-benar hilang dan cascade tidak
    | pernah terpicu. Penghapusan lampiran beserta berkasnya dilakukan eksplisit
    | oleh controller.
    |--------------------------------------------------------------------------
    */
    public function up(): void
    {
        Schema::table('cash_advance_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_advance_item_id')
                ->nullable()
                ->after('cash_advance_id');

            $table->foreign('cash_advance_item_id')
                ->references('id')
                ->on('cash_advance_items')
                ->cascadeOnDelete();

            $table->index(
                ['cash_advance_id', 'cash_advance_item_id'],
                'cash_advance_attachments_item_index',
            );
        });

        Schema::table('cash_advance_realization_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_advance_realization_item_id')
                ->nullable()
                ->after('cash_advance_realization_id');

            $table->foreign('cash_advance_realization_item_id', 'ca_realization_attachments_item_foreign')
                ->references('id')
                ->on('cash_advance_realization_items')
                ->cascadeOnDelete();

            $table->index(
                ['cash_advance_realization_id', 'cash_advance_realization_item_id'],
                'ca_realization_attachments_item_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('cash_advance_attachments', function (Blueprint $table) {
            $table->dropForeign(['cash_advance_item_id']);
            $table->dropIndex('cash_advance_attachments_item_index');
            $table->dropColumn('cash_advance_item_id');
        });

        Schema::table('cash_advance_realization_attachments', function (Blueprint $table) {
            $table->dropForeign('ca_realization_attachments_item_foreign');
            $table->dropIndex('ca_realization_attachments_item_index');
            $table->dropColumn('cash_advance_realization_item_id');
        });
    }
};
