<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('nomor_po', 255);
            $table->date('tanggal_po');

            $table->unsignedBigInteger('vendor_id');
            $table->string('cabang', 255);

            $table->string('jenis_pembayaran', 255);
            $table->integer('top')->nullable()->comment('TOP dalam hari, hanya untuk CREDIT');

            $table->decimal('total_nilai', 18, 2)->default(0);
            $table->string('status', 255)->default('DRAFT');
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->nullable();
            $table->timestamp('submitted_at', 0)->nullable();
            $table->timestamp('approved_at', 0)->nullable();
            $table->string('approved_by', 255)->nullable();

            $table->unsignedBigInteger('id_department')->nullable();
            $table->decimal('dpp', 18, 2)->default(0);
            $table->decimal('ppn', 18, 2)->default(0);

            $table->index('id_department', 'purchase_orders_id_department_index');
            $table->index('nomor_po', 'purchase_orders_nomor_po_index');
            $table->index('status', 'purchase_orders_status_index');
            $table->index('vendor_id', 'purchase_orders_vendor_id_index');
        });

        DB::statement("
            ALTER TABLE purchase_orders
            ADD CONSTRAINT purchase_orders_jenis_pembayaran_check
            CHECK (jenis_pembayaran IN ('CREDIT', 'CBD', 'COD'))
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE purchase_orders
            DROP CONSTRAINT IF EXISTS purchase_orders_jenis_pembayaran_check
        ");

        Schema::dropIfExists('purchase_orders');
    }
};
