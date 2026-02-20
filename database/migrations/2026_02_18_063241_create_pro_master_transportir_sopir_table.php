<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transportir_sopir', function (Blueprint $table) {

            $table->increments('id_master'); // int(11) auto_increment

            $table->unsignedInteger('id_transportir');

            $table->string('nama_sopir', 70);

            $table->string('photo', 250)->nullable();
            $table->string('photo_ori', 250)->nullable();

            $table->tinyInteger('is_active')->default(1);

            $table->dateTime('created_time');
            $table->string('created_ip', 20);
            $table->string('created_by', 50);

            $table->dateTime('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 20)->nullable();
            $table->string('lastupdate_by', 50)->nullable();

            // index
            $table->index('id_transportir', 'transportir_sopir_idx1');

            // foreign key
            $table->foreign('id_transportir')
                ->references('id') // jika parent pakai id_master, ganti jadi id_master
                ->on('transportir') // ganti jika nama tabel parent berbeda
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transportir_sopir');
    }
};
