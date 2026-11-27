<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Rantai approval BDV
|--------------------------------------------------------------------------
| Bentuknya sengaja dibuat sama persis dengan cash_advance_approvals dan
| claim_approvals. Bukan demi kerapian: layar approval, notifikasi, dan
| cetakan di seluruh aplikasi ini sudah membaca bentuk itu, dan satu kolom
| yang namanya berbeda berarti satu cabang kode baru di setiap tempat yang
| menyentuhnya.
|
| SNAPSHOT, BUKAN ACUAN.
|
| Baris di sini adalah salinan beku dari approval_flow_steps pada saat
| dokumennya diajukan -- termasuk nama approver-nya. Matriks approval boleh
| berubah kapan saja sesudahnya, dan dokumen yang sedang berjalan tidak ikut
| berubah. Tanpa pembekuan itu, mengubah matriks berarti memindahkan dokumen
| orang lain ke meja yang berbeda di tengah jalan.
|
| approval_flow_id dan approval_flow_step_id boleh null, dan itu disengaja:
| keduanya hanya jejak asal-usul. Flow yang kelak dihapus tidak boleh
| menjatuhkan dokumen yang sudah terlanjur memakainya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bdv_approvals')) {
            return;
        }

        Schema::create('bdv_approvals', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('bdv_id');

            $table->unsignedBigInteger('approval_flow_id')->nullable();
            $table->unsignedBigInteger('approval_flow_step_id')->nullable();

            $table->integer('step_order')->default(1);
            $table->string('label')->nullable();

            /* USER atau ROLE. Baris bertipe ROLE dicairkan saat menyetujui. */
            $table->string('approver_type', 20);
            $table->unsignedBigInteger('approver_id');
            $table->string('approver_name_snapshot')->nullable();

            /* ANY = cukup satu approver; ALL = menunggu semuanya. */
            $table->string('approval_mode', 10)->default('ANY');

            /* PENDING / WAITING / APPROVED / REJECTED / SKIPPED / CANCELLED. */
            $table->string('status', 20)->default('PENDING');

            $table->string('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            /*
            | Ikut terhapus bersama vouchernya. BDV memakai soft delete, jadi
            | ini hanya berlaku pada penghapusan sungguhan -- rantai approval
            | yatim tanpa dokumen tidak berarti apa pun.
            */
            $table->foreign('bdv_id')
                ->references('id')
                ->on('bank_disbursement_vouchers')
                ->cascadeOnDelete();

            /*
            | Dipakai pada hampir setiap pembacaan: "tahap mana yang sedang
            | menunggu pada dokumen ini". Tanpa indeks ini, setiap pembukaan
            | daftar memindai seluruh tabel approval.
            */
            $table->index(['bdv_id', 'step_order']);

            /*
            | Dipakai mencari dokumen yang menunggu SESEORANG -- inti dari
            | badge "menunggu persetujuan Anda" di sidebar.
            */
            $table->index(['approver_type', 'approver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bdv_approvals');
    }
};
