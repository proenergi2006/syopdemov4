<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserPermission extends Model
{
    protected $table = 'user_permissions';

    protected $fillable = [
        'user_id',
        'permission_id',
        'scope',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'permission_id' => 'integer',
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public const SCOPE_NONE = 'NONE';
    public const SCOPE_OWN_DATA = 'OWN_DATA';
    public const SCOPE_OWN_DEPARTMENT = 'OWN_DEPARTMENT';
    public const SCOPE_OWN_CABANG = 'OWN_CABANG';
    public const SCOPE_ASSIGNED_DEPARTMENTS = 'ASSIGNED_DEPARTMENTS';
    public const SCOPE_ALL = 'ALL';

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id',
        );
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(
            Permission::class,
            'permission_id',
        );
    }

    public function departmentAssignments(): HasMany
    {
        return $this->hasMany(
            UserPermissionDepartment::class,
            'user_permission_id',
        );
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(
            Department::class,
            'user_permission_departments',
            'user_permission_id',
            'department_id',
        )->withTimestamps();
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

    public function scopeActive($query)
    {
        return $query->where(
            'is_active',
            true,
        );
    }

    public function hasScope(
        string $scope,
    ): bool {
        return strtoupper(
            trim((string) $this->scope),
        ) === strtoupper(
            trim($scope),
        );
    }
}
