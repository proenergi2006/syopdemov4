<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/**
 * Bank Disbursement Voucher.
 *
 * Dokumen pencairan lewat bank. Selalu mengangkat satu dokumen sumber yang
 * sudah disetujui penuh dan berkasnya sudah diterima Finance -- untuk sekarang
 * FPU atau Claim, lihat config/bdv.php.
 *
 * Dokumen sumbernya ditunjuk oleh pasangan (document_type, source_id), bukan
 * relasi Eloquent biasa, karena jenisnya memang dirancang untuk bertambah.
 * Yang tahu cara membacanya adalah BdvSourceRegistry.
 */
class BankDisbursementVoucher extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_IN_PROGRESS = 'IN PROGRESS';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_NEED_REVISION = 'NEED REVISION';

    /** Urutan untuk pilihan penyaring di daftar. */
    public const SEMUA_STATUS = [
        self::STATUS_DRAFT,
        self::STATUS_IN_PROGRESS,
        self::STATUS_NEED_REVISION,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'bank_disbursement_vouchers';

    protected $fillable = [
        'bdv_number',
        'date',
        'document_type',
        'source_id',
        'source_number',
        'branch',
        'department_id',
        'transaction_category_id',
        'total_amount',
        'notes',
        'status',
        'created_by',
        'updated_by',
        'submitted_by',
        'submitted_at',
        'final_approved_by',
        'final_approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_notes',
        'cancelled_by',
        'cancelled_at',
        'cancellation_notes',
    ];

    protected $casts = [
        'date' => 'date',
        'source_id' => 'integer',
        'department_id' => 'integer',
        'transaction_category_id' => 'integer',
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'final_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relasi
    |--------------------------------------------------------------------------
    | Nama relasinya sengaja disamakan dengan FPU dan Claim -- branchData,
    | departmentData, creator -- supaya kode yang menampilkan ketiga dokumen
    | ini tidak perlu mengingat perbedaan nama.
    |--------------------------------------------------------------------------
    */
    public function branchData(): BelongsTo
    {
        return $this->belongsTo(Cabang::class, 'branch', 'id');
    }

    public function departmentData(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function transactionCategory(): BelongsTo
    {
        return $this->belongsTo(
            FundRequestTransactionCategory::class,
            'transaction_category_id',
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Id yang aman ditampilkan di URL
    |--------------------------------------------------------------------------
    | Sama seperti modul lain: id asli tidak pernah muncul di alamat halaman.
    |--------------------------------------------------------------------------
    */
    public function getEncryptedIdAttribute(): string
    {
        return Crypt::encryptString((string) $this->id);
    }
}
