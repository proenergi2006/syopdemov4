@php
    /*
    |--------------------------------------------------------------------------
    | Email pemesanan perjalanan
    |--------------------------------------------------------------------------
    | Tabel bersarang dan gaya inline, sama seperti email perdin lainnya --
    | Outlook mengabaikan CSS eksternal dan flexbox.
    |
    | Yang dibaca pemohon di sini bukan status dokumennya, melainkan keterangan
    | yang akan ia bawa: nama hotel, nomor booking, tanggal masuk. Maka itulah
    | yang diletakkan paling atas, dan rincian perdinnya menyusul sebagai
    | pengingat perjalanan mana yang dimaksud.
    |--------------------------------------------------------------------------
    */

    $dibatalkan = ($mode ?? 'created') === 'cancelled';

    $accentColor = $dibatalkan ? '#b3261e' : '#1b7f4f';

    $title = __('mail.business_trip.arrangement.title_' . ($dibatalkan ? 'cancelled' : 'created'));

    $description = __('mail.business_trip.arrangement.description_' . ($dibatalkan ? 'cancelled' : 'created'));

    $tanggal = static fn ($nilai): string =>
        $nilai ? \Carbon\Carbon::parse($nilai)->format('d/m/Y') : '-';

    $jam = static fn ($nilai): string => $nilai ? substr((string) $nilai, 0, 5) : '';

    $jenis = __('mail.business_trip.arrangement.type_' . strtolower($arrangement->type));

    /* Rentang menginap hanya ditulis bila memang diisi -- transport sering tidak punya. */
    $rentang = null;

    if ($arrangement->starts_at) {
        $rentang = $tanggal($arrangement->starts_at);

        if ($arrangement->ends_at) {
            $rentang .= ' → ' . $tanggal($arrangement->ends_at);
        }
    }

    $logoUrl = 'https://syop.proenergi.com/proEnergi/libraries/themes/images/logo-proenergi.png';
@endphp

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>

