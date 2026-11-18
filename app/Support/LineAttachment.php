<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/*
|--------------------------------------------------------------------------
| Lampiran per baris rincian
|--------------------------------------------------------------------------
| FPU, Realisasi, dan Claim mengirim lampirannya dengan bentuk yang sama:
| line_attachments[nomor baris][] -- dan ketiganya memvalidasinya dengan
| aturan yang sama pula, di enam tempat (store dan update masing-masing).
|
| Yang dikumpulkan di sini bukan aturannya, melainkan KALIMATNYA. Aturan
| mimes dan max tetap ditulis di controller, berdampingan dengan konstanta
| ATTACHMENT_MIMES dan ATTACHMENT_MAX_KB miliknya, supaya batas yang berlaku
| tetap terbaca di tempat ia dipakai.
|
| Sebabnya: pesan bawaan Laravel menyebut atribut mentah --
|
|     The line_attachments.0.0 must not be greater than 3000 kilobytes.
|
| -- dan "line_attachments.0.0" tidak berarti apa pun bagi orang yang sedang
| mengisi formulir. Ia tidak tahu berkas mana yang dimaksud, apalagi kalau
| rinciannya sepuluh baris dan tiap baris berisi tiga bukti.
|--------------------------------------------------------------------------
*/
class LineAttachment
{
    /**
     * Nama field tempat layar menyatakan BERAPA berkas yang ia kirim.
     *
     * Dibaca ExplainUploadFailure untuk mengetahui apakah ada berkas yang
     * hilang di perjalanan. Tanpa angka ini, berkas yang dibuang PHP karena
     * melewati max_file_uploads tidak meninggalkan jejak apa pun.
     */
    public const DECLARED_COUNT_FIELD = 'attachment_count';

    /**
     * Kalimat untuk tiap aturan, menggantikan bawaan Laravel.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'line_attachments.*.*.file' => __('upload.rule.file'),
            'line_attachments.*.*.uploaded' => __('upload.rule.uploaded'),
            'line_attachments.*.*.mimes' => __('upload.rule.mimes'),
            'line_attachments.*.*.max' => __('upload.rule.max'),
        ];
    }

    /**
     * Nama atribut yang terbaca orang, satu untuk tiap berkas yang dikirim.
     *
     * Disusun dari isi request, bukan dari daftar tetap: nomor barisnya baru
     * diketahui saat kirimannya tiba.
     *
     * @return array<string, string>
     */
    public static function attributes(Request $request): array
    {
        $nama = [];

        $grouped = $request->file('line_attachments');

        if (!is_array($grouped)) {
            return $nama;
        }

        foreach ($grouped as $baris => $berkas) {
            foreach ((array) $berkas as $urut => $satu) {
                $nama["line_attachments.{$baris}.{$urut}"] = __('upload.rule.attribute', [
                    'file' => $satu instanceof UploadedFile
                        ? ($satu->getClientOriginalName() ?: $satu->getFilename())
                        : '-',
                    'row' => (int) $baris + 1,
                ]);
            }
        }

        return $nama;
    }

    /**
     * Jumlah berkas yang benar-benar diterima PHP, berapa pun dalam sarangnya.
     *
     * @param  array<mixed>  $berkas  hasil $request->allFiles()
     */
    public static function count(array $berkas): int
    {
        $jumlah = 0;

        foreach ($berkas as $isi) {
            if (is_array($isi)) {
                $jumlah += self::count($isi);

                continue;
            }

            if ($isi instanceof UploadedFile) {
                $jumlah++;
            }
        }

        return $jumlah;
    }
}
