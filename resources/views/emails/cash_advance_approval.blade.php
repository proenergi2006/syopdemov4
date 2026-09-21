@php
    /*
    |--------------------------------------------------------------------------
    | Email approval FPU
    |--------------------------------------------------------------------------
    | Tabel bersarang dan gaya inline dipakai karena banyak klien email
    | (Outlook terutama) mengabaikan CSS eksternal dan flexbox.
    |--------------------------------------------------------------------------
    */

    $currentMode = $mode ?? 'approval_request';

    $translationMode = in_array(
        $currentMode,
        ['final_approved', 'rejected', 'disbursed', 'disbursement_request', 'received', 'receipt_request'],
        true,
    ) ? $currentMode : 'default';

    $title = __('mail.fpu.title.' . $translationMode);

    $actorName = optional($actor)->name ?? '-';

    $description = __('mail.fpu.description.' . $translationMode, [
        'actor_name' => $actorName,
    ]);

    /*
    | disbursement_request dikirim tepat setelah approval tuntas, jadi status
    | dokumennya memang APPROVED -- yang ditunggu tinggal pencairannya.
    */
    $displayStatus = match ($currentMode) {
        'final_approved', 'receipt_request' => 'APPROVED',
        'received', 'disbursement_request' => 'RECEIVED',
        'rejected' => 'REJECTED',
        'disbursed' => 'DISBURSED',
        default => 'IN PROGRESS',
    };

    $statusColor = match ($currentMode) {
        'final_approved', 'receipt_request' => '#1b7f4f',
        'received', 'disbursement_request' => '#0d6b7d',
        'rejected' => '#b3261e',
        'disbursed' => '#1f4e78',
        default => '#8a6d1f',
    };

    $accentColor = $currentMode === 'rejected' ? '#b3261e' : '#1f4e78';

    $rupiah = static fn ($value): string =>
        'Rp ' . number_format((float) $value, 0, ',', '.');

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
                                            {{ $fpu->advance_number ?? '-' }}
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
                                        {{ __('mail.fpu.field_no') }}
                                    </td>
                                    <td style="padding:9px 12px; font-size:12px; font-weight:bold;">
                                        {{ $fpu->advance_number ?? '-' }}
                                    </td>
                                </tr>

                                {{-- Jadwal pembayaran: yang paling dicari pemohon --}}
                                @if (!empty($fpu->scheduled_payment_date))
                                    <tr>
                                        <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                            {{ __('mail.fpu.field_payment_date') }}
                                        </td>
                                        <td style="padding:0 12px 9px; font-size:12px; font-weight:bold; color:#1b7f4f;">
                                            {{ \App\Services\FundRequest\PaymentScheduleService::formatTanggal($fpu->scheduled_payment_date) }}
                                        </td>
                                    </tr>
                                @endif

                                <tr>
                                    <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                        {{ __('mail.fpu.field_date') }}
                                    </td>
                                    <td style="padding:0 12px 9px; font-size:12px; font-weight:bold;">
                                        {{ $fpu->date ? \Carbon\Carbon::parse($fpu->date)->format('d/m/Y') : '-' }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                        {{ __('mail.fpu.field_category') }}
                                    </td>
                                    <td style="padding:0 12px 9px; font-size:12px; font-weight:bold;">
                                        {{ $fpu->transactionCategory->name ?? '-' }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                        {{ __('mail.fpu.field_subject') }}
                                    </td>
                                    <td style="padding:0 12px 9px; font-size:12px;">
                                        {{ $fpu->subject ?? '-' }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                        {{ __('mail.fpu.field_total') }}
                                    </td>
                                    <td style="padding:0 12px 9px; font-size:13px; font-weight:bold; color:{{ $accentColor }};">
                                        {{ $rupiah($fpu->total_amount) }}
                                    </td>
                                </tr>

                                @if (!empty($stepOrder))
                                    <tr>
                                        <td style="padding:0 12px 9px; font-size:12px; color:#5b6b80;">
                                            {{ __('mail.fpu.field_step') }}
                                        </td>
                                        <td style="padding:0 12px 9px; font-size:12px; font-weight:bold;">
                                            {{ __('mail.fpu.field_step_value', ['step_order' => $stepOrder]) }}
                                            @if (!empty($stepLabel))
                                                &mdash; {{ $stepLabel }}
                                            @endif
                                        </td>
                                    </tr>
                                @endif

                                <tr>
                                    <td style="padding:0 12px 11px; font-size:12px; color:#5b6b80;">
                                        {{ __('mail.fpu.field_status') }}
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
                                            {{ __('mail.fpu.field_notes') }}
                                        </td>
                                        <td style="padding:0 12px 11px; font-size:12px;">
                                            {{ $notes }}
                                        </td>
                                    </tr>
                                @endif
                            </table>
                        </td>
                    </tr>

                    {{-- TOMBOL --}}
                    <tr>
                        <td style="padding:18px 26px 4px; font-size:12px; color:#5b6b80;">
                            {{ __('mail.fpu.instruction') }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:12px 26px 24px;">
                            <a
                                href="{{ $approvalUrl }}"
                                style="display:inline-block; padding:11px 22px; border-radius:4px; background:{{ $accentColor }}; color:#ffffff; font-size:13px; font-weight:bold; text-decoration:none;"
                            >
                                {{ __('mail.fpu.button') }}
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
