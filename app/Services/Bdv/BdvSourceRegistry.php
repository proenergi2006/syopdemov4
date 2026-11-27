<?php

namespace App\Services\Bdv;

use App\Models\BdvSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Daftar jenis dokumen yang boleh diangkat menjadi BDV
|--------------------------------------------------------------------------
| Seluruh pertanyaan tentang "jenis dokumen apa saja yang bisa masuk BDV"
| bermuara ke kelas ini -- pilihan di formulir, penyaring di daftar, dan
| pencarian dokumen yang layak. Tidak ada satu pun pemanggil yang membaca
| config('bdv') langsung.
|
| Itu disengaja. Ketika daftarnya kelak dipindahkan ke tabel supaya bisa
| diatur dari layar Master, yang berubah hanya isi kelas ini.
|--------------------------------------------------------------------------
*/
class BdvSourceRegistry
{
    /**
     * Seluruh jenis dokumen, terurut untuk ditampilkan.
     *
     * @return array<int, array{code: string, label: string}>
     */
    public function all(): array
    {
        $jenis = collect(config('bdv.document_types', []))
            ->map(fn (array $isi, string $kode): array => [
                'code' => $kode,
                'label' => $isi['label'] ?? $kode,
                'sort_order' => $isi['sort_order'] ?? 999,
            ])
            ->sortBy('sort_order')
            ->values();

        return $jenis->map(fn (array $i): array => [
            'code' => $i['code'],
            'label' => $i['label'],
        ])->all();
    }

    public function has(string $kode): bool
    {
        return array_key_exists(
            strtoupper(trim($kode)),
            config('bdv.document_types', []),
        );
    }

    /**
     * Keterangan satu jenis dokumen.
     *
     * @return array<string, mixed>
     */
    public function get(string $kode): array
    {
        $kode = strtoupper(trim($kode));

        $isi = config('bdv.document_types.' . $kode);

        if (!is_array($isi)) {
            throw ValidationException::withMessages([
                'document_type' => [__('bdv_messages.unknown_document_type')],
            ]);
        }

        return $isi + ['code' => $kode];
    }

    public function label(string $kode): string
    {
        return $this->get($kode)['label'] ?? $kode;
    }

    /*
    |--------------------------------------------------------------------------
    | Dokumen yang layak diangkat
    |--------------------------------------------------------------------------
    | Dua syarat, dan keduanya perlu:
    |
    | 1. Statusnya tepat -- sudah disetujui penuh dan berkasnya sudah diterima.
    | 2. Belum pernah diangkat BDV lain yang masih berlaku. Tanpa syarat kedua,
    |    satu dokumen bisa tercairkan dua kali lewat dua BDV.
    |
    | BDV yang dibatalkan tidak menahan dokumennya: yang batal memang tidak
    | jadi mencairkan apa pun, jadi dokumennya harus bisa diangkat ulang.
    |
    | $kecualiBdvId dipakai halaman UBAH: dokumen milik voucher yang sedang
    | diubah memang sudah tidak "layak diangkat" -- justru karena sudah
    | diangkat voucher itu sendiri. Tanpa pengecualian ini, dokumen yang
    | sudah terpilih tidak muncul di pilihannya sendiri, dan yang membuka
    | halaman ubah akan melihat daftarnya seolah kosong.
    |--------------------------------------------------------------------------
    */
    public function eligibleQuery(string $kode, ?int $kecualiBdvId = null): Builder
    {
        $jenis = $this->get($kode);

        /** @var class-string<Model> $model */
        $model = $jenis['model'];

        /*
        | Dibaca dari bdv_sources, bukan dari kepala voucher: satu BDV bisa
        | mencairkan beberapa dokumen sekaligus, dan daftarnya ada di sana.
        |
        | Saringannya cukup released_at -- nisan itu DIISI saat vouchernya
        | dihapus atau dibatalkan, jadi ia sekaligus mewakili kedua keadaan
        | yang dulu diperiksa terpisah lewat deleted_at dan status.
        */
        $sudahTerpakai = BdvSource::query()
            ->where('document_type', $jenis['code'])
            ->whereNull('released_at')
            ->when($kecualiBdvId !== null, fn ($q) => $q->where('bdv_id', '!=', $kecualiBdvId))
            ->select('source_id');

        return $model::query()
            ->whereRaw('UPPER(status) = ?', [strtoupper((string) $jenis['eligible_status'])])
            ->whereNotIn('id', $sudahTerpakai);
    }

