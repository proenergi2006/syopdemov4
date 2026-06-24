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
        Schema::create('inventory_vendor_harga_tebus', function (Blueprint $table) {
            $table->bigInteger('id_master');
            $table->bigInteger('id_po_receive');
            $table->bigInteger('id_po_supplier');
            $table->unsignedBigInteger('id_produk');
            $table->unsignedBigInteger('id_terminal');
            $table->date('tgl_terima');
            $table->decimal('harga_tebus', 22, 4)->default(0.0000);

            $table->foreign('id_produk', 'produk_fk')
                ->references('id')
                ->on('produk')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreign('id_terminal', 'terminal_fk')
                ->references('id')
                ->on('terminal')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
                
            $table->foreign('id_po_supplier', 'id_po_fk')
                ->references('id_master')
                ->on('inventory_vendor_po')
                ->onUpdate('cascade')
                ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('inventory_vendor_harga_tebus');
    }
};
