<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PermissionModule extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'route_prefix',
        'sort_order',
        'is_active',

        /*
        | Pendaftaran module ini sebagai jenis dokumen Approval Flow. NULL
        | berarti module tidak punya approval flow sama sekali.
        */
        'approval_document_type',
        'approval_document_label',
        'approval_uses_area_matrix',
        'approval_uses_transaction_category',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'approval_uses_area_matrix' => 'boolean',
        'approval_uses_transaction_category' => 'boolean',
    ];

    /**
     * Module yang terdaftar sebagai jenis dokumen Approval Flow.
     */
    public function scopeApprovalDocumentType($query)
    {
        return $query
            ->whereNotNull('approval_document_type')
            ->where('approval_document_type', '<>', '');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(
            Permission::class,
            'module',
            'code',
        );
    }

    public function approvalFlows(): HasMany
    {
        return $this->hasMany(
            ApprovalFlow::class,
            'permission_module_id',
        );
    }
}
