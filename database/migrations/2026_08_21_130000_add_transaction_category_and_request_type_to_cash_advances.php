<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| FPU: keterangan transaksi dan sifat pengajuan
|--------------------------------------------------------------------------
| transaction_category_id ikut menentukan approval flow, sesuai matriks yang
| membedakan Perdin dari Telpon/Listrik/Pantry dan seterusnya.
|
| request_type (Rutin / Non Rutin) hanya keterangan pada dokumen, mengikuti
| pr_type pada Purchase Requisition. Tidak dipakai untuk memilih flow.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_advances', function (Blueprint $table) {
            $table->unsignedBigInteger('transaction_category_id')
                ->nullable()
                ->after('subject');

            $table->string('request_type', 50)
                ->nullable()
                ->after('transaction_category_id');

            $table->foreign('transaction_category_id')
                ->references('id')
                ->on('fund_request_transaction_categories')
                ->nullOnDelete();

            $table->index('transaction_category_id');
        });

        /*
        | Menandai bahwa jenis dokumen milik module ini dipilih juga
        | berdasarkan keterangan transaksi. Disimpan sebagai data, bukan
        | pemeriksaan "kalau FPU" di dalam kode.
        */
        Schema::table('permission_modules', function (Blueprint $table) {
            $table->boolean('approval_uses_transaction_category')
                ->default(false)
                ->after('approval_uses_area_matrix');
        });

        DB::table('permission_modules')
            ->where('code', 'cash_advance')
            ->update([
                'approval_uses_transaction_category' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('permission_modules', function (Blueprint $table) {
            $table->dropColumn('approval_uses_transaction_category');
        });

        Schema::table('cash_advances', function (Blueprint $table) {
            $table->dropForeign(['transaction_category_id']);

            $table->dropColumn([
                'transaction_category_id',
                'request_type',
            ]);
        });
    }
};
