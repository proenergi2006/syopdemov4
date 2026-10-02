@php
    /*
    |--------------------------------------------------------------------------
    | Halaman lampiran, disisipkan di belakang cetakan dokumen
    |--------------------------------------------------------------------------
    | Dipakai FPU, Realisasi, dan Claim. Ketiganya mencetak lampiran dengan
    | aturan yang sama, jadi tampilannya satu berkas -- tiga salinan yang
    | berawal sama selalu berakhir berbeda.
    |
    | Dua gambar per halaman. Satu menyisakan separuh kertas kosong; tiga sudah
    | terlalu kecil untuk dibaca angkanya.
    |
    | Tingginya dipatok, bukan dibiarkan mengikuti gambarnya. Nota yang
    | dipotret tegak dan yang dipotret melintang punya perbandingan sisi yang
    | jauh berbeda; tanpa patokan, satu halaman bisa memuat dua nota mungil dan
    | halaman berikutnya satu nota yang meluber ke halaman ketiga.
    |
    | Seluruhnya di dalam @if: dokumen tanpa lampiran tidak boleh menghasilkan
    | satu pun halaman tambahan, bahkan halaman kosong berjudul "Lampiran".
    |--------------------------------------------------------------------------
    */

    $halamanGambar = $attachmentPages ?? [];
    $tidakTersisip = $attachmentOthers ?? [];
@endphp

@if ($halamanGambar !== [] || $tidakTersisip !== [])
    <style>
        .lampiran-page {
            page-break-before: always;
        }

        .lampiran-head {
            border-bottom: 1px solid #333;
            margin-bottom: 10px;
            padding-bottom: 4px;
            font-size: 11px;
            font-weight: bold;
        }

        .lampiran-item {
            margin-bottom: 10px;
            /* Dipatok supaya dua gambar selalu muat pada satu halaman. */
            height: 340px;
            overflow: hidden;
            border: 1px solid #bbb;
            text-align: center;
        }

        .lampiran-cap {
            border-bottom: 1px solid #ddd;
            padding: 3px 6px;
            background: #f2f2f2;
            font-size: 9px;
            text-align: left;
        }

        .lampiran-cap b {
            font-size: 9px;
        }

        .lampiran-img {
            /* Muat di dalam kotaknya tanpa terpotong, apa pun bentuk aslinya. */
            max-width: 100%;
            max-height: 300px;
        }

        .lampiran-lain {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }

        .lampiran-lain th,
        .lampiran-lain td {
            border: 1px solid #bbb;
            padding: 4px 6px;
            text-align: left;
        }

        .lampiran-lain th {
            background: #f2f2f2;
        }

        .lampiran-catatan {
            margin-bottom: 8px;
            font-size: 9px;
            font-style: italic;
        }
    </style>

    {{-- Gambar: dua per halaman --}}
    @foreach ($halamanGambar as $nomorHalaman => $isiHalaman)
        <div class="lampiran-page">
            <div class="lampiran-head">
                LAMPIRAN {{ $nomorHalaman + 1 }} DARI {{ count($halamanGambar) }}
            </div>

            @foreach ($isiHalaman as $lampiran)
                <div class="lampiran-item">
                    <div class="lampiran-cap">
                        <b>{{ $lampiran['label'] }}</b> &mdash; {{ $lampiran['name'] }}
                    </div>

                    <img
                        src="{{ $lampiran['path'] }}"
                        alt="{{ $lampiran['name'] }}"
                        class="lampiran-img"
                    >
                </div>
            @endforeach
        </div>
    @endforeach

    {{--
        Yang tidak bisa disisipkan tetap didaftarkan.

        Lampiran yang hilang tanpa jejak lebih berbahaya daripada lampiran yang
        disebutkan tetapi harus dibuka terpisah: yang pertama membuat pembacanya
        mengira berkasnya memang cuma segitu.
    --}}
    @if ($tidakTersisip !== [])
        <div class="lampiran-page">
            <div class="lampiran-head">
                LAMPIRAN YANG TIDAK IKUT TERCETAK
            </div>

            <div class="lampiran-catatan">
                Berkas berikut ada pada dokumen ini tetapi tidak dapat disatukan
                ke dalam cetakan. Bukalah dari aplikasi bila diperlukan.
            </div>

            <table class="lampiran-lain">
                <tr>
                    <th style="width: 26%;">Bagian</th>
                    <th>Nama Berkas</th>
                    <th style="width: 30%;">Keterangan</th>
                </tr>

                @foreach ($tidakTersisip as $lampiran)
                    <tr>
                        <td>{{ $lampiran['label'] }}</td>
                        <td>{{ $lampiran['name'] }}</td>
                        <td>
                            @if ($lampiran['reason'] === 'MISSING')
                                Berkasnya tidak ditemukan di penyimpanan
                            @else
                                Berformat PDF, dibuka terpisah
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
@endif
