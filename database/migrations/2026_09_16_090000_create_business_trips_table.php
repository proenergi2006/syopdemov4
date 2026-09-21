<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Perjalanan Dinas (Perdin)
|--------------------------------------------------------------------------
| Dua tabel:
|
|   business_trips              kepala formulir -- siapa, ke mana, kapan, untuk apa
|   business_trip_itineraries   rundown perjalanannya, diisi manual oleh pemohon
|
| Perdin berdiri sendiri, terpisah dari FPU. Yang menautkan keduanya nanti
| adalah keterangan transaksi "Perdin" pada FPU -- bukan tabel ini. Karena itu
| di sini belum ada kolom apa pun yang menunjuk ke FPU: menambahkannya sekarang
| berarti menebak bentuk tautan yang belum diputuskan.
|
| NAMA, DEPARTMENT, DAN JABATAN DISALIN, BUKAN CUMA DIRUJUK.
|
| Ketiganya memang diambil otomatis dari akun pemohon, tetapi disimpan ulang
| sebagai teks. Alasannya: orang pindah department dan berganti jabatan, dan
| formulir perdin yang sudah dicetak harus tetap berbunyi seperti saat
| diajukan. Kalau hanya merujuk ke akun, sebuah perdin tahun lalu akan ikut
| berubah jabatannya begitu orangnya naik pangkat -- dokumen yang berubah
| sendiri setelah ditandatangani.
|
| FK-nya tetap disimpan, supaya masih bisa ditelusuri ke akunnya.
|
| BELUM ADA STATUS.
|
| Yang diminta baru CRUD. Menambahkan kolom status sekarang berarti kolom yang
| tidak pernah berpindah nilai -- lebih jujur ditambahkan bersama alur
| persetujuannya nanti, sekalian dengan kolom-kolom pendampingnya.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_trips', function (Blueprint $table): void {
            $table->id();

            $table->string('trip_number', 100)->unique();

            /* Tanggal dokumen, dipakai penomoran. Bukan tanggal berangkat. */
            $table->date('date');

            /*
            | Karyawan yang melakukan perjalanan. Untuk sekarang selalu sama
            | dengan pembuatnya; dipisah dari created_by sejak awal supaya
            | nanti bisa dibuatkan orang lain tanpa mengubah bentuk tabel.
            */
            $table->unsignedBigInteger('user_id');

            /* Salinan tiga hal yang diambil dari akun -- lihat catatan di atas. */
            $table->string('employee_name', 150);
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('department_name', 150)->nullable();
            $table->string('position_name', 150)->nullable();

            /*
            | Cabang disimpan sebagai teks berisi id cabang, mengikuti
            | cash_advances dan claims. Bentuknya ditiru supaya penyaringan
            | cakupan data bekerja dengan cara yang sama di semua modul.
            */
            $table->string('branch', 50)->nullable();

            $table->string('destination', 255);

            /* Periode perjalanan: tanggal dan jamnya dipisah, seperti di formulir. */
            $table->date('depart_date');
            $table->time('depart_time')->nullable();
            $table->date('return_date');
            $table->time('return_time')->nullable();

            $table->text('purpose');
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'date'], 'business_trips_user_date_index');
            $table->index(['department_id', 'branch'], 'business_trips_scope_index');
            $table->index('depart_date', 'business_trips_depart_date_index');
        });

        Schema::create('business_trip_itineraries', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('business_trip_id');

            /* Urutan tampil, bukan nomor yang punya arti. */
            $table->unsignedSmallInteger('sort_no')->default(1);

            $table->date('date');

            /*
            | Jam dipecah tiga, bukan satu kolom teks.
            |
            | Di formulir kertas isinya beragam: "06:00 AM WIB", "15:00 - 19:00",
            | "17:00 PM - 00:00 AM WITA". Disimpan apa adanya, kolom itu tidak
            | bisa diurutkan, tidak bisa divalidasi, dan salah ketik tidak
            | ketahuan. Dipecah, kalimatnya tetap bisa dirangkai kembali untuk
            | ditampilkan.
            |
            | Perjalanan ini melintasi zona waktu, jadi zonanya ikut disimpan --
            | "14:00" tanpa keterangan zona menyesatkan justru pada dokumen yang
            | gunanya menjelaskan perjalanan antar pulau.
            */
            $table->time('time_start');
            $table->time('time_end')->nullable();
            $table->string('timezone', 4)->default('WIB');

            $table->text('description');

            /* Penanggung jawab: bisa nama, bisa unit, bisa nama vendor. */
            $table->string('pic', 150)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_trip_id', 'sort_no'], 'business_trip_itineraries_order_index');
        });

        /*
        | Keterangan kolom, supaya tabelnya bisa dibaca tanpa membuka migrasi ini.
        */
        $keterangan = [
            'business_trips.user_id' => 'Karyawan yang melakukan perjalanan. Untuk sekarang selalu sama dengan created_by.',
            'business_trips.employee_name' => 'Salinan nama saat dokumen dibuat, supaya dokumen lama tidak ikut berubah.',
            'business_trips.department_name' => 'Salinan nama department saat dokumen dibuat.',
            'business_trips.position_name' => 'Salinan jabatan (nama role) saat dokumen dibuat.',
            'business_trips.branch' => 'Berisi id cabang sebagai teks, mengikuti cash_advances dan claims.',
            'business_trips.date' => 'Tanggal dokumen, dipakai penomoran. Bukan tanggal berangkat.',
            'business_trip_itineraries.sort_no' => 'Urutan tampil dalam rundown.',
            'business_trip_itineraries.timezone' => 'WIB, WITA, atau WIT. Perjalanan bisa melintasi zona waktu.',
            'business_trip_itineraries.pic' => 'Penanggung jawab: nama orang, unit, atau vendor.',
        ];

        foreach ($keterangan as $kolom => $teks) {
            DB::statement("COMMENT ON COLUMN {$kolom} IS '" . str_replace("'", "''", $teks) . "'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_trip_itineraries');
        Schema::dropIfExists('business_trips');
    }
};
