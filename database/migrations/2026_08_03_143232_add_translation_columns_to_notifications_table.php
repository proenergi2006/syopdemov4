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
    public function up()
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('title_key', 150)->nullable()->after('title');
            $table->json('title_params')->nullable()->after('title_key');
            $table->string('message_key', 150)->nullable()->after('message');
            $table->json('message_params')->nullable()->after('message_key');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn([
                'title_key',
                'title_params',
                'message_key',
                'message_params',
            ]);
        });
    }
};
