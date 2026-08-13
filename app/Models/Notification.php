<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'title_key',
        'title_params',
        'message',
        'message_key',
        'message_params',
        'module',
        'reference_type',
        'reference_id',
        'reference_public_id',
        'url',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'title_params' => 'array',
        'message_params' => 'array',
    ];
}
