<?php

namespace App\Services\Bdv;

use App\Models\BankDisbursementVoucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
    |--------------------------------------------------------------------------
    */
    public function eligibleQuery(string $kode): Builder
    {
        $jenis = $this->get($kode);

        /** @var class-string<Model> $model */
        $model = $jenis['model'];

        $sudahTerpakai = BankDisbursementVoucher::query()
            ->where('document_type', $jenis['code'])
            ->where('status', '<>', BankDisbursementVoucher::STATUS_CANCELLED)
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

            'items' => $this->rincianItem($dokumen),
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
    private function rincianItem(Model $dokumen): array
    {
        if (!method_exists($dokumen, 'items')) {
            return [];
        }

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
            ])
            ->all();
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
