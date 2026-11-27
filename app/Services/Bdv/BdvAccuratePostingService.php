<?php

namespace App\Services\Bdv;

use App\Models\BankDisbursementVoucher;
use App\Services\AccurateApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/*
|--------------------------------------------------------------------------
| Mengirim BDV ke Accurate sebagai Other Payment
|--------------------------------------------------------------------------
| Other Payment ("Pembayaran Lain") dipilih karena bentuknya memang sama
| dengan BDV: uang keluar dari SATU akun kas/bank, lalu dibebankan ke BEBERAPA
| akun biaya. Satu baris rincian BDV menjadi satu baris detailExpense.
|
| DIKIRIM SEJAK DISIMPAN, TERMASUK SAAT MASIH DRAFT.
|
| Bukan saat disetujui. Accurate-lah yang memegang catatan sesungguhnya sejak
| awal, dan approval di aplikasi ini tidak mengirim apa pun lagi.
|
| Akibatnya ada satu, dan perlu disadari: BDV tidak bisa disimpan setengah
| jadi. Akun pada setiap baris wajib sudah dipilih sejak simpan pertama,
| sebab baris tanpa akun tidak punya tempat jatuh di pembukuan. Layar memang
| sudah menandainya wajib -- di sini aturan yang sama dijaga server.
|
| SIMPAN KEDUA MEMPERBARUI, BUKAN MEMBUAT LAGI.
|
| Accurate memakai endpoint yang sama untuk menyimpan dan mengubah; yang
| membedakan hanya ada tidaknya field 'id'. Pola itu sudah dipakai
| PurchaseOrderInventoryService dan diikuti di sini. Karena itu accurate_id
| yang sudah tersimpan selalu disertakan: tanpa itu, tiap kali orang menekan
| Simpan akan lahir satu dokumen baru di pembukuan.
|
|--------------------------------------------------------------------------
| PAYLOAD -- MENGIKUTI DOKUMENTASI RESMI other-payment/save.do
|--------------------------------------------------------------------------
| Yang WAJIB menurut dokumentasinya:
|
|   bankNo                       nomor COA kas/bank sumber dananya
|   payee                        penerima pembayaran
|   transDate                    tanggal, bergaya 31/03/2016
|   detailAccount[n].accountNo   akun biaya tiap baris
|   detailAccount[n].amount      nominal tiap baris
|   detailAccount[n].expenseName NAMA AKUN Accurate, bukan uraian barisnya
|
| Yang OPSIONAL dan memang dipakai di sini:
|
|   id                           ada berarti mengubah, tidak ada berarti baru
|   number                       nomor bukti -- lihat catatan di bawah
|   detailAccount[n].id          sama, tetapi untuk baris rinciannya
|   detailAccount[n].memo        uraian baris SYOP -- "Catatan" di Accurate
|   detailAccount[n].departmentName cabang SYOP yang menanggung biayanya
|   branchName                   cabang
|   chequeNo                     nomor cek
|   description                  keterangan dokumen
|
| Yang sengaja TIDAK dipakai: branchId (sudah diwakili branchName), chequeDate,
| rate, typeAutoNumber, dan seluruh dataClassification1..10Name.
|
| NOMOR BUKTI DITENTUKAN DI SINI, BUKAN OLEH ACCURATE.
|
| 'number' dikirim berisi nomor BDV yang diketik orangnya, sehingga No. Bukti
| di Accurate sama persis dengan nomor yang tercetak pada vouchernya. Tanpa
| itu Accurate menomori sendiri, dan satu dokumen yang sama punya dua nomor
| berbeda di dua sistem -- siapa pun yang kelak mencocokkan pembukuan dengan
| arsip voucher harus menebak pasangannya satu per satu.
|
| Akibatnya: nomor BDV harus unik di Accurate juga, bukan hanya di sini.
| Penolakan karena nomor kembar akan sampai ke layar sebagai peringatan, dan
| itu memang yang diinginkan -- lebih baik ditolak daripada menimpa dokumen
| orang lain.
|
| CATATAN SEJARAH YANG PENTING: payload ini sempat ditulis meniru
| PurchaseOrderInventoryService, yang memakai detailExpense[].accountNo dan
| expenseAmount. Itu KELIRU untuk endpoint ini -- purchase-order dan
| other-payment punya skema yang berbeda. Jangan menyalin dari modul lain
| tanpa memeriksa dokumentasi endpointnya sendiri.
|--------------------------------------------------------------------------
*/
class BdvAccuratePostingService
{
    private const ENDPOINT = '/accurate/api/other-payment/save.do';

