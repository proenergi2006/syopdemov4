<?php

namespace App\Services\FundRequest;

use App\Models\BusinessTrip;
use App\Models\CashAdvance;
use App\Models\CashAdvanceRealization;
use App\Models\Claim;

/*
|--------------------------------------------------------------------------
| Cetakan gabungan: dokumen keuangan beserta perjalanan dinasnya
|--------------------------------------------------------------------------
| FPU, Claim, dan Realisasi bisa terikat ke sebuah perdin. Ketiganya, berikut
| perdinnya sendiri, kini bisa dicetak bersama dalam satu PDF.
|
| SUSUNANNYA SELALU SAMA, dari pintu mana pun: dokumen keuangan di atas,
| perdin menyusul. Mencetak dari halaman perdin menghasilkan susunan yang
| sama persis -- keputusan yang diambil supaya satu berkas gabungan bisa
| dibandingkan dengan yang lain tanpa memperhatikan siapa yang mencetaknya.
|
| SYARATNYA DUA ARAH, DAN ITU BUKAN KEBETULAN.
|
| Perdin hanya boleh ikut bila sudah disetujui penuh -- aturan yang sudah
| berlaku pada cetakan tunggalnya, karena perdin yang belum disetujui belum
| berisi tanda tangan siapa pun dan hasil cetaknya bisa disalahpahami sebagai
| dokumen sah.
|
| Dokumen keuangan pun hanya boleh ikut bila statusnya boleh dicetak. Aturan
| yang sama, arahnya saja terbalik.
|
| Keduanya ditegakkan DI SINI, bukan di layar. URL cetak bertanda tangan
| membawa parameternya secara terbuka; parameter yang hanya dijaga layar
| bukan dijaga sama sekali.
|--------------------------------------------------------------------------
*/
class CombinedPrintComposer
{
    /** Status dokumen keuangan yang hasil cetaknya sah dibaca. */
    private const BOLEH_DICETAK = [
        CashAdvance::class => [
            CashAdvance::STATUS_APPROVED,
            CashAdvance::STATUS_RECEIVED,
            CashAdvance::STATUS_DISBURSED,
        ],

        Claim::class => [
            Claim::STATUS_APPROVED,
            Claim::STATUS_RECEIVED,
            Claim::STATUS_PAID,
        ],

        CashAdvanceRealization::class => [
            CashAdvanceRealization::STATUS_APPROVED,
            CashAdvanceRealization::STATUS_RECEIVED,
            CashAdvanceRealization::STATUS_VERIFIED,
            CashAdvanceRealization::STATUS_SETTLED,
        ],
    ];

    /** Berkas tampilan tiap dokumen keuangan. */
    private const TAMPILAN = [
        CashAdvance::class => 'cash-advance',
        Claim::class => 'claim',
        CashAdvanceRealization::class => 'cash-advance-realization',
    ];

    /**
     * Perdin yang bisa digabungkan dengan sebuah dokumen keuangan.
     *
     * Realisasi tidak menyimpan tautannya sendiri -- ia mewarisinya dari FPU
     * yang dipertanggungjawabkannya. Satu sumber kebenaran, bukan dua yang
     * bisa berbeda.
     */
    public function tripFor(object $dokumen): ?BusinessTrip
    {
        $tripId = $dokumen instanceof CashAdvanceRealization
            ? $dokumen->cashAdvance?->business_trip_id
            : $dokumen->business_trip_id;

        return $tripId ? BusinessTrip::find($tripId) : null;
    }

    /**
     * Alasan kenapa perdinnya belum bisa digabungkan, bila memang belum.
     *
     * Mengembalikan null bila boleh. Yang dikembalikan alasannya, bukan
     * sekadar benar atau salah: penolakan yang tidak mengatakan sebabnya
     * hanya memindahkan kebingungan.
     */
    public function tripBlocker(object $dokumen): ?string
    {
        $trip = $this->tripFor($dokumen);

        if (!$trip) {
            return __('fund_request_print.combine.no_trip');
        }

        if (strtoupper((string) $trip->status) !== BusinessTrip::STATUS_APPROVED) {
            return __('fund_request_print.combine.trip_not_approved', [
                'number' => (string) $trip->trip_number,
            ]);
        }

        return null;
    }

    /**
     * Dokumen keuangan yang memegang sebuah perdin, bila ada.
     *
     * Satu perdin hanya bisa dipegang satu dokumen hidup -- pemilih perdin
     * pada FPU dan Claim saling menutup. Yang sudah ditolak atau dibatalkan
     * dilewati: ia melepaskan perdinnya, dan mencetaknya bersama perjalanan
     * yang masih berlaku akan menyesatkan.
     *
     * Realisasi didahulukan daripada FPU-nya sendiri: bila pertanggungjawaban
     * sudah ada, dialah dokumen yang paling lengkap menggambarkan uangnya.
     */
    public function documentFor(BusinessTrip $trip): ?object
    {
        $fpu = CashAdvance::query()
            ->where('business_trip_id', $trip->id)
            ->whereNotIn('status', [CashAdvance::STATUS_REJECTED, CashAdvance::STATUS_CANCELLED])
            ->latest('id')
            ->first();

        if ($fpu) {
            $realisasi = CashAdvanceRealization::query()
                ->where('cash_advance_id', $fpu->id)
                ->whereNotIn('status', [
                    CashAdvanceRealization::STATUS_REJECTED,
                    CashAdvanceRealization::STATUS_CANCELLED,
                ])
                ->latest('id')
                ->first();

            if ($realisasi && $this->bolehDicetak($realisasi)) {
                return $realisasi;
            }

            return $fpu;
        }

        return Claim::query()
            ->where('business_trip_id', $trip->id)
            ->whereNotIn('status', [Claim::STATUS_REJECTED, Claim::STATUS_CANCELLED])
            ->latest('id')
            ->first();
    }

