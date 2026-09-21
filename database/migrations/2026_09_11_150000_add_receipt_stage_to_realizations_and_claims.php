<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Tahap "Diterima" pada Realisasi dan Claim
|--------------------------------------------------------------------------
| Menyamakan keduanya dengan FPU, yang lebih dulu memakai tahap ini:
|
|   Realisasi : APPROVED -> RECEIVED -> SETTLED
|   Claim     : APPROVED -> RECEIVED -> PAID
|
| Pemegang permission .receive menandai bahwa berkasnya sudah sampai dan siap
| diproses; baru setelah itu selisihnya diselesaikan atau uangnya dibayarkan.
|
| Kolomnya mengikuti pola yang sudah ada di ketiga modul (settled_by/paid_by
| dan seterusnya) supaya terbaca sebagai satu keluarga.
|
| Status disimpan sebagai teks tanpa CHECK constraint, jadi nilai RECEIVED
| tidak memerlukan perubahan struktur apa pun.
|--------------------------------------------------------------------------
*/
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cash_advance_realizations', 'claims'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table): void {
                $table->unsignedBigInteger('received_by')->nullable()->after('cancellation_notes');
                $table->timestamp('received_at')->nullable()->after('received_by');
                $table->text('receipt_notes')->nullable()->after('received_at');
            });
        }

        $keterangan = [
            'cash_advance_realizations' => [
                'permission' => 'cash_advance_realization.receive',
                'lanjutan' => 'diselesaikan selisihnya',
            ],
            'claims' => [
                'permission' => 'claim.receive',
                'lanjutan' => 'dibayarkan',
            ],
        ];

        foreach ($keterangan as $tabel => $teks) {
            DB::statement("COMMENT ON COLUMN {$tabel}.received_by IS 'User pemegang permission {$teks['permission']} yang menandai dokumen diterima'");
            DB::statement("COMMENT ON COLUMN {$tabel}.received_at IS 'Waktu dokumen ditandai diterima, sebelum {$teks['lanjutan']}'");
            DB::statement("COMMENT ON COLUMN {$tabel}.receipt_notes IS 'Catatan opsional saat dokumen ditandai diterima'");
        }

        /*
        | Dokumen yang sudah tuntas sebelum tahap ini ada tidak punya jejak
        | penerimaan. Diisi dari data tahap penutupnya supaya riwayatnya tetap
        | utuh -- tanpa ini akan ada dokumen selesai yang seolah melewati tahap
        | penerimaan begitu saja.
        |
        | Dokumen yang masih APPROVED sengaja TIDAK disentuh: dokumen itu
        | memang belum diterima siapa pun, dan sekarang harus melewati tahap
        | ini lebih dulu.
        */
        $catatan = 'Terisi otomatis: dokumen ini selesai sebelum tahap penerimaan diberlakukan.';

        DB::table('cash_advance_realizations')
            ->where('status', 'SETTLED')
            ->whereNull('received_at')
            ->update([
                'received_by' => DB::raw('settled_by'),
                'received_at' => DB::raw('settled_at'),
                'receipt_notes' => $catatan,
            ]);

        DB::table('claims')
            ->where('status', 'PAID')
            ->whereNull('received_at')
            ->update([
                'received_by' => DB::raw('paid_by'),
                'received_at' => DB::raw('paid_at'),
                'receipt_notes' => $catatan,
            ]);
    }

    public function down(): void
    {
        /*
        | Dokumen yang sedang berada di tahap penerimaan dikembalikan ke
        | approved -- tanpa kolomnya, status RECEIVED tidak punya arti.
        */
        foreach (['cash_advance_realizations', 'claims'] as $tabel) {
            DB::table($tabel)
                ->where('status', 'RECEIVED')
                ->update(['status' => 'APPROVED']);

            Schema::table($tabel, function (Blueprint $table): void {
                $table->dropColumn(['received_by', 'received_at', 'receipt_notes']);
            });
        }
    }
};
