<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\HasRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequest extends Model
{
    use HasFactory, HasRevisions, SoftDeletes;

    protected $table = 'purchase_requests';

    protected $dates = ['deleted_at'];

    protected $appends = [
        'encrypted_id',
        'full_lampiran_url',
    ];

    protected $fillable = [
        'nomor_pr',
        'tanggal_pr',
        'cabang',
        'id_department',
        'kategori',
        'pr_type',
        'recommended_vendor_id',
        'notes',
        'total_amount',

        'status',
        'revision_count',
        'status_po',

        'submitted_at',
        'submitted_by',

        'final_approved_at',
        'final_approved_by',

        'rejected_at',
        'rejected_by',
        'rejected_notes',

        'cancelled_by',
        'cancelled_at',
        'cancel_notes',

        'created_by',
        'updated_by',

        'requester_signed_by',
        'requester_signature_path',
        'requester_signed_at',

        'requester_role_id',
        'requester_role_name_snapshot',

        'status_pkp',
        'jenis_pembayaran',
        'top',
        'dpp',
        'ppn',

        'special_document_type_id',
    ];

    protected $casts = [
        'revision_count' => 'integer',
        'tanggal_pr' => 'date',
        'submitted_at' => 'datetime',
        'requester_signed_at' => 'datetime',
        'requester_role_id' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    public const HO_CABANG_ID = 1;

    public function getApprovalAreaType(): string
    {
        return (string) $this->cabang === (string) self::HO_CABANG_ID
            ? 'HO'
            : 'CABANG';
    }

    public function requesterRole(): BelongsTo
    {
        return $this->belongsTo(
            Role::class,
            'requester_role_id',
        );
    }

    protected static function booted()
    {
        static::deleting(function ($pr) {

            // Soft delete / force delete items
            if ($pr->isForceDeleting()) {

                $pr->items()->forceDelete();
                $pr->attachments()->forceDelete();
                $pr->approvalHistories()->forceDelete();
            } else {

                foreach ($pr->items as $item) {
                    $item->delete();
                }

                foreach ($pr->attachments as $attachment) {
                    $attachment->delete();
                }

                foreach ($pr->approvalHistories as $history) {
                    $history->delete();
                }
            }
        });
    }

    /**
     * Approval Histories
     */
    public function approvalHistories()
    {
        return $this->hasMany(
            PurchaseRequestHistoryApproval::class,
            'purchase_request_id'
        )->orderBy('level');
    }

    /**
     * PR Items
     */
    public function items()
    {
        return $this->hasMany(
            PurchaseRequestItem::class,
            'purchase_request_id',
            'id'
        );
    }

    /**
     * Attachments
     */
    public function attachments()
    {
        return $this->hasMany(
            PrAttachment::class,
            'purchase_request_id',
            'id'
        );
    }

    /**
     * Recommended Vendor
     */
    public function recommendedVendor()
    {
        return $this->belongsTo(
            MasterVendor::class,
            'recommended_vendor_id',
            'id'
        );
    }

    /**
     * Tipe Dokumen Khusus.
     *
     * NULL berarti dokumen biasa dan approval berjalan seperti biasa.
     */
    public function specialDocumentType()
    {
        return $this->belongsTo(
            SpecialDocumentType::class,
            'special_document_type_id',
            'id'
        );
    }

    /**
     * Purchase Orders
     */
    public function purchaseOrders()
    {
        return $this->belongsToMany(
            PurchaseOrder::class,
            'po_pr',
            'purchase_request_id',
            'purchase_order_id'
        )->withTimestamps();
    }

    /**
     * Cabang
     */
    public function cabangData()
    {
        return $this->belongsTo(
            Cabang::class,
            'cabang',
            'id'
        );
    }

    /**
     * Department
     */
    public function departmentData()
    {
        return $this->belongsTo(
            Department::class,
            'id_department',
            'id'
        );
    }

    /**
     * Encrypted ID
     */
    public function getEncryptedIdAttribute()
    {
        return Crypt::encryptString($this->id);
    }

    /**
     * Full Lampiran URL
     */
    public function getFullLampiranUrlAttribute()
    {
        if (!$this->path_lampiran) {
            return null;
        }

        return asset('storage/' . $this->path_lampiran);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
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

    public function approvals()
    {
        return $this->hasMany(PurchaseRequestApproval::class, 'purchase_request_id')
            ->orderBy('step_order')
            ->orderBy('id');
    }

    public function waitingApprovals()
    {
        return $this->hasMany(PurchaseRequestApproval::class, 'purchase_request_id')
            ->where('status', PurchaseRequestApproval::STATUS_WAITING)
            ->orderBy('step_order')
            ->orderBy('id');
    }

    public function pendingApprovals()
    {
        return $this->hasMany(PurchaseRequestApproval::class, 'purchase_request_id')
            ->where('status', PurchaseRequestApproval::STATUS_PENDING)
            ->orderBy('step_order')
            ->orderBy('id');
    }

    public function unfinishedApprovals(): HasMany
    {
        return $this->hasMany(
            PurchaseRequestApproval::class,
            'purchase_request_id',
        )
            ->whereIn('status', [
                PurchaseRequestApproval::STATUS_WAITING,
                PurchaseRequestApproval::STATUS_PENDING,
            ])
            ->orderBy('step_order')
            ->orderBy('id');
    }

    /**
     * Status Constants
     */
    const STATUS_DRAFT = 'DRAFT';
    const STATUS_IN_PROGRESS = 'IN PROGRESS';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_CANCELLED = 'CANCELLED';

    /*
    | Dikembalikan approver ke pemohon untuk diperbaiki.
    |
    | Statusnya HIDUP, dan justru di situ ia berbeda dari REJECTED yang sudah
    | selesai: dokumennya masih berjalan dan masih akan menjadi PO, hanya
    | sedang ada di tangan pemohonnya.
    |
    | Nomornya sengaja TIDAK dikembalikan ke DRAFT. Dokumen ini sudah pernah
    | beredar; approver yang pernah membukanya harus masih bisa menemukannya
    | dengan nomor yang sama.
    */
    const STATUS_NEED_REVISION = 'NEED REVISION';

    /**
     * Yang ikut dipotret saat dokumennya dikembalikan.
     *
     * Rincian dan lampirannya -- keduanya diisi pemohon, dan keduanya bisa
     * menjadi sebab dokumennya dikembalikan.
     *
     * @return array<int, string>
     */
    public function revisionSnapshotRelations(): array
    {
        return ['items', 'attachments'];
    }

    /**
     * Pengenal baris anak pada potret revisi.
     *
     * Rincian PR DITULIS ULANG seluruhnya setiap kali dokumennya disimpan --
     * barisnya dihapus lalu dibuat kembali -- sehingga id-nya berganti pada
     * setiap penyimpanan. Tidak ada pula satu kolom pun yang tetap: nama
     * item boleh diubah, dan tidak ada kolom urutan.
     *
     * Karena itu barisnya dipasangkan menurut URUTANNYA. Itu bukan pengenal
     * yang bisa dipercaya -- menghapus baris pertama menggeser seluruh
     * sisanya -- tetapi tetap lebih menjelaskan daripada menganggap seluruh
     * rinciannya baru setiap kali satu angka dibetulkan.
     *
     * Lampirannya lain: nama berkasnya dibangkitkan sistem dan tidak pernah
     * berubah, jadi itulah yang mengenalinya.
     */
    public function revisionSnapshotRowKey(string $relasi): ?string
    {
        return match ($relasi) {
            'items' => null,
            'attachments' => 'filename',
            default => $this->getKeyName(),
        };
    }

    /**
     * Kolom turunan yang tidak ikut dipotret.
     *
     * Ketiganya berubah karena PO dibuat dari PR ini, bukan karena pemohon
     * mengetik apa pun: status_po menyatakan sudah dipesan atau belum,
     * qty_po berapa yang sudah dipesan, qty_outstanding sisanya.
     *
     * Kalau ikut terekam, daftar perubahan revisi akan menyebut "Qty PO:
     * 0 -> 5" dan menyalahkan pemohon atas pemesanan yang justru dilakukan
     * bagian pembelian.
     *
     * @return array<int, string>
     */
    public function revisionSnapshotSkip(): array
    {
        return ['status_po', 'qty_po', 'qty_outstanding'];
    }

    // Status PO
    const STATUS_PO_OPEN = 'OPEN';
    const STATUS_PO_PARTIAL = 'PARTIAL';
    const STATUS_PO_COMPLETED = 'COMPLETED';
}
