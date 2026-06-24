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

@endphp
<style>
    .tabel_header td {
        padding: 1px 3px;
        font-size: 9pt;
        height: 35px;
    }

    .tabel_rincian td {
        height: 35px;
        padding: 3px;
        font-size: 9pt;
    }

    p {
        margin: 0 0 10px;
        text-align: justify;
    }

    .b1 {
        border-top: 1px solid #000;
    }

    .b2 {
        border-right: 1px solid #000;
    }

    .b3 {
        border-bottom: 1px solid #000;
    }

    .b4 {
        border-left: 1px solid #000;
    }

    .b1d {
        border-top: 2px solid #000;
    }

    .b2d {
        border-right: 2px solid #000;
    }

    .b3d {
        border-bottom: 2px solid #000;
    }

    .b4d {
        border-left: 2px solid #000;
    }

    .coret {
        text-decoration: line-through;
    }

    .td-header,
    .td-isi {
        font-size: 5pt;
        padding: 2px;
    }

    .th-isi {
        font-size: 5pt;
        padding: 1px;
        background-color: #b8cce4;
    }

    .td-isi {
        text-align: center;
        font-weight: bold;
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

    .isi-spj {
        padding: 1px 0 2px;
        vertical-align: top;
        font-size: 10pt;
        font-family: tahoma;
    }

    .isi-spj2 {
        padding: 1px;
        vertical-align: top;
        font-size: 9pt;
        font-family: tahoma;
    }
</style>
{{-- 
<htmlpagefooter name="myHTMLFooter1">
    <p style="font-size: 6pt; text-align: right;">
        Printed by {{ $printe ?? '-' }}
    </p>
</htmlpagefooter>

<sethtmlpagefooter
    name="myHTMLFooter1"
    page="ALL"
    value="on"
    show-this-page="1"
/> --}}

        @php
            $barcode = ($data->kode_barcode ?? '')
                . '07'
                . str_pad((string) ($data->id_dsk ?? ''), 6, '0', STR_PAD_LEFT);

            $note = !empty($data->keterangan)
                ? str_replace('<br />', PHP_EOL, $data->keterangan)
                : null;

            $tank = json_decode($data->tank_seal ?? '[]', true) ?: [];
            $manifold = json_decode($data->manifold_seal ?? '[]', true) ?: [];
            $pump = json_decode($data->pump_seal ?? '[]', true) ?: [];
            $other = json_decode($data->other_seal ?? '[]', true) ?: [];

            $sealInitial = $data->inisial_segel ?? '';

            $formatSealRange = function (
                $total,
                $start,
                $end,
                string $initial
            ): string {
                $total = (int) ($total ?? 0);

                $start = !empty($start)
                    ? str_pad((string) $start, 4, '0', STR_PAD_LEFT)
                    : '';

                $end = !empty($end)
                    ? str_pad((string) $end, 4, '0', STR_PAD_LEFT)
                    : '';

                if ($total === 1 && $start !== '') {
                    return $initial . '-' . $start;
                }

                if ($total === 2 && $start !== '' && $end !== '') {
                    return $initial . '-' . $start
                        . ' &amp; '
                        . $initial . '-' . $end;
                }

                if ($total > 2 && $start !== '' && $end !== '') {
                    return $initial . '-' . $start
                        . ' s/d '
                        . $initial . '-' . $end;
                }

                return '';
            };

            $manifoldLeft = $formatSealRange(
                $manifold['jumlah_kiri'] ?? 0,
                $manifold['mani_kiri_awal'] ?? null,
                $manifold['mani_kiri_akhir'] ?? null,
                $sealInitial
            );

            $manifoldRight = $formatSealRange(
                $manifold['jumlah_kanan'] ?? 0,
                $manifold['mani_kanan_awal'] ?? null,
                $manifold['mani_kanan_akhir'] ?? null,
                $sealInitial
            );

            $pumpLeft = $formatSealRange(
                $pump['jumlah_kiri'] ?? 0,
                $pump['pump_kiri_awal'] ?? null,
                $pump['pump_kiri_akhir'] ?? null,
                $sealInitial
            );

            $pumpRight = $formatSealRange(
                $pump['jumlah_kanan'] ?? 0,
                $pump['pump_kanan_awal'] ?? null,
                $pump['pump_kanan_akhir'] ?? null,
                $sealInitial
            );

            $tankLeft = [];
            $tankRight = [];

            foreach ($tank as $tankItem) {
                $leftStart = $tankItem['tank_kiri_awal'] ?? null;
                $leftEnd = $tankItem['tank_kiri_akhir'] ?? null;

                if (
                    $leftStart !== null &&
                    $leftStart !== '' &&
                    $leftEnd !== null &&
                    $leftEnd !== ''
                ) {
                    for ($i = (int) $leftStart; $i <= (int) $leftEnd; $i++) {
                        $tankLeft[] = $sealInitial
                            . '-'
                            . str_pad((string) $i, 5, '0', STR_PAD_LEFT);
                    }
                }

                $rightStart = $tankItem['tank_kanan_awal'] ?? null;
                $rightEnd = $tankItem['tank_kanan_akhir'] ?? null;

                if (
                    $rightStart !== null &&
                    $rightStart !== '' &&
                    $rightEnd !== null &&
                    $rightEnd !== ''
                ) {
                    for ($i = (int) $rightStart; $i <= (int) $rightEnd; $i++) {
                        $tankRight[] = $sealInitial
                            . '-'
                            . str_pad((string) $i, 5, '0', STR_PAD_LEFT);
                    }
                }
            }

            $allTank = array_unique(array_merge($tankLeft, $tankRight));
            $tankSealOutput = implode(', ', $allTank);
        @endphp

        <p style="margin-bottom: 0; text-align: center;">
            <u>DELIVERY NOTE</u>
        </p>

        <p style="margin-bottom: 0; text-align: center;">
            <b>NO : {{ $data->nomor_dn_kapal ?? '-' }}</b>
        </p>

        <hr>

        <div style="width: 100%;">
            <div style="width: 50%; float: left;">
                <div style="padding: 0 5px 5px 0;">
                    <p style="margin: 0; font-size: 12pt;">
                        <u>PT. Pro Energi</u>
                    </p>

                    <p style="margin: 0;">
                        Head Office: Graha Irama Building lt.6 Unit G
                    </p>

                    <p style="margin: 0;">
                        Jl. HR. Rasuna Said Blok X-1 Kav. 1-2
                        12950 DKI Jakarta - Indonesia
                    </p>

                    <p style="margin: 0;">
                        Phone: +62-21-52892321
                    </p>

                    <p style="margin: 0;">
                        Email: info@proenergi.com
                    </p>
                </div>
            </div>

            <div style="width: 50%; float: left;">
                <div style="padding: 0 5px 5px 0;">
                    <div style="text-align: right;">
                        <barcode
                            code="{{ $barcode }}"
                            type="QR"
                            size="1"
                        />
                    </div>

                    <p
                        style="
                            margin: 0;
                            padding-left: 125px;
                            text-align: right;
                            font-size: 6pt;
                        "
                    >
                        {{ $barcode }}
                    </p>

                    <p
                        style="
                            margin: 0 0 10px;
                            text-align: right;
                            font-size: 7pt;
                        "
                    >
                        <i>
                            (This form is valid with sign by computerized system)
                        </i>
                    </p>

                    <p
                        style="
                            margin: 0;
                            font-size: 8pt;
                            text-align: right;
                        "
                    >
                        Date :
                        {{ !empty($data->tanggal_loading)
                            ? tgl_indo($data->tanggal_loading)
                            : '-' }}
                    </p>
                </div>
            </div>
        </div>

        <div style="clear: both;"></div>

        <table
            width="100%"
            border="0"
            cellpadding="0"
            cellspacing="0"
            class="tabel_rincian"
            style="margin-bottom: 15px;"
        >
            <tr>
                <td width="33%" class="b1 b4">
                    <b>Shipper</b>
                </td>

                <td width="33%" class="b1 b4">
                    <b>Consignee</b>
                </td>

                <td width="34%" class="b1 b2 b4">
                    <b>Notify Party</b>
                </td>
            </tr>

            <tr>
                <td class="b3 b4">
                    {{ $data->consignor_nama ?? '-' }}

                    <br>

                    {!! nl2br(e($data->consignor_alamat ?? '-')) !!}
                </td>

                <td class="b3 b4">
                    {{ $data->consignee_nama ?? '-' }}

                    <br>

                    {!! nl2br(e($data->consignee_alamat ?? '-')) !!}
                </td>

                <td class="b2 b3 b4">
                    {{ $data->notify_nama ?? '-' }}

                    <br>

                    {!! nl2br(e($data->notify_alamat ?? '-')) !!}
                </td>
            </tr>
        </table>

        <table
            width="100%"
            border="0"
            cellpadding="0"
            cellspacing="0"
            class="tabel_rincian"
        >
            <tr>
                <th
                    rowspan="2"
                    width="5%"
                    class="b1 b3 b4"
                    style="text-align: center;"
                >
                    No
                </th>

                <th
                    rowspan="2"
                    width="30%"
                    class="b1 b3 b4"
                    style="text-align: center;"
                >
                    Description
                </th>

                <th
                    width="15%"
                    class="b1 b3 b4"
                    style="text-align: center;"
                >
                    Quantity (BL)
                </th>

                <th
                    rowspan="2"
                    width="35%"
                    class="b1 b2 b3 b4"
                    style="text-align: center;"
                >
                    Unit
                </th>
            </tr>

            <tr>
                <th
                    width="15%"
                    class="b3 b4"
                    style="text-align: center;"
                >
                    BL
                </th>
            </tr>

            <tr>
                <td
                    rowspan="3"
                    class="b3 b4"
                    style="text-align: center;"
                >
                    1
                </td>

                <td
                    rowspan="3"
                    class="b3 b4"
                    style="text-align: center;"
                >
                    {{ $data->produk_dn ?? '-' }}
                </td>

                <td class="b3 b4" style="text-align: right;">
                    {{ !empty($data->bl_lo_jumlah)
                        ? number_format((float) $data->bl_lo_jumlah, 0, '', '.')
                        : '' }}
                </td>

                <td class="b2 b3 b4">
                    Litres Observe
                </td>
            </tr>

            <tr>
                <td class="b3 b4" style="text-align: right;">
                    {{ !empty($data->bl_lc_jumlah)
                        ? number_format((float) $data->bl_lc_jumlah, 0, '', '.')
                        : '' }}
                </td>

                <td class="b2 b3 b4">
                    Litres 15<sup>o</sup>C (GSV)
                </td>
            </tr>

            <tr>
                <td class="b3 b4" style="text-align: right;">
                    {{ !empty($data->bl_mt_jumlah)
                        ? number_format((float) $data->bl_mt_jumlah, 0, '', '.')
                        : '' }}
                </td>

                <td class="b2 b3 b4">
                    MT
                </td>
            </tr>
        </table>

        <table
            width="100%"
            border="0"
            cellpadding="0"
            cellspacing="0"
            class="tabel_rincian"
        >
            <tr>
                <td class="b4" width="20%">
                    Loading Port
                </td>

                <td width="2%" style="text-align: center;">
                    :
                </td>

                <td class="b2" width="78%">
                    {{ $data->nama_terminal ?? '-' }}
                </td>
            </tr>

            <tr>
                <td class="b3 b4">
                    Port of Discharge
                </td>

                <td class="b3" style="text-align: center;">
                    :
                </td>

                <td class="b2 b3">
                    {{ $data->port_discharge ?? '-' }}
                </td>
            </tr>

            <tr>
                <td class="b4">
                    Shipping Line
                </td>

                <td style="text-align: center;">
                    :
                </td>

                <td class="b2">
                    {{ $data->nama_suplier ?? '-' }}
                </td>
            </tr>

            <tr>
                <td class="b4">
                    Master (Captain)
                </td>

                <td style="text-align: center;">
                    :
                </td>

                <td class="b2">
                    {{ $data->kapten_name ?? '-' }}
                </td>
            </tr>

            <tr>
                <td class="b4">
                    Vessel Name
                </td>

                <td style="text-align: center;">
                    :
                </td>

                <td class="b2">
                    {{ $data->vessel_name ?? '-' }}
                </td>
            </tr>

            <tr>
                <td class="b4">
                    Shipment
                </td>

                <td style="text-align: center;">
                    :
                </td>

                <td class="b2">
                    {{ $data->shipment ?? '-' }}
                </td>
            </tr>

            <tr>
                <td class="b3 b4">
                    Seal Number
                </td>

                <td class="b3" style="text-align: center;">
                    :
                </td>

                <td class="b2 b3">
                    {{ $tankSealOutput !== '' ? $tankSealOutput : '-' }}
                </td>
            </tr>
        </table>

        {{--
        <table
            width="100%"
            border="0"
            cellpadding="0"
            cellspacing="0"
            class="tabel_rincian"
        >
            <tr>
                <td class="b3 b4" colspan="2">
                    Manifold
                </td>

                <td class="b3 b4" style="text-align: center;">
                    {!! $manifoldLeft !!}
                </td>

                <td class="b3 b4" colspan="2">
                    Manifold
                </td>

                <td class="b2 b3 b4" style="text-align: center;">
                    {!! $manifoldRight !!}
                </td>
            </tr>

            <tr>
                <td class="b3 b4" colspan="2">
                    Pump Room
                </td>

                <td class="b3 b4" style="text-align: center;">
                    {!! $pumpLeft !!}
                </td>

                <td class="b3 b4" colspan="2">
                    &nbsp;
                </td>

                <td class="b2 b3 b4" style="text-align: center;">
                    {!! $pumpRight !!}
                </td>
            </tr>
        </table>
        --}}

        @if (count($other) > 0)
            <table
                width="100%"
                border="0"
                cellpadding="0"
                cellspacing="0"
                class="tabel_rincian"
            >
                <tr>
                    <td class="b3 b4">
                        Other Seal Number
                    </td>

                    <td class="b2 b3" colspan="3">
                        :
                    </td>
                </tr>

                @foreach ($other as $otherSeal)
                    @php
                        $otherLeft = $formatSealRange(
                            $otherSeal['jumlah_kiri'] ?? 0,
                            $otherSeal['sgl_kiri_awal'] ?? null,
                            $otherSeal['sgl_kiri_akhir'] ?? null,
                            $sealInitial
                        );

                        $otherRight = $formatSealRange(
                            $otherSeal['jumlah_kanan'] ?? 0,
                            $otherSeal['sgl_kanan_awal'] ?? null,
                            $otherSeal['sgl_kanan_akhir'] ?? null,
                            $sealInitial
                        );
                    @endphp

                    <tr>
                        <td width="20%" class="b3 b4">
                            {{ $otherSeal['jns_kiri'] ?? '-' }}
                        </td>

                        <td
                            width="30%"
                            class="b3 b4"
                            style="text-align: center;"
                        >
                            {!! $otherLeft !== '' ? $otherLeft : '-' !!}
                        </td>

                        <td width="20%" class="b3 b4">
                            {{ $otherSeal['jns_kanan'] ?? '-' }}
                        </td>

                        <td
                            width="30%"
                            class="b2 b3 b4"
                            style="text-align: center;"
                        >
                            {!! $otherRight !== '' ? $otherRight : '-' !!}
                        </td>
                    </tr>
                @endforeach
            </table>
        @endif

        <table
            width="100%"
            border="0"
            cellpadding="0"
            cellspacing="0"
            class="tabel_rincian"
            style="margin-bottom: 10px;"
        >
            <tr>
                <td colspan="6" class="b2 b4">
                    Remarks :
                </td>
            </tr>

            <tr>
                <td colspan="6" class="b2 b3 b4">
                    @if ($note)
                        {!! nl2br(e($note)) !!}
                    @else
                        &nbsp;
                    @endif
                </td>
            </tr>

            <tr>
                <td colspan="6" class="b2 b4">
                    Acknowledge :
                </td>
            </tr>

            <tr>
                <td
                    width="30%"
                    class="b4"
                    style="text-align: left;"
                >
                    Shipper
                </td>

                <td width="5%" style="text-align: center;">
                    &nbsp;
                </td>

                <td width="30%" style="text-align: center;">
                    Master
                </td>

                <td width="5%" style="text-align: center;">
                    &nbsp;
                </td>

                <td width="28%" style="text-align: center;">
                    Customer
                </td>

                <td
                    width="2%"
                    class="b2"
                    style="text-align: center;"
                >
                    &nbsp;
                </td>
            </tr>

            <tr>
                <td
                    class="b4"
                    style="text-align: center; height: 30px;"
                >
                    &nbsp;
                </td>

                <td style="text-align: center;">
                    &nbsp;
                </td>

                <td style="text-align: center;">
                    &nbsp;
                </td>

                <td style="text-align: center;">
                    &nbsp;
                </td>

                <td style="text-align: center;">
                    &nbsp;
                </td>

                <td class="b2" style="text-align: center;">
                    &nbsp;
                </td>
            </tr>

            <tr>
                <td class="b4" style="text-align: left;">
                    {{ $data->created_by ?? '-' }}
                </td>

                <td style="text-align: center;">
                    &nbsp;
                </td>

                <td style="text-align: center;">
                    &nbsp;
                </td>

                <td style="text-align: center;">
                    &nbsp;
                </td>

                <td style="text-align: center;">
                    &nbsp;
                </td>

                <td class="b2" style="text-align: center;">
                    &nbsp;
                </td>
            </tr>

            <tr>
                <td class="b1 b3 b4" style="text-align: left;">
                    PT. Pro Energi
                </td>

                <td class="b3" style="text-align: center;">
                    &nbsp;
                </td>

                <td class="b1 b3" style="text-align: center;">
                    {{ $data->vessel_name ?? '-' }}
                </td>

                <td class="b3" style="text-align: center;">
                    &nbsp;
                </td>

                <td class="b1 b3" style="text-align: center;">
                    {{ $data->nama_customer ?? '-' }}
                </td>

                <td class="b2 b3" style="text-align: center;">
                    &nbsp;
                </td>
            </tr>
        </table>

    