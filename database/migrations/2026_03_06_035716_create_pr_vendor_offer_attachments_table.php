<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_vendor_offer_attachments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('pr_vendor_offer_id');

            $table->string('filename', 255);
            $table->string('filepath', 255);
            $table->integer('filesize')->nullable();
            $table->string('filetype', 50)->nullable();

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->nullable();

            $table->foreign(
                'pr_vendor_offer_id',
                'pr_vendor_offer_attachments_pr_vendor_offer_id_foreign'
            )
                ->references('id')
                ->on('purchase_request_vendors')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('pr_vendor_offer_attachments', function (Blueprint $table) {
            $table->dropForeign('pr_vendor_offer_attachments_pr_vendor_offer_id_foreign');
        });

        Schema::dropIfExists('pr_vendor_offer_attachments');
    }
};
