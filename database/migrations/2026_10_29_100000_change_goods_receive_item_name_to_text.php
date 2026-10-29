<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Nama item Goods Receive dan Goods Return dilebarkan menjadi text
|--------------------------------------------------------------------------
| purchase_request_items.nama_item dan purchase_order_items.nama_item sudah
| bertipe text sejak awal, jadi PR dan PO menerima nama item sepanjang apa
| pun. Dua tabel turunannya tertinggal di varchar(255):
|
|   goods_receive_items.nama_item
|   goods_return_items.nama_item
|
| Nama item di kedua tabel itu DISALIN dari PO (lihat GoodsReturnController:
| nama_item <- goodsReceiveItem->nama_item ?? purchaseOrderItem->nama_item),
| bukan diketik ulang. Artinya panjangnya ditentukan oleh PO, dan batas 255
| yang lebih sempit daripada sumbernya pasti pecah suatu saat.
|
| Dan memang pecah -- spesifikasi laptop sepanjang 280-an karakter yang
| tersimpan rapi di PO menolak masuk saat di-GR:
|
|   SQLSTATE[22001]: String data, right truncated:
|   value too long for type character varying(255)
|
| Pengajuannya gagal seluruhnya, bukan terpotong, jadi tidak ada data yang
| terlanjur cacat. Goods Return ikut dilebarkan di sini walaupun belum ada
| yang melaporkannya: ia menyalin dari tabel yang sama, jadi galat yang
| persis sama menunggu di sana.
|
| TIDAK ADA DATA YANG BERUBAH. varchar(255) -> text di PostgreSQL adalah
| pelebaran murni: isi lama tetap apa adanya, index tetap terpakai, dan
| tidak ada penulisan ulang tabel.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    /** @var array<int, string> */
    private const TABEL = ['goods_receive_items', 'goods_return_items'];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            if (!Schema::hasColumn($tabel, 'nama_item')) {
                continue;
            }

            DB::statement("ALTER TABLE {$tabel} ALTER COLUMN nama_item TYPE text");
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            if (!Schema::hasColumn($tabel, 'nama_item')) {
                continue;
            }

            /*
            | Menyempitkan kembali hanya aman selama belum ada nama item yang
            | melewati 255 karakter. Kalau sudah ada, pengembalian ini DILEWATI
            | -- memaksakannya berarti PostgreSQL menolak di tengah jalan, atau
            | lebih buruk, data terpotong diam-diam.
            */
            $terpanjang = (int) DB::table($tabel)->max(DB::raw('length(nama_item)'));

            if ($terpanjang > 255) {
                continue;
            }

            DB::statement("ALTER TABLE {$tabel} ALTER COLUMN nama_item TYPE varchar(255)");
        }
    }
};
