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
    public function up(): void
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->string('kategori_vendor', 50)->default('NON_TRADING');
            $table->string('fax', 50)->nullable()->after('nama_vendor');
            $table->text('alamat')->nullable()->after('fax');
            $table->string('email', 100)->nullable()->after('alamat');
            $table->string('telepon', 50)->nullable()->after('email');
            $table->smallInteger('jenis_perusahaan')->nullable()->after('telepon');

            $table->string('nama_pic', 100)->nullable()->after('jenis_perusahaan');
            $table->string('jabatan_pic', 100)->nullable()->after('nama_pic');
            $table->string('email_pic', 100)->nullable()->after('jabatan_pic');
            $table->string('telp_pic', 50)->nullable()->after('email_pic');

            $table->string('no_npwp', 50)->nullable()->after('telp_pic');
            $table->text('alamat_npwp')->nullable()->after('no_npwp');
            $table->string('no_sppkp', 50)->nullable()->after('alamat_npwp');
            $table->date('tgl_sppkp')->nullable()->after('no_sppkp');
            $table->text('alamat_sppkp')->nullable()->after('tgl_sppkp');

            $table->string('jenis_pembayaran', 100)->nullable()->after('alamat_sppkp');
            $table->string('status_pkp', 255)->default('PKP')->after('jenis_pembayaran');
            $table->string('status_approval', 255)->default('PENDING_REVIEW')->after('status_pkp');
            $table->text('approval_note')->nullable()->after('status_approval');
            $table->bigInteger('approved_by')->nullable()->after('approval_note');
            $table->timestamp('approved_at', 0)->nullable()->after('approved_by');

            $table->string('no_ktp', 50)->nullable()->after('approved_at');
            $table->integer('top')->default(0)->after('no_ktp');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('master_vendor', function (Blueprint $table) {
            $table->dropColumn([
                'kategori_vendor',
                'fax',
                'alamat',
                'email',
                'telepon',
                'jenis_perusahaan',
                'nama_pic',
                'jabatan_pic',
                'email_pic',
                'telp_pic',
                'no_npwp',
                'alamat_npwp',
                'no_sppkp',
                'tgl_sppkp',
                'alamat_sppkp',
                'jenis_pembayaran',
                'status_pkp',
                'status_approval',
                'approval_note',
                'approved_by',
                'approved_at',
                'no_ktp',
                'top',
            ]);
        });
    }
};