    private const ENDPOINT_HAPUS = '/accurate/api/other-payment/delete.do';

    /**
     * Menyimpan satu BDV ke Accurate -- membuat baru atau memperbarui.
     *
     * Kegagalan dilempar sebagai pengecualian, tidak dikembalikan sebagai
     * false: pemanggilnya harus memutuskan apa yang disampaikan ke layar, dan
     * kegagalan yang terbaca sebagai "selesai" adalah kegagalan yang paling
     * mahal di sini -- uangnya tercatat di satu sistem saja.
     *
     * @throws RuntimeException
     */
    public function kirim(BankDisbursementVoucher $bdv): bool
    {
        $this->pastikanLayakDikirim($bdv);

        $perbarui = (bool) $bdv->accurate_id;

        $jawaban = app(AccurateApiService::class)->post(
            rtrim((string) config('services.accurate.base_url'), '/') . self::ENDPOINT,
            $this->buatPayload($bdv),
        );

        /*
        | Accurate tidak selalu gagal lewat HTTP error. Ia menjawab 200 dengan
        | s:false ketika muatannya ditolak -- akun tidak ditemukan, cabang
        | tidak dikenal, periode sudah ditutup. Tanpa pemeriksaan ini,
        | dokumennya tercatat "terkirim" padahal tidak ada apa pun di sana.
        */
        if (!($jawaban['s'] ?? false)) {
            Log::error('[BDV] Ditolak Accurate', [
                'bdv_id' => $bdv->id,
                'bdv_number' => $bdv->bdv_number,
                'memperbarui' => $perbarui,
                'jawaban' => $jawaban['d'] ?? $jawaban,
            ]);

            throw new RuntimeException($this->pesanPenolakan($jawaban));
        }

        $hasil = $jawaban['r'] ?? [];

        $id = $hasil['id'] ?? $bdv->accurate_id;

        if (!$id) {
            /*
            | Berhasil tetapi tanpa id adalah keadaan yang tidak boleh
            | didiamkan: dokumennya mungkin benar-benar terbentuk di sana,
            | sementara kita kehilangan satu-satunya cara menemukannya lagi.
            */
            Log::error('[BDV] Accurate menjawab berhasil tanpa id', [
                'bdv_id' => $bdv->id,
                'jawaban' => $jawaban,
            ]);

            throw new RuntimeException(__('bdv_messages.accurate_no_id'));
        }

        /*
        | Kegagalan MENCATAT berbeda dari kegagalan MENGIRIM, dan bedanya
        | besar: di titik ini dokumennya sudah benar-benar ada di Accurate.
        | Pesan Postgres tidak boleh sampai ke layar -- yang perlu diketahui
        | orangnya adalah bahwa dokumennya SUDAH masuk sana dan pencatatannya
        | yang tertinggal, bukan sebaliknya.
        */
        try {
            DB::transaction(function () use ($bdv, $id, $hasil): void {
                $bdv->forceFill([
                    'accurate_id' => (string) $id,
                    'accurate_number' => $hasil['number'] ?? $bdv->accurate_number,
                    'accurate_sent_at' => now(),

                    /*
                    | Satu-satunya tempat kolom ini menjadi true, dan hanya
                    | di sini: isi yang barusan dikirim sudah diterima
                    | Accurate. Setiap penyimpanan berikutnya menandainya
                    | false lagi sampai pengirimannya berhasil -- lihat
                    | BdvController::jawabSesudahSimpan.
                    */
                    'accurate_in_sync' => true,
                ])->save();

                /*
                | Nisannya dibersihkan HANYA sesudah Accurate menerima
                | penghapusannya. Bila pengirimannya gagal, baris bernisan
                | tetap tinggal dan pengiriman berikutnya mengulangi
                | permintaan yang sama.
                */
                $bdv->itemsDihapus()->delete();

                $this->catatIdRincian($bdv, $hasil);
            });
        } catch (\Throwable $e) {
            Log::critical('[BDV] Tersimpan di Accurate tetapi gagal dicatat', [
                'bdv_id' => $bdv->id,
                'bdv_number' => $bdv->bdv_number,
                'accurate_id' => $id,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                __('bdv_messages.accurate_sent_not_recorded', ['id' => $id]),
            );
        }

        Log::info('[BDV] Tersimpan di Accurate', [
            'bdv_id' => $bdv->id,
            'bdv_number' => $bdv->bdv_number,
            'accurate_id' => $id,
            'memperbarui' => $perbarui,
        ]);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Menghapus dokumennya di Accurate
    |--------------------------------------------------------------------------
    | Dipanggil saat sebuah BDV dihapus di sini. Harus dijalankan LEBIH DULU,
    | sebelum penghapusan lokal commit, dan kegagalannya harus membatalkan
    | penghapusan itu.
    |
    | Urutannya kebalikan dari menyimpan, dan itu disengaja. Saat menyimpan,
    | yang lebih berbahaya adalah dokumen yatim di Accurate, jadi lokal dulu.
    | Saat menghapus, yang lebih berbahaya justru sebaliknya: kalau lokal
    | dihapus duluan lalu Accurate menolak, kita kehilangan accurate_id --
    | satu-satunya cara menemukan dokumen itu lagi. Ia akan tinggal di
    | pembukuan selamanya tanpa ada yang tahu ia milik siapa.
    |
    | BDV yang belum pernah sampai Accurate (accurate_id kosong) dijawab true
    | tanpa memanggil apa pun: tidak ada yang perlu dihapus di sana.
    |
    | @throws RuntimeException
    |--------------------------------------------------------------------------
    */
    public function hapus(BankDisbursementVoucher $bdv): bool
    {
        if (!$bdv->accurate_id) {
            return true;
        }

        $jawaban = app(AccurateApiService::class)->delete(
            rtrim((string) config('services.accurate.base_url'), '/') . self::ENDPOINT_HAPUS,
            ['id' => $bdv->accurate_id],
        );

        if (!($jawaban['s'] ?? false)) {
            Log::error('[BDV] Penghapusan ditolak Accurate', [
                'bdv_id' => $bdv->id,
                'bdv_number' => $bdv->bdv_number,
                'accurate_id' => $bdv->accurate_id,
                'jawaban' => $jawaban['d'] ?? $jawaban,
            ]);

            throw new RuntimeException($this->pesanPenolakan($jawaban));
        }

        Log::info('[BDV] Terhapus di Accurate', [
            'bdv_id' => $bdv->id,
            'bdv_number' => $bdv->bdv_number,
            'accurate_id' => $bdv->accurate_id,
        ]);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Syarat yang harus dipenuhi sebelum dikirim
    |--------------------------------------------------------------------------
    | Diperiksa di sini, bukan hanya di pemanggilnya. Layanan ini kelak dipakai
    | dari beberapa tempat -- menyimpan, mengubah, mungkin perintah artisan
    | untuk mengirim ulang yang gagal -- dan syarat yang hanya dijaga satu
    | pemanggil adalah syarat yang cepat atau lambat terlewati.
    |
    | TIDAK ADA SYARAT STATUS. Draft pun masuk Accurate, dan itu memang
    | keputusannya: Accurate yang memegang catatan sesungguhnya sejak awal.
    |--------------------------------------------------------------------------
    */
    private function pastikanLayakDikirim(BankDisbursementVoucher $bdv): void
    {
        if (trim((string) $bdv->cash_bank_account_no) === '') {
            throw new RuntimeException(__('bdv_messages.accurate_no_cash_bank'));
        }

        /* Wajib menurut dokumentasi other-payment/save.do. */
        if (trim((string) $bdv->payee) === '') {
            throw new RuntimeException(__('bdv_messages.accurate_no_payee'));
        }

        $items = $bdv->relationLoaded('items') ? $bdv->items : $bdv->items()->get();

        if ($items->isEmpty()) {
            throw new RuntimeException(__('bdv_messages.accurate_no_items'));
        }

        $tanpaAkun = $items
            ->filter(fn ($item): bool => trim((string) $item->accurate_account_no) === '')
            ->pluck('line_no')
            ->all();

        if ($tanpaAkun !== []) {
            throw new RuntimeException(__('bdv_messages.accurate_item_no_account', [
                'lines' => implode(', ', $tanpaAkun),
            ]));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Muatan yang dikirim
    |--------------------------------------------------------------------------
    | Lihat catatan di kepala kelas tentang field mana yang sudah terbukti dan
    | mana yang masih perlu dicocokkan.
    |--------------------------------------------------------------------------
    */
    private function buatPayload(BankDisbursementVoucher $bdv): array
    {
        $items = $bdv->relationLoaded('items') ? $bdv->items : $bdv->items()->get();

        $muatan = [
            /*
            | Accurate memakai tanggal bergaya Indonesia pada API-nya, sama
            | seperti X-Api-Timestamp yang dirangkai AccurateApiService.
            */
            'transDate' => optional($bdv->date)->format('d/m/Y'),

            /* Akun kas/bank sumber dananya. NOMOR akun, bukan id internal. */
            'bankNo' => $bdv->cash_bank_account_no,

            /*
            | No. Bukti di Accurate = nomor BDV yang diketik orangnya. Lihat
            | catatan di kepala kelas: tanpa ini Accurate menomori sendiri dan
            | satu dokumen punya dua nomor di dua sistem.
            */
            'number' => $bdv->bdv_number ?: null,

            'branchName' => $this->namaCabang($bdv),

            'chequeNo' => $bdv->check_number ?: null,

            /* Wajib menurut dokumentasinya, jadi tidak pernah dikosongkan. */
            'payee' => $bdv->payee,

            /*
            | Keterangan yang terbaca dari sisi Accurate: nomor dokumen
            | sumbernya, lalu catatan yang diketik orangnya. Nomor itu yang
            | membuat orang akuntansi bisa kembali ke dokumen asalnya tanpa
            | bertanya -- nomor BDV-nya sendiri sudah ada di No. Bukti.
            */
            'description' => $this->keterangan($bdv),

            'detailAccount' => $items
                ->map(fn ($item): array => [
                    'accountNo' => $item->accurate_account_no,
                    'amount' => (float) $item->amount,

                    /*
                    | expenseName = NAMA AKUN ACCURATE, bukan uraian barisnya.
                    |
                    | Kolom "Nama Akun" di Accurate memang menampilkan nama
                    | akunnya sendiri -- "Advance Payment - Others" dan
                    | semacamnya. Mengisinya dengan uraian baris SYOP membuat
                    | kolom itu memuat kalimat yang berbeda-beda tiap baris
                    | padahal akunnya sama, dan siapa pun yang membaca
                    | pembukuan tidak lagi bisa mengenali akun apa yang
                    | sedang dibebani.
                    |
                    | Nomor akun dipakai sebagai cadangan, dan nomor baris
                    | sebagai cadangan terakhir supaya field wajib ini tidak
                    | pernah kosong.
                    */
                    'expenseName' => $this->namaAkun($item),

                    /*
                    | memo = uraian baris SYOP, yang di Accurate tampil
                    | sebagai "Catatan" pada Payment Detail > Additional Info.
                    | Di situlah keterangan tiap pengeluaran berada --
                    | "Service spring no. 7 belakang kiri unit KT 8087 MU"
                    | dan seterusnya.
                    |
                    | Kategori dipakai sebagai cadangan: baris tanpa uraian
                    | tetap lebih berguna disebut kategorinya daripada
                    | dibiarkan kosong.
                    */
                    'memo' => $this->catatanBaris($item),

                    /*
                    | departmentName = CABANG SYOP.
                    |
                    | Penamaannya memang membingungkan: yang Accurate sebut
                    | "Department" pada baris pengeluaran adalah unit yang
                    | menanggung biayanya, dan di SYOP unit itu adalah
                    | cabangnya. Nilainya sama persis dengan branchName di
                    | kepala dokumen -- lihat namaCabang().
                    */
                    'departmentName' => $this->namaCabang($bdv),

                    /*
                    | Ada berarti memperbarui baris yang sudah ada di sana.
                    | Tidak ada berarti baris baru. Inilah yang menahan satu
                    | voucher yang disimpan berkali-kali dari meninggalkan
                    | rincian berlipat di pembukuan.
                    */
                    'id' => $item->accurate_detail_id ?: null,
                ])
                ->values()
                ->all(),
        ];

        /*
        | Baris yang sudah hilang dari dokumen sumber dititipkan di ekor
        | daftar yang sama, dengan _status "delete". Tanpa ini ia tetap hidup
        | di Accurate sebagai biaya yatim -- tidak ada padanannya di dokumen
        | mana pun, dan tidak ada yang akan mencarinya sampai neracanya
        | diperiksa.
        */
        foreach ($this->barisDihapus($bdv) as $item) {
            $muatan['detailAccount'][] = [
                'id' => $item->accurate_detail_id,
                '_status' => 'delete',
            ];
        }

        /*
        | id hanya disertakan bila dokumennya SUDAH ada di Accurate. Ada-nya
        | field inilah yang membedakan "simpan baru" dari "ubah" -- tanpa itu,
        | tiap kali Simpan ditekan akan lahir satu dokumen baru di pembukuan.
        */
        if ($bdv->accurate_id) {
            $muatan['id'] = $bdv->accurate_id;
        }

        /*
        | Field kosong dibuang, tidak dikirim sebagai null. Accurate
        | memperlakukan field yang hadir-tetapi-null berbeda dari field yang
        | memang tidak disebut, dan yang kedua itulah maksud kita untuk isian
        | yang opsional seperti nomor cek.
        */
        return $this->buangYangKosong($muatan);
    }

    /**
     * Baris bernisan: sudah hilang dari dokumen sumber, belum dihapus di sana.
     *
     * Hanya yang punya accurate_detail_id yang berarti di sini. Yang tidak
     * punya memang tidak pernah sampai Accurate, dan sudah dibuang langsung
     * saat penyimpanan.
     */
    private function barisDihapus(BankDisbursementVoucher $bdv)
    {
        return $bdv->itemsDihapus()
            ->whereNotNull('accurate_detail_id')
            ->get();
    }

    /**
     * Nama akun Accurate untuk satu baris -- field wajib expenseName.
     *
     * Disalin dari pilihan akun pada baris itu, bukan dari uraian
     * pengeluarannya: kolom "Nama Akun" di Accurate memang menyebut akunnya
     * sendiri. Uraiannya pergi ke memo.
     */
    private function namaAkun($item): string
    {
        foreach ([$item->accurate_account_name, $item->accurate_account_no] as $calon) {
            $bersih = trim((string) $calon);

            if ($bersih !== '') {
                return mb_substr($bersih, 0, 200);
            }
        }

        return 'Baris ' . $item->line_no;
    }

    /**
     * Uraian satu baris, yang di Accurate tampil sebagai "Catatan".
     *
     * Dikembalikan null bila tidak ada apa pun yang pantas ditulis: field
     * ini opsional, dan mengirimkan teks kosong hanya membuat kolomnya
     * terisi tanda baca tanpa arti.
     */
    private function catatanBaris($item): ?string
    {
        foreach ([$item->description, $item->category] as $calon) {
            $bersih = trim((string) $calon);

            if ($bersih !== '') {
                return mb_substr($bersih, 0, 200);
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Mencatat id baris rincian dari jawaban Accurate
    |--------------------------------------------------------------------------
    | Tanpa ini, simpan berikutnya mengirim seluruh rincian tanpa id, dan
    | Accurate memperlakukan semuanya sebagai baris baru.
    |
    | PENCOCOKANNYA MEMAKAI URUTAN, dan itu perlu dinyatakan terang-terangan:
    | kita mengirim detailAccount terurut menurut line_no, lalu menganggap
    | Accurate mengembalikannya dalam urutan yang sama. Anggapan itu hanya
    | dipakai bila jumlahnya memang sama persis; bila tidak, pencatatannya
    | DILEWATI dengan peringatan, bukan dipaksakan.
    |
    | Memaksakan pencocokan yang jumlahnya tidak sama akan memasangkan id ke
    | baris yang salah -- dan kesalahan itu baru muncul pada simpan berikutnya,
    | sebagai nominal yang berpindah akun tanpa ada yang mengubahnya.
    |
    | @param  array<string, mixed>  $hasil
    |--------------------------------------------------------------------------
    */
    private function catatIdRincian(BankDisbursementVoucher $bdv, array $hasil): void
    {
        $dariAccurate = $hasil['detailAccount'] ?? null;

        if (!is_array($dariAccurate) || $dariAccurate === []) {
            return;
        }

        $items = $bdv->items()->orderBy('line_no')->get();

        if (count($dariAccurate) !== $items->count()) {
            Log::warning('[BDV] Jumlah rincian dari Accurate tidak sama, id baris tidak dicatat', [
                'bdv_id' => $bdv->id,
                'dikirim' => $items->count(),
                'dikembalikan' => count($dariAccurate),
            ]);

            return;
        }

        foreach ($items as $urutan => $item) {
            $idBaris = $dariAccurate[$urutan]['id'] ?? null;

            if (!$idBaris) {
                continue;
            }

            $item->forceFill(['accurate_detail_id' => (string) $idBaris])->save();
        }
    }

    /**
     * Nama cabang sebagaimana dikenal Accurate.
     *
     * "Kantor Pusat" di sini bernama "Head Office" di sana -- pemetaan yang
     * sama sudah dipakai GoodsReceipt dan PurchaseOrder, dan disamakan di sini
     * supaya ketiganya tidak perlahan menyimpang.
     */
    private function namaCabang(BankDisbursementVoucher $bdv): ?string
    {
        $nama = $bdv->branchData->nama_cabang ?? null;

        if (!$nama) {
            return null;
        }

        return trim($nama) === 'Kantor Pusat' ? 'Head Office' : $nama;
    }

    /*
    |--------------------------------------------------------------------------
    | Keterangan dokumen -- dipisah baris, bukan dirangkai dengan tanda
    |--------------------------------------------------------------------------
    | Pemisahnya ganti baris sungguhan ("\n"), BUKAN <br>. Kolom keterangan di
    | Accurate adalah teks biasa, bukan HTML: <br> yang dikirim ke sana muncul
    | di layar sebagai tulisan "<br>", bukan sebagai pindah baris. Ini sudah
    | pernah dicoba, jadi jangan diulang.
    |
    | Catatan yang diketik orangnya ikut membawa ganti barisnya sendiri, dan
    | itu memang dibiarkan -- dialah satu-satunya bagian yang ditulis manusia.
    |--------------------------------------------------------------------------
    */
    private function keterangan(BankDisbursementVoucher $bdv): string
    {
        $bagian = array_filter([
            /*
            | Nomor dokumen sumbernya saja, tanpa awalan apa pun.
            |
            | Jenisnya tidak ditulis karena sudah terbaca dari nomornya
            | sendiri -- FPU/... dan CLM/... tidak mungkin tertukar. Nomor BDV
            | juga tidak diulang di sini: sejak 'number' dikirim, ia sudah
            | menjadi No. Bukti dokumen ini di Accurate, dan menuliskannya dua
            | kali hanya memakan tempat pada kolom yang terbatas 255 huruf.
            */
            /*
            | SELURUH nomor dokumen sumbernya, dipisah koma pada satu baris.
            |
            | Satu voucher bisa mencairkan beberapa FPU/Claim sekaligus, dan
            | orang akuntansi yang membukanya di Accurate harus bisa kembali
            | ke semuanya -- bukan hanya ke yang kebetulan terdaftar pertama.
            */
            $bdv->sources->pluck('source_number')->filter()->implode(', ') ?: null,

            /* Lalu catatan yang diketik orangnya, apa adanya. */
            trim((string) $bdv->notes) ?: null,
        ]);

        return mb_substr(implode("\n", $bagian), 0, 255);
    }

    /**
     * @param  array<string, mixed>  $muatan
     * @return array<string, mixed>
     */
    private function buangYangKosong(array $muatan): array
    {
        foreach ($muatan as $kunci => $nilai) {
            if (is_array($nilai)) {
                $muatan[$kunci] = array_map(
                    fn ($baris) => is_array($baris)
                        ? array_filter($baris, fn ($v): bool => $v !== null && $v !== '')
                        : $baris,
                    $nilai,
                );

                continue;
            }

            if ($nilai === null || $nilai === '') {
                unset($muatan[$kunci]);
            }
        }

        return $muatan;
    }

    /**
     * Keluhan Accurate dirangkai menjadi satu kalimat yang bisa dibaca.
     *
     * Bentuk 'd' pada jawabannya tidak tetap: kadang daftar kalimat, kadang
     * satu kalimat, kadang objek. Ketiganya ditangani supaya yang sampai ke
     * layar tidak pernah berupa potongan JSON.
     *
     * @param  array<string, mixed>  $jawaban
     */
    private function pesanPenolakan(array $jawaban): string
    {
        $d = $jawaban['d'] ?? null;

        if (is_string($d) && trim($d) !== '') {
            return __('bdv_messages.accurate_rejected', ['reason' => $d]);
        }

        if (is_array($d)) {
            $kalimat = collect($d)
                ->flatten()
                ->filter(fn ($v): bool => is_string($v) && trim($v) !== '')
                ->implode('; ');

            if (trim($kalimat) !== '') {
                return __('bdv_messages.accurate_rejected', ['reason' => $kalimat]);
            }
        }

        return __('bdv_messages.accurate_failed');
    }
}
