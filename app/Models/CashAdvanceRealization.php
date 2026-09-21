<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/*
|--------------------------------------------------------------------------
| Realisasi FPU
|--------------------------------------------------------------------------
| Pertanggungjawaban atas uang yang sudah dicairkan lewat FPU. Hanya boleh
| dibuat dari FPU berstatus DISBURSED, dan satu FPU hanya punya satu
| realisasi yang hidup.
|
| SETTLED dipisahkan dari APPROVED: approval menyatakan angka realisasinya
| benar, penyelesaian menyatakan selisihnya sudah beres.
|--------------------------------------------------------------------------
*/
class CashAdvanceRealization extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cash_advance_realizations';

    protected $fillable = [
        'realization_number',
        'cash_advance_id',
        'date',
        'notes',

        /*
        | Disalin dari FPU induk. Ketiganya dipakai mesin approval untuk
        | memilih flow, sekaligus menjadi snapshot dokumen.
        */
        'branch',
        'department_id',
        'transaction_category_id',

        'total_advance_amount',
        'total_realization_amount',
        'difference_amount',
        'difference_type',

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
        'settled_by',
        'settled_at',
        'settlement_notes',
        'settlement_amount',
    ];

    protected $casts = [
        'date' => 'date',
        'cash_advance_id' => 'integer',
        'department_id' => 'integer',
        'transaction_category_id' => 'integer',

        'total_advance_amount' => 'decimal:2',
        'total_realization_amount' => 'decimal:2',
        'difference_amount' => 'decimal:2',
        'settlement_amount' => 'decimal:2',

        'submitted_at' => 'datetime',
        'requester_signed_at' => 'datetime',
        'final_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'received_at' => 'datetime',
        'scheduled_payment_date' => 'date',
        'settled_at' => 'datetime',
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
    | dari "selisihnya sudah diselesaikan".
    */
    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_SETTLED = 'SETTLED';

    /**
     * Status yang membuat realisasi dianggap sudah mati.
     *
     * Selama realisasinya belum berada di salah satu status ini, FPU induknya
     * masih terikat dan tidak boleh dibatalkan.
     */
    public const RELEASED_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /*
    |--------------------------------------------------------------------------
    | Jenis selisih
    |--------------------------------------------------------------------------
    | RETURN    : realisasi lebih kecil, sisa dikembalikan pemohon.
    | REIMBURSE : realisasi lebih besar, kekurangan dibayarkan perusahaan.
    |--------------------------------------------------------------------------
    */

    public const DIFFERENCE_NONE = 'NONE';
    public const DIFFERENCE_RETURN = 'RETURN';
    public const DIFFERENCE_REIMBURSE = 'REIMBURSE';

    /**
     * Menentukan jenis selisih dari nilainya.
     */
    public static function resolveDifferenceType(float $differenceAmount): string
    {
        if (abs($differenceAmount) < 0.005) {
            return self::DIFFERENCE_NONE;
        }

        return $differenceAmount > 0
            ? self::DIFFERENCE_RETURN
            : self::DIFFERENCE_REIMBURSE;
    }

    /*
    |--------------------------------------------------------------------------
    | Area approval
    |--------------------------------------------------------------------------
    | Cabang ID 1 adalah kantor pusat, selebihnya cabang -- sama dengan FPU.
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

    public function cashAdvance()
    {
        return $this->belongsTo(CashAdvance::class, 'cash_advance_id');
    }

    public function items()
    {
        return $this->hasMany(
            CashAdvanceRealizationItem::class,
            'cash_advance_realization_id',
        );
    }

    public function attachments()
    {
        return $this->hasMany(
            CashAdvanceRealizationAttachment::class,
            'cash_advance_realization_id',
        );
    }

    public function approvals()
    {
        return $this->hasMany(
            CashAdvanceRealizationApproval::class,
            'cash_advance_realization_id',
        );
    }

    /**
     * Cabang disimpan sebagai teks -- mengikuti FPU dan purchase_requests.
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

    public function settler()
    {
        return $this->belongsTo(User::class, 'settled_by');
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
