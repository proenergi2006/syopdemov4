<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Plafon petty cash dan buku mutasinya
|--------------------------------------------------------------------------
| Dua tabel yang harus dibaca bersama:
|
|   petty_cash_ceilings  plafon per cabang -- nilai semula, yang dipulihkan
|                        setiap top up disetujui
|   petty_cash_ledgers   seluruh pergerakan uangnya, satu baris per kejadian
|
| SALDO TIDAK DISIMPAN. Ia dihitung sebagai SUM(petty_cash_ledgers.amount)
| untuk cabang itu. Keputusan ini sengaja:
|
|   - Saldo yang disimpan sebagai satu angka dan ditimpa berulang kali akan
|     melenceng pada suatu titik -- satu transaksi yang gagal setengah jalan,
|     satu perbaikan data lewat SQL, dan tidak ada lagi yang bisa memastikan
|     angka itu benar.
|   - Pembatalan jadi penambahan baris, bukan penulisan ulang angka. Riwayatnya
|     utuh: kelihatan bahwa ada pencairan lalu ada pembalikan, bukan seolah
|     pencairannya tidak pernah terjadi.
|   - Pertanyaan "kenapa saldonya sekian" selalu bisa dijawab dengan daftar,
|     bukan dengan tebakan.
|
| Biayanya murah: delapan cabang beroperasi, dan pergerakannya puluhan per
| bulan per cabang. Menjumlahkan beberapa ratus baris bukan beban bagi
| Postgres, sedangkan saldo yang salah tanpa jejak adalah beban bagi orang.
|
| Plafon pun TIDAK menjadi dasar perhitungan saldo. Baris OPENING-lah yang
| membawa nilai awalnya ke dalam buku. Kalau plafon diubah di master, yang
| tercatat adalah baris ADJUSTMENT -- bukan riwayat lama yang mendadak
| berarti lain karena angka pembandingnya bergeser.
|
| AMBANG CLAIM MENEMPEL DI SINI, bukan di pengaturan global. Alasannya: yang
| menentukan sebuah claim kecil harus lewat petty cash adalah ada-tidaknya kas
| kecil di cabang itu. Cabang tanpa baris plafon -- atau yang plafonnya
| dinonaktifkan -- berarti claim kecilnya kembali ke jalur Finance seperti
| biasa. Satu baris data menjawab dua pertanyaan sekaligus, dan tidak ada
| keadaan di mana ambangnya ada tetapi kas kecilnya tidak.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_ceilings', function (Blueprint $table): void {
            $table->id();

            /*
            | Satu baris per cabang. HO ikut punya plafon sendiri, jadi tidak
            | ada perlakuan khusus untuk area -- HO di sini satu cabang seperti
            | yang lain.
            */
            $table->unsignedBigInteger('branch_id');

            /*
            | Kas kecilnya dipegang Finance, bukan department yang mengajukan
            | claim. Disimpan supaya bisa dipindahkan tanpa mengubah kode,
            | bukan karena diharapkan sering berganti.
            */
            $table->unsignedBigInteger('department_id');

            $table->decimal('ceiling_amount', 18, 2);

            /*
            | Claim dengan nominal sampai angka ini wajib lewat petty cash.
            | Disimpan sebagai data, bukan ditulis di kode: kalau besok jadi
            | 750.000, yang berubah satu baris, bukan satu rilis.
            */
            $table->decimal('claim_threshold', 18, 2)->default(500000);

            /*
            | Batas peringatan, dalam persen dari plafon. Di bawah angka ini
            | ADH diperingatkan di dashboard-nya untuk mengajukan top up.
            |
            | Persen, bukan rupiah, supaya cabang berplafon besar dan kecil
            | tidak perlu disetel dengan dua cara berpikir berbeda.
            */
            $table->unsignedSmallInteger('warning_percentage')->default(50);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('cabang');
            $table->foreign('department_id')->references('id')->on('departments');

            $table->index(['is_active', 'branch_id']);
        });

        /*
        | Satu cabang tidak boleh punya dua plafon hidup sekaligus -- kalau
        | sampai terjadi, tidak ada aturan yang bisa memilih mana yang berlaku,
        | dan saldonya akan terbaca ganda.
        |
        | Indeks partial, jadi baris yang sudah di-soft delete tidak menghalangi
        | pembuatan plafon baru untuk cabang yang sama.
        */
        DB::statement(
            'CREATE UNIQUE INDEX petty_cash_ceilings_branch_unique
             ON petty_cash_ceilings (branch_id)
             WHERE deleted_at IS NULL'
        );

        DB::statement("COMMENT ON COLUMN petty_cash_ceilings.ceiling_amount IS 'Plafon -- nilai semula yang dipulihkan setiap top up disetujui'");
        DB::statement("COMMENT ON COLUMN petty_cash_ceilings.claim_threshold IS 'Claim sampai nominal ini wajib lewat petty cash'");
        DB::statement("COMMENT ON COLUMN petty_cash_ceilings.warning_percentage IS 'Saldo di bawah persen ini memicu peringatan top up di dashboard ADH'");

        Schema::create('petty_cash_ledgers', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('petty_cash_ceiling_id');

            /*
            | Cabang ikut disimpan, meski sudah bisa dicari lewat plafonnya.
            |
            | Buku ini akan tetap dibaca setelah plafon dinonaktifkan atau
            | diganti barisnya; tanpa kolom ini, mutasi lama kehilangan
            | cabangnya begitu plafon yang melahirkannya pergi.
            */
            $table->unsignedBigInteger('branch_id');

            /*
            | OPENING      saldo awal saat plafon dibuat          (+)
            | DISBURSEMENT petty cash dicairkan ADH               (-)
            | REVERSAL     pencairan dibatalkan                   (+)
            | TOPUP        pengisian kembali disetujui            (+)
            | ADJUSTMENT   plafon diubah atau koreksi manual      (+/-)
            */
            $table->string('entry_type', 20);

            /*
            | Bertanda. Pengurangan disimpan negatif, bukan positif dengan
            | kolom arah terpisah: saldo jadi satu SUM tanpa syarat, dan tidak
            | ada kemungkinan baris bernilai positif yang arahnya terbaca
            | keliru.
            */
            $table->decimal('amount', 18, 2);

            /*
            | Dokumen penyebabnya, bila ada. Baris OPENING dan ADJUSTMENT tidak
            | punya dokumen, jadi keduanya nullable.
            |
            | Tidak dibuat foreign key: buku ini harus tetap utuh dan terbaca
            | walaupun dokumennya kelak dihapus. Jejak uang yang keluar tidak
            | boleh ikut hilang bersama formulirnya.
            */
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('notes')->nullable();

            /*
            | Saldo sesudah baris ini, dibekukan saat pencatatan.
            |
            | Bukan sumber kebenaran -- saldo yang berlaku tetap hasil SUM.
            | Gunanya untuk pemeriksaan: kalau suatu hari jumlahnya tidak cocok
            | dengan deretan angka ini, baris tempat melencengnya langsung
            | ketemu tanpa menghitung ulang seluruh riwayat dengan tangan.
            */
            $table->decimal('balance_after', 18, 2);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->foreign('petty_cash_ceiling_id')
                ->references('id')
                ->on('petty_cash_ceilings')
                ->cascadeOnDelete();

            $table->index(['branch_id', 'id']);
            $table->index(['petty_cash_ceiling_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement("COMMENT ON COLUMN petty_cash_ledgers.amount IS 'Bertanda: pengurangan negatif, penambahan positif. Saldo = SUM kolom ini'");
        DB::statement("COMMENT ON COLUMN petty_cash_ledgers.balance_after IS 'Saldo sesudah baris ini. Untuk pemeriksaan, bukan sumber kebenaran'");
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_ledgers');
        Schema::dropIfExists('petty_cash_ceilings');
    }
};
