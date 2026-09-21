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
| Export Excel Realisasi FPU
|--------------------------------------------------------------------------
| Satu Realisasi bisa memuat banyak baris rincian. Tata letak yang dipakai:
|
| - Satu baris per RINCIAN. Realisasi tanpa rincian tetap ditulis satu baris
|   supaya tidak diam-diam hilang dari laporan.
| - Kolom milik Realisasi (nomor, tanggal, nomor FPU induk, total, selisih,
|   status) di-merge vertikal sepanjang baris rinciannya.
|
| Nomor FPU disertakan sebagai referensi silang ke modul sebelah. Berbeda
| dengan arah sebaliknya, kolom ini selalu terisi: realisasi tidak pernah ada
| tanpa FPU induk.
|
| Judul kolom mengikuti locale aktif lewat file lang
| cash_advance_realization_messages.
|--------------------------------------------------------------------------
*/
/*
| WithStrictNullComparison wajib ada. Tanpa itu fromArray() PhpSpreadsheet
| memakai perbandingan longgar (0 == null) sehingga selisih bernilai 0 --
| yang justru berarti realisasinya pas -- ditulis sebagai sel KOSONG.
*/
class CashAdvanceRealizationExport implements FromArray, WithHeadings, WithEvents, WithTitle, WithStrictNullComparison
{
    protected const COLUMN_COUNT = 17;

    /** Kolom milik Realisasi (1 = A). Kolom 9-12 adalah data rincian. */
    protected const DOCUMENT_LEVEL_COLUMNS = [1, 2, 3, 4, 5, 6, 7, 8, 13, 14, 15, 16, 17];

    /** Kolom bernilai uang -> format ribuan, tetap numerik agar bisa di-SUM. */
    protected const MONEY_COLUMNS = ['J', 'K', 'L', 'M', 'N', 'O'];

    protected $data;

    /** @var array<int, array{start:int, end:int}> */
    protected array $mergeRanges = [];

    protected int $lastRow = 1;

    public function __construct($realizations)
    {
        $this->data = $realizations;
    }

    public function title(): string
    {
        return __('cash_advance_realization_messages.export.sheet_title');
    }

    public function headings(): array
    {
        $c = 'cash_advance_realization_messages.export.columns.';

        return [
            __($c . 'no'),
            __($c . 'realization_number'),
            __($c . 'date'),
            __($c . 'advance_number'),
            __($c . 'branch'),
            __($c . 'department'),
            __($c . 'transaction_category'),
            __($c . 'subject'),
            __($c . 'item_description'),
            __($c . 'item_advance_amount'),
            __($c . 'item_realization_amount'),
            __($c . 'item_difference'),
            __($c . 'total_advance_amount'),
            __($c . 'total_realization_amount'),
            __($c . 'difference_amount'),
            __($c . 'difference_type'),
            __($c . 'status'),
        ];
    }

