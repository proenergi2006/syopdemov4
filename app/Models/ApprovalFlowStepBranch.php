<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalFlowStepBranch extends Model
{

    protected $table = 'approval_flow_step_branches';

    protected $fillable = [
        'approval_flow_step_id',
        'cabang_id',
    ];

    protected $casts = [
        'approval_flow_step_id' => 'integer',
        'cabang_id' => 'integer',
    ];

    /**
     * Approval flow step pemilik mapping cabang ini.
     */
    public function approvalFlowStep(): BelongsTo
    {
        return $this->belongsTo(
            ApprovalFlowStep::class,
            'approval_flow_step_id',
        );
    }
}
