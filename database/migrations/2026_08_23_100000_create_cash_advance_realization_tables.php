<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Realisasi FPU
|--------------------------------------------------------------------------
| Dokumen terpisah yang mempertanggungjawabkan uang yang sudah dicairkan.
| Hanya boleh dibuat dari FPU berstatus DISBURSED.
|
| Alur dokumen:
|
|   DRAFT -> IN PROGRESS -> APPROVED -> SETTLED
|                        \-> REJECTED
|             (APPROVED) \-> CANCELLED
|
| SETTLED dipisahkan dari APPROVED karena approval hanya menyatakan angka
| realisasinya benar, sedangkan penyelesaian menyatakan selisihnya sudah
| beres -- sisa sudah dikembalikan, atau kekurangan sudah dibayarkan.
| Tanpa pemisahan itu, FPU yang uangnya belum kembali tidak terlihat.
|
| Penamaan kolom memakai bahasa Inggris, mengikuti seluruh tabel baru.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_advance_realizations', function (Blueprint $table) {
            $table->id();

            /*
            | Format: RLS/{cabang}/{department}/{tahun 2 angka}/{bulan romawi}/{urut 3 digit}
            | Contoh : RLS/HO/GA/26/VIII/001
            |
            | Deret terpisah dari FPU, per cabang + department, reset tiap tahun.
            */
            $table->string('realization_number')->unique();

            $table->unsignedBigInteger('cash_advance_id');

            $table->date('date');
            $table->text('notes')->nullable();

            /*
            | Cabang, department, dan keterangan transaksi disalin dari FPU
            | induk. Ketiganya dipakai mesin approval untuk memilih flow, dan
            | disimpan sebagai snapshot supaya dokumen tetap terbaca utuh
            | walau master di kemudian hari berubah.
            */
            $table->string('branch')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('transaction_category_id')->nullable();

            /*
            | total_advance_amount  = nilai FPU yang dicairkan (snapshot)
            | difference_amount     = total_advance_amount - total_realization_amount
            |                         positif -> sisa dikembalikan pemohon
            |                         negatif -> kekurangan dibayarkan perusahaan
            */
            $table->decimal('total_advance_amount', 18, 2)->default(0);
            $table->decimal('total_realization_amount', 18, 2)->default(0);
            $table->decimal('difference_amount', 18, 2)->default(0);

            // NONE / RETURN / REIMBURSE
            $table->string('difference_type', 20)->default('NONE');

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

            /*
            | Penyelesaian selisih oleh Finance.
            */
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->text('settlement_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('cash_advance_id')
                ->references('id')
                ->on('cash_advances')
                ->cascadeOnDelete();

            $table->foreign('transaction_category_id')
                ->references('id')
                ->on('fund_request_transaction_categories')
                ->nullOnDelete();

            $table->index('status');
            $table->index('date');
            $table->index('cash_advance_id');
        });

        /*
        | Satu FPU hanya boleh punya satu realisasi yang masih hidup. Dokumen
        | yang sudah dihapus dikecualikan supaya FPU-nya bisa direalisasi ulang.
        */
        DB::statement(
            'CREATE UNIQUE INDEX cash_advance_realization_unique_per_advance
             ON cash_advance_realizations (cash_advance_id)
             WHERE deleted_at IS NULL'
        );

        Schema::create('cash_advance_realization_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cash_advance_realization_id');

            /*
            | Baris asal pada FPU. NULL berarti pengeluaran di luar rencana
            | yang tidak ada padanannya di FPU.
            */
            $table->unsignedBigInteger('cash_advance_item_id')->nullable();

            $table->date('date')->nullable();
            $table->text('description');

            /*
            | advance_amount adalah snapshot nominal pengajuan pada baris FPU,
            | supaya perbandingan per baris tetap benar walau FPU-nya diubah.
            */
            $table->decimal('advance_amount', 18, 2)->default(0);
            $table->decimal('realization_amount', 18, 2)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('cash_advance_realization_id', 'car_items_realization_foreign')
                ->references('id')
                ->on('cash_advance_realizations')
                ->cascadeOnDelete();

            $table->foreign('cash_advance_item_id', 'car_items_advance_item_foreign')
                ->references('id')
                ->on('cash_advance_items')
                ->nullOnDelete();
        });

        Schema::create('cash_advance_realization_attachments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cash_advance_realization_id');

            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('filepath');

            $table->timestamps();

            $table->foreign('cash_advance_realization_id', 'car_attachments_realization_foreign')
                ->references('id')
                ->on('cash_advance_realizations')
                ->cascadeOnDelete();
        });

        Schema::create('cash_advance_realization_approvals', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cash_advance_realization_id');

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

            $table->foreign('cash_advance_realization_id', 'car_approvals_realization_foreign')
                ->references('id')
                ->on('cash_advance_realizations')
                ->cascadeOnDelete();

            $table->index(
                ['cash_advance_realization_id', 'step_order'],
                'car_approvals_realization_step_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advance_realization_approvals');
        Schema::dropIfExists('cash_advance_realization_attachments');
        Schema::dropIfExists('cash_advance_realization_items');

        DB::statement('DROP INDEX IF EXISTS cash_advance_realization_unique_per_advance');

        Schema::dropIfExists('cash_advance_realizations');
    }
};
