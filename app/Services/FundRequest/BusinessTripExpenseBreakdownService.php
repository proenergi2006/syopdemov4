<?php

namespace App\Services\FundRequest;

use App\Models\BusinessTripExpenseCategory;
use App\Models\FundRequestArrangedCategory;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Rincian perjalanan dinas: berkategori, berkuantitas, berharga satuan
|--------------------------------------------------------------------------
| Dipakai FPU dan Realisasi. Keduanya memakai bentuk yang sama, dan aturannya
| ditulis sekali supaya tidak ada satu modul pun yang perlahan menyimpang.
|
| TOTALNYA DIHITUNG, TIDAK DITERIMA DARI LAYAR.
|
| Nominal tiap baris selalu qty x unit_price, dihitung di sini. Angka yang
| dikirim layar diabaikan sepenuhnya -- bukan karena layarnya tidak
| dipercaya, melainkan karena nominal yang bisa datang dari dua tempat cepat
| atau lambat akan datang berbeda, dan yang berbeda itu uang.
|
| Keputusannya diambil 1 Oktober 2026: angka pada formulir kertas yang
| berjalan sekarang sempat tidak konsisten -- satu baris Uang Saku bertuliskan
| Qty 2 x 275.000 dengan total 275.000. Dihitung, baris seperti itu akan
| berubah menjadi 550.000, dan cara mengisinya perlu disepakati: Qty 1 x
| 275.000, atau Qty 2 x 137.500.
|
| "DIURUS GA" BUKAN "LUPA DIISI".
|
| Kategori yang ditandai diurus GA tidak boleh punya baris, dan kategori yang
| punya baris tidak boleh ditandai. Keduanya pernyataan yang saling
| meniadakan; membiarkan keduanya berlaku berarti cetakannya mengatakan dua
| hal sekaligus tentang kategori yang sama.
|
| DAN TIDAK SEMUA KATEGORI BOLEH DITANDAI.
|
| GA hanya mengurus sebagian -- hari ini tiket dan penginapan. Kategori mana
| saja ditentukan masternya (ga_arrangeable), bukan ditulis di sini: daftar
| "GA mengurus apa" adalah kesepakatan kerja, dan ia berubah tanpa menunggu
| rilis.
|--------------------------------------------------------------------------
*/
class BusinessTripExpenseBreakdownService
{
    /**
     * Memeriksa dan merapikan rincian perdin.
     *
     * Mengembalikan baris yang sudah dilengkapi nominal hasil hitungan, siap
     * disimpan apa adanya. Baris non-perdin dikembalikan tanpa disentuh.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  int[]  $arrangedCategoryIds  Kategori yang ditandai diurus GA
     * @param  string  $messagePrefix  Kelompok kalimat modulnya
     * @return array<int, array<string, mixed>>
     */
    public function normalize(
        array $items,
        array $arrangedCategoryIds,
        string $messagePrefix,
    ): array {
        $kategori = BusinessTripExpenseCategory::query()
            ->whereIn('id', $this->kumpulkanKategori($items, $arrangedCategoryIds))
            ->get()
            ->keyBy('id');

        $hasil = [];

        $terpakai = [];

        foreach ($items as $index => $baris) {
            $baris['expense_category_id'] = (int) ($baris['expense_category_id'] ?? 0);

            if ($baris['expense_category_id'] <= 0 || !$kategori->has($baris['expense_category_id'])) {
                $this->tolak($messagePrefix . '.category_required', ['row' => $index + 1]);
            }

            $qty = $this->angka($baris['qty'] ?? null);
            $harga = $this->angka($baris['unit_price'] ?? null);

            /*
            | Qty harus lebih dari nol. Qty nol menghasilkan nominal nol, dan
            | baris bernominal nol hanya menambah panjang cetakan tanpa
            | menagih apa pun -- kalau memang tidak ditagih, barisnya tidak
            | perlu ada.
            */
            if ($qty === null || $qty <= 0) {
                $this->tolak($messagePrefix . '.qty_required', ['row' => $index + 1]);
            }

            if ($harga === null || $harga <= 0) {
                $this->tolak($messagePrefix . '.unit_price_required', ['row' => $index + 1]);
            }

            $baris['qty'] = $qty;
            $baris['unit_price'] = $harga;

            /* Nominalnya dihitung di sini, bukan diterima dari layar. */
            $baris['amount'] = round($qty * $harga, 2);

            $terpakai[$baris['expense_category_id']] = true;

            $hasil[] = $baris;
        }

        foreach ($arrangedCategoryIds as $id) {
            $id = (int) $id;

            $satu = $kategori->get($id);

            /*
            | Hanya kategori yang disebut masternya.
            |
            | GA tidak mengurus semua kategori -- tiket dan penginapan iya,
            | uang saku tidak. Penanda yang dipasang di luar itu menyatakan
            | ada yang akan mengurusnya padahal tidak ada siapa pun, dan
            | angkanya hilang dari dokumen tanpa ada yang menagihnya.
            |
            | Diperiksa di sini, bukan hanya di layar: layar bisa tidak ikut
            | diperbarui, dan permintaannya bisa datang tanpa lewat layar
            | sama sekali.
            |
            | Kategori yang tidak ditemukan pun ditolak di sini: id yang tidak
            | menunjuk apa pun bukan penanda yang sah, dan membiarkannya lewat
            | akan menyimpan baris penanda yang tidak bisa ditampilkan.
            */
            if (!$satu || !$satu->ga_arrangeable) {
                $this->tolak($messagePrefix . '.arranged_not_allowed', [
                    'category' => $satu?->name ?? '-',
                ]);
            }

            /*
            | Tidak boleh sekaligus ditandai diurus GA dan punya baris.
            | Keduanya pernyataan yang saling meniadakan.
            */
            if (isset($terpakai[$id])) {
                $this->tolak($messagePrefix . '.arranged_has_items', [
                    'category' => $satu->name,
                ]);
            }
        }

        return $hasil;
    }

