<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';

    /*
    | 'level' wajib ada di sini.
    |
    | Controller sudah memvalidasi dan meneruskannya, tetapi Eloquent
    | membuang atribut yang tidak fillable tanpa mengeluh. Akibatnya form
    | melaporkan berhasil sementara tingkat jabatannya tetap kosong -- dan
    | role tanpa tingkat tidak ikut aturan "bawahan tidak menyetujui
    | atasan", jadi diamnya kegagalan ini berakibat jauh.
    */
    protected $fillable = ['kode', 'nama', 'level', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'level' => 'integer',
    ];

    public function menus()
    {
        // PENTING: pivot table role_menus, key role_id & menu_id
        return $this->belongsToMany(Menu::class, 'role_menus', 'role_id', 'menu_id');
        // JANGAN withTimestamps kalau role_menus tidak punya created_at/updated_at
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withPivot([
                'scope',
                'is_active',
            ])
            ->withTimestamps();
    }
}
