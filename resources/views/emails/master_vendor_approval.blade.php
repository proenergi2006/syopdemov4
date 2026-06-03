@php
    $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', config('app.url'))), '/');
    $vendorUrl = $frontendUrl . '/master/vendor';
    $logoUrl = 'https://syop.proenergi.com/proEnergi/libraries/themes/images/logo-proenergi.png';

    $title = match ($mode ?? 'approval_request') {
        'approved' => 'Vendor Telah Disetujui',
        'rejected' => 'Vendor Ditolak',
        default => 'Approval Master Vendor',
    };

    $description = match ($mode ?? 'approval_request') {
        'approved' => 'Vendor telah disetujui oleh ' . optional($actor)->name . '.',
        'rejected' => 'Vendor telah ditolak oleh ' . optional($actor)->name . '.',
        default => 'Terdapat data Master Vendor yang membutuhkan review dan approval Anda.',
    };

    $displayStatus = match ($mode ?? 'approval_request') {
        'approved' => 'APPROVED',
        'rejected' => 'REJECTED',
        default => $vendor->status_approval,
    };

    $statusStyle = match (strtoupper($displayStatus ?? '')) {
        'APPROVED' => ['background' => '#dcfce7', 'color' => '#166534'],
        'REJECTED' => ['background' => '#fee2e2', 'color' => '#991b1b'],
        default => ['background' => '#fef3c7', 'color' => '#92400e'],
    };
@endphp

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
</head>

<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,sans-serif;color:#1f2937;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:32px 0;">
<tr>
<td align="center">
<table width="660" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e5e7eb;">

<tr>
<td style="padding:24px 30px;background:#0f766e;">
    <img src="{{ $logoUrl }}" alt="Pro Energi" style="height:40px;display:block;">
</td>
</tr>

<tr>
<td style="padding:30px;">
    <div style="display:inline-block;padding:6px 12px;border-radius:999px;background:#ccfbf1;color:#115e59;font-size:12px;font-weight:bold;letter-spacing:.4px;margin-bottom:14px;">
        MASTER DATA VENDOR
    </div>

    <h2 style="margin:0 0 10px;font-size:24px;color:#111827;">
        {{ $title }}
    </h2>

    <p style="margin:0 0 22px;font-size:14px;line-height:1.7;color:#4b5563;">
        Dear <strong>{{ $recipient->name }}</strong>,<br>
        {{ $description }}
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:18px 0;border-radius:12px;overflow:hidden;">
        <tr>
            <td style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;width:35%;font-size:13px;color:#64748b;">Nama Vendor</td>
            <td style="padding:14px;border:1px solid #e5e7eb;font-size:14px;font-weight:bold;color:#111827;">{{ $vendor->nama_vendor }}</td>
        </tr>
        <tr>
            <td style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#64748b;">Kode Vendor</td>
            <td style="padding:14px;border:1px solid #e5e7eb;font-size:14px;">{{ $vendor->kode_vendor ?? '-' }}</td>
        </tr>
        <tr>
            <td style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#64748b;">Inisial</td>
            <td style="padding:14px;border:1px solid #e5e7eb;font-size:14px;">{{ $vendor->inisial_vendor ?? '-' }}</td>
        </tr>
        <tr>
            <td style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#64748b;">Status Approval</td>
            <td style="padding:14px;border:1px solid #e5e7eb;font-size:14px;">
                <span style="display:inline-block;padding:6px 12px;border-radius:999px;background:{{ $statusStyle['background'] }};color:{{ $statusStyle['color'] }};font-weight:bold;font-size:12px;letter-spacing:.3px;">
                    {{ strtoupper($displayStatus) }}
                </span>
            </td>
        </tr>

        @if (!empty($notes))
            <tr>
                <td style="padding:14px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#64748b;">Catatan</td>
                <td style="padding:14px;border:1px solid #e5e7eb;font-size:14px;">{{ $notes }}</td>
            </tr>
        @endif
    </table>

    <div style="margin:24px 0;padding:16px 18px;border-radius:12px;background:#f0fdfa;border:1px solid #99f6e4;color:#115e59;font-size:13px;line-height:1.6;">
        Email ini khusus untuk proses <strong>review Vendor</strong>. Mohon lakukan pengecekan data vendor sebelum approval.
    </div>

    <p style="margin:24px 0;">
        <a href="{{ $vendorUrl }}"
           style="display:inline-block;padding:13px 22px;background:#0f766e;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:bold;font-size:14px;">
            Buka Master Vendor
        </a>
    </p>

    <p style="margin:20px 0 0;font-size:13px;color:#64748b;">
        Email ini dikirim otomatis oleh sistem SYOP v4. Mohon tidak membalas email ini.
    </p>
</td>
</tr>

<tr>
<td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e5e7eb;font-size:12px;color:#94a3b8;text-align:center;">
    Copyright © {{ date('Y') }} <a href="https://proenergi.com/en" style="color:#0f766e;">Proenergi.com</a> All Right Reserved.
</td>
</tr>

</table>
</td>
</tr>
</table>
</body>
</html>