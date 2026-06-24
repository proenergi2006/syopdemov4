<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_gain_loss', function (Blueprint $table) {
            $table->bigIncrements('id_master');

            $table->unsignedBigInteger('id_po_supplier');

            $table->decimal('volume_po', 22, 4)->nullable()->default(0);
            $table->decimal('volume_terima', 22, 4)->nullable()->default(0);

            $table->tinyInteger('jenis')->nullable();

            $table->decimal('volume', 22, 4)->nullable()->default(0);

            $table->string('file_upload', 300)->nullable();
            $table->string('file_upload_ori', 300)->nullable();

            $table->text('ket')->nullable();

            $table->tinyInteger('disposisi_gain_loss')->nullable()->default(0);

            $table->tinyInteger('ceo_result')->nullable()->default(0);
            $table->string('ceo_pic', 80)->nullable();
            $table->dateTime('ceo_tanggal')->nullable();
            $table->text('ceo_summary')->nullable();

            $table->dateTime('created_time')->nullable();
            $table->string('created_ip', 20)->nullable();
            $table->string('created_by', 50)->nullable();

            // Optional foreign key jika tabel parent sudah ada
            $table->foreign('id_po_supplier')
                ->references('id_master')
                ->on('inventory_vendor_po')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_gain_loss');
    }
};