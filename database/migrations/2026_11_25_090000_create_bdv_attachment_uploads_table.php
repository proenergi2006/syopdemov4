<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Bukti yang sudah terkirim ke Accurate
|--------------------------------------------------------------------------
| Satu baris = satu berkas yang sudah benar-benar diterima Accurate untuk
| satu voucher.
|
| GUNANYA MENAHAN PENGIRIMAN ULANG, dan itu bukan sekadar penghematan.
| Menyimpan BDV berarti mengirim ulang dokumennya ke Accurate, dan orang
| memang menyimpan berkali-kali -- memperbaiki penerima, mengganti akun satu
| baris. Tanpa catatan ini, setiap simpan melampirkan ulang berkas yang sama:
| satu transaksi hanya boleh memegang 30 lampiran, dan jatah itu habis oleh
| salinan dari berkas yang itu-itu juga.
|
| Dicatat SESUDAH Accurate menjawab berhasil, bukan sebelum mengirim. Yang
| gagal harus tetap bisa dicoba lagi -- menekan Simpan sekali lagi adalah cara
| menyelesaikannya.
|
| KUNCINYA BERTIGA: bdv_id, document_type, attachment_id.
|
| document_type perlu karena id lampiran datang dari DUA tabel yang berbeda
| -- cash_advance_attachments dan claim_attachments -- dan keduanya menomori
| dirinya sendiri mulai dari satu. Tanpa jenisnya, lampiran FPU nomor 5
| dianggap sama dengan lampiran Claim nomor 5, dan salah satunya diam-diam
| tidak pernah terkirim.
|
| Tidak ada foreign key ke tabel lampirannya: relasinya polimorfis, dan
| lampiran yang dihapus di dokumen sumber tidak boleh menyeret catatan ini --
| berkasnya terlanjur ada di Accurate, dan catatan itulah satu-satunya bukti
| bahwa ia pernah dikirim dari sini.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bdv_attachment_uploads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bdv_id')
                ->constrained('bank_disbursement_vouchers')
                ->cascadeOnDelete();

            $table->string('document_type', 20);
            $table->unsignedBigInteger('attachment_id');

            /* Id lampiran di Accurate, untuk menelusurinya kembali ke sana. */
            $table->string('accurate_id', 50)->nullable();

            /*
            | Nama berkasnya ikut disalin. Lampiran bisa dihapus di dokumen
            | sumber, dan tanpa salinan ini catatan yang tertinggal hanya
            | berupa deretan angka yang tidak bisa dibaca siapa pun.
            */
            $table->string('filename', 255)->nullable();

            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['bdv_id', 'document_type', 'attachment_id'],
                'bdv_attachment_uploads_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bdv_attachment_uploads');
    }
};
