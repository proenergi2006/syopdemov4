<?php

namespace App\Services\FundRequest;

use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| Menyiapkan lampiran untuk ikut tercetak
|--------------------------------------------------------------------------
| Dipakai FPU, Realisasi, dan Claim. Ketiganya menyimpan lampirannya dengan
| bentuk kolom yang sama persis, dan ketiganya mencetaknya dengan aturan yang
| sama.
|
| GAMBAR IKUT MASUK, PDF TIDAK.
|
| dompdf menggambar halaman dari HTML; ia tidak bisa menyisipkan halaman PDF
| yang sudah jadi. Gambar bisa -- ia dipasang sebagai <img> biasa. Berkas PDF
| tidak, dan itu batas alatnya, bukan pilihan rancangan.
|
| Yang tidak bisa disisipkan TIDAK DIBUANG DIAM-DIAM. Nama berkasnya tetap
| didaftarkan pada halaman penutup, supaya yang memegang cetakannya tahu masih
| ada bukti lain di luar kertas yang ia pegang. Lampiran yang hilang tanpa
| jejak lebih berbahaya daripada lampiran yang disebutkan tetapi harus dibuka
| terpisah -- yang pertama membuat orang mengira berkasnya memang cuma segitu.
|
| Berkas yang tercatat di basis data tetapi hilang dari disk juga didaftarkan,
| dengan sebab yang berbeda. Keduanya perlu dibedakan: yang satu batas alat,
| yang satu tanda ada yang tidak beres.
|--------------------------------------------------------------------------
*/
class PrintAttachmentCollector
{
    /** Yang bisa digambar dompdf. */
    private const MIME_GAMBAR = ['image/jpeg', 'image/jpg', 'image/png'];

    private const EXT_GAMBAR = ['jpg', 'jpeg', 'png'];

    /**
     * Memilah lampiran menjadi yang bisa dicetak dan yang tidak.
     *
     * @param  Collection  $attachments  Baris lampiran salah satu modul
     * @param  array<string, string>  $labels  Kode jenis => kalimatnya di cetakan
     * @return array{
     *     images: array<int, array{label: string, name: string, path: string}>,
     *     others: array<int, array{label: string, name: string, reason: string}>,
     *     has_any: bool,
     * }
     */
    public function collect(Collection $attachments, array $labels): array
    {
        $gambar = [];
        $lainnya = [];

        foreach ($attachments as $lampiran) {
            $jenis = strtoupper(trim((string) $lampiran->attachment_type));

            $label = $labels[$jenis] ?? $jenis;

            $nama = (string) ($lampiran->original_filename ?: $lampiran->filename);

            $absolut = $this->berkasAda($lampiran->filepath);

            if ($absolut === null) {
                /*
                | Tercatat di basis data tetapi tidak ada di disk. Dibedakan
                | dari PDF: yang ini pertanda ada yang tidak beres, bukan batas
                | alat cetaknya.
                */
                $lainnya[] = [
                    'label' => $label,
                    'name' => $nama,
                    'reason' => 'MISSING',
                ];

                continue;
            }

            if (!$this->berupaGambar($lampiran)) {
                $lainnya[] = [
                    'label' => $label,
                    'name' => $nama,
                    'reason' => 'NOT_IMAGE',
                ];

                continue;
            }

            $gambar[] = [
                'label' => $label,
                'name' => $nama,
                'path' => $absolut,
            ];
        }

        return [
            'images' => $gambar,
            'others' => $lainnya,
            'has_any' => $gambar !== [] || $lainnya !== [],
        ];
    }

    /**
     * Gambar dua-dua per halaman.
     *
     * Satu gambar per halaman menyisakan separuh kertas kosong, dan cetakan
     * sepuluh nota menjadi sepuluh lembar yang setengahnya putih. Tiga sudah
     * terlalu kecil untuk dibaca angkanya.
     *
     * @param  array<int, array{label: string, name: string, path: string}>  $images
     * @return array<int, array<int, array{label: string, name: string, path: string}>>
     */
    public function paginate(array $images, int $perPage = 2): array
    {
        return $images === [] ? [] : array_chunk($images, max(1, $perPage));
    }

    /**
     * Jalur absolut berkasnya, atau null bila tidak ada di disk.
     *
     * Keberadaannya diperiksa di sini, bukan di tampilan: dompdf yang menemui
     * <img> menunjuk berkas hilang akan menggambar kotak rusak di tengah
     * cetakan resmi, tanpa satu pun keterangan kenapa.
     */
    private function berkasAda(mixed $filepath): ?string
    {
        if (blank($filepath)) {
            return null;
        }

        $absolut = storage_path('app/public/' . ltrim((string) $filepath, '/'));

        return is_file($absolut) ? $absolut : null;
    }

    /**
     * Apakah lampirannya berupa gambar.
     *
     * Diperiksa dari mime_type DAN dari akhiran namanya. mime_type datang dari
     * peramban saat diunggah dan tidak selalu terisi; akhiran nama saja juga
     * tidak cukup. Yang dipakai keduanya, karena salah tebak di sini berarti
     * dompdf mencoba menggambar berkas PDF sebagai gambar.
     */
    private function berupaGambar(mixed $lampiran): bool
    {
        $mime = strtolower(trim((string) $lampiran->mime_type));

        if ($mime !== '') {
            return in_array($mime, self::MIME_GAMBAR, true);
        }

        $ext = strtolower(pathinfo(
            (string) ($lampiran->filename ?: $lampiran->original_filename),
            PATHINFO_EXTENSION,
        ));

        return in_array($ext, self::EXT_GAMBAR, true);
    }
}
