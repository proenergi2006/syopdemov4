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
        Schema::create('harga_jual', function (Blueprint $table) {
            $table->id();
            // Primary key composite
            $table->date('periode_awal');
            $table->date('periode_akhir');
            $table->unsignedBigInteger('id_area')->nullable();
            $table->unsignedBigInteger('pajak')->nullable();
            $table->unsignedBigInteger('produk')->nullable();
            // Fields
            $table->integer('harga_normal')->default(0);
            $table->string('loco', 100)->nullable();
            $table->string('skp', 100)->nullable();
            $table->integer('harga_sm')->default(0);
            $table->integer('harga_om')->default(0);
            $table->text('note_jual');
            $table->boolean('is_approved')->default(false);
            $table->boolean('is_evaluated')->default(true);
            $table->dateTime('tanggal_persetujuan')->nullable();
            $table->dateTime('created_time')->nullable();
            $table->string('created_ip', 20)->nullable();
            $table->string('created_by', 50)->nullable();
            $table->dateTime('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 20)->nullable();
            $table->string('lastupdate_by', 50)->nullable();
            $table->boolean('is_edited')->default(false);
            $table->integer('harga_ceo')->default(0)->nullable();
            $table->integer('harga_coo')->default(0)->nullable();

            // Indexes
            // $table->primary(['periode_awal','periode_akhir','id_area','pajak','produk'], 'harga_jual_primary');
            $table->index('id_area', 'pro_master_hm_idx1');
            $table->index('pajak', 'pro_master_hm_idx2');
            $table->index('produk', 'pro_master_hm_idx3');
            $table->index(['periode_awal','periode_akhir','id_area','produk','pajak','is_approved'], 'idx_hm_approved');


            // Foreign keys
            $table->foreign('pajak')->references('id')->on('pbbkb')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('produk')->references('id')->on('produk')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreign('id_area')->references('id')->on('area')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('harga_jual');
    }
};
