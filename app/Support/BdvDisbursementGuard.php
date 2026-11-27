<?php

namespace App\Support;

use App\Models\BdvSource;

/*
|--------------------------------------------------------------------------
| Uang tidak keluar sebelum vouchernya terbit
|--------------------------------------------------------------------------
| FPU dicairkan dan Claim dibayarkan HANYA bila sudah ada Bank Disbursement
| Voucher yang mengangkatnya.
|
| Sebabnya bukan tata tertib belaka. BDV-lah dokumen pencairannya: ia yang
| ditandatangani, yang dicatat ke Accurate, dan yang menjadi dasar bank
| mengeluarkan uang. Menandai FPU "dicairkan" lebih dulu membalik urutannya
| -- dan karena dokumen yang sudah DISBURSED/PAID keluar dari daftar yang
| layak diangkat, pintunya ikut tertutup: vouchernya tidak akan pernah bisa
| dibuat, sementara di pembukuan uangnya sudah dinyatakan keluar.
|
| Itu bukan dugaan. Persis itu yang terjadi saat FPU/HO/IT/26/XI/003 ditandai
| cair delapan detik setelah diterima, lalu tidak bisa lagi diangkat BDV.
|
|--------------------------------------------------------------------------
| YANG DIANGGAP "SUDAH ADA VOUCHERNYA"
|--------------------------------------------------------------------------
| Satu baris hidup di bdv_sources, yaitu released_at masih kosong.
|
| Nisan itu diisi ketika vouchernya dihapus, ditolak, atau dibatalkan --
| ketiganya berarti voucher itu tidak jadi mencairkan apa pun, jadi
| dokumennya kembali telanjang dan pencairannya harus ditahan lagi.
|
| Statusnya sendiri TIDAK diperiksa di sini: voucher berstatus draft pun
| dianggap cukup, sesuai permintaan. Bila kelak hendak diperketat menjadi
| "harus sudah disetujui penuh", yang diubah hanya kueri di bawah -- bukan
| pemanggilnya.
|--------------------------------------------------------------------------
*/
class BdvDisbursementGuard
{
    /**
     * Alasan pencairan harus ditahan -- null berarti boleh jalan.
     *
     * @param  string  $documentType  kode jenis dokumen di config('bdv'), mis. FPU
     * @param  string  $pesan         kunci terjemahan yang dipakai modul pemanggil
     */
    public static function blocking(string $documentType, int $sourceId, string $pesan): ?string
    {
        /*
        | Penjaganya bisa dimatikan lewat config.
        |
        | Bukan keragu-raguan: aturan ini menahan SELURUH pencairan di
        | perusahaan, dan bila BDV belum dipakai Finance, menyalakannya
        | berarti menghentikan pembayaran sampai ada yang menerbitkan
        | voucher. Tombol matinya harus ada di tempat yang bisa dicapai
        | tanpa menunggu rilis kode.
        */
        if (!config('bdv.require_voucher_before_disbursement', true)) {
            return null;
        }

        $adaVoucher = BdvSource::query()
            ->where('document_type', $documentType)
            ->where('source_id', $sourceId)
            ->whereNull('released_at')
            ->exists();

        return $adaVoucher ? null : __($pesan);
    }
}
