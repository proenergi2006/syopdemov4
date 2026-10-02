<?php

namespace App\Services\FundRequest;

use Illuminate\Database\Eloquent\Model;

/*
|--------------------------------------------------------------------------
| Menarik kembali penerimaan berkas
|--------------------------------------------------------------------------
| Dipakai FPU, Realisasi, dan Claim. Ketiganya menyimpan penerimaannya pada
| kolom yang sama persis dan membatalkannya dengan cara yang sama, jadi
| aturannya tinggal di satu tempat.
|
| KENAPA AKSI INI ADA.
|
| Sebelumnya dokumen yang terlanjur diterima tidak punya jalan mundur: batal
| sudah tertutup, dan satu-satunya pintu keluar adalah mencairkan uang yang
| justru tidak jadi dipakai. Aksi ini mengembalikannya ke Approved, dan dari
| sana barulah ia bisa dibatalkan seperti dokumen lain.
|
| YANG IKUT TERHAPUS.
|
| Tanggal pembayarannya. Ia dibekukan saat berkasnya diterima dan sudah
| terlanjur dikirim ke pemohon lewat email; menariknya kembali berarti janji
| itu batal. Dibiarkan menempel, dokumennya akan diterima ulang di pekan lain
| sambil membawa tanggal dari rombongan pembayaran yang sudah lewat.
|
| YANG TIDAK DISENTUH.
|
| Nominal hasil revisi Finance. Pembetulan angka itu temuan tentang notanya,
| bukan tentang penerimaannya -- nota yang ternyata 700 ribu tetap 700 ribu
| walau berkasnya ditarik dari antrean. Angka pemohon pun tetap tersimpan di
| original_amount, jadi jejaknya tidak hilang ke mana-mana.
|
| Berkas lampiran penerimaan juga tetap tinggal: ia bukti bahwa penerimaannya
| memang pernah terjadi.
|--------------------------------------------------------------------------
*/
class ReceiptReversalService
{
    /**
     * Mengembalikan dokumen yang sudah diterima ke status Approved.
     *
     * Dokumennya ikut tersimpan di sini; pemanggilnya hanya perlu memastikan
     * semuanya berada di dalam satu transaksi.
     *
     * @param  string  $approvedStatus  Status tujuan, milik modelnya masing-masing
     * @param  string  $receivedStatus  Satu-satunya status asal yang sah
     * @return array{
     *     ok: bool,
     *     message_key: string|null,
     *     status: int,
     *     scheduled_date: string|null,
     * }
     */
    public function revert(
        Model $document,
        string $approvedStatus,
        string $receivedStatus,
        mixed $user,
        string $permission,
        ?string $notes,
    ): array {
        $tolak = static fn (string $kunci, int $kode): array => [
            'ok' => false,
            'message_key' => $kunci,
            'status' => $kode,
            'scheduled_date' => null,
        ];

        if (!$user || !$user->hasPermission($permission)) {
            return $tolak('fund_request_messages.receipt_reversal.forbidden', 403);
        }

        /*
        | Hanya dari status Diterima.
        |
        | Pemeriksaan satu baris ini sekaligus menutup yang sudah dicairkan,
        | dibayarkan, dan diselesaikan -- ketiganya memindahkan statusnya
        | keluar dari Diterima. Menyebut mereka satu per satu hanya menambah
        | daftar yang harus diingat orang saat kelak ada status baru.
        */
        if (strtoupper((string) $document->status) !== $receivedStatus) {
            return $tolak('fund_request_messages.receipt_reversal.only_received', 422);
        }

        if (trim((string) $notes) === '') {
            return $tolak('fund_request_messages.receipt_reversal.notes_required', 422);
        }

        /* Dikabarkan ke pemohon, jadi dibaca sebelum dihapus. */
        $jadwalLama = $document->scheduled_payment_date
            ? \Carbon\Carbon::parse($document->scheduled_payment_date)->format('d/m/Y')
            : null;

        $document->status = $approvedStatus;

        $document->received_by = null;
        $document->received_at = null;
        $document->receipt_notes = null;

        /* Janjinya batal bersama penerimaannya -- lihat keterangan di atas. */
        $document->scheduled_payment_date = null;

        $document->receipt_reverted_by = $user->id;
        $document->receipt_reverted_at = now();
        $document->receipt_reversal_notes = trim((string) $notes);

        $document->save();

        return [
            'ok' => true,
            'message_key' => null,
            'status' => 200,
            'scheduled_date' => $jadwalLama,
        ];
    }
}
