<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Satu pemesanan untuk sebuah perdin
|--------------------------------------------------------------------------
| Hotel, tiket pesawat, transport lokal, atau apa pun yang diurus GA setelah
| perdinnya tuntas disetujui. Satu baris = satu pemesanan, dengan berkas
| buktinya bisa lebih dari satu (voucher dan invoice, atau tiket berangkat
| dan tiket pulang).
|
| Baris ini tidak pernah disunting dan tidak pernah dihapus. Pemesanan yang
| batal ditandai DIBATALKAN beserta alasannya, lalu penggantinya dicatat
| sebagai baris baru yang menunjuk ke sini lewat replaces_id.
|--------------------------------------------------------------------------
*/
class BusinessTripArrangement extends Model
{
    use HasFactory;

    protected $table = 'business_trip_arrangements';

    /*
    |--------------------------------------------------------------------------
    | Jenis pemesanan
    |--------------------------------------------------------------------------
    | Dipisah supaya terbaca sekilas mana yang sudah diurus dan mana yang
    | belum -- penginapan bisa sudah dipesan sementara tiketnya menyusul.
    */
    public const TYPE_LODGING = 'PENGINAPAN';
    public const TYPE_FLIGHT = 'TIKET';
    public const TYPE_TRANSPORT = 'TRANSPORT';
    public const TYPE_OTHER = 'LAINNYA';

    public const TYPES = [
        self::TYPE_LODGING,
        self::TYPE_FLIGHT,
        self::TYPE_TRANSPORT,
        self::TYPE_OTHER,
    ];

    public const STATUS_ACTIVE = 'AKTIF';
    public const STATUS_CANCELLED = 'DIBATALKAN';

    protected $fillable = [
        'business_trip_id',
        'type',
        'vendor_name',
        'reference_no',
        'starts_at',
        'ends_at',
        'notes',
        'status',
        'replaces_id',
        'created_by',
        'cancelled_by',
        'cancelled_at',
        'cancellation_notes',
    ];

    protected $casts = [
        'business_trip_id' => 'integer',
        'replaces_id' => 'integer',
        'created_by' => 'integer',
        'cancelled_by' => 'integer',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function businessTrip()
    {
        return $this->belongsTo(
            BusinessTrip::class,
            'business_trip_id',
        );
    }

    public function files()
    {
        return $this->hasMany(
            BusinessTripArrangementFile::class,
            'business_trip_arrangement_id',
        );
    }

    /** Yang mencatatnya -- biasanya GA. */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Pemesanan lama yang digantikan baris ini.
     *
     * Kosong untuk pemesanan pertama. Terisi bila ia pengganti dari sebuah
     * pemesanan yang dibatalkan -- dari sinilah rantai penggantiannya terbaca.
     */
    public function replaces()
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    public function replacement()
    {
        return $this->hasOne(self::class, 'replaces_id');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
