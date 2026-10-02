<?php

namespace App\Services\BusinessTrip;

use App\Models\BusinessTrip;
use App\Models\CashAdvance;
use App\Models\CashAdvanceApproval;
use App\Models\Claim;
use App\Models\ClaimApproval;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Dokumen yang bergantung pada satu perjalanan dinas
|--------------------------------------------------------------------------
| FPU dan Claim dapat terikat ke sebuah perdin, dan hanya satu di antaranya
| sekaligus -- pemilih perdin pada keduanya saling menutup. Tetapi keduanya
| berjalan dengan alur persetujuannya sendiri, jadi sebuah FPU bisa tuntas
| disetujui ketika perdinnya masih berjalan.
|
| Dua arah, dan keduanya tidak setangkup.
|
| KE BAWAH: perdin yang mati menggugurkan dokumennya. Perjalanannya tidak jadi
| ada, jadi tidak ada yang perlu dibiayai. Dokumen yang ditinggalkan hidup
| akan membeku -- tidak bisa dicairkan karena perdinnya mati, tidak bisa
| dibatalkan karena tidak ada yang tahu ia perlu dibatalkan -- dan langkah
| persetujuannya tetap nangkring di daftar tugas, meminta persetujuan atas
| perjalanan yang sudah tidak ada.
|
| KE ATAS: dokumen yang mati TIDAK menggugurkan perdinnya. FPU yang ditolak
| bisa diajukan lagi atas perjalanan yang sama, dan memang begitu seharusnya
| -- satu penolakan nominal tidak membatalkan izin perjalanannya. Itu sudah
| berlaku lewat penyaring dipegangFpuHidup() pada pemilih perdinnya.
|
| SATU PENGECUALIAN, DAN INILAH SEBAB KELAS INI ADA.
|
| Begitu dokumennya sampai ke tangan Finance, perdinnya tidak lagi boleh
| dibatalkan diam-diam. Finance sudah memegang berkasnya, mungkin sudah
| menyiapkan uangnya. Maka yang dilakukan bukan menggugurkan dokumennya,
| melainkan MENOLAK pembatalan perdinnya, sambil mengatakan siapa yang sudah
| menerimanya dan apa jalan keluarnya.
|
| Jalan keluarnya sudah ada: Finance membatalkan penerimaannya, dokumennya
| kembali ke APPROVED, lalu perdinnya bisa dibatalkan dan dokumennya ikut
| gugur lewat jalur biasa.
|
| Untuk yang sudah dicairkan atau dibayar, tidak ada jalan keluar, dan itu
| benar: uangnya sudah keluar. Yang menyelesaikannya realisasi atau
| pengembalian dana -- bukan mengubah status dokumen menjadi seolah tidak
| pernah ada.
|--------------------------------------------------------------------------
*/
class BusinessTripDependentDocumentService
{
    /** Status dokumen yang masih bisa digugurkan tanpa merugikan siapa pun. */
    private const FPU_MASIH_GUGUR = [
        CashAdvance::STATUS_DRAFT,
        CashAdvance::STATUS_IN_PROGRESS,
        CashAdvance::STATUS_APPROVED,
    ];

    private const CLAIM_MASIH_GUGUR = [
        Claim::STATUS_DRAFT,
        Claim::STATUS_IN_PROGRESS,
        Claim::STATUS_APPROVED,
    ];

    /**
     * Dokumen yang menahan pembatalan perdinnya, bila ada.
     *
     * Dikembalikan apa adanya supaya pemanggilnya bisa menyusun pesan yang
     * menyebut nomor, penerima, dan jalan keluarnya -- bukan sekadar true
     * atau false, yang akan memaksa pembacanya menebak apa yang menahannya.
     *
     * @return array{kind: string, number: string, status: string, receiver: string, reversible: bool}|null
     */
    public function blocking(BusinessTrip $trip): ?array
    {
        $fpu = CashAdvance::query()
            ->where('business_trip_id', $trip->id)
            ->whereIn('status', [
                CashAdvance::STATUS_RECEIVED,
                CashAdvance::STATUS_DISBURSED,
            ])
            ->with('receiver:id,name')
            ->orderBy('id')
            ->first();

        if ($fpu) {
            return [
                'kind' => 'cash_advance',
                'number' => (string) $fpu->advance_number,
                'status' => strtoupper((string) $fpu->status),
                'receiver' => (string) (optional($fpu->receiver)->name ?: '-'),

                /*
                | Yang baru diterima masih bisa dilepas Finance lewat batal
                | terima. Yang sudah dicairkan tidak -- dan pesannya harus
                | mengatakan itu, bukan menyuruh orang mencoba jalan yang
                | tidak ada.
                */
                'reversible' => strtoupper((string) $fpu->status) === CashAdvance::STATUS_RECEIVED,
            ];
        }

        $claim = Claim::query()
            ->where('business_trip_id', $trip->id)
            ->whereIn('status', [
                Claim::STATUS_RECEIVED,
                Claim::STATUS_PAID,
            ])
            ->with('receiver:id,name')
            ->orderBy('id')
            ->first();

        if ($claim) {
            return [
                'kind' => 'claim',
                'number' => (string) $claim->claim_number,
                'status' => strtoupper((string) $claim->status),
                'receiver' => (string) (optional($claim->receiver)->name ?: '-'),
                'reversible' => strtoupper((string) $claim->status) === Claim::STATUS_RECEIVED,
            ];
        }

        return null;
    }

