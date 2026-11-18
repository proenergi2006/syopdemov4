<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    protected $table = 'menus';

    protected $fillable = [
        'parent_id',
        'menu_section_id',
        'name',
        'path',
        'route_name',
        'icon',
        'order_no',
        'permission_key',
        'show_in_sidebar',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_in_sidebar' => 'boolean',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_menus', 'menu_id', 'role_id');
    }

    /**
     * Section pengelompokan di sidebar.
     *
     * Hanya berarti bagi menu INDUK. Submenu mengikuti induknya, dan
     * menu_section_id padanya diabaikan saat navigasi disusun.
     */
    public function section()
    {
        return $this->belongsTo(MenuSection::class, 'menu_section_id');
    }
}
