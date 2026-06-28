<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermissionDepartment extends Model
{
    protected $table = 'user_permission_departments';

    protected $fillable = [
        'user_permission_id',
        'department_id',
    ];

    protected $casts = [
        'user_permission_id' => 'integer',
        'department_id' => 'integer',
    ];

    public function userPermission(): BelongsTo
    {
        return $this->belongsTo(
            UserPermission::class,
            'user_permission_id',
        );
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(
            Department::class,
            'department_id',
        );
    }
}
