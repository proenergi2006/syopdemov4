@php
    /*
    |--------------------------------------------------------------------------
    | Halaman lampiran, disisipkan di belakang cetakan dokumen
    |--------------------------------------------------------------------------
    | Dipakai FPU, Realisasi, Claim, dan Perdin. Keempatnya mencetak lampiran
    | dengan aturan yang sama, jadi tampilannya satu berkas -- empat salinan
    | yang berawal sama selalu berakhir berbeda.
    |
    | SATU GAMBAR PER HALAMAN.
    |
    | Sebelumnya dua, untuk menghemat kertas. Alasan itu kalah oleh alasan
    | lampirannya diikutkan sejak awal: nota dan kuitansi disertakan supaya
    | angkanya bisa DIBACA oleh yang memeriksa, dan setengah halaman A4 terlalu
    | kecil untuk foto nota yang dipotret dari jarak biasa. Kertas yang dihemat
    | tidak ada gunanya kalau isinya tidak terbaca.
    |
    | Jumlah per halaman diatur di PrintAttachmentCollector::paginate(), bukan
    | di sini. Keduanya harus sejalan: ukuran di bawah dihitung untuk satu
    | gambar yang mengisi hampir seluruh tinggi halaman, jadi dua gambar dengan
    | ukuran ini akan mendorong yang kedua ke halaman berikutnya dengan kotak
    | yang terpotong di tengah.
    |
    | Tinggi gambarnya dibatasi, bukan dibiarkan apa adanya. Nota yang dipotret
    | tegak dan yang dipotret melintang punya perbandingan sisi yang jauh
    | berbeda; tanpa batas, foto beresolusi tinggi akan meluber melewati tepi
    | halaman.
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
            /*
            | Tinggi mengikuti gambarnya, tidak dipatok: dengan satu gambar per
            | halaman, mematoknya hanya membuat kotak bergaris panjang menganga
            | di bawah foto yang dipotret melintang.
            |
            | overflow tetap dipasang sebagai pengaman terakhir, kalau-kalau
            | dompdf mengabaikan batas tinggi pada gambar tertentu.
            */
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
            /*
            | Muat di dalam kotaknya tanpa terpotong, apa pun bentuk aslinya.
            |
            | 980px dihitung dari ruang yang benar-benar tersedia pada A4 tegak
            | dengan margin @page 22px/26px/28px:
            |
            |   tinggi halaman          1122px
            |   - margin atas + bawah     50px
            |   - judul "LAMPIRAN n"      28px
            |   - bingkai + jarak bawah   12px
            |   - keterangan berkas       18px
            |   ----------------------------------
            |   tersisa                 1014px
            |
            | Dipakai 980px, menyisakan sedikit kelonggaran untuk selisih tinggi
            | baris antar huruf. Lebihnya sengaja, bukan kelebihan hitung: satu
            | piksel yang meleset berarti satu halaman tambahan yang isinya
            | hanya tepi bawah sebuah foto.
            */
            max-width: 100%;
            max-height: 980px;
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
