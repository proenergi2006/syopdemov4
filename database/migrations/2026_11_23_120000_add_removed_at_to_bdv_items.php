<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Nisan sementara untuk baris yang hilang dari dokumen sumber
|--------------------------------------------------------------------------
| Finance masih boleh merevisi FPU/Claim selama BDV-nya belum diajukan, dan
| revisi itu bisa menghapus satu baris. Di sini barisnya memang harus ikut
| hilang -- tetapi di Accurate ia sudah terlanjur ada, dan membuangnya di sini
| begitu saja membuat kita kehilangan satu-satunya hal yang bisa
| menghapusnya di sana: accurate_detail_id miliknya.
|
| Yang tertinggal kemudian adalah baris biaya yatim di pembukuan, dengan
| nominal yang tidak ada padanannya di dokumen mana pun.
|
| Karena itu barisnya ditandai dulu, bukan dibuang. Pada pengiriman berikutnya
| ia disertakan dengan _status "delete", dan baru benar-benar dihapus di sini
| setelah Accurate menerimanya.
|
| NISANNYA BERUMUR PENDEK -- biasanya hanya hidup di dalam satu permintaan,
| antara penyimpanan dan pengirimannya. Ia bertahan lebih lama hanya bila
| pengirimannya gagal, dan itu memang gunanya: simpan berikutnya mengulangi
| penghapusan yang belum sampai.
|
| Baris yang belum pernah terkirim (accurate_detail_id kosong) tidak perlu
| nisan. Tidak ada apa pun di Accurate yang harus dibereskan untuknya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bdv_items', function (Blueprint $table): void {
            $table->timestamp('removed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bdv_items', function (Blueprint $table): void {
            $table->dropColumn('removed_at');
        });
    }
};
