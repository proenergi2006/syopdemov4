<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Satu batas setor pada sebuah jadwal pembayaran
|--------------------------------------------------------------------------
| "Berkas yang diterima sampai Rabu pukul 14:00 dibayar Senin minggu
| berikutnya" -- itu satu baris di sini.
|--------------------------------------------------------------------------
*/
class PaymentScheduleCutoff extends Model
{
    use HasFactory;

    protected $table = 'payment_schedule_cutoffs';

    protected $fillable = [
        'payment_schedule_id',
        'cutoff_day',
        'cutoff_time',
        'payment_day',
        'payment_week_offset',
    ];

    protected $casts = [
        'payment_schedule_id' => 'integer',
        'cutoff_day' => 'integer',
        'payment_day' => 'integer',
        'payment_week_offset' => 'integer',
    ];

    public function schedule()
    {
        return $this->belongsTo(PaymentSchedule::class, 'payment_schedule_id');
    }

    public function getCutoffDayNameAttribute(): string
    {
        return PaymentSchedule::namaHari($this->cutoff_day);
    }

    public function getPaymentDayNameAttribute(): string
    {
        return PaymentSchedule::namaHari($this->payment_day);
    }

    /**
     * Kalimat utuh yang bisa dibaca siapa saja.
     *
     * Dirakit dari berkas bahasa, bukan disambung dari potongan kata di sini --
     * susunan kalimatnya berbeda antar bahasa, dan menyambungnya sendiri akan
     * menghasilkan kalimat Inggris berstruktur Indonesia.
     */
    public function getDescriptionAttribute(): string
    {
        $minggu = match (true) {
            $this->payment_week_offset === 0 => __('payment_schedule_messages.week.same'),
            $this->payment_week_offset === 1 => __('payment_schedule_messages.week.next'),
            default => __('payment_schedule_messages.week.after', ['count' => $this->payment_week_offset]),
        };

        return __('payment_schedule_messages.cutoff_sentence', [
            'cutoff_day' => $this->cutoff_day_name,
            'cutoff_time' => substr((string) $this->cutoff_time, 0, 5),
            'payment_day' => $this->payment_day_name,
            'week' => $minggu,
        ]);
    }
}
