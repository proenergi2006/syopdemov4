<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermissionRoute extends Model
{
    public const MATCH_EXACT = 'EXACT';

    public const MATCH_PREFIX = 'PREFIX';

    public const MATCH_PARAMETERIZED = 'PARAMETERIZED';

    protected $fillable = [
        'permission_id',
        'route_path',
        'match_type',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'permission_id' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Permission
    |--------------------------------------------------------------------------
    */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(
            Permission::class,
            'permission_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Match Types
    |--------------------------------------------------------------------------
    */
    public static function matchTypes(): array
    {
        return [
            self::MATCH_EXACT,
            self::MATCH_PREFIX,
            self::MATCH_PARAMETERIZED,
        ];
    }
}
