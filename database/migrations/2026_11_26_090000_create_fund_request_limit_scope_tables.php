<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Cakupan batas pengajuan FPU: dari dua kolom tunggal menjadi dua daftar
|--------------------------------------------------------------------------
| Sebelumnya sebuah batas hanya bisa menyebut SATU area (HO atau CABANG) dan
| SATU department. Permintaannya: sebuah batas boleh menyebut beberapa cabang
| sekaligus -- cabang A, B, dan C -- dan beberapa department sekaligus.
|
| Kolom lamanya (area_type, department_id) SENGAJA TIDAK DIHAPUS. Isinya
| menjadi satu-satunya jejak cakupan lama kalau migrasi ini perlu diputar
| balik; yang berubah hanya siapa yang membacanya -- sejak sekarang tidak ada
| lagi kode yang membacanya, dan seluruh pencocokan memakai kedua tabel di
| bawah.
|
| TERJEMAHAN ISINYA, dan satu hal yang berubah artinya:
|   area_type NULL   -> tanpa baris cabang sama sekali = berlaku untuk semua
|   area_type 'HO'   -> satu baris, cabang Kantor Pusat
|   area_type 'CABANG' -> satu baris untuk SETIAP cabang aktif selain pusat
|
| Yang ketiga itu bukan terjemahan yang setara, dan itu disengaja. 'CABANG'
| dahulu berarti "selain pusat, apa pun cabangnya, termasuk yang dibuka
| besok". Sesudah ini daftarnya tetap pada cabang yang ada saat migrasi
| berjalan; cabang baru TIDAK otomatis ikut. Itulah harga dari memilih cabang
| satu per satu, dan memang itu yang diminta.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_request_limit_branches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('fund_request_limit_id')
                ->constrained('fund_request_limits')
                ->cascadeOnDelete();

            /*
            | Tanpa foreign key ke cabang.
            |
            | cash_advances.branch sendiri hanya kolom teks tanpa relasi, dan
            | memasang kunci di sini saja akan membuat cabang yang dihapus
            | gagal dihapus karena baris batas -- padahal batas itu cuma
            | pengaturan, bukan dokumen. Cabang yang hilang cukup berhenti
            | cocok dengan dokumen mana pun.
            */
            $table->unsignedBigInteger('cabang_id');

            $table->timestamps();

            $table->unique(
                ['fund_request_limit_id', 'cabang_id'],
                'fund_request_limit_branches_unique',
            );

            $table->index('cabang_id', 'fund_request_limit_branches_cabang_idx');
        });

        Schema::create('fund_request_limit_departments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('fund_request_limit_id')
                ->constrained('fund_request_limits')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('department_id');

            $table->timestamps();

            $table->unique(
                ['fund_request_limit_id', 'department_id'],
                'fund_request_limit_departments_unique',
            );

            $table->index('department_id', 'fund_request_limit_departments_dept_idx');
        });

        $sekarang = now();

        /* ===== Terjemahan department: satu kolom menjadi satu baris ===== */
        $baris = DB::table('fund_request_limits')
            ->whereNotNull('department_id')
            ->get(['id', 'department_id']);

        foreach ($baris as $satu) {
            DB::table('fund_request_limit_departments')->insert([
                'fund_request_limit_id' => $satu->id,
                'department_id' => (int) $satu->department_id,
                'created_at' => $sekarang,
                'updated_at' => $sekarang,
            ]);
        }

        /* ===== Terjemahan area ===== */
        $pusat = 1;

        $cabangSelainPusat = DB::table('cabang')
            ->where('is_active', true)
            ->where('id', '!=', $pusat)
            ->pluck('id');

        $berArea = DB::table('fund_request_limits')
            ->whereNotNull('area_type')
            ->get(['id', 'area_type']);

        foreach ($berArea as $satu) {
            $tujuan = strtoupper(trim((string) $satu->area_type)) === 'HO'
                ? collect([$pusat])
                : $cabangSelainPusat;

            foreach ($tujuan as $cabangId) {
                DB::table('fund_request_limit_branches')->insert([
                    'fund_request_limit_id' => $satu->id,
                    'cabang_id' => (int) $cabangId,
                    'created_at' => $sekarang,
                    'updated_at' => $sekarang,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_request_limit_departments');
        Schema::dropIfExists('fund_request_limit_branches');
    }
};
