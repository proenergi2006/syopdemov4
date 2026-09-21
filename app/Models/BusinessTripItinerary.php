<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
| Satu baris rundown perdin: kapan, jam berapa, mengerjakan apa, ditemani siapa.
*/
class BusinessTripItinerary extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'business_trip_itineraries';

    /** Zona waktu yang boleh dipilih. Indonesia hanya punya tiga. */
    public const TIMEZONES = ['WIB', 'WITA', 'WIT'];

    protected $fillable = [
        'business_trip_id',
        'sort_no',
        'date',
        'time_start',
        'time_end',
        'timezone',
        'description',
        'pic',
    ];

    protected $casts = [
        'business_trip_id' => 'integer',
        'sort_no' => 'integer',
        'date' => 'date',
    ];

    public function businessTrip()
    {
        return $this->belongsTo(
            BusinessTrip::class,
            'business_trip_id',
        );
    }

    /**
     * Jamnya dirangkai kembali jadi satu kalimat: "15:00 - 19:00 WITA".
     *
     * Disimpan terpecah supaya bisa diurutkan dan divalidasi; dirangkai di sini
     * supaya yang dibaca orang tetap satu kalimat seperti di formulir kertas.
     */
    public function getTimeTextAttribute(): string
    {
        $potong = fn (?string $jam): string => $jam ? substr($jam, 0, 5) : '';

        $mulai = $potong($this->time_start);
        $selesai = $potong($this->time_end);

        if ($mulai === '') {
            return '';
        }

        $rentang = $selesai !== '' && $selesai !== $mulai
            ? "{$mulai} - {$selesai}"
            : $mulai;

        return trim($rentang . ' ' . (string) $this->timezone);
    }
}
