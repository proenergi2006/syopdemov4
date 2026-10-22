<?php

namespace App\Services\FundRequest;

use App\Models\BusinessTrip;
use App\Models\CashAdvance;
use App\Models\CashAdvanceRealization;
use App\Models\Claim;
use App\Models\FundRequestRevision;
use App\Models\Notification;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Kabar seputar revisi dokumen
|--------------------------------------------------------------------------
| Tiga saat yang harus terkabarkan, dan ketiganya berlaku sama di FPU,
| Realisasi, Claim, dan Perdin:
|
|   1. Dikembalikan     -> ke pemohon, beserta alasannya.
|   2. Mulai dikerjakan -> ke yang mengembalikan, supaya tahu dokumennya
|                          sedang ditangani dan bukan terdiam.
|   3. Diajukan ulang   -> ke approver tahap pertama, DENGAN menyebut bahwa
|                          yang akan ia setujui adalah dokumen revisian;
|                          dan ke yang mengembalikan, supaya tahu
|                          permintaannya sudah dipenuhi.
|
| KENAPA DI SATU TEMPAT.
|
| Keempat modul punya notification service sendiri, dan itu memang tepat:
| penerima notifikasi approval harus diambil dari baris approval masing-
| masing modul. Tetapi kabar revisi hampir seluruhnya BUKAN soal itu --
| penerimanya satu orang yang sudah diketahui namanya (pemohon, atau yang
| mengembalikan). Menyalinnya empat kali berarti pada hari kalimatnya
| diperbaiki, tiga modul tertinggal.
|
| Yang memang perlu pengetahuan modul -- menerjemahkan baris approval
| menjadi daftar pengguna -- diminta sebagai callable dari pemanggilnya,
| sehingga pengetahuan itu tetap tinggal di modulnya.
|--------------------------------------------------------------------------
*/
class RevisionNotifier
{
    /**
     * Keterangan tiap modul: kode, alamat layar, dan nama kolom nomornya.
     *
     * @var array<class-string, array{code: string, url: string, number: string, label: string}>
     */
    private const MODUL = [
        CashAdvance::class => [
            'code' => 'cash_advance',
            'url' => '/fund_request/cash_advance',
            'number' => 'advance_number',
            'label' => 'FPU',
        ],
        Claim::class => [
            'code' => 'claim',
            'url' => '/fund_request/claim',
            'number' => 'claim_number',
            'label' => 'Claim',
        ],
        CashAdvanceRealization::class => [
            'code' => 'cash_advance_realization',
            'url' => '/fund_request/cash_advance_realization',
            'number' => 'realization_number',
            'label' => 'Realisasi',
        ],
        BusinessTrip::class => [
            'code' => 'business_trip',
            'url' => '/business_trip/perdin',
            'number' => 'trip_number',
            'label' => 'Perdin',
        ],

        /*
        | PR dan PO ikut di sini, walau keduanya bukan modul pengajuan dana.
        |
        | Yang disamakan bukan jenis dokumennya, melainkan peristiwanya:
        | dikembalikan, dikerjakan, diajukan ulang. Ketiganya berbunyi sama
        | di mana pun, dan kalimatnya pun satu.
        */
        PurchaseRequest::class => [
            'code' => 'purchase_request',
            'url' => '/non_stock/purchase_request',
            'number' => 'nomor_pr',
            'label' => 'PR',
        ],
        PurchaseOrder::class => [
            'code' => 'purchase_order',
            'url' => '/non_stock/purchase_order',
            'number' => 'nomor_po',
            'label' => 'PO',
        ],
    ];

    /** 1. Dokumennya dikembalikan ke pemohon. */
    public function dikembalikan(Model $document, FundRequestRevision $revisi): void
    {
        $pemohon = $this->pemohon($document);

        if (!$pemohon) {
            return;
        }

        $this->kirim(
            $pemohon,
            $document,
            'returned',
            [
                'number' => $this->nomor($document),
                'by' => $revisi->returnedBy?->name ?? '-',

                /*
                | Alasannya dipendekkan di sini saja.
                |
                | Yang utuh tersimpan di catatan revisinya dan ditampilkan
                | penuh di layar pemohon. Notifikasi adalah baris pendek di
                | daftar; alasan 2000 karakter di situ hanya akan mendorong
                | pemberitahuan lain keluar dari pandangan.
                */
                'reason' => \Illuminate\Support\Str::limit($revisi->return_reason, 200),
                'revision' => $revisi->revision_number,
            ],
        );
    }

    /** 2. Pemohon mulai mengerjakan revisinya. */
    public function sedangDirevisi(Model $document, FundRequestRevision $revisi): void
    {
        $penerima = $revisi->returned_by
            ? User::query()->whereKey((int) $revisi->returned_by)->first()
            : null;

        if (!$penerima) {
            return;
        }

        $this->kirim(
            $penerima,
            $document,
            'being_revised',
            [
                'number' => $this->nomor($document),
                'revision' => $revisi->revision_number,
            ],
        );
    }

