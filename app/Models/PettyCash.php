<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/*
|--------------------------------------------------------------------------
| Petty Cash
|--------------------------------------------------------------------------
| Formulir ADH untuk membayar beberapa claim kecil sekaligus dari kas kecil
| cabangnya. Isinya bukan rincian yang diketik, melainkan claim-claim yang
| sudah disetujui dan sudah diterima.
|
| DISBURSED dipisahkan dari APPROVED dengan alasan yang sama seperti pada FPU:
| persetujuan adalah izin, pencairan adalah uangnya benar-benar keluar. Dan di
| sini pemisahan itu punya akibat tambahan -- plafon baru terpotong saat
| dicairkan, bukan saat disetujui.
|--------------------------------------------------------------------------
*/
class PettyCash extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'petty_cashes';

    protected $fillable = [
        'petty_cash_number',
        'date',
        'subject',
        'branch',
        'department_id',
        'petty_cash_ceiling_id',
        'total_amount',
        'notes',
        'status',

        'created_by',
        'updated_by',

        'submitted_by',
        'submitted_at',

        'requester_signed_by',
        'requester_signature_path',
        'requester_signed_at',

        'final_approved_by',
        'final_approved_at',

        'rejected_by',
        'rejected_at',
        'rejection_notes',

        'cancelled_by',
        'cancelled_at',
        'cancellation_notes',

        'disbursed_by',
        'disbursed_at',
        'disbursement_notes',
    ];

    protected $casts = [
        'date' => 'date',
        'department_id' => 'integer',
        'petty_cash_ceiling_id' => 'integer',
        'total_amount' => 'decimal:2',

        'submitted_at' => 'datetime',
        'requester_signed_at' => 'datetime',
        'final_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'disbursed_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_IN_PROGRESS = 'IN PROGRESS';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_DISBURSED = 'DISBURSED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /*
    |--------------------------------------------------------------------------
    | Area approval
    |--------------------------------------------------------------------------
    | Sama persis dengan Claim dan FPU, supaya satu master approval flow tetap
    | berlaku dan tidak ada modul yang memakai pengertian "HO" sendiri.
    |--------------------------------------------------------------------------
    */
    public const HO_BRANCH_ID = 1;

    public function getApprovalAreaType(): string
    {
        return (string) $this->branch === (string) self::HO_BRANCH_ID
            ? 'HO'
            : 'CABANG';
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Pasangan dengan claim, termasuk yang sudah dilepas.
     *
     * Yang dipakai menghitung total dan memotong plafon adalah yang BELUM
     * dilepas -- lihat activeClaims().
     */
    public function claimLinks()
    {
        return $this->hasMany(PettyCashClaim::class, 'petty_cash_id');
    }

    public function activeClaimLinks()
    {
        return $this->claimLinks()->whereNull('released_at');
    }

    public function claims()
    {
        return $this->belongsToMany(Claim::class, 'petty_cash_claims', 'petty_cash_id', 'claim_id')
            ->withPivot(['amount', 'released_at'])
            ->withTimestamps();
    }

    public function approvals()
    {
        return $this->hasMany(PettyCashApproval::class, 'petty_cash_id');
    }

    public function attachments()
    {
        return $this->hasMany(PettyCashAttachment::class, 'petty_cash_id');
    }

    public function ceiling()
    {
        return $this->belongsTo(PettyCashCeiling::class, 'petty_cash_ceiling_id');
    }

    public function branchData()
    {
        return $this->belongsTo(Cabang::class, 'branch', 'id');
    }

    public function departmentData()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function finalApprover()
    {
        return $this->belongsTo(User::class, 'final_approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function disburser()
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function getEncryptedIdAttribute()
    {
        return Crypt::encryptString($this->id);
    }
}
