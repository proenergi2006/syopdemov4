<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/*
|--------------------------------------------------------------------------
| Claim
|--------------------------------------------------------------------------
| Kebalikan FPU: uangnya sudah lebih dulu dikeluarkan pemohon, lalu diminta
| penggantiannya. Karena itu tidak ada pencairan di muka dan tidak ada dokumen
| realisasi -- bukti pengeluarannya sudah menempel sejak dokumen dibuat.
|
| PAID dipisahkan dari APPROVED dengan alasan yang sama seperti DISBURSED pada
| FPU: approval hanya menyatakan persetujuan, sedangkan PAID menyatakan uang
| penggantinya benar-benar sudah diterima pemohon.
|--------------------------------------------------------------------------
*/
class Claim extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'claims';

    protected $fillable = [
        'claim_number',
        'date',
        'subject',

        /*
        | Ikut menentukan approval flow, bersama area, department, dan nominal.
        */
        'transaction_category_id',

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
        'paid_by',
        'paid_at',
        'payment_notes',
    ];

    protected $casts = [
        'date' => 'date',
        'department_id' => 'integer',
        'transaction_category_id' => 'integer',
        'total_amount' => 'decimal:2',

        'submitted_at' => 'datetime',
        'requester_signed_at' => 'datetime',
        'final_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'received_at' => 'datetime',
        'scheduled_payment_date' => 'date',
        'paid_at' => 'datetime',
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
    | Dokumen sudah disetujui penuh dan berkasnya diterima, tetapi belum
    | tuntas. Tahap antara ini yang membedakan "berkasnya sudah di tangan"
    | dari "uangnya sudah dibayarkan".
    */
    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_PAID = 'PAID';

    /*
    |--------------------------------------------------------------------------
    | Area approval
    |--------------------------------------------------------------------------
    | Cabang ID 1 adalah kantor pusat, selebihnya dianggap cabang -- sama
    | dengan aturan pada FPU sehingga satu master approval flow tetap berlaku.
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
        return $this->hasMany(ClaimItem::class, 'claim_id');
    }

    public function attachments()
    {
        return $this->hasMany(ClaimAttachment::class, 'claim_id');
    }

    public function approvals()
    {
        return $this->hasMany(ClaimApproval::class, 'claim_id');
    }

    /**
     * Cabang disimpan sebagai teks -- mengikuti cash_advances.
     */
    public function branchData()
    {
        return $this->belongsTo(Cabang::class, 'branch', 'id');
    }

    public function departmentData()
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function transactionCategory()
    {
        return $this->belongsTo(
            FundRequestTransactionCategory::class,
            'transaction_category_id',
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

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
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
