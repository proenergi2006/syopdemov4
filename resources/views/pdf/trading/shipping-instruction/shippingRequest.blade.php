@php
    $data = $res;

    $ptname = ucwords(strtolower($data['transportir']['nama_suplier'] ?? '-'));
    $ptname = preg_replace('/^Pt\.?\s/i', 'PT. ', $ptname);

    $lossType = $data['losstype'] ?? null;

    $info = match ($lossType) {
        'R2' => 'SFAL VS SFBD',
        'R4' => 'L15 Based on Tanki Darat',
        default => 'Observed Value vs Corrected Value',
    };

    $createdAt = $data['created_at'] ?? now();

     $etlDateFirst = $data['etl_date_first'] ?? null;
    $etlDateLast = $data['etl_date_last'] ?? null;

    $loading_date = '-';

    if ($etlDateFirst && $etlDateLast) {
        $firstMonth = date('m', strtotime($etlDateFirst));
        $lastMonth = date('m', strtotime($etlDateLast));

        $firstDay = date('d', strtotime($etlDateFirst));
        $lastDay = date('d', strtotime($etlDateLast));

        if ($firstMonth === $lastMonth) {
            if ($firstDay === $lastDay) {
                $loading_date = ($etlDateFirst);
            } else {
                $loading_date = $firstDay . ' - ' . ($etlDateLast);
            }
        } else {
            $loading_date = ($etlDateFirst)
                . ' - '
                . ($etlDateLast);
        }
    }

    $vesselName = trim(
        ($res->vessel->tipe_kapal ?? '')
        . ' '
        . ($res->vessel->nama_kapal ?? '')
    );

    if (($res->tipe_si ?? null) == 1) {
        $tugboatName = trim(
            ($res->vessel_tb->tipe_kapan ?? '')
            . ' '
            . ($res->vessel_tb->nama_kapal ?? '')
        );

        if ($tugboatName !== '') {
            $vesselName .= ' & ' . $tugboatName;
        }
    }

@endphp

<style>
    table {
        font-size: 8.5pt;
    }

    .tabel_header td {
        padding: 1px 3px;
        font-size: 9pt;
        height: 18px;
    }

    .tabel_rincian th {
        padding: 5px 3px;
        background-color: #ffcc99;
    }

    .tabel_rincian td {
        padding: 3px 2px;
    }

    .td-ket,
    .td-subisi {
        padding: 1px 0 2px;
        vertical-align: top;
    }

    .td-subisi {
        font-size: 5pt;
    }

    .td-ket {
        padding: 1px 0;
        font-size: 8pt;
    }

    p {
        margin: 0 0 10px;
        text-align: justify;
    }

    .b1,
    .b1d {
        border-top: 0.5px solid #000;
    }

    .b2,
    .b2d {
        border-right: 0.5px solid #000;
    }

    .b3,
    .b3d {
        border-bottom: 0.5px solid #000;
    }

    .b4,
    .b4d {
        border-left: 0.5px solid #000;
    }

    tr td {
        padding-bottom: 2px;
    }

    .div-table {
        padding: 0;
        margin: 0;
        display: table;
        width: 100%;
        border: none;
    }

    .div-table-row {
        padding: 0;
        margin: 0;
        display: table-row;
        width: 100%;
        clear: both;
    }

    .div-table-cell {
        padding: 0;
        margin: 0;
        display: table-cell;
        float: right;
        font-size: 12px;
    }
</style>

{{--
<htmlpagefooter name="myHTMLFooter1">
    <div style="margin: 0; text-align: right;">
        <barcode code="{{ $barcode }}" type="QR" size="1"/>
    </div>

    <br>

    <p style="margin: 0; text-align: right; font-size: 7pt;">
        <i>(This form is valid with sign by computerized system)</i>
    </p>

    <p style="margin: 0; text-align: right; font-size: 6pt;">
        Printed by {{ $printer }}
    </p>
</htmlpagefooter>

<sethtmlpagefooter
    name="myHTMLFooter1"
    page="ALL"
    value="on"
    show-this-page="1"
/>
--}}

<div class="container">
    <table border="0" width="100%">
        <tr>
            <td width="30%"></td>

            <td></td>

            <td width="30%" align="right">
                <div>
                    <img
                        src="{{ public_path('images/logo-kiri-penawaran.png') }}"
                        width="15%"
                        alt="Logo PT Pro Energi"
                    >
                </div>

                <br>

                <p><b>PT PRO ENERGI</b></p>
                <p>Gedung Graha Irama 6 G</p>
                <p>Jl. HR. Rasuna Said Blok X1, Kav 1-2</p>
                <p>Jakarta 12950 DKI Jakarta - Indonesia</p>
                <p><b>Telp</b>: (021) 5289 2321</p>
                <p><b>Fax</b>: (021) 5289 2310</p>
            </td>
        </tr>
    </table>
</div>

<br>

