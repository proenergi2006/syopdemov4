<?php

namespace App\Services\Bdv;

use App\Models\BankDisbursementVoucher;
use App\Models\BdvAttachmentUpload;
use App\Models\BdvSource;
use App\Services\AccurateApiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Mengunggah bukti BDV ke Accurate
|--------------------------------------------------------------------------
| Endpoint attachment/attach.do, dengan transactionType "PAY" dan
| transactionId berisi id dokumen Other Payment-nya. Berkasnya dikirim
| sebagai Base64 di dalam body JSON -- BUKAN multipart upload.
|
| Yang diunggah adalah bukti yang menempel pada BARIS RINCIAN dokumen
| sumbernya, himpunan yang sama persis dengan yang tampil di layar. Satu
| tempat yang memutuskannya: BdvSourceRegistry::lampiranBaris().
|
|--------------------------------------------------------------------------
| TIDAK PERNAH MENGGAGALKAN PENYIMPANAN
|--------------------------------------------------------------------------
| Dipanggil SESUDAH dokumennya diterima Accurate. Di titik itu uangnya sudah
| tercatat di pembukuan, dan melempar pengecualian karena sebuah berkas
| gagal terkirim akan menampilkan "gagal" untuk sesuatu yang sebenarnya
| berhasil -- lalu orangnya menekan Simpan lagi, dan lagi.
|
| Karena itu seluruh kegagalan dikembalikan sebagai LAPORAN, bukan
| dilemparkan. Pemanggilnya yang memutuskan bagaimana menyampaikannya.
|
|--------------------------------------------------------------------------
| MENGIRIM ULANG DITAHAN, DAN ITU PENTING
|--------------------------------------------------------------------------
| Menyimpan BDV berarti mengirim ulang dokumennya ke Accurate, dan orang
| memang menyimpan berkali-kali. Tanpa catatan bdv_attachment_uploads,
| setiap simpan melampirkan ulang berkas yang sama sampai jatah 30 lampiran
| per transaksi habis oleh salinan dari berkas yang itu-itu juga.
|
| Sebaliknya, yang GAGAL tidak dicatat -- menekan Simpan sekali lagi adalah
| cara menyelesaikannya.
|
|--------------------------------------------------------------------------
| BATASNYA DIPERIKSA LEBIH DULU, BUKAN DISERAHKAN KE ACCURATE
|--------------------------------------------------------------------------
| Ukuran, jenis berkas, dan jumlah maksimum diperiksa di sini sebelum
| apa pun dikirim. Bukan ketidakpercayaan pada Accurate: berkas 20 MB yang
| dikirim hanya untuk ditolak menghabiskan waktu orang yang menunggu DAN
| satu jatah permintaan per detik, sementara alasan penolakannya sudah bisa
| diketahui tanpa mengirim apa pun.
|
| Yang disaring tetap dilaporkan dengan sebabnya, bukan didiamkan. Bukti
| yang hilang tanpa keterangan adalah bukti yang baru ketahuan hilang saat
| diperiksa auditor.
|--------------------------------------------------------------------------
*/
class BdvAttachmentUploadService
{
    private const ENDPOINT = '/accurate/api/attachment/attach.do';

    private const ENDPOINT_HAPUS = '/accurate/api/attachment/delete.do';

    public function __construct(private BdvSourceRegistry $registry)
    {
    }

