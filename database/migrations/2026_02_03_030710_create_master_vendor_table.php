<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('master_vendor', function (Blueprint $table) {
            $table->id();

            $table->string('id_accurate', 50)->nullable();
            $table->string('kode_vendor', 50)->unique();
            $table->string('inisial_vendor', 20);
            $table->string('nama_vendor', 150);

            $table->boolean('is_active')->default(true);

            // audit
            $table->timestamp('created_time')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('lastupdate_time')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_vendor');
    }
};
