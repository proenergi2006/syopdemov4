<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleMenu extends Model
{
    protected $table = 'role_menus';

    protected $fillable = [
        'role_id',
        'menu_id',
    ];
}
