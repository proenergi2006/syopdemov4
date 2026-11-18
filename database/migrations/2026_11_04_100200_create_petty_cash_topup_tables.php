<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Pengisian kembali plafon petty cash
|--------------------------------------------------------------------------
| Kas kecil yang hanya berkurang akan habis. Dokumen inilah yang memulihkannya
| ke plafon semula: diajukan ADH, disetujui Kacab, lalu disetujui Finance di
| HO. Sesudah itu uangnya dikirim dan buku mutasi mencatat satu baris TOPUP.
|
| NOMINALNYA DIHITUNG SISTEM, bukan diketik: plafon dikurangi saldo saat
| diajukan. Kalau boleh diketik, saldo dan plafon akan saling melenceng dan
| tidak ada lagi arti dari kata "plafon".
|
| ANGKANYA DIBEKUKAN SAAT DIAJUKAN, dan tidak dihitung ulang saat disetujui.
| Ini keputusan yang perlu dibaca dengan sadar:
|
|   Kalau ADH mencairkan petty cash lain selagi top up menunggu persetujuan,
|   saldo turun lebih jauh, dan uang yang akhirnya dikirim -- sebesar angka
|   yang disetujui -- tidak lagi mengembalikan saldo tepat ke plafon.
|
| Dibiarkan begitu, karena buku mutasi harus mencerminkan uang yang benar
| benar berpindah. Menghitung ulang saat persetujuan akan membuat Kacab dan
| Finance menyetujui satu angka sementara yang tercatat angka lain, dan itu
| jauh lebih berbahaya daripada saldo yang kurang sedikit.
|
| Yang dicegah justru penyebab kebingungannya: hanya boleh ada SATU top up
| berjalan per cabang. Lihat indeks di bawah.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_topups', function (Blueprint $table): void {
            $table->id();

            /*
            | Format : PTCT/{cabang}/{department}/{tahun 2 angka}/{bulan romawi}/{urut 3 digit}
            | Contoh : PTCT/SLW/FIN/26/XI/002
            */
            $table->string('topup_number')->unique();

            $table->date('date');

            $table->text('subject')->nullable();

            $table->string('branch')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            $table->unsignedBigInteger('petty_cash_ceiling_id');

            /*
            | Ketiganya dibekukan saat diajukan, dan ketiganya perlu disimpan
            | terpisah. ceiling_amount bisa diubah di master sesudah dokumen
            | ini lewat; tanpa salinannya, cetakan dan riwayat akan menyebut
            | plafon yang bukan plafon saat itu.
            |
            |   amount = ceiling_amount - balance_before
            */
            $table->decimal('ceiling_amount', 18, 2);
            $table->decimal('balance_before', 18, 2);
            $table->decimal('amount', 18, 2);

            $table->text('notes')->nullable();

            $table->string('status', 30)->default('DRAFT');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();

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

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('petty_cash_ceiling_id')
                ->references('id')
                ->on('petty_cash_ceilings');

            $table->index('status');
            $table->index('date');
            $table->index('branch');
        });

        /*
        | Satu top up berjalan per plafon.
        |
        | Dua top up sekaligus berarti plafon dipulihkan dua kali dari satu
        | kekurangan yang sama -- uang lebih yang tidak akan ketahuan dari
        | saldo, karena saldonya memang naik seperti yang diharapkan.
        |
        | Yang masih berjalan = belum selesai nasibnya. Yang sudah disetujui
        | penuh, ditolak, atau dibatalkan tidak lagi menghalangi.
        */
        DB::statement(
            "CREATE UNIQUE INDEX petty_cash_topups_running_unique
             ON petty_cash_topups (petty_cash_ceiling_id)
             WHERE deleted_at IS NULL
               AND status IN ('DRAFT', 'IN PROGRESS')"
        );

        DB::statement("COMMENT ON COLUMN petty_cash_topups.amount IS 'Dihitung sistem: ceiling_amount - balance_before, dibekukan saat diajukan'");
        DB::statement("COMMENT ON COLUMN petty_cash_topups.balance_before IS 'Saldo kas kecil saat top up diajukan'");

        Schema::create('petty_cash_topup_approvals', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('petty_cash_topup_id');

            $table->unsignedBigInteger('approval_flow_id')->nullable();
            $table->unsignedBigInteger('approval_flow_step_id')->nullable();

            $table->integer('step_order')->default(1);
            $table->string('label')->nullable();

            $table->string('approver_type', 20);
            $table->unsignedBigInteger('approver_id');
            $table->string('approver_name_snapshot')->nullable();

            $table->string('approval_mode', 10)->default('ANY');
            $table->string('status', 20)->default('PENDING');

            $table->string('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->foreign('petty_cash_topup_id')
                ->references('id')
                ->on('petty_cash_topups')
                ->cascadeOnDelete();

            $table->index(['petty_cash_topup_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_topup_approvals');
        Schema::dropIfExists('petty_cash_topups');
    }
};
