<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/*
|--------------------------------------------------------------------------
| FPU (Form Pengajuan Uang) / Cash Advance
|--------------------------------------------------------------------------
| Uang diajukan di depan, lalu dipertanggungjawabkan lewat dokumen Realisasi
| yang terpisah.
|
| DISBURSED sengaja dipisahkan dari APPROVED: approval hanya menyatakan
| persetujuan, sedangkan pencairan menyatakan uang benar-benar sudah keluar.
| Realisasi hanya boleh dibuat setelah dokumen berstatus DISBURSED.
|--------------------------------------------------------------------------
*/
class CashAdvance extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cash_advances';

    protected $fillable = [
        'advance_number',
        'date',
        'subject',

        /*
        | Ikut menentukan approval flow, bersama area, department, dan nominal.
        */
        'transaction_category_id',

        /*
        | Perdin yang mendasari FPU ini. WAJIB ada di sini: tanpanya,
        | CashAdvance::create() membuangnya diam-diam dan tautannya tidak
        | pernah tersimpan -- tanpa galat, tanpa jejak.
        */
        'business_trip_id',

        /*
        | Rutin / Non Rutin. Keterangan dokumen saja, tidak dipakai untuk
        | memilih flow -- mengikuti pr_type pada Purchase Requisition.
        */
        'request_type',

        'branch',
        'department_id',
        'total_amount',
        'notes',
        'status',

        'created_by',
        'updated_by',

        'submitted_by',
        'submitted_at',

        'requester_signed_by',
        'requester_signature_path',
        'requester_signed_at',

        'final_approved_by',
        'final_approved_at',

        'rejected_by',
        'rejected_at',
        'rejection_notes',

        'cancelled_by',
        'cancelled_at',
        'cancellation_notes',

        'received_by',
        'received_at',
        'receipt_notes',
        'scheduled_payment_date',

        'disbursed_by',
        'disbursed_at',
        'disbursement_notes',
    ];

    protected $casts = [
        'date' => 'date',
        'department_id' => 'integer',
        'transaction_category_id' => 'integer',
        'business_trip_id' => 'integer',
        'total_amount' => 'decimal:2',

        'submitted_at' => 'datetime',
        'requester_signed_at' => 'datetime',
        'final_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'received_at' => 'datetime',
        'scheduled_payment_date' => 'date',
        'disbursed_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_IN_PROGRESS = 'IN PROGRESS';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    /*
    | Dokumen sudah disetujui penuh dan diterima PIC, tetapi dananya belum
    | keluar. Tahap antara ini yang membedakan "berkasnya sudah di tangan"
    | dari "uangnya sudah dibayarkan".
    */
    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_DISBURSED = 'DISBURSED';

    /*
    |--------------------------------------------------------------------------
    | Area approval
    |--------------------------------------------------------------------------
    | Cabang ID 1 adalah kantor pusat, selebihnya dianggap cabang -- sama
    | dengan aturan pada PR sehingga satu master approval flow tetap berlaku.
    |--------------------------------------------------------------------------
    */
    public const HO_BRANCH_ID = 1;

    public function getApprovalAreaType(): string
    {
        return (string) $this->branch === (string) self::HO_BRANCH_ID
            ? 'HO'
            : 'CABANG';
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function items()
    {
        return $this->hasMany(
            CashAdvanceItem::class,
            'cash_advance_id',
        );
    }

    public function attachments()
    {
        return $this->hasMany(
            CashAdvanceAttachment::class,
            'cash_advance_id',
        );
    }

    public function approvals()
    {
        return $this->hasMany(
            CashAdvanceApproval::class,
            'cash_advance_id',
        );
    }

    /**
     * Cabang disimpan sebagai teks -- mengikuti purchase_requests.
     */
    /**
     * Perdin yang mendasari FPU ini.
     *
     * Hanya terisi pada FPU berketerangan transaksi perjalanan dinas.
     */
    public function businessTrip()
    {
        return $this->belongsTo(BusinessTrip::class, 'business_trip_id');
    }

    public function branchData()
    {
        return $this->belongsTo(
            Cabang::class,
            'branch',
            'id',
        );
    }

    public function departmentData()
    {
        return $this->belongsTo(
            Department::class,
            'department_id',
            'id',
        );
    }

    public function transactionCategory()
    {
        return $this->belongsTo(
            FundRequestTransactionCategory::class,
            'transaction_category_id',
        );
    }

    /**
     * Dokumen Realisasi yang mempertanggungjawabkan FPU ini.
     *
     * Satu FPU hanya punya satu realisasi hidup -- dijaga unique index parsial
     * pada cash_advance_realizations.
     */
    public function realization()
    {
        return $this->hasOne(
            CashAdvanceRealization::class,
            'cash_advance_id',
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function finalApprover()
    {
        return $this->belongsTo(User::class, 'final_approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function disburser()
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getEncryptedIdAttribute()
    {
        return Crypt::encryptString($this->id);
    }
}
