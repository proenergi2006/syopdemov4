<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Riwayat pengembalian sisa dana
|--------------------------------------------------------------------------
| Pengembalian sisa dana bisa GAGAL, dan karena itu bisa BERULANG. Pemohon
| menyatakan sudah transfer, Finance memeriksa buktinya, lalu menolaknya --
| salah rekening, nominalnya kurang, buktinya milik transaksi lain. Pemohon
| memperbaiki dan menyatakan lagi.
|
| Sebelum ini seluruh perjalanan itu ditulis ke TIGA KOLOM pada dokumennya:
| return_declared_by, return_declared_at, return_declaration_notes. Satu baris
| untuk satu dokumen, sehingga pernyataan kedua menimpa yang pertama. Yang
| hilang bukan sekadar catatan: pemohon yang ditolak dua kali tidak bisa
| membaca lagi apa yang salah pada percobaan pertamanya, dan tidak seorang pun
| bisa menunjukkan bahwa dokumen ini sempat ditolak sebelum akhirnya diterima.
|
| Tiap percobaan kini punya barisnya sendiri, lengkap dengan hasilnya dan
| alasan penolakannya. Kolom lama pada dokumen TETAP dipakai sebagai cermin
| percobaan yang sedang berjalan -- seluruh kueri dan layar yang sudah
| membacanya tidak perlu diubah, dan tidak ada yang perlu menggabungkan tabel
| hanya untuk tahu dokumen ini sedang menunggu siapa.
|
| LAMPIRANNYA IKUT DIIKAT.
|
| Bukti transfer selama ini menempel ke dokumennya dengan jenis SETTLEMENT,
| tanpa penanda milik percobaan yang mana. Setelah percobaan kedua, bukti yang
| ditolak dan bukti yang diterima berbaur dalam satu daftar, dan yang membaca
| tidak punya cara membedakannya. Kolom return_declaration_id menutup itu.
|
| Berkas lama dibiarkan null: tidak ada percobaan yang bisa ditunjuknya, dan
| mengarang satu akan menyebut peristiwa yang tidak pernah terjadi.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_advance_realization_return_declarations', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('cash_advance_realization_id');

            /*
            | Nomor percobaan, mulai dari 1. Disimpan, bukan dihitung saat
            | dibaca: urutan yang dihitung ulang akan berubah bila satu baris
            | terhapus, dan "percobaan ke-2" yang berubah menjadi ke-1
            | membuat alasan penolakan menunjuk percobaan yang keliru.
            */
            $table->unsignedSmallInteger('attempt')->default(1);

            $table->unsignedBigInteger('declared_by')->nullable();
            $table->timestamp('declared_at')->nullable();

            /* Nominal yang DIAKUI pemohon, bukan selisih dokumennya. */
            $table->decimal('declared_amount', 20, 2)->default(0);

            $table->text('declaration_notes')->nullable();

            /*
            | PENDING  : menunggu Finance memeriksa.
            | CONFIRMED: uangnya dinyatakan benar-benar masuk; dokumen tutup.
            | REJECTED : tidak masuk; dokumennya kembali ke pemohon.
            */
            $table->string('status', 20)->default('PENDING');

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            /* Alasan penolakan, atau catatan Finance saat mengonfirmasi. */
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->foreign('cash_advance_realization_id')
                ->references('id')
                ->on('cash_advance_realizations')

                /*
                | Dokumen yang benar-benar terhapus tidak menyisakan riwayat
                | yang menunjuk ke mana-mana. Realisasi memakai soft delete,
                | jadi penghapusan biasa tidak menyentuh baris ini sama sekali.
                */
                ->cascadeOnDelete();

            /* Pegawai yang keluar tidak menghapus jejak pengakuannya. */
            $table->foreign('declared_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();

            /*
            | Dibaca selalu bersama-sama dan selalu berurutan: "riwayat
            | dokumen ini, percobaan pertama lebih dulu".
            */
            $table->index(
                ['cash_advance_realization_id', 'attempt'],
                'carrd_realization_attempt_idx',
            );
        });

        Schema::table('cash_advance_realization_attachments', function (Blueprint $table): void {
            $table->unsignedBigInteger('return_declaration_id')->nullable()->after('attachment_type');

            $table->foreign('return_declaration_id')
                ->references('id')
                ->on('cash_advance_realization_return_declarations')

                /*
                | Berkasnya TIDAK ikut terhapus bersama percobaannya: ia masih
                | tersimpan di disk dan masih menempel pada dokumennya. Yang
                | hilang hanya keterangan milik percobaan yang mana.
                */
                ->nullOnDelete();

            $table->index('return_declaration_id', 'carra_return_declaration_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cash_advance_realization_attachments', function (Blueprint $table): void {
            $table->dropForeign(['return_declaration_id']);
            $table->dropIndex('carra_return_declaration_idx');
            $table->dropColumn('return_declaration_id');
        });

        Schema::dropIfExists('cash_advance_realization_return_declarations');
    }
};
