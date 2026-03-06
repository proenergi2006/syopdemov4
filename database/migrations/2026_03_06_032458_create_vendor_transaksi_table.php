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
    public function up()
    {
        Schema::create('vendor_transaksi', function (Blueprint $table) {

            $table->bigIncrements('id');

            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('transaksi_id');

            $table->text('keterangan_tambahan')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->foreign('vendor_id', 'vendor_transaksis_vendor_id_foreign')
                ->references('id')
                ->on('master_vendor')
                ->onUpdate('no action')
                ->onDelete('cascade');

            $table->foreign('transaksi_id', 'vendor_transaksis_transaksi_id_foreign')
                ->references('id')
                ->on('master_keterangan_transaksi')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('vendor_transaksi', function (Blueprint $table) {
            $table->dropForeign('vendor_transaksis_vendor_id_foreign');
            $table->dropForeign('vendor_transaksis_transaksi_id_foreign');
        });

        Schema::dropIfExists('vendor_transaksi');
    }
};
