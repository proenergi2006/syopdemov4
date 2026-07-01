<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalFlowStep extends Model
{
    protected $table = 'approval_flow_steps';

    protected $fillable = [
        'approval_flow_id',
        'step_order',
        'label',
        'approver_type',
        'approver_id',
        'approval_mode',
        'is_required',
        'approver_scope',
    ];

    protected $casts = [
        'approval_flow_id' => 'integer',
        'step_order' => 'integer',
        'approver_id' => 'integer',
        'is_required' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Approver Scope
    |--------------------------------------------------------------------------
    */

    public const APPROVER_SCOPE_GLOBAL = 'GLOBAL';

    public const APPROVER_SCOPE_SAME_BRANCH = 'SAME_BRANCH';

    public const APPROVER_SCOPE_SELECTED_BRANCHES = 'SELECTED_BRANCHES';

    /*
    |--------------------------------------------------------------------------
    | Approver Type
    |--------------------------------------------------------------------------
    */

    public const APPROVER_TYPE_USER = 'USER';

    public const APPROVER_TYPE_ROLE = 'ROLE';

    /*
    |--------------------------------------------------------------------------
    | Approval Mode
    |--------------------------------------------------------------------------
    */

    public const APPROVAL_MODE_ANY = 'ANY';

    public const APPROVAL_MODE_ALL = 'ALL';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function flow(): BelongsTo
    {
        return $this->belongsTo(
            ApprovalFlow::class,
            'approval_flow_id',
        );
    }

    public function approvalFlow(): BelongsTo
    {
        return $this->belongsTo(
            ApprovalFlow::class,
            'approval_flow_id',
        );
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(
            Role::class,
            'approver_id',
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'approver_id',
        );
    }

    public function approverRole(): BelongsTo
    {
        return $this->belongsTo(
            Role::class,
            'approver_id',
        );
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'approver_id',
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by',
        );
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by',
        );
    }

    /**
     * Daftar mapping cabang yang boleh ditangani oleh approver.
     *
     * Relasi ini digunakan ketika approver_scope adalah
     * SELECTED_BRANCHES.
     */
    public function branchMappings(): HasMany
    {
        return $this->hasMany(
            ApprovalFlowStepBranch::class,
            'approval_flow_step_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query
            ->orderBy('step_order')
            ->orderBy('id');
    }

    /*
    |--------------------------------------------------------------------------
    | Approver Type Helpers
    |--------------------------------------------------------------------------
    */

    public function isRoleApprover(): bool
    {
        return strtoupper(
            trim((string) $this->approver_type),
        ) === self::APPROVER_TYPE_ROLE;
    }

    public function isUserApprover(): bool
    {
        return strtoupper(
            trim((string) $this->approver_type),
        ) === self::APPROVER_TYPE_USER;
    }

    /*
    |--------------------------------------------------------------------------
    | Approval Mode Helpers
    |--------------------------------------------------------------------------
    */

    public function isAnyMode(): bool
    {
        return strtoupper(
            trim((string) $this->approval_mode),
        ) === self::APPROVAL_MODE_ANY;
    }

    public function isAllMode(): bool
    {
        return strtoupper(
            trim((string) $this->approval_mode),
        ) === self::APPROVAL_MODE_ALL;
    }

    /*
    |--------------------------------------------------------------------------
    | Approver Scope Helpers
    |--------------------------------------------------------------------------
    */

    public function isGlobalScope(): bool
    {
        return strtoupper(
            trim((string) $this->approver_scope),
        ) === self::APPROVER_SCOPE_GLOBAL;
    }

    public function isSameBranchScope(): bool
    {
        return strtoupper(
            trim((string) $this->approver_scope),
        ) === self::APPROVER_SCOPE_SAME_BRANCH;
    }

    public function isSelectedBranchesScope(): bool
    {

        return strtoupper(
            trim((string) $this->approver_scope),
        ) === self::APPROVER_SCOPE_SELECTED_BRANCHES;
    }
}
