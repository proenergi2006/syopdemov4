@php
    /*
    |--------------------------------------------------------------------------
    | Email approval Perdin
    |--------------------------------------------------------------------------
    | Tabel bersarang dan gaya inline dipakai karena banyak klien email
    | (Outlook terutama) mengabaikan CSS eksternal dan flexbox.
    |
    | Rundown perjalanannya ikut disertakan. Approver perdin menilai rencana
    | perjalanannya, bukan angkanya -- tanpa rundown, email ini menyuruh orang
    | membuka aplikasi hanya untuk tahu apa yang sedang ia setujui.
    |--------------------------------------------------------------------------
    */

    $currentMode = $mode ?? 'approval_request';

    $translationMode = in_array($currentMode, ['final_approved', 'rejected'], true)
        ? $currentMode
        : 'default';

    $title = __('mail.business_trip.title.' . $translationMode);

    $actorName = optional($actor)->name ?? '-';

    $description = __('mail.business_trip.description.' . $translationMode, [
        'actor_name' => $actorName,
    ]);

    $displayStatus = match ($currentMode) {
        'final_approved' => 'APPROVED',
        'rejected' => 'REJECTED',
        default => 'IN PROGRESS',
    };

    $statusColor = match ($currentMode) {
        'final_approved' => '#1b7f4f',
        'rejected' => '#b3261e',
        default => '#8a6d1f',
    };

    $accentColor = $currentMode === 'rejected' ? '#b3261e' : '#1f4e78';

    $tanggal = static fn ($nilai): string =>
        $nilai ? \Carbon\Carbon::parse($nilai)->format('d/m/Y') : '-';

    $jam = static fn ($nilai): string => $nilai ? substr((string) $nilai, 0, 5) : '';

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

                    {{-- ISI --}}
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

                    {{-- RINGKASAN --}}
                    <tr>
                        <td style="padding:14px 26px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3e9f1; border-radius:4px; background:#f8fafc;">
                                <tr>
                                    <td style="padding:9px 12px; font-size:12px; color:#5b6b80; width:42%;">
                                        {{ __('mail.business_trip.field_no') }}
                                    </td>
                                    <td style="padding:9px 12px; font-size:12px; font-weight:bold;">
                                        {{ $trip->trip_number ?? '-' }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                        {{ __('mail.business_trip.field_employee') }}
                                    </td>
                                    <td style="padding:0 12px 9px; font-size:12px; font-weight:bold;">
                                        {{ $trip->employee_name ?? '-' }}
                                        @if (!empty($trip->position_name))
                                            <span style="font-weight:normal; color:#5b6b80;">
                                                &mdash; {{ $trip->position_name }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                        {{ __('mail.business_trip.field_destination') }}
                                    </td>
                                    <td style="padding:0 12px 9px; font-size:12px; font-weight:bold; color:{{ $accentColor }};">
                                        {{ $trip->destination ?? '-' }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                        {{ __('mail.business_trip.field_period') }}
                                    </td>
                                    <td style="padding:0 12px 9px; font-size:12px; font-weight:bold;">
                                        {{ $tanggal($trip->depart_date) }} {{ $jam($trip->depart_time) }}
                                        &rarr;
                                        {{ $tanggal($trip->return_date) }} {{ $jam($trip->return_time) }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                        {{ __('mail.business_trip.field_purpose') }}
                                    </td>
                                    <td style="padding:0 12px 9px; font-size:12px;">
                                        {{ $trip->purpose ?? '-' }}
                                    </td>
                                </tr>

                                @if (!empty($stepOrder))
                                    <tr>
                                        <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                            {{ __('mail.business_trip.field_step') }}
                                        </td>
                                        <td style="padding:0 12px 9px; font-size:12px; font-weight:bold;">
                                            {{ __('mail.business_trip.field_step_value', ['step_order' => $stepOrder]) }}
                                            @if (!empty($stepLabel))
                                                &mdash; {{ $stepLabel }}
                                            @endif
                                        </td>
                                    </tr>
                                @endif

                                <tr>
                                    <td style="padding:0 12px 11px; font-size:12px; color:#5b6b80;">
                                        {{ __('mail.business_trip.field_status') }}
                                    </td>
                                    <td style="padding:0 12px 11px;">
                                        <span style="display:inline-block; padding:2px 9px; border-radius:10px; background:{{ $statusColor }}; color:#ffffff; font-size:11px; font-weight:bold;">
                                            {{ $displayStatus }}
                                        </span>
                                    </td>
                                </tr>

                                @if (!empty($notes))
                                    <tr>
                                        <td style="padding:0 12px 11px; font-size:12px; color:#5b6b80;">
                                            {{ __('mail.business_trip.field_notes') }}
                                        </td>
                                        <td style="padding:0 12px 11px; font-size:12px;">
                                            {{ $notes }}
                                        </td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>

                    {{-- RUNDOWN --}}
                    @if ($trip->relationLoaded('itineraries') && $trip->itineraries->isNotEmpty())
                        <tr>
                            <td style="padding:16px 26px 0;">
                                <div style="font-size:12px; font-weight:bold; color:#17365d; margin-bottom:8px;">
                                    {{ __('mail.business_trip.itinerary_title') }}
                                </div>

                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e3e9f1; border-radius:4px; border-collapse:separate;">
                                    <tr style="background:#f1f4f8;">
                                        <th align="left" style="padding:7px 10px; font-size:11px; color:#5b6b80;">
                                            {{ __('mail.business_trip.itinerary_date') }}
                                        </th>
                                        <th align="left" style="padding:7px 10px; font-size:11px; color:#5b6b80;">
                                            {{ __('mail.business_trip.itinerary_time') }}
                                        </th>
                                        <th align="left" style="padding:7px 10px; font-size:11px; color:#5b6b80;">
                                            {{ __('mail.business_trip.itinerary_description') }}
                                        </th>
                                    </tr>

                                    @foreach ($trip->itineraries as $baris)
                                        <tr>
                                            <td style="padding:7px 10px; font-size:11px; border-top:1px solid #eef2f7; white-space:nowrap;">
                                                {{ $tanggal($baris->date) }}
                                            </td>
                                            <td style="padding:7px 10px; font-size:11px; border-top:1px solid #eef2f7; white-space:nowrap;">
                                                {{ $baris->time_text }}
                                            </td>
                                            <td style="padding:7px 10px; font-size:11px; border-top:1px solid #eef2f7;">
                                                {{ $baris->description }}
                                                @if (!empty($baris->pic))
                                                    <span style="color:#5b6b80;">({{ $baris->pic }})</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- TOMBOL --}}
                    <tr>
                        <td style="padding:18px 26px 4px; font-size:12px; color:#5b6b80;">
                            {{ __('mail.business_trip.instruction') }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:12px 26px 24px;">
                            <a
                                href="{{ $approvalUrl }}"
                                style="display:inline-block; padding:11px 22px; border-radius:4px; background:{{ $accentColor }}; color:#ffffff; font-size:13px; font-weight:bold; text-decoration:none;"
                            >
                                {{ __('mail.business_trip.button') }}
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
