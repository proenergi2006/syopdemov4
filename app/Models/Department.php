<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $table = 'departments';

    protected $fillable = [
        'kode',
        'nama',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /*
|--------------------------------------------------------------------------
| Direct Permission Department Assignments
|--------------------------------------------------------------------------
*/
    public function userPermissionDepartments(): HasMany
    {
        return $this->hasMany(
            UserPermissionDepartment::class,
            'department_id',
        );
    }

    /*
|--------------------------------------------------------------------------
| User Permissions yang memiliki akses ke department ini
|--------------------------------------------------------------------------
*/
    public function userPermissions(): BelongsToMany
    {
        return $this->belongsToMany(
            UserPermission::class,
            'user_permission_departments',
            'department_id',
            'user_permission_id',
        )->withTimestamps();
    }
}