    public function array(): array
    {
        $rows = [];
        $rowIndex = 2; // baris 1 dipakai heading
        $sequence = 1;

        foreach ($this->data as $realization) {
            $startRow = $rowIndex;

            $cashAdvance = $realization->cashAdvance ?? null;

            $date = $this->formatDate($realization->date ?? null);
            $advanceNumber = $this->formatText($cashAdvance->advance_number ?? null);
            $branch = $this->formatBranch($realization);
            $department = $this->formatDepartment($realization);
            $category = $this->formatText($realization->transactionCategory->name ?? null);
            $subject = $this->formatText($cashAdvance->subject ?? null);

            $totalAdvance = $this->formatMoney($realization->total_advance_amount ?? 0);
            $totalRealization = $this->formatMoney($realization->total_realization_amount ?? 0);
            $difference = $this->formatMoney($realization->difference_amount ?? 0);
            $differenceType = $this->formatDifferenceType($realization->difference_type ?? null);
            $status = $this->formatText($realization->status ?? null);

            $items = $realization->items ?? collect();

            if ($items->isEmpty()) {
                $rows[] = [
                    $sequence,
                    $realization->realization_number ?? '-',
                    $date,
                    $advanceNumber,
                    $branch,
                    $department,
                    $category,
                    $subject,
                    __('cash_advance_realization_messages.export.no_item'),
                    null,
                    null,
                    null,
                    $totalAdvance,
                    $totalRealization,
                    $difference,
                    $differenceType,
                    $status,
                ];

                $rowIndex++;
            } else {
                foreach ($items as $item) {
                    $itemAdvance = (float) ($item->advance_amount ?? 0);
                    $itemRealization = (float) ($item->realization_amount ?? 0);

                    $rows[] = [
                        $sequence,
                        $realization->realization_number ?? '-',
                        $date,
                        $advanceNumber,
                        $branch,
                        $department,
                        $category,
                        $subject,
                        $this->formatText($item->description ?? null),
                        $this->formatMoney($itemAdvance),
                        $this->formatMoney($itemRealization),

                        /*
                        | Selisih per baris dihitung ulang di sini, bukan diambil
                        | dari accessor model, supaya isi file tetap konsisten
                        | walau baris lama belum punya nilai tersimpan.
                        */
                        $this->formatMoney($itemAdvance - $itemRealization),

                        $totalAdvance,
                        $totalRealization,
                        $difference,
                        $differenceType,
                        $status,
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

    protected function formatDate($date): string
    {
        if (empty($date)) {
            return '-';
        }

        try {
            return \Carbon\Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string) $date;
        }
    }

    protected function formatText($value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : '-';
    }

    protected function formatMoney($value): float
    {
        return round((float) $value, 2);
    }

    protected function formatDifferenceType($type): string
    {
        $type = strtoupper(trim((string) $type));

        $map = [
            'RETURN' => 'difference_return',
            'REIMBURSE' => 'difference_reimburse',
            'NONE' => 'difference_none',
        ];

        if (isset($map[$type])) {
            return __('cash_advance_realization_messages.export.' . $map[$type]);
        }

        return __('cash_advance_realization_messages.export.difference_none');
    }

    protected function formatBranch($realization): string
    {
        $branch = $realization->branchData ?? null;

        if (!$branch) {
            return '-';
        }

        $parts = array_filter([
            $branch->inisial_cabang ?? null,
            $branch->nama_cabang ?? null,
        ]);

        return $parts ? implode(' - ', $parts) : '-';
    }

    protected function formatDepartment($realization): string
    {
        $department = $realization->departmentData ?? null;

        if (!$department) {
            return '-';
        }

        $parts = array_filter([
            $department->kode ?? null,
            $department->nama ?? null,
        ]);

        return $parts ? implode(' - ', $parts) : '-';
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
                | Merge kolom milik Realisasi sepanjang baris rinciannya.
                */
                foreach ($this->mergeRanges as $range) {
                    foreach (self::DOCUMENT_LEVEL_COLUMNS as $column) {
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

                if ($lastRow >= 2) {
                    $bodyStart = 2;

                    /*
                    | No, nomor dokumen, tanggal, jenis selisih, status -> tengah.
                    */
                    foreach ([1, 2, 3, 4, 16, 17] as $column) {
                        $letter = Coordinate::stringFromColumnIndex($column);

                        $sheet->getStyle($letter . $bodyStart . ':' . $letter . $lastRow)
                            ->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }

                    /*
                    | Kolom uang -> kanan + pemisah ribuan, tetap numerik supaya
                    | bisa langsung dijumlahkan di Excel.
                    */
                    foreach (self::MONEY_COLUMNS as $letter) {
                        $range = $letter . $bodyStart . ':' . $letter . $lastRow;

                        $sheet->getStyle($range)->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                        $sheet->getStyle($range)->getNumberFormat()
                            ->setFormatCode('#,##0.00');
                    }
                }

                /*
                | Lebar kolom ditetapkan manual. Auto-size meleset jauh karena
                | ada sel yang di-merge dan sel multi-baris.
                */
                $widths = [
                    'A' => 6,
                    'B' => 26,
                    'C' => 13,
                    'D' => 24,
                    'E' => 24,
                    'F' => 24,
                    'G' => 22,
                    'H' => 30,
                    'I' => 34,
                    'J' => 18,
                    'K' => 18,
                    'L' => 16,
                    'M' => 18,
                    'N' => 18,
                    'O' => 16,
                    'P' => 18,
                    'Q' => 14,
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