    /**
     * Menyimpan penanda kategori yang diurus GA.
     *
     * Ditulis ulang seluruhnya, bukan ditambal: penanda yang dicabut harus
     * benar-benar hilang, dan membandingkan satu per satu hanya menambah
     * jalan untuk keliru pada himpunan sekecil ini.
     *
     * Catatan pemohon ikut disimpan bila ada. Ia dikirim sebagai peta
     * id-kategori => catatan, dan HANYA yang kategorinya benar-benar
     * ditandai yang tersimpan: catatan untuk kategori yang penandanya
     * dimatikan bukan keterangan apa pun lagi, dan menyimpannya akan
     * menghidupkannya kembali diam-diam begitu penandanya menyala lagi.
     *
     * @param  int[]  $categoryIds
     * @param  array<int|string, mixed>  $notes  id kategori => catatan pemohon
     */
    public function syncArranged(
        string $documentType,
        int $documentId,
        array $categoryIds,
        array $notes = [],
    ): void {
        FundRequestArrangedCategory::query()
            ->for($documentType, $documentId)
            ->delete();

        foreach (array_unique(array_map('intval', $categoryIds)) as $id) {
            if ($id <= 0) {
                continue;
            }

            $catatan = trim((string) ($notes[$id] ?? $notes[(string) $id] ?? ''));

            FundRequestArrangedCategory::create([
                'document_type' => $documentType,
                'document_id' => $documentId,
                'expense_category_id' => $id,

                /*
                | Kosong disimpan sebagai null, bukan sebagai string kosong:
                | keduanya berarti "tidak ada permintaan khusus", dan dua
                | bentuk untuk satu arti memaksa setiap pembacanya memeriksa
                | dua-duanya -- salah satunya pasti terlupa.
                */
                'notes' => $catatan !== '' ? $catatan : null,
            ]);
        }
    }

