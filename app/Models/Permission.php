<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Permission extends Model
{
    protected $fillable = [
        'module',
        'action',
        'code',
        'name',
        'description',
        'route_prefix',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withPivot([
                'scope',
                'is_active',
            ])
            ->withTimestamps();
    }

    public function permissionModule(): BelongsTo
    {
        return $this->belongsTo(
            PermissionModule::class,
            'module',
            'code',
        );
    }

    /*
|--------------------------------------------------------------------------
| Direct User Permission Records
|--------------------------------------------------------------------------
*/
    public function userPermissions(): HasMany
    {
        return $this->hasMany(
            UserPermission::class,
            'permission_id',
        );
    }

    /*
|--------------------------------------------------------------------------
| Users dengan Direct Permission
|--------------------------------------------------------------------------
*/
    public function directUsers(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_permissions',
            'permission_id',
            'user_id',
        )
            ->withPivot([
                'scope',
                'is_active',
                'created_by',
                'updated_by',
            ])
            ->withTimestamps();
    }

    /*
    |--------------------------------------------------------------------------
    | Protected Frontend Routes
    |--------------------------------------------------------------------------
    */
    public function permissionRoutes(): HasMany
    {
        return $this->hasMany(
            PermissionRoute::class,
            'permission_id',
        );
    }
}
