{{--
    Cetakan gabungan: dokumen keuangan beserta perjalanan dinasnya.

    SUSUNANNYA SELALU SAMA, dari pintu mana pun ia dicetak. FPU, Claim, atau
    Realisasi selalu di atas; perdinnya menyusul. Mencetak dari halaman perdin
    pun menghasilkan susunan yang sama -- berkas yang tersusun berbeda
    tergantung dari mana dicetak tidak bisa dibandingkan dan sulit diarsipkan.

    Tiap dokumen membawa lampirannya sendiri, tepat di belakangnya: dokumen
    keuangan membawa bukti pengeluarannya, perdin membawa berkas pemesanan GA.
    Dengan begitu tiap bagian lengkap bila dipisahkan.

    Gaya kedua dokumen dimuat berdampingan. Banyak nama kelasnya sama, dan itu
    aman: aturannya identik, jadi yang mana pun yang menang hasilnya sama.
    Diperiksa sebelum cara ini dipilih -- nol aturan yang bertabrakan.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $judul }}</title>

    <style>
        @include('pdf.partials.' . $gayaDokumen)
        @include('pdf.partials.business-trip-styles')

        /*
        | Pemisah antar dokumen.
        |
        | Memakai elemen tersendiri, bukan page-break-after pada dokumen
        | sebelumnya: dokumen itu dipakai juga sendirian, dan menambahkan
        | pemutus halaman padanya akan melahirkan satu halaman kosong di
        | belakang setiap cetakan tunggal.
        */
        .gabung-pemisah {
            page-break-before: always;
        }
    </style>
</head>

<body>
    {{--
        Dokumen keuangannya berikut lampirannya. Partial badannya sendiri yang
        memanggil lampiran, jadi berkasnya dititipkan lewat include ini --
        kalau tidak, ia akan mengambil lampiran yang kebetulan ada di lingkup
        luar, yaitu milik perdinnya.
    --}}
    @include('pdf.partials.' . $badanDokumen, [
        'attachmentPages' => $attachmentPages,
        'attachmentOthers' => $attachmentOthers,
    ])

    <div class="gabung-pemisah"></div>

    @include('pdf.partials.business-trip-body', [
        'attachmentPages' => $tripAttachmentPages,
        'attachmentOthers' => $tripAttachmentOthers,
    ])
</body>
</html>
