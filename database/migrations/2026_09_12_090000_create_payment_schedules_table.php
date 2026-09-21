<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Master jadwal pembayaran Finance
|--------------------------------------------------------------------------
| Finance membayar pada hari tertentu saja, dan berkas yang masuk sebelum
| suatu batas waktu ikut rombongan pembayaran berikutnya.
|
| Yang disimpan BUKAN "berapa hari jedanya", melainkan batas setornya. Aturan
| yang berlaku sekarang cukup dua baris batas:
|
|   Batas Rabu 14:00     -> dibayar Senin minggu berikutnya
|   Batas Minggu 23:59   -> dibayar Rabu  minggu berikutnya
|
| Berkas yang masuk Sabtu atau Minggu otomatis ikut rombongan Rabu, karena
| batas terdekatnya memang batas kedua.
|
| DUA TABEL, bukan satu. Sebuah "jadwal" adalah sekumpulan batas yang berlaku
| bersama, dan cakupannya melekat pada jadwal -- bukan pada tiap batas. Tanpa
| pemisahan ini, memberi Perdin jadwal sendiri berarti menyalin cakupan yang
| sama ke setiap barisnya, dan cepat atau lambat salah satunya akan tertinggal
| saat diubah.
|
| Cakupan jadwal ada dua sumbu, keduanya boleh kosong:
|
|   document_type            kosong = berlaku untuk semua modul
|   transaction_category_id  kosong = berlaku untuk semua keterangan transaksi
|
| Yang paling khusus menang. Bobotnya: keterangan transaksi 2, modul 1 --
| sehingga "Perdin, semua modul" mengalahkan "FPU, semua keterangan". Ini
| disengaja: keterangan transaksinya yang menentukan sifat pembayarannya,
| bukan modul tempat ia diajukan.
|
| Hari memakai penomoran ISO: 1 = Senin ... 7 = Minggu.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_schedules', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 100);

            /*
            | Cakupan. Keduanya kosong berarti jadwal umum -- dan memang harus
            | selalu ada satu yang begitu, kalau tidak dokumen yang tidak cocok
            | ke mana pun akan kehilangan jadwalnya.
            */
            $table->string('document_type', 30)->nullable();
            $table->unsignedBigInteger('transaction_category_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'document_type', 'transaction_category_id'], 'payment_schedules_scope_index');
        });

        Schema::create('payment_schedule_cutoffs', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('payment_schedule_id');

            /* Batas setor: berkas yang diterima sampai saat ini ikut rombongan ini. */
            $table->unsignedTinyInteger('cutoff_day');
            $table->time('cutoff_time');

            /* Hari pembayarannya, dihitung dari minggu tempat batas itu berada. */
            $table->unsignedTinyInteger('payment_day');
            $table->unsignedTinyInteger('payment_week_offset')->default(1);

            $table->timestamps();

            $table->foreign('payment_schedule_id')
                ->references('id')
                ->on('payment_schedules')
                ->cascadeOnDelete();

            $table->index(['payment_schedule_id', 'cutoff_day'], 'payment_schedule_cutoffs_lookup_index');
        });

        DB::statement("COMMENT ON COLUMN payment_schedules.document_type IS 'Modul yang dicakup: FPU, REALISASI, CLAIM. Kosong = semua modul'");
        DB::statement("COMMENT ON COLUMN payment_schedules.transaction_category_id IS 'Keterangan transaksi yang dicakup. Kosong = semua keterangan'");
        DB::statement("COMMENT ON COLUMN payment_schedule_cutoffs.cutoff_day IS 'Hari batas setor, ISO 1=Senin s/d 7=Minggu'");
        DB::statement("COMMENT ON COLUMN payment_schedule_cutoffs.cutoff_time IS 'Jam batas setor pada hari tersebut, inklusif'");
        DB::statement("COMMENT ON COLUMN payment_schedule_cutoffs.payment_day IS 'Hari pembayaran, ISO 1=Senin s/d 7=Minggu'");
        DB::statement("COMMENT ON COLUMN payment_schedule_cutoffs.payment_week_offset IS 'Berapa minggu setelah minggu batas setor; 1 = minggu berikutnya'");

        /*
        | Jadwal umum yang berlaku saat fitur ini dibuat. Diisi lewat migrasi,
        | bukan seeder, supaya jadwalnya sudah ada begitu kolom tanggalnya
        | dipakai -- tanpa itu dokumen yang diterima lebih dulu tidak akan
        | punya jadwal sama sekali.
        */
        $now = now();

        $umum = DB::table('payment_schedules')->insertGetId([
            'name' => 'Jadwal Umum',
            'document_type' => null,
            'transaction_category_id' => null,
            'is_active' => true,
            'notes' => 'Berlaku untuk seluruh modul dan keterangan transaksi yang tidak punya jadwal sendiri.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('payment_schedule_cutoffs')->insert([
            [
                'payment_schedule_id' => $umum,
                'cutoff_day' => 3,
                'cutoff_time' => '14:00:00',
                'payment_day' => 1,
                'payment_week_offset' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'payment_schedule_id' => $umum,
                'cutoff_day' => 7,
                'cutoff_time' => '23:59:59',
                'payment_day' => 3,
                'payment_week_offset' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_schedule_cutoffs');
        Schema::dropIfExists('payment_schedules');
    }
};
