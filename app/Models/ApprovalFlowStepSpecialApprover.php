<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/*
|--------------------------------------------------------------------------
| Approver Pengganti per Step untuk Tipe Dokumen Khusus
|--------------------------------------------------------------------------
| Contoh: step "GM Procurement" pada flow PR/PO Procurement.
| - Dokumen biasa  -> approver normal step tersebut (Donna)
| - Dokumen Kapal  -> approver pengganti dari baris ini (Vica)
|
| Label step tidak berubah, sehingga rantai approval tetap terbaca sama.
|--------------------------------------------------------------------------
*/
class ApprovalFlowStepSpecialApprover extends Model
{
    use HasFactory;

    protected $table = 'approval_flow_step_special_approvers';

    protected $fillable = [
        'approval_flow_step_id',
        'special_document_type_id',
        'approver_type',
        'approver_id',
    ];

    protected $casts = [
        'approval_flow_step_id' => 'integer',
        'special_document_type_id' => 'integer',
        'approver_id' => 'integer',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(
            ApprovalFlowStep::class,
            'approval_flow_step_id',
        );
    }

    public function specialDocumentType(): BelongsTo
    {
        return $this->belongsTo(
            SpecialDocumentType::class,
            'special_document_type_id',
        );
    }

    /*
    | Dua relasi di bawah memakai kolom yang sama (approver_id). Mana yang
    | dipakai ditentukan approver_type -- lihat resolveApproverName().
    */
    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'approver_id');
    }

    /**
     * Nama approver pengganti untuk ditampilkan.
     */
    public function resolveApproverName(): ?string
    {
        $type = strtoupper(trim((string) $this->approver_type));

        if ($type === ApprovalFlowStep::APPROVER_TYPE_USER) {
            return $this->approverUser?->name;
        }

        if ($type === ApprovalFlowStep::APPROVER_TYPE_ROLE) {
            return $this->approverRole?->nama
                ?? $this->approverRole?->kode;
        }

        return null;
    }

    public function isValid(): bool
    {
        $type = strtoupper(trim((string) $this->approver_type));

        return in_array(
            $type,
            [
                ApprovalFlowStep::APPROVER_TYPE_USER,
                ApprovalFlowStep::APPROVER_TYPE_ROLE,
            ],
            true,
        ) && (int) $this->approver_id > 0;
    }
}
