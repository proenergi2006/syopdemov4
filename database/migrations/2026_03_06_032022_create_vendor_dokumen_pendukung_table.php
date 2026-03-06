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
        Schema::create('vendor_dokumen_pendukung', function (Blueprint $table) {

            $table->bigIncrements('id');

            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('dokumen_id');

            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 255)->nullable();

            $table->boolean('is_uploaded')->default(false);
            $table->boolean('is_validated')->default(false);

            $table->text('notes')->nullable();

            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->bigInteger('file_size')->nullable();
            $table->string('file_type', 100)->nullable();

            $table->foreign('vendor_id', 'vendor_dokumen_pendukung_vendor_id_foreign')
                ->references('id')
                ->on('master_vendor')
                ->onUpdate('no action')
                ->onDelete('cascade');

            $table->foreign('dokumen_id', 'vendor_dokumen_pendukung_dokumen_id_foreign')
                ->references('id')
                ->on('master_dokumen_pendukung')
                ->onUpdate('no action')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('vendor_dokumen_pendukung', function (Blueprint $table) {
            $table->dropForeign('vendor_dokumen_pendukung_vendor_id_foreign');
            $table->dropForeign('vendor_dokumen_pendukung_dokumen_id_foreign');
        });

        Schema::dropIfExists('vendor_dokumen_pendukung');
    }
};
