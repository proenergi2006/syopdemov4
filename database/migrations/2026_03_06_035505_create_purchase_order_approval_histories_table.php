<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_approval_histories', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('purchase_order_id');
            $table->string('role', 255);
            $table->string('status', 50);
            $table->text('notes')->nullable();
            $table->string('approved_by', 255)->nullable();
            $table->timestamp('approved_at', 0)->nullable();

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->nullable();

            $table->foreign(
                'purchase_order_id',
                'purchase_order_approval_histories_purchase_order_id_foreign'
            )
                ->references('id')
                ->on('purchase_orders')
                ->onUpdate('no action')
                ->onDelete('cascade');

            $table->index(
                'approved_by',
                'purchase_order_approval_histories_approved_by_index'
            );
            $table->index(
                'purchase_order_id',
                'purchase_order_approval_histories_purchase_order_id_index'
            );
            $table->index(
                'role',
                'purchase_order_approval_histories_role_index'
            );
            $table->index(
                'status',
                'purchase_order_approval_histories_status_index'
            );
        });

        DB::statement("
            ALTER TABLE purchase_order_approval_histories
            ADD CONSTRAINT purchase_order_approval_histories_role_check
            CHECK (role IN ('BM', 'CEO'))
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE purchase_order_approval_histories
            DROP CONSTRAINT IF EXISTS purchase_order_approval_histories_role_check
        ");

        Schema::table('purchase_order_approval_histories', function (Blueprint $table) {
            $table->dropForeign('purchase_order_approval_histories_purchase_order_id_foreign');
            $table->dropIndex('purchase_order_approval_histories_approved_by_index');
            $table->dropIndex('purchase_order_approval_histories_purchase_order_id_index');
            $table->dropIndex('purchase_order_approval_histories_role_index');
            $table->dropIndex('purchase_order_approval_histories_status_index');
        });

        Schema::dropIfExists('purchase_order_approval_histories');
    }
};
