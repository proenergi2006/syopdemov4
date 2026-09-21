<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Mengaktifkan kartu dashboard Purchase Requisition
|--------------------------------------------------------------------------
| Modulnya sudah terdaftar sejak awal tetapi ditandai belum tersedia, karena
| halamannya memang belum ada. Sekarang halaman dan endpoint-nya sudah jadi,
| jadi kartunya boleh dibuka dari Management Dashboard.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('dashboard_modules')
            ->where('code', 'PURCHASE_REQUISITION')
            ->update([
                'is_available' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('dashboard_modules')
            ->where('code', 'PURCHASE_REQUISITION')
            ->update([
                'is_available' => false,
                'updated_at' => now(),
            ]);
    }
};
