<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Departemen extends Model
{
  protected $fillable = ['kode', 'nama', 'is_active'];

  protected $casts = [
    'is_active' => 'boolean',
  ];
}
