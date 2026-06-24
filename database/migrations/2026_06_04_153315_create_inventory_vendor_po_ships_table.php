<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_vendor_po_ship', function (Blueprint $table) {

            $table->id('id_master');

            $table->unsignedBigInteger('id_vendor_po');
            $table->tinyInteger('tipe_kapal')->nullable();
            $table->integer('id_transportir')->nullable();
            $table->integer('id_vessel_tb')->nullable();
            $table->integer('id_vessel');
            $table->foreignId('id_terminal_discharging')->constrained('terminal');
            $table->foreignId('loading_port')->constrained('terminal');
            $table->string('flag', 200)->nullable();
            $table->decimal('quantity', 22, 4)->default(0);
            $table->string('nomor_req', 300)->nullable();
            $table->string('nomor_si', 300)->nullable();
            $table->date('etl_date_first')->nullable();
            $table->date('etl_date_last')->nullable();

            $table->string('cargo_name', 255)->nullable();

            $table->integer('bill_lading')->nullable();

            $table->string('losstype', 10)->nullable();
            $table->decimal('loss_tolerance', 10, 2)->nullable();

            $table->string('satuan', 10)->nullable();

            $table->decimal('freight', 10, 2)->nullable();

            $table->integer('demurrage')->default(0);
            $table->integer('leadtime')->default(0);

            $table->string('country_origin', 200)->nullable();
            $table->string('shipper', 200)->nullable();
            $table->string('consignee', 200)->nullable();

            $table->text('bl_ship')->nullable();

            $table->tinyInteger('status')->default(0);

            $table->text('ket_ship')->nullable();
            $table->text('ket_log')->nullable();

            $table->string('log_pic', 80)->nullable();
            $table->dateTime('log_tanggal')->nullable();

            $table->dateTime('created_at')->nullable();
            $table->string('created_by', 50)->nullable();

            $table->dateTime('updated_at')->nullable();
            $table->string('updated_by', 50)->nullable();

            $table->integer('mgrfin_result')->nullable();
            $table->string('mgrfin_pic', 80)->nullable();
            $table->dateTime('mgrfin_tanggal')->nullable();
            $table->text('mgrfin_summary')->nullable();

            $table->tinyInteger('cfo_result')->default(0);
            $table->string('cfo_pic', 80)->nullable();
            $table->dateTime('cfo_tanggal')->nullable();
            $table->text('cfo_summary')->nullable();

            $table->tinyInteger('ceo_result')->default(0);
            $table->string('ceo_pic', 80)->nullable();
            $table->dateTime('ceo_tanggal')->nullable();
            $table->text('ceo_summary')->nullable();

            $table->tinyInteger('is_cancel')->default(0);
            $table->text('ket_cancel')->nullable();

            // INDEX
            $table->index('id_transportir');
            $table->index('id_terminal_discharging');
            $table->index('loading_port');
            $table->index('id_vendor_po');

            $table->foreign('id_vendor_po')
                ->references('id_master')
                ->on('inventory_vendor_po')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_vendor_po_ship');
    }
};