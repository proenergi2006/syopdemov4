<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Section pengelompokan menu sidebar
|--------------------------------------------------------------------------
| Sidebar selama ini satu deret panjang tanpa pemisah. Section memberi judul
| kelompok di antaranya -- Finance, Procurement, dan seterusnya.
|
| SECTION ADALAH DATA, BUKAN KODE.
|
| Namanya, ikonnya, urutannya, dan isinya seluruhnya diatur Super Admin lewat
| layar Menu Management. Tidak ada daftar section yang ditulis di dalam kode,
| jadi menambah atau mengganti nama kelompok tidak perlu rilis baru.
|
| DIBUAT SEBAGAI TABEL, BUKAN KOLOM TEKS DI MENUS.
|
| Kolom teks akan membuat "Finance", "finance", dan "Finace" menjadi tiga
| kelompok berbeda, dan mengganti nama satu section berarti menyunting setiap
| menu anggotanya satu per satu. Sebagai tabel, namanya berubah di satu tempat
| dan urutannya punya angkanya sendiri.
|
| HANYA MENU INDUK YANG PUNYA SECTION.
|
| Submenu mengikuti induknya; section adalah pemisah antar kelompok di tingkat
| teratas sidebar. menu_section_id pada submenu diabaikan saat menyusun
| navigasi.
|
| NULL BERARTI TANPA SECTION, dan itu sah: menu seperti Dashboard memang
| digambar di paling atas sebelum heading pertama muncul.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menu_sections')) {
            Schema::create('menu_sections', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 100);
                $table->string('icon', 100)->nullable();
                $table->integer('order_no')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['is_active', 'order_no']);
            });
        }

        if (!Schema::hasColumn('menus', 'menu_section_id')) {
            Schema::table('menus', function (Blueprint $table): void {
                $table->unsignedBigInteger('menu_section_id')->nullable()->after('parent_id');

                /*
                | nullOnDelete, bukan cascade.
                |
                | Menghapus sebuah section tidak boleh ikut menghapus menunya.
                | Yang dibubarkan kelompoknya, bukan isinya -- menunya kembali
                | ke bagian tanpa section, dan Super Admin tinggal
                | memindahkannya.
                */
                $table->foreign('menu_section_id')
                    ->references('id')
                    ->on('menu_sections')
                    ->nullOnDelete();

                $table->index('menu_section_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('menus', 'menu_section_id')) {
            Schema::table('menus', function (Blueprint $table): void {
                $table->dropForeign(['menu_section_id']);
                $table->dropIndex(['menu_section_id']);
                $table->dropColumn('menu_section_id');
            });
        }

        Schema::dropIfExists('menu_sections');
    }
};
