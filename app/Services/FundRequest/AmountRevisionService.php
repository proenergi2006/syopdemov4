<?php

namespace App\Services\FundRequest;

use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Finance membetulkan nominal saat menerima berkas
|--------------------------------------------------------------------------
| Dipakai FPU dan Claim. Keduanya berbentuk sama -- dokumen dengan baris
| rincian bertanggal, berketerangan, dan bernominal -- dan keduanya direvisi
| pada saat yang sama, yaitu ketika berkasnya diterima. Dua salinan aturan
| yang sama cepat atau lambat akan berbeda, dan yang berbeda di sini adalah
| uang orang.
|
| TIGA HAL YANG DIJAGA.
|
| Pertama, angka pemohon tidak hilang. Nominal semula disalin ke
| original_amount sebelum ditimpa, sekali saja -- revisi kedua tidak boleh
| menggeser titik pembandingnya, kalau tidak "yang diajukan" perlahan berubah
| menjadi "yang terakhir disetujui Finance".
|
| Kedua, tidak berubah berarti tidak ada revisi. Finance yang membuka modal,
| melihat angkanya sudah benar, lalu menutupnya, tidak sedang merevisi apa
| pun: ia tidak butuh permission revisi, tidak butuh alasan, dan pemohonnya
| tidak perlu dikabari apa-apa.
|
| Ketiga, yang berubah harus punya alasan. Angka yang sudah disetujui
| berjenjang lalu berubah di meja penerimaan tanpa sepatah kata pun adalah
| hal yang tidak bisa dipertanggungjawabkan kepada siapa pun -- termasuk
| kepada Finance sendiri, enam bulan kemudian.
|--------------------------------------------------------------------------
*/
class AmountRevisionService
{
    /**
     * Menerapkan revisi nominal pada sebuah dokumen beserta rinciannya.
     *
     * Dokumennya dan barisnya ikut tersimpan di sini; pemanggilnya hanya
     * perlu memastikan semuanya berada di dalam satu transaksi.
     *
     * @param  Model  $document  FPU atau Claim yang sedang diterima
     * @param  array<int, array{id?: mixed, amount?: mixed}>  $requested
     * @return array{
     *     ok: bool,
     *     message_key: string|null,
     *     status: int,
     *     changed: bool,
     *     lines: array<int, array{description: string, old: string, new: string}>,
     *     old_total: string|null,
     *     new_total: string|null,
     * }
     */
    public function apply(
        Model $document,
        string $permission,
        mixed $user,
        array $requested,
        ?string $notes,
    ): array {
        $kosong = [
            'ok' => true,
            'message_key' => null,
            'status' => 200,
            'changed' => false,
            'lines' => [],
            'old_total' => null,
            'new_total' => null,
        ];

        if ($requested === []) {
            return $kosong;
        }

        $baris = $document->items()->get()->keyBy('id');

        /*
        | Nominal baru, dipetakan menurut id barisnya.
        |
        | Baris yang tidak disebut sama sekali dianggap tidak berubah -- layar
        | boleh mengirim hanya yang disentuh. Tetapi id yang TIDAK dikenali
        | ditolak mentah-mentah: kiriman semacam itu bukan salah ketik, ia
        | menunjuk baris milik dokumen lain.
        */
        $perubahan = [];

        foreach ($requested as $satu) {
            $id = (int) ($satu['id'] ?? 0);

            if (!$baris->has($id)) {
                return [
                    'ok' => false,
                    'message_key' => 'fund_request_messages.revision.unknown_item',
                    'status' => 422,
                    'changed' => false,
                    'lines' => [],
                    'old_total' => null,
                    'new_total' => null,
                ];
            }

            $lama = $this->uang($baris[$id]->amount);
            $baru = $this->uang($satu['amount'] ?? null);

            if ($baru !== $lama) {
                $perubahan[$id] = $baru;
            }
        }

        if ($perubahan === []) {
            return $kosong;
        }

        /* Sejak sini, yang terjadi adalah revisi sungguhan. */
        if (!$user || !$user->hasPermission($permission)) {
            return [
                'ok' => false,
                'message_key' => 'fund_request_messages.revision.forbidden',
                'status' => 403,
                'changed' => false,
                'lines' => [],
                'old_total' => null,
                'new_total' => null,
            ];
        }

        if (trim((string) $notes) === '') {
            return [
                'ok' => false,
                'message_key' => 'fund_request_messages.revision.notes_required',
                'status' => 422,
                'changed' => false,
                'lines' => [],
                'old_total' => null,
                'new_total' => null,
            ];
        }

        $totalLama = $this->uang($document->total_amount);

        $rincian = [];

        foreach ($perubahan as $id => $baru) {
            $item = $baris[$id];

            $lama = $this->uang($item->amount);

            /*
            | Disalin sekali saja. Revisi kedua membandingkan dirinya dengan
            | angka pemohon, bukan dengan hasil revisi pertama.
            */
            if ($item->original_amount === null) {
                $item->original_amount = $lama;
            }

            $item->amount = $baru;
            $item->save();

            $rincian[] = [
                'description' => (string) $item->description,
                'old' => $lama,
                'new' => $baru,
            ];
        }

        /*
        | Totalnya dihitung ulang dari barisnya, bukan digeser sebesar
        | selisihnya. Menggeser total mewarisi setiap pembulatan yang pernah
        | terjadi sebelumnya; menjumlah ulang selalu cocok dengan apa yang
        | dibaca orang di layar.
        */
        $totalBaru = $this->uang(
            $document->items()->get()->sum(fn ($b): float => (float) $b->amount),
        );

        $document->amount_revision_notes = trim((string) $notes);

        if ($document->original_total_amount === null) {
            $document->original_total_amount = $totalLama;
        }

        $document->total_amount = $totalBaru;
        $document->save();

        return [
            'ok' => true,
            'message_key' => null,
            'status' => 200,
            'changed' => true,
            'lines' => $rincian,
            'old_total' => $totalLama,
            'new_total' => $totalBaru,
        ];
    }

    /**
     * Nominal sebagai teks dua desimal.
     *
     * Dibandingkan sebagai teks, bukan sebagai float: 1500000.00 dan
     * 1499999.999999 adalah dua angka berbeda bagi float, padahal keduanya
     * rupiah yang sama persis. Perbandingan float juga membuat baris yang
     * tidak disentuh sesekali dianggap berubah, dan itu melahirkan revisi
     * yang tidak pernah diminta siapa pun.
     */
    private function uang(mixed $nilai): string
    {
        return number_format((float) $nilai, 2, '.', '');
    }
}
