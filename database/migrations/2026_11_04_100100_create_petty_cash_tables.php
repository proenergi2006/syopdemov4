<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Petty Cash
|--------------------------------------------------------------------------
| Formulir yang dibuat ADH untuk membayar beberapa claim kecil sekaligus dari
| kas kecil cabangnya.
|
| Bedanya dengan modul pengajuan dana yang lain: dokumen ini TIDAK punya
| rincian yang diketik sendiri. Isinya adalah claim-claim yang sudah disetujui
| dan sudah diterima, dipilih dari daftar. Jadi yang ada di sini bukan
| petty_cash_items melainkan petty_cash_claims -- pasangan, bukan rincian.
|
| Siklusnya:
|
|   DRAFT -> IN PROGRESS -> APPROVED -> DISBURSED
|                                 \
|                                  -> CANCELLED   (hanya sebelum dicairkan)
|
| PLAFON TERPOTONG SAAT DICAIRKAN, bukan saat disetujui penuh. Persetujuan
| adalah izin untuk mengeluarkan uang; yang mengurangi kas adalah uangnya
| benar-benar keluar. Di saat itu pula claim-claim di dalamnya menjadi PAID --
| satu kejadian, satu transaksi, tidak ada keadaan di mana plafon sudah
| terpotong tetapi claim-nya belum terbayar.
|
| Nominalnya dibekukan per claim saat formulir dibuat. Claim yang sudah
| diterima memang tidak berubah nominal lagi, tetapi membekukannya membuat
| cetakan dan buku mutasi tetap bisa dipertanggungjawabkan tanpa bergantung
| pada keadaan dokumen lain di kemudian hari.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cashes', function (Blueprint $table): void {
            $table->id();

            /*
            | Format : PTC/{cabang}/{department}/{tahun 2 angka}/{bulan romawi}/{urut 3 digit}
            | Contoh : PTC/SLW/FIN/26/XI/004
            |
            | Mengikuti pola CLM dan FPU, termasuk nomor sementara selama draft.
            */
            $table->string('petty_cash_number')->unique();

            $table->date('date');

            $table->text('subject');

            /*
            | Cabang bertipe string mengikuti claims, cash_advances, dan
            | purchase_requests. Bukan pilihan yang bagus, tetapi satu modul
            | yang menyimpannya berbeda dari empat modul lain akan membuat
            | setiap query gabungan jadi perangkap.
            */
            $table->string('branch')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            /*
            | Plafon yang dipakai. Disimpan supaya mutasi dan dokumen saling
            | menunjuk, dan supaya pemindahan kas kecil ke department lain
            | tidak mengubah arti dokumen yang sudah lewat.
            */
            $table->unsignedBigInteger('petty_cash_ceiling_id')->nullable();

            $table->decimal('total_amount', 18, 2)->default(0);

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

            /*
            | Pencairan: uang tunai diserahkan ADH kepada para pemohon. Nama
            | kolomnya sengaja sama dengan cash_advances, karena artinya pun
            | sama -- uang keluar dari kas.
            */
            $table->unsignedBigInteger('disbursed_by')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->text('disbursement_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('petty_cash_ceiling_id')
                ->references('id')
                ->on('petty_cash_ceilings')
                ->nullOnDelete();

            $table->index('status');
            $table->index('date');
            $table->index('branch');
            $table->index('department_id');
        });

        Schema::create('petty_cash_claims', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('petty_cash_id');
            $table->unsignedBigInteger('claim_id');

            /*
            | Nominal claim pada saat dimasukkan ke formulir ini. Yang dipakai
            | menjumlahkan total dan memotong plafon adalah angka ini, bukan
            | claims.total_amount -- supaya dokumen yang sudah dicairkan tidak
            | pernah berubah arti karena dokumen lain disentuh.
            */
            $table->decimal('amount', 18, 2);

            /*
            | Saat formulirnya dibatalkan, ditolak, atau dihapus, pasangannya
            | dilepas -- ditandai di sini, bukan dihapus. Claim-nya bebas
            | dipakai formulir berikutnya, sementara jejak bahwa ia pernah
            | masuk formulir yang gagal tetap ada.
            |
            | Kolom ini yang memungkinkan aturan "satu claim satu formulir
            | hidup" dijaga oleh indeks di bawah. Keadaan formulirnya sendiri
            | tidak bisa dipakai: predikat indeks partial Postgres tidak boleh
            | berisi subquery, jadi penandanya harus ada di baris ini.
            */
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            $table->foreign('petty_cash_id')
                ->references('id')
                ->on('petty_cashes')
                ->cascadeOnDelete();

            $table->foreign('claim_id')
                ->references('id')
                ->on('claims');

            $table->unique(['petty_cash_id', 'claim_id']);
            $table->index('claim_id');
        });

        /*
        | Satu claim tidak boleh berada di dua formulir yang masih hidup.
        |
        | Diperiksa juga di layanannya -- dengan pesan yang menyebut nomor
        | formulir yang sudah memakainya -- tetapi aturan sepenting ini tidak
        | boleh hanya bergantung pada kode. Dua ADH yang menekan simpan pada
        | detik yang sama tidak akan saling terlihat di tingkat PHP, dan
        | akibatnya satu claim terbayar dua kali dari kas kecil.
        */
        DB::statement(
            'CREATE UNIQUE INDEX petty_cash_claims_claim_active_unique
             ON petty_cash_claims (claim_id)
             WHERE released_at IS NULL'
        );

        Schema::create('petty_cash_approvals', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('petty_cash_id');

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

            $table->foreign('petty_cash_id')
                ->references('id')
                ->on('petty_cashes')
                ->cascadeOnDelete();

            $table->index(['petty_cash_id', 'step_order']);
        });

        Schema::create('petty_cash_attachments', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('petty_cash_id');

            /*
            | REQUEST      lampiran yang disertakan ADH saat mengajukan
            | DISBURSEMENT bukti serah terima tunai kepada para pemohon
            */
            $table->string('attachment_type', 20)->default('REQUEST');

            $table->string('filename');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('filepath');

            $table->timestamps();

            $table->foreign('petty_cash_id')
                ->references('id')
                ->on('petty_cashes')
                ->cascadeOnDelete();

            $table->index('petty_cash_id');
        });

        DB::statement("COMMENT ON COLUMN petty_cash_claims.amount IS 'Nominal claim saat dimasukkan ke formulir. Dasar total dan pemotongan plafon'");
        DB::statement("COMMENT ON COLUMN petty_cash_claims.released_at IS 'Terisi bila formulirnya batal/ditolak, sehingga claim bebas dipakai formulir lain'");
        DB::statement("COMMENT ON COLUMN petty_cashes.status IS 'DRAFT, IN PROGRESS, APPROVED, DISBURSED, REJECTED, CANCELLED'");
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_attachments');
        Schema::dropIfExists('petty_cash_approvals');
        Schema::dropIfExists('petty_cash_claims');
        Schema::dropIfExists('petty_cashes');
    }
};
