<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Master Keterangan Transaksi (modul Pengajuan Dana)
|--------------------------------------------------------------------------
| Dipakai FPU sebagai salah satu penentu approval flow: satu dokumen punya
| satu keterangan transaksi, dan flow dipilih dari kombinasi area, department,
| keterangan transaksi, dan nominal.
|
| Sengaja TIDAK memakai master_keterangan_transaksi yang sudah ada -- tabel
| itu berisi kategori pajak vendor (PPh Pasal 23, Pasal 4 ayat 2, dan
| seterusnya), konsep yang sama sekali berbeda meski namanya mirip.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_request_transaction_categories', function (Blueprint $table) {
            $table->id();

            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();

            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_request_transaction_categories');
    }
};
