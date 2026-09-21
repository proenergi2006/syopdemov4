<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Perdin naik jadi dokumen berpersetujuan, dan FPU menautkan diri padanya
|--------------------------------------------------------------------------
| Tiga perubahan sekaligus, karena ketiganya satu alur:
|
|   1. business_trips        dapat status dan jejak pengajuannya
|   2. business_trip_approvals   snapshot langkah persetujuannya
|   3. cash_advances         dapat business_trip_id
|
| ITINERARY TIDAK IKUT PUNYA PERSETUJUAN. Ia bagian dari formulir perdin,
| bukan dokumen tersendiri -- disetujui bersama induknya, sekali.
|
| Kenapa snapshot, bukan membaca master approval flow saat menampilkan:
| flow bisa diubah kapan saja. Dokumen yang sudah berjalan harus tetap
| memakai susunan penyetuju yang berlaku saat ia diajukan, kalau tidak
| riwayat persetujuannya berubah sendiri setelah ditandatangani. Bentuknya
| ditiru persis dari cash_advance_approvals dan claim_approvals.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_trips', function (Blueprint $table): void {
            /*
            | DRAFT -> IN PROGRESS -> APPROVED / REJECTED, dan CANCELLED dari
            | mana saja sebelum selesai. Nilainya ditulis sama persis dengan
            | Claim supaya satu kosakata dipakai di seluruh aplikasi.
            */
            $table->string('status', 20)->default('DRAFT');

            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();

            /*
            | Tanda tangan pemohon dibekukan saat mengajukan, bukan diambil dari
            | akunnya saat dicetak -- kalau diambil saat cetak, mengganti berkas
            | tanda tangan akan mengubah dokumen yang sudah disetujui.
            */
            $table->unsignedBigInteger('requester_signed_by')->nullable();
            $table->string('requester_signature_path')->nullable();
            $table->timestamp('requester_signed_at')->nullable();

            $table->unsignedBigInteger('final_approved_by')->nullable();
            $table->timestamp('final_approved_at')->nullable();

            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_notes')->nullable();

            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_notes')->nullable();

            $table->index(['status', 'user_id'], 'business_trips_status_user_index');
        });

        Schema::create('business_trip_approvals', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('business_trip_id');

            /*
            | Asal langkahnya disimpan untuk penelusuran, dan boleh kosong:
            | flow atau step-nya bisa dihapus setelah dokumen berjalan, dan
            | dokumen yang sudah berjalan tidak boleh ikut hilang karenanya.
            */
            $table->unsignedBigInteger('approval_flow_id')->nullable();
            $table->unsignedBigInteger('approval_flow_step_id')->nullable();

            $table->integer('step_order');
            $table->string('label')->nullable();

            $table->string('approver_type', 10);
            $table->unsignedBigInteger('approver_id');

            /*
            | Nama penyetuju ikut disalin. Orang berganti nama dan role berganti
            | sebutan; riwayat persetujuan harus tetap berbunyi seperti saat
            | ditandatangani.
            */
            $table->string('approver_name_snapshot')->nullable();

            $table->string('approval_mode', 10);
            $table->string('status', 20);

            $table->string('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['business_trip_id', 'step_order'], 'business_trip_approvals_order_index');
            $table->index(['approver_type', 'approver_id', 'status'], 'business_trip_approvals_approver_index');
        });

        Schema::table('cash_advances', function (Blueprint $table): void {
            /*
            | Tautan ke perdin. Boleh kosong: hanya FPU berketerangan transaksi
            | perdin yang menautkan diri, dan seluruh FPU yang sudah ada
            | dibuat sebelum tautan ini ada.
            |
            | TANPA indeks unik. Satu perdin boleh punya beberapa FPU sepanjang
            | waktu -- yang ditolak, yang dibatalkan, lalu yang berjalan. Yang
            | dilarang hanya dua FPU HIDUP sekaligus, dan "hidup" bergantung
            | status yang berubah-ubah; aturan seperti itu tempatnya di
            | penjagaan saat mengajukan, bukan di indeks basis data.
            */
            $table->unsignedBigInteger('business_trip_id')->nullable();

            $table->index('business_trip_id', 'cash_advances_business_trip_index');
        });

        $keterangan = [
            'business_trips.status' => 'DRAFT, IN PROGRESS, APPROVED, REJECTED, atau CANCELLED.',
            'business_trips.requester_signature_path' => 'Tanda tangan pemohon, dibekukan saat mengajukan.',
            'business_trip_approvals.approver_name_snapshot' => 'Salinan nama penyetuju saat dokumen diajukan.',
            'cash_advances.business_trip_id' => 'Perdin yang mendasari FPU ini. Hanya terisi pada FPU berketerangan transaksi perdin.',
        ];

        foreach ($keterangan as $kolom => $teks) {
            DB::statement("COMMENT ON COLUMN {$kolom} IS '" . str_replace("'", "''", $teks) . "'");
        }

        /*
        | Perdin yang sudah terlanjur dibuat sebelum ada persetujuan dianggap
        | sudah selesai, bukan draft. Menjadikannya draft berarti memaksa
        | pemiliknya mengajukan ulang perjalanan yang barangkali sudah berjalan.
        */
        DB::table('business_trips')->update(['status' => 'APPROVED']);
    }

    public function down(): void
    {
        Schema::table('cash_advances', function (Blueprint $table): void {
            $table->dropIndex('cash_advances_business_trip_index');
            $table->dropColumn('business_trip_id');
        });

        Schema::dropIfExists('business_trip_approvals');

        Schema::table('business_trips', function (Blueprint $table): void {
            $table->dropIndex('business_trips_status_user_index');

            $table->dropColumn([
                'status',
                'submitted_by',
                'submitted_at',
                'requester_signed_by',
                'requester_signature_path',
                'requester_signed_at',
                'final_approved_by',
                'final_approved_at',
                'rejected_by',
                'rejected_at',
                'rejection_notes',
                'cancelled_by',
                'cancelled_at',
                'cancellation_notes',
            ]);
        });
    }
};
