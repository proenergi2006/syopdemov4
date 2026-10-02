<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Rincian berkategori, berkuantitas, dan berharga satuan
|--------------------------------------------------------------------------
| Rincian FPU dan Realisasi perjalanan dinas kini berbentuk seperti formulir
| kertasnya: Item, Qty, Rincian Biaya, Total -- dikelompokkan per kategori.
|
| SATU TABEL, BUKAN DUA.
|
| Kolomnya ditambahkan ke tabel rincian yang sudah ada dan dibiarkan kosong
| untuk dokumen non-perdin. Tabel terpisah akan memaksa setiap pembacanya
| mengenal dua bentuk sekaligus -- cetakan PDF, export Excel, pencocokan
| Realisasi terhadap FPU, dan revisi nominal Finance -- dan satu di antaranya
| pasti terlupa suatu hari. Yang terlupa itu membaca angka yang salah tanpa
| satu pun galat.
|
| TOTALNYA TIDAK DISIMPAN SEBAGAI KOLOM BARU.
|
| Ia tetap masuk amount (FPU) dan realization_amount (Realisasi), yaitu kolom
| yang sudah dibaca seluruh sistem. Qty dan unit_price ditambahkan sebagai
| keterangan dari mana angka itu datang, bukan sebagai penggantinya.
|
| Tanggal tidak dihapus dari tabelnya. Baris perdin tidak mengisinya -- itu
| sudah cukup, dan dokumen lama yang terlanjur bertanggal tetap terbaca.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    /** Tabel rincian => kolom nominal totalnya. */
    private const TABEL = [
        'cash_advance_items' => 'amount',
        'cash_advance_realization_items' => 'realization_amount',
    ];

    public function up(): void
    {
        foreach (self::TABEL as $tabel => $kolomTotal) {
            if (!Schema::hasTable($tabel) || Schema::hasColumn($tabel, 'expense_category_id')) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $table) use ($kolomTotal): void {
                $table->foreignId('expense_category_id')
                    ->nullable()
                    ->after('description')

                    /*
                    | nullOnDelete, bukan restrict. Kategori yang dihapus tidak
                    | boleh menyandera dokumen yang sudah disetujui; yang hilang
                    | cukup pengelompokannya, sedangkan angkanya tetap tinggal.
                    */
                    ->constrained('business_trip_expense_categories')
                    ->nullOnDelete();

                $table->decimal('qty', 15, 2)->nullable()->after('expense_category_id');
                $table->decimal('unit_price', 20, 2)->nullable()->after('qty');
            });

            DB::statement(
                "COMMENT ON COLUMN {$tabel}.expense_category_id IS "
                . "'Kategori biaya perjalanan dinas. Null untuk dokumen yang keterangan transaksinya bukan perdin.'",
            );

            DB::statement(
                "COMMENT ON COLUMN {$tabel}.qty IS "
                . "'Banyaknya satuan. Null untuk baris non-perdin.'",
            );

            DB::statement(
                "COMMENT ON COLUMN {$tabel}.unit_price IS "
                . "'Harga satuan. Total = qty x unit_price, dan hasilnya disimpan di {$kolomTotal} -- kolom yang sudah dibaca seluruh sistem.'",
            );
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABEL) as $tabel) {
            if (!Schema::hasTable($tabel) || !Schema::hasColumn($tabel, 'expense_category_id')) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('expense_category_id');
                $table->dropColumn(['qty', 'unit_price']);
            });
        }
    }
};
