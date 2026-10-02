<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Kategori biaya perjalanan dinas
|--------------------------------------------------------------------------
| Transportasi, Penginapan, Uang Saku -- pengelompokan rincian FPU dan
| Realisasi yang berketerangan transaksi perjalanan dinas. Formulir kertas
| yang berjalan sekarang memang disusun begitu, dan rincian yang datar
| memaksa pembacanya mengelompokkan sendiri di kepalanya.
|
| NAMANYA MENYEBUT PERDIN, DAN ITU DISENGAJA.
|
| Kategori ini hanya berlaku pada dokumen berketerangan perjalanan dinas.
| Dinamai umum -- fund_request_expense_categories, misalnya -- ia cepat atau
| lambat akan ditawarkan pada dokumen yang tidak mengenalnya, dan yang
| menawarkannya tidak akan tahu kenapa itu keliru.
|
| Berupa master, bukan tetapan di kode: urutan tampil dan penambahan kategori
| keempat tidak semestinya menunggu rilis.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_trip_expense_categories')) {
            return;
        }

        Schema::create('business_trip_expense_categories', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 120);
            $table->text('description')->nullable();

            /*
            | Urutan tampilnya mengikuti formulir kertas: Transportasi dulu,
            | lalu Penginapan, lalu Uang Saku. Diatur, bukan diurutkan menurut
            | abjad -- abjad akan menaruh Penginapan sebelum Transportasi, dan
            | yang membaca cetakannya berhenti mengenalinya.
            */
            $table->integer('sort_order')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

        DB::statement(
            "COMMENT ON TABLE business_trip_expense_categories IS "
            . "'Kategori biaya pada rincian FPU dan Realisasi berketerangan perjalanan dinas. Hanya berlaku di sana.'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('business_trip_expense_categories');
    }
};
