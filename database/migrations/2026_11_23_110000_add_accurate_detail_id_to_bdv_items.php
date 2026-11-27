<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Id baris rincian di sisi Accurate
|--------------------------------------------------------------------------
| Dokumentasi other-payment/save.do menyebutkan detailAccount[n].id sebagai
| "need to be supplied for update / delete data". Tanpa menyimpannya, tiap
| kali BDV disimpan ulang kita mengirim daftar rincian yang seluruhnya tanpa
| id -- dan Accurate tidak punya cara mengetahui bahwa baris-baris itu adalah
| baris yang sudah ada, bukan baris baru.
|
| Akibatnya bukan galat yang terlihat, melainkan penggandaan yang diam: satu
| voucher yang disimpan tiga kali meninggalkan tiga kali lipat baris biaya di
| pembukuan, dengan nomor dokumen yang tetap satu. Yang menemukannya biasanya
| orang pajak, berbulan-bulan kemudian.
|
| Nullable, karena baru terisi sesudah Accurate menjawab. Baris yang belum
| pernah terkirim memang belum punya padanannya di sana.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bdv_items', function (Blueprint $table): void {
            $table->string('accurate_detail_id', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bdv_items', function (Blueprint $table): void {
            $table->dropColumn('accurate_detail_id');
        });
    }
};
