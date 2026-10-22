{{--
    Gaya cetakan Perjalanan Dinas.

    Dipisah supaya pembungkus cetakan gabungan bisa memuatnya bersama
    gaya dokumen lain dalam satu <style>. Nama kelasnya memang banyak
    yang sama dengan cetakan lain, dan itu tidak apa-apa: aturannya
    identik, sehingga yang mana pun yang menang hasilnya sama.
--}}

        @page {
            margin: 22px 26px 28px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #243247;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.42;
            background: #ffffff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .text-center { text-align: center; }
        .text-right  { text-align: right; }
        .text-bold   { font-weight: bold; }
        .nowrap      { white-space: nowrap; }

        /*
        |--------------------------------------------------------------------------
        | Header
        |--------------------------------------------------------------------------
        */
        .header {
            margin-bottom: 14px;
            padding: 12px 0 11px;
            border-top: 5px solid #1f4e78;
            border-bottom: 1px solid #cdd7e3;
        }

        .header-table td {
            vertical-align: middle;
        }

        .company-logo {
            width: 140px;
            max-height: 72px;
        }

        .document-title {
            margin: 0;
            color: #17365d;
            font-size: 22px;
            font-weight: bold;
            line-height: 1.15;
            letter-spacing: 1px;
            text-align: right;
            text-transform: uppercase;
        }

        .document-subtitle {
            margin-top: 2px;
            color: #5b6b80;
            font-size: 9px;
            text-align: right;
            letter-spacing: 0.5px;
        }

        .document-number {
            margin-top: 6px;
            color: #1f4e78;
            font-size: 11px;
            font-weight: bold;
            text-align: right;
        }

        /*
        |--------------------------------------------------------------------------
        | Kartu informasi
        |--------------------------------------------------------------------------
        */
        .info-card {
            margin-bottom: 12px;
            padding: 9px 11px 7px;
            border: 1px solid #d7dfea;
            border-radius: 3px;
            background: #f8fafc;
        }

        .info-card-title {
            margin-bottom: 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #dde5ef;
            color: #17365d;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .info-table td {
            padding: 2px 0;
            vertical-align: top;
        }

        .info-label {
            width: 17%;
            color: #5b6b80;
        }

        .info-separator {
            width: 2%;
            color: #5b6b80;
        }

        .info-value {
            width: 31%;
            font-weight: bold;
        }

        /*
        | Kartu periode punya label terpanjang di seluruh cetakan --
        | "Hari & Tanggal (berangkat)". Pada 17% ia patah dua baris.
        |
        | Kolomnya dilebarkan hanya di kartu ini, bukan di .info-label,
        | karena kartu identitas memakai label pendek dan akan terlihat
        | renggang tanpa alasan. Kelebihannya diambil dari kolom "Jam",
        | yang hanya memuat lima karakter.
        */
        .info-table--periode .info-label {
            width: 27%;
            white-space: nowrap;
        }

        .info-table--periode .info-value {
            width: 37%;
        }

        .info-table--periode .info-label-jam {
            width: 5%;
        }

        .info-table--periode .info-value-jam {
            width: 27%;
        }

        /*
        |--------------------------------------------------------------------------
        | Tabel rincian
        |--------------------------------------------------------------------------
        */
        .section-title {
            margin: 12px 0 6px;
            color: #17365d;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .item-table th {
            padding: 6px 7px;
            border: 1px solid #b9c6d6;
            background: #1f4e78;
            color: #ffffff;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .item-table td {
            padding: 5px 7px;
            border: 1px solid #d7dfea;
            vertical-align: top;
        }

        .item-table tbody tr:nth-child(even) td {
            background: #f8fafc;
        }

        .item-no {
            width: 30px;
            text-align: center;
        }

        .item-date {
            width: 72px;
            text-align: center;
        }

        .item-amount {
            width: 120px;
            text-align: right;
        }

        .total-row td {
            padding: 6px 7px;
            border: 1px solid #b9c6d6;
            background: #eaf0f7;
            font-size: 10px;
            font-weight: bold;
        }

        .terbilang-box {
            margin-top: 7px;
            padding: 6px 9px;
            border: 1px dashed #b9c6d6;
            border-radius: 3px;
            background: #fbfcfe;
            font-style: italic;
        }

        .terbilang-label {
            color: #5b6b80;
            font-style: normal;
        }

        /*
        |--------------------------------------------------------------------------
        | Catatan
        |--------------------------------------------------------------------------
        */
        .notes-box {
            margin-top: 10px;
            padding: 7px 9px;
            border: 1px solid #d7dfea;
            border-radius: 3px;
            background: #f8fafc;
        }

        .notes-label {
            margin-bottom: 2px;
            color: #5b6b80;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }

        /*
        |--------------------------------------------------------------------------
        | Tanda tangan
        |--------------------------------------------------------------------------
        | page-break-inside: avoid menjaga blok tanda tangan tidak terbelah dua
        | halaman -- dokumen yang tanda tangannya terpotong sulit dipakai.
        |--------------------------------------------------------------------------
        */
        .signature-section {
            margin-top: 18px;
            page-break-inside: avoid;
        }

        .signature-frame td {
            padding: 0;
            border: 1px solid #d7dfea;
            vertical-align: top;
        }

        .signature-heading {
            padding: 5px 7px;
            border-bottom: 1px solid #d7dfea;
            background: #eaf0f7;
            color: #17365d;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.4px;
            text-align: center;
            text-transform: uppercase;
        }

        .signer-table td {
            padding: 7px 5px 8px;
            border: 0;
            text-align: center;
            vertical-align: top;
        }

        .signer-role {
            margin-bottom: 2px;
            color: #5b6b80;
            font-size: 8px;
            text-transform: uppercase;
        }

        .signature-area {
            height: 58px;
        }

        .signature-image {
            max-width: 130px;
            max-height: 56px;
        }

        .signer-name {
            padding-top: 2px;
            border-top: 1px solid #9fb0c4;
            font-size: 9px;
            font-weight: bold;
        }

        .signer-date {
            color: #7d8999;
            font-size: 8px;
        }

        .signer-empty {
            padding: 22px 6px;
            color: #9aa6b5;
            font-size: 9px;
            text-align: center;
        }

        .footer {
            margin-top: 14px;
            padding-top: 6px;
            border-top: 1px solid #e3e9f1;
            color: #7d8999;
            font-size: 8px;
            text-align: center;
        }
        /*
        |--------------------------------------------------------------------------
        | Halaman kedua: itinerary
        |--------------------------------------------------------------------------
        | Rundown perjalanan dipindah ke halaman sendiri. Di halaman pertama ia
        | akan mendesak blok tanda tangan turun, dan tanda tangan yang terdorong
        | ke halaman berikutnya membuat lembar pertama terlihat belum lengkap.
        |--------------------------------------------------------------------------
        */
        .page-break {
            page-break-before: always;
        }

        .itinerary-table th {
            padding: 6px 7px;
            border: 1px solid #cdd7e3;
            background: #eaf0f7;
            color: #17365d;
            font-size: 9px;
            font-weight: bold;
            text-align: left;
        }

        .itinerary-table td {
            padding: 6px 7px;
            border: 1px solid #dde4ed;
            font-size: 9px;
            vertical-align: top;
        }

        .itinerary-table tr {
            page-break-inside: avoid;
        }

        .itinerary-day {
            background: #f6f9fc;
        }

        .itinerary-empty {
            padding: 18px;
            color: #9aa6b5;
            text-align: center;
        }
