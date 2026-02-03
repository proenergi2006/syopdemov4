<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
  protected $table = 'area';

  public $timestamps = false; // karena kita pakai created_time/lastupdate_time, bukan created_at/updated_at

  protected $fillable = [
    'nama_area',
    'wapu',
    'lampiran',
    'is_active',
    'created_time',
    'created_ip',
    'created_by',
    'lastupdate_time',
  ];

  protected $casts = [
    'wapu' => 'boolean',
    'is_active' => 'boolean',
    'created_time' => 'datetime',
    'lastupdate_time' => 'datetime',
  ];
}
