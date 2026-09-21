<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | FPU (Form Pengajuan Uang) / Cash Advance
    |--------------------------------------------------------------------------
    | Field mengikuti form FPU manual:
    |   NO. Advance, Tanggal, Perihal, lalu tabel item berisi
    |   Date, Descriptions, dan Amount. Kolom "No Lamp" sengaja tidak dibuat.
    |
    | Penamaan kolom memakai bahasa Inggris untuk seluruh modul baru
    | (FPU dan Claim), berbeda dari modul lama yang bercampur.
    |
    | Struktur tabel mengikuti pola Purchase Requisition (header + items +
    | approvals + attachments) supaya mesin approval, notifikasi, dan pola
    | lampiran yang sudah ada dapat dipakai ulang tanpa penyesuaian.
    |--------------------------------------------------------------------------
    */
    public function up(): void
    {
        Schema::create('cash_advances', function (Blueprint $table) {
            $table->id();

            /*
            | Format: {cabang}/{department}/{tahun 2 angka}/{bulan romawi}/{urut 3 digit}
            | Contoh : JKT/PROC/26/VIII/001 -- nomor urut direset tiap tahun.
            */
            $table->string('advance_number')->unique();

            $table->date('date');

            /*
            | "Perihal" pada form. Dibuat text karena isinya kalimat, bukan
            | sekadar label pendek.
            */
            $table->text('subject');

            /*
            | Cabang & department dipakai mesin approval untuk memilih flow dan
            | untuk pembatasan visibility -- mengikuti pola PR.
            |
            | branch bertipe string agar konsisten dengan purchase_requests
            | yang juga menyimpan id cabang sebagai teks.
            */
            $table->string('branch')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            $table->decimal('total_amount', 18, 2)->default(0);

            /*
            | Bagian "Note :" pada bagian bawah form.
            */
            $table->text('notes')->nullable();

            $table->string('status', 30)->default('DRAFT');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();

            /*
            | Tanda tangan pemohon, dipakai saat submit dan pada cetakan --
            | mengikuti pola PR.
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

            /*
            | Pencairan oleh Finance. Dipisahkan dari APPROVED supaya sistem
            | tahu bedanya "sudah disetujui" dengan "uang sudah keluar".
            | Realisasi hanya boleh dibuat setelah kolom ini terisi.
            */
            $table->unsignedBigInteger('disbursed_by')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->text('disbursement_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('date');
        });

        Schema::create('cash_advance_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cash_advance_id');

            /*
            | Kolom "Date" pada form. Nullable karena pada form manual satu
            | tanggal kerap ditulis sekali untuk beberapa baris sekaligus.
            */
            $table->date('date')->nullable();

            // Kolom "Decriptions" pada form
            $table->text('description');

            // Kolom "Amount" pada form
            $table->decimal('amount', 18, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('cash_advance_id')
                ->references('id')
                ->on('cash_advances')
                ->cascadeOnDelete();
        });

        Schema::create('cash_advance_attachments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cash_advance_id');

            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('filepath');

            $table->timestamps();

            $table->foreign('cash_advance_id')
                ->references('id')
                ->on('cash_advances')
                ->cascadeOnDelete();
        });

        Schema::create('cash_advance_approvals', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('cash_advance_id');

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

            $table->foreign('cash_advance_id')
                ->references('id')
                ->on('cash_advances')
                ->cascadeOnDelete();

            $table->index(['cash_advance_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_advance_approvals');
        Schema::dropIfExists('cash_advance_attachments');
        Schema::dropIfExists('cash_advance_items');
        Schema::dropIfExists('cash_advances');
    }
};
