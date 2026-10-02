<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/*
| Berkas bukti sebuah pemesanan: voucher hotel, e-ticket, invoice.
|
| Tidak dihapus walau pemesanannya dibatalkan. Pembatalan hotel kadang
| berbiaya, dan berkas inilah bukti bahwa pemesanannya memang pernah ada.
*/
class BusinessTripArrangementFile extends Model
{
    use HasFactory;

    protected $table = 'business_trip_arrangement_files';

    protected $fillable = [
        'business_trip_arrangement_id',
        'filename',
        'original_filename',
        'mime_type',
        'file_size',
        'filepath',
        'uploaded_by',
    ];

    protected $casts = [
        'business_trip_arrangement_id' => 'integer',
        'file_size' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function arrangement()
    {
        return $this->belongsTo(
            BusinessTripArrangement::class,
            'business_trip_arrangement_id',
        );
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Alamat berkasnya untuk dibuka di peramban. */
    public function getUrlAttribute(): ?string
    {
        return $this->filepath
            ? Storage::disk('public')->url($this->filepath)
            : null;
    }
}
