<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_orders', 'jenis_pembayaran')) {
                $table
                    ->string('jenis_pembayaran', 100)
                    ->nullable()
                    ->after('status_pkp');
            }

            if (!Schema::hasColumn('purchase_orders', 'top')) {
                $table
                    ->integer('top')
                    ->nullable()
                    ->default(0)
                    ->after('jenis_pembayaran');
            }
        });

        /*
        |--------------------------------------------------------------------------
        | Backfill data lama - PostgreSQL safe
        |--------------------------------------------------------------------------
        | Jangan pakai Query Builder update join, karena PostgreSQL bisa error:
        | missing FROM-clause entry for table "mv".
        |--------------------------------------------------------------------------
        */
        if (
            Schema::hasTable('master_vendor')
            && Schema::hasTable('purchase_orders')
            && Schema::hasColumn('purchase_orders', 'vendor_id')
            && Schema::hasColumn('purchase_orders', 'jenis_pembayaran')
            && Schema::hasColumn('purchase_orders', 'top')
            && Schema::hasColumn('master_vendor', 'jenis_pembayaran')
            && Schema::hasColumn('master_vendor', 'top')
        ) {
            DB::statement("
                UPDATE purchase_orders AS po
                SET
                    jenis_pembayaran = mv.jenis_pembayaran,
                    top = COALESCE(mv.top, 0)
                FROM master_vendor AS mv
                WHERE mv.id = po.vendor_id
            ");
        }
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'top')) {
                $table->dropColumn('top');
            }

            if (Schema::hasColumn('purchase_orders', 'jenis_pembayaran')) {
                $table->dropColumn('jenis_pembayaran');
            }
        });
    }
};
