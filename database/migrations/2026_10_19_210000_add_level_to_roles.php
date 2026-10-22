<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Tingkat jabatan sebuah role
|--------------------------------------------------------------------------
| 1 staf, 2 supervisor, 3 head/asisten manager, 4 manager, 5 direksi.
|
| Dipakai satu aturan saja: approval tidak boleh turun ke bawah dalam satu
| garis department. Step yang approvernya BERTINGKAT SAMA ATAU LEBIH RENDAH
| daripada pemohon, DAN berada di department yang sama, tidak ikut digambar
| saat dokumen dibuat.
|
| Department berbeda tidak pernah digugurkan oleh aturan ini -- di sana yang
| terjadi pemeriksaan fungsi (GA memeriksa perjalanan, HRGA memeriksa
| kepegawaian), bukan atasan menyetujui bawahan.
|
| NULL BERARTI BELUM DIATUR, DAN ATURANNYA TIDAK BERLAKU.
|
| Itu bawaannya, dan itu disengaja: selama angkanya belum diisi, seluruh alur
| berjalan persis seperti sebelum kolom ini ada. Mengisinya bisa bertahap.
|
| Angkanya DATA, bukan kode: diubah lewat master role, tanpa menyentuh
| aturan approval mana pun.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('roles', 'level')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table): void {
            $table->smallInteger('level')->nullable()->after('nama');
        });

        DB::statement(
            "COMMENT ON COLUMN roles.level IS "
            . "'Tingkat jabatan: 1 staf, 2 supervisor, 3 head, 4 manager, 5 direksi. "
            . "NULL berarti belum diatur dan aturan \"bawahan tidak menyetujui atasan\" tidak berlaku untuk role ini.'",
        );
    }

    public function down(): void
    {
        if (!Schema::hasColumn('roles', 'level')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('level');
        });
    }
};
