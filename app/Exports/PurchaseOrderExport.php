<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/*
|--------------------------------------------------------------------------
| Export Excel Purchase Order
|--------------------------------------------------------------------------
| Tata letaknya mengikuti export Purchase Requisition:
|
| - Satu baris per ITEM. Jumlah baris satu PO = jumlah itemnya (minimal 1,
|   supaya PO tanpa item tetap muncul dan tidak diam-diam hilang).
| - Kolom milik PO (nomor, tanggal, vendor, cabang, department, total,
|   status, status GR, nomor PR) di-merge vertikal sepanjang baris item.
| - Nomor PR ditulis satu per baris dalam sel yang sama. Sengaja TIDAK
|   disejajarkan dengan item karena satu PR bisa menyumbang beberapa item
|   sekaligus -- menyejajarkannya memberi kesan keterkaitan yang keliru.
|
| Judul kolom mengikuti locale aktif lewat file lang purchase_order_messages.
|
| WithStrictNullComparison wajib ada. Tanpa itu, fromArray() PhpSpreadsheet
| memakai perbandingan longgar (0 == null) sehingga setiap sel bernilai 0 --
| qty 0, total 0 -- ditulis sebagai sel KOSONG, bukan angka 0.
|--------------------------------------------------------------------------
*/
class PurchaseOrderExport implements FromArray, WithHeadings, WithEvents, WithTitle, WithStrictNullComparison
{
    protected const COLUMN_COUNT = 16;

    /** Kolom yang di-merge vertikal per PO (1 = A). Kolom 7-11 adalah data item. */
    protected const PO_LEVEL_COLUMNS = [1, 2, 3, 4, 5, 6, 12, 13, 14, 15, 16];

    /** Kolom bernilai uang -> format ribuan, tetap numerik agar bisa di-SUM. */
    protected const MONEY_COLUMNS = ['H', 'K', 'L', 'M'];

    /** Kolom Total PO -- nilai level PO, bukan per item. */
    protected const TOTAL_COLUMN = 'M';

    protected $data;

    /** @var array<int, array{start:int, end:int}> */
    protected array $mergeRanges = [];

    protected int $lastRow = 1;

    public function __construct($purchaseOrders)
    {
        $this->data = $purchaseOrders;
    }

    public function title(): string
    {
        return __('purchase_order_messages.export.sheet_title');
    }

    public function headings(): array
    {
        $c = 'purchase_order_messages.export.columns.';

        return [
            __($c . 'no'),
            __($c . 'nomor_po'),
            __($c . 'tanggal_po'),
            __($c . 'vendor'),
            __($c . 'cabang'),
            __($c . 'department'),
            __($c . 'item'),
            __($c . 'harga_satuan'),
            __($c . 'qty'),
            __($c . 'satuan'),
            __($c . 'subtotal_item'),
            __($c . 'ppn'),
            __($c . 'total_po'),
            __($c . 'status'),
            __($c . 'status_gr'),
            __($c . 'nomor_pr'),
        ];
    }

    public function array(): array
    {
        $rows = [];
        $rowIndex = 2; // baris 1 dipakai heading
        $sequence = 1;

        foreach ($this->data as $po) {
            $startRow = $rowIndex;

            $nomorPr = $this->formatNomorPr($po);
            $statusGr = $this->formatStatusGr($po);
            $vendor = $this->formatVendor($po);
            $cabang = $this->formatCabang($po);
            $department = $this->formatDepartment($po);
            $tanggalPo = $this->formatTanggal($po->tanggal_po ?? null);
            $totalPo = $this->formatTotal($po->total_nilai ?? 0);
            $status = $this->formatStatus($po->status ?? null);

            /*
            | PPN diambil dari header PO, bukan dihitung dari item. Vendor non-PKP
            | menghasilkan 0 dan itu memang ditulis 0 -- bukan dikosongkan --
            | supaya kolomnya tetap bisa dijumlahkan dan tidak ambigu antara
            | "tidak kena PPN" dengan "datanya hilang".
            */
            $ppn = $this->formatTotal($po->ppn ?? 0);

            $items = $po->items ?? collect();

            if ($items->isEmpty()) {
                $rows[] = [
                    $sequence,
                    $po->nomor_po ?? '-',
                    $tanggalPo,
                    $vendor,
                    $cabang,
                    $department,
                    __('purchase_order_messages.export.no_item'),
                    null,
                    null,
                    null,
                    null,
                    $ppn,
                    $totalPo,
                    $status,
                    $statusGr,
                    $nomorPr,
                ];

                $rowIndex++;
            } else {
                foreach ($items as $item) {
                    $rows[] = [
                        $sequence,
                        $po->nomor_po ?? '-',
                        $tanggalPo,
                        $vendor,
                        $cabang,
                        $department,
                        $item->nama_item ?? '-',
                        $this->formatTotal($item->harga_unit ?? 0),
                        $this->formatQty($item->qty ?? 0),
                        $this->formatSatuan($item),
                        $this->formatSubtotalItem($item),
                        $ppn,
                        $totalPo,
                        $status,
                        $statusGr,
                        $nomorPr,
                    ];

                    $rowIndex++;
                }
            }

            $endRow = $rowIndex - 1;

            if ($endRow > $startRow) {
                $this->mergeRanges[] = [
                    'start' => $startRow,
                    'end' => $endRow,
                ];
            }

            $sequence++;
        }

        $this->lastRow = max($rowIndex - 1, 1);

        return $rows;
    }

