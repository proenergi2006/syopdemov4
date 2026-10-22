<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Berapa kali PR dan PO pernah direvisi
|--------------------------------------------------------------------------
| Menyusul kolom yang sama pada FPU, Realisasi, Claim, dan Perdin, dan
| dengan alasan yang sama: yang paling sering menanyakan angka ini adalah
| DAFTAR -- daftar dokumen pemohon dan daftar tugas approver -- dan keduanya
| hanya perlu satu angka, bukan riwayatnya.
|
| Angka ini sekaligus nomor putaran rantai approval yang sedang hidup. Nol
| berarti dokumennya belum pernah dikembalikan, dan itulah keadaan seluruh
| PR dan PO yang sudah ada.
|
| Riwayat revisinya sendiri tidak butuh tabel baru: fund_request_revisions
| sudah polimorfik, dan kedua dokumen ini menyimpan hal yang sama persis.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    /** @var array<int, string> */
    private array $tabel = [
        'purchase_requests',
        'purchase_orders',
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