    /**
     * Satu dokumen sumber, bila memang layak diangkat.
     */
    public function findEligible(string $kode, int $id): ?Model
    {
        return $this->eligibleQuery($kode)->find($id);
    }

    /*
    |--------------------------------------------------------------------------
    | Satu dokumen sumber, TANPA syarat kelayakan
    |--------------------------------------------------------------------------
    | Dipakai untuk membaca dokumen yang SUDAH terikat pada sebuah BDV.
    |
    | Kenapa perlu pintu tersendiri: eligibleQuery justru MENGECUALIKAN dokumen
    | yang sudah dipakai BDV lain -- itulah gunanya. Tetapi begitu sebuah BDV
    | terbit, dokumen sumbernya sendiri ikut tersaring keluar, sehingga halaman
    | ubah dan halaman detail BDV itu tidak bisa lagi membaca rincian dokumen
    | yang justru menjadi isinya. Halaman ubahnya terbuka dengan rincian
    | kosong, dan sebabnya tidak kelihatan dari layar.
    |
    | BERBEDA DARI findEligible, INI TIDAK MENJAGA APA PUN.
    |
    | Ia hanya mencari menurut jenis dan id. Pemanggilnya yang harus sudah
    | memastikan orangnya memang berhak membaca dokumen itu -- di BdvController
    | kepastian itu datang dari BDV-nya sendiri, yang sudah lolos batas akses
    | sebelum method ini dipanggil. Jangan memanggilnya dengan id yang berasal
    | langsung dari layar.
    |--------------------------------------------------------------------------
     */
    public function cari(string $kode, int $id): ?Model
    {
        $jenis = $this->get($kode);

        /** @var class-string<Model> $model */
        $model = $jenis['model'];

        return $model::query()->find($id);
    }

    /*
    |--------------------------------------------------------------------------
    | Bentuk seragam untuk ditampilkan
    |--------------------------------------------------------------------------
    | FPU dan Claim punya bentuk kolom yang hampir sama persis, dan nama
    | relasinya memang sudah sama. Diseragamkan di sini supaya layar tidak
    | perlu tahu sedang menampilkan dokumen jenis apa.
    |--------------------------------------------------------------------------
    */

    /**
     * Baris ringkas untuk pilihan nomor dokumen.
     *
     * @return array<string, mixed>
     */
    public function ringkasan(string $kode, Model $dokumen): array
    {
        $jenis = $this->get($kode);

        return [
            'id' => $dokumen->getKey(),
            'number' => (string) $dokumen->{$jenis['number_column']},
            'date' => optional($dokumen->date)->toDateString() ?? $dokumen->date,
            'subject' => $dokumen->subject,
            'total_amount' => (float) $dokumen->total_amount,
        ];
    }

