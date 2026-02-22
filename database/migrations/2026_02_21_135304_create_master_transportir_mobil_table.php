<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('master_transportir_mobil', function (Blueprint $table) {
            // PK ala legacy
            $table->bigIncrements('id_master');

            // FK ke transportir (default asumsi PK transportir adalah "id")
            $table->unsignedBigInteger('id_transportir');

            $table->string('nomor_plat', 20);
            $table->string('no_proyek', 50)->nullable();

            $table->integer('max_kap')->default(0);

            // MySQL: text NOT NULL
            $table->text('komp_tanki');

            $table->string('photo', 250)->nullable();
            $table->string('photo_ori', 250)->nullable();

            $table->string('link_gps', 150);
            $table->string('user_gps', 100);
            $table->string('pass_gps', 100);
            $table->string('membercode_gps', 50);

            $table->smallInteger('is_active')->default(1);

            // timestamps versi kamu
            $table->dateTime('created_time');
            $table->string('created_ip', 20);
            $table->string('created_by', 50);
            $table->dateTime('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 20)->nullable();
            $table->string('lastupdate_by', 50)->nullable();

            // index
            $table->index('id_transportir', 'pro_mt_mobil_idx1');

            // FK (PostgreSQL)
            $table->foreign('id_transportir', 'master_transportir_mobil_ibfk_1')
                ->references('id')      // <-- asumsi PK transportir "id"
                ->on('transportir')
                ->onUpdate('cascade');
            // onDelete tidak kamu minta, jadi saya biarkan default (RESTRICT)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_transportir_mobil');
    }
};