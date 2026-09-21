@php
    /*
    |--------------------------------------------------------------------------
    | Cetakan Perjalanan Dinas (Perdin)
    |--------------------------------------------------------------------------
    | Bahasa Indonesia saja -- dokumen ini untuk kebutuhan internal, sama
    | seperti cetakan FPU.
    |
    | Dua halaman:
    |
    |   1. formulir perjalanan beserta kolom tanda tangan
    |   2. daftar perjalanan (itinerary)
    |
    | Itinerary sengaja dipisah. Di halaman pertama ia mendesak blok tanda
    | tangan turun, dan tanda tangan yang terdorong ke halaman berikutnya
    | membuat lembar pertama terlihat belum lengkap.
    |--------------------------------------------------------------------------
    */

    $tanggal = static fn ($value): string =>
        $value ? \Carbon\Carbon::parse($value)->format('d/m/Y') : '-';

    $tanggalJam = static fn ($value): string =>
        $value ? \Carbon\Carbon::parse($value)->format('d/m/Y H:i') : '-';

    /* Nama hari ikut dicetak: formulir aslinya menyebut "Hari & Tanggal". */
    $namaHari = [
        1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis',
        5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];

    $hariTanggal = static function ($value) use ($namaHari, $tanggal): string {
        if (!$value) {
            return '-';
        }

        $d = \Carbon\Carbon::parse($value);

        return ($namaHari[$d->dayOfWeekIso] ?? '') . ', ' . $tanggal($value);
    };

    $jam = static fn ($value): string => $value ? substr((string) $value, 0, 5) : '';

    $cabang = $trip->branchData ?? null;

    $cabangTeks = $cabang
        ? trim(($cabang->inisial_cabang ?? '-') . ' - ' . ($cabang->nama_cabang ?? '-'))
        : '-';

    /*
    | Lebar kolom penyetuju dibagi rata. Dibatasi minimal 1 agar tidak pernah
    | membagi dengan nol saat dokumen belum punya penyetuju sama sekali.
    */
    $jumlahPenyetuju = max($approvers->count(), 1);
    $lebarPenyetuju = 100 / $jumlahPenyetuju;
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Perdin {{ $trip->trip_number }}</title>

    <style>

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
    </style>
</head>

