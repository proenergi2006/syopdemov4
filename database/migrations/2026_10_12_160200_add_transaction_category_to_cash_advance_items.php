<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Keterangan transaksi pada baris rincian FPU
|--------------------------------------------------------------------------
| Inti permintaannya: keterangan transaksi menempel pada barisnya, bukan pada
| dokumennya. Satu FPU Rutin bisa memuat baris Telpon, baris Listrik, dan
| baris Pantry sekaligus -- masing-masing terbaca sebagai apa adanya, bukan
| melebur ke satu keterangan di header.
|
| JANGAN TERTUKAR DENGAN expense_category_id.
|
| Tabel ini kini punya DUA kolom berkata "kategori", dan keduanya hal yang
| berbeda:
|
|   transaction_category_id  keterangan transaksi -- Telpon, Listrik,
|                            Perjalanan Dinas. Dari master yang sama dengan
|                            header, dan dibatasi himpunan headernya.
|
|   expense_category_id      kategori biaya perjalanan dinas -- Tiket,
|                            Penginapan, Uang Saku. Hanya terisi pada baris
|                            perdin, dan untuk mengelompokkan rinciannya.
|
| NULLABLE, dan null punya arti.
|
| Baris FPU yang dibuat sebelum ini tidak punya keterangan transaksi sendiri;
| yang berlaku adalah milik headernya. Diisi paksa dengan nilai header akan
| menyatakan sesuatu yang tidak pernah dipilih siapa pun -- dan tidak bisa
| dibedakan lagi dari baris yang memang dipilih begitu. Null di sini berarti
| "ikut headernya", dan yang membacanya memang harus jatuh ke sana.
|
| nullOnDelete mengikuti expense_category_id di tabel yang sama: kategori yang
| hilang tidak boleh menyandera dokumen yang sudah disetujui, dan yang hilang
| cukup keterangannya -- angkanya tetap tinggal.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cash_advance_items', 'transaction_category_id')) {
            return;
        }

        Schema::table('cash_advance_items', function (Blueprint $table): void {
            $table->foreignId('transaction_category_id')
                ->nullable()
                ->after('cash_advance_id')
                ->constrained('fund_request_transaction_categories')
                ->nullOnDelete();
        });

        DB::statement(
            "COMMENT ON COLUMN cash_advance_items.transaction_category_id IS "
            . "'Keterangan transaksi baris ini, dibatasi himpunan di headernya. NULL berarti ikut keterangan transaksi header -- bukan belum diisi. Bukan expense_category_id, yang mengelompokkan biaya perdin.'",
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('cash_advance_items', 'transaction_category_id')) {
            return;
        }

        Schema::table('cash_advance_items', function (Blueprint $table): void {
            $table->dropForeign(['transaction_category_id']);

            $table->dropColumn('transaction_category_id');
        });
    }
};
