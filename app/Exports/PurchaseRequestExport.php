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
| Export Excel Purchase Requisition
|--------------------------------------------------------------------------
| Satu PR dapat memiliki banyak item dan banyak Nomor PO, dan jumlah keduanya
| tidak selalu sama. Tata letak yang dipakai:
|
| - Satu baris per ITEM. Jumlah baris satu PR = jumlah itemnya (minimal 1,
|   supaya PR tanpa item tetap muncul dan tidak diam-diam hilang dari laporan).
| - Kolom milik PR (nomor, tanggal, cabang, department, status, status PO,
|   nomor PO) di-merge vertikal sepanjang baris item tersebut.
| - Nomor PO ditulis satu per baris di dalam sel yang sama. Nomor PO sengaja
|   TIDAK dipetakan sebaris dengan item, karena satu PO bisa mencakup beberapa
|   item sekaligus -- menyejajarkannya akan memberi kesan keterkaitan yang
|   sebenarnya tidak ada.
|
| Judul kolom mengikuti locale aktif (app()->setLocale) lewat file lang
| purchase_request_messages.
|--------------------------------------------------------------------------
*/
/*
| WithStrictNullComparison wajib ada. Tanpa itu, fromArray() PhpSpreadsheet
| memakai perbandingan longgar (0 == null) sehingga setiap sel bernilai 0 --
| PPN vendor non-PKP, qty 0, harga 0 -- ditulis sebagai sel KOSONG, bukan 0.
*/
class PurchaseRequestExport implements FromArray, WithHeadings, WithEvents, WithTitle, WithStrictNullComparison
{
    protected const COLUMN_COUNT = 15;

    /** Kolom yang di-merge vertikal per PR (1 = A). Kolom 6-10 adalah data item. */
    protected const PR_LEVEL_COLUMNS = [1, 2, 3, 4, 5, 11, 12, 13, 14, 15];

    /** Kolom bernilai uang -> format ribuan, tetap numerik agar bisa di-SUM. */
    protected const MONEY_COLUMNS = ['G', 'J', 'K', 'L'];

    /** Kolom Total PR -- nilai level PR, bukan per item. */
    protected const TOTAL_COLUMN = 'L';

    protected $data;

    /** @var array<int, array{start:int, end:int}> */
    protected array $mergeRanges = [];

    protected int $lastRow = 1;

    public function __construct($purchaseRequests)
    {
        $this->data = $purchaseRequests;
    }

    public function title(): string
    {
        return __('purchase_request_messages.export.sheet_title');
    }

    public function headings(): array
    {
        $c = 'purchase_request_messages.export.columns.';

        return [
            __($c . 'no'),
            __($c . 'nomor_pr'),
            __($c . 'tanggal_pr'),
            __($c . 'cabang'),
            __($c . 'department'),
            __($c . 'item'),
            __($c . 'harga_satuan'),
            __($c . 'qty'),
            __($c . 'satuan'),
            __($c . 'subtotal_item'),
            __($c . 'ppn'),
            __($c . 'total_pr'),
            __($c . 'status'),
            __($c . 'status_po'),
            __($c . 'nomor_po'),
        ];
    }