<body>
    {{-- ============================================================
         HALAMAN 1 -- FORMULIR
         ============================================================ --}}
    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 42%;">
                    <img
                        src="{{ public_path('logo-proenergi.png') }}"
                        class="company-logo"
                        alt="PT Pro Energi"
                    >
                </td>

                <td style="width: 58%;">
                    <div class="document-title">
                        Form Perjalanan Dinas
                    </div>

                    <div class="document-subtitle">
                        Perdin &middot; Perjalanan Dinas
                    </div>

                    <div class="document-number">
                        {{ $trip->trip_number ?? '-' }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- IDENTITAS PEMOHON --}}
    <div class="info-card">
        <div class="info-card-title">Identitas Pemohon</div>

        <table class="info-table">
            <tr>
                <td class="info-label">Nama Karyawan</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $trip->employee_name ?? '-' }}</td>

                <td class="info-label">Nomor Perdin</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $trip->trip_number ?? '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Departemen</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $trip->department_name ?? '-' }}</td>

                <td class="info-label">Tanggal Dokumen</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $tanggal($trip->date) }}</td>
            </tr>

            <tr>
                <td class="info-label">Jabatan</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $trip->position_name ?? '-' }}</td>

                <td class="info-label">Cabang</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $cabangTeks }}</td>
            </tr>
        </table>
    </div>

    {{-- PERIODE PERJALANAN --}}
    <div class="info-card">
        <div class="info-card-title">Periode Perjalanan Dinas</div>

        <table class="info-table info-table--periode">
            <tr>
                <td class="info-label">Tujuan</td>
                <td class="info-separator">:</td>
                <td class="info-value" colspan="4">{{ $trip->destination ?? '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Hari &amp; Tanggal (berangkat)</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $hariTanggal($trip->depart_date) }}</td>

                <td class="info-label info-label-jam">Jam</td>
                <td class="info-separator">:</td>
                <td class="info-value info-value-jam">{{ $jam($trip->depart_time) ?: '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Hari &amp; Tanggal (kembali)</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $hariTanggal($trip->return_date) }}</td>

                <td class="info-label info-label-jam">Jam</td>
                <td class="info-separator">:</td>
                <td class="info-value info-value-jam">{{ $jam($trip->return_time) ?: '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Lama Perjalanan</td>
                <td class="info-separator">:</td>
                <td class="info-value" colspan="4">
                    {{ $trip->duration_days }} hari
                </td>
            </tr>

            <tr>
                <td class="info-label">Keperluan</td>
                <td class="info-separator">:</td>
                <td class="info-value" colspan="4">{{ $trip->purpose ?? '-' }}</td>
            </tr>

            @if (!empty($trip->notes))
                <tr>
                    <td class="info-label">Catatan</td>
                    <td class="info-separator">:</td>
                    <td class="info-value" colspan="4">{{ $trip->notes }}</td>
                </tr>
            @endif
        </table>
    </div>

    {{-- ============================================================
         TANDA TANGAN
         Dua kolom, persis seperti cetakan FPU: seluruh penyetuju
         digabung di dalam kolom "Disetujui Oleh", berapa pun jumlahnya.
         ============================================================ --}}
    <div class="signature-section">
        <table class="signature-frame">
            <tr>
                <td style="width: 26%;">
                    <div class="signature-heading">Dibuat Oleh</div>

                    <table class="signer-table">
                        <tr>
                            <td>
                                <div class="signer-role">Pemohon</div>

                                <div class="signature-area">
                                    @if (!empty($requester->signature_file) && file_exists($requester->signature_file))
                                        <img
                                            src="{{ $requester->signature_file }}"
                                            class="signature-image"
                                            alt="Tanda tangan"
                                        >
                                    @endif
                                </div>

                                <div class="signer-name">{{ $requester->name ?? '-' }}</div>

                                <div class="signer-date">
                                    {{ $tanggalJam($requester->signed_at ?? null) }}
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>

                <td style="width: 74%;">
                    <div class="signature-heading">Disetujui Oleh</div>

                    <table class="signer-table">
                        <tr>
                            @forelse ($approvers as $approver)
                                <td style="width: {{ $lebarPenyetuju }}%;">
                                    <div class="signer-role">
                                        {{ $approver->label ?? 'Penyetuju' }}
                                    </div>

                                    <div class="signature-area">
                                        @if (!empty($approver->signature_file) && file_exists($approver->signature_file))
                                            <img
                                                src="{{ $approver->signature_file }}"
                                                class="signature-image"
                                                alt="Tanda tangan"
                                            >
                                        @endif
                                    </div>

                                    <div class="signer-name">{{ $approver->name ?? '-' }}</div>

                                    <div class="signer-date">
                                        {{ $tanggalJam($approver->signed_at ?? null) }}
                                    </div>
                                </td>
                            @empty
                                <td class="signer-empty">Belum ada persetujuan.</td>
                            @endforelse
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="footer">
        Dokumen ini dicetak dari sistem SYOP pada {{ now()->format('d/m/Y H:i') }} WIB.
    </div>

    {{-- ============================================================
         HALAMAN 2 -- DAFTAR PERJALANAN (ITINERARY)
         ============================================================ --}}
    <div class="page-break"></div>

    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 42%;">
                    <img
                        src="{{ public_path('logo-proenergi.png') }}"
                        class="company-logo"
                        alt="PT Pro Energi"
                    >
                </td>

                <td style="width: 58%;">
                    <div class="document-title">
                        Daftar Perjalanan
                    </div>

                    <div class="document-subtitle">
                        Itinerary &middot; {{ $trip->employee_name ?? '-' }}
                    </div>

                    <div class="document-number">
                        {{ $trip->trip_number ?? '-' }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="itinerary-table">
        <thead>
            <tr>
                <th style="width: 6%;">No</th>
                <th style="width: 20%;">Hari &amp; Tanggal</th>
                <th style="width: 17%;">Jam</th>
                <th style="width: 38%;">Keterangan</th>
                <th style="width: 19%;">PIC</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($trip->itineraries as $baris)
                <tr>
                    <td class="text-center">{{ $baris->sort_no }}</td>
                    <td class="nowrap">{{ $hariTanggal($baris->date) }}</td>
                    <td class="nowrap">{{ $baris->time_text }}</td>
                    <td>{{ $baris->description }}</td>
                    <td>{{ $baris->pic ?: '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="itinerary-empty">
                        Belum ada rundown perjalanan.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Dokumen ini dicetak dari sistem SYOP pada {{ now()->format('d/m/Y H:i') }} WIB.
    </div>
</body>
</html>