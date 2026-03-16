<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->boolean('same_as_npwp')
                ->default(false)
                ->after('no_npwp'); // sesuaikan posisi kolom jika perlu
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->dropColumn('same_as_npwp');
        });
    }
};
