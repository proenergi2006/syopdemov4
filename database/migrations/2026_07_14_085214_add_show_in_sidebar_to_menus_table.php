<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('menus', 'show_in_sidebar')) {
            Schema::table('menus', function (Blueprint $table) {
                $table
                    ->boolean('show_in_sidebar')
                    ->default(true)
                    ->after('permission_key');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Existing menu dianggap tampil di sidebar
        |--------------------------------------------------------------------------
        */
        DB::table('menus')
            ->whereNull('show_in_sidebar')
            ->update([
                'show_in_sidebar' => true,
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('menus', 'show_in_sidebar')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->dropColumn('show_in_sidebar');
            });
        }
    }
};
