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
| Export Excel Claim
|--------------------------------------------------------------------------
| Satu Claim bisa memuat banyak baris rincian. Tata letak yang dipakai:
|
| - Satu baris per RINCIAN. Claim tanpa rincian tetap ditulis satu baris supaya
|   tidak diam-diam hilang dari laporan.
| - Kolom milik Claim (nomor, tanggal, cabang, department, total, dan status)
|   di-merge vertikal sepanjang baris rinciannya, jadi satu Claim tetap
|   terbaca sebagai satu kesatuan.
|
| Tidak ada kolom referensi dokumen lanjutan: Claim selesai pada dirinya
| sendiri, tidak dipertanggungjawabkan lewat dokumen lain.
|
| Judul kolom mengikuti locale aktif lewat file lang claim_messages.
|--------------------------------------------------------------------------
*/
/*
| WithStrictNullComparison wajib ada. Tanpa itu fromArray() PhpSpreadsheet
| memakai perbandingan longgar (0 == null) sehingga setiap sel bernilai 0
| ditulis sebagai sel KOSONG, bukan angka 0.
*/
class ClaimExport implements FromArray, WithHeadings, WithEvents, WithTitle, WithStrictNullComparison
{
    protected const COLUMN_COUNT = 13;

    /** Kolom milik Claim (1 = A). Kolom 8-10 adalah data rincian. */
    protected const DOCUMENT_LEVEL_COLUMNS = [1, 2, 3, 4, 5, 6, 7, 11, 12, 13];

    /** Kolom bernilai uang -> format ribuan, tetap numerik agar bisa di-SUM. */
    protected const MONEY_COLUMNS = ['J', 'K'];

    protected $data;

    /** @var array<int, array{start:int, end:int}> */
    protected array $mergeRanges = [];

    protected int $lastRow = 1;

    public function __construct($claims)
    {
        $this->data = $claims;
    }

    public function title(): string
    {
        return __('claim_messages.export.sheet_title');
    }

    public function headings(): array
    {
        $c = 'claim_messages.export.columns.';

        return [
            __($c . 'no'),
            __($c . 'claim_number'),
            __($c . 'date'),
            __($c . 'branch'),
            __($c . 'department'),
            __($c . 'transaction_category'),
            __($c . 'subject'),
            __($c . 'item_date'),
            __($c . 'item_description'),
            __($c . 'item_amount'),
            __($c . 'total_amount'),
            __($c . 'status'),
            __($c . 'created_by'),
        ];
    }

    public function array(): array
    {
        $rows = [];
        $rowIndex = 2; // baris 1 dipakai heading
        $sequence = 1;

        foreach ($this->data as $claim) {
            $startRow = $rowIndex;

            $date = $this->formatDate($claim->date ?? null);
            $branch = $this->formatBranch($claim);
            $department = $this->formatDepartment($claim);
            $category = $this->formatText($claim->transactionCategory->name ?? null);
            $subject = $this->formatText($claim->subject ?? null);
            $total = $this->formatMoney($claim->total_amount ?? 0);
            $status = $this->formatText($claim->status ?? null);
            $createdBy = $this->formatText($claim->creator->name ?? null);

            $items = $claim->items ?? collect();

            if ($items->isEmpty()) {
                $rows[] = [
                    $sequence,
                    $claim->claim_number ?? '-',
                    $date,
                    $branch,
                    $department,
                    $category,
                    $subject,
                    null,
                    __('claim_messages.export.no_item'),
                    null,
                    $total,
                    $status,
                    $createdBy,
                ];

                $rowIndex++;
            } else {
                foreach ($items as $item) {
                    $rows[] = [
                        $sequence,
                        $claim->claim_number ?? '-',
                        $date,
                        $branch,
                        $department,
                        $category,
                        $subject,
                        $this->formatDate($item->date ?? null),
                        $this->formatText($item->description ?? null),
                        $this->formatMoney($item->amount ?? 0),
                        $total,
                        $status,
                        $createdBy,
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

    /*
    | Dikembalikan sebagai angka, bukan string "Rp ...", supaya selnya tetap
    | numerik dan bisa langsung di-SUM. Tampilannya diatur lewat number format
    | pada registerEvents().
    */
    protected function formatMoney($value): float
    {
        return round((float) $value, 2);
    }

    protected function formatBranch($claim): string
    {
        $branch = $claim->branchData ?? null;

        if (!$branch) {
            return '-';
        }

        $parts = array_filter([
            $branch->inisial_cabang ?? null,
            $branch->nama_cabang ?? null,
        ]);

        return $parts ? implode(' - ', $parts) : '-';
    }

    protected function formatDepartment($claim): string
    {
        $department = $claim->departmentData ?? null;

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
                | Merge kolom milik Claim sepanjang baris rinciannya.
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
                    | No, nomor dokumen, tanggal, tanggal rincian, status -> tengah.
                    */
                    foreach ([1, 2, 3, 8, 12] as $column) {
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
                    'B' => 24,
                    'C' => 13,
                    'D' => 24,
                    'E' => 24,
                    'F' => 26,
                    'G' => 30,
                    'H' => 13,
                    'I' => 34,
                    'J' => 18,
                    'K' => 18,
                    'L' => 14,
                    'M' => 22,
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
