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
        'is_unlimited',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'max_outstanding' => 'integer',
        'max_realization_days' => 'integer',
        'is_unlimited' => 'boolean',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Cakupan: dua daftar, bukan dua kolom
    |--------------------------------------------------------------------------
    | Sebuah batas boleh menyebut beberapa cabang sekaligus dan beberapa
    | department sekaligus. Daftar yang KOSONG berarti "berlaku untuk semua" --
    | bukan "tidak berlaku untuk siapa pun".
    |
    | Kolom lama area_type dan department_id masih ada di basis data sebagai
    | jejak migrasi, tetapi TIDAK ADA LAGI yang membacanya. Menambahkannya
    | kembali ke pencocokan berarti satu batas punya dua cakupan yang bisa
    | bertentangan.
    |--------------------------------------------------------------------------
    */

    public function branches()
    {
        return $this->belongsToMany(
            Cabang::class,
            'fund_request_limit_branches',
            'fund_request_limit_id',
            'cabang_id',
        )->withTimestamps();
    }

    public function departments()
    {
        return $this->belongsToMany(
            Department::class,
            'fund_request_limit_departments',
            'fund_request_limit_id',
            'department_id',
        )->withTimestamps();
    }

    /**
     * Seberapa khusus cakupan batas ini.
     *
     * Department diberi bobot lebih besar daripada cabang: kebiasaan belanja
     * sebuah department cenderung lebih menentukan daripada letak kantornya.
     *
     * Yang dihitung ADA-TIDAKNYA daftar, bukan panjangnya. Batas untuk tiga
     * cabang sama khususnya dengan batas untuk satu cabang -- keduanya sama-
     * sama menyebut cabang tertentu, dan panjang daftar bukan ukuran
     * ketelitian.
     */
    public function getScopeWeightAttribute(): int
    {
        return ($this->jumlahDepartment() > 0 ? 2 : 0)
            + ($this->jumlahCabang() > 0 ? 1 : 0);
    }

    /** Batas umum adalah jaring pengaman dan tidak boleh dihapus terakhir. */
    public function getIsFallbackAttribute(): bool
    {
        return $this->jumlahCabang() === 0 && $this->jumlahDepartment() === 0;
    }

    /*
    | Memakai hitungan yang sudah dimuat bila ada (withCount / with), supaya
    | menilai sederet batas tidak berubah menjadi sederet kueri.
    */
    private function jumlahCabang(): int
    {
        if ($this->branches_count !== null) {
            return (int) $this->branches_count;
        }

        return $this->relationLoaded('branches')
            ? $this->branches->count()
            : $this->branches()->count();
    }

    private function jumlahDepartment(): int
    {
        if ($this->departments_count !== null) {
            return (int) $this->departments_count;
        }

        return $this->relationLoaded('departments')
            ? $this->departments->count()
            : $this->departments()->count();
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
