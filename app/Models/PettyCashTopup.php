<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/*
|--------------------------------------------------------------------------
| Pengisian kembali plafon petty cash
|--------------------------------------------------------------------------
| Diajukan ADH, disetujui Kacab, lalu Finance di HO. Sesudah disetujui penuh,
| buku mutasi mencatat satu baris TOPUP dan saldo kembali ke plafon.
|
| Tidak ada tahap pencairan terpisah seperti pada petty cash. Alasannya:
| di petty cash, persetujuan dan keluarnya uang dikerjakan orang yang berbeda
| pada waktu yang berbeda; di sini yang menyetujui terakhir adalah Finance
| yang juga mengirim uangnya, jadi satu tindakan.
|
| amount DIBEKUKAN saat diajukan dan tidak dihitung ulang -- lihat keterangan
| di migrasi 2026_11_04_100200 untuk alasannya dan akibatnya.
|--------------------------------------------------------------------------
*/
class PettyCashTopup extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'petty_cash_topups';

    protected $fillable = [
        'topup_number',
        'date',
        'subject',
        'branch',
        'department_id',
        'petty_cash_ceiling_id',
        'ceiling_amount',
        'balance_before',
        'amount',
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
    ];

    protected $casts = [
        'date' => 'date',
        'department_id' => 'integer',
        'petty_cash_ceiling_id' => 'integer',
        'ceiling_amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'amount' => 'decimal:2',

        'submitted_at' => 'datetime',
        'requester_signed_at' => 'datetime',
        'final_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_IN_PROGRESS = 'IN PROGRESS';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const HO_BRANCH_ID = 1;

    public function getApprovalAreaType(): string
    {
        return (string) $this->branch === (string) self::HO_BRANCH_ID
            ? 'HO'
            : 'CABANG';
    }

    public function approvals()
    {
        return $this->hasMany(PettyCashTopupApproval::class, 'petty_cash_topup_id');
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

    public function getEncryptedIdAttribute()
    {
        return Crypt::encryptString($this->id);
    }
}