    /**
     * Alasan kenapa dokumen keuangannya belum bisa digabungkan ke cetakan
     * perdin, bila memang belum.
     */
    public function documentBlocker(BusinessTrip $trip): ?string
    {
        $dokumen = $this->documentFor($trip);

        if (!$dokumen) {
            return __('fund_request_print.combine.no_document');
        }

        if (!$this->bolehDicetak($dokumen)) {
            return __('fund_request_print.combine.document_not_printable', [
                'number' => (string) $this->nomor($dokumen),
            ]);
        }

        return null;
    }

    /**
     * Perdin mana saja pada satu halaman daftar yang punya dokumen tertaut
     * berstatus boleh dicetak.
     *
     * Dijawab sekaligus untuk seluruh halaman, bukan satu per satu: daftar
     * perdin bisa panjang, dan satu pertanyaan per baris akan terasa persis
     * ketika daftarnya paling ramai.
     *
     * @param  int[]  $tripIds
     * @return array<int, bool>
     */
    public function printableByTrip(array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $hasil = [];

        $fpu = CashAdvance::query()
            ->whereIn('business_trip_id', $tripIds)
            ->whereIn('status', self::BOLEH_DICETAK[CashAdvance::class])
            ->pluck('business_trip_id');

        foreach ($fpu as $id) {
            $hasil[(int) $id] = true;
        }

        $claim = Claim::query()
            ->whereIn('business_trip_id', $tripIds)
            ->whereIn('status', self::BOLEH_DICETAK[Claim::class])
            ->pluck('business_trip_id');

        foreach ($claim as $id) {
            $hasil[(int) $id] = true;
        }

        /*
        | Realisasi ikut dihitung lewat FPU induknya: FPU yang belum boleh
        | dicetak bisa saja sudah punya realisasi yang boleh.
        */
        $realisasi = CashAdvanceRealization::query()
            ->whereIn('status', self::BOLEH_DICETAK[CashAdvanceRealization::class])
            ->whereHas('cashAdvance', fn ($q) => $q->whereIn('business_trip_id', $tripIds))
            ->with('cashAdvance:id,business_trip_id')
            ->get();

        foreach ($realisasi as $satu) {
            $id = (int) ($satu->cashAdvance->business_trip_id ?? 0);

            if ($id > 0) {
                $hasil[$id] = true;
            }
        }

        return $hasil;
    }
    /** Berkas tampilan untuk sebuah dokumen keuangan. */
    public function viewName(object $dokumen): string
    {
        return self::TAMPILAN[$dokumen::class]
            ?? throw new \InvalidArgumentException('Dokumen tanpa tampilan cetak: ' . $dokumen::class);
    }

    /** Nomor dokumennya, apa pun jenisnya. */
    public function nomor(object $dokumen): string
    {
        return (string) (
            $dokumen->advance_number
            ?? $dokumen->claim_number
            ?? $dokumen->realization_number
            ?? '-'
        );
    }

    /**
     * Lampiran perdin: berkas pemesanan yang dicatat GA.
     *
     * Bentuknya disamakan dengan lampiran dokumen keuangan supaya partial
     * yang sama bisa menggambarnya -- yang berbeda hanya asal berkasnya.
     *
     * @return array{pages: array, others: array}
     */
    public function tripAttachments(BusinessTrip $trip): array
    {
        $trip->loadMissing('arrangements.files');

        $berkas = $trip->arrangements
            ->flatMap(fn ($satu) => $satu->files)
            ->values();

        if ($berkas->isEmpty()) {
            return ['pages' => [], 'others' => []];
        }

        $kolektor = app(PrintAttachmentCollector::class);

        /*
        | Seluruhnya satu golongan: berkas pemesanan. Memilahnya per jenis
        | pemesanan akan melahirkan judul berulang untuk hal yang sama.
        */
        $terpilah = $kolektor->collect(
            $berkas->map(function ($satu) {
                $satu->attachment_type = 'ARRANGEMENT';

                return $satu;
            }),
            ['ARRANGEMENT' => __('fund_request_print.combine.trip_attachment_label')],
        );

        return [
            'pages' => $kolektor->paginate($terpilah['images']),
            'others' => $terpilah['others'],
        ];
    }

    /** Apakah status dokumennya termasuk yang boleh dicetak. */
    private function bolehDicetak(object $dokumen): bool
    {
        $boleh = self::BOLEH_DICETAK[$dokumen::class] ?? [];

        return in_array(strtoupper((string) $dokumen->status), $boleh, true);
    }
}
