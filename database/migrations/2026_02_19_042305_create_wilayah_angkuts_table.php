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
        Schema::create('wilayah_angkut', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_prov')->nullable();
            $table->unsignedBigInteger('id_kab')->nullable();
            $table->string('wilayah_angkut', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_time')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->string('created_by', 80)->nullable();
            $table->string('lastupdate_by', 80)->nullable();
            $table->string('lastupdate_ip', 45)->nullable();
            $table->timestamp('lastupdate_time')->nullable();

            $table->foreign('id_prov')->references('id')->on('provinsi')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('id_kab')->references('id')->on('kabupaten')->cascadeOnUpdate()->restrictOnDelete();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wilayah_angkut');
    }
};