    /**
     * Rincian lengkap, ditampilkan setelah nomornya dipilih.
     *
     * @return array<string, mixed>
     */
    public function rincian(string $kode, Model $dokumen): array
    {
        $jenis = $this->get($kode);

        $dokumen->loadMissing([
            'branchData',
            'departmentData',
            'transactionCategory',
            'transactionCategories',
            'creator',
        ]);

        /*
        | Item dimuat terpisah dan hanya bila dokumennya memang punya, supaya
        | jenis dokumen baru yang kelak ditambahkan tanpa rincian item tidak
        | menjatuhkan halaman ini.
        */
        if (method_exists($dokumen, 'items')) {
            $dokumen->loadMissing('items.transactionCategory');

            /* expenseCategory hanya ada pada item FPU. */
            $contoh = $dokumen->items->first();

            if ($contoh && method_exists($contoh, 'expenseCategory')) {
                $dokumen->loadMissing('items.expenseCategory');
            }
        }

        /*
        | Lampiran dimuat SEKALI di sini, lalu dikelompokkan menurut baris.
        |
        | Bukan diambil per baris di dalam perulangan: dokumen dengan dua puluh
        | rincian akan menjadi dua puluh query, dan halaman yang terasa lambat
        | tanpa sebab yang jelas adalah yang paling jarang diperbaiki.
        */
        if (method_exists($dokumen, 'attachments')) {
            $dokumen->loadMissing('attachments');
        }

        return [
            'document_type' => $jenis['code'],
            'document_type_label' => $jenis['label'],

            'id' => $dokumen->getKey(),
            'number' => (string) $dokumen->{$jenis['number_column']},
            'date' => optional($dokumen->date)->toDateString() ?? $dokumen->date,
            'subject' => $dokumen->subject,
            'status' => $dokumen->status,

            'branch_id' => $dokumen->branch,
            'branch' => $this->namaCabang($dokumen),

            'department_id' => $dokumen->department_id,
            'department' => $this->namaDepartment($dokumen),

            'transaction_category_id' => $dokumen->transaction_category_id,

            /*
            | Memakai method milik dokumennya, bukan membaca relasi sendiri:
            | sejak keterangan transaksi bisa lebih dari satu per dokumen,
            | method itulah yang tahu cara merangkai himpunannya. Membaca
            | transactionCategory saja hanya memperlihatkan yang pertama.
            */
            'transaction_category' => method_exists($dokumen, 'transactionCategoryLabel')
                ? $dokumen->transactionCategoryLabel()
                : ($dokumen->transactionCategory->name ?? null),

            'total_amount' => (float) $dokumen->total_amount,
            'notes' => $dokumen->notes,

            'requester' => $dokumen->creator->name ?? null,

            'received_at' => $dokumen->received_at,
            'scheduled_payment_date' => $dokumen->scheduled_payment_date,

            'items' => $this->rincianItem($dokumen, $jenis),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Rincian item dokumen sumber
    |--------------------------------------------------------------------------
    | Diurutkan menurut id, yaitu urutan pemohon memasukkannya. Bukan menurut
    | tanggal atau nominal: yang memeriksa BDV membandingkannya dengan cetakan
    | dokumen aslinya, dan di sana urutannya seperti ini.
    |
    | qty dan unit_price hanya ada pada item FPU. Pada Claim keduanya null, dan
    | layar memang tidak menampilkan kolomnya bila seluruh item tidak punya --
    | kolom kosong yang selalu bergaris hanya menambah lebar tanpa isi.
    |--------------------------------------------------------------------------
    */
    private function rincianItem(Model $dokumen, array $jenis): array
    {
        if (!method_exists($dokumen, 'items')) {
            return [];
        }

        $lampiran = $this->lampiranPerItem($dokumen, $jenis);

        return $dokumen->items
            ->sortBy('id')
            ->values()
            ->map(fn ($item, int $urutan): array => [
                'no' => $urutan + 1,
                'date' => optional($item->date)->toDateString() ?? $item->date,
                'description' => $item->description,

                'qty' => $item->qty !== null ? (float) $item->qty : null,
                'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : null,

                'amount' => (float) $item->amount,

                /*
                | Satu kolom untuk dua jenis kategori: item FPU memakai
                | kategori biaya perjalanan, item Claim memakai keterangan
                | transaksi. Keduanya menjawab pertanyaan yang sama bagi yang
                | membaca -- "ini pengeluaran apa".
                */
                'category' => $item->expenseCategory?->name
                    ?? $item->transactionCategory?->name,

                /*
                | Bukti milik baris ini. Inilah yang dibuka orang Finance
                | sebelum menandatangani voucher -- nominal tanpa buktinya
                | hanya angka yang harus dipercaya begitu saja.
                */
                'attachments' => $lampiran[$item->getKey()] ?? [],
            ])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Lampiran dikelompokkan menurut baris rincian
    |--------------------------------------------------------------------------
    | Hanya lampiran PENGAJUAN (REQUEST) dan hanya yang menempel pada sebuah
    | baris. Dua pembatas itu keduanya perlu:
    |
    | - Lampiran tingkat dokumen (item_id null) bukan milik baris mana pun;
    |   menempelkannya ke semua baris membuat satu berkas tampak berulang di
    |   setiap baris, seolah-olah tiap pengeluaran punya buktinya sendiri.
    |
    | - Lampiran RECEIPT, PAYMENT, dan DISBURSEMENT lahir SESUDAH dokumennya
    |   dibayar. Pada BDV, uangnya justru belum keluar -- berkas-berkas itu
    |   belum ada, dan kalaupun ada, ia menjawab pertanyaan yang berbeda.
    |
    | @param  array<string, mixed>  $jenis
    | @return array<int, array<int, array<string, mixed>>>
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | Bukti yang menempel pada baris rincian, sebagai model
    |--------------------------------------------------------------------------
    | Satu-satunya tempat yang memutuskan bukti MANA yang dianggap milik
    | sebuah dokumen: hanya lampiran REQUEST, dan hanya yang menunjuk sebuah
    | baris rincian. Lampiran tingkat dokumen (item_id kosong) dan jenis
    | RECEIPT/PAYMENT/DISBURSEMENT sengaja dibuang -- yang terakhir itu bukti
    | transfer yang dilampirkan Finance, bukan bukti pengeluarannya.
    |
    | Dipakai bersama oleh dua hal yang harus melihat himpunan yang SAMA
    | PERSIS: apa yang tampil di layar, dan apa yang diunggah ke Accurate.
    | Dua aturan penyaring yang berdiri sendiri adalah dua aturan yang cepat
    | atau lambat menyimpang, dan menyimpangnya tidak akan terlihat -- yang
    | terjadi hanyalah bukti yang ada di layar tetapi tidak ada di pembukuan.
    |
    | @return \Illuminate\Support\Collection
    |--------------------------------------------------------------------------
    */
    public function lampiranBaris(string $kode, Model $dokumen)
    {
        $jenis = $this->get($kode);

        $kolom = $jenis['item_attachment_key'] ?? null;

        if (!$kolom || !method_exists($dokumen, 'attachments')) {
            return collect();
        }

        $dokumen->loadMissing('attachments');

        return $dokumen->attachments
            ->filter(fn ($berkas): bool => (bool) ($berkas->{$kolom} ?? null))
            ->filter(fn ($berkas): bool => strtoupper(trim((string) $berkas->attachment_type)) === 'REQUEST')
            ->values();
    }

    private function lampiranPerItem(Model $dokumen, array $jenis): array
    {
        $kolom = $jenis['item_attachment_key'] ?? null;

        if (!$kolom || !method_exists($dokumen, 'attachments')) {
            return [];
        }

        $hasil = [];

        foreach ($this->lampiranBaris((string) $jenis['code'], $dokumen) as $berkas) {
            $idItem = $berkas->{$kolom};

            $hasil[(int) $idItem][] = [
                'id' => $berkas->id,
                'filename' => $berkas->filename,
                'original_filename' => $berkas->original_filename,
                'mime_type' => $berkas->mime_type,
                'file_size' => $berkas->file_size !== null ? (int) $berkas->file_size : null,

                'url' => $berkas->filepath
                    ? Storage::disk('public')->url($berkas->filepath)
                    : null,
            ];
        }

        return $hasil;
    }

    private function namaCabang(Model $dokumen): ?string
    {
        $cabang = $dokumen->branchData;

        if (!$cabang) {
            return null;
        }

        $inisial = trim((string) ($cabang->inisial_cabang ?? ''));
        $nama = trim((string) ($cabang->nama_cabang ?? ''));

        return trim($inisial !== '' ? $inisial . ' - ' . $nama : $nama) ?: null;
    }

    private function namaDepartment(Model $dokumen): ?string
    {
        $dept = $dokumen->departmentData;

        if (!$dept) {
            return null;
        }

        $kode = trim((string) ($dept->kode ?? ''));
        $nama = trim((string) ($dept->nama ?? ''));

        return trim($kode !== '' ? $kode . ' - ' . $nama : $nama) ?: null;
    }
}
