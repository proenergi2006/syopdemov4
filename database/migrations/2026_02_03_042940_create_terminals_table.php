<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('terminal', function (Blueprint $table) {
            $table->id();

            $table->string('nama_terminal', 150);
            $table->string('inisial_terminal', 30)->nullable();

            $table->string('tanki_terminal', 100)->nullable();
            $table->string('lokasi_terminal', 150)->nullable();

            $table->enum('kategori_terminal', ['Depo', 'Dispenser', 'Truck Gantung'])->default('Depo');

            $table->decimal('batas_atas', 18, 2)->nullable();
            $table->decimal('batas_bawah', 18, 2)->nullable();

            $table->decimal('latitude', 12, 8)->nullable();
            $table->decimal('longitude', 12, 8)->nullable();

            $table->text('alamat_terminal')->nullable();
            $table->string('telp_terminal', 50)->nullable();
            $table->string('fax_terminal', 50)->nullable();
            $table->string('cc_terminal', 100)->nullable();

            $table->text('catatan_terminal')->nullable();

            // attachment: simpan path file di server
            $table->string('att_terminal', 255)->nullable();

            $table->boolean('is_active')->default(true);

            // audit (sesuai yang kamu minta)
            $table->timestamp('created_time')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->string('created_by', 80)->nullable();

            $table->timestamp('lastupdate_time')->nullable();
            $table->string('lastupdate_by', 80)->nullable();

            // relasi
            $table->unsignedBigInteger('id_cabang')->nullable();
            $table->unsignedBigInteger('id_area')->nullable();

            $table->foreign('id_cabang')->references('id')->on('cabang')->nullOnDelete();
            $table->foreign('id_area')->references('id')->on('area')->nullOnDelete();

            // index biar search cepat
            $table->index(['nama_terminal']);
            $table->index(['inisial_terminal']);
            $table->index(['kategori_terminal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal');
    }
};
