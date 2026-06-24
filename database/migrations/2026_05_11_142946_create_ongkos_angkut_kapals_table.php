<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ongkos_angkut_kapal', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('id_transportir');

            $table->string('nama_kapal', 150);
            $table->string('tipe_kapal', 150);

            $table->integer('max_kapal')->default(0);

            $table->string('asal_angkut', 100);
            $table->string('tujuan_angkut', 100);

            $table->integer('harga_angkut');
            $table->integer('volume_angkut');

            $table->dateTime('created_time');
            $table->string('created_ip', 20);
            $table->string('created_by', 50);

            $table->dateTime('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 20)->nullable();
            $table->string('lastupdate_by', 50)->nullable();

            $table->index('id_transportir', 'pro_oa_kapal_idx1');

            $table->foreign('id_transportir')
                ->references('id')
                ->on('transportir')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ongkos_angkut_kapal');
    }
};