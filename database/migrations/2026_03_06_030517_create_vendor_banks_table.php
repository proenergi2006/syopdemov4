<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('vendor_banks', function (Blueprint $table) {

            $table->bigIncrements('id');

            $table->unsignedBigInteger('vendor_id');

            $table->string('nama_bank', 100)->nullable();
            $table->string('atas_nama', 150)->nullable();
            $table->string('nomor_rekening', 50)->nullable();
            $table->string('cabang', 100)->nullable();
            $table->text('alamat_bank')->nullable();
            $table->string('swift_code', 50)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->foreign('vendor_id', 'vendor_banks_vendor_id_foreign')
                ->references('id')
                ->on('master_vendor')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('vendor_banks', function (Blueprint $table) {
            $table->dropForeign('vendor_banks_vendor_id_foreign');
        });

        Schema::dropIfExists('vendor_banks');
    }
};
