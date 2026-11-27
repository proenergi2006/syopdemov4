<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Nomor BDV: unik hanya di antara voucher yang masih berlaku
|--------------------------------------------------------------------------
| Indeks lamanya POLOS -- unik atas seluruh isi tabel, tanpa satu pun
| syarat. Akibatnya nomor yang pernah dipakai terkunci selamanya: voucher
| yang sudah dihapus, ditolak, atau dibatalkan tetap menahan nomornya, dan
| orang yang hendak menerbitkan ulang dengan nomor yang sama ditolak dengan
| pesan "Nomor BDV tersebut sudah dipakai dokumen lain" -- menunjuk dokumen
| yang tidak bisa ia lihat di mana pun.
|
| Tiga keadaan yang melepaskan nomornya, dan masing-masing punya alasannya:
|
|   deleted_at terisi   vouchernya memang sudah tidak ada. Penghapusan BDV
|                       juga menghapus dokumennya di Accurate lebih dulu,
|                       jadi nomor itu benar-benar bebas di kedua sistem.
|
|   REJECTED            ditolak penyetuju, dan di BDV status ini terminal --
|                       pengajuan ulang hanya menerima DRAFT dan NEED
|                       REVISION. Vouchernya tidak akan pernah hidup lagi.
|
|   CANCELLED           dibatalkan. Syarat yang sama sudah dipakai indeks
|                       bank_disbursement_vouchers_source_unique di tabel
|                       ini, dan dua indeks pada satu tabel sebaiknya tidak
|                       punya tafsir berbeda tentang kata "batal".
|
| YANG PERLU DISADARI: REJECTED dan CANCELLED tidak menghapus dokumennya di
| Accurate. Nomornya bebas di sini, tetapi di sana dokumen lama masih
| memegang No. Bukti yang sama. Bila Accurate menolak nomor kembar, voucher
| barunya tetap tersimpan dan layar menampilkan peringatan kuning -- lihat
| BdvAccuratePostingService. Hanya penghapusan yang membebaskan keduanya.
|
| Memakai IF NOT EXISTS / IF EXISTS supaya aman dijalankan ulang.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        /*
        | Dilepas sebagai CONSTRAINT lebih dulu, baru sebagai INDEX.
        |
        | ->unique() milik Laravel menghasilkan UNIQUE CONSTRAINT pada
        | PostgreSQL, bukan indeks biasa. Keduanya bernama sama dan sama-sama
        | tampil di pg_indexes, tetapi DROP INDEX menolak menyentuh yang
        | berupa constraint -- PostgreSQL menjawabnya dengan saran "drop
        | constraint ... instead", dan migrasinya berhenti di situ.
        |
        | Menjatuhkan constraint-nya ikut menjatuhkan indeks pendukungnya,
        | jadi DROP INDEX di bawah hanya berlaku untuk basis data yang
        | indeksnya memang dibuat lepas.
        */
        DB::statement('
            ALTER TABLE bank_disbursement_vouchers
            DROP CONSTRAINT IF EXISTS bank_disbursement_vouchers_bdv_number_unique
        ');

        DB::statement('DROP INDEX IF EXISTS bank_disbursement_vouchers_bdv_number_unique');

        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS bank_disbursement_vouchers_bdv_number_unique
            ON bank_disbursement_vouchers (bdv_number)
            WHERE deleted_at IS NULL
              AND bdv_number IS NOT NULL
              AND status NOT IN ('REJECTED', 'CANCELLED')
        ");
    }

    public function down(): void
    {
        /*
        | Dikembalikan ke bentuk polosnya.
        |
        | Perlu disadari bila ini benar-benar dijalankan: bila sudah ada dua
        | voucher bernomor sama -- yang satu ditolak, yang satu berlaku --
        | pembuatan indeks polos ini akan GAGAL. Itu bukan kerusakan,
        | melainkan tabel yang memang sudah memuat keadaan yang tidak bisa
        | diwakili aturan lama.
        */
        DB::statement('
            ALTER TABLE bank_disbursement_vouchers
            DROP CONSTRAINT IF EXISTS bank_disbursement_vouchers_bdv_number_unique
        ');

        DB::statement('DROP INDEX IF EXISTS bank_disbursement_vouchers_bdv_number_unique');

        DB::statement('
            CREATE UNIQUE INDEX bank_disbursement_vouchers_bdv_number_unique
            ON bank_disbursement_vouchers (bdv_number)
        ');
    }
};
