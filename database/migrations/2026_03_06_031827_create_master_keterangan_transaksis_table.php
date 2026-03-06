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
        Schema::create('master_keterangan_transaksi', function (Blueprint $table) {

            $table->bigIncrements('id');

            $table->string('kategori', 255);
            $table->string('pasal_pajak', 50)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('master_keterangan_transaksi');
    }
};
