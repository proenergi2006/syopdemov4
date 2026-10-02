<?php

namespace App\Mail;

use App\Models\BusinessTrip;
use App\Models\BusinessTripArrangement;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;

/*
|--------------------------------------------------------------------------
| Email pemesanan perjalanan
|--------------------------------------------------------------------------
| Dua kabar: sebuah pemesanan baru dicatat, atau sebuah pemesanan dibatalkan.
|
| Terpisah dari BusinessTripApprovalMail karena isinya memang lain. Email
| approval menjawab "apa yang sedang saya setujui"; email ini menjawab "apa
| yang sudah diurus untuk saya" -- nama hotel, nomor booking, tanggal masuk
| dan keluar. Menyatukan keduanya berarti satu tampilan dengan dua paruh yang
| saling tidak dipakai.
|
| ShouldQueue seperti kerabatnya: SMTP yang lambat tidak boleh menahan GA
| yang sedang mengunggah voucher.
|--------------------------------------------------------------------------
*/
class BusinessTripArrangementMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public bool $deleteWhenMissingModels = true;

    public string $tripUrl;

    public function __construct(
        public BusinessTrip $trip,
        public BusinessTripArrangement $arrangement,
        public User $recipient,

        /** created | cancelled */
        public string $mode = 'created',

        /**
         * Pemesanan yang digantikan, bila ini penggantinya.
         *
         * Dibawa supaya suratnya bisa berkata "menggantikan yang dibatalkan
         * kemarin" -- tanpa itu, pemohon menerima dua kabar yang terbaca
         * sebagai dua pemesanan yang sama-sama berlaku.
         */
        public ?BusinessTripArrangement $replaces = null,
    ) {
        $this->afterCommit();

        $this->locale($recipient->locale ?? 'id');

        $encryptedId = $this->trip->encrypted_id
            ?? Crypt::encryptString((string) $this->trip->id);

        $this->tripUrl = url(
            '/business_trip/perdin?reference=' . urlencode($encryptedId),
        );
    }

    public function envelope(): Envelope
    {
        $key = $this->mode === 'cancelled'
            ? 'mail.business_trip.arrangement.subject_cancelled'
            : 'mail.business_trip.arrangement.subject_created';

        return new Envelope(
            subject: __($key, [
                'trip_number' => $this->trip->trip_number ?: '-',
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.business_trip_arrangement',
            with: [
                'trip' => $this->trip,
                'arrangement' => $this->arrangement,
                'recipient' => $this->recipient,
                'mode' => $this->mode,
                'replaces' => $this->replaces,
                'tripUrl' => $this->tripUrl,
            ],
        );
    }

    public function attachments(): array
    {
        /*
        | Vouchernya TIDAK dilampirkan.
        |
        | Sepuluh berkas tiga megabita akan menabrak batas lampiran di banyak
        | server, dan yang gagal terkirim adalah kabarnya sendiri. Tautan ke
        | perdinnya membawa pemohon ke berkas yang selalu berversi terbaru --
        | lampiran email tidak ikut berubah saat pemesanannya dibatalkan.
        */
        return [];
    }
}