<body style="margin:0; padding:0; background:#f1f4f8; font-family:Arial, Helvetica, sans-serif; color:#243247;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f4f8; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:100%; background:#ffffff; border-radius:6px; overflow:hidden; border:1px solid #dfe6ef;">

                    {{-- HEADER --}}
                    <tr>
                        <td style="padding:20px 26px; border-top:5px solid {{ $accentColor }}; border-bottom:1px solid #e3e9f1;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="vertical-align:middle;">
                                        <img src="{{ $logoUrl }}" alt="PT Pro Energi" width="130" style="display:block; border:0;">
                                    </td>

                                    <td style="vertical-align:middle; text-align:right;">
                                        <div style="font-size:16px; font-weight:bold; color:#17365d;">
                                            {{ $title }}
                                        </div>

                                        <div style="margin-top:4px; font-size:12px; color:#1f4e78; font-weight:bold;">
                                            {{ $trip->trip_number ?? '-' }}
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- SALAM --}}
                    <tr>
                        <td style="padding:22px 26px 6px;">
                            <div style="font-size:14px;">
                                {{ __('mail.greeting') }} <strong>{{ $recipient->name ?? '-' }}</strong>,
                            </div>

                            <div style="margin-top:10px; font-size:13px; line-height:1.6;">
                                {{ $description }}
                            </div>
                        </td>
                    </tr>

                    {{-- PEMESANANNYA --}}
                    <tr>
                        <td style="padding:14px 26px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3e9f1; border-radius:4px; background:#f8fafc;">
                                <tr>
                                    <td style="padding:9px 12px; font-size:12px; color:#5b6b80; width:42%;">
                                        {{ __('mail.business_trip.arrangement.field_type') }}
                                    </td>
                                    <td style="padding:9px 12px; font-size:12px; font-weight:bold; color:{{ $accentColor }};">
                                        {{ $jenis }}

                                        @if ($dibatalkan)
                                            <span style="display:inline-block; margin-left:6px; padding:2px 9px; border-radius:10px; background:#b3261e; color:#ffffff; font-size:11px; font-weight:bold;">
                                                {{ __('mail.business_trip.arrangement.badge_cancelled') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>

                                @if (!empty($arrangement->vendor_name))
                                    <tr>
                                        <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                            {{ __('mail.business_trip.arrangement.field_vendor') }}
                                        </td>
                                        <td style="padding:0 12px 9px; font-size:12px; font-weight:bold;">
                                            {{ $arrangement->vendor_name }}
                                        </td>
                                    </tr>
                                @endif

                                @if (!empty($arrangement->reference_no))
                                    <tr>
                                        <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                            {{ __('mail.business_trip.arrangement.field_reference') }}
                                        </td>
                                        <td style="padding:0 12px 9px; font-size:12px; font-weight:bold;">
                                            {{ $arrangement->reference_no }}
                                        </td>
                                    </tr>
                                @endif

                                @if ($rentang)
                                    <tr>
                                        <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                            {{ __('mail.business_trip.arrangement.field_dates') }}
                                        </td>
                                        <td style="padding:0 12px 9px; font-size:12px; font-weight:bold;">
                                            {{ $rentang }}
                                        </td>
                                    </tr>
                                @endif

                                @if (!empty($arrangement->notes))
                                    <tr>
                                        <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                            {{ __('mail.business_trip.arrangement.field_notes') }}
                                        </td>
                                        <td style="padding:0 12px 9px; font-size:12px;">
                                            {{ $arrangement->notes }}
                                        </td>
                                    </tr>
                                @endif

                                @if ($dibatalkan && !empty($arrangement->cancellation_notes))
                                    <tr>
                                        <td style="padding:0 12px 11px; font-size:12px; color:#5b6b80;">
                                            {{ __('mail.business_trip.arrangement.field_cancel_reason') }}
                                        </td>
                                        <td style="padding:0 12px 11px; font-size:12px; font-weight:bold; color:#b3261e;">
                                            {{ $arrangement->cancellation_notes }}
                                        </td>
                                    </tr>
                                @endif

                                {{-- Berkasnya cukup disebut namanya; yang membuka tetap lewat aplikasi. --}}
                                @if ($arrangement->relationLoaded('files') && $arrangement->files->isNotEmpty())
                                    <tr>
                                        <td style="padding:0 12px 11px; font-size:12px; color:#5b6b80;">
                                            {{ __('mail.business_trip.arrangement.field_files') }}
                                        </td>
                                        <td style="padding:0 12px 11px; font-size:12px;">
                                            @foreach ($arrangement->files as $berkas)
                                                <div style="margin-bottom:2px;">{{ $berkas->original_filename }}</div>
                                            @endforeach
                                        </td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>

                    {{-- PENGGANTIAN --}}
                    @if ($replaces)
                        <tr>
                            <td style="padding:14px 26px 0;">
                                <div style="padding:10px 12px; border-left:3px solid #8a6d1f; background:#fdf8ec; font-size:12px; line-height:1.6;">
                                    {{ __('mail.business_trip.arrangement.replaces_notice', [
                                        'vendor' => $replaces->vendor_name
                                            ?: __('mail.business_trip.arrangement.type_' . strtolower($replaces->type)),
                                        'reason' => $replaces->cancellation_notes ?: '-',
                                    ]) }}
                                </div>
                            </td>
                        </tr>
                    @endif

                    {{-- PERJALANANNYA --}}
                    <tr>
                        <td style="padding:16px 26px 0;">
                            <div style="font-size:12px; font-weight:bold; color:#17365d; margin-bottom:8px;">
                                {{ __('mail.business_trip.arrangement.trip_title') }}
                            </div>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3e9f1; border-radius:4px;">
                                <tr>
                                    <td style="padding:9px 12px; font-size:12px; color:#5b6b80; width:42%;">
                                        {{ __('mail.business_trip.field_destination') }}
                                    </td>
                                    <td style="padding:9px 12px; font-size:12px; font-weight:bold;">
                                        {{ $trip->destination ?? '-' }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding:0 12px 11px; font-size:12px; color:#5b6b80;">
                                        {{ __('mail.business_trip.field_period') }}
                                    </td>
                                    <td style="padding:0 12px 11px; font-size:12px; font-weight:bold;">
                                        {{ $tanggal($trip->depart_date) }} {{ $jam($trip->depart_time) }}
                                        &rarr;
                                        {{ $tanggal($trip->return_date) }} {{ $jam($trip->return_time) }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- TOMBOL --}}
                    <tr>
                        <td style="padding:18px 26px 4px; font-size:12px; color:#5b6b80;">
                            {{ __('mail.business_trip.arrangement.instruction') }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:12px 26px 24px;">
                            <a
                                href="{{ $tripUrl }}"
                                style="display:inline-block; padding:11px 22px; border-radius:4px; background:{{ $accentColor }}; color:#ffffff; font-size:13px; font-weight:bold; text-decoration:none;"
                            >
                                {{ __('mail.business_trip.arrangement.button') }}
                            </a>
                        </td>
                    </tr>

                    {{-- FOOTER --}}
                    <tr>
                        <td style="padding:14px 26px; border-top:1px solid #e3e9f1; background:#f8fafc; font-size:11px; color:#7d8999;">
                            {{ __('mail.footer_notice') }}
                        </td>
                    </tr>
                </table>

                <div style="margin-top:12px; font-size:11px; color:#8d99a8;">
                    &copy; {{ now()->year }} PT Pro Energi. {{ __('mail.footer_rights') }}
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
