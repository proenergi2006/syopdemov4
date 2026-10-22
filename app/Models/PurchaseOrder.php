<?php

namespace App\Models;

use App\Models\Concerns\HasRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class PurchaseOrder extends Model
{
    use HasRevisions, SoftDeletes;

    protected $fillable = [
        'nomor_po',
        'tanggal_po',
        'vendor_id',
        'cabang',
        'id_department',
        'total_nilai',
        'status',
        'revision_count',
        'notes',
        'created_by',
        'approved_by',
        'dpp',
        'ppn',
        'status_pkp',
        'jenis_pembayaran',
        'top',
        'requester_signature_path',
        'requester_signed_at',
        'requester_signed_by',
        'cancelled_by',
        'cancelled_at',
        'cancel_notes',
    ];

    protected $casts = [
        'revision_count' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    /* ===============================
     | RELATION: PO ↔ PR
     =============================== */
    public function purchaseRequests()
    {
        return $this->belongsToMany(
            PurchaseRequest::class,
            'po_pr',
            'purchase_order_id',
            'purchase_request_id'
        )->withTimestamps();
    }

    /* ===============================
     | RELATION: PO ITEMS
     =============================== */
    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id', 'id');
    }

    public function vendor()
    {
        return $this->belongsTo(MasterVendor::class, 'vendor_id');
    }

    public function approvalHistories()
    {
        return $this->hasMany(
            PurchaseOrderApprovalHistory::class,
            'purchase_order_id'
        )->orderBy('id');
    }

    public function cabangData()
    {
        return $this->belongsTo(
            Cabang::class,
            'cabang',
            'id'
        );
    }

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

    public function requesterSignedBy()
    {
        return $this->belongsTo(User::class, 'requester_signed_by', 'id');
    }

    public function approvals()
    {
        return $this->hasMany(PurchaseOrderApproval::class, 'purchase_order_id')
            ->orderBy('step_order');
    }

    public function goodsReceives()
    {
        return $this->hasMany(GoodsReceive::class);
    }

    public function attachments()
    {
        return $this->hasMany(
            PoAttachment::class,
            'purchase_order_id',
            'id'
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function requesterSigner()
    {
        return $this->belongsTo(User::class, 'requester_signed_by');
    }

    public const STATUS_RECEIVE_OPEN = 'OPEN';
    public const STATUS_RECEIVE_PARTIAL = 'PARTIAL RECEIVED';
    public const STATUS_RECEIVE_FULL = 'FULL RECEIVED';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_IN_PROGRESS = 'IN PROGRESS';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /*
    | Dikembalikan approver ke pemohon untuk diperbaiki.
    |
    | Statusnya HIDUP, berbeda dari REJECTED yang sudah selesai: dokumennya
    | masih berjalan, dan kuota item PR yang dipakainya masih tercatat --
    | sama seperti saat ia masih draft.
    |
    | Nomornya sengaja TIDAK dikembalikan ke DRAFT: approver yang pernah
    | membukanya harus masih bisa menemukannya dengan nomor yang sama, dan
    | nomor PO sudah beredar ke pemasok.
    */
    public const STATUS_NEED_REVISION = 'NEED REVISION';

    /**
     * Yang ikut dipotret saat dokumennya dikembalikan.
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
     * Sama seperti PR: rincian PO ditulis ulang seluruhnya setiap kali
     * dokumennya disimpan ("Insert Ulang Item PO"), jadi id-nya berganti dan
     * barisnya dipasangkan menurut urutannya.
     *
     * purchase_request_item_id sengaja TIDAK dipakai sebagai pengenal: satu
     * baris PR boleh dipenuhi lebih dari satu baris PO, dan pengenal yang
     * kembar membuat pasangannya ditebak sembarang.
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
     * Ketiganya berubah karena BARANGNYA DITERIMA, bukan karena pemohon
     * mengetik apa pun: status_receive, jumlah yang sudah diterima, dan
     * sisanya. Semuanya ditulis modul penerimaan barang.
     *
     * @return array<int, string>
     */
    public function revisionSnapshotSkip(): array
    {
        return ['status_receive', 'qty_received', 'qty_outstanding_receive'];
    }
}