    /**
     * 3. Dokumen revisian diajukan ulang.
     *
     * @param  callable(mixed): Collection  $resolverApprover  Baris approval -> daftar pengguna
     */
    public function diajukanUlang(
        Model $document,
        FundRequestRevision $revisi,
        callable $resolverApprover,
    ): void {
        $params = [
            'number' => $this->nomor($document),
            'revision' => $revisi->revision_number,
        ];

        /*
        | Approver tahap aktif.
        |
        | Kalimatnya sengaja berbeda dari permintaan persetujuan biasa: yang
        | ada di meja mereka adalah dokumen yang pernah dikembalikan dan
        | sudah berubah. Approver yang tidak diberi tahu akan menyetujuinya
        | sebagai dokumen baru, dan perubahan yang justru jadi sebab
        | pengembaliannya lewat tanpa dilihat siapa pun.
        */
        if (method_exists($document, 'approvals')) {
            /*
            | 'WAITING' ditulis apa adanya, bukan lewat konstanta.
            |
            | Keempat modul punya model approval sendiri dengan konstanta
            | STATUS_WAITING sendiri -- bernilai sama persis -- dan servis ini
            | sengaja tidak tahu sedang mengurus modul yang mana. Memilih
            | salah satu konstantanya justru akan berbohong: ia menyatakan
            | kaitan ke satu modul yang sebenarnya tidak ada.
            */
            $tahap = $document->approvals()
                ->where('status', 'WAITING')
                ->min('step_order');

            if ($tahap !== null) {
                $baris = $document->approvals()
                    ->where('step_order', (int) $tahap)
                    ->where('status', 'WAITING')
                    ->orderBy('id')
                    ->get();

                $penerima = $baris
                    ->flatMap(static fn ($a): Collection => collect($resolverApprover($a)))
                    ->filter(static fn ($u): bool => $u instanceof User)
                    ->unique('id')
                    ->values();

                foreach ($penerima as $satu) {
                    $this->kirim($satu, $document, 'resubmitted', $params);
                }
            }
        }

        /* Yang mengembalikannya. */
        $pengembali = $revisi->returned_by
            ? User::query()->whereKey((int) $revisi->returned_by)->first()
            : null;

        if ($pengembali) {
            $this->kirim($pengembali, $document, 'resubmitted_returner', $params);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Bagian dalam
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $params
     */
    private function kirim(
        User $penerima,
        Model $document,
        string $peristiwa,
        array $params,
    ): void {
        $modul = $this->modul($document);

        if (!$modul) {
            return;
        }

        $kunciJudul = "notification_messages.fund_request_revision.{$peristiwa}.title";
        $kunciPesan = "notification_messages.fund_request_revision.{$peristiwa}.message";

        /*
        | Nama modulnya ikut sebagai parameter supaya satu kalimat cukup
        | untuk empat modul, dan supaya pemberitahuannya menyebut dokumen apa
        | yang dimaksud tanpa pembacanya harus menebak dari nomornya.
        */
        $params['module'] = $modul['label'];

        try {
            Notification::create([
                'user_id' => $penerima->id,
                'type' => $modul['code'] . '_revision',
                'title' => __($kunciJudul, $params),
                'title_key' => $kunciJudul,
                'message' => __($kunciPesan, $params),
                'message_key' => $kunciPesan,
                'message_params' => $params,
                'module' => $modul['code'],
                'reference_type' => $document->getMorphClass(),
                'reference_id' => $document->getKey(),
                'reference_public_id' => $document->encrypted_id ?? null,
                'url' => $modul['url'],
            ]);
        } catch (\Throwable $e) {
            /*
            | Kabar yang gagal tidak boleh menggagalkan tindakannya.
            |
            | Dokumennya sudah berpindah tangan saat ini dipanggil; melempar
            | dari sini hanya akan membuat pemanggilnya mengira pengembalian
            | atau pengajuannya batal, padahal sudah terjadi.
            */
            Log::error('[Revisi] Notifikasi gagal dibuat', [
                'document' => $document->getMorphClass(),
                'document_id' => $document->getKey(),
                'peristiwa' => $peristiwa,
                'user_id' => $penerima->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function pemohon(Model $document): ?User
    {
        $id = $document->submitted_by ?? $document->created_by ?? null;

        return $id ? User::query()->whereKey((int) $id)->first() : null;
    }

    /** @return array{code: string, url: string, number: string, label: string}|null */
    private function modul(Model $document): ?array
    {
        return self::MODUL[$document->getMorphClass()]
            ?? self::MODUL[get_class($document)]
            ?? null;
    }

    private function nomor(Model $document): string
    {
        $modul = $this->modul($document);

        return (string) ($modul ? ($document->{$modul['number']} ?: '-') : '-');
    }
}
