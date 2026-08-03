@php
    $logoUrl = 'https://syop.proenergi.com/proEnergi/libraries/themes/images/logo-proenergi.png';
@endphp

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#1f2937;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 0;">
        <tr>
            <td align="center">
                <table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb;">
                    <tr>
                        <td style="padding:24px 28px;background:#a5abb9;">
                            <img src="{{ $logoUrl }}" alt="Pro Energi" style="height:42px;display:block;">
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px;">
                            <h2 style="margin:0 0 10px;font-size:22px;color:#111827;">
                                Reset Password
                            </h2>

                            <p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#4b5563;">
                                Halo{{ $userName ? ', ' . $userName : '' }},<br>
                                Kami menerima permintaan untuk mereset password akun SYOP Anda.
                            </p>

                            <p style="margin:24px 0;">
                                <a href="{{ $resetUrl }}"
                                   style="display:inline-block;padding:12px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;font-size:14px;">
                                    Reset Password
                                </a>
                            </p>

                            <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#6b7280;">
                                Link ini akan kedaluwarsa dalam {{ $expireMinutes }} menit.
                            </p>

                            <p style="margin:0 0 18px;font-size:13px;line-height:1.6;color:#6b7280;">
                                Jika Anda tidak meminta reset password, abaikan email ini.
                            </p>

                            <p style="margin:18px 0 0;font-size:12px;line-height:1.6;color:#9ca3af;word-break:break-all;">
                                Jika tombol di atas tidak berfungsi, klik pada tautan atau salin dan tempel tautan berikut ke browser Anda:<br>
                                <a href="{{ $resetUrl }}" style="color:#2563eb;">{{ $resetUrl }}</a>
                            </p>

                            <p style="margin:20px 0 0;font-size:13px;color:#6b7280;">
                                Email ini dikirim otomatis oleh sistem SYOP v4. Mohon tidak membalas email ini.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;text-align:center;">
                            Copyright &copy; 2026 <a href="https://proenergi.com/en">Proenergi.com</a> All Right Reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
