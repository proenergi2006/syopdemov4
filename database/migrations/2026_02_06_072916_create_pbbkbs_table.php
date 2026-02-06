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
        Schema::create('pbbkb', function (Blueprint $table) {
            $table->id();
            $table->float('nilai_pbbkb');
            $table->text('ket_pbbkb');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_time')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->string('created_by', 80)->nullable();
            $table->string('lastupdate_by', 80)->nullable();
            $table->string('lastupdate_ip', 45)->nullable();
            $table->timestamp('lastupdate_time')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pbbkb');
    }
};
