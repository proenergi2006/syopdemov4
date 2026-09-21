<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\BulkDocumentActionException;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Tindakan atas banyak dokumen sekaligus
|--------------------------------------------------------------------------
| Dipakai bersama oleh FPU, Realisasi, dan Claim untuk tindakan yang memang
| lumrah dikerjakan berombongan: menerima, mencairkan, menyelesaikan selisih,
| dan membayar.
|
| Tiap dokumen diproses SENDIRI-SENDIRI, bukan dalam satu transaksi besar.
| Alasannya: kegagalan satu dokumen tidak boleh membatalkan yang lain. Kalau
| delapan dari sepuluh berhasil, yang perlu diulang cukup dua sisanya --
| memaksa seluruhnya gagal hanya membuat pekerjaan berlipat tanpa manfaat.
|
| Konsekuensinya hasilnya bisa sebagian. Karena itu laporan yang dikembalikan
| selalu menyebut dokumen mana yang gagal dan kenapa, bukan sekadar jumlahnya.
|--------------------------------------------------------------------------
*/
trait ProcessesBulkDocumentAction
{
    /** Batas jumlah dokumen dalam satu permintaan. */
    private const BULK_MAX_DOCUMENTS = 100;

    /**
     * Menjalankan satu tindakan atas sekumpulan dokumen.
     *
     * @param  array<int, string>  $publicIds
     * @param  callable(string): string  $handler
     *         Memproses satu dokumen dan mengembalikan nomornya. Lemparkan
     *         BulkDocumentActionException untuk kegagalan yang wajar.
     * @return array{succeeded: array<int, string>, failed: array<int, array{number: string, reason: string}>}
     */
    protected function processBulkDocuments(
        array $publicIds,
        string $logTag,
        callable $handler,
    ): array {
        $berhasil = [];
        $gagal = [];

        /*
        | Id kembar disaring lebih dulu. Tanpa ini, satu dokumen yang terkirim
        | dua kali akan diproses dua kali -- yang kedua pasti gagal karena
        | statusnya sudah berubah, dan laporannya jadi membingungkan.
        */
        foreach (array_values(array_unique($publicIds)) as $publicId) {
            try {
                $berhasil[] = $handler($publicId);
            } catch (BulkDocumentActionException $e) {
                /*
                | Kegagalan yang bisa dijelaskan -- umumnya karena statusnya
                | sudah berubah lebih dulu oleh orang lain. Tidak perlu dicatat
                | sebagai error; cukup dilaporkan ke pengguna.
                */
                $gagal[] = [
                    'number' => $e->documentNumber,
                    'reason' => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                Log::error("{$logTag} gagal", [
                    'public_id' => $publicId,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                $gagal[] = [
                    'number' => '-',
                    'reason' => __('bulk_action.unexpected_error'),
                ];
            }
        }

        return [
            'succeeded' => $berhasil,
            'failed' => $gagal,
        ];
    }

    /**
     * Aturan validasi daftar dokumen yang dikirim.
     *
     * @return array<string, array<int, string>>
     */
    protected function bulkDocumentRules(): array
    {
        return [
            'public_ids' => ['required', 'array', 'min:1', 'max:' . self::BULK_MAX_DOCUMENTS],
            'public_ids.*' => ['required', 'string', 'max:512'],
        ];
    }

    /**
     * Menyusun pesan ringkas dari hasil pemrosesan.
     *
     * @param  array{succeeded: array<int, string>, failed: array<int, array{number: string, reason: string}>}  $hasil
     */
    protected function bulkResultMessage(array $hasil): string
    {
        $jumlahBerhasil = count($hasil['succeeded']);
        $jumlahGagal = count($hasil['failed']);

        if ($jumlahGagal === 0) {
            return __('bulk_action.all_succeeded', ['count' => $jumlahBerhasil]);
        }

        if ($jumlahBerhasil === 0) {
            return __('bulk_action.all_failed', ['count' => $jumlahGagal]);
        }

        return __('bulk_action.partially_succeeded', [
            'succeeded' => $jumlahBerhasil,
            'failed' => $jumlahGagal,
        ]);
    }
}
