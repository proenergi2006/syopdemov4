{{--
    Peringatan kesehatan antrean.

    Sengaja polos dan ringkas: dibaca orang yang baru saja diberi tahu bahwa
    ada yang tidak beres, jadi yang dibutuhkan adalah angka dan sebabnya --
    bukan tata letak yang bagus.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Peringatan antrean</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Segoe UI,Arial,sans-serif;color:#2f3349;">

<div style="max-width:680px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e6e7ea;">

    @php
        $status = $snapshot['status'] ?? '-';

        $warna = match ($status) {
            'BERMASALAH' => '#ea5455',
            'PERHATIAN' => '#ff9f43',
            default => '#28c76f',
        };
    @endphp

    <div style="padding:20px 24px;background:{{ $warna }};color:#ffffff;">
        <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.85;">
            Pengawasan antrean {{ config('app.name') }}
        </div>
        <div style="font-size:20px;font-weight:700;margin-top:4px;">
            Status: {{ $status }}
        </div>
    </div>

    <div style="padding:24px;">

        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;">
            Pemeriksaan pada <strong>{{ $snapshot['checked_at'] ?? '-' }}</strong> menemukan hal berikut
            pada antrean pengiriman email.
        </p>

        {{-- Sebab, ditaruh paling atas karena itu yang menentukan tindakan. --}}
        @if (!empty($snapshot['reasons']))
            <ul style="margin:0 0 20px;padding-left:20px;font-size:14px;line-height:1.7;">
                @foreach ($snapshot['reasons'] as $alasan)
                    <li>{{ $alasan }}</li>
                @endforeach
            </ul>
        @endif

        <table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:20px;">
            <tr>
                <td style="padding:10px 12px;border:1px solid #e6e7ea;background:#fafbfc;">Job menunggu</td>
                <td style="padding:10px 12px;border:1px solid #e6e7ea;font-weight:600;">
                    {{ number_format((int) ($snapshot['waiting']['count'] ?? 0)) }}
                </td>
            </tr>
            <tr>
                <td style="padding:10px 12px;border:1px solid #e6e7ea;background:#fafbfc;">Menunggu terlama</td>
                <td style="padding:10px 12px;border:1px solid #e6e7ea;font-weight:600;">
                    @if (($snapshot['waiting']['oldest_waiting_minutes'] ?? null) === null)
                        &mdash;
                    @else
                        {{ number_format((float) $snapshot['waiting']['oldest_waiting_minutes'], 1) }} menit
                    @endif
                </td>
            </tr>
            <tr>
                <td style="padding:10px 12px;border:1px solid #e6e7ea;background:#fafbfc;">Job gagal (total)</td>
                <td style="padding:10px 12px;border:1px solid #e6e7ea;font-weight:600;">
                    {{ number_format((int) ($snapshot['failed']['total'] ?? 0)) }}
                </td>
            </tr>
            <tr>
                <td style="padding:10px 12px;border:1px solid #e6e7ea;background:#fafbfc;">
                    Job gagal ({{ $snapshot['thresholds']['failure_window_hours'] ?? 24 }} jam terakhir)
                </td>
                <td style="padding:10px 12px;border:1px solid #e6e7ea;font-weight:600;">
                    {{ number_format((int) ($snapshot['failed']['recent'] ?? 0)) }}
                </td>
            </tr>
        </table>

        @if (!empty($snapshot['failed']['by_job']))
            <div style="font-size:13px;font-weight:700;margin-bottom:8px;">Kegagalan menurut jenis</div>

            <table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:20px;">
                <tr style="background:#fafbfc;">
                    <th style="padding:8px 10px;border:1px solid #e6e7ea;text-align:left;">Jenis</th>
                    <th style="padding:8px 10px;border:1px solid #e6e7ea;text-align:right;">Jumlah</th>
                    <th style="padding:8px 10px;border:1px solid #e6e7ea;text-align:left;">Contoh pesan</th>
                </tr>
                @foreach ($snapshot['failed']['by_job'] as $baris)
                    <tr>
                        <td style="padding:8px 10px;border:1px solid #e6e7ea;">{{ $baris['job'] }}</td>
                        <td style="padding:8px 10px;border:1px solid #e6e7ea;text-align:right;">{{ $baris['count'] }}</td>
                        <td style="padding:8px 10px;border:1px solid #e6e7ea;color:#6e6b7b;">
                            {{ \Illuminate\Support\Str::limit($baris['sample_error'], 110) }}
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif

        <a href="{{ $url }}"
           style="display:inline-block;padding:11px 20px;background:#7367f0;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;">
            Buka halaman pemantauan
        </a>

        <p style="margin:20px 0 0;font-size:12px;color:#6e6b7b;line-height:1.6;">
            Email ini dikirim langsung tanpa melewati antrean, supaya tetap sampai walaupun
            antreannya sedang bermasalah.
        </p>

    </div>
</div>

</body>
</html>