    /*
    |--------------------------------------------------------------------------
    | Menyelaraskan bukti voucher ini dengan yang ada di Accurate
    |--------------------------------------------------------------------------
    | Dua arah, dan dihitung sebagai SELISIH -- bukan "hapus semuanya lalu
    | unggah ulang".
    |
    |   yang harus ada  = seluruh bukti baris dari dokumen yang MASIH terpasang
    |   yang tercatat   = isi bdv_attachment_uploads
    |
    |   dihapus  = tercatat - harus ada
    |   diunggah = harus ada - tercatat
    |
    | Kenapa bukan hapus-semua-lalu-unggah-ulang, walau hasil akhirnya sama:
    | penghapusan di Accurate TIDAK BISA DITARIK KEMBALI dari sini. Kalau
    | penghapusannya berhasil lalu unggah ulangnya gagal -- jaringan putus,
    | jatah permintaan per detik habis, satu berkas melampaui batas -- bukti
    | yang tadinya sudah sah ada di sana menjadi hilang, dan pemicunya cuma
    | mengganti satu dokumen dari tiga. Selisih tidak pernah menghapus sesuatu
    | yang semenit kemudian ia unggah lagi.
    |
    | Satu aturan ini sekaligus menangani tiga keadaan yang kelihatannya
    | berbeda: dokumen diganti, dokumen dikeluarkan, dan bukti di dokumen
    | sumbernya diunggah ulang (barisnya baru, id-nya berganti).
    |
    | URUTANNYA: hapus dulu, baru unggah. Yang dihapus memang sudah tidak
    | boleh ada di sana, jadi tidak ada yang hilang bila langkah berikutnya
    | gagal -- sementara mendahulukan unggahan bisa menabrak batas 30 lampiran
    | oleh berkas yang justru sedang menunggu dibuang.
    |
    | @return array{terkirim: int, sudah_ada: int, dihapus: int, gagal: array<int, array{nama: string, alasan: string}>, gagal_hapus: array<int, array{nama: string, alasan: string}>}
    |--------------------------------------------------------------------------
    */
    public function selaraskan(BankDisbursementVoucher $bdv): array
    {
        $laporan = [
            'terkirim' => 0,
            'sudah_ada' => 0,
            'dihapus' => 0,

            /* Gagal DIUNGGAH -- buktinya belum sampai Accurate. */
            'gagal' => [],

            /*
            | Gagal DIHAPUS -- kebalikannya: buktinya masih di sana padahal
            | sudah tidak lagi milik voucher ini. Dipisahkan karena kalimat
            | yang menyampaikannya pun berlawanan, dan menggabungkan keduanya
            | menghasilkan kabar yang menyesatkan: "belum sampai Accurate"
            | untuk berkas yang justru tidak mau pergi dari sana.
            */
            'gagal_hapus' => [],
        ];

        if (!$bdv->accurate_id) {
            /*
            | Tidak ada tempat untuk melampirkannya. Bukan kegagalan: voucher
            | yang belum sampai Accurate memang belum punya dokumen di sana,
            | dan pemanggilnya sudah melaporkan hal itu sendiri.
            */
            return $laporan;
        }

        $seharusnya = $this->berkasVoucher($bdv);

        $kunciSeharusnya = collect($seharusnya)
            ->map(fn (array $b): string => $b['document_type'] . ':' . $b['attachment_id'])
            ->flip();

        $this->buangYangTertinggal($bdv, $kunciSeharusnya->all(), $laporan);

        /* Dibaca ULANG: baris yang barusan dihapus tidak boleh ikut terhitung. */
        $sudah = BdvAttachmentUpload::where('bdv_id', $bdv->id)
            ->get()
            ->keyBy(fn (BdvAttachmentUpload $u): string => $u->document_type . ':' . $u->attachment_id);

        $laporan['sudah_ada'] = $sudah->count();

        $terpakai = $sudah->count();
        $batasJumlah = (int) config('bdv.attachment.max_files', 30);

        foreach ($seharusnya as $berkas) {
            $kunci = $berkas['document_type'] . ':' . $berkas['attachment_id'];

            if ($sudah->has($kunci)) {
                continue;
            }

            if ($terpakai >= $batasJumlah) {
                $laporan['gagal'][] = [
                    'nama' => $berkas['nama'],
                    'alasan' => __('bdv_messages.attachment_too_many', ['max' => $batasJumlah]),
                ];

                continue;
            }

            $salah = $this->alasanTidakLayak($berkas);

            if ($salah !== null) {
                $laporan['gagal'][] = ['nama' => $berkas['nama'], 'alasan' => $salah];

                continue;
            }

            $hasil = $this->kirimSatu($bdv, $berkas);

            if ($hasil !== true) {
                $laporan['gagal'][] = ['nama' => $berkas['nama'], 'alasan' => $hasil];

                continue;
            }

            $laporan['terkirim']++;
            $terpakai++;
        }

        $adaKabar = $laporan['terkirim'] > 0 || $laporan['dihapus'] > 0
            || $laporan['gagal'] !== [] || $laporan['gagal_hapus'] !== [];

        if ($adaKabar) {
            Log::info('[BDV] Penyelarasan bukti dengan Accurate selesai', [
                'bdv_id' => $bdv->id,
                'bdv_number' => $bdv->bdv_number,
                'accurate_id' => $bdv->accurate_id,
                'terkirim' => $laporan['terkirim'],
                'dihapus' => $laporan['dihapus'],
                'sudah_ada' => $laporan['sudah_ada'],
                'gagal' => $laporan['gagal'],
                'gagal_hapus' => $laporan['gagal_hapus'],
            ]);
        }

        return $laporan;
    }

