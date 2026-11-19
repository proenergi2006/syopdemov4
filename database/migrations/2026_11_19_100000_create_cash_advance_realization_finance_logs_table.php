<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Riwayat proses Finance atas sebuah Realisasi
|--------------------------------------------------------------------------
| Tahap Finance kini hanya punya TIGA status, dan itu memang disengaja --
| yang mengerjakannya tidak perlu menghafal lima nama. Tetapi tiga status
| hanya menjawab "sekarang di mana", bukan "kapan dan oleh siapa".
|
| Kolom received_at, verified_at, dan settled_at sudah merekam sebagian,
| tetapi masing-masing hanya menyimpan SATU kali. Penerimaan yang ditarik
| lalu diterima lagi menimpa jejak yang pertama, dan sesudahnya tidak ada
| yang bisa menunjukkan bahwa dokumen ini sempat dikembalikan.
|
| Tabel ini merekam tiap peristiwanya sebagai barisnya sendiri: apa yang
| terjadi, status sebelum dan sesudahnya, siapa pelakunya, kapan, dan
| catatannya. Tidak pernah ditimpa dan tidak pernah dihapus.
|
| Ketiga cabang memakai tabel yang sama. Yang membedakan hanya peristiwa apa
| saja yang muncul di dalamnya -- cabang kekurangan punya pembayaran, cabang
| pengembalian punya pernyataan dan penolakan bukti, cabang yang nilainya pas
| tidak punya keduanya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_advance_realization_finance_logs', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('cash_advance_realization_id');

            /*
            | RECEIVED        : Finance menerima berkasnya
            | UNRECEIVED      : penerimaannya ditarik kembali
            | RETURN_DECLARED : pemohon menyatakan sudah mengembalikan dana
            | RETURN_REJECTED : Finance menolak bukti pengembaliannya
            | APPROVED        : Finance menyetujui -- ujung ketiga cabang
            | REIMBURSED      : kekurangannya dibayarkan kepada pemohon
            */
            $table->string('event', 30);

            /*
            | Status Finance sebelum dan sesudah peristiwanya.
            |
            | Disimpan, bukan disimpulkan dari urutan barisnya: peristiwa yang
            | TIDAK mengubah status -- pembayaran kekurangan, misalnya -- akan
            | terbaca keliru kalau statusnya ditebak dari baris sebelumnya.
            */
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('occurred_at')->nullable();

            /* Alasan penolakan, catatan penerimaan, nomor bukti transfer. */
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('cash_advance_realization_id')
                ->references('id')
                ->on('cash_advance_realizations')
                ->cascadeOnDelete();

            /* Pegawai yang keluar tidak menghapus jejak pekerjaannya. */
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();

            /* Selalu dibaca sekaligus dan selalu berurutan waktu. */
            $table->index(
                ['cash_advance_realization_id', 'occurred_at'],
                'carfl_realization_time_idx',
            );
        });

        /*
        | Jejak yang SUDAH ADA dipindahkan, bukan dibiarkan kosong.
        |
        | Dokumen yang sudah berjalan sebelum tabel ini lahir tetap menyimpan
        | received_at, verified_at, dan settled_at. Membiarkannya di luar
        | riwayat berarti dokumen lama tampil seolah tidak pernah dikerjakan
        | siapa pun -- dan riwayat yang bolong lebih menyesatkan daripada
        | riwayat yang pendek.
        |
        | Yang tidak pernah terekam tidak dikarang. Peristiwa yang kolomnya
        | kosong memang tidak dibuatkan barisnya.
        */
        $salin = function (string $kolomWaktu, string $kolomPelaku, string $event, ?string $to): void {
            DB::statement(
                'INSERT INTO cash_advance_realization_finance_logs'
                . ' (cash_advance_realization_id, event, from_status, to_status, actor_id, occurred_at, created_at, updated_at)'
                . ' SELECT id, ?, NULL, ?, ' . $kolomPelaku . ', ' . $kolomWaktu . ', NOW(), NOW()'
                . ' FROM cash_advance_realizations'
                . ' WHERE deleted_at IS NULL AND ' . $kolomWaktu . ' IS NOT NULL',
                [$event, $to],
            );
        };

        $salin('received_at', 'received_by', 'RECEIVED', 'ON CHECKING');
        $salin('settled_at', 'settled_by', 'APPROVED', 'APPROVED');
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advance_realization_finance_logs');
    }
};
