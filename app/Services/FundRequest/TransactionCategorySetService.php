<?php

namespace App\Services\FundRequest;

use App\Models\FundRequestTransactionCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Himpunan keterangan transaksi sebuah dokumen pengajuan dana
|--------------------------------------------------------------------------
| Keterangan transaksi kini menempel pada BARIS rincian. Headernya tetap ada,
| tetapi berubah peran: ia menyatakan himpunan yang boleh dipakai baris-baris
| itu.
|
| Aturannya ditulis SEKALI di sini.
|
| Dipakai dua jalur -- simpan dan ubah -- dan satu pembaca ketiga, yaitu
| pemeriksaan baris rinciannya. Aturan yang ditulis tiga kali cepat atau
| lambat berbeda di salah satunya, dan yang berbeda itu menentukan siapa
| menandatangani dokumen.
|
| TIGA ATURAN, DAN KENAPA MASING-MASING ADA:
|
| 1. Tidak boleh kosong. Himpunan kosong membuat baris rinciannya tidak punya
|    satu pun pilihan -- formulirnya hidup tetapi tidak bisa diisi.
|
| 2. Non Rutin tepat satu. Permintaan manajemen. Akibat sampingannya penting:
|    alur approval dan jadwal pembayaran jadi tidak pernah ambigu pada Non
|    Rutin, karena tidak ada himpunan untuk diperdebatkan.
|
| 3. Harus cocok sifat pengajuannya. Masternya sudah menyatakan tiap
|    keterangan transaksi berlaku untuk Rutin, Non Rutin, atau keduanya.
|    Tanpa pemeriksaan ini, layar boleh saja menyaring dengan benar sementara
|    permintaan yang datang tanpa lewat layar menyelipkan kategori yang
|    sifatnya tidak cocok.
|--------------------------------------------------------------------------
*/
class TransactionCategorySetService
{
    /**
     * Memeriksa himpunannya dan mengembalikan yang sudah rapi.
     *
     * Hasilnya berurutan menurut urutan masternya, bukan menurut urutan
     * kirimnya: yang pertama di antaranya menjadi penentu alur approval, dan
     * penentu yang bergantung pada urutan centang pengguna akan berpindah
     * tanpa sebab yang bisa dijelaskan kepada siapa pun.
     *
     * @param  array<int|string, mixed>  $categoryIds
     * @return int[]  terurut menurut masternya, tanpa duplikat
     */
    public function normalize(array $categoryIds, ?string $requestType): array
    {
        $diminta = collect($categoryIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($diminta->isEmpty()) {
            $this->tolak('required');
        }

        /* Satu untuk Non Rutin -- lihat aturan 2 di kepala berkas. */
        if (!FundRequestTransactionCategory::allowsMultiple($requestType) && $diminta->count() > 1) {
            $this->tolak('single_for_non_routine');
        }

        $kategori = FundRequestTransactionCategory::query()
            ->whereIn('id', $diminta->all())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        /*
        | Yang tidak ditemukan ditolak dengan menyebut jumlahnya, bukan
        | didiamkan. Id yang tidak menunjuk apa pun berarti layarnya dan
        | masternya sudah tidak sepakat, dan menyimpan diam-diam hanya
        | sebagiannya membuat dokumen itu berisi himpunan yang tidak pernah
        | dipilih siapa pun.
        */
        if ($kategori->count() !== $diminta->count()) {
            $this->tolak('unknown');
        }

        /* Cocok sifat pengajuannya -- lihat aturan 3. */
        $kolom = match (trim((string) $requestType)) {
            FundRequestTransactionCategory::REQUEST_TYPE_ROUTINE => 'allows_routine',
            FundRequestTransactionCategory::REQUEST_TYPE_NON_ROUTINE => 'allows_non_routine',
            default => null,
        };

        if ($kolom !== null) {
            $tidakCocok = $kategori
                ->filter(fn (FundRequestTransactionCategory $satu): bool => !$satu->{$kolom})
                ->pluck('name');

            if ($tidakCocok->isNotEmpty()) {
                $this->tolak('request_type_mismatch', [
                    'categories' => $tidakCocok->implode(', '),
                    'request_type' => trim((string) $requestType),
                ]);
            }
        }

        return $kategori->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Keterangan transaksi PENENTU dari sebuah himpunan.
     *
     * Yang pertama menurut urutan masternya. Ia masih memilih alur approval
     * dan jadwal pembayaran -- dua hal yang hanya bisa satu per dokumen.
     *
     * SEMENTARA, dan disengaja. Pemindahannya menuntut aturan baru untuk
     * himpunan yang mengarah ke lebih dari satu alur, dan aturan itu ditunda
     * atas permintaan. Hari ini tidak ada akibatnya: Non Rutin hanya punya
     * satu, dan keterangan transaksi Rutin yang ada terikat ke alur yang sama.
     *
     * Itu berhenti benar begitu ada keterangan transaksi Rutin baru yang
     * terikat ke alur lain -- lihat catatan perubahan 12 Oktober 2026.
     *
     * @param  int[]  $categoryIds  hasil normalize(), sudah terurut
     */
    public function primary(array $categoryIds): ?int
    {
        return $categoryIds[0] ?? null;
    }

    /**
     * Menyimpan himpunannya, ditulis ulang seluruhnya.
     *
     * Bukan ditambal: kategori yang dicabut harus benar-benar hilang dari
     * himpunannya, dan membandingkan satu per satu hanya menambah jalan untuk
     * keliru pada himpunan sekecil ini.
     *
     * @param  int[]  $categoryIds
     */
    public function sync(Model $document, array $categoryIds): void
    {
        $document->transactionCategories()->sync($categoryIds);
    }

    /**
     * Himpunan yang tersimpan pada sebuah FPU.
     *
     * Jatuh ke kolom penentunya bila himpunannya kosong -- itu keadaan FPU
     * yang dibuat sebelum himpunan ini ada, dan mengembalikan daftar kosong
     * akan membuat formulir suntingnya kehilangan seluruh pilihan.
     *
     * @return int[]
     */
    public function idsFor(Model $document): array
    {
        $himpunan = $document->transactionCategories()
            ->orderBy('sort_order')
            ->orderBy('fund_request_transaction_categories.id')
            ->pluck('fund_request_transaction_categories.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($himpunan !== []) {
            return $himpunan;
        }

        return $document->transaction_category_id
            ? [(int) $document->transaction_category_id]
            : [];
    }

    /**
     * Memeriksa keterangan transaksi pada baris-baris rinciannya.
     *
     * Baris hanya boleh memakai keterangan transaksi yang ada di himpunan
     * headernya. Tanpa batas itu, satu FPU bisa memuat baris dari keterangan
     * transaksi mana pun, dan angka pada laporan per keterangan transaksi
     * berhenti bisa ditelusuri ke dokumen yang menyetujuinya.
     *
     * Barisnya dikembalikan sudah terisi: bila himpunannya tepat satu, baris
     * yang tidak menyebut apa pun diisikan yang satu itu. Isian berpilihan
     * tunggal tidak pantas diminta dari pengguna, tetapi datanya tetap harus
     * lengkap -- yang membacanya nanti tidak perlu tahu soal himpunan.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  int[]  $categoryIds  himpunan headernya, hasil normalize()
     * @return array<int, array<string, mixed>>
     */
    public function applyToItems(array $items, array $categoryIds): array
    {
        $tunggal = count($categoryIds) === 1 ? $categoryIds[0] : null;

        $hasil = [];

        foreach ($items as $index => $baris) {
            $dipilih = (int) ($baris['transaction_category_id'] ?? 0);

            if ($dipilih <= 0) {
                /*
                | Himpunan berisi satu: tidak ada yang perlu dipilih, jadi
                | tidak ada yang perlu ditolak. Lebih dari satu: barisnya
                | memang harus menyebut yang mana.
                */
                if ($tunggal === null) {
                    $this->tolak('item_required', ['row' => $index + 1]);
                }

                $baris['transaction_category_id'] = $tunggal;

                $hasil[] = $baris;

                continue;
            }

            if (!in_array($dipilih, $categoryIds, true)) {
                $this->tolak('item_not_in_set', ['row' => $index + 1]);
            }

            $baris['transaction_category_id'] = $dipilih;

            $hasil[] = $baris;
        }

        /*
        | Tiap keterangan transaksi yang dipilih harus terpakai minimal sekali.
        |
        | Himpunan di header adalah pernyataan tentang isi dokumennya. Anggota
        | yang tidak dipakai satu baris pun membuat pernyataan itu keliru:
        | cetakannya menyebut tiga keterangan transaksi sementara angkanya hanya
        | menjelaskan dua, dan yang membacanya tidak punya cara tahu mana yang
        | hilang -- apakah memang tidak ditagih, atau lupa diisi.
        |
        | HANYA saat himpunannya lebih dari satu.
        |
        | Pada himpunan tunggal tidak ada yang perlu dibagi, dan rincian kosong
        | sudah punya aturannya sendiri -- FPU perdin yang seluruh kategorinya
        | diurus GA memang sah tanpa satu baris pun. Memeriksanya di sini akan
        | menolak dokumen yang justru paling rapi.
        */
        if (count($categoryIds) > 1) {
            $terpakai = array_unique(array_map(
                fn (array $baris): int => (int) $baris['transaction_category_id'],
                $hasil,
            ));

            $kosong = array_values(array_diff($categoryIds, $terpakai));

            if ($kosong !== []) {
                $nama = FundRequestTransactionCategory::query()
                    ->whereIn('id', $kosong)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->pluck('name')
                    ->implode(', ');

                $this->tolak('category_without_item', ['categories' => $nama]);
            }
        }

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    /**
     * Kelompok kalimat penolakan modul yang sedang memakainya.
     *
     * Bawaannya milik FPU supaya pemanggil yang sudah ada menjawab persis
     * seperti sebelumnya. Modul lain menyebut kelompoknya sendiri -- kalimat
     * penolakan yang menyebut modul yang salah membuat yang ditolak mencari
     * isiannya di layar yang tidak sedang ia buka.
     */
    private string $messagePrefix = 'cash_advance_messages.transaction_category_set';

    public function usingMessages(string $prefix): self
    {
        $salinan = clone $this;

        $salinan->messagePrefix = $prefix;

        return $salinan;
    }

    private function tolak(string $key, array $params = []): void
    {
        throw ValidationException::withMessages([
            'transaction_category_ids' => [
                __($this->messagePrefix . '.' . $key, $params),
            ],
        ]);
    }
}
