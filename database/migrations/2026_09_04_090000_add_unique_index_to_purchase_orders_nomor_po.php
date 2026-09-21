<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Indeks unik nomor Purchase Order
|--------------------------------------------------------------------------
| purchase_orders satu-satunya tabel dokumen yang nomornya tidak dijaga
| indeks unik. Akibatnya sudah terjadi: sembilan PO memakai nomor draft yang
| kembar (DRAFT/PO/2026/0001 empat kali, 0002 lima kali) tanpa ada yang
| menghentikannya. Semuanya lahir dari dua permintaan yang berjalan
| bersamaan -- persis celah yang baru saja ditutup oleh kunci deret.
|
| Indeksnya dibuat PARSIAL, hanya berlaku untuk baris yang belum dihapus.
| Alasannya: seluruh nomor kembar yang ada saat ini berada pada dokumen yang
| sudah di-soft delete. Indeks penuh akan menolak dibuat sampai riwayat itu
| diubah, dan mengubah riwayat demi memasang indeks bukan pertukaran yang
| sepadan. Yang perlu dijaga adalah dokumen yang masih hidup.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    private const INDEX_NAME = 'purchase_orders_nomor_po_active_unique';

    public function up(): void
    {
        /*
        | Diperiksa lebih dulu supaya migrasinya berhenti dengan pesan yang
        | jelas, bukan dengan galat indeks yang sulit ditelusuri.
        */
        $kembar = DB::table('purchase_orders')
            ->whereNull('deleted_at')
            ->whereNotNull('nomor_po')
            ->select('nomor_po')
            ->groupBy('nomor_po')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('nomor_po');

        if ($kembar->isNotEmpty()) {
            throw new RuntimeException(
                'Masih ada nomor PO kembar pada dokumen aktif, indeks unik tidak bisa dipasang: '
                . $kembar->implode(', '),
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS ' . self::INDEX_NAME
            . ' ON purchase_orders (nomor_po) WHERE deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::INDEX_NAME);
    }
};
