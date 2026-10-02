<?php

namespace App\Exports;

use App\Models\FundRequestArrangedCategory;
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
| Export Excel FPU
|--------------------------------------------------------------------------
| Satu FPU bisa memuat banyak baris rincian. Tata letak yang dipakai:
|
| - Satu baris per RINCIAN. FPU tanpa rincian tetap ditulis satu baris supaya
|   tidak diam-diam hilang dari laporan.
| - Kolom milik FPU (nomor, tanggal, cabang, department, total, status, dan
|   nomor realisasinya) di-merge vertikal sepanjang baris rinciannya, jadi
|   satu FPU tetap terbaca sebagai satu kesatuan.
|
| Nomor Realisasi disertakan sebagai referensi silang ke modul sebelah. FPU
| yang belum dipertanggungjawabkan dikosongkan pada kolom itu -- bukan diberi
| tanda hubung -- supaya mudah disaring di Excel.
|
| Judul kolom mengikuti locale aktif lewat file lang cash_advance_messages.
|--------------------------------------------------------------------------
*/
/*
| WithStrictNullComparison wajib ada. Tanpa itu fromArray() PhpSpreadsheet
| memakai perbandingan longgar (0 == null) sehingga setiap sel bernilai 0
| ditulis sebagai sel KOSONG, bukan angka 0.
*/
class CashAdvanceExport implements FromArray, WithHeadings, WithEvents, WithTitle, WithStrictNullComparison
{
    protected const COLUMN_COUNT = 19;

    /** Kolom milik FPU (1 = A). Kolom 9-14 adalah data rincian. */
    protected const DOCUMENT_LEVEL_COLUMNS = [1, 2, 3, 4, 5, 6, 7, 8, 15, 16, 17, 18, 19];

    /** Kolom bernilai uang -> format ribuan, tetap numerik agar bisa di-SUM. */
    protected const MONEY_COLUMNS = ['M', 'N', 'O'];

    /**
     * Kategori yang diurus GA, per id FPU.
     *
     * Dikumpulkan sekali di muka, bukan ditanyakan per dokumen: laporan ini
     * bisa memuat ratusan FPU, dan satu query per dokumen akan terasa
     * persis ketika laporannya paling dibutuhkan.
     *
     * @var array<int, array<int, string>>
     */
    protected array $arrangedByDocument = [];

    protected $data;

    /** @var array<int, array{start:int, end:int}> */
    protected array $mergeRanges = [];

    protected int $lastRow = 1;

    public function __construct($cashAdvances)
    {
        $this->data = $cashAdvances;
    }

    public function title(): string
    {
        return __('cash_advance_messages.export.sheet_title');
    }

    public function headings(): array
    {
        $c = 'cash_advance_messages.export.columns.';

        return [
            __($c . 'no'),
            __($c . 'advance_number'),
            __($c . 'date'),
            __($c . 'branch'),
            __($c . 'department'),
            __($c . 'transaction_category'),
            __($c . 'request_type'),
            __($c . 'subject'),
            __($c . 'item_date'),
            __($c . 'item_description'),

            /* Terisi hanya pada rincian perjalanan dinas. */
            __($c . 'expense_category'),
            __($c . 'qty'),
            __($c . 'unit_price'),

            __($c . 'item_amount'),
            __($c . 'total_amount'),
            __($c . 'status'),
            __($c . 'realization_number'),
            __($c . 'realization_status'),
            __($c . 'created_by'),
        ];
    }

    /**
     * Kategori yang diurus GA pada seluruh FPU perdin di laporan ini.
     */
    protected function collectArranged(): void
    {
        $ids = [];

        foreach ($this->data as $cashAdvance) {
            if (!empty($cashAdvance->business_trip_id)) {
                $ids[] = (int) $cashAdvance->id;
            }
        }

        if (!$ids) {
            return;
        }

        $baris = FundRequestArrangedCategory::query()
            ->where('document_type', FundRequestArrangedCategory::DOC_CASH_ADVANCE)
            ->whereIn('document_id', $ids)
            ->join(
                'business_trip_expense_categories',
                'business_trip_expense_categories.id',
                '=',
                'fund_request_arranged_categories.expense_category_id',
            )
            ->orderBy('business_trip_expense_categories.sort_order')
            ->get([
                'fund_request_arranged_categories.document_id',
                'business_trip_expense_categories.name',
            ]);

        foreach ($baris as $satu) {
            $this->arrangedByDocument[(int) $satu->document_id][] = (string) $satu->name;
        }
    }

