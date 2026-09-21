<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Claim
    |--------------------------------------------------------------------------
    | Field mengikuti form Claim manual:
    |   NO. Advance, Tanggal, Perihal, lalu tabel item berisi
    |   Date, Decriptions, dan Amount.
    |
    | "NO. Advance" pada kertas adalah nomor dokumen Claim itu sendiri, bukan
    | rujukan ke FPU -- labelnya saja yang belum ikut berubah. Karena itu tidak
    | ada kolom cash_advance_id di sini: Claim berdiri sendiri, dipakai untuk
    | penggantian uang yang sudah lebih dulu dikeluarkan pemohon, sehingga
    | memang tidak melewati pencairan maupun realisasi seperti FPU.
    |
    | Penamaan kolom memakai bahasa Inggris, mengikuti modul FPU.
    |
    | Struktur tabel mengikuti pola FPU (header + items + attachments +
    | approvals) supaya mesin approval, notifikasi, dan pola lampiran yang
    | sudah ada dapat dipakai ulang tanpa penyesuaian. Kolom untuk tahap yang
    | belum dibangun ikut dibuat sekarang supaya penambahannya nanti tidak
    | perlu mengubah struktur tabel lagi.
    |--------------------------------------------------------------------------
    */
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();

            /*
            | Format: {cabang}/{department}/{tahun 2 angka}/{bulan romawi}/{urut 3 digit}
            | Contoh : JKT/POOL/26/III/019 -- nomor urut direset tiap tahun.
            */
            $table->string('claim_number')->unique();

            $table->date('date');

            /*
            | "Perihal" pada form. Dibuat text karena isinya kalimat, bukan
            | sekadar label pendek.
            */
            $table->text('subject');

            /*
            | Cabang & department dipakai mesin approval untuk memilih flow dan
            | untuk pembatasan visibility -- mengikuti pola FPU.
            |
            | branch bertipe string agar konsisten dengan cash_advances dan
            | purchase_requests yang juga menyimpan id cabang sebagai teks.
            */
            $table->string('branch')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            /*
            | Keterangan transaksi dipakai matriks approval flow untuk memilih
            | jalur yang tepat, sama seperti FPU.
            */
            $table->unsignedBigInteger('transaction_category_id')->nullable();

            $table->decimal('total_amount', 18, 2)->default(0);

            // Bagian "Note :" pada bagian bawah form.
            $table->text('notes')->nullable();

            $table->string('status', 30)->default('DRAFT');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();

            /*
            | Tanda tangan pemohon, dipakai saat submit dan pada cetakan --
            | mengikuti pola FPU.
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
            | Penggantian uang oleh Finance. Sejajar dengan pencairan pada FPU,
            | tetapi arahnya terbalik: di sini uangnya dikembalikan kepada
            | pemohon yang sudah lebih dulu membayar.
            */
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('payment_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('date');
            $table->index('department_id');
            $table->index('transaction_category_id');
        });

        Schema::create('claim_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('claim_id');

            /*
            | Kolom "Date" pada form. Wajib, berbeda dengan cash_advance_items
            | yang dibuat nullable lebih dulu lalu diwajibkan di aplikasi --
            | tabel baru langsung memakai aturan yang berlaku sekarang.
            */
            $table->date('date');

            // Kolom "Decriptions" pada form
            $table->text('description');

            // Kolom "Amount" pada form
            $table->decimal('amount', 18, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('claim_id')
                ->references('id')
                ->on('claims')
                ->cascadeOnDelete();

            $table->index('claim_id');
        });

        Schema::create('claim_attachments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('claim_id');

            /*
            | Bukti melekat pada baris rincian, bukan pada dokumen. Nullable
            | karena bukti pembayaran dari Finance memang milik dokumen dan
            | tidak menunjuk baris mana pun.
            |
            | Cascade di sini hanya jaring pengaman: claim_items memakai soft
            | delete, sehingga penghapusan baris tidak pernah benar-benar
            | memicu cascade -- barisnya dihapus eksplisit oleh controller.
            */
            $table->unsignedBigInteger('claim_item_id')->nullable();

            /*
            | REQUEST : bukti pengeluaran yang diunggah pemohon.
            | PAYMENT : bukti transfer yang dilampirkan Finance saat mengganti.
            */
            $table->string('attachment_type', 20)->default('REQUEST');

            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('filepath');

            $table->timestamps();

            $table->foreign('claim_id')
                ->references('id')
                ->on('claims')
                ->cascadeOnDelete();

            $table->foreign('claim_item_id')
                ->references('id')
                ->on('claim_items')
                ->cascadeOnDelete();

            $table->index('claim_id');
            $table->index('claim_item_id');
        });

        Schema::create('claim_approvals', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('claim_id');

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

            $table->foreign('claim_id')
                ->references('id')
                ->on('claims')
                ->cascadeOnDelete();

            $table->index(['claim_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_approvals');
        Schema::dropIfExists('claim_attachments');
        Schema::dropIfExists('claim_items');
        Schema::dropIfExists('claims');
    }
};
