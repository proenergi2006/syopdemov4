<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
|--------------------------------------------------------------------------
| Section pengelompokan menu sidebar
|--------------------------------------------------------------------------
| Judul kelompok yang digambar di antara menu-menu teratas sidebar --
| Finance, Procurement, dan seterusnya. Seluruhnya data: namanya, ikonnya,
| urutannya, dan isinya diatur Super Admin lewat layar Menu Management.
|
| Section TIDAK dipermisikan. Ia cuma label, bukan sesuatu yang bisa dibuka.
| Yang menentukan terlihat atau tidak adalah menu di bawahnya: section yang
| seluruh menunya tak terlihat bagi sebuah role ikut hilang dari sidebarnya
| -- lihat MenuController::navigation().
|--------------------------------------------------------------------------
*/
class MenuSection extends Model
{
    use SoftDeletes;

    protected $table = 'menu_sections';

    protected $fillable = [
        'name',
        'icon',
        'order_no',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order_no' => 'integer',
    ];

    public function menus()
    {
        return $this->hasMany(Menu::class, 'menu_section_id');
    }
}
