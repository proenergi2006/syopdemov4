<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Pengembalian sisa dana: dari satu peristiwa menjadi dua
|--------------------------------------------------------------------------
| Realisasi yang nilainya LEBIH KECIL dari FPU-nya menyisakan uang yang harus
| dikembalikan pemohon ke Finance. Uangnya berpindah di luar sistem -- lewat
| transfer atau diserahkan langsung -- sehingga yang dikerjakan di sini hanya
| penandaannya.
|
| Sebelum ini penandaannya SATU peristiwa: satu tombol, ditekan pemegang izin
| pengembalian, dan dokumennya langsung tertutup SETTLED. Dua hal keliru di
| sana.
|
| Pertama, yang menekan bukan orang yang menyerahkan uangnya. Izinnya dipegang
| 38 akun dari 15 role -- Marketing, Service Advisor, Admin Warehouse -- dan
| tidak satu pun pemeriksaan memastikan penekannya adalah pemohon dokumen itu.
| Pemohonnya sendiri justru tidak memegang izin itu: dari 82 akun yang bisa
| membuat realisasi, 44 di antaranya tidak bisa menandai pengembaliannya.
|
| Kedua, pengakuan dan penerimaan dijadikan satu. Pemohon menyatakan sudah
| transfer, dan di detik yang sama dokumennya dinyatakan lunas -- padahal
| tidak ada seorang pun di sisi Finance yang menghitung uangnya masuk. Bukti
| transfernya wajib diunggah, tetapi tidak pernah ada yang memeriksanya.
|
| Ketiga kolom ini memisahkan keduanya. Pemohon menyatakan (return_declared_*,
| status RETURN DECLARED), lalu Finance mengonfirmasi uangnya benar diterima
| (settled_*, status SETTLED) -- kolom settled_* yang sudah ada tetap berarti
| hal yang sama seperti sebelumnya: titik dokumen itu benar-benar tutup.
|
| Bentuknya ditiru dari received_*, verified_*, dan settled_*: satu peristiwa,
| satu pelaku, satu waktu, satu catatan.
|
| HANYA CABANG PENGEMBALIAN yang melewatinya. Realisasi yang nilainya pas atau
| yang justru kurang tidak pernah menyentuh ketiga kolom ini, dan null di sana
| berarti "tidak pernah perlu", bukan "belum dikerjakan".
|
| Dokumen lama tidak disentuh. Yang sudah SETTLED tetap SETTLED dengan
| return_declared_* kosong -- jejak itu memang tidak pernah direkam saat itu,
| dan mengarangnya sekarang akan menyebut pelaku yang tidak pernah melakukannya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_advance_realizations', function (Blueprint $table): void {
            $table->unsignedBigInteger('return_declared_by')->nullable()->after('verification_notes');
            $table->timestamp('return_declared_at')->nullable()->after('return_declared_by');
            $table->text('return_declaration_notes')->nullable()->after('return_declared_at');

            /*
            | nullOnDelete, sama seperti pelaku lain pada tabel ini: pegawai
            | yang keluar tidak boleh menghapus jejak pengembalian yang pernah
            | ia nyatakan, dan tidak boleh pula menahan penghapusan akunnya.
            */
            $table->foreign('return_declared_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_advance_realizations', function (Blueprint $table): void {
            $table->dropForeign(['return_declared_by']);

            $table->dropColumn([
                'return_declared_by',
                'return_declared_at',
                'return_declaration_notes',
            ]);
        });
    }
};
