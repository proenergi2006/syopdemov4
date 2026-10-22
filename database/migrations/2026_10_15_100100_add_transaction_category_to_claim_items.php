<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Keterangan transaksi pada baris rincian Claim
|--------------------------------------------------------------------------
| Inti permintaannya, sama seperti di FPU: keterangan transaksi menempel pada
| barisnya, bukan pada dokumennya. Satu Claim bisa memuat baris fotokopi,
| baris parkir, dan baris ATK sekaligus -- masing-masing terbaca sebagai apa
| adanya.
|
| TIDAK ADA expense_category_id DI SINI.
|
| Berbeda dari FPU dan Realisasi, baris Claim tidak pernah dikelompokkan per
| kategori biaya perjalanan dinas -- tabelnya memang tidak punya kolomnya, dan
| Claim perdin pun rinciannya tetap datar dan bertanggal. Jadi di tabel ini
| hanya ada satu kolom berkata "kategori", dan tidak ada yang bisa tertukar.
|
| NULLABLE, dan null punya arti: baris yang dibuat sebelum ini mengikuti
| keterangan transaksi headernya. Mengisinya paksa akan menyatakan pilihan
| yang tidak pernah dibuat siapa pun.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('claim_items', 'transaction_category_id')) {
            return;
        }

        Schema::table('claim_items', function (Blueprint $table): void {
            $table->foreignId('transaction_category_id')
                ->nullable()
                ->after('claim_id')
                ->constrained('fund_request_transaction_categories')
                ->nullOnDelete();
        });

        DB::statement(
            "COMMENT ON COLUMN claim_items.transaction_category_id IS "
            . "'Keterangan transaksi baris ini, dibatasi himpunan di headernya. NULL berarti ikut keterangan transaksi header -- bukan belum diisi.'",
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('claim_items', 'transaction_category_id')) {
            return;
        }

        Schema::table('claim_items', function (Blueprint $table): void {
            $table->dropForeign(['transaction_category_id']);

            $table->dropColumn('transaction_category_id');
        });
    }
};
