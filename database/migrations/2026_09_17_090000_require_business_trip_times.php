<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Jam berangkat dan jam kembali wajib diisi
|--------------------------------------------------------------------------
| Keduanya lahir sebagai kolom yang boleh kosong, mengikuti formulir kertasnya
| yang kadang hanya menulis tanggal. Manajemen meminta keduanya wajib.
|
| Dinyatakan di basis data, bukan hanya di validasi -- sama seperti Tujuan dan
| Keperluan pada modul ini, yang memang sejak awal tidak boleh kosong. Aturan
| yang hanya ada di validasi tetap bisa dilewati oleh jalur lain: seeder,
| perbaikan data, atau impor.
|
| Modul ini baru lahir dan belum punya satu pun dokumen, jadi tidak ada baris
| yang perlu ditambal lebih dulu. Kalau ternyata ada, migrasinya berhenti
| dengan penjelasan -- bukan dengan galat Postgres yang tidak menyebut apa pun,
| dan bukan dengan menebak jam yang tidak pernah dituliskan orangnya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        $kosong = DB::table('business_trips')
            ->where(function ($q): void {
                $q->whereNull('depart_time')->orWhereNull('return_time');
            })
            ->count();

        if ($kosong > 0) {
            throw new RuntimeException(
                "Ada {$kosong} Perjalanan Dinas yang jam berangkat atau jam kembalinya "
                . 'masih kosong. Lengkapi dulu datanya sebelum menjalankan migrasi ini -- '
                . 'jamnya tidak boleh ditebak oleh sistem.',
            );
        }

        DB::statement('ALTER TABLE business_trips ALTER COLUMN depart_time SET NOT NULL');
        DB::statement('ALTER TABLE business_trips ALTER COLUMN return_time SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE business_trips ALTER COLUMN depart_time DROP NOT NULL');
        DB::statement('ALTER TABLE business_trips ALTER COLUMN return_time DROP NOT NULL');
    }
};
