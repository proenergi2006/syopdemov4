<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Apakah ISI TERAKHIR voucher ini sudah sampai Accurate
|--------------------------------------------------------------------------
| accurate_id hanya menjawab "dokumennya pernah terbentuk di sana". Itu tidak
| sama dengan "angka di sini sama dengan angka di pembukuan".
|
| Keadaan yang luput sebelum kolom ini ada: voucher yang sudah terkirim, lalu
| diubah, dan perubahannya DITOLAK Accurate. accurate_id-nya masih terisi,
| jadi penjaga pengajuan meloloskannya -- orang menyetujui angka yang sudah
| berbeda dari pembukuan, dan bedanya baru ketahuan saat seseorang
| mencocokkan kedua sistem berminggu-minggu kemudian.
|
| KENAPA TIDAK CUKUP MEMBANDINGKAN updated_at DENGAN accurate_sent_at.
|
| Keduanya ditulis pada penyimpanan yang SAMA: pengiriman yang berhasil
| mengisi accurate_sent_at lewat save(), dan save() itu sendiri memperbarui
| updated_at. Jaraknya tinggal milidetik, dan "lebih baru" menjadi
| pertanyaan yang jawabannya bergantung pada urutan penulisan -- bukan dasar
| yang pantas dipakai menahan persetujuan uang.
|
| BAWAANNYA false, DAN ITU DISENGAJA.
|
| Voucher yang belum pernah dikirim memang belum sinkron. Baris lama
| diisikan dari accurate_id: yang punya id dianggap sinkron, karena sampai
| hari ini satu-satunya cara memilikinya adalah lewat pengiriman yang
| berhasil.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bank_disbursement_vouchers', 'accurate_in_sync')) {
            return;
        }

        Schema::table('bank_disbursement_vouchers', function (Blueprint $table) {
            $table->boolean('accurate_in_sync')->default(false)->after('accurate_sent_at');
        });

        /*
        | Baris lama. Dikerjakan sekali di sini, bukan dibiarkan false
        | seluruhnya: menandai seluruh voucher yang sudah berjalan sebagai
        | "belum sinkron" akan menahan pengajuan yang sebenarnya sah, dan
        | orang yang mengalaminya tidak punya cara tahu kenapa.
        */
        DB::statement('
            UPDATE bank_disbursement_vouchers
            SET accurate_in_sync = TRUE
            WHERE accurate_id IS NOT NULL
        ');

        /*
        | Dipakai pada daftar untuk menandai voucher yang tertinggal.
        | Partial: yang sudah sinkron tidak pernah dicari, dan ia justru
        | mayoritasnya.
        */
        DB::statement('
            CREATE INDEX IF NOT EXISTS bank_disbursement_vouchers_accurate_pending_index
            ON bank_disbursement_vouchers (id)
            WHERE accurate_in_sync = FALSE AND deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bank_disbursement_vouchers_accurate_pending_index');

        if (!Schema::hasColumn('bank_disbursement_vouchers', 'accurate_in_sync')) {
            return;
        }

        Schema::table('bank_disbursement_vouchers', function (Blueprint $table) {
            $table->dropColumn('accurate_in_sync');
        });
    }
};
