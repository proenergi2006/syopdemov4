<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_attachments', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('purchase_request_id');

            $table->string('filename', 255);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('file_size')->nullable();

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->string('filepath', 255);

            $table->foreign(
                'purchase_request_id',
                'pr_attachments_purchase_request_id_foreign'
            )
                ->references('id')
                ->on('purchase_requests')
                ->onUpdate('no action')
                ->onDelete('cascade');

            $table->index(
                'purchase_request_id',
                'pr_attachments_purchase_request_id_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pr_attachments', function (Blueprint $table) {
            $table->dropForeign('pr_attachments_purchase_request_id_foreign');
            $table->dropIndex('pr_attachments_purchase_request_id_index');
        });

        Schema::dropIfExists('pr_attachments');
    }
};
