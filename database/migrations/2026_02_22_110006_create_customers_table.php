<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            // PK standar laravel (Postgres: bigserial)
            $table->bigIncrements('id');

            // === sesuai form ===
            $table->unsignedBigInteger('marketing_id');   // required
            $table->string('nama_perusahaan', 255);       // required
            $table->string('email', 255)->unique();       // required + unique
            $table->text('alamat_perusahaan');            // required
            $table->unsignedBigInteger('provinsi_id');    // required
            $table->unsignedBigInteger('kabupaten_id');   // required
            $table->string('postal_code', 20)->nullable();// optional
            $table->string('telepon', 30);                // required
            $table->string('fax', 30)->nullable();        // optional
            $table->string('jenis_customer', 50);         // required (mis: Retail/Industri/dll)

            // status
            $table->boolean('is_active')->default(true);

            // audit (ikut style tabel2 kamu)
            $table->timestamp('created_time')->useCurrent();
            $table->string('created_ip', 45)->nullable();
            $table->string('created_by', 80)->nullable();

            $table->timestamp('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 45)->nullable();
            $table->string('lastupdate_by', 80)->nullable();

            // index yang umum
            $table->index(['marketing_id']);
            $table->index(['provinsi_id']);
            $table->index(['kabupaten_id']);
            $table->index(['jenis_customer']);
            $table->index(['is_active']);

            // FK (sesuaikan nama tabel kalau di project kamu beda)
            $table->foreign('marketing_id')
                ->references('id')->on('users')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign('provinsi_id')
                ->references('id')->on('provinsi')
                ->onUpdate('cascade')
                ->onDelete('restrict');

            $table->foreign('kabupaten_id')
                ->references('id')->on('kabupaten')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};