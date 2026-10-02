<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Finance menarik kembali penerimaan berkas
|--------------------------------------------------------------------------
| Sebelumnya dokumen yang terlanjur diterima tidak punya jalan mundur: satu-
| satunya pintu keluar adalah mencairkan uang yang justru tidak jadi dipakai.
| Aksi batal terima menutup lubang itu -- dokumennya kembali ke Approved, dan
| dari sana barulah bisa dibatalkan seperti biasa.
|
| KENAPA PERLU JEJAK SENDIRI.
|
| Menarik penerimaan berarti membatalkan tanggal pembayaran yang sudah
| dijanjikan ke pemohon lewat email. received_at dan scheduled_payment_date
| dikosongkan supaya dokumennya benar-benar bersih saat diterima ulang -- dan
| begitu dikosongkan, tidak ada lagi yang menunjukkan bahwa penerimaan itu
| pernah terjadi.
|
| Ketiga kolom ini yang menyimpannya: siapa yang menariknya, kapan, dan
| kenapa. Yang terakhir paling penting -- pemohon yang tanggal pembayarannya
| lenyap akan bertanya, dan jawabannya harus sudah ada sebelum ia bertanya.
|
| Penarikan berikutnya menimpa yang sebelumnya. Riwayat berlapis tidak
| dibuatkan tabel sendiri karena alurnya memang sekali jalan: ditarik, lalu
| dibatalkan atau diterima ulang. Kalau ternyata berulang-ulang di lapangan,
| itu pertanda alurnya yang perlu diperiksa, bukan tabelnya yang kurang.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    private const TABEL = [
        'cash_advances',
        'claims',
        'cash_advance_realizations',
    ];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            if (!Schema::hasTable($tabel)) {
                continue;
            }

            if (!Schema::hasColumn($tabel, 'receipt_reverted_by')) {
                Schema::table($tabel, function (Blueprint $table): void {
                    $table->unsignedBigInteger('receipt_reverted_by')->nullable();
                    $table->timestamp('receipt_reverted_at')->nullable();
                    $table->text('receipt_reversal_notes')->nullable();
                });

                DB::statement(
                    "COMMENT ON COLUMN {$tabel}.receipt_reversal_notes IS "
                    . "'Alasan Finance menarik kembali penerimaan berkas. Wajib diisi -- tanggal pembayaran yang sudah dijanjikan ke pemohon ikut batal karenanya.'",
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            if (!Schema::hasTable($tabel)) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $table): void {
                foreach ([
                    'receipt_reverted_by',
                    'receipt_reverted_at',
                    'receipt_reversal_notes',
                ] as $kolom) {
                    if (Schema::hasColumn($table->getTable(), $kolom)) {
                        $table->dropColumn($kolom);
                    }
                }
            });
        }
    }
};
