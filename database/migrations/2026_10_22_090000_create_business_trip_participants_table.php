<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Peserta lain yang ikut dalam satu perjalanan dinas
|--------------------------------------------------------------------------
| Satu perdin bisa dijalani lebih dari satu orang. Sebelum ini dokumennya
| hanya menyebut pemohonnya, sehingga penyetuju tidak punya cara mengetahui
| berapa orang yang sebenarnya berangkat -- padahal jumlah orang itulah yang
| menentukan biaya penginapan, tiket, dan transportnya.
|
| IDENTITASNYA DISALIN, BUKAN HANYA DITUNJUK.
|
| user_id tetap disimpan supaya tahu akun mana yang dimaksud, tetapi nama,
| department, dan jabatannya disalin ke sini pada saat dokumennya dibuat --
| aturan yang sama dengan identitas pemohon pada business_trips, dan
| alasannya pun sama: dokumen yang sudah ditandatangani tidak boleh berubah
| sendiri mengikuti mutasi jabatan atau perpindahan department orangnya.
| Cetakan perdin tahun lalu harus tetap berbunyi seperti saat ia dicetak.
|
| KENAPA TABEL SENDIRI, BUKAN KOLOM JSON.
|
| Barisnya ikut dipotret riwayat revisi, dan daftar perubahan membandingkan
| baris demi baris memakai id-nya. Kolom JSON akan terbaca sebagai satu nilai
| yang berubah seluruhnya setiap kali ada satu orang ditambahkan.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_trip_participants')) {
            return;
        }

        Schema::create('business_trip_participants', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('business_trip_id');

            /*
            | Akun yang dimaksud. Tanpa foreign key, mengikuti kolom pengguna
            | lain di modul ini -- akun yang dinonaktifkan tidak boleh
            | menghapus baris dokumen yang menyebutnya.
            */
            $table->unsignedBigInteger('user_id');

            /* Salinan identitas, lihat keterangan di atas. */
            $table->string('employee_name');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('department_name')->nullable();
            $table->string('position_name')->nullable();

            /*
            | Urutan tampilnya, mengikuti urutan saat dipilih pemohon. Tanpa
            | ini daftarnya berganti urutan setiap kali dibaca, dan cetakan
            | yang sama tercetak berbeda dua kali.
            */
            $table->unsignedInteger('sort_no')->default(1);

            $table->timestamps();

            $table->index('business_trip_id', 'btp_trip_index');

            /*
            | Satu orang tidak bisa ikut dua kali dalam perjalanan yang sama.
            | Dijaga di sini, bukan hanya di layar: daftar yang kembar membuat
            | jumlah orang pada dokumennya berbohong.
            */
            $table->unique(['business_trip_id', 'user_id'], 'btp_trip_user_unique');
        });

        DB::statement(
            "COMMENT ON TABLE business_trip_participants IS "
            . "'Peserta lain yang ikut dalam satu perjalanan dinas, selain pemohonnya. "
            . "Nama, department, dan jabatannya adalah SALINAN keadaan saat dokumen dibuat.'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('business_trip_participants');
    }
};