    /**
     * Kategori yang diurus GA pada sebuah dokumen.
     *
     * @return int[]
     */
    public function arrangedFor(string $documentType, int $documentId): array
    {
        return FundRequestArrangedCategory::query()
            ->for($documentType, $documentId)
            ->pluck('expense_category_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Catatan pemohon pada kategori yang diurus GA.
     *
     * Terpisah dari arrangedFor() yang hanya mengembalikan idnya: yang
     * memanggilnya hampir selalu cukup dengan id, dan mengubah bentuk
     * kembaliannya akan menyentuh seluruh pemanggil demi sesuatu yang
     * hanya dibutuhkan formulir suntingnya.
     *
     * Yang catatannya kosong tidak ikut -- ketiadaan kunci dan kunci
     * bernilai kosong berarti sama, dan satu bentuk lebih baik dari dua.
     *
     * @return array<int, string>  id kategori => catatan
     */
    public function arrangedNotesFor(string $documentType, int $documentId): array
    {
        return FundRequestArrangedCategory::query()
            ->for($documentType, $documentId)
            ->whereNotNull('notes')
            ->get(['expense_category_id', 'notes'])
            ->mapWithKeys(fn ($baris): array => [
                (int) $baris->expense_category_id => (string) $baris->notes,
            ])
            ->filter(fn (string $catatan): bool => trim($catatan) !== '')
            ->all();
    }

    /**
     * Rincian berkategori untuk hasil cetak.
     *
     * Formulir kertasnya dibaca per kategori, bukan sebagai satu daftar
     * panjang, jadi cetakannya pun dikelompokkan begitu. Urutannya mengikuti
     * urutan masternya supaya dua dokumen yang berbeda tetap terbaca dengan
     * susunan yang sama.
     *
     * Kategori yang diurus GA ikut ditampilkan walau tanpa baris: kosongnya
     * disengaja, dan pembaca kertasnya tidak punya cara lain untuk
     * membedakannya dari kategori yang lupa diisi.
     *
     * @param  iterable<object>  $items  baris dokumennya
     * @param  string  $kolomNominal  amount pada FPU, realization_amount pada Realisasi
     * @return array<int, array{name: string, arranged: bool, notes: string|null, rows: array<int, object>, total: float}>
     */
    public function groupedForPrint(
        string $documentType,
        int $documentId,
        iterable $items,
        string $kolomNominal,
    ): array {
        $arranged = $this->arrangedFor($documentType, $documentId);
        $catatan = $this->arrangedNotesFor($documentType, $documentId);

        $barisPerKategori = [];

        foreach ($items as $item) {
            $kategoriId = (int) ($item->expense_category_id ?? 0);

            if ($kategoriId > 0) {
                $barisPerKategori[$kategoriId][] = $item;
            }
        }

        $kategori = BusinessTripExpenseCategory::query()
            ->whereIn('id', array_unique(array_merge(
                array_keys($barisPerKategori),
                $arranged,
            )))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $hasil = [];

        foreach ($kategori as $satu) {
            $baris = $barisPerKategori[(int) $satu->id] ?? [];

            $diurusGa = in_array((int) $satu->id, $arranged, true);

            $hasil[] = [
                'name' => (string) $satu->name,
                'arranged' => $diurusGa,

                /*
                | Hanya pada kategori yang diurus GA. Catatan pada kategori
                | berisi akan menyesatkan: di sana yang menjelaskan barisnya
                | sendiri, dan catatan sisa dari penanda yang sudah dimatikan
                | akan terbaca sebagai keterangan barisnya.
                */
                'notes' => $diurusGa ? ($catatan[(int) $satu->id] ?? null) : null,
                'rows' => $baris,
                'total' => array_reduce(
                    $baris,
                    fn (float $jumlah, $item): float => $jumlah + (float) ($item->{$kolomNominal} ?? 0),
                    0.0,
                ),
            ];
        }

        return $hasil;
    }

    /*
    |--------------------------------------------------------------------------
    | Pendukung
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  int[]  $arranged
     * @return int[]
     */
    private function kumpulkanKategori(array $items, array $arranged): array
    {
        $id = array_map(
            fn (array $baris): int => (int) ($baris['expense_category_id'] ?? 0),
            $items,
        );

        return array_values(array_unique(array_filter(
            array_merge($id, array_map('intval', $arranged)),
            fn (int $satu): bool => $satu > 0,
        )));
    }

    /**
     * Angka dari kiriman layar, atau null bila memang bukan angka.
     *
     * TIDAK ADA PEMBERSIHAN PEMISAH RIBUAN, dan itu disengaja.
     *
     * Versi pertama membuang titik supaya "24.200" terbaca 24200. Niatnya
     * benar, akibatnya tidak: "1500.75" ikut terbuang titiknya dan menjadi
     * 150075 -- seratus kali lipat, pada angka yang sama sekali tidak
     * bermasalah.
     *
     * Teks berformat memang mendua: "24.200" bisa berarti dua puluh empat
     * ribu dua ratus, bisa pula dua puluh empat koma dua. Tidak ada cara
     * memilihnya tanpa menebak, dan nominal adalah hal yang paling tidak
     * pantas ditebak.
     *
     * Maka yang diterima angka, bukan teks berformat. Layar mengirim angka
     * polos -- begitu pula kolom amount yang sudah ada sejak dulu.
     */
    private function angka(mixed $nilai): ?float
    {
        if (is_int($nilai) || is_float($nilai)) {
            return (float) $nilai;
        }

        $teks = trim((string) $nilai);

        return $teks !== '' && is_numeric($teks) ? (float) $teks : null;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function tolak(string $key, array $params = []): void
    {
        throw ValidationException::withMessages([
            'items' => [__($key, $params)],
        ]);
    }
}
