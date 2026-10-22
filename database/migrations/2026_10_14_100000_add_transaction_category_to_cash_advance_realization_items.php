<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Keterangan transaksi pada baris realisasi
|--------------------------------------------------------------------------
| Mengikuti FPU-nya: sejak 12 Oktober 2026 keterangan transaksi menempel pada
| baris, bukan pada dokumennya. Realisasi mempertanggungjawabkan baris-baris
| itu, jadi ia perlu menyebut baris mana menjawab keterangan transaksi yang
| mana.
|
| HIMPUNANNYA TIDAK DISALIN KE SINI.
|
| Realisasi tidak punya tabel pivot sendiri. Himpunan yang boleh dipakai
| barisnya dibaca dari FPU sumbernya, dan memang tidak bisa diubah di
| realisasi -- menyalinnya berarti dua tempat yang bisa berbeda tanpa ada yang
| memberi tahu.
|
| JANGAN TERTUKAR DENGAN expense_category_id.
|
| Sama seperti di FPU, tabel ini kini punya dua kolom berkata "kategori":
|
|   transaction_category_id  keterangan transaksi -- Telpon, Listrik
|   expense_category_id      kategori biaya perdin -- Tiket, Penginapan
|
| NULL berarti "ikut keterangan transaksi FPU sumbernya", bukan "belum diisi".
| Baris realisasi yang dibuat sebelum ini memang tidak punya pilihannya
| sendiri, dan mengisinya paksa akan menyatakan pilihan yang tidak pernah
| dibuat siapa pun.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cash_advance_realization_items', 'transaction_category_id')) {
            return;
        }

        Schema::table('cash_advance_realization_items', function (Blueprint $table): void {
            $table->foreignId('transaction_category_id')
                ->nullable()
                ->after('cash_advance_item_id')
                ->constrained('fund_request_transaction_categories')
                ->nullOnDelete();
        });

        DB::statement(
            "COMMENT ON COLUMN cash_advance_realization_items.transaction_category_id IS "
            . "'Keterangan transaksi baris ini, dibatasi himpunan pada FPU sumbernya. NULL berarti ikut FPU sumbernya -- bukan belum diisi.'",
        );

        /*
        | Baris yang sudah ada diisikan dari baris FPU asalnya.
        |
        | Keduanya memang tertaut lewat cash_advance_item_id, jadi jawabannya
        | sudah diketahui -- membiarkannya null akan membuat realisasi lama
        | tampak belum memilih apa pun, padahal pilihannya tidak pernah ada
        | untuk dibuat.
        */
        DB::statement("
            UPDATE cash_advance_realization_items AS r
               SET transaction_category_id = i.transaction_category_id
              FROM cash_advance_items AS i
             WHERE r.cash_advance_item_id = i.id
               AND i.transaction_category_id IS NOT NULL
               AND r.transaction_category_id IS NULL
        ");
    }

    public function down(): void
    {
        if (!Schema::hasColumn('cash_advance_realization_items', 'transaction_category_id')) {
            return;
        }

        Schema::table('cash_advance_realization_items', function (Blueprint $table): void {
            $table->dropForeign(['transaction_category_id']);

            $table->dropColumn('transaction_category_id');
        });
    }
};
