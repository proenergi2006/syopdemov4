<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Kolom PIC pada rundown perjalanan dibuang
|--------------------------------------------------------------------------
| Isiannya dihapus dari formulir, cetakan, dan email. Kolomnya ikut dibuang,
| bukan sekadar ditinggalkan kosong: kolom yang masih ada tetapi tidak pernah
| diisi lama-lama menjadi teka-teki bagi yang membaca basis datanya nanti --
| dan cepat atau lambat ada yang mengira ia masih dipakai.
|
| down() mengembalikan kolomnya, tetapi TIDAK bisa mengembalikan isinya. Itu
| memang sifat pembuangan kolom, dan disebut di sini supaya yang menjalankan
| rollback tahu apa yang tidak akan kembali.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('business_trip_itineraries', 'pic')) {
            return;
        }

        Schema::table('business_trip_itineraries', function (Blueprint $table): void {
            $table->dropColumn('pic');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('business_trip_itineraries', 'pic')) {
            return;
        }

        Schema::table('business_trip_itineraries', function (Blueprint $table): void {
            $table->string('pic', 150)->nullable()->after('description');
        });

        DB::statement(
            "COMMENT ON COLUMN business_trip_itineraries.pic IS "
            . "'Penanggung jawab kegiatan. Dihidupkan kembali oleh rollback; isinya tidak ikut kembali.'",
        );
    }
};
