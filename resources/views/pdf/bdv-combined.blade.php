{{--
    Cetakan gabungan: voucher pencairan beserta dokumen yang dicairkannya.

    SUSUNANNYA SELALU SAMA -- voucher di atas, dokumen sumbernya menyusul
    sesuai urutan pemilihannya di voucher. Berkas yang tersusun berbeda
    tergantung dari mana dicetak tidak bisa dibandingkan dan sulit
    diarsipkan.

    Berbeda dari pdf/combined.blade.php yang menggabung tepat dua dokumen
    (dokumen keuangan + perdinnya), di sini jumlahnya tidak tentu: satu
    voucher boleh mencairkan beberapa FPU atau beberapa Claim sekaligus.
    Itulah sebabnya berkas ini berdiri sendiri alih-alih menumpang yang
    sudah ada -- memaksa yang lama menerima daftar akan membuatnya melayani
    dua bentuk sekaligus, dan yang satu pasti merusak yang lain.

    Gaya ketiga dokumen dimuat berdampingan. Banyak nama kelasnya sama, dan
    itu aman: aturannya memang identik, jadi yang mana pun yang menang
    hasilnya sama.
--}}
<!DOCTYPE html>
<html lang="{{ $bahasa ?? 'id' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $judul }}</title>

    <style>
        @include('pdf.partials.bdv-styles')
        @include('pdf.partials.cash-advance-styles')
        @include('pdf.partials.claim-styles')

        /*
        | Pemisah antar dokumen.
        |
        | Memakai elemen tersendiri, bukan page-break-after pada dokumen
        | sebelumnya: tiap badan dokumen dipakai juga sendirian, dan
        | menambahkan pemutus halaman padanya akan melahirkan satu halaman
        | kosong di belakang setiap cetakan tunggal.
        */
        .gabung-pemisah {
            page-break-before: always;
        }
    </style>
</head>

<body>
    {{--
        Vouchernya. Lampirannya dititipkan lewat include ini supaya ia
        tidak mengambil yang kebetulan ada di lingkup luar -- yaitu milik
        dokumen sumber yang menyusul di bawah.
    --}}
    @include('pdf.partials.bdv-body', [
        'attachmentPages' => $attachmentPages,
        'attachmentOthers' => $attachmentOthers,
    ])

    @foreach ($blokSumber as $blok)
        <div class="gabung-pemisah"></div>

        @include('pdf.partials.' . $blok['body'], $blok['data'])
    @endforeach
</body>
</html>
