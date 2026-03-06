<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('nomor_pr', 100);
            $table->date('tanggal_pr');
            $table->string('cabang', 100);
            $table->text('notes')->nullable();
            $table->string('requested_by', 150)->nullable();
            $table->date('request_date')->nullable();

            $table->timestamp('deleted_at', 0)->nullable();
            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->decimal('total_amount', 20, 2)->default(0);
            $table->string('status', 20)->default('NEW');
            $table->integer('current_level')->default(1);

            $table->bigInteger('created_by')->nullable();
            $table->bigInteger('updated_by')->nullable();
            $table->timestamp('final_approved_at', 0)->nullable();

            $table->bigInteger('id_department')->nullable()->comment('Relasi ke table departments SYOP');
            $table->string('kategori', 255)->nullable();
        });

        DB::statement("
            CREATE UNIQUE INDEX pro_purchase_requests_nomor_pr_unique
            ON purchase_requests (nomor_pr)
            WHERE deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS pro_purchase_requests_nomor_pr_unique");

        Schema::dropIfExists('purchase_requests');
    }
};
