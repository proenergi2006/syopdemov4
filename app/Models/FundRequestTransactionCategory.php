<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
|--------------------------------------------------------------------------
| Master Keterangan Transaksi (modul Pengajuan Dana)
|--------------------------------------------------------------------------
| Salah satu penentu approval flow FPU, bersama area, department, dan
| nominal. Berbeda dari MasterKeteranganTransaksi yang berisi kategori pajak
| vendor -- keduanya tidak berhubungan meski namanya mirip.
|--------------------------------------------------------------------------
*/
class FundRequestTransactionCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'fund_request_transaction_categories';

    protected $fillable = [
        'code',
        'document_type',
        'name',
        'description',
        'claimable_status',
        'required_documents',
        'sort_order',
        'requires_business_trip',
        'is_active',
    ];

    protected $casts = [
        'claimable_status' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'requires_business_trip' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Modul pemilik
    |--------------------------------------------------------------------------
    | Satu baris melayani satu modul saja. FPU dan Claim punya daftar jenis
    | transaksi yang berbeda, tetapi ditampung pada tabel yang sama supaya
    | master-nya satu pintu.
    |--------------------------------------------------------------------------
    */
    public const DOCUMENT_TYPE_ADVANCE = 'ADVANCE';
    public const DOCUMENT_TYPE_CLAIM = 'CLAIM';

    public const DOCUMENT_TYPES = [
        self::DOCUMENT_TYPE_ADVANCE,
        self::DOCUMENT_TYPE_CLAIM,
    ];

    /*
    |--------------------------------------------------------------------------
    | Kode "Dapat Diklaim?"
    |--------------------------------------------------------------------------
    | Hanya berlaku pada baris Claim; baris FPU meninggalkannya kosong.
    |--------------------------------------------------------------------------
    */
    public const CLAIMABLE_YES = 1;
    public const CLAIMABLE_NO = 2;
    public const CLAIMABLE_LIMITED = 3;
    public const CLAIMABLE_BY_POLICY = 4;

    public const CLAIMABLE_STATUSES = [
        self::CLAIMABLE_YES,
        self::CLAIMABLE_NO,
        self::CLAIMABLE_LIMITED,
        self::CLAIMABLE_BY_POLICY,
    ];

    /**
     * Label siap tampil untuk kode "Dapat Diklaim?".
     */
    public static function claimableLabel(?int $status): ?string
    {
        return match ($status) {
            self::CLAIMABLE_YES => 'Ya',
            self::CLAIMABLE_NO => 'Tidak',
            self::CLAIMABLE_LIMITED => 'Ya, terbatas',
            self::CLAIMABLE_BY_POLICY => 'Sesuai kebijakan',
            default => null,
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForDocumentType($query, string $documentType)
    {
        return $query->where('document_type', strtoupper(trim($documentType)));
    }

    /**
     * Label siap tampil untuk dropdown.
     */
    public function getTitleAttribute(): string
    {
        return (string) $this->name;
    }
}
