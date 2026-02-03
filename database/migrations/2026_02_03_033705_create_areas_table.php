<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('area', function (Blueprint $table) {
      $table->id();

      $table->string('nama_area', 150);
      $table->boolean('wapu')->default(false);

      // simpan path file (misal: storage/app/public/area/xxx.pdf)
      $table->string('lampiran', 255)->nullable();

      $table->boolean('is_active')->default(true);

      // audit fields
      $table->timestamp('created_time')->nullable();
      $table->string('created_ip', 45)->nullable();
      $table->unsignedBigInteger('created_by')->nullable();
      $table->timestamp('lastupdate_time')->nullable();

      // optional index
      $table->index(['nama_area']);
      $table->index(['is_active']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('area');
  }
};