    /*
    |--------------------------------------------------------------------------
    | Formatter
    |--------------------------------------------------------------------------
    */

    protected function formatTanggal($tanggal): string
    {
        if (empty($tanggal)) {
            return '-';
        }

        try {
            return \Carbon\Carbon::parse($tanggal)->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string) $tanggal;
        }
    }

    protected function formatVendor($po): string
    {
        $vendor = $po->vendor ?? null;

        if (!$vendor) {
            return '-';
        }

        $parts = array_filter([
            $vendor->kode_vendor ?? null,
            $vendor->nama_vendor ?? null,
        ]);

        return $parts ? implode(' - ', $parts) : '-';
    }

    protected function formatCabang($po): string
    {
        $cabang = $po->cabangData ?? null;

        if (!$cabang) {
            return '-';
        }

        $parts = array_filter([
            $cabang->inisial_cabang ?? null,
            $cabang->nama_cabang ?? null,
        ]);

        return $parts ? implode(' - ', $parts) : '-';
    }

    protected function formatDepartment($po): string
    {
        $department = $po->departmentData ?? null;

        if (!$department) {
            return '-';
        }

        $parts = array_filter([
            $department->kode ?? null,
            $department->nama ?? null,
        ]);

        return $parts ? implode(' - ', $parts) : '-';
    }

    protected function formatStatus($status): string
    {
        $status = trim((string) $status);

        return $status !== '' ? $status : '-';
    }

    protected function formatStatusGr($po): string
    {
        $statusGr = strtoupper(trim((string) ($po->status_receive ?? '')));

        $map = [
            'OPEN' => 'status_gr_open',
            'PARTIAL' => 'status_gr_partial',
            'COMPLETED' => 'status_gr_completed',
        ];

        if (isset($map[$statusGr])) {
            return __('purchase_order_messages.export.' . $map[$statusGr]);
        }

        return __('purchase_order_messages.export.status_gr_empty');
    }

    protected function formatNomorPr($po): string
    {
        $purchaseRequests = $po->purchaseRequests ?? collect();

        $nomorList = $purchaseRequests
            ->pluck('nomor_pr')
            ->filter(fn($nomor) => trim((string) $nomor) !== '')
            ->unique()
            ->values();

        if ($nomorList->isEmpty()) {
            return __('purchase_order_messages.export.no_pr');
        }

        return $nomorList->implode("\n");
    }

    protected function formatQty($qty): float
    {
        return round((float) $qty, 2);
    }

    /*
    | Dikembalikan sebagai angka agar sel tetap numerik dan bisa langsung
    | di-SUM di Excel. Tampilannya diatur lewat number format.
    */
    protected function formatTotal($total): float
    {
        return round((float) $total, 2);
    }

    /*
    | Kolom subtotal pada purchase_order_items sudah terisi saat PO disimpan.
    | Namun untuk data lama yang subtotal-nya kosong/0 padahal qty dan harga
    | terisi, nilainya dihitung ulang supaya kolom ini tidak tampil 0.
    */
    protected function formatSubtotalItem($item): float
    {
        $subtotal = (float) ($item->subtotal ?? 0);

        if ($subtotal > 0) {
            return round($subtotal, 2);
        }

        $qty = (float) ($item->qty ?? 0);
        $hargaUnit = (float) ($item->harga_unit ?? 0);

        return round($qty * $hargaUnit, 2);
    }

    protected function formatSatuan($item): string
    {
        $unit = $item->unit ?? null;

        if ($unit && trim((string) ($unit->nama ?? '')) !== '') {
            return (string) $unit->nama;
        }

        $satuan = trim((string) ($item->satuan ?? ''));

        return $satuan !== '' ? $satuan : '-';
    }

    /*
    |--------------------------------------------------------------------------
    | Styling
    |--------------------------------------------------------------------------
    */

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $lastColumn = Coordinate::stringFromColumnIndex(self::COLUMN_COUNT);
                $lastRow = $this->lastRow;

                foreach ($this->mergeRanges as $range) {
                    foreach (self::PO_LEVEL_COLUMNS as $column) {
                        $letter = Coordinate::stringFromColumnIndex($column);

                        $sheet->mergeCells(
                            $letter . $range['start'] . ':' . $letter . $range['end'],
                        );
                    }
                }

                $headerRange = 'A1:' . $lastColumn . '1';

                $sheet->getStyle($headerRange)->getFont()
                    ->setBold(true)
                    ->getColor()->setARGB('FFFFFFFF');

                $sheet->getStyle($headerRange)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FF4B4EDE');

                $sheet->getStyle($headerRange)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);

                $sheet->getRowDimension(1)->setRowHeight(28);

                if ($lastRow >= 1) {
                    $tableRange = 'A1:' . $lastColumn . $lastRow;

                    $sheet->getStyle($tableRange)->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN)
                        ->getColor()->setARGB('FFBFBFBF');

                    $sheet->getStyle($tableRange)->getAlignment()
                        ->setVertical(Alignment::VERTICAL_CENTER)
                        ->setWrapText(true);
                }

                if ($lastRow >= 2) {
                    $bodyStart = 2;

                    // No, Tanggal, Status, Status GR -> tengah
                    foreach ([1, 3, 14, 15] as $column) {
                        $letter = Coordinate::stringFromColumnIndex($column);

                        $sheet->getStyle($letter . $bodyStart . ':' . $letter . $lastRow)
                            ->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }

                    // Qty -> kanan
                    $sheet->getStyle('I' . $bodyStart . ':I' . $lastRow)
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                    // Satuan -> tengah
                    $sheet->getStyle('J' . $bodyStart . ':J' . $lastRow)
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                    /*
                    | Kolom uang -> kanan + pemisah ribuan. Nilainya tetap numerik
                    | sehingga bisa langsung dijumlahkan di Excel.
                    */
                    foreach (self::MONEY_COLUMNS as $letter) {
                        $range = $letter . $bodyStart . ':' . $letter . $lastRow;

                        $sheet->getStyle($range)->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                        $sheet->getStyle($range)->getNumberFormat()
                            ->setFormatCode('#,##0.00');
                    }

                    // Nomor PO & Nomor PR -> tengah, sering berisi banyak baris
                    foreach ([2, 16] as $column) {
                        $letter = Coordinate::stringFromColumnIndex($column);

                        $sheet->getStyle($letter . $bodyStart . ':' . $letter . $lastRow)
                            ->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }
                }

                /*
                | Lebar kolom diatur manual: auto-size meleset jauh untuk sel
                | yang di-merge dan sel multi-baris.
                */
                $widths = [
                    'A' => 6,
                    'B' => 22,
                    'C' => 14,
                    'D' => 30,
                    'E' => 24,
                    'F' => 24,
                    'G' => 32,
                    'H' => 16,
                    'I' => 10,
                    'J' => 12,
                    'K' => 18,
                    'L' => 16,
                    'M' => 18,
                    'N' => 16,
                    'O' => 14,
                    'P' => 24,
                ];

                foreach ($widths as $column => $width) {
                    $sheet->getColumnDimension($column)->setWidth($width);
                }

                $sheet->freezePane('A2');

                if ($lastRow >= 2) {
                    $sheet->setAutoFilter('A1:' . $lastColumn . $lastRow);
                }
            },
        ];
    }
}