    public function array(): array
    {
        $rows = [];
        $rowIndex = 2; // baris 1 dipakai heading
        $sequence = 1;

        foreach ($this->data as $pr) {
            $startRow = $rowIndex;

            $nomorPo = $this->formatNomorPo($pr);
            $statusPo = $this->formatStatusPo($pr);
            $totalPr = $this->formatTotal($pr->total_amount ?? 0);

            /*
            | PPN diambil dari header PR, bukan dihitung dari item. Vendor non-PKP
            | menghasilkan 0 dan itu memang ditulis 0 -- bukan dikosongkan --
            | supaya kolomnya tetap bisa dijumlahkan dan tidak ambigu antara
            | "tidak kena PPN" dengan "datanya hilang".
            */
            $ppn = $this->formatTotal($pr->ppn ?? 0);
            $cabang = $this->formatCabang($pr);
            $department = $this->formatDepartment($pr);
            $tanggalPr = $this->formatTanggal($pr->tanggal_pr ?? null);

            $items = $pr->items ?? collect();

            /*
            | PR tanpa item tetap ditulis satu baris agar tidak hilang dari
            | laporan -- kolom item diisi penanda, bukan dikosongkan.
            */
            if ($items->isEmpty()) {
                $rows[] = [
                    $sequence,
                    $pr->nomor_pr ?? '-',
                    $tanggalPr,
                    $cabang,
                    $department,
                    __('purchase_request_messages.export.no_item'),
                    null,
                    null,
                    null,
                    null,
                    $ppn,
                    $totalPr,
                    $this->formatStatus($pr->status ?? null),
                    $statusPo,
                    $nomorPo,
                ];

                $rowIndex++;
            } else {
                foreach ($items as $item) {
                    $rows[] = [
                        $sequence,
                        $pr->nomor_pr ?? '-',
                        $tanggalPr,
                        $cabang,
                        $department,
                        $item->nama_item ?? '-',
                        $this->formatTotal($item->harga_unit ?? 0),
                        $this->formatQty($item->qty ?? 0),
                        $this->formatSatuan($item),
                        $this->formatSubtotalItem($item),
                        $ppn,
                        $totalPr,
                        $this->formatStatus($pr->status ?? null),
                        $statusPo,
                        $nomorPo,
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

    protected function formatCabang($pr): string
    {
        $cabang = $pr->cabangData ?? null;

        if (!$cabang) {
            return '-';
        }

        $parts = array_filter([
            $cabang->inisial_cabang ?? null,
            $cabang->nama_cabang ?? null,
        ]);

        return $parts ? implode(' - ', $parts) : '-';
    }

    protected function formatDepartment($pr): string
    {
        $department = $pr->departmentData ?? null;

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

    protected function formatStatusPo($pr): string
    {
        $statusPo = strtoupper(trim((string) ($pr->status_po ?? '')));

        $map = [
            'OPEN' => 'status_po_open',
            'PARTIAL' => 'status_po_partial',
            'COMPLETED' => 'status_po_completed',
        ];

        if (isset($map[$statusPo])) {
            return __('purchase_request_messages.export.' . $map[$statusPo]);
        }

        return __('purchase_request_messages.export.status_po_empty');
    }

    protected function formatNomorPo($pr): string
    {
        $purchaseOrders = $pr->purchaseOrders ?? collect();

        $nomorList = $purchaseOrders
            ->pluck('nomor_po')
            ->filter(fn($nomor) => trim((string) $nomor) !== '')
            ->unique()
            ->values();

        if ($nomorList->isEmpty()) {
            return __('purchase_request_messages.export.no_po');
        }

        return $nomorList->implode("\n");
    }

    protected function formatQty($qty): float
    {
        return round((float) $qty, 2);
    }

    /*
    | Dikembalikan sebagai angka, bukan string berformat "Rp ...", supaya sel
    | tetap numerik dan bisa langsung di-SUM di Excel. Tampilannya diatur
    | lewat number format pada registerEvents().
    */
    protected function formatTotal($total): float
    {
        return round((float) $total, 2);
    }

    /*
    | Kolom subtotal pada purchase_request_items sudah terisi saat PR disimpan.
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

                /*
                | Merge kolom milik PR sepanjang baris itemnya.
                */
                foreach ($this->mergeRanges as $range) {
                    foreach (self::PR_LEVEL_COLUMNS as $column) {
                        $letter = Coordinate::stringFromColumnIndex($column);

                        $sheet->mergeCells(
                            $letter . $range['start'] . ':' . $letter . $range['end'],
                        );
                    }
                }

                /*
                | Header.
                */
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

                /*
                | Border seluruh tabel.
                */
                if ($lastRow >= 1) {
                    $tableRange = 'A1:' . $lastColumn . $lastRow;

                    $sheet->getStyle($tableRange)->getBorders()->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN)
                        ->getColor()->setARGB('FFBFBFBF');

                    $sheet->getStyle($tableRange)->getAlignment()
                        ->setVertical(Alignment::VERTICAL_CENTER)
                        ->setWrapText(true);
                }

                /*
                | Perataan per kolom.
                */
                if ($lastRow >= 2) {
                    $bodyStart = 2;

                    // No, Tanggal, Status, Status PO -> tengah
                    foreach ([1, 3, 13, 14] as $column) {
                        $letter = Coordinate::stringFromColumnIndex($column);

                        $sheet->getStyle($letter . $bodyStart . ':' . $letter . $lastRow)
                            ->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }

                    // Qty -> kanan
                    $sheet->getStyle('H' . $bodyStart . ':H' . $lastRow)
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                    // Satuan -> tengah
                    $sheet->getStyle('I' . $bodyStart . ':I' . $lastRow)
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

                    // Nomor PR & Nomor PO -> tengah, sering berisi banyak baris
                    foreach ([2, 15] as $column) {
                        $letter = Coordinate::stringFromColumnIndex($column);

                        $sheet->getStyle($letter . $bodyStart . ':' . $letter . $lastRow)
                            ->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }
                }

                /*
                | Lebar kolom. Auto-size tidak dipakai karena sel yang di-merge
                | dan sel multi-baris membuat perhitungannya meleset jauh.
                */
                $widths = [
                    'A' => 6,
                    'B' => 22,
                    'C' => 14,
                    'D' => 26,
                    'E' => 26,
                    'F' => 34,
                    'G' => 16,
                    'H' => 10,
                    'I' => 12,
                    'J' => 18,
                    'K' => 16,
                    'L' => 18,
                    'M' => 16,
                    'N' => 22,
                    'O' => 24,
                ];

                foreach ($widths as $column => $width) {
                    $sheet->getColumnDimension($column)->setWidth($width);
                }

                /*
                | Baris header dibekukan supaya tetap terlihat saat scroll.
                */
                $sheet->freezePane('A2');

                /*
                | Filter otomatis pada header.
                */
                if ($lastRow >= 2) {
                    $sheet->setAutoFilter('A1:' . $lastColumn . $lastRow);
                }
            },
        ];
    }
}
