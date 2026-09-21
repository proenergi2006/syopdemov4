<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Tahap "Diterima" pada FPU
|--------------------------------------------------------------------------
| Menyisipkan satu tahap di antara persetujuan dan pencairan:
|
|   APPROVED -> RECEIVED -> DISBURSED
|
| Pemegang permission cash_advance.receive menandai bahwa dokumennya sudah
| diterima untuk diproses, baru setelah itu dananya boleh dicairkan.
|
| Kolomnya dibuat mengikuti pola pencairan yang sudah ada (disbursed_by,
| disbursed_at, disbursement_notes) supaya keduanya terbaca sebagai satu
| keluarga, bukan dua rancangan yang berbeda.
|
| Status disimpan sebagai teks tanpa CHECK constraint, jadi nilai RECEIVED
| tidak memerlukan perubahan struktur apa pun.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_advances', function (Blueprint $table): void {
            $table->unsignedBigInteger('received_by')->nullable()->after('cancellation_notes');
            $table->timestamp('received_at')->nullable()->after('received_by');
            $table->text('receipt_notes')->nullable()->after('received_at');
        });

        DB::statement("COMMENT ON COLUMN cash_advances.received_by IS 'User pemegang permission cash_advance.receive yang menandai FPU diterima'");
        DB::statement("COMMENT ON COLUMN cash_advances.received_at IS 'Waktu FPU ditandai diterima, sebelum dicairkan'");
        DB::statement("COMMENT ON COLUMN cash_advances.receipt_notes IS 'Catatan opsional saat FPU ditandai diterima'");

        /*
        | Dokumen yang terlanjur dicairkan sebelum tahap ini ada tidak punya
        | jejak penerimaan. Diisi dari data pencairannya supaya riwayatnya
        | tetap utuh dan tidak ada dokumen DISBURSED yang seolah melewati
        | tahap penerimaan.
        */
        DB::table('cash_advances')
            ->where('status', 'DISBURSED')
            ->whereNull('received_at')
            ->update([
                'received_by' => DB::raw('disbursed_by'),
                'received_at' => DB::raw('disbursed_at'),
                'receipt_notes' => 'Terisi otomatis: dokumen ini dicairkan sebelum tahap penerimaan diberlakukan.',
            ]);
    }

    public function down(): void
    {
        Schema::table('cash_advances', function (Blueprint $table): void {
            $table->dropColumn(['received_by', 'received_at', 'receipt_notes']);
        });

        /*
        | Dokumen yang sedang berada di tahap penerimaan dikembalikan ke
        | approved -- tanpa kolomnya, status RECEIVED tidak punya arti.
        */
        DB::table('cash_advances')
            ->where('status', 'RECEIVED')
            ->update(['status' => 'APPROVED']);
    }
};