<div class="container">
    <table border="0" width="100%">
        <tr>
            <td width="30%"></td>

            <td width="40%" align="center">
                <h2 style="margin-bottom: 3px;">
                    SHIPPING REQUEST
                </h2>

                <hr
                    style="
                        height: 1px;
                        border: 1px solid black;
                        width: 75%;
                        margin: 3px auto;
                    "
                >

                <p style="text-align: center;">
                    <b>{{ $data['nomor_req'] ?? '-' }}</b>
                </p>
            </td>

            <td width="30%"></td>
        </tr>
    </table>
</div>

<br>
<br>

<div class="container">
    <table border="0" width="100%">
        <tr>
            <td width="24%">To</td>
            <td width="5%">:</td>
            <td>
                <b>{{ $ptname }}</b>
            </td>
        </tr>

        <tr>
            <td width="24%">Attn</td>
            <td width="5%">:</td>
            <td>-</td>
        </tr>

        <tr>
            <td width="24%">Subject</td>
            <td width="5%">:</td>
            <td>
                Shipping request for PT. Pro Energi
                {{ $data['load_nama'] ?? '-' }}
             {{ \Carbon\Carbon::parse($createdAt)->translatedFormat('F') }}
                {{ date('Y', strtotime($createdAt)) }}
            </td>
        </tr>

        <tr>
            <td width="24%">Our ref</td>
            <td width="5%">:</td>
            <td>-</td>
        </tr>

        <tr>
            <td width="24%">Date</td>
            <td width="5%">:</td>
            <td>{{ ($createdAt) }}</td>
        </tr>
    </table>

    <hr
        style="
            height: 1.5px;
            border: 1px solid black;
            margin: 3px auto;
        "
    >

    <p style="font-size: 12px; margin-top: 20px; margin-bottom: 20px;">
        We appoint {{ $ptname }} to arrange our shipment as below mentioned:
    </p>

    <table border="0" width="100%">
        <tr>
            <td width="24%" style="vertical-align: top;">
                Vessel Name
            </td>

            <td width="5%" style="vertical-align: top;">
                :
            </td>

            <td>
               {{$vesselName}}
            </td>
        </tr>

        <tr>
            <td width="24%">Flag</td>
            <td width="5%">:</td>
            <td>{{ $data['flag'] ?? '-' }}</td>
        </tr>

        <tr>
            <td width="24%">Loading Port</td>
            <td width="5%">:</td>
            <td>{{ $data['load_port']['nama_terminal'] ?? '-' }}</td>
        </tr>

        <tr>
            <td width="24%">Discharging Port</td>
            <td width="5%">:</td>
            <td>{{ $data['discharge_port']['nama_terminal'] ?? '-' }}</td>
        </tr>

        <tr>
            <td width="24%">Estimated Loading Date</td>
            <td width="5%">:</td>
            <td>{{ $loading_date ?? '-' }}</td>
        </tr>

        <tr>
            <td width="24%">Cargo Name</td>
            <td width="5%">:</td>
            <td>{{ $data['cargo_name'] ?? '-' }}</td>
        </tr>

        <tr>
            <td width="24%">Quantity</td>
            <td width="5%">:</td>
            <td>
                &plusmn;
                {{ number_format((float) ($data['quantity'] ?? 0)) }}
                ({{ $data['satuan'] ?? '-' }})
            </td>
        </tr>

        {{--
        <tr>
            <td width="24%">Bill of Lading</td>
            <td width="5%">:</td>
            <td>
                {{ $data['bill_lading'] ?? '-' }} (GSV @15C)
            </td>
        </tr>
        --}}

        <tr>
            <td width="24%">Loss Tolerance</td>
            <td width="5%">:</td>
            <td>
                {{ $data['losstype'] ?? '-' }}
                =
                {{ $data['loss_tolerance'] ?? 0 }}%
                ({{ $info }})
            </td>
        </tr>

        <tr>
            <td width="24%">Freight</td>
            <td width="5%">:</td>
            <td>
                IDR
                {{ number_format((float) ($data['freight'] ?? 0)) }}
                /L (Exclude VAT, include withholding tax)
            </td>
        </tr>

        <tr>
            <td width="24%">Country of Origin</td>
            <td width="5%">:</td>
            <td>{{ $data['country_origin'] ?? '-' }}</td>
        </tr>

        <tr>
            <td width="24%">Shipper</td>
            <td width="5%">:</td>
            <td>{{ $data['shipper'] ?? '-' }}</td>
        </tr>

        <tr>
            <td width="24%">Consignee / Notify Address</td>
            <td width="5%">:</td>
            <td>{{ $data['consignee'] ?? '-' }}</td>
        </tr>

        <tr>
            <td width="24%">BL Ship on Board</td>
            <td width="5%">:</td>
            <td>{{ $data['bl_ship'] ?? '-' }}</td>
        </tr>
    </table>
</div>

<p style="font-size: 12px; margin-top: 30px;">
    Thank you for your kind attention and cooperation.
</p>

<p style="font-size: 12px; margin-top: 20px;">
    <b>PT. PRO ENERGI</b>
</p>

<div style="margin-bottom: 90px;"></div>

<p style="font-size: 12px; margin: 0;">
    <b>
        <u>Bilal Gustifar</u>
    </b>
</p>

<p style="font-size: 12px;">
    <i>Supervisor Procurement</i>
</p>