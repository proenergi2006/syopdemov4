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
        Schema::create('inventory_depot', function (Blueprint $table) {
           $table->bigIncrements('id_master');

            $table->integer('id_accurate')->nullable();
            $table->string('id_datanya', 255);
            $table->integer('id_jenis');
            $table->integer('id_produk');
            $table->integer('id_terminal');
            $table->integer('id_vendor')->nullable();
            $table->bigInteger('id_po_supplier')->nullable();
            $table->bigInteger('id_po_receive')->nullable();

            $table->date('tanggal_inven');

            $table->integer('harga')->default(0);

            $table->decimal('awal_inven', 22, 4)->default(0);
            $table->decimal('in_inven', 22, 4)->default(0);
            $table->decimal('out_inven', 22, 4)->default(0);
            $table->decimal('adj_inven', 22, 4)->default(0);
            $table->decimal('out_inven_virtual', 22, 4)->default(0);

            $table->text('keterangan')->nullable();

            $table->dateTime('created_time');
            $table->string('created_ip', 20);
            $table->string('created_by', 255);

            $table->dateTime('lastupdate_time')->nullable();
            $table->string('lastupdate_ip', 20)->nullable();
            $table->string('lastupdate_by', 255)->nullable();

            $table->integer('id_dsd')->nullable();
            $table->integer('id_dsk')->nullable();
            $table->integer('id_pr')->nullable();
            $table->integer('id_prd')->nullable();
            $table->integer('id_pr_ti')->nullable();
            $table->integer('id_prd_ti')->nullable();
            $table->integer('id_pengisian_solar')->default(0);

            // indexes
            $table->index('id_terminal', 'new_pro_inventory_depot_idx1');
            $table->index('id_produk', 'new_pro_inventory_depot_idx2');
            $table->index('id_vendor', 'new_pro_inventory_depot_idx3');

            // foreign keys
            $table->foreign('id_terminal', 'inventory_depot_fk1')
                ->references('id')
                ->on('terminal')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('id_produk', 'inventory_depot_fk2')
                ->references('id')
                ->on('produk')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('inventory_depot');
    }
};
