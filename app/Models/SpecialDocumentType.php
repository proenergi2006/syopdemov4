<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
|--------------------------------------------------------------------------
| Tipe Dokumen Khusus
|--------------------------------------------------------------------------
| Katalog dokumen yang jalur approval-nya berbeda dari dokumen biasa,
| misalnya "Dokumen Kapal". Dibuat sebagai data agar kebutuhan serupa
| berikutnya cukup ditambah lewat menu, tanpa perubahan kode.
|
| department_id membatasi siapa yang boleh memakai tipe ini. NULL berarti
| terbuka untuk semua department.
|--------------------------------------------------------------------------
*/
class SpecialDocumentType extends Model
{
    use HasFactory;

    protected $table = 'special_document_types';

    protected $fillable = [
        'code',
        'name',
        'description',
        'department_id',
        'is_active',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(
            Department::class,
            'department_id',
        );
    }

    public function stepApprovers(): HasMany
    {
        return $this->hasMany(
            ApprovalFlowStepSpecialApprover::class,
            'special_document_type_id',
        );
    }

    /**
     * Apakah tipe ini boleh dipakai oleh department tertentu.
     *
     * Tipe tanpa pembatas department terbuka untuk semua.
     */
    public function isUsableByDepartment(?int $departmentId): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->department_id === null) {
            return true;
        }

        return (int) $this->department_id === (int) $departmentId;
    }
}
