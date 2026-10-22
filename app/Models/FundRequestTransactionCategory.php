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
        'allows_routine',
        'allows_non_routine',
        'is_active',
    ];

    protected $casts = [
        'claimable_status' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'requires_business_trip' => 'boolean',
        'allows_routine' => 'boolean',
        'allows_non_routine' => 'boolean',
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
    |----------------------------------------------------------------------
    | Modul dokumen memakai daftar milik siapa
    |----------------------------------------------------------------------
    | Kuncinya jenis dokumen sebagaimana dikenal approval flow dan jadwal
    | pembayaran: FPU, REALISASI, CLAIM.
    |
    | Realisasi TIDAK punya daftar sendiri -- keterangan transaksinya
    | disalin dari FPU yang direalisasikan, jadi yang boleh ditunjuk pun
    | daftar FPU.
    |
    | Jenis dokumen yang tidak ada di sini (PR, PO, Perdin) memang tidak
    | mengenal keterangan transaksi sama sekali.
    |----------------------------------------------------------------------
    */
    public const DOCUMENT_TYPE_BY_MODULE = [
        'FPU' => self::DOCUMENT_TYPE_ADVANCE,
        'REALISASI' => self::DOCUMENT_TYPE_ADVANCE,
        'CLAIM' => self::DOCUMENT_TYPE_CLAIM,
    ];

    /**
     * Daftar keterangan transaksi yang boleh dipakai sebuah modul dokumen.
     *
     * null berarti modul itu tidak mengenal keterangan transaksi, atau
     * cakupannya menyeluruh sehingga tidak ada satu daftar pun yang
     * mewakilinya.
     */
    public static function documentTypeForModule(?string $modul): ?string
    {
        if ($modul === null) {
            return null;
        }

        return self::DOCUMENT_TYPE_BY_MODULE[strtoupper(trim($modul))] ?? null;
    }

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

    /*
    |--------------------------------------------------------------------------
    | Sifat pengajuan
    |--------------------------------------------------------------------------
    | Nilainya ditulis apa adanya seperti yang tersimpan di cash_advances
    | sejak awal -- 'Rutin' dan 'Non Rutin', berspasi dan berhuruf besar di
    | depan. Dinormalkan menjadi slug di sini, kolom lamanya akan berisi dua
    | bentuk untuk satu arti, dan setiap pembacanya harus mengenal dua-duanya.
    |--------------------------------------------------------------------------
    */
    public const REQUEST_TYPE_ROUTINE = 'Rutin';
    public const REQUEST_TYPE_NON_ROUTINE = 'Non Rutin';

    public const REQUEST_TYPES = [
        self::REQUEST_TYPE_ROUTINE,
        self::REQUEST_TYPE_NON_ROUTINE,
    ];

    /**
     * Menyaring menurut sifat pengajuan.
     *
     * Sifat yang tidak dikenali TIDAK menyaring apa pun, bukan mengosongkan
     * hasilnya: daftar kosong tanpa sebab yang kelihatan jauh lebih sulit
     * ditelusuri daripada daftar yang kelewat panjang.
     */
    public function scopeForRequestType($query, ?string $requestType)
    {
        $kolom = match (trim((string) $requestType)) {
            self::REQUEST_TYPE_ROUTINE => 'allows_routine',
            self::REQUEST_TYPE_NON_ROUTINE => 'allows_non_routine',
            default => null,
        };

        return $kolom === null
            ? $query
            : $query->where($kolom, true);
    }

    /**
     * Pada sifat pengajuan ini, FPU hanya boleh memilih satu keterangan.
     *
     * Aturan dokumen, bukan sifat kategori -- tetapi ditaruh di sini supaya
     * yang menanyakannya hanya punya satu tempat untuk bertanya.
     */
    public static function allowsMultiple(?string $requestType): bool
    {
        return trim((string) $requestType) !== self::REQUEST_TYPE_NON_ROUTINE;
    }

    /**
     * Label siap tampil untuk dropdown.
     */
    public function getTitleAttribute(): string
    {
        return (string) $this->name;
    }
}
