<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Batas pengajuan FPU
|--------------------------------------------------------------------------
| Lihat migrasi 2026_09_15_090000 untuk penjelasan bentuk dan cakupannya.
|--------------------------------------------------------------------------
*/
class FundRequestLimit extends Model
{
    use HasFactory;

    protected $table = 'fund_request_limits';

    protected $fillable = [
        'name',
        'area_type',
        'department_id',
        'max_outstanding',
        'max_realization_days',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'max_outstanding' => 'integer',
        'max_realization_days' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Area yang bisa dicakup.
     *
     * Nilainya sama persis dengan yang dihasilkan
     * CashAdvance::getApprovalAreaType(), supaya pencocokannya tidak
     * memerlukan penerjemahan apa pun di tengah jalan.
     */
    public const AREA_TYPES = ['HO', 'CABANG'];

    /**
     * @return array<string, string>
     */
    public static function areaTypeOptions(): array
    {
        $label = (array) __('fund_request_limit_messages.area_types');

        return collect(self::AREA_TYPES)
            ->mapWithKeys(fn (string $kode): array => [$kode => $label[$kode] ?? $kode])
            ->all();
    }

    public static function areaTypeLabel(?string $kode): ?string
    {
        if ($kode === null) {
            return null;
        }

        return self::areaTypeOptions()[$kode] ?? $kode;
    }

    /**
     * Seberapa khusus cakupan batas ini.
     *
     * Department diberi bobot lebih besar daripada area: kebiasaan belanja
     * sebuah department cenderung lebih menentukan daripada letak kantornya.
     * Bobotnya sengaja sama dengan master jadwal pembayaran, supaya seluruh
     * aplikasi memakai satu aturan yang sama tentang "yang paling khusus
     * menang".
     */
    public function getScopeWeightAttribute(): int
    {
        return ($this->department_id !== null ? 2 : 0)
            + ($this->area_type !== null ? 1 : 0);
    }

    /** Batas umum adalah jaring pengaman dan tidak boleh dihapus terakhir. */
    public function getIsFallbackAttribute(): bool
    {
        return $this->area_type === null && $this->department_id === null;
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
