<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Tempat menyimpan isian BDV yang selama ini hanya hidup di layar
|--------------------------------------------------------------------------
| Formulir BDV sudah menanyakan empat hal yang tidak punya tempat di basis
| data: Kas/Bank, nomor cek, penerima, dan akun Accurate untuk setiap baris
| rincian. Selama itu belum ada, store dan update tidak mungkin dibuat --
| bukan karena belum sempat, tetapi karena tidak ada yang bisa ditulis.
|
| AKUNNYA DISIMPAN SEBAGAI NOMOR AKUN, BUKAN ID.
|
| Yang dipegang layar sebagai "id" sebenarnya nomor akun Accurate:
| AccurateController memetakan 'id' => $item['no'] saat menyusun pilihannya.
| Accurate memang juga punya id internalnya sendiri, dan ia ikut diminta pada
| daftar fields -- hanya saja tidak pernah diteruskan ke layar. Menamai kolom
| ini "..._id" akan membuat dua hal yang berbeda terbaca sebagai satu, dan
| yang membacanya setahun lagi tidak punya cara menebak mana yang tersimpan.
|
| NAMA AKUNNYA IKUT DISIMPAN, dan itu bukan penggandaan data.
|
| Akun hidup di sistem lain. Ia bisa berganti nama, bisa dinonaktifkan, dan
| Accurate bisa sedang tidak bisa dihubungi saat voucher ini dicetak. Voucher
| yang sudah disetujui harus tetap menyebut akun yang dulu dipilih, dengan
| nama yang dulu terbaca -- bukan menampilkan kolom kosong karena sumbernya
| sedang diam.
|
| RINCIANNYA DISALIN, BUKAN SEKADAR DIPETAKAN.
|
| Yang disimpan bukan hanya "baris nomor 3 memakai akun X", melainkan seluruh
| isi barisnya. Alasannya: nilai dokumen sumber masih bisa direvisi saat
| berkasnya diterima Finance -- halaman buat bahkan sudah memperingatkan
| selisihnya. Bila yang disimpan hanya nomor barisnya, voucher yang sudah
| disetujui akan diam-diam berubah isinya mengikuti dokumen yang bergerak di
| belakangnya. Voucher adalah catatan apa yang dicairkan pada saat itu.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_disbursement_vouchers', function (Blueprint $table): void {
            /*
            | Nomor akun Accurate, bukan id internalnya. Panjang 50 dengan
            | kelonggaran: nomor akun bersegmen seperti "1101.01.001" masih
            | jauh di bawahnya.
            */
            $table->string('cash_bank_account_no', 50)->nullable();
            $table->string('cash_bank_account_name')->nullable();

            $table->string('check_number', 50)->nullable();
            $table->string('payee')->nullable();
        });

        Schema::create('bdv_items', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('bdv_id');

            /*
            | Nomor baris sebagaimana tertera pada dokumen sumbernya, bukan
            | nomor urut yang dihitung ulang di sini. Itulah yang menautkan
            | baris ini kembali ke asalnya bila suatu saat perlu ditelusuri.
            */
            $table->unsignedSmallInteger('line_no');

            /* Salinan baris dokumen sumber pada saat voucher dibuat. */
            $table->date('item_date')->nullable();
            $table->text('description')->nullable();
            $table->string('category')->nullable();

            /* Hanya terisi pada item FPU; item Claim tidak memakai keduanya. */
            $table->decimal('qty', 15, 2)->nullable();
            $table->decimal('unit_price', 20, 2)->nullable();

            $table->decimal('amount', 18, 2)->default(0);

            /*
            | Satu-satunya isian manusia di tabel ini. Sisanya salinan.
            |
            | Dibiarkan nullable supaya draft bisa disimpan setengah jadi --
            | orang yang mengisi dua puluh baris tidak boleh kehilangan
            | semuanya hanya karena baris terakhir belum sempat dipilih.
            | Kelengkapannya diperiksa saat diajukan, bukan saat disimpan.
            */
            $table->string('accurate_account_no', 50)->nullable();
            $table->string('accurate_account_name')->nullable();

            $table->timestamps();

            $table->foreign('bdv_id')
                ->references('id')
                ->on('bank_disbursement_vouchers')
                ->cascadeOnDelete();

            /*
            | Satu baris dokumen hanya boleh muncul sekali pada satu voucher.
            | Tanpa ini, penyimpanan yang terkirim dua kali -- tombol diklik
            | ganda, atau permintaan yang diulang jaringan -- melahirkan
            | rincian kembar yang jumlahnya mendadak dua kali lipat.
            */
            $table->unique(['bdv_id', 'line_no'], 'bdv_items_bdv_line_unique');

            $table->index('accurate_account_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bdv_items');

        Schema::table('bank_disbursement_vouchers', function (Blueprint $table): void {
            $table->dropColumn([
                'cash_bank_account_no',
                'cash_bank_account_name',
                'check_number',
                'payee',
            ]);
        });
    }
};
