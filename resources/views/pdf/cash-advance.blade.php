@php
    /*
    |--------------------------------------------------------------------------
    | Cetakan FPU (Form Pengajuan Uang)
    |--------------------------------------------------------------------------
    | Bahasa Indonesia saja -- dokumen ini untuk kebutuhan internal.
    |
    | Kolom tanda tangan hanya dua: "Dibuat Oleh" dan "Disetujui Oleh".
    | Bila penyetujunya lebih dari satu orang, semuanya digabung berdampingan
    | di dalam kolom Disetujui.
    |--------------------------------------------------------------------------
    */

    $rupiah = static fn ($value): string =>
        'Rp ' . number_format((float) $value, 0, ',', '.');

    $tanggal = static fn ($value): string =>
        $value ? \Carbon\Carbon::parse($value)->format('d/m/Y') : '-';

    $tanggalJam = static fn ($value): string =>
        $value ? \Carbon\Carbon::parse($value)->format('d/m/Y H:i') : '-';

    $cabang = $fpu->branchData
        ? trim(($fpu->branchData->inisial_cabang ?? '-') . ' - ' . ($fpu->branchData->nama_cabang ?? '-'))
        : '-';

    $department = $fpu->departmentData
        ? trim(($fpu->departmentData->kode ?? '-') . ' - ' . ($fpu->departmentData->nama ?? '-'))
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
    <title>FPU {{ $fpu->advance_number }}</title>

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
    </style>
</head>

<body>
    {{-- ============================================================
         HEADER
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
                        Form Pengajuan Uang
                    </div>

                    <div class="document-subtitle">
                        FPU &middot; Pengajuan Dana
                    </div>

                    <div class="document-number">
                        {{ $fpu->advance_number ?? '-' }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         INFORMASI DOKUMEN
         ============================================================ --}}
    <div class="info-card">
        <div class="info-card-title">Informasi Pengajuan</div>

        <table class="info-table">
            <tr>
                <td class="info-label">Nomor FPU</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $fpu->advance_number ?? '-' }}</td>

                <td class="info-label">Tanggal</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $tanggal($fpu->date) }}</td>
            </tr>

            <tr>
                <td class="info-label">Cabang</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $cabang }}</td>

                <td class="info-label">Department</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $department }}</td>
            </tr>

            <tr>
                <td class="info-label">Keterangan Transaksi</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{ $fpu->transactionCategory->name ?? '-' }}
                </td>

                <td class="info-label">Sifat Pengajuan</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $fpu->request_type ?? '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Diajukan Oleh</td>
                <td class="info-separator">:</td>
                <td class="info-value">
                    {{ $fpu->submitter->name ?? $fpu->creator->name ?? '-' }}
                </td>

                <td class="info-label">Status</td>
                <td class="info-separator">:</td>
                <td class="info-value">{{ $fpu->status ?? '-' }}</td>
            </tr>

            <tr>
                <td class="info-label">Perihal</td>
                <td class="info-separator">:</td>
                <td class="info-value" colspan="4">{{ $fpu->subject ?? '-' }}</td>
            </tr>
        </table>
    </div>

    {{-- ============================================================
         RINCIAN PENGAJUAN
         ============================================================ --}}
    <div class="section-title">Rincian Pengajuan</div>

    <table class="item-table">
        <thead>
            <tr>
                <th class="item-no">No</th>
                <th class="item-date">Tanggal</th>
                <th>Deskripsi</th>
                <th class="item-amount">Nominal</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($fpu->items as $index => $item)
                <tr>
                    <td class="item-no">{{ $index + 1 }}</td>
                    <td class="item-date">{{ $tanggal($item->date) }}</td>
                    <td>{{ $item->description }}</td>
                    <td class="item-amount nowrap">{{ $rupiah($item->amount) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center" style="padding: 14px;">
                        Tidak ada rincian pengajuan.
                    </td>
                </tr>
            @endforelse

            <tr class="total-row">
                <td colspan="3" class="text-right">Total Pengajuan</td>
                <td class="item-amount nowrap">{{ $rupiah($totalAmount) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="terbilang-box">
        <span class="terbilang-label">Terbilang:</span> {{ $terbilang }}
    </div>

    @if (!empty($fpu->notes))
        <div class="notes-box">
            <div class="notes-label">Catatan</div>
            <div>{{ $fpu->notes }}</div>
        </div>
    @endif

    @if (!empty($fpu->disbursed_at))
        <div class="notes-box">
            <div class="notes-label">Pencairan</div>
            <div>
                Dibayarkan pada {{ $tanggalJam($fpu->disbursed_at) }}
                oleh {{ $fpu->disburser->name ?? '-' }}.
                @if (!empty($fpu->disbursement_notes))
                    {{ $fpu->disbursement_notes }}
                @endif
            </div>
        </div>
    @endif

    {{-- ============================================================
         TANDA TANGAN
         ============================================================
         Dua kolom saja. Seluruh penyetuju digabung di dalam kolom
         "Disetujui Oleh", berapa pun jumlahnya.
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
</body>
</html>
