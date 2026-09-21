<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Jenis dokumen Approval Flow menjadi data, bukan kode
|--------------------------------------------------------------------------
| Sebelumnya daftar jenis dokumen (PR, PO, Vendor, FPU) ditulis langsung di
| lima tabel match() pada ApprovalFlowController dan satu tabel di frontend,
| sehingga menambah jenis dokumen baru menuntut perubahan kode di dua bahasa
| yang harus sinkron manual.
|
| Jenis dokumen sebenarnya selalu 1:1 dengan sebuah permission module --
| approval_flows.permission_module_id bahkan sudah punya foreign key ke sana.
| Karena itu daftarnya ditumpangkan pada permission_modules, bukan pada tabel
| master baru: satu sumber kebenaran, tanpa relasi tambahan.
|
| Menambah jenis dokumen baru sekarang cukup mengisi tiga kolom di bawah pada
| permission module yang bersangkutan.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    /**
     * Nilai awal, menyalin persis perilaku kode yang digantikan.
     *
     * code => [approval_document_type, approval_document_label, uses_area_matrix]
     */
    private const SEED = [
        'purchase_request' => ['PR', 'Purchase Requisition (PR)', true],
        'purchase_order' => ['PO', 'Purchase Order (PO)', false],
        'vendor' => ['Vendor', 'Master Vendor', false],
        'cash_advance' => ['FPU', 'FPU (Form Pengajuan Uang)', true],
    ];

    public function up(): void
    {
        Schema::table('permission_modules', function (Blueprint $table) {
            /*
            | Kode jenis dokumen sebagaimana tersimpan pada
            | approval_flows.document_type. NULL berarti module ini memang
            | tidak punya approval flow -- mayoritas module seperti itu.
            */
            $table->string('approval_document_type', 50)
                ->nullable()
                ->after('route_prefix');

            $table->string('approval_document_label')
                ->nullable()
                ->after('approval_document_type');

            /*
            | Menandai dokumen yang flow-nya dibedakan per area + department,
            | sehingga kedua field itu wajib diisi pada form approval flow.
            */
            $table->boolean('approval_uses_area_matrix')
                ->default(false)
                ->after('approval_document_label');

            /*
            | Satu kode jenis dokumen hanya boleh dimiliki satu module, supaya
            | pemetaan ke permission module tidak pernah ambigu.
            */
            $table->unique('approval_document_type');
        });

        foreach (self::SEED as $code => [$type, $label, $usesAreaMatrix]) {
            DB::table('permission_modules')
                ->where('code', $code)
                ->update([
                    'approval_document_type' => $type,
                    'approval_document_label' => $label,
                    'approval_uses_area_matrix' => $usesAreaMatrix,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('permission_modules', function (Blueprint $table) {
            $table->dropUnique(['approval_document_type']);

            $table->dropColumn([
                'approval_document_type',
                'approval_document_label',
                'approval_uses_area_matrix',
            ]);
        });
    }
};