    /*
    |--------------------------------------------------------------------------
    | Bukti yang sudah tidak lagi milik voucher ini, dibuang dari Accurate
    |--------------------------------------------------------------------------
    | Dipanggil ketika dokumen sumbernya diganti, dikeluarkan, atau buktinya
    | diunggah ulang di dokumen asalnya. Ketiganya berujung sama: ada catatan
    | unggahan yang tidak punya padanan lagi pada daftar bukti voucher ini.
    |
    | KEGAGALAN MENGHAPUS TIDAK MENGHAPUS CATATANNYA.
    |
    | Itu disengaja, dan sebabnya sama dengan nisan pada baris rincian:
    | catatan inilah satu-satunya yang tahu berkas itu ada di Accurate dan
    | milik voucher ini. Membuangnya saat penghapusan gagal berarti berkasnya
    | tinggal di sana selamanya tanpa ada yang bisa menemukannya lagi.
    | Dengan catatannya utuh, menekan Simpan sekali lagi mengulang
    | penghapusan yang tertunda.
    |
    | @param  array<string, mixed>  $kunciSeharusnya
    | @param  array<string, mixed>  $laporan
    |--------------------------------------------------------------------------
    */
    private function buangYangTertinggal(
        BankDisbursementVoucher $bdv,
        array $kunciSeharusnya,
        array &$laporan,
    ): void {
        $tertinggal = BdvAttachmentUpload::where('bdv_id', $bdv->id)
            ->get()
            ->reject(fn (BdvAttachmentUpload $u): bool => array_key_exists(
                $u->document_type . ':' . $u->attachment_id,
                $kunciSeharusnya,
            ));

        foreach ($tertinggal as $catatan) {
            $nama = (string) ($catatan->filename ?: $catatan->attachment_id);

            /*
            | Tanpa id dari Accurate tidak ada yang bisa disebut pada
            | delete.do. Catatannya dibuang -- ia memang bukan lagi milik
            | voucher ini -- tetapi orangnya DIBERI TAHU bahwa berkasnya
            | tinggal di sana dan harus dicabut manual. Didiamkan, lampiran
            | itu menjadi bukti yatim yang menempel pada pembayaran yang
            | sudah tidak menjelaskannya.
            */
            if (!$catatan->accurate_id) {
                Log::warning('[BDV] Bukti tertinggal di Accurate tanpa id -- tidak bisa dihapus', [
                    'bdv_id' => $bdv->id,
                    'bdv_number' => $bdv->bdv_number,
                    'accurate_id' => $bdv->accurate_id,
                    'berkas' => $nama,
                ]);

                $laporan['gagal_hapus'][] = [
                    'nama' => $nama,
                    'alasan' => __('bdv_messages.attachment_no_remote_id'),
                ];

                $catatan->delete();

                continue;
            }

            $jawaban = app(AccurateApiService::class)->delete(
                rtrim((string) config('services.accurate.base_url'), '/') . self::ENDPOINT_HAPUS,
                ['id' => $catatan->accurate_id],
            );

            if (!is_array($jawaban) || !($jawaban['s'] ?? false)) {
                Log::warning('[BDV] Penghapusan bukti ditolak Accurate', [
                    'bdv_id' => $bdv->id,
                    'accurate_id' => $bdv->accurate_id,
                    'attachment_accurate_id' => $catatan->accurate_id,
                    'berkas' => $nama,
                    'jawaban' => is_array($jawaban) ? ($jawaban['d'] ?? $jawaban) : $jawaban,
                ]);

                $laporan['gagal_hapus'][] = [
                    'nama' => $nama,
                    'alasan' => __('bdv_messages.attachment_delete_failed', [
                        'reason' => rtrim(
                            is_array($jawaban)
                                ? $this->pesanPenolakan($jawaban)
                                : __('bdv_messages.attachment_no_answer'),
                            '.',
                        ),
                    ]),
                ];

                continue;
            }

            $catatan->delete();

            $laporan['dihapus']++;
        }
    }

