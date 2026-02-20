<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transportir', function (Blueprint $table) {
            $table->id();

            $table->string('nama_transportir', 200);
            $table->string('nama_suplier', 200)->nullable();
            $table->string('lokasi_suplier', 200)->nullable();
            $table->text('alamat_suplier')->nullable();
            $table->string('att_suplier', 150)->nullable();
            $table->string('telp_suplier', 50)->nullable();
            $table->string('fax_suplier', 50)->nullable();

            // tinyint (Postgres pakai smallInteger)
            $table->smallInteger('is_fleet')->default(0);

            $table->text('terms_suplier')->nullable();
            $table->text('catatan')->nullable();

            $table->boolean('is_active')->default(true);

            $table->string('tipe_angkutan', 100)->nullable();

            // audit trail
            $table->timestamp('created_time')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamp('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 45)->nullable();
            $table->unsignedBigInteger('lastupdate_by')->nullable();

            // owner supplier (int)
            $table->integer('owner_suplier')->nullable();

            // optional index
            $table->index('nama_transportir');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transportir');
    }
};
