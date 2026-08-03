<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoAttachment extends Model
{
    use HasFactory;

    protected $table = 'po_attachments';

    protected $fillable = [
        'purchase_order_id',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'filepath'
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
