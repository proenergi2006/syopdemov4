<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_vendors', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('purchase_request_id');
            $table->unsignedBigInteger('vendor_id');

            $table->decimal('price_offer', 20, 2)->nullable()->comment('Harga penawaran vendor');
            $table->boolean('is_selected')->default(false);
            $table->text('keterangan')->nullable();

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->decimal('dpp', 18, 2)->default(0);
            $table->decimal('ppn', 18, 2)->default(0);

            $table->timestamp('deleted_at', 0)->nullable();

            $table->foreign('purchase_request_id', 'purchase_request_vendors_purchase_request_id_foreign')
                ->references('id')
                ->on('purchase_requests')
                ->onUpdate('no action')
                ->onDelete('cascade');

            $table->foreign('vendor_id', 'purchase_request_vendors_vendor_id_foreign')
                ->references('id')
                ->on('master_vendor')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_vendors', function (Blueprint $table) {
            $table->dropForeign('purchase_request_vendors_purchase_request_id_foreign');
            $table->dropForeign('purchase_request_vendors_vendor_id_foreign');
        });

        Schema::dropIfExists('purchase_request_vendors');
    }
};
