<?php

namespace App\Services\NonTrade\PurchaseOrder;

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderApproval;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PurchaseOrderApprovalGeneratorService
{
    public function generate(
        PurchaseOrder $purchaseOrder,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Cegah approval tergenerate dua kali
        |--------------------------------------------------------------------------
        */
        $alreadyExists = PurchaseOrderApproval::query()
            ->where(
                'purchase_order_id',
                $purchaseOrder->id,
            )
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'approval' => [
                    'Approval Purchase Order sudah pernah dibuat.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Hitung nominal PO
        |--------------------------------------------------------------------------
        */
        $totalAmount = $this->calculateTotalAmount(
            $purchaseOrder,
        );

        if ($totalAmount <= 0) {
            throw ValidationException::withMessages([
                'total_amount' => [
                    'Total nilai Purchase Order harus lebih besar dari 0.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Ambil approval flow PO secara cumulative
        |--------------------------------------------------------------------------
        |
        | Contoh:
        | - 2 juta  = Semua Nilai
        | - 30 juta = Semua Nilai + 10 sampai 50 juta
        | - 60 juta = Semua Nilai + 10 sampai 50 juta + Di atas 50 juta
        |--------------------------------------------------------------------------
        */
        $approvalFlows = $this->findCumulativeFlows(
            $purchaseOrder,
            $totalAmount,
        );

        if ($approvalFlows->isEmpty()) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    sprintf(
                        'Approval flow Purchase Order tidak ditemukan untuk nominal Rp %s.',
                        number_format(
                            $totalAmount,
                            0,
                            ',',
                            '.',
                        ),
                    ),
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate snapshot approval dengan step_order baru
        |--------------------------------------------------------------------------
        |
        | Step di master flow masing-masing boleh mulai dari 1.
        | Pada snapshot transaksi PO, step harus diurutkan ulang menjadi:
        | 1, 2, 3, dst.
        |--------------------------------------------------------------------------
        */
        $effectiveStepOrder = 0;
        $createdApprovals = 0;

        /*
        |--------------------------------------------------------------------------
        | Tipe Dokumen Khusus PO
        |--------------------------------------------------------------------------
        | Diturunkan dari PR yang ditarik ke PO ini, tidak disimpan ulang di
        | tabel PO, sehingga tidak mungkin ada data PO dan PR yang bertentangan.
        |
        | Pencampuran PR beda tipe sudah ditolak saat pembuatan PO, jadi di sini
        | cukup mengambil tipe pertama yang ditemukan.
        |--------------------------------------------------------------------------
        */
        $specialTypeId = $this->resolveSpecialDocumentTypeId($purchaseOrder);

        foreach ($approvalFlows as $approvalFlow) {
            $flowSteps = ApprovalFlowStep::query()
                ->with('specialApprovers')
                ->where(
                    'approval_flow_id',
                    $approvalFlow->id,
                )
                ->orderBy('step_order')
                ->orderBy('id')
                ->get();

            /*
            | Dokumen biasa bernilai NULL -> tidak ada step yang tersentuh,
            | sehingga flow department lain berperilaku sama persis.
            */
            if ($specialTypeId) {
                foreach ($flowSteps as $flowStep) {
                    if ($flowStep->applySpecialDocumentApprover($specialTypeId)) {
                        Log::info(
                            '[PO Approval Generator] Substitusi approver dokumen khusus',
                            [
                                'purchase_order_id' => $purchaseOrder->id,
                                'special_document_type_id' => $specialTypeId,
                                'approval_flow_step_id' => $flowStep->id,
                                'label' => $flowStep->label,
                                'approver_type' => $flowStep->approver_type,
                                'approver_id' => $flowStep->approver_id,
                            ],
                        );
                    }
                }
            }

            if ($flowSteps->isEmpty()) {
                Log::warning(
                    '[PO Approval Generator] Flow aktif tidak memiliki step',
                    [
                        'purchase_order_id' => $purchaseOrder->id,
                        'approval_flow_id' => $approvalFlow->id,
                        'approval_flow_name' => $approvalFlow->name,
                    ],
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Group per logical step pada flow asal
            |--------------------------------------------------------------------------
            |
            | Jika 1 step punya banyak approver, semua row tetap masuk
            | ke effective step yang sama.
            |--------------------------------------------------------------------------
            */
            $stepGroups = $flowSteps
                ->groupBy(
                    fn(ApprovalFlowStep $step): int => (int) $step->step_order,
                )
                ->sortKeys();

            foreach ($stepGroups as $originalStepOrder => $groupedSteps) {
                $effectiveStepOrder++;

                $approvalMode = $this->normalizeApprovalMode(
                    (string) (
                        $groupedSteps->first()?->approval_mode
                        ?: PurchaseOrderApproval::MODE_ANY
                    ),
                );

                $status = $effectiveStepOrder === 1
                    ? PurchaseOrderApproval::STATUS_WAITING
                    : PurchaseOrderApproval::STATUS_PENDING;

                /*
                |--------------------------------------------------------------------------
                | Deduplikasi approver pada logical step yang sama
                |--------------------------------------------------------------------------
                |
                | ROLE-10 dan USER-10 tetap dianggap berbeda.
                |--------------------------------------------------------------------------
                */
                $usedApproverKeys = [];

                foreach ($groupedSteps as $flowStep) {
                    $approverType = strtoupper(
                        trim((string) $flowStep->approver_type),
                    );

                    $approverId = (int) $flowStep->approver_id;

                    if (
                        !in_array(
                            $approverType,
                            [
                                PurchaseOrderApproval::APPROVER_TYPE_ROLE,
                                PurchaseOrderApproval::APPROVER_TYPE_USER,
                            ],
                            true,
                        )
                        || $approverId <= 0
                    ) {
                        Log::warning(
                            '[PO Approval Generator] Approver tidak valid',
                            [
                                'purchase_order_id' => $purchaseOrder->id,
                                'approval_flow_id' => $approvalFlow->id,
                                'approval_flow_step_id' => $flowStep->id,
                                'approver_type' => $flowStep->approver_type,
                                'approver_id' => $flowStep->approver_id,
                            ],
                        );

                        continue;
                    }

                    $approverKey = $approverType . '-' . $approverId;

                    if (isset($usedApproverKeys[$approverKey])) {
                        continue;
                    }

                    $usedApproverKeys[$approverKey] = true;

                    PurchaseOrderApproval::create([
                        'purchase_order_id' => $purchaseOrder->id,

                        /*
                        |--------------------------------------------------------------------------
                        | Simpan sumber flow asal
                        |--------------------------------------------------------------------------
                        */
                        'approval_flow_id' => $approvalFlow->id,

                        'approval_flow_step_id' => $flowStep->id,

                        /*
                        |--------------------------------------------------------------------------
                        | Step order hasil gabungan cumulative
                        |--------------------------------------------------------------------------
                        */
                        'step_order' => $effectiveStepOrder,

                        'label' => $flowStep->label,

                        'approver_type' => $approverType,

                        'approver_id' => $approverId,

                        'approver_name_snapshot'
                        => $this->resolveApproverName(
                            $flowStep,
                        ),

                        'approval_mode' => $approvalMode,

                        'status' => $status,
                    ]);

                    $createdApprovals++;
                }

                /*
                |--------------------------------------------------------------------------
                | Kalau grup step tidak menghasilkan approver valid,
                | rollback nomor step agar tidak ada step kosong.
                |--------------------------------------------------------------------------
                */
                if (empty($usedApproverKeys)) {
                    $effectiveStepOrder--;
                }
            }
        }

        if ($createdApprovals <= 0) {
            throw ValidationException::withMessages([
                'approval_flow' => [
                    'Approval flow Purchase Order ditemukan, tetapi tidak memiliki approver valid.',
                ],
            ]);
        }

        Log::info(
            '[PO Approval Generator] Approval snapshot berhasil dibuat',
            [
                'purchase_order_id' => $purchaseOrder->id,
                'nomor_po' => $purchaseOrder->nomor_po,
                'total_amount' => $totalAmount,
                'approval_flow_ids' => $approvalFlows
                    ->pluck('id')
                    ->values()
                    ->all(),
                'created_approvals' => $createdApprovals,
                'effective_step_count' => $effectiveStepOrder,
            ],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Cari approval flow PO cumulative
    |--------------------------------------------------------------------------
    */
    private function findCumulativeFlows(
        PurchaseOrder $purchaseOrder,
        float $totalAmount,
    ): EloquentCollection {
        Log::info(
            '[PO Approval Generator] Cumulative flow parameters',
            [
                'purchase_order_id' => $purchaseOrder->id,
                'nomor_po' => $purchaseOrder->nomor_po,
                'document_type' => 'PO',
                'total_amount' => $totalAmount,
            ],
        );

        /*
        |--------------------------------------------------------------------------
        | Satu flow menang
        |--------------------------------------------------------------------------
        | Flow dipilih berdasarkan rentang nominal: min_amount <= total <= max_amount.
        | HANYA SATU flow yang dipakai, dan flow tersebut harus memuat seluruh
        | step approval-nya sendiri secara lengkap.
        |
        | Sebelumnya PO memakai konsep akumulatif -- seluruh flow dengan
        | min_amount <= total ikut menumpuk. Diubah agar tiap flow berdiri
        | sendiri dan mudah dibaca, mengikuti pola yang sudah dipakai PR.
        |
        | Konsekuensinya: flow "semua nilai" tidak lagi otomatis menempel pada
        | flow nominal di atasnya. Setiap flow wajib mendefinisikan rantai
        | approval-nya secara utuh.
        |--------------------------------------------------------------------------
        */
        $flow = ApprovalFlow::query()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereRaw(
                'UPPER(TRIM(document_type)) = ?',
                ['PO'],
            )
            ->where(function ($query) use ($totalAmount) {
                $query
                    ->whereNull('min_amount')
                    ->orWhere('min_amount', 0)
                    ->orWhere(
                        'min_amount',
                        '<=',
                        $totalAmount,
                    );
            })

            /*
            | max_amount null atau 0 berarti tanpa batas atas.
            */
            ->where(function ($query) use ($totalAmount) {
                $query
                    ->whereNull('max_amount')
                    ->orWhere('max_amount', 0)
                    ->orWhere(
                        'max_amount',
                        '>=',
                        $totalAmount,
                    );
            })

            /*
            | Bila beberapa rentang saling tumpang tindih, yang paling spesifik
            | menang -- yaitu batas bawah tertinggi.
            |
            | COALESCE wajib: pada PostgreSQL, ORDER BY ... DESC menaruh NULL
            | paling depan. Tanpa ini, flow "semua nilai" (min_amount NULL)
            | selalu menang dan flow bertingkat nominal tidak pernah terpakai.
            */
            ->orderByRaw('COALESCE(min_amount, 0) DESC')
            ->orderByRaw('COALESCE(max_amount, 999999999999999999) ASC')
            ->orderByDesc('id')
            ->first();

        /*
        | Tetap dikembalikan sebagai koleksi (berisi 0 atau 1 flow) agar
        | pemanggil di atasnya tidak perlu diubah.
        */
        $flows = new EloquentCollection(
            $flow ? [$flow] : [],
        );

        Log::info(
            '[PO Approval Generator] Matching flow result',
            [
                'purchase_order_id' => $purchaseOrder->id,
                'nomor_po' => $purchaseOrder->nomor_po,
                'total_amount' => $totalAmount,
                'approval_flow_id' => $flow?->id,
                'approval_flow_name' => $flow?->name,
                'min_amount' => $flow?->min_amount,
                'max_amount' => $flow?->max_amount,
            ],
        );

        return $flows;
    }

    /*
    |--------------------------------------------------------------------------
    | Hitung total PO
    |--------------------------------------------------------------------------
    */
    private function calculateTotalAmount(
        PurchaseOrder $purchaseOrder,
    ): float {
        /*
        |--------------------------------------------------------------------------
        | Gunakan total header jika tersedia
        |--------------------------------------------------------------------------
        */
        $headerTotal = (float) (
            $purchaseOrder->total_nilai
            ?? $purchaseOrder->grand_total
            ?? $purchaseOrder->total_amount
            ?? 0
        );

        if ($headerTotal > 0) {
            return $headerTotal;
        }

        $purchaseOrder->loadMissing('items');

        /*
        |--------------------------------------------------------------------------
        | Gunakan subtotal item jika tersedia
        |--------------------------------------------------------------------------
        */
        $subtotal = (float) $purchaseOrder
            ->items
            ->sum(function ($item) {
                return (float) (
                    $item->subtotal
                    ?? $item->total
                    ?? 0
                );
            });

        if ($subtotal > 0) {
            return $subtotal;
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback qty × harga
        |--------------------------------------------------------------------------
        */
        return (float) $purchaseOrder
            ->items
            ->sum(function ($item) {
                $qty = (float) (
                    $item->qty
                    ?? $item->quantity
                    ?? 0
                );

                $price = (float) (
                    $item->harga
                    ?? $item->harga_satuan
                    ?? $item->unit_price
                    ?? 0
                );

                return $qty * $price;
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Normalisasi approval mode
    |--------------------------------------------------------------------------
    */
    private function normalizeApprovalMode(
        string $approvalMode,
    ): string {
        $approvalMode = strtoupper(
            trim($approvalMode),
        );

        if (
            !in_array(
                $approvalMode,
                [
                    PurchaseOrderApproval::MODE_ANY,
                    PurchaseOrderApproval::MODE_ALL,
                ],
                true,
            )
        ) {
            return PurchaseOrderApproval::MODE_ANY;
        }

        return $approvalMode;
    }

    /*
    |--------------------------------------------------------------------------
    | Tipe Dokumen Khusus dari PR yang tertaut
    |--------------------------------------------------------------------------
    | Mengembalikan NULL untuk PO biasa. Pencampuran PR beda tipe sudah ditolak
    | saat pembuatan PO, sehingga nilai pertama yang ditemukan mewakili
    | keseluruhan PO.
    |--------------------------------------------------------------------------
    */
    private function resolveSpecialDocumentTypeId(
        PurchaseOrder $purchaseOrder,
    ): ?int {
        $typeId = $purchaseOrder
            ->purchaseRequests()
            ->whereNotNull('special_document_type_id')
            ->value('purchase_requests.special_document_type_id');

        return $typeId ? (int) $typeId : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve nama approver untuk snapshot
    |--------------------------------------------------------------------------
    */
    private function resolveApproverName(
        ApprovalFlowStep $flowStep,
    ): ?string {
        $approverType = strtoupper(
            trim((string) $flowStep->approver_type),
        );

        if (
            $approverType
            === PurchaseOrderApproval::APPROVER_TYPE_USER
        ) {
            return User::query()
                ->whereKey($flowStep->approver_id)
                ->value('name');
        }

        if (
            $approverType
            === PurchaseOrderApproval::APPROVER_TYPE_ROLE
        ) {
            return Role::query()
                ->whereKey($flowStep->approver_id)
                ->value('nama')
                ?? Role::query()
                ->whereKey($flowStep->approver_id)
                ->value('name');
        }

        return null;
    }
}
