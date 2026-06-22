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
        Schema::create('inventory_vendor_receive_history', function (Blueprint $table) {
            $table->id('id_master');
            $table->bigInteger('id_po_receive');
            $table->bigInteger('id_po_supplier');

            $table->integer('id_accurate')->nullable();
            $table->string('no_terima', 255)->nullable();
            $table->string('nama_pic', 300)->nullable();
            $table->date('tgl_terima');

            $table->decimal('volume_bol', 22, 4)->default(0);
            $table->decimal('volume_terima', 22, 4)->default(0);
            $table->decimal('harga_tebus', 22, 4)->default(0);

            $table->string('file_upload', 300)->nullable();
            $table->string('file_upload_ori', 300)->nullable();

            $table->integer('is_aktif')->default(1);

            $table->dateTime('created_time');
            $table->string('created_ip', 20);
            $table->string('created_by', 50);

            $table->dateTime('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 20)->nullable();
            $table->string('lastupdate_by', 50)->nullable();

            $table->tinyInteger('is_updated')->default(0);
            $table->tinyInteger('updated_count')->default(0);
            $table->text('keterangan_updated');


            // index
            $table->index('id_po_receive', 'receive_log_fk1');
            $table->index('id_po_supplier', 'receive_log_fk2');

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('inventory_vendor_receive_history');
    }
};
