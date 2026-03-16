<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->string('public_id', 26)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
