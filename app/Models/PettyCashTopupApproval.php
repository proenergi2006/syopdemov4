<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
| Baris approval top up. Bentuknya sama persis dengan petty_cash_approvals;
| tabelnya dipisah karena dokumennya berbeda dan alurnya pun berbeda -- satu
| tabel bersama hanya akan menambah satu kolom penanda yang harus diingat di
| setiap kueri.
*/
class PettyCashTopupApproval extends Model
{
    use HasFactory;

    protected $table = 'petty_cash_topup_approvals';

    protected $fillable = [
        'petty_cash_topup_id',
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
        'petty_cash_topup_id' => 'integer',
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

    public function topup()
    {
        return $this->belongsTo(PettyCashTopup::class, 'petty_cash_topup_id');
    }
}
