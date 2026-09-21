<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Keterangan kolom pada master keterangan transaksi
|--------------------------------------------------------------------------
| claimable_status disimpan sebagai angka, jadi artinya tidak terbaca dari
| datanya sendiri. Komentar kolom membuat maknanya ikut terbawa ke tools
| database mana pun -- DBeaver, pgAdmin, dump skema -- tanpa perlu membuka
| kode aplikasinya.
|
| Ditulis dengan COMMENT ON COLUMN, bukan $table->comment()->change(), supaya
| tidak memerlukan doctrine/dbal dan tidak menyentuh definisi kolomnya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    private const KOMENTAR = [
        'document_type' => 'Modul pemilik baris: ADVANCE = FPU, CLAIM = Claim. Satu baris melayani satu modul saja.',
        'claimable_status' => 'Kolom "Dapat Diklaim?" pada matriks Claim: 1 = Ya, 2 = Tidak, 3 = Ya terbatas, 4 = Sesuai kebijakan. NULL untuk baris ADVANCE.',
        'required_documents' => 'Kolom "Dokumen Pendukung Wajib" pada matriks Claim. NULL untuk baris ADVANCE.',
        'description' => 'Keterangan bebas. Pada baris CLAIM diisi kolom "Batasan / Ketentuan".',
    ];

    public function up(): void
    {
        foreach (self::KOMENTAR as $kolom => $komentar) {
            DB::statement(sprintf(
                "COMMENT ON COLUMN fund_request_transaction_categories.%s IS %s",
                $kolom,
                DB::getPdo()->quote($komentar),
            ));
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::KOMENTAR) as $kolom) {
            DB::statement(sprintf(
                'COMMENT ON COLUMN fund_request_transaction_categories.%s IS NULL',
                $kolom,
            ));
        }
    }
};
