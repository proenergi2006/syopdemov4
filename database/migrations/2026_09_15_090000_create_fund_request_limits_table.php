<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Batas pengajuan FPU
|--------------------------------------------------------------------------
| Dua angka yang membatasi seorang pemohon:
|
|   max_outstanding      berapa FPU boleh berjalan bersamaan
|   max_realization_days berapa lama sebuah FPU boleh menunggu direalisasi
|
| Melewati salah satunya berarti pengajuan berikutnya ditolak, sampai yang
| lama dipertanggungjawabkan. Tujuannya memaksa realisasi, bukan menghukum --
| jadi batasnya harus bisa diubah tanpa menyentuh kode.
|
| Cakupannya dua sumbu, keduanya boleh kosong:
|
|   area_type     kosong = berlaku untuk HO maupun cabang
|   department_id kosong = berlaku untuk seluruh department
|
| Yang paling khusus menang. Bobotnya: department 2, area 1 -- sama persis
| dengan master jadwal pembayaran, supaya seluruh aplikasi memakai satu cara
| berpikir tentang cakupan.
|
| BERBEDA dari jadwal pembayaran, batas ini TIDAK dibekukan ke dokumen. Ia
| dinilai saat orang menekan Ajukan, karena tidak ada apa pun yang sudah
| terlanjur dijanjikan kepada pemohon. Konsekuensinya menurunkan batas langsung
| berlaku pada semua orang, termasuk yang sedang berada di atasnya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_request_limits', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 100);

            /* Cakupan. Keduanya kosong berarti batas umum -- jaring pengaman. */
            $table->string('area_type', 10)->nullable();
            $table->unsignedBigInteger('department_id')->nullable();

            $table->unsignedSmallInteger('max_outstanding');
            $table->unsignedSmallInteger('max_realization_days');

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'area_type', 'department_id'], 'fund_request_limits_scope_index');
        });

        DB::statement("COMMENT ON COLUMN fund_request_limits.area_type IS 'HO atau CABANG. Kosong = berlaku keduanya'");
        DB::statement("COMMENT ON COLUMN fund_request_limits.department_id IS 'Department yang dicakup. Kosong = seluruh department'");
        DB::statement("COMMENT ON COLUMN fund_request_limits.max_outstanding IS 'Jumlah FPU yang boleh berjalan bersamaan untuk satu pemohon'");
        DB::statement("COMMENT ON COLUMN fund_request_limits.max_realization_days IS 'Berapa hari kalender sebuah FPU boleh menunggu direalisasi'");

        /*
        | Batas umum yang berlaku saat fitur ini dibuat. Diisi lewat migrasi
        | supaya jaring pengamannya sudah ada sejak baris pertama -- tanpa itu,
        | pemohon yang tidak cocok ke batas khusus mana pun tidak terbatasi
        | sama sekali.
        */
        $now = now();

        DB::table('fund_request_limits')->insert([
            'name' => 'Batas Umum',
            'area_type' => null,
            'department_id' => null,
            'max_outstanding' => 3,
            'max_realization_days' => 14,
            'is_active' => true,
            'notes' => 'Berlaku untuk seluruh area dan department yang tidak punya batas sendiri.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_request_limits');
    }
};
