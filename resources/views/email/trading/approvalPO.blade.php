<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $title ?? 'Purchase Order' }}</title>
</head>

<body style="font-family: Arial, sans-serif; background:#f4f6f9; padding:20px;">

    <div style="max-width:700px;margin:auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #eee;">

        <!-- HEADER -->
       <div style="background:#1976d2;color:#fff;padding:20px;">

            <table role="presentation" style="width:100%;">
                <tr>

                    <!-- LOGO -->
                    <td style="width:60px;vertical-align:middle;">
                        <img src="https://syop.proenergi.com/proEnergi/libraries/themes/images/logo-proenergi.png"
                            alt="Logo"
                            style="width:50px;height:50px;background:#fff;padding:5px;border-radius:8px;">
                    </td>

                    <!-- TEXT -->
                    <td style="vertical-align:middle;padding-left:10px;">
                        <h2 style="margin:0;color:#fff;">Purchase Order</h2>
                        <p style="margin:3px 0 0;color:#fff;">PT Pro Energi</p>
                    </td>

                </tr>
            </table>

        </div>

        <!-- CONTENT -->
        <div style="padding:20px;">

            <h3 style="margin-top:0;">
                {{ $title }}
            </h3>

            <p style="color:#555;">
                {{ $messageBody }}
            </p>

            <!-- PO INFO BOX -->
            <div style="border:1px solid #eee;border-radius:8px;padding:15px;margin-top:15px;background:#fafafa;">

                <table style="width:100%;font-size:14px;">
                    <tr>
                        <td><b>Nomor PO</b></td>
                        <td>: {{ $po['nomor_po'] }}</td>
                    </tr>
                    <tr>
                        <td><b>Volume</b></td>
                        <td>: {{ number_format($po['volume_po']) }} L</td>
                    </tr>
                    <tr>
                        <td><b>Dibuat Oleh</b></td>
                        <td>: {{ $po['created_by'] }}</td>
                    </tr>
                </table>

            </div>

            <!-- BUTTON -->
            <div style="margin-top:20px;text-align:center;">

                <a href="{{ $actionUrl }}"
                   style="background:#1976d2;color:#fff;padding:12px 20px;
                          text-decoration:none;border-radius:6px;display:inline-block;">
                    View PO
                </a>

            </div>

        </div>

        <!-- FOOTER -->
        <div style="padding:15px;text-align:center;font-size:12px;color:#999;">
            Email ini dikirim otomatis oleh sistem SYOP.
        </div>

    </div>

</body>
</html>