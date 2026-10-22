<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Batas FPU yang tidak membatasi apa pun
|--------------------------------------------------------------------------
| Untuk BOD: bebas dari "maksimal 3 FPU berjalan" dan dari "14 hari
| realisasi".
|
| PENANDA, BUKAN ANGKA BESAR.
|
| Godaannya mengisi max_outstanding = 999. Angka itu berbohong dua kali: di
| layar ia terbaca sebagai batas yang sungguhan ada, dan pada hari seseorang
| benar-benar menyentuhnya, ia memblokir orang yang justru dibebaskan.
|
| Kedua kolom angkanya dibiarkan NOT NULL dan tetap terisi. Penanda ini yang
| dibaca lebih dulu; angkanya tinggal sebagai nilai yang tidak dipakai, bukan
| sebagai nol yang bisa salah dibaca sebagai "tidak boleh mengajukan sama
| sekali".
|
| Bawaannya mati: seluruh batas yang sudah ada berlaku persis seperti kemarin.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fund_request_limits', 'is_unlimited')) {
            return;
        }

        Schema::table('fund_request_limits', function (Blueprint $table): void {
            $table->boolean('is_unlimited')->default(false)->after('max_realization_days');
        });

        DB::statement(
            "COMMENT ON COLUMN fund_request_limits.is_unlimited IS "
            . "'Batas ini tidak membatasi apa pun: jumlah FPU berjalan dan umur realisasi diabaikan. "
            . "max_outstanding dan max_realization_days tetap terisi tetapi tidak dipakai.'",
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('fund_request_limits', 'is_unlimited')) {
            return;
        }

        Schema::table('fund_request_limits', function (Blueprint $table): void {
            $table->dropColumn('is_unlimited');
        });
    }
};
