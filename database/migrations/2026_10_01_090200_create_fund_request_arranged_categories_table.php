<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Kategori yang diurus GA, bukan ditagihkan pemohon
|--------------------------------------------------------------------------
| Satu baris berarti: pada dokumen ini, kategori itu diurus General Affair --
| hotelnya dipesankan, tiketnya dibelikan -- jadi pemohon tidak menagih
| apa-apa untuknya. Tidak ada baris rincian, dan sumbangannya pada total nol.
|
| BERUPA BARIS, BUKAN KOLOM JSON.
|
| Penanda ini menentukan uang: ia menjelaskan kenapa sebuah kategori kosong,
| dan membedakan "diurus GA" dari "lupa diisi". Sesuatu yang menentukan uang
| pantas bisa ditanyakan lewat kueri biasa -- berapa perjalanan yang
| hotelnya diurus GA kuartal ini, misalnya -- tanpa membongkar JSON.
|
| SATU TABEL UNTUK DUA MODUL.
|
| FPU dan Realisasi memakai penanda yang sama dengan arti yang sama. Dua
| tabel kembar hanya melahirkan dua aturan yang suatu hari berbeda; yang
| membedakan cukup satu kolom document_type.
|
| Catatan: penanda ini BELUM menunjuk ke business_trip_arrangements, tempat
| GA benar-benar mengunggah vouchernya. Kaitannya sengaja ditunda -- lihat
| catatan perubahan 1 Oktober 2026.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fund_request_arranged_categories')) {
            return;
        }

        Schema::create('fund_request_arranged_categories', function (Blueprint $table): void {
            $table->id();

            /* FPU atau REALISASI. */
            $table->string('document_type', 20);

            /*
            | Id dokumennya, tanpa foreign key -- ia menunjuk dua tabel yang
            | berbeda menurut document_type. Pembersihannya dikerjakan
            | pemiliknya saat dokumen dihapus.
            */
            $table->unsignedBigInteger('document_id');

            $table->foreignId('expense_category_id')
                ->constrained('business_trip_expense_categories')
                ->cascadeOnDelete();

            $table->timestamps();

            /*
            | Satu kategori hanya boleh ditandai sekali per dokumen. Tanpa ini,
            | penandanya bisa terkirim dua kali dan kategori yang sama muncul
            | berulang pada cetakannya.
            */
            $table->unique(
                ['document_type', 'document_id', 'expense_category_id'],
                'fund_request_arranged_unique',
            );

            $table->index(['document_type', 'document_id']);
        });

        DB::statement(
            "COMMENT ON TABLE fund_request_arranged_categories IS "
            . "'Kategori biaya yang diurus GA pada sebuah FPU atau Realisasi perjalanan dinas -- pemohon tidak menagih apa pun untuknya.'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_request_arranged_categories');
    }
};
