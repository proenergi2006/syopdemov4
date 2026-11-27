<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Jejak pengiriman BDV ke Accurate
|--------------------------------------------------------------------------
| BDV yang sudah disetujui penuh dikirim ke Accurate sebagai Other Payment.
| Tiga kolom ini mencatat hasilnya, dan masing-masing punya tugas sendiri:
|
|   accurate_id       id yang dikembalikan Accurate (r.id pada jawabannya).
|                     Inilah penanda bahwa dokumen ini SUDAH masuk. Ia pula
|                     yang membuat pengiriman ulang tidak melahirkan dokumen
|                     kembar -- yang sudah terisi tidak dikirim lagi.
|
|   accurate_number   nomor dokumen di Accurate, untuk dicocokkan manusia
|                     saat menelusuri dari sisi akuntansi.
|
|   accurate_sent_at  kapan berhasilnya. Dipisah dari updated_at karena
|                     dokumennya masih bisa disunting sesudah terkirim.
|
| MENGAPA PENANDANYA ID, BUKAN SEKADAR BOOLEAN.
|
| Boolean hanya bisa menjawab "sudah atau belum". Ketika suatu hari ada yang
| bertanya "yang di Accurate itu yang mana", boolean tidak punya jawaban, dan
| penelusurannya terpaksa dilakukan dengan mencocokkan tanggal dan nominal --
| cara yang selalu berhasil menemukan sesuatu, belum tentu yang benar.
|
| INDEKS UNIK PARSIAL.
|
| Satu dokumen Accurate hanya boleh dimiliki satu BDV. Tanpa ini, pengiriman
| yang terputus di tengah lalu diulang bisa menautkan dua BDV ke satu dokumen
| yang sama, dan yang ketahuan belakangan hanyalah angkanya tidak cocok.
| Dibuat parsial supaya BDV yang belum terkirim -- nilainya null, dan jumlahnya
| banyak -- tidak saling bentrok.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    private const NAMA_INDEKS = 'bank_disbursement_vouchers_accurate_id_unique';

    public function up(): void
    {
        Schema::table('bank_disbursement_vouchers', function (Blueprint $table): void {
            $table->string('accurate_id', 50)->nullable();
            $table->string('accurate_number', 100)->nullable();
            $table->timestamp('accurate_sent_at')->nullable();
        });

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::NAMA_INDEKS
            . ' ON bank_disbursement_vouchers (accurate_id)'
            . ' WHERE accurate_id IS NOT NULL AND deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::NAMA_INDEKS);

        Schema::table('bank_disbursement_vouchers', function (Blueprint $table): void {
            $table->dropColumn([
                'accurate_id',
                'accurate_number',
                'accurate_sent_at',
            ]);
        });
    }
};
