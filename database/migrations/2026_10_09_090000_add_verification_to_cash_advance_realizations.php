<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Pemeriksaan realisasi sebelum kekurangannya dibayarkan
|--------------------------------------------------------------------------
| Realisasi yang nilainya MELEBIHI FPU-nya dikerjakan dua orang: yang pertama
| menerima dan memeriksa dokumennya, yang kedua membayarkan kekurangannya.
|
| Sebelum ini keduanya tidak terpisah sama sekali -- begitu dokumennya
| diterima, siapa pun pemegang izin pembayaran sudah bisa langsung membayar,
| dan pemeriksaan orang pertama tidak terekam di mana pun.
|
| Ketiga kolom ini merekam pemeriksaan itu: siapa, kapan, dan catatannya bila
| ada. Bentuknya sengaja ditiru dari received_* dan settled_* yang sudah ada
| -- satu peristiwa, satu pelaku, satu waktu, satu catatan.
|
| Hanya cabang kelebihan yang melewatinya. Realisasi yang nilainya pas atau
| kurang tidak pernah berpindah meja, jadi ketiga kolomnya tetap null di sana
| -- dan null di situ berarti "tidak pernah perlu", bukan "belum dikerjakan".
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_advance_realizations', function (Blueprint $table): void {
            $table->unsignedBigInteger('verified_by')->nullable()->after('received_by');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->text('verification_notes')->nullable()->after('verified_at');

            /*
            | nullOnDelete, sama seperti pelaku lain pada tabel ini: pegawai
            | yang keluar tidak boleh menghapus jejak dokumen yang pernah
            | diperiksanya, dan tidak boleh pula menahan penghapusan akunnya.
            */
            $table->foreign('verified_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_advance_realizations', function (Blueprint $table): void {
            $table->dropForeign(['verified_by']);

            $table->dropColumn(['verified_by', 'verified_at', 'verification_notes']);
        });
    }
};
