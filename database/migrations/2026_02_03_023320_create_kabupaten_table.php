<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('kabupaten', function (Blueprint $table) {
      $table->id();

      $table->foreignId('provinsi_id')
        ->constrained('provinsi')
        ->cascadeOnUpdate()
        ->restrictOnDelete(); // biar gak bisa delete provinsi jika masih dipakai kabupaten

      $table->string('kode', 20)->unique();
      $table->string('nama', 150);
      $table->boolean('is_active')->default(true);

      $table->timestamps();

      $table->index(['provinsi_id', 'nama']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('kabupaten');
  }
};

