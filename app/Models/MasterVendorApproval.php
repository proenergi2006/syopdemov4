<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterVendorApproval extends Model
{
    protected $table = 'master_vendor_approvals';

    protected $fillable = [
        'vendor_id',
        'approval_flow_id',
        'approval_flow_step_id',
        'step_order',
        'approver_type',
        'approver_id',
        'status',
        'approver_name_snapshot',
        'notes',
        'approved_at',
        'rejected_at',
        'cancelled_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id');
    }

    public function approvalFlow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function approvalFlowStep()
    {
        return $this->belongsTo(ApprovalFlowStep::class, 'approval_flow_step_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
