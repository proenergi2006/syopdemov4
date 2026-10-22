<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Riwayat revisi dokumen: FPU, Realisasi, Claim, Perdin
|--------------------------------------------------------------------------
| Satu dokumen yang dikembalikan approver ke pemohon, direvisi, lalu diajukan
| ulang, meninggalkan satu baris di sini.
|
| SATU TABEL UNTUK EMPAT MODUL.
|
| Keempatnya dikembalikan dengan cara yang sama, menyimpan hal yang sama, dan
| ditampilkan dengan cara yang sama. Empat tabel kembar berarti empat salinan
| aturan yang sama, dan yang tercatat di sini adalah jejak uang orang: pada
| hari salah satu salinannya tertinggal, yang hilang bukan baris basis data,
| melainkan jawaban atas "kenapa nominalnya berubah".
|
| KENAPA SNAPSHOT, BUKAN HANYA DAFTAR PERUBAHAN.
|
| Daftar perubahan menjawab "apa yang berubah", dan itu yang dibaca orang.
| Tetapi ia hanya bisa dipercaya sejauh kode yang menghitungnya waktu itu.
| Snapshot menjawab "dokumennya berbunyi apa" -- apa adanya, tanpa tafsir --
| dan masih bisa dibaca bertahun-tahun kemudian walau formnya sudah berubah
| bentuk. Keduanya disimpan: snapshot sebagai kebenaran, daftar perubahan
| sebagai tampilan yang murah dan tidak ikut bergeser.
|
| KENAPA RANTAI APPROVAL IKUT DIARSIPKAN DI SINI.
|
| Dokumen yang dikembalikan akan diajukan ulang dari step 1, jadi rantai
| approval-nya dibuat ulang. Tabel approval sengaja hanya memuat SATU putaran
| -- yang sedang hidup -- supaya seluruh pembacaan approval yang sudah jalan
| (daftar tugas, notifikasi, email, cetakan) tidak perlu tahu apa-apa soal
| revisi. Yang ditanggalkan dari sana disimpan di approvals_before, lengkap
| dengan tanda tangan, catatan, dan waktunya; tanpa itu, tanda tangan yang
| pernah diberikan orang akan lenyap tanpa bekas.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fund_request_revisions')) {
            return;
        }

        Schema::create('fund_request_revisions', function (Blueprint $table): void {
            $table->id();

            /* Dokumennya. Tanpa foreign key, mengikuti kolom polimorfik lain. */
            $table->string('document_type');
            $table->unsignedBigInteger('document_id');

            $table->unsignedInteger('revision_number');

            /*
            | Pengembaliannya.
            |
            | returned_from_status dicatat karena ada dua pintu yang berbeda
            | asalnya: approver pada IN PROGRESS, dan Finance pada RECEIVED.
            | Keduanya berakhir sama di layar pemohon, tetapi yang membaca
            | riwayatnya enam bulan kemudian perlu tahu berkasnya sudah
            | sejauh mana ketika dikembalikan.
            */
            $table->unsignedBigInteger('returned_by');
            $table->timestamp('returned_at');
            $table->string('returned_from_status', 32);
            $table->unsignedInteger('returned_step_order')->nullable();
            $table->string('returned_step_label')->nullable();
            $table->text('return_reason');

            $table->json('snapshot_before');
            $table->json('approvals_before')->nullable();

            /*
            | Saat pemohon pertama kali menyimpan perubahannya.
            |
            | Terpisah dari resubmitted_at: ada jeda antara "sudah dikerjakan"
            | dan "sudah diajukan ulang", dan approver yang menunggu berhak
            | tahu dokumennya sedang ditangani, bukan terdiam.
            */
            $table->timestamp('revised_at')->nullable();

            $table->unsignedBigInteger('resubmitted_by')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
            $table->json('snapshot_after')->nullable();
            $table->json('changes')->nullable();

            $table->timestamps();

            $table->index(['document_type', 'document_id'], 'fr_revisions_document_index');

            /*
            | Nomor revisinya tidak boleh kembar dalam satu dokumen.
            |
            | Nomornya dihitung dari revision_count dokumen, di dalam baris
            | yang sudah dikunci. Penjaga ini untuk hari ketika ada jalan
            | kedua yang lupa mengunci.
            */
            $table->unique(
                ['document_type', 'document_id', 'revision_number'],
                'fr_revisions_document_number_unique',
            );
        });

        DB::statement(
            "COMMENT ON TABLE fund_request_revisions IS "
            . "'Riwayat revisi FPU, Realisasi, Claim, dan Perdin: satu baris per "
            . "pengembalian ke pemohon. Baris yang resubmitted_at-nya masih NULL "
            . "berarti dokumennya sedang menunggu direvisi.'",
        );

        DB::statement(
            "COMMENT ON COLUMN fund_request_revisions.approvals_before IS "
            . "'Rantai approval putaran sebelumnya, diarsipkan sebelum barisnya "
            . "dihapus dari tabel approval. Tabel approval hanya memuat putaran "
            . "yang sedang hidup.'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_request_revisions');
    }
};
