<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
| Baris approval hasil generator. Notifikasi dan email membaca dari sini,
| bukan dari approval_flow_steps -- mengikuti pola FPU.
*/
class ClaimApproval extends Model
{
    use HasFactory;

    protected $table = 'claim_approvals';

    protected $fillable = [
        'claim_id',
        'approval_flow_id',
        'approval_flow_step_id',
        'step_order',
        'label',
        'approver_type',
        'approver_id',
        'approver_name_snapshot',
        'approval_mode',
        'status',
        'signature_path',
        'signed_at',
        'approved_at',
        'rejected_at',
        'notes',
    ];

    protected $casts = [
        'claim_id' => 'integer',
        'approval_flow_id' => 'integer',
        'approval_flow_step_id' => 'integer',
        'step_order' => 'integer',
        'approver_id' => 'integer',
        'signed_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public const APPROVER_TYPE_USER = 'USER';
    public const APPROVER_TYPE_ROLE = 'ROLE';

    public const APPROVAL_MODE_ANY = 'ANY';
    public const APPROVAL_MODE_ALL = 'ALL';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_WAITING = 'WAITING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_SKIPPED = 'SKIPPED';
    public const STATUS_CANCELLED = 'CANCELLED';

    public function claim()
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }
}