    /**
     * Berapa bukti yang dipegang voucher ini, dan berapa yang sudah di sana.
     *
     * Dipakai layar untuk mengatakannya terang-terangan pada detail voucher:
     * toast hanya lewat sekali, dan yang perlu tahu bukti sudah sampai atau
     * belum biasanya baru bertanya beberapa hari kemudian.
     *
     * @return array{total: int, terkirim: int}
     */
    public function ringkasan(BankDisbursementVoucher $bdv): array
    {
        $total = count($this->berkasVoucher($bdv));

        return [
            'total' => $total,

            /*
            | Dibatasi $total supaya tidak pernah melampaui penyebutnya.
            | Catatan unggahan BERTAHAN ketika dokumennya dikeluarkan dari
            | voucher -- berkasnya memang sudah telanjur di Accurate -- jadi
            | tanpa batas ini layar bisa menulis "7 dari 4".
            */
            'terkirim' => min(
                $total,
                BdvAttachmentUpload::where('bdv_id', $bdv->id)->count(),
            ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Seluruh bukti yang dipegang voucher ini
    |--------------------------------------------------------------------------
    | Dibaca dari dokumen sumbernya yang MASIH terpasang. Dokumen yang sudah
    | dikeluarkan tidak ikut: buktinya tidak lagi menjelaskan apa pun pada
    | voucher ini.
    |
    | (Yang telanjur terkirim sebelum dokumennya dikeluarkan tetap tinggal di
    | Accurate -- lihat catatan pada BdvController::destroy.)
    |
    | @return array<int, array<string, mixed>>
    |--------------------------------------------------------------------------
    */
    private function berkasVoucher(BankDisbursementVoucher $bdv): array
    {
        $hasil = [];

        $sumber = $bdv->relationLoaded('sources') ? $bdv->sources : $bdv->sources()->get();

        foreach ($sumber as $satu) {
            /** @var BdvSource $satu */
            $kode = (string) $satu->document_type;

            if (!$this->registry->has($kode)) {
                continue;
            }

            $dokumen = $this->registry->cari($kode, (int) $satu->source_id);

            if (!$dokumen) {
                continue;
            }

            foreach ($this->registry->lampiranBaris($kode, $dokumen) as $berkas) {
                $hasil[] = [
                    'document_type' => $kode,
                    'attachment_id' => (int) $berkas->id,

                    /*
                    | Nama aslinya yang dipakai, bukan nama simpanannya: yang
                    | kedua berupa deretan acak hasil unggahan, dan itulah yang
                    | akan terbaca orang akuntansi di Accurate.
                    */
                    'nama' => $this->namaBerkas($berkas),

                    'filepath' => (string) ($berkas->filepath ?? ''),
                    'ukuran' => $berkas->file_size !== null ? (int) $berkas->file_size : null,
                    'nomor_dokumen' => (string) ($satu->source_number ?? ''),
                ];
            }
        }

        return $hasil;
    }

    /**
     * Nama berkas yang dikirim ke Accurate, lengkap dengan ekstensinya.
     *
     * Ekstensi WAJIB ada: Accurate menentukan jenis berkas dari namanya, dan
     * nama tanpa ekstensi ditolak tanpa menyebut sebabnya dengan jelas.
     */
    private function namaBerkas($berkas): string
    {
        foreach ([$berkas->original_filename, $berkas->filename] as $calon) {
            $bersih = trim((string) $calon);

            if ($bersih !== '' && pathinfo($bersih, PATHINFO_EXTENSION) !== '') {
                return mb_substr(basename($bersih), 0, 200);
            }
        }

        /* Tidak ada nama yang berekstensi -- diambil dari jalur simpanannya. */
        $dariJalur = basename((string) ($berkas->filepath ?? ''));

        return $dariJalur !== '' ? mb_substr($dariJalur, 0, 200) : 'lampiran-' . $berkas->id;
    }

    /*
    |--------------------------------------------------------------------------
    | Sebab sebuah berkas tidak layak dikirim -- null berarti layak
    |--------------------------------------------------------------------------
    | Urutan pemeriksaannya disengaja: keberadaan berkas lebih dulu, karena
    | ukuran dan jenis yang dibaca dari catatan basis data tidak berarti apa
    | pun kalau berkasnya sendiri sudah tidak ada di disk.
    |
    | Ukuran dibaca dari DISK, bukan dari kolom file_size. Kolom itu diisi
    | saat berkasnya diunggah dan tidak pernah diperiksa ulang; yang menentukan
    | ditolak atau tidak adalah berkas yang benar-benar dikirim.
    |--------------------------------------------------------------------------
    */
    private function alasanTidakLayak(array $berkas): ?string
    {
        $disk = Storage::disk('public');

        if ($berkas['filepath'] === '' || !$disk->exists($berkas['filepath'])) {
            return __('bdv_messages.attachment_file_missing');
        }

        $ekstensi = strtolower(pathinfo($berkas['nama'], PATHINFO_EXTENSION));

        $boleh = array_map('strtolower', (array) config('bdv.attachment.allowed_extensions', []));

        if ($ekstensi === '' || !in_array($ekstensi, $boleh, true)) {
            return __('bdv_messages.attachment_bad_type', [
                'type' => $ekstensi !== '' ? $ekstensi : '-',
                'allowed' => implode(', ', $boleh),
            ]);
        }

        $batas = (int) config('bdv.attachment.max_file_size', 10 * 1024 * 1024);
        $ukuran = (int) $disk->size($berkas['filepath']);

        if ($ukuran > $batas) {
            return __('bdv_messages.attachment_too_big', [
                'size' => $this->ukuranTerbaca($ukuran),
                'max' => $this->ukuranTerbaca($batas),
            ]);
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Mengirim satu berkas -- true bila berhasil, kalimat alasan bila gagal
    |--------------------------------------------------------------------------
    | Tidak melempar apa pun. Satu berkas yang gagal tidak boleh menghentikan
    | berkas berikutnya: lima bukti yang terkirim dari enam jauh lebih berguna
    | daripada nol karena yang pertama kebetulan rusak.
    |
    | @return true|string
    |--------------------------------------------------------------------------
    */
    private function kirimSatu(BankDisbursementVoucher $bdv, array $berkas)
    {
        try {
            $isi = Storage::disk('public')->get($berkas['filepath']);
        } catch (\Throwable $e) {
            Log::warning('[BDV] Berkas bukti gagal dibaca', [
                'bdv_id' => $bdv->id,
                'filepath' => $berkas['filepath'],
                'message' => $e->getMessage(),
            ]);

            return __('bdv_messages.attachment_unreadable');
        }

        if ($isi === null || $isi === '') {
            return __('bdv_messages.attachment_unreadable');
        }

        $muatan = [
            'contentBase64' => base64_encode($isi),
            'name' => $berkas['nama'],
            'transactionType' => (string) config('bdv.attachment.transaction_type', 'PAY'),

            /*
            | Accurate memberi contoh transactionId berupa ANGKA, sedangkan
            | accurate_id disimpan sebagai teks supaya id panjang tidak
            | kehilangan ketelitiannya. Dikembalikan ke angka di sini, dan
            | hanya di sini.
            */
            'transactionId' => (int) $bdv->accurate_id,
        ];

        $keterangan = $this->keteranganBerkas($berkas);

        if ($keterangan !== null) {
            $muatan['description'] = $keterangan;
        }

        $jawaban = app(AccurateApiService::class)->post(
            rtrim((string) config('services.accurate.base_url'), '/') . self::ENDPOINT,
            $muatan,
            (int) config('bdv.attachment.timeout', 120),
        );

        /*
        | Jawaban yang bukan JSON sama sekali -- badan kosong, halaman galat
        | server, sambungan putus -- sampai di sini sebagai null. Dibedakan
        | dari penolakan yang bisa dibaca supaya yang terbaca di layar bukan
        | kalimat kosong.
        */
        if (!is_array($jawaban)) {
            return __('bdv_messages.attachment_no_answer');
        }

        if (!($jawaban['s'] ?? false)) {
            Log::warning('[BDV] Bukti ditolak Accurate', [
                'bdv_id' => $bdv->id,
                'accurate_id' => $bdv->accurate_id,
                'berkas' => $berkas['nama'],
                'jawaban' => $jawaban['d'] ?? $jawaban,
            ]);

            return $this->pesanPenolakan($jawaban);
        }

        BdvAttachmentUpload::updateOrCreate(
            [
                'bdv_id' => $bdv->id,
                'document_type' => $berkas['document_type'],
                'attachment_id' => $berkas['attachment_id'],
            ],
            [
                'accurate_id' => $this->idLampiran($jawaban, $bdv, $berkas),
                'filename' => $berkas['nama'],
                'uploaded_at' => now(),
            ],
        );

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Id lampiran di Accurate -- yang dipakai nanti untuk menghapusnya
    |--------------------------------------------------------------------------
    | Bentuk jawaban attach.do tidak didokumentasikan, dan yang terjadi di
    | lapangan: id-nya TIDAK ada di r.id seperti endpoint Accurate lainnya.
    | Beberapa kemungkinan letaknya dicoba berurutan, daripada menyerah pada
    | satu bentuk tebakan.
    |
    | Tanpa id ini berkasnya tetap terunggah -- yang hilang hanya kemampuan
    | menghapusnya lewat API nanti. Karena itu bentuk jawaban yang sebenarnya
    | dicatat di log: sekali terlihat, letaknya bisa dipakai langsung.
    |--------------------------------------------------------------------------
    */
    private function idLampiran(array $jawaban, BankDisbursementVoucher $bdv, array $berkas): ?string
    {
        $hasil = $jawaban['r'] ?? null;

        $calon = [
            is_array($hasil) ? ($hasil['id'] ?? null) : null,
            is_array($hasil) ? ($hasil['attachmentId'] ?? null) : null,
            is_array($hasil) ? ($hasil['fileId'] ?? null) : null,
            is_scalar($hasil) ? $hasil : null,
            $jawaban['id'] ?? null,
        ];

        foreach ($calon as $satu) {
            if (is_scalar($satu) && trim((string) $satu) !== '') {
                return (string) $satu;
            }
        }

        Log::warning('[BDV] Bukti terunggah tanpa id Accurate', [
            'bdv_id' => $bdv->id,
            'berkas' => $berkas['nama'],
            'jawaban' => $jawaban,
        ]);

        return null;
    }

    /**
     * Keterangan lampiran di Accurate: nama asli berkasnya, sama dengan name.
     *
     * Sengaja kembar. Accurate menampilkan keterangan inilah sebagai label
     * lampiran, bukan name-nya, jadi apa pun yang ditaruh di sini terbaca
     * orang sebagai "nama berkas". Sebelumnya di sini nomor dokumen, dan
     * akibatnya nama asli berkasnya tidak pernah terlihat di sana.
     *
     * Nomor dokumen asalnya tidak hilang: bukti mana milik dokumen mana
     * terjawab di SYOP, di halaman BDV-nya sendiri.
     */
    private function keteranganBerkas(array $berkas): ?string
    {
        $nama = trim((string) $berkas['nama']);

        return $nama !== '' ? mb_substr($nama, 0, 200) : null;
    }

    /*
    | Byte menjadi angka yang bisa dibaca orang, bukan deretan tujuh digit.
    |
    | Yang di bawah satu KB disebut byte-nya, tidak dibulatkan. Dibulatkan,
    | kalimatnya menjadi "ukurannya 0 KB, melampaui batas 0 KB" -- benar
    | secara angka, dan sama sekali tidak menjelaskan apa pun.
    */
    private function ukuranTerbaca(int $byte): string
    {
        $byte = max($byte, 0);

        if ($byte >= 1024 * 1024) {
            return number_format($byte / (1024 * 1024), 1) . ' MB';
        }

        if ($byte >= 1024) {
            return number_format($byte / 1024, 0) . ' KB';
        }

        return $byte . ' byte';
    }

    /**
     * Keluhan Accurate dirangkai menjadi satu kalimat yang bisa dibaca.
     *
     * Bentuk 'd' pada jawabannya tidak tetap -- kadang daftar kalimat, kadang
     * satu kalimat. Keduanya ditangani supaya yang sampai ke layar tidak
     * pernah berupa potongan JSON.
     *
     * @param  array<string, mixed>  $jawaban
     */
    private function pesanPenolakan(array $jawaban): string
    {
        $d = $jawaban['d'] ?? null;

        if (is_string($d) && trim($d) !== '') {
            return trim($d);
        }

        if (is_array($d)) {
            $kalimat = collect($d)
                ->flatten()
                ->filter(fn ($v): bool => is_string($v) && trim($v) !== '')
                ->implode('; ');

            if (trim($kalimat) !== '') {
                return $kalimat;
            }
        }

        return __('bdv_messages.attachment_rejected_unknown');
    }
}
