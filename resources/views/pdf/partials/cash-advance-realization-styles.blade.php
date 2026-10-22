{{--
    Gaya cetakan Realisasi FPU.

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

        .section-title {
            margin: 12px 0 6px;
            color: #17365d;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }

        .item-table th {
            padding: 6px 6px;
            border: 1px solid #b9c6d6;
            background: #1f4e78;
            color: #ffffff;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .item-table td {
            padding: 5px 6px;
            border: 1px solid #d7dfea;
            vertical-align: top;
        }

        .item-table tbody tr:nth-child(even) td {
            background: #f8fafc;
        }

        .item-no {
            width: 28px;
            text-align: center;
        }

        .item-date {
            width: 68px;
            text-align: center;
        }

        .item-amount {
            width: 96px;
            text-align: right;
        }

        .item-extra-tag {
            color: #8a6d1f;
            font-size: 8px;
        }

        /* Rincian perjalanan dinas, dikelompokkan menurut kategori. */
        .cat-row td {
            padding: 5px 7px;
            border: 1px solid #b9c6d6;
            background: #eaf0f7;
            font-size: 10px;
            font-weight: bold;
        }

        .cat-ga {
            float: right;
            font-weight: normal;
            font-size: 8px;
            color: #6b7785;
        }

        .cat-empty td {
            padding: 7px;
            color: #7d8999;
            font-style: italic;
        }

        .cat-subtotal td {
            padding: 4px 7px;
            border: 1px solid #d7dfea;
            background: #f3f7fb;
            font-size: 9px;
        }

        .item-qty {
            width: 46px;
            text-align: center;
        }

        .item-unit {
            width: 96px;
            text-align: right;
        }

        .total-row td {
            padding: 6px;
            border: 1px solid #b9c6d6;
            background: #eaf0f7;
            font-size: 10px;
            font-weight: bold;
        }

        /*
        |--------------------------------------------------------------------------
        | Ringkasan selisih
        |--------------------------------------------------------------------------
        | Ditonjolkan karena inilah angka yang harus ditindaklanjuti Finance.
        |--------------------------------------------------------------------------
        */
        .difference-box {
            margin-top: 9px;
            padding: 8px 11px;
            border: 1px solid #b9c6d6;
            border-left: 4px solid #1f4e78;
            border-radius: 3px;
            background: #f3f7fb;
        }

        .difference-table td {
            padding: 2px 0;
        }

        .difference-label {
            color: #5b6b80;
        }

        .difference-value {
            text-align: right;
            font-weight: bold;
        }

        .difference-final td {
            padding-top: 5px;
            border-top: 1px solid #c6d2e0;
            font-size: 11px;
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
