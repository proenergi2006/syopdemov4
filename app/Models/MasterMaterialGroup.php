<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MasterMaterialGroup extends Model
{
    use HasFactory;

    protected $table = 'master_material_groups';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Relasi ke item Purchase Request
     */
    public function purchaseRequestItems()
    {
        return $this->hasMany(
            PurchaseRequestItem::class,
            'master_material_group_id'
        );
    }
}
