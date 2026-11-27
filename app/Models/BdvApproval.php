<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/*
|--------------------------------------------------------------------------
| Satu baris rantai approval BDV
|--------------------------------------------------------------------------
| Salinan beku dari satu approval_flow_step pada saat vouchernya diajukan.
| Notifikasi, layar, dan cetakan membaca dari sini -- BUKAN dari
| approval_flow_steps. Membaca dari flow berarti dokumen yang sedang
| berjalan ikut berubah setiap kali matriks approval disunting.
|
| Nama konstanta dan nilainya disamakan persis dengan CashAdvanceApproval
| dan ClaimApproval. Satu kata yang berbeda ejaannya -- 'WAIT' alih-alih
| 'WAITING' -- akan membuat seluruh pemeriksaan status gagal tanpa satu pun
| galat.
|--------------------------------------------------------------------------
*/
class BdvApproval extends Model
{
    use HasFactory;

    protected $table = 'bdv_approvals';

    protected $fillable = [
        'bdv_id',
        'approval_flow_id',
        'approval_flow_step_id',
        'step_order',
        'label',
        'approver_type',
        'approver_id',
        'approver_name_snapshot',
        'approval_mode',
        'status',
        'signature_path',
        'signed_at',
        'approved_at',
        'rejected_at',
        'notes',
    ];

    protected $casts = [
        'bdv_id' => 'integer',
        'approval_flow_id' => 'integer',
        'approval_flow_step_id' => 'integer',
        'step_order' => 'integer',
        'approver_id' => 'integer',
        'signed_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public const APPROVER_TYPE_USER = 'USER';
    public const APPROVER_TYPE_ROLE = 'ROLE';

    public const APPROVAL_MODE_ANY = 'ANY';
    public const APPROVAL_MODE_ALL = 'ALL';

    /** Belum giliran tahap ini. */
    public const STATUS_PENDING = 'PENDING';

    /** Sedang ditunggu -- inilah tahap yang berjalan. */
    public const STATUS_WAITING = 'WAITING';

    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    /** Tahap mode ANY yang sudah dipenuhi orang lain. */
    public const STATUS_SKIPPED = 'SKIPPED';

    /** Ikut gugur karena dokumennya ditolak di tahap lain. */
    public const STATUS_CANCELLED = 'CANCELLED';

    public function bdv(): BelongsTo
    {
        return $this->belongsTo(BankDisbursementVoucher::class, 'bdv_id');
    }
}
