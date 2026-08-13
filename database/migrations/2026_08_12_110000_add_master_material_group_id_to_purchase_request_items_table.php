<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            /*
            |------------------------------------------------------------------
            | Material Group
            |------------------------------------------------------------------
            | Kolom dibuat nullable meskipun pada form input bersifat wajib.
            | Alasannya: PR yang sudah terlanjur ada tidak memiliki nilai ini
            | dan tidak boleh gagal saat migration dijalankan. Aturan "wajib"
            | ditegakkan di lapisan validasi request, bukan di skema.
            |------------------------------------------------------------------
            */
            $table->unsignedBigInteger('master_material_group_id')
                ->nullable()
                ->after('nama_item');

            $table->foreign('master_material_group_id')
                ->references('id')
                ->on('master_material_groups')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropForeign(['master_material_group_id']);
            $table->dropColumn('master_material_group_id');
        });
    }
};
