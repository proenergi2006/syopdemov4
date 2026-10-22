<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Sifat pengajuan sebuah keterangan transaksi
|--------------------------------------------------------------------------
| Sebuah keterangan transaksi kini dinyatakan sejak di masternya: ia berlaku
| untuk pengajuan Rutin, Non Rutin, atau dua-duanya. Formulir FPU lalu hanya
| menawarkan yang cocok dengan sifat pengajuan yang dipilih.
|
| DUA BOOLEAN, BUKAN SATU ENUM BERISI TIGA NILAI.
|
| "Keduanya" bukan jenis ketiga -- ia memang dua-duanya. Dengan enum, menyaring
| kategori untuk Rutin berarti mencari 'RUTIN' ATAU 'KEDUANYA', dan yang
| menulis kueri berikutnya cepat atau lambat lupa cabang keduanya. Dengan dua
| boolean, saringannya satu syarat: allows_routine = true.
|
| KEDUANYA MENYALA UNTUK DATA YANG SUDAH ADA.
|
| Bawaan false akan membuat seluruh keterangan transaksi yang berjalan
| sekarang mendadak hilang dari formulirnya, dan FPU tidak bisa dibuat sama
| sekali sampai seseorang menyetelnya satu per satu. Yang menyetel adalah
| pemiliknya, lewat master -- bukan migrasi ini.
|
| Catatan: aturan "Non Rutin tidak boleh memilih lebih dari satu keterangan
| transaksi" TIDAK tinggal di sini. Ia aturan dokumen, bukan sifat kategori,
| dan tempatnya di pemeriksaan FPU.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fund_request_transaction_categories', 'allows_routine')) {
            return;
        }

        Schema::table('fund_request_transaction_categories', function (Blueprint $table): void {
            $table->boolean('allows_routine')->default(true)->after('requires_business_trip');
            $table->boolean('allows_non_routine')->default(true)->after('allows_routine');
        });

        DB::statement(
            "COMMENT ON COLUMN fund_request_transaction_categories.allows_routine IS "
            . "'Boleh dipakai pengajuan bersifat Rutin. Boleh menyala bersama allows_non_routine.'",
        );

        DB::statement(
            "COMMENT ON COLUMN fund_request_transaction_categories.allows_non_routine IS "
            . "'Boleh dipakai pengajuan bersifat Non Rutin. Pada Non Rutin, FPU hanya boleh memilih satu keterangan transaksi.'",
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('fund_request_transaction_categories', 'allows_routine')) {
            return;
        }

        Schema::table('fund_request_transaction_categories', function (Blueprint $table): void {
            $table->dropColumn(['allows_routine', 'allows_non_routine']);
        });
    }
};
