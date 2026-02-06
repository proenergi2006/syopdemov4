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
        Schema::create('produk', function (Blueprint $table) {
            $table->id();
            $table->string('jenis_produk', 25);
            $table->string('merk_dagang', 100);
            $table->text('catatan_produk');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_time')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->string('created_by', 80)->nullable();
            $table->string('lastupdate_by', 80)->nullable();
            $table->string('lastupdate_ip', 45)->nullable();
            $table->timestamp('lastupdate_time')->nullable();
            $table->integer('no_urut')->nullable();
            // $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('produk');
    }
};
