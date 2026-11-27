<?php

namespace App\Services\Bdv;

use App\Models\BankDisbursementVoucher;
use App\Models\Claim;
use App\Models\CashAdvance;
use App\Services\FundRequest\PrintAttachmentCollector;
use App\Services\FundRequest\RupiahWords;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/*
|--------------------------------------------------------------------------
| Menyusun cetakan gabungan BDV
|--------------------------------------------------------------------------
| Voucher pencairan tidak berdiri sendiri: ia selalu mencairkan satu atau
| beberapa FPU/Claim yang sudah disetujui. Yang memeriksa pembayaran hampir
| selalu perlu melihat keduanya berdampingan -- voucher yang menyatakan
| berapa yang keluar, dan dokumen yang menerangkan untuk apa.
|
| SUSUNANNYA SELALU SAMA: voucher di atas, dokumen sumbernya menyusul
| sesuai urutan pemilihannya. Berkas yang tersusun berbeda tergantung dari
| mana dicetak tidak bisa dibandingkan dan sulit diarsipkan.
|
| TIAP DOKUMEN MEMBAWA LAMPIRANNYA SENDIRI, tepat di belakangnya -- aturan
| yang sama dengan cetakan gabungan FPU+Perdin yang sudah ada. Dengan
| begitu tiap bagian tetap lengkap bila kelak dipisahkan.
|
| BDV TIDAK PUNYA LAMPIRAN SENDIRI. Bukti pengeluarannya memang milik
| dokumen sumbernya; itu pula yang dikirim ke Accurate (lihat
| BdvAttachmentUploadService). Jadi saat dokumen sumbernya ikut dicetak,
| lampirannya menempel di sana -- bukan di belakang vouchernya, yang hanya
| akan mencetak gambar yang sama dua kali.
|--------------------------------------------------------------------------
*/
class BdvPrintComposer
{
    public function __construct(
        private readonly BdvSourceRegistry $registry,
        private readonly PrintAttachmentCollector $lampiran,
    ) {
    }

    /**
     * Satu blok cetakan per dokumen sumber, siap di-include blade.
     *
     * @return array<int, array{style: string, body: string, number: string, data: array<string, mixed>}>
     */
    public function blokSumber(BankDisbursementVoucher $bdv, bool $denganLampiran): array
    {
        $blok = [];

        $sumber = $bdv->relationLoaded('sources') ? $bdv->sources : $bdv->sources()->get();

        foreach ($sumber as $satu) {
            $kode = strtoupper(trim((string) $satu->document_type));

            if (!$this->registry->has($kode)) {
                continue;
            }

            $dokumen = $this->registry->cari($kode, (int) $satu->source_id);

            if (!$dokumen) {
                /*
                | Dokumen sumbernya sudah tidak ada. Dilewati diam-diam,
                | bukan menggagalkan seluruh cetakan: vouchernya sendiri
                | tetap sah dan tetap perlu dicetak, dan nomor dokumen yang
                | hilang itu tetap tercantum di rincian voucher.
                */
                continue;
            }

            $satuBlok = match ($kode) {
                'FPU' => $this->blokFpu($dokumen, $denganLampiran),
                'CLAIM' => $this->blokClaim($dokumen, $denganLampiran),
                default => null,
            };

            if ($satuBlok !== null) {
                $blok[] = $satuBlok;
            }
        }

        return $blok;
    }

    /**
     * Lampiran SELURUH dokumen sumber, dikumpulkan di belakang vouchernya.
     *
     * Dipakai hanya bila dokumen sumbernya TIDAK ikut dicetak. Kalau ikut,
     * lampirannya menempel pada masing-masing dokumen -- lihat keterangan
     * kelas ini.
     *
     * @return array{pages: array<int, mixed>, others: array<int, mixed>}
     */
    public function lampiranSeluruhSumber(BankDisbursementVoucher $bdv): array
    {
        $terkumpul = new Collection();

        $sumber = $bdv->relationLoaded('sources') ? $bdv->sources : $bdv->sources()->get();

        foreach ($sumber as $satu) {
            $kode = strtoupper(trim((string) $satu->document_type));

            if (!$this->registry->has($kode)) {
                continue;
            }

            $dokumen = $this->registry->cari($kode, (int) $satu->source_id);

            if (!$dokumen) {
                continue;
            }

            $dokumen->loadMissing('attachments');

            $terkumpul = $terkumpul->merge($dokumen->attachments ?? []);
        }

        return $this->pilahLampiran($terkumpul);
    }

    /**
     * @return array{style: string, body: string, number: string, data: array<string, mixed>}
     */
    private function blokFpu(CashAdvance $fpu, bool $denganLampiran): array
    {
        $fpu->loadMissing([
            'branchData:id,nama_cabang,inisial_cabang',
            'departmentData:id,kode,nama',
            'items',
            'creator:id,name',
            'submitter:id,name',
            'approvals',
        ]);

        $total = (float) ($fpu->total_amount ?: $fpu->items->sum(fn ($i) => (float) ($i->amount ?? 0)));

        $berkas = $denganLampiran
            ? $this->lampiranDokumen($fpu)
            : ['pages' => [], 'others' => []];

        return [
            'style' => 'cash-advance-styles',
            'body' => 'cash-advance-body',
            'number' => (string) $fpu->advance_number,

            'data' => [
                'fpu' => $fpu,

                /*
                | Null, bukan rincian berkelompok ala perdin.
                |
                | Pengelompokan itu dihitung BusinessTripExpenseBreakdownService
                | dan hanya berlaku untuk FPU perjalanan dinas. Memanggilnya
                | dari sini berarti cetakan BDV ikut memikul aturan modul
                | perdin; null membuat badan cetakannya memakai daftar
                | bertanggal biasa, yang benar untuk FPU jenis apa pun.
                */
                'rincianPerdin' => null,

                'totalAmount' => $total,
                'terbilang' => RupiahWords::of($total),
                'requester' => $this->pemohon($fpu),
                'approvers' => $this->penyetuju($fpu->approvals),
                'attachmentPages' => $berkas['pages'],
                'attachmentOthers' => $berkas['others'],
            ],
        ];
    }

