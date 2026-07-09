<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAccessAssignment extends Model
{
    use HasFactory;

    protected $table = 'user_access_assignments';

    protected $fillable = [
        'user_id',
        'branch_id',
        'department_id',
        'is_primary',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'department_id' => 'integer',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Branch
    |--------------------------------------------------------------------------
    | Table existing masih bernama cabang.
    | Kalau model Cabang kamu namespace-nya beda, nanti sesuaikan bagian ini.
    |--------------------------------------------------------------------------
    */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(
            Cabang::class,
            'branch_id',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Department
    |--------------------------------------------------------------------------
    | Kalau model Department kamu namespace-nya beda, nanti sesuaikan bagian ini.
    |--------------------------------------------------------------------------
    */
    public function department(): BelongsTo
    {
        return $this->belongsTo(
            Department::class,
            'department_id',
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by',
        );
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by',
        );
    }
}
