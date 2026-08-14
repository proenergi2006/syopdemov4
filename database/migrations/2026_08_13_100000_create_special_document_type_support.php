<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Tipe Dokumen Khusus + Substitusi Approver
    |--------------------------------------------------------------------------
    | Kebutuhan asal: dokumen "Kapal" milik Procurement memakai orang yang
    | berbeda pada step GM Procurement. Slot approval-nya tetap, hanya
    | approver-nya yang berganti.
    |
    | Dibuat generik, bukan dipatok ke "Kapal": tipe dokumen khusus menjadi
    | DATA, sehingga kebutuhan serupa berikutnya cukup ditambah lewat menu --
    | tanpa migration dan tanpa perubahan kode.
    |
    | Tiga bagian:
    | 1. special_document_types      -> katalog tipe (Kapal, dst)
    | 2. purchase_requests.special_document_type_id -> penanda pada dokumen
    | 3. approval_flow_step_special_approvers -> siapa penggantinya, per step
    |
    | Seluruhnya nullable/kosong secara default, sehingga flow yang sudah
    | berjalan -- termasuk department lain -- berperilaku persis seperti
    | sebelum migration ini dijalankan.
    |--------------------------------------------------------------------------
    */
    public function up(): void
    {
        Schema::create('special_document_types', function (Blueprint $table) {
            $table->id();

            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();

            /*
            | Pembatas department yang boleh memakai tipe ini.
            | NULL = boleh dipakai semua department.
            */
            $table->unsignedBigInteger('department_id')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->nullOnDelete();
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            /*
            | NULL = dokumen biasa. Inilah sebabnya dokumen lama dan department
            | lain tidak terpengaruh sama sekali.
            */
            $table->unsignedBigInteger('special_document_type_id')
                ->nullable()
                ->after('pr_type');

            $table->foreign('special_document_type_id')
                ->references('id')
                ->on('special_document_types')
                ->nullOnDelete();
        });

        Schema::create('approval_flow_step_special_approvers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('approval_flow_step_id');
            $table->unsignedBigInteger('special_document_type_id');

            $table->string('approver_type', 20);
            $table->unsignedBigInteger('approver_id');

            $table->timestamps();

            $table->foreign('approval_flow_step_id', 'afssa_step_fk')
                ->references('id')
                ->on('approval_flow_steps')
                ->cascadeOnDelete();

            $table->foreign('special_document_type_id', 'afssa_type_fk')
                ->references('id')
                ->on('special_document_types')
                ->cascadeOnDelete();

            /*
            | Satu step hanya boleh punya satu pengganti untuk tiap tipe
            | dokumen, supaya tidak ada dua aturan yang saling bertabrakan.
            */
            $table->unique(
                ['approval_flow_step_id', 'special_document_type_id'],
                'afssa_step_type_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_flow_step_special_approvers');

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropForeign(['special_document_type_id']);
            $table->dropColumn('special_document_type_id');
        });

        Schema::dropIfExists('special_document_types');
    }
};
