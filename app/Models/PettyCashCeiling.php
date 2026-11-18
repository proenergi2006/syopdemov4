<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/*
|--------------------------------------------------------------------------
| Plafon petty cash
|--------------------------------------------------------------------------
| Satu baris per cabang, dipegang department Finance. Keberadaan baris inilah
| yang menentukan sebuah cabang memakai petty cash atau tidak -- cabang tanpa
| plafon hidup berarti claim kecilnya kembali ke jalur Finance seperti biasa.
|
| SALDO TIDAK DISIMPAN DI SINI. Ia dijumlahkan dari buku mutasinya, supaya
| tidak ada angka yang bisa melenceng tanpa jejak. Lihat keterangan panjangnya
| di migrasi 2026_11_04_100000.
|--------------------------------------------------------------------------
*/
class PettyCashCeiling extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'petty_cash_ceilings';

    protected $fillable = [
        'branch_id',
        'department_id',
        'ceiling_amount',
        'claim_threshold',
        'warning_percentage',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'department_id' => 'integer',
        'ceiling_amount' => 'decimal:2',
        'claim_threshold' => 'decimal:2',
        'warning_percentage' => 'integer',
        'is_active' => 'boolean',
    ];

    public function ledgers()
    {
        return $this->hasMany(PettyCashLedger::class, 'petty_cash_ceiling_id');
    }

    public function branchData()
    {
        return $this->belongsTo(Cabang::class, 'branch_id', 'id');
    }

    public function departmentData()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Saldo
    |--------------------------------------------------------------------------
    */

    /**
     * Saldo kas kecil saat ini, dijumlahkan dari buku mutasi.
     *
     * Tidak di-cache dan tidak disimpan. Pemanggil yang hendak MENGUBAH saldo
     * wajib lewat PettyCashLedgerService supaya pembacaan dan pencatatannya
     * berada dalam satu kuncian -- memanggil ini lalu menulis sendiri adalah
     * cara membuat dua pencairan bersamaan sama-sama merasa saldonya cukup.
     */
    public function currentBalance(): float
    {
        return (float) $this->ledgers()->sum('amount');
    }

    /**
     * Ambang peringatan dalam rupiah.
     */
    public function warningThreshold(): float
    {
        return (float) $this->ceiling_amount * ($this->warning_percentage / 100);
    }

    public function getEncryptedIdAttribute()
    {
        return Crypt::encryptString($this->id);
    }
}
