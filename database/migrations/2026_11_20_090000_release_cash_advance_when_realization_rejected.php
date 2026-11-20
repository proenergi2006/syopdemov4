<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| FPU dilepas kembali ketika realisasinya ditolak atau dibatalkan
|--------------------------------------------------------------------------
| Satu FPU hanya boleh punya satu realisasi, dan itu dijaga indeks unik
| parsial di bawah ini. Yang terlewat: "satu" dihitung tanpa melihat status.
|
| Akibatnya realisasi yang DITOLAK approver atau DIBATALKAN pemohon tetap
| memegang kursinya. FPU-nya hilang dari pemilih, tidak bisa dipertanggung-
| jawabkan lagi oleh siapa pun, dan dananya menggantung selamanya -- padahal
| yang terjadi justru sebaliknya: dokumen yang ditolak berarti
| pertanggungjawabannya HARUS diulang.
|
| Penolakan bukan akhir perkara. Ia memulangkan dokumennya, bukan
| memulangkan uangnya.
|
| Indeksnya kini mengecualikan kedua status itu, sejalan dengan konstanta
| CashAdvanceRealization::RELEASED_STATUSES yang sudah lama ada di model dan
| sudah dipakai modul FPU untuk memutuskan boleh tidaknya FPU dibatalkan.
| Yang ganjil selama ini: konstanta itu ada, benar, dan dipakai di satu
| tempat -- sementara tiga tempat lain memutuskan hal yang sama dengan
| aturan yang berbeda.
|
| STATUSNYA DITULIS SEBAGAI TEKS, bukan membaca konstanta PHP. Indeks hidup
| di basis data dan tidak ikut berubah saat konstantanya berubah; menuliskan
| nilainya terang-terangan membuat ketidakcocokan itu terlihat saat dibaca,
| bukan tersembunyi di balik nama.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    private const NAMA = 'cash_advance_realization_unique_per_advance';

    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::NAMA);

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::NAMA
            . ' ON cash_advance_realizations (cash_advance_id)'
            . " WHERE deleted_at IS NULL AND UPPER(TRIM(status)) NOT IN ('REJECTED', 'CANCELLED')",
        );
    }

    public function down(): void
    {
        /*
        | Dikembalikan apa adanya. Bila sudah ada FPU yang punya DUA realisasi
        | -- satu ditolak, satu yang baru -- indeks lama akan menolak
        | dibentuk, dan itu benar: ia memang tidak bisa menampung keadaan yang
        | lahir sesudahnya.
        */
        DB::statement('DROP INDEX IF EXISTS ' . self::NAMA);

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::NAMA
            . ' ON cash_advance_realizations (cash_advance_id)'
            . ' WHERE deleted_at IS NULL',
        );
    }
};
