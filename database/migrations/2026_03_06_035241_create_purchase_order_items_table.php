<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('purchase_request_vendor_item_id')
                ->comment('Relasi ke PR vendor item (source)');

            $table->string('nama_item', 255);
            $table->integer('qty')->default(0);
            $table->string('satuan', 255)->nullable();
            $table->text('spesifikasi')->nullable();
            $table->text('keterangan')->nullable();

            $table->decimal('harga_unit', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->nullable();

            $table->foreign('purchase_order_id', 'purchase_order_items_purchase_order_id_foreign')
                ->references('id')
                ->on('purchase_orders')
                ->onUpdate('no action')
                ->onDelete('cascade');

            $table->index('purchase_order_id', 'purchase_order_items_purchase_order_id_index');
            $table->index(
                'purchase_request_vendor_item_id',
                'purchase_order_items_purchase_request_vendor_item_id_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropForeign('purchase_order_items_purchase_order_id_foreign');
            $table->dropIndex('purchase_order_items_purchase_order_id_index');
            $table->dropIndex('purchase_order_items_purchase_request_vendor_item_id_index');
        });

        Schema::dropIfExists('purchase_order_items');
    }
};