    /**
     * @return array{style: string, body: string, number: string, data: array<string, mixed>}
     */
    private function blokClaim(Claim $claim, bool $denganLampiran): array
    {
        $claim->loadMissing([
            'branchData:id,nama_cabang,inisial_cabang',
            'departmentData:id,kode,nama',
            'transactionCategory:id,code,name',
            'items.transactionCategory:id,name',
            'creator:id,name',
            'submitter:id,name',
            'approvals',
        ]);

        $total = (float) ($claim->total_amount ?: $claim->items->sum(fn ($i) => (float) ($i->amount ?? 0)));

        $berkas = $denganLampiran
            ? $this->lampiranDokumen($claim)
            : ['pages' => [], 'others' => []];

        return [
            'style' => 'claim-styles',
            'body' => 'claim-body',
            'number' => (string) $claim->claim_number,

            'data' => [
                'claim' => $claim,
                'totalAmount' => $total,
                'terbilang' => RupiahWords::of($total),
                'requester' => $this->pemohon($claim),
                'approvers' => $this->penyetuju($claim->approvals),
                'attachmentPages' => $berkas['pages'],
                'attachmentOthers' => $berkas['others'],
            ],
        ];
    }

    /**
     * @return array{pages: array<int, mixed>, others: array<int, mixed>}
     */
    private function lampiranDokumen(Model $dokumen): array
    {
        $dokumen->loadMissing('attachments');

        return $this->pilahLampiran(new Collection($dokumen->attachments ?? []));
    }

    /**
     * @return array{pages: array<int, mixed>, others: array<int, mixed>}
     */
    private function pilahLampiran(Collection $lampiran): array
    {
        /*
        | Label jenis lampirannya memakai kalimat yang sama dengan cetakan
        | FPU dan Claim. Disebut sekali di sini karena BDV bisa memegang
        | keduanya sekaligus, dan dua daftar label untuk satu cetakan akan
        | membuat halaman lampiran berbunyi berbeda-beda tanpa sebab.
        */
        $terpilah = $this->lampiran->collect($lampiran, [
            'REQUEST' => 'Lampiran Pengajuan',
            'RECEIPT' => 'Lampiran Penerimaan',
            'PAYMENT' => 'Bukti Pembayaran',
            'DISBURSEMENT' => 'Bukti Pencairan',
        ]);

        return [
            'pages' => $this->lampiran->paginate($terpilah['images']),
            'others' => $terpilah['others'],
        ];
    }

    /**
     * Blok tanda tangan "Dibuat Oleh".
     *
     * Satu bentuk untuk FPU dan Claim: nama kolomnya memang sama di kedua
     * tabel, dan menyalin pembangunnya dari masing-masing controller hanya
     * menambah dua salinan lagi dari sesuatu yang sudah ada dua.
     */
    private function pemohon(Model $dokumen): object
    {
        return (object) [
            'name' => $dokumen->submitter?->name ?? $dokumen->creator?->name ?? '-',
            'signature_file' => $this->berkasTandaTangan($dokumen->requester_signature_path ?? null),
            'signed_at' => $dokumen->requester_signed_at ?? $dokumen->submitted_at ?? null,
        ];
    }

    /**
     * Blok tanda tangan "Disetujui Oleh".
     *
     * Hanya baris yang benar-benar APPROVED. Baris SKIPPED pada mode ANY
     * tidak pernah ditandatangani siapa pun, dan mencetaknya sebagai kolom
     * kosong terbaca seperti persetujuan yang belum diberikan.
     */
    private function penyetuju(?EloquentCollection $approvals): Collection
    {
        return (new Collection($approvals ?? []))
            ->filter(
                fn ($baris): bool => strtoupper(trim((string) $baris->status)) === 'APPROVED',
            )
            ->sortBy(
                fn ($baris): string => sprintf(
                    '%010d-%010d',
                    (int) $baris->step_order,
                    (int) $baris->id,
                ),
            )
            ->map(fn ($baris): object => (object) [
                'label' => $baris->label ?: 'Penyetuju',
                'name' => $baris->approver_name_snapshot ?? '-',
                'signature_file' => $this->berkasTandaTangan($baris->signature_path ?? null),
                'signed_at' => $baris->approved_at ?? $baris->signed_at ?? null,
            ])
            ->values();
    }

    /**
     * Path yang bisa dibaca DomPDF.
     *
     * DomPDF membaca berkas dari disk, bukan lewat URL -- gambar yang
     * dirujuk dengan tautan http akan diam-diam tidak tergambar.
     */
    private function berkasTandaTangan(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return storage_path('app/public/' . ltrim($path, '/'));
    }
}
