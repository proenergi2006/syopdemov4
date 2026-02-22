<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ongkos_angkut', function (Blueprint $table) {

            // Columns
            $table->unsignedBigInteger('id_transportir');
            $table->unsignedBigInteger('id_wil_angkut');
            $table->unsignedBigInteger('id_prov_angkut');
            $table->unsignedBigInteger('id_kab_angkut');
            $table->unsignedBigInteger('id_vol_angkut');

            $table->integer('ongkos_angkut');

            $table->timestamp('created_time');
            $table->string('created_ip', 20);
            $table->string('created_by', 50);

            $table->timestamp('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 20)->nullable();
            $table->string('lastupdate_by', 50)->nullable();

            // Composite Primary Key
            $table->primary([
                'id_transportir',
                'id_wil_angkut',
                'id_vol_angkut'
            ]);

            // Indexes
            $table->index('id_transportir');
            $table->index('id_wil_angkut');
            $table->index('id_vol_angkut');
            $table->index('id_prov_angkut');
            $table->index('id_kab_angkut');

            // Foreign Keys
            $table->foreign('id_transportir')
                ->references('id')
                ->on('transportir')
                ->onUpdate('cascade');

            $table->foreign('id_wil_angkut')
                ->references('id')
                ->on('wilayah_angkut')
                ->onUpdate('cascade');

            $table->foreign('id_prov_angkut')
                ->references('id')
                ->on('provinsi')
                ->onUpdate('cascade');

            $table->foreign('id_kab_angkut')
                ->references('id')
                ->on('kabupaten')
                ->onUpdate('cascade');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ongkos_angkut');
    }
};