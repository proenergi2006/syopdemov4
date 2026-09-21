<?php

namespace Database\Seeders;

use App\Models\FundRequestTransactionCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Master Keterangan Transaksi untuk modul Pengajuan Dana
|--------------------------------------------------------------------------
| Satu tabel menampung dua daftar yang berbeda, dibedakan document_type:
|
|   ADVANCE : jenis transaksi FPU, penentu approval flow.
|   CLAIM   : jenis transaksi Claim, lengkap dengan batasan dan dokumen
|             pendukung wajibnya.
|
| Kolom yang tidak berlaku pada suatu modul dibiarkan null -- baris FPU tidak
| memiliki "Dapat Diklaim?" maupun dokumen pendukung wajib.
|
| Kode baris Claim diberi awalan CLM_ karena kolom code unik untuk seluruh
| tabel, bukan per modul. Tanpa awalan itu, jenis transaksi bernama sama pada
| dua modul akan saling menutup.
|
| Aman dijalankan berulang karena memakai updateOrInsert dengan code sebagai
| kunci.
|--------------------------------------------------------------------------
*/
class FundRequestTransactionCategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $this->seedAdvanceCategories($now);
        $this->seedClaimCategories($now);
    }

    private function seedAdvanceCategories($now): void
    {
        $categories = [
            ['TELPON', 'Telpon'],
            ['LISTRIK', 'Listrik'],
            ['PANTRY', 'Pantry'],
            ['LEGALITAS_KENDARAAN', 'Legalitas Kendaraan'],
            ['UPL', 'UPL'],
            ['STATIONARY', 'Stationary'],
            ['SERVICE_KENDARAAN', 'Service Kendaraan Operasional/Truk'],
            ['ENTERTAIN', 'Entertain'],
            ['SUMBANGAN', 'Sumbangan'],
            ['PARCEL', 'Parcel'],
            ['OPERASIONAL_KANTOR', 'Operasional Kantor'],
            ['PERDIN', 'Perdin (Exclude Tiket & Hotel)'],
        ];

        foreach ($categories as $index => [$code, $name]) {
            $this->simpan($code, [
                'document_type' => FundRequestTransactionCategory::DOCUMENT_TYPE_ADVANCE,
                'name' => $name,
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
                'updated_at' => $now,
            ], $now);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Jenis transaksi Claim
    |--------------------------------------------------------------------------
    | Isi kolomnya mengikuti matriks Claim:
    |
    |   description        <- "Batasan / Ketentuan"
    |   claimable_status   <- "Dapat Diklaim?"
    |   required_documents <- "Dokumen Pendukung Wajib"
    |--------------------------------------------------------------------------
    */
    private function seedClaimCategories($now): void
    {
        $ya = FundRequestTransactionCategory::CLAIMABLE_YES;
        $terbatas = FundRequestTransactionCategory::CLAIMABLE_LIMITED;
        $kebijakan = FundRequestTransactionCategory::CLAIMABLE_BY_POLICY;

        $categories = [
            [
                'CLM_TOL_PARKIR',
                'Tol dan parkir',
                $ya,
                'Terkait perjalanan dinas/operasional perusahaan dan didukung bukti',
                'Struk parkir / mutasi e-toll, tanggal, tujuan perjalanan',
            ],
            [
                'CLM_TRANSPORTASI_ONLINE',
                'Transportasi online/taksi',
                $ya,
                'Untuk kepentingan perusahaan, meeting, perjalanan dinas, atau operasional',
                'E-receipt aplikasi, titik jemput & tujuan, keperluan',
            ],
            [
                'CLM_BBM_OPERASIONAL',
                'BBM kendaraan operasional',
                $ya,
                'Jika tidak tersedia fasilitas pembayaran perusahaan atau kondisi mendesak',
                'Struk SPBU, nomor polisi, KM kendaraan, alasan tidak pakai fasilitas',
            ],
            [
                'CLM_JAMUAN_EXTERNAL',
                'Jamuan external (customer/vendor/bank/konsultan)',
                $ya,
                'Harus memiliki kepentingan bisnis yang jelas dan sesuai limit kewenangan',
                'Struk, nama & perusahaan tamu, tujuan jamuan',
            ],
            [
                'CLM_PENGIRIMAN_DOKUMEN',
                'Biaya pengiriman dokumen/kurir',
                $ya,
                'Untuk kebutuhan operasional dan nilainya relatif kecil',
                'Resi pengiriman, keterangan dokumen & tujuan',
            ],
            [
                'CLM_MATERAI',
                'Materai',
                $ya,
                'Untuk kebutuhan dokumen perusahaan',
                'Struk/kuitansi, keterangan dokumen',
            ],
            [
                'CLM_FOTOKOPI_PRINT',
                'Fotokopi, print, jilid dokumen',
                $ya,
                'Jika sifatnya insidental',
                'Struk/kuitansi, keterangan dokumen',
            ],
            [
                'CLM_ATK_MENDESAK',
                'ATK kecil dan mendesak',
                $ya,
                'Hanya jika tidak tersedia di kantor dan dibutuhkan segera',
                'Struk, keterangan urgensi, konfirmasi stok kosong dari GA',
            ],
            [
                'CLM_ADMINISTRASI_INSTANSI',
                'Biaya administrasi instansi',
                $ya,
                'Misalnya legalisasi, administrasi pengadilan, notaris kecil, atau instansi pemerintah jika dibayar langsung oleh karyawan',
                'Kuitansi resmi instansi, keterangan pengurusan',
            ],
            [
                'CLM_PERLENGKAPAN_KECIL',
                'Pembelian perlengkapan operasional kecil',
                $terbatas,
                'Kondisi mendesak dan nilainya di bawah limit klaim',
                'Struk/nota, keterangan urgensi, foto barang bila perlu',
            ],
            [
                'CLM_SPAREPART_DARURAT',
                'Sparepart kecil unit untuk perbaikan operasional darurat',
                $terbatas,
                'Hanya untuk mencegah gangguan operasional dan harus dijelaskan kondisi daruratnya',
                'Nota bengkel/toko, nomor polisi, foto kondisi, laporan sopir',
            ],
            [
                'CLM_PERBAIKAN_RINGAN',
                'Biaya tambal ban/cuci kendaraan/perbaikan ringan',
                $ya,
                'Jika berkaitan dengan kendaraan operasional dan bersifat insidental',
                'Nota, nomor polisi, tanggal',
            ],
            [
                'CLM_KOMUNIKASI',
                'Biaya komunikasi/pulsa/data',
                $kebijakan,
                'Jika memang diberikan sebagai fasilitas atau timbul karena tugas khusus',
                'Bukti top-up, nomor telepon operasional',
            ],
            [
                'CLM_EMERGENCY_PURCHASE',
                'Emergency purchase',
                $ya,
                'Kondisi benar-benar mendesak dan tidak memungkinkan proses normal',
                'Struk/nota, form justifikasi darurat, persetujuan lisan terdokumentasi',
            ],
        ];

        foreach ($categories as $index => [$code, $name, $claimable, $description, $documents]) {
            $this->simpan($code, [
                'document_type' => FundRequestTransactionCategory::DOCUMENT_TYPE_CLAIM,
                'name' => $name,
                'description' => $description,
                'claimable_status' => $claimable,
                'required_documents' => $documents,
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
                'updated_at' => $now,
            ], $now);
        }
    }

    private function simpan(string $code, array $nilai, $now): void
    {
        DB::table('fund_request_transaction_categories')->updateOrInsert(
            ['code' => $code],
            $nilai,
        );

        DB::table('fund_request_transaction_categories')
            ->where('code', $code)
            ->whereNull('created_at')
            ->update(['created_at' => $now]);
    }
}
