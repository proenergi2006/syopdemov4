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
        Schema::create('harga_pertamina', function (Blueprint $table) {
            $table->id();
            $table->date('periode_awal');
            $table->date('periode_akhir');
            $table->unsignedBigInteger('id_area');
            $table->unsignedBigInteger('id_produk');
            $table->integer('harga_minyak');

            $table->dateTime('created_time');
            $table->string('created_ip', 20);
            $table->string('created_by', 50);

            $table->dateTime('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 20)->nullable();
            $table->string('lastupdate_by', 50)->nullable();

            // Primary Key (Composite)
            // $table->primary([
            //     'periode_awal',
            //     'periode_akhir',
            //     'id_area',
            //     'id_produk'
            // ]);

            // Index
            $table->index('id_area', 'pro_pertamina_idx1');
            $table->index('id_produk', 'pro_pertamina_idx2');

            // Foreign Key
            $table->foreign('id_area', 'harga_pertamina_ibfk_1')
                  ->references('id')
                  ->on('area')
                  ->restrictOnDelete()
                  ->cascadeOnUpdate();

            $table->foreign('id_produk', 'harga_pertamina_ibfk_2')
                  ->references('id')
                  ->on('produk')
                  ->restrictOnDelete()
                  ->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('harga_pertamina');
    }
};
