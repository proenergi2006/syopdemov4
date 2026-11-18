<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Bank Disbursement Voucher (BDV)
|--------------------------------------------------------------------------
| Dokumen pencairan lewat bank. Ia tidak berdiri sendiri: tiap BDV selalu
| mengangkat SATU dokumen sumber yang sudah disetujui dan berkasnya sudah
| diterima -- untuk sekarang FPU atau Claim.
|
| Kolom sumbernya disimpan sebagai pasangan (document_type, source_id), bukan
| sebagai foreign key ke satu tabel tertentu, karena jenis dokumen yang boleh
| masuk BDV memang dirancang untuk bertambah. Lihat config/bdv.php.
|
| NOMOR DAN NILAI DOKUMEN SUMBER IKUT DISALIN ke source_number dan
| total_amount. Itu disengaja, bukan duplikasi yang terlewat: BDV adalah bukti
| pencairan, dan bukti harus mencatat apa yang tertulis pada saat ia dibuat.
| Kalau nilainya kelak direvisi di dokumen sumber, BDV yang sudah terbit tidak
| boleh ikut berubah diam-diam.
|
| Kolom daur hidup (submitted/approved/rejected/cancelled) disiapkan sekarang
| mengikuti bentuk yang sama persis dengan FPU, Claim, dan Realisasi, karena
| BDV akan memakai approval flow yang sama. Menyiapkannya sekarang menghemat
| satu migration lagi nanti, dan tidak membebani apa pun selagi kosong.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_disbursement_vouchers', function (Blueprint $table) {
            $table->id();

            /*
            | Nomor baru terbit saat diajukan, seperti modul lain. Selama masih
            | draft nilainya null, dan null tidak saling bentrok pada unique
            | index Postgres.
            */
            $table->string('bdv_number')->nullable()->unique();

            $table->date('date');

            /*
            |------------------------------------------------------------------
            | Dokumen sumber
            |------------------------------------------------------------------
            | document_type berisi kode dari config/bdv.php -- 'FPU' atau
            | 'CLAIM' untuk sekarang.
            */
            $table->string('document_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->string('source_number');

            /*
            | Disalin dari dokumen sumber saat BDV dibuat. Dipakai untuk
            | menyaring daftar tanpa harus menyambung ke tabel sumber yang
            | berbeda-beda.
            */
            $table->string('branch')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('transaction_category_id')->nullable();
            $table->decimal('total_amount', 18, 2)->default(0);

            $table->text('notes')->nullable();

            $table->string('status')->default('DRAFT');

            /*
            |------------------------------------------------------------------
            | Daur hidup
            |------------------------------------------------------------------
            */
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();

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

            /*
            |------------------------------------------------------------------
            | Index
            |------------------------------------------------------------------
            | Dibuat sejak awal, bukan menunggu lambat lebih dulu. Postgres
            | tidak membuatkannya sendiri, dan kolom-kolom inilah yang dipakai
            | menyaring daftar.
            */
            $table->index('status');
            $table->index('date');
            $table->index('branch');
            $table->index('department_id');
            $table->index('created_by');
            $table->index(['document_type', 'source_id']);
        });

        /*
        |----------------------------------------------------------------------
        | Satu dokumen sumber hanya boleh diangkat satu kali
        |----------------------------------------------------------------------
        | Dijaga basis data, bukan hanya pemeriksaan di layar: dua orang yang
        | menekan Simpan pada saat hampir bersamaan untuk FPU yang sama akan
        | lolos dari pemeriksaan di kode, tetapi tidak dari index ini.
        |
        | Dibatasi pada BDV yang belum dibatalkan dan belum dihapus, supaya
        | dokumen yang BDV-nya batal bisa diangkat ulang.
        */
        DB::statement("
            CREATE UNIQUE INDEX bank_disbursement_vouchers_source_unique
            ON bank_disbursement_vouchers (document_type, source_id)
            WHERE deleted_at IS NULL AND status <> 'CANCELLED'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bank_disbursement_vouchers_source_unique');

        Schema::dropIfExists('bank_disbursement_vouchers');
    }
};
