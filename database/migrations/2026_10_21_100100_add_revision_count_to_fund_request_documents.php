<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Berapa kali dokumennya pernah direvisi
|--------------------------------------------------------------------------
| Angkanya sebenarnya bisa dihitung dari fund_request_revisions. Ia tetap
| ditaruh di dokumennya karena yang paling sering menanyakannya adalah
| DAFTAR: daftar dokumen pemohon, dan daftar tugas approver. Keduanya memuat
| puluhan baris sekaligus, dan keduanya hanya perlu satu angka -- bukan
| riwayatnya.
|
| Angka ini juga yang menjadi nomor putaran yang sedang berjalan: rantai
| approval yang hidup sekarang adalah putaran ke-revision_count. Nol berarti
| dokumennya belum pernah dikembalikan sama sekali, dan itulah keadaan
| seluruh dokumen yang sudah ada.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tabel = [
        'cash_advances',
        'claims',
        'cash_advance_realizations',
        'business_trips',
    ];

    public function up(): void
    {
        foreach ($this->tabel as $nama) {
            if (Schema::hasColumn($nama, 'revision_count')) {
                continue;
            }

            Schema::table($nama, function (Blueprint $table): void {
                $table->unsignedInteger('revision_count')->default(0);
            });

            DB::statement(
                "COMMENT ON COLUMN {$nama}.revision_count IS "
                . "'Jumlah kali dokumen ini dikembalikan ke pemohon untuk direvisi. "
                . "Sekaligus nomor putaran rantai approval yang sedang hidup.'",
            );
        }
    }

    public function down(): void
    {
        foreach ($this->tabel as $nama) {
            if (!Schema::hasColumn($nama, 'revision_count')) {
                continue;
            }

            Schema::table($nama, function (Blueprint $table): void {
                $table->dropColumn('revision_count');
            });
        }
    }
};
