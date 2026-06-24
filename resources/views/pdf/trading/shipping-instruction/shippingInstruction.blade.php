@php

    $loadingDate = '-';

    if (
        !empty($res->etl_date_first) &&
        !empty($res->etl_date_last)
    ) {
        $firstMonth = date('m', strtotime($res->etl_date_first));
        $lastMonth = date('m', strtotime($res->etl_date_last));

        $firstDay = date('d', strtotime($res->etl_date_first));
        $lastDay = date('d', strtotime($res->etl_date_last));

        if ($firstMonth === $lastMonth) {
            if ($firstDay === $lastDay) {
                $loadingDate = ($res->etl_date_first);
            } else {
                $loadingDate =
                    $firstDay . ' - ' . ($res->etl_date_last);
            }
        } else {
            $loadingDate =
                ($res->etl_date_first)
                . ' - '
                . ($res->etl_date_last);
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
        font-size: 9.5pt;
        margin-left: 5px;
        border-collapse: separate;
        border-spacing: 0 5px;
    }

    .container {
        font-size: 9.5pt;
        padding-left: 15px;
        padding-right: 15px;
    }

    p {
        margin: 3px 0 8px 5px;
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

    tr.jarak-atas td {
        padding-top: 25px;
        padding-bottom: 10px;
    }

    tr.jarak-bawah td {
        padding-bottom: 20px;
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
        <barcode
            code="{{ $barcode ?? '' }}"
            type="QR"
            size="1"
        />
    </div>

    <br>

    <p style="margin: 0; text-align: right; font-size: 7pt;">
        <i>
            (This form is valid with sign by computerized system)
        </i>
    </p>

    <p style="margin: 0; text-align: right; font-size: 6pt;">
        Printed by {{ $printer ?? '-' }}
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
    <img
       src="{{ public_path('images/logo-kiri-penawaran.png') }}"
        width="15%"
        style="float: right;"
        alt="Logo PT Pro Energi"
    >
</div>

<div class="container">
    <table border="0" width="100%">
        <tr>
            <td width="30%"></td>

            <td width="40%" align="center">
                <h2>
                    Shipping Instruction
                </h2>
            </td>

            <td width="30%"></td>
        </tr>
    </table>
</div>

<br>

<div class="container">
    <table
        border="0"
        width="100%"
        style="margin-top: 30px; margin-bottom: 20px;"
    >
        <tr>
            <td width="18%">Number</td>
            <td width="2%">:</td>
            <td>
                {{ $res->nomor_req ?? '-' }}
            </td>
        </tr>

        <tr>
            <td width="18%">Date</td>
            <td width="2%">:</td>
            <td>
                @if (!empty($res->created_at))
                    {{ ($res->created_at) }}
                @else
                    -
                @endif
            </td>
        </tr>
    </table>

    <div
        style="
            line-height: 1;
            margin-bottom: 10px;
            max-width: 50%;
        "
    >
        <p>To:</p>

        <p>
            <b>{{ $res->transportir->nama_suplier ?? '-' }}</b>
        </p>

        <p>
            {!! nl2br(e($res->transportir->alamat_suplier ?? '-')) !!}
        </p>
    </div>

    <div style="line-height: 1.4; margin-top: 10px;">
        <p>Dear Sir,</p>

        <p>
            We hereby request you to arrange shipment with detail
            as mentioned below:
        </p>
    </div>

    <table border="0" width="100%">
        <tr>
            <td width="23%">
                Product Description
            </td>

            <td width="2%">:</td>

            <td>
                {{ $res->cargo_name ?? '-' }}
            </td>
        </tr>

        <tr>
            <td width="23%">
                Vessel/Ship
            </td>

            <td width="2%">:</td>

            <td>
                {{ $vesselName !== '' ? $vesselName : '-' }}
            </td>
        </tr>

        <tr>
            <td width="23%">
                Laycan
            </td>

            <td width="2%">:</td>

            <td>
                {{ $loadingDate }}
            </td>
        </tr>

        <tr>
            <td width="23%">
                Quantity
            </td>

            <td width="2%">:</td>

            <td>
                {{ number_format((float) ($res->quantity ?? 0)) }}
                ({{ $res->satuan ?? '-' }})
            </td>
        </tr>

        <tr class="jarak-atas">
            <td
                width="23%"
                style="vertical-align: top;"
            >
                <i>Shipper</i>
            </td>

            <td
                width="2%"
                style="vertical-align: top;"
            >
                :
            </td>

            <td>
                <div>
                    <p style="padding: 2px 0;">
                        {{ $res->shipper ?? '-' }}
                    </p>

                    <p style="padding: 2px 0;">
                        Graha Irama Building Lt. 6G
                    </p>

                    <p style="padding: 2px 0;">
                        Jl. HR. Rasuna Said Blok X1, Kav 1-2,
                        Kuningan, Jakarta, 12950
                    </p>
                </div>
            </td>
        </tr>

        <tr class="jarak-atas">
            <td
                width="23%"
                style="vertical-align: top;"
            >
                <i>Consignee</i>
            </td>

            <td
                width="2%"
                style="vertical-align: top;"
            >
                :
            </td>

            <td>
                <div style="line-height: 1.4;">
                    <p>
                        {{ $res->consignee ?? '-' }}
                    </p>

                    <p>
                        Graha Irama Building Lt. 6 Unit F
                    </p>

                    <p>
                        Jl. HR. Rasuna Said Blok X1, Kav 1-2,
                        Kuningan, Jakarta, 12950
                    </p>
                </div>
            </td>
        </tr>

        <tr>
            <td width="23%">
                Port of Loading
            </td>

            <td width="2%">:</td>

            <td>
                {{ $res->vessel->asal_angkut ?? '-' }}
            </td>
        </tr>

        <tr class="jarak-bawah">
            <td width="23%">
                Port of Discharge
            </td>

            <td width="2%">:</td>

            <td>
                {{ $res->vessel->tujuan_angkut ?? '-' }}
            </td>
        </tr>
    </table>

    <p style="font-size: 12px; margin-bottom: 30px;">
        Regards,
    </p>

    <div style="margin-bottom: 60px;"></div>

    <p>
        Bilal Gustifar
    </p>

    <p
        style="
            width: 150px;
            border-top: 1px solid black;
            margin-bottom: 5px;
        "
    ></p>

    <p>
        Management Procurement
    </p>
</div>