    public function array(): array
    {
        $this->collectArranged();

        $rows = [];
        $rowIndex = 2; // baris 1 dipakai heading
        $sequence = 1;

        foreach ($this->data as $cashAdvance) {
            $startRow = $rowIndex;

            $date = $this->formatDate($cashAdvance->date ?? null);
            $branch = $this->formatBranch($cashAdvance);
            $department = $this->formatDepartment($cashAdvance);
            $category = $this->formatText($cashAdvance->transactionCategory->name ?? null);
            $requestType = $this->formatText($cashAdvance->request_type ?? null);
            $subject = $this->formatText($cashAdvance->subject ?? null);
            $total = $this->formatMoney($cashAdvance->total_amount ?? 0);
            $status = $this->formatText($cashAdvance->status ?? null);
            $createdBy = $this->formatText($cashAdvance->creator->name ?? null);

            $realization = $cashAdvance->realization ?? null;

            /*
            | Sengaja null, bukan '-'. Sel kosong lebih mudah disaring dengan
            | filter "Blanks" saat mencari FPU yang belum direalisasi.
            */
            $realizationNumber = $realization->realization_number ?? null;
            $realizationStatus = $realization->status ?? null;

            /*
            | Bentuk rinciannya ditentukan dokumennya, bukan barisnya: satu
            | baris yang kebetulan tidak berkategori tidak membuat FPU-nya
            | berhenti menjadi perdin.
            */
            $perdin = $cashAdvance->business_trip_id !== null;

            $items = $cashAdvance->items ?? collect();

            if ($items->isEmpty()) {
                $rows[] = [
                    $sequence,
                    $cashAdvance->advance_number ?? '-',
                    $date,
                    $branch,
                    $department,
                    $category,
                    $requestType,
                    $subject,
                    null,
                    __('cash_advance_messages.export.no_item'),
                    null,
                    null,
                    null,
                    null,
                    $total,
                    $status,
                    $realizationNumber,
                    $realizationStatus,
                    $createdBy,
                ];

                $rowIndex++;
            } else {
                foreach ($items as $item) {
                    $rows[] = [
                        $sequence,
                        $cashAdvance->advance_number ?? '-',
                        $date,
                        $branch,
                        $department,
                        $category,
                        $requestType,
                        $subject,
                        /*
                        | Rincian perdin tidak bertanggal. Tanggal peninggalan
                        | rincian lama dikosongkan di sini, bukan ditampilkan:
                        | angka yang tidak lagi berarti tetapi masih terbaca
                        | sebagai berarti lebih buruk daripada sel kosong.
                        */
                        $perdin ? null : $this->formatDate($item->date ?? null),

                        $this->formatText($item->description ?? null),

                        /*
                        | Null, bukan '-', pada rincian bukan-perdin: sel kosong
                        | lebih mudah disaring, dan tanda hubung di kolom angka
                        | membuat selnya berhenti numerik.
                        |
                        | Pada perdin, baris yang tidak berkategori justru diberi
                        | keterangan: ia rincian lama dari sebelum FPU-nya menjadi
                        | perdin, dan kosongnya perlu terbaca sebagai keadaan,
                        | bukan sebagai sel yang gagal terisi.
                        */
                        $item->expenseCategory->name
                            ?? ($perdin ? __('cash_advance_messages.export.no_expense_category') : null),
                        $item->qty !== null ? (float) $item->qty : null,
                        $item->unit_price !== null ? (float) $item->unit_price : null,

                        $this->formatMoney($item->amount ?? 0),
                        $total,
                        $status,
                        $realizationNumber,
                        $realizationStatus,
                        $createdBy,
                    ];

                    $rowIndex++;
                }
            }

            /*
            | Kategori yang diurus GA mendapat barisnya sendiri -- tanpa itu ia
            | hilang sama sekali dari laporan, dan yang hilang dari laporan
            | terbaca sebagai tidak pernah ada, bukan sebagai sengaja kosong.
            */
            foreach ($this->arrangedByDocument[(int) $cashAdvance->id] ?? [] as $namaKategori) {
                $rows[] = [
                    $sequence,
                    $cashAdvance->advance_number ?? '-',
                    $date,
                    $branch,
                    $department,
                    $category,
                    $requestType,
                    $subject,
                    null,
                    __('cash_advance_messages.export.arranged_by_ga'),
                    $namaKategori,
                    null,
                    null,
                    null,
                    $total,
                    $status,
                    $realizationNumber,
                    $realizationStatus,
                    $createdBy,
                ];

                $rowIndex++;
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

    protected function formatBranch($cashAdvance): string
    {
        $branch = $cashAdvance->branchData ?? null;

        if (!$branch) {
            return '-';
        }

        $parts = array_filter([
            $branch->inisial_cabang ?? null,
            $branch->nama_cabang ?? null,
        ]);

        return $parts ? implode(' - ', $parts) : '-';
    }

    protected function formatDepartment($cashAdvance): string
    {
        $department = $cashAdvance->departmentData ?? null;

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
                | Merge kolom milik FPU sepanjang baris rinciannya.
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
                    | No, tanggal, jenis pengajuan, status, nomor dokumen -> tengah.
                    */
                    foreach ([1, 2, 3, 7, 9, 12, 16, 17, 18] as $column) {
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
                    'F' => 22,
                    'G' => 14,
                    'H' => 30,
                    'I' => 13,
                    'J' => 34,
                    'K' => 20,
                    'L' => 8,
                    'M' => 16,
                    'N' => 18,
                    'O' => 18,
                    'P' => 14,
                    'Q' => 26,
                    'R' => 16,
                    'S' => 22,
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
