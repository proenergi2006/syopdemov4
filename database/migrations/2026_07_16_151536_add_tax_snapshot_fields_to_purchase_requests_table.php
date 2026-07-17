<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_requests', 'status_pkp')) {
                $table->string('status_pkp', 20)
                    ->default('NON_PKP');
            }

            if (!Schema::hasColumn('purchase_requests', 'jenis_pembayaran')) {
                $table->string('jenis_pembayaran', 100)
                    ->nullable();
            }

            if (!Schema::hasColumn('purchase_requests', 'top')) {
                $table->integer('top')
                    ->nullable()
                    ->default(0);
            }

            if (!Schema::hasColumn('purchase_requests', 'dpp')) {
                $table->decimal('dpp', 18, 2)
                    ->default(0);
            }

            if (!Schema::hasColumn('purchase_requests', 'ppn')) {
                $table->decimal('ppn', 18, 2)
                    ->default(0);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_requests', 'ppn')) {
                $table->dropColumn('ppn');
            }

            if (Schema::hasColumn('purchase_requests', 'dpp')) {
                $table->dropColumn('dpp');
            }

            if (Schema::hasColumn('purchase_requests', 'top')) {
                $table->dropColumn('top');
            }

            if (Schema::hasColumn('purchase_requests', 'jenis_pembayaran')) {
                $table->dropColumn('jenis_pembayaran');
            }

            if (Schema::hasColumn('purchase_requests', 'status_pkp')) {
                $table->dropColumn('status_pkp');
            }
        });
    }
};
