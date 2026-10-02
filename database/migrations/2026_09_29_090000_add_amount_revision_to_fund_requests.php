<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Revisi nominal oleh Finance
|--------------------------------------------------------------------------
| Finance boleh membetulkan angka yang diajukan saat menerima berkasnya --
| nota yang ternyata beda, biaya yang tidak jadi, pembulatan yang keliru.
|
| ANGKA PEMOHON TIDAK HILANG.
|
| Yang diubah adalah amount, dan angka semula disalin ke original_amount
| lebih dulu. Tanpa itu, dokumen yang sudah direvisi tidak bisa lagi
| dibandingkan dengan yang diajukan: pemohon ingat ia menulis 1.500.000,
| layar menunjukkan 1.200.000, dan tidak ada apa pun yang bisa menengahi.
|
| Kolomnya null selama belum pernah direvisi. Null berarti "apa adanya,
| seperti yang diajukan" -- bukan nol, dan bukan disalin dari amount. Menyalin
| nilai yang sama ke dua kolom membuat "pernah direvisi" tidak bisa dibedakan
| dari "kebetulan angkanya sama".
|
| Siapa dan kapan tidak diberi kolom sendiri: revisinya menempel pada aksi
| terima, jadi received_by dan received_at sudah menjawab keduanya. Yang
| belum terjawab hanya kenapa -- itulah amount_revision_notes.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    /** Dokumen dan baris rinciannya, sepasang per modul. */
    private const TABEL = [
        'cash_advances' => 'cash_advance_items',
        'claims' => 'claim_items',
    ];

    public function up(): void
    {
        foreach (self::TABEL as $dokumen => $rincian) {
            if (Schema::hasTable($rincian) && !Schema::hasColumn($rincian, 'original_amount')) {
                Schema::table($rincian, function (Blueprint $table): void {
                    $table->decimal('original_amount', 20, 2)
                        ->nullable()
                        ->after('amount');
                });

                DB::statement(
                    "COMMENT ON COLUMN {$rincian}.original_amount IS "
                    . "'Nominal yang diajukan pemohon, disalin ke sini saat Finance merevisinya. Null berarti belum pernah direvisi.'",
                );
            }

            if (!Schema::hasTable($dokumen)) {
                continue;
            }

            if (!Schema::hasColumn($dokumen, 'original_total_amount')) {
                Schema::table($dokumen, function (Blueprint $table): void {
                    $table->decimal('original_total_amount', 20, 2)
                        ->nullable()
                        ->after('total_amount');
                });

                DB::statement(
                    "COMMENT ON COLUMN {$dokumen}.original_total_amount IS "
                    . "'Total yang diajukan pemohon sebelum revisi Finance. Null berarti belum pernah direvisi.'",
                );
            }

            if (!Schema::hasColumn($dokumen, 'amount_revision_notes')) {
                Schema::table($dokumen, function (Blueprint $table): void {
                    $table->text('amount_revision_notes')->nullable();
                });

                DB::statement(
                    "COMMENT ON COLUMN {$dokumen}.amount_revision_notes IS "
                    . "'Alasan Finance merevisi nominalnya. Wajib diisi saat merevisi -- angka orang lain tidak boleh berubah tanpa penjelasan.'",
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $dokumen => $rincian) {
            if (Schema::hasTable($rincian) && Schema::hasColumn($rincian, 'original_amount')) {
                Schema::table($rincian, function (Blueprint $table): void {
                    $table->dropColumn('original_amount');
                });
            }

            if (!Schema::hasTable($dokumen)) {
                continue;
            }

            Schema::table($dokumen, function (Blueprint $table): void {
                foreach (['original_total_amount', 'amount_revision_notes'] as $kolom) {
                    if (Schema::hasColumn($table->getTable(), $kolom)) {
                        $table->dropColumn($kolom);
                    }
                }
            });
        }
    }
};
