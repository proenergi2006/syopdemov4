<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/*
|--------------------------------------------------------------------------
| Peringatan kesehatan antrean
|--------------------------------------------------------------------------
| SENGAJA TIDAK memakai ShouldQueue.
|
| Seluruh email lain di aplikasi ini diantrekan supaya tidak menahan response.
| Email ini justru sebaliknya: isinya adalah laporan bahwa antreannya sedang
| bermasalah. Kalau ikut diantrekan, laporannya akan tertahan oleh persoalan
| yang sedang dilaporkannya sendiri -- dan tidak pernah sampai.
|
| Karena dikirim langsung, pengirimannya bisa gagal di depan mata pemanggil.
| Itu justru yang diinginkan: perintah pemanggilnya menangkap kegagalan itu
| dan mencatatnya ke log, sehingga jejaknya tetap ada.
|--------------------------------------------------------------------------
*/
class QueueHealthAlertMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $snapshot  Hasil QueueHealthService::snapshot()
     */
    public function __construct(
        public array $snapshot,
    ) {}

    public function envelope(): Envelope
    {
        $status = (string) ($this->snapshot['status'] ?? '-');
        $aplikasi = (string) config('app.name', 'Aplikasi');

        return new Envelope(
            subject: "[{$status}] Antrean email {$aplikasi} perlu diperiksa",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.queue_health_alert',
            with: [
                'snapshot' => $this->snapshot,
                'url' => url('/monitoring/queue-health'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
