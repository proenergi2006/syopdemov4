<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sebelum ini, apakah sebuah permission butuh scope atau tidak ditentukan
 * hardcode dari nama action (action === 'create' -> tidak butuh scope,
 * selain itu -> butuh scope). Sekarang dijadikan flag per-permission yang
 * bisa diatur admin lewat halaman Permission Modules.
 *
 * Default column true + backfill action='create' jadi false supaya
 * perilaku hari ini persis sama sebelum admin mulai mengubahnya manual.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->boolean('requires_scope')->default(true)->after('is_active');
        });

        DB::table('permissions')
            ->where('action', 'create')
            ->update(['requires_scope' => false]);
    }

    public function down()
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('requires_scope');
        });
    }
};
