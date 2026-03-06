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
        Schema::create('master_dokumen_pendukung', function (Blueprint $table) {

            $table->bigIncrements('id');

            $table->string('nama_dokumen', 150);
            $table->text('deskripsi')->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->string('slug', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('master_dokumen_pendukung');
    }
};
