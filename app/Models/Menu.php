<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    protected $table = 'menus';

    protected $fillable = [
        'parent_id','name','path','route_name','icon','order_no','permission_key','is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_menus', 'menu_id', 'role_id');
    }
}