    /**
     * Menggugurkan FPU dan Claim yang bergantung pada perdin ini.
     *
     * Dipanggil DI DALAM transaksi pembatalan perdinnya: dokumen yang gugur
     * dan perdin yang membuatnya gugur harus tersimpan bersama atau tidak
     * sama sekali. Perdin yang batal sementara FPU-nya masih hidup persis
     * keadaan yang hendak dihindari kelas ini.
     *
     * Mengembalikan yang digugurkan beserta langkah persetujuan yang tugasnya
     * ikut hilang, supaya pemanggilnya bisa mengabari mereka SETELAH
     * transaksinya tuntas -- notifikasi yang terkirim lalu transaksinya gagal
     * akan memberitahukan sesuatu yang tidak pernah terjadi.
     *
     * Yang dikembalikan barisnya, bukan daftar id pengguna: penyetuju bisa
     * ditunjuk sebagai peran, dan pada baris itu approver_id adalah id peran,
     * bukan id orang. Yang tahu cara menguraikannya layanan notifikasi
     * masing-masing modul, dan kolom yang dibutuhkannya tidak ikut berubah
     * saat langkahnya dimatikan.
     *
     * @return array<int, array{kind: string, model: CashAdvance|Claim, approvals: \Illuminate\Support\Collection}>
     */
    public function cascadeCancel(
        BusinessTrip $trip,
        User $actor,
        string $note,
    ): array {
        $hasil = [];

        $fpuList = CashAdvance::query()
            ->where('business_trip_id', $trip->id)
            ->whereIn('status', self::FPU_MASIH_GUGUR)
            ->get();

        foreach ($fpuList as $fpu) {
            $menggantung = $this->matikanLangkah(
                CashAdvanceApproval::query()->where('cash_advance_id', $fpu->id),
                CashAdvanceApproval::STATUS_WAITING,
                CashAdvanceApproval::STATUS_PENDING,
                CashAdvanceApproval::STATUS_CANCELLED,
                $note,
            );

            $fpu->update([
                'status' => CashAdvance::STATUS_CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_notes' => $note,
            ]);

            $hasil[] = [
                'kind' => 'cash_advance',
                'model' => $fpu,
                'approvals' => $menggantung,
            ];
        }

        $claimList = Claim::query()
            ->where('business_trip_id', $trip->id)
            ->whereIn('status', self::CLAIM_MASIH_GUGUR)
            ->get();

        foreach ($claimList as $claim) {
            $menggantung = $this->matikanLangkah(
                ClaimApproval::query()->where('claim_id', $claim->id),
                ClaimApproval::STATUS_WAITING,
                ClaimApproval::STATUS_PENDING,
                ClaimApproval::STATUS_CANCELLED,
                $note,
            );

            $claim->update([
                'status' => Claim::STATUS_CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_notes' => $note,
            ]);

            $hasil[] = [
                'kind' => 'claim',
                'model' => $claim,
                'approvals' => $menggantung,
            ];
        }

        return $hasil;
    }

    /**
     * Mematikan langkah persetujuan yang belum terpakai.
     *
     * Dikumpulkan dulu barisnya, baru dimatikan: sesudah statusnya berubah,
     * tidak ada lagi cara mengetahui siapa yang tugasnya hilang -- dan
     * merekalah yang justru perlu dikabari.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Support\Collection
     */
    private function matikanLangkah(
        $query,
        string $waiting,
        string $pending,
        string $cancelled,
        string $note,
    ) {
        $menggantung = (clone $query)
            ->whereIn('status', [$waiting, $pending])
            ->get();

        if ($menggantung->isEmpty()) {
            return $menggantung;
        }

        (clone $query)
            ->whereIn('status', [$waiting, $pending])
            ->update([
                'status' => $cancelled,
                'notes' => $note,
                'updated_at' => now(),
            ]);

        return $menggantung;
    }
}
