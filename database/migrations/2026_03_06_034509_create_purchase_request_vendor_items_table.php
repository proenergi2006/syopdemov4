<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_vendor_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('pr_vendor_id');

            $table->string('nama_item', 255);
            $table->integer('qty')->default(1);
            $table->string('satuan', 255)->nullable();
            $table->text('spesifikasi')->nullable();
            $table->text('keterangan')->nullable();

            $table->decimal('harga_unit', 18, 2)->nullable();
            $table->decimal('subtotal', 18, 2)->nullable();

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->nullable();

            $table->foreign('pr_vendor_id', 'purchase_request_vendor_items_pr_vendor_id_foreign')
                ->references('id')
                ->on('purchase_request_vendors')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_vendor_items', function (Blueprint $table) {
            $table->dropForeign('purchase_request_vendor_items_pr_vendor_id_foreign');
        });

        Schema::dropIfExists('purchase_request_vendor_items');
    }
};
