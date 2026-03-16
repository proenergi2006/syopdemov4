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
    public function up(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique()->after('id');
        });
    }
};
