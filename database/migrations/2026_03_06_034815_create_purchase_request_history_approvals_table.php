<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_request_history_approvals', function (Blueprint $table) {

            $table->bigIncrements('id');

            $table->unsignedBigInteger('purchase_request_id');

            $table->integer('level');
            $table->unsignedBigInteger('approver_user_id')->nullable();
            $table->string('approver_role', 100);

            $table->string('status', 255);
            $table->text('notes')->nullable();

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->foreign(
                'purchase_request_id',
                'purchase_request_history_approvals_purchase_request_id_foreign'
            )
                ->references('id')
                ->on('purchase_requests')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_history_approvals', function (Blueprint $table) {
            $table->dropForeign('purchase_request_history_approvals_purchase_request_id_foreign');
        });

        Schema::dropIfExists('purchase_request_history_approvals');
    }
};
