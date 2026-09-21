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
| Export Excel Perjalanan Dinas
|--------------------------------------------------------------------------
| Satu baris per FPU yang menumpang perdinnya. Perjalanan yang belum diajukan
| biayanya tetap ditulis satu baris, dengan kolom FPU dikosongkan -- sel
| kosong mudah disaring dengan filter "Blanks", dan perjalanan itu tidak
| diam-diam hilang dari laporan.
|
| Rundown sengaja TIDAK ikut. Ia agenda jam per jam, dan di laporan ia hanya
| melipatgandakan baris tanpa menambah keterangan: satu perjalanan lima hari
| menjadi lima belas baris yang kolom-kolom lainnya berulang persis. Yang
| dicari dari sebuah laporan perjalanan adalah berapa biayanya dan sampai di
| mana dokumennya -- itu yang kini menempati kolomnya. Rundown tetap terbaca
| utuh pada cetakan PDF perdinnya, tempat ia memang berguna.
|
| Biasanya satu perdin punya satu FPU. Bisa lebih dari satu bila pengajuan
| sebelumnya ditolak atau dibatalkan -- perdinnya kembali terbuka supaya
| perjalanan yang sudah disetujui tidak terkunci selamanya oleh satu
| penolakan. Riwayat itu ikut tertulis apa adanya, dan kolom Status FPU yang
| menjelaskannya.
|
| Judul kolom mengikuti locale aktif lewat file lang business_trip_messages.
|--------------------------------------------------------------------------
*/
/*
| WithStrictNullComparison wajib ada. Tanpa itu fromArray() PhpSpreadsheet
| memakai perbandingan longgar (0 == null) sehingga setiap sel bernilai 0
| ditulis sebagai sel KOSONG, bukan angka 0.
*/
class BusinessTripExport implements FromArray, WithHeadings, WithEvents, WithTitle, WithStrictNullComparison
{
    protected const COLUMN_COUNT = 17;

    /** Kolom milik perdin (1 = A). Kolom 14-17 milik FPU-nya. */
    protected const DOCUMENT_LEVEL_COLUMNS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13];

    /** Kolom bernilai uang -> format ribuan, tetap numerik agar bisa di-SUM. */
    protected const MONEY_COLUMNS = ['P'];

    protected $data;

    /** @var array<int, array{start:int, end:int}> */
    protected array $mergeRanges = [];

    protected int $lastRow = 1;

    public function __construct($trips)
    {
        $this->data = $trips;
    }

    public function title(): string
    {
        return __('business_trip_messages.export.sheet_title');
    }

    public function headings(): array
    {
        $c = 'business_trip_messages.export.columns.';

        return [
            __($c . 'no'),
            __($c . 'trip_number'),
            __($c . 'date'),
            __($c . 'branch'),
            __($c . 'department'),
            __($c . 'employee_name'),
            __($c . 'position'),
            __($c . 'destination'),
            __($c . 'depart'),
            __($c . 'return'),
            __($c . 'duration'),
            __($c . 'purpose'),
            __($c . 'trip_status'),
            __($c . 'cash_advance_number'),
            __($c . 'cash_advance_date'),
            __($c . 'cash_advance_amount'),
            __($c . 'cash_advance_status'),
        ];
    }

    public function array(): array
    {
        $rows = [];
        $rowIndex = 2; // baris 1 dipakai heading
        $sequence = 1;

        foreach ($this->data as $trip) {
            $startRow = $rowIndex;

            $kepala = [
                $sequence,
                $trip->trip_number ?? '-',
                $this->formatDate($trip->date ?? null),
                $this->formatBranch($trip),
                $this->formatText($trip->department_name ?? null),
                $this->formatText($trip->employee_name ?? null),
                $this->formatText($trip->position_name ?? null),
                $this->formatText($trip->destination ?? null),
                $this->formatMoment($trip->depart_date ?? null, $trip->depart_time ?? null),
                $this->formatMoment($trip->return_date ?? null, $trip->return_time ?? null),
                (int) ($trip->duration_days ?? 0),
                $this->formatText($trip->purpose ?? null),
                $this->formatText($trip->status ?? null),
            ];

            $fpu = $trip->cashAdvances ?? collect();

            if ($fpu->isEmpty()) {
                /*
                | Sengaja null, bukan '-'. Sel kosong mudah disaring dengan
                | filter "Blanks" saat mencari perjalanan yang belum diajukan
                | biayanya.
                */
                $rows[] = array_merge($kepala, [null, null, null, null]);

                $rowIndex++;
            } else {
                foreach ($fpu as $dokumen) {
                    $rows[] = array_merge($kepala, [
                        $this->formatText($dokumen->advance_number ?? null),
                        $this->formatDate($dokumen->date ?? null),
                        $this->formatMoney($dokumen->total_amount ?? 0),
                        $this->formatText($dokumen->status ?? null),
                    ]);

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

    /*
    | Tanggal dan jamnya dijadikan satu sel: keduanya selalu dibaca bersama,
    | dan memisahnya berarti empat kolom hanya untuk menyebut dua saat.
    */
    protected function formatMoment($date, $time): string
    {
        $tanggal = $this->formatDate($date);
        $jam = trim((string) $time);

        if ($jam === '') {
            return $tanggal;
        }

        return $tanggal . ' ' . substr($jam, 0, 5);
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

    protected function formatBranch($trip): string
    {
        $branch = $trip->branchData ?? null;

        if (!$branch) {
            return '-';
        }

        $parts = array_filter([
            $branch->inisial_cabang ?? null,
            $branch->nama_cabang ?? null,
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
                | Merge kolom milik perdin sepanjang baris FPU-nya.
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
                    | No, tanggal, periode, lama, status, dan nomor dokumen -> tengah.
                    */
                    foreach ([1, 2, 3, 9, 10, 11, 13, 14, 15, 17] as $column) {
                        $letter = Coordinate::stringFromColumnIndex($column);

                        $sheet->getStyle($letter . $bodyStart . ':' . $letter . $lastRow)
                            ->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }

                    /*
                    | Biaya FPU -> kanan + pemisah ribuan, tetap numerik supaya
                    | bisa langsung dijumlahkan di Excel.
                    */
                    foreach (self::MONEY_COLUMNS as $letter) {
                        $range = $letter . $bodyStart . ':' . $letter . $lastRow;

                        $sheet->getStyle($range)->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                        $sheet->getStyle($range)->getNumberFormat()
                            ->setFormatCode('#,##0.00');
                    }

                    /*
                    | Lama perjalanan tetap numerik supaya bisa dijumlahkan dan
                    | dirata-rata, bukan ditulis "3 hari".
                    */
                    $sheet->getStyle('K' . $bodyStart . ':K' . $lastRow)
                        ->getNumberFormat()
                        ->setFormatCode('0');
                }

                /*
                | Lebar kolom ditetapkan manual. Auto-size meleset jauh karena
                | ada sel yang di-merge dan sel multi-baris.
                */
                $widths = [
                    'A' => 6,
                    'B' => 24,
                    'C' => 15,
                    'D' => 24,
                    'E' => 24,
                    'F' => 24,
                    'G' => 20,
                    'H' => 22,
                    'I' => 18,
                    'J' => 18,
                    'K' => 10,
                    'L' => 30,
                    'M' => 14,
                    'N' => 24,
                    'O' => 15,
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
