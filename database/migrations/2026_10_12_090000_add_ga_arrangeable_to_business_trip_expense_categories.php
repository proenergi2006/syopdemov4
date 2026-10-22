<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Kategori mana yang boleh ditandai "diurus GA"
|--------------------------------------------------------------------------
| Penanda "diurus GA" pada rincian perdin sudah ada sejak 1 Oktober 2026,
| tetapi ditawarkan pada SEMUA kategori. Kenyataannya GA hanya mengurus
| sebagian -- tiket dan penginapan -- sedangkan sisanya diisi pemohon
| sendiri.
|
| Penanda yang ditawarkan di tempat yang bukan tempatnya bukan sekadar
| kelebihan pilihan: pemohon yang menyalakannya pada Uang Saku sedang
| menyatakan GA akan mengurusnya, dan tidak ada siapa pun yang akan
| mengurusnya. Angkanya hilang dari FPU tanpa ada yang menagihnya.
|
| DI MASTER, BUKAN DI KODE.
|
| Daftar "GA mengurus apa" adalah kesepakatan kerja, bukan aturan sistem --
| ia berubah tanpa perlu menunggu rilis, sama seperti urutan tampil dan
| penambahan kategori keempat yang sudah lebih dulu dijadikan master.
|
| Nilai bawaannya FALSE, bukan TRUE.
|
| Bawaan TRUE akan membuat kategori yang baru dibuat langsung menawarkan
| penanda GA kepada pemohon -- tepat keadaan yang sedang diperbaiki ini,
| hanya saja datang kembali lewat pintu lain. Yang perlu diurus GA disebut
| dengan sengaja; yang lain diisi pemohon, dan itu keadaan wajarnya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    /**
     * Kategori yang hari ini memang diurus GA.
     *
     * Dicocokkan menurut nama, bukan id: id-nya berbeda antar lingkungan,
     * sedangkan namanya yang dibaca orang.
     */
    private const DIURUS_GA = ['Tiket', 'Penginapan'];

    public function up(): void
    {
        if (Schema::hasColumn('business_trip_expense_categories', 'ga_arrangeable')) {
            return;
        }

        Schema::table('business_trip_expense_categories', function (Blueprint $table): void {
            $table->boolean('ga_arrangeable')
                ->default(false)
                ->after('sort_order');
        });

        DB::statement(
            "COMMENT ON COLUMN business_trip_expense_categories.ga_arrangeable IS "
            . "'Kategori ini boleh ditandai diurus GA pada rincian perdin. Yang tidak, wajib diisi pemohon.'",
        );

        /*
        | Keadaan yang berjalan sekarang diisikan SEKALI di sini.
        |
        | Bukan di seeder: seeder dijalankan berulang, dan penyetelan yang
        | diubah lewat master akan dikembalikan diam-diam setiap kali ia
        | jalan. Migrasi berjalan sekali, dan itu memang yang dimaui -- ini
        | nilai awal, bukan kebenaran yang terus ditegakkan.
        |
        | Dicocokkan ILIKE supaya "tiket" dan "Tiket" sama-sama tertangkap.
        */
        foreach (self::DIURUS_GA as $nama) {
            DB::table('business_trip_expense_categories')
                ->where('name', 'ILIKE', $nama)
                ->update(['ga_arrangeable' => true]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('business_trip_expense_categories', 'ga_arrangeable')) {
            return;
        }

        Schema::table('business_trip_expense_categories', function (Blueprint $table): void {
            $table->dropColumn('ga_arrangeable');
        });
    }
};
