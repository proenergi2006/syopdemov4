<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterVendor extends Model
{
    protected $table = 'master_vendor';

    protected $fillable = [
        'id_accurate',
        'kode_vendor',
        'inisial_vendor',
        'nama_vendor',
        'is_active',
        'created_time',
        'created_ip',
        'created_by',
        'lastupdate_time',
    ];
}
