<?php

namespace App\Exceptions;

use RuntimeException;

/*
|--------------------------------------------------------------------------
| Kegagalan satu dokumen pada tindakan massal
|--------------------------------------------------------------------------
| Dipakai untuk kegagalan yang WAJAR dan bisa dijelaskan ke pengguna --
| misalnya dokumen yang statusnya sudah berubah lebih dulu oleh orang lain.
| Berbeda dari galat tak terduga, yang tetap dicatat ke log sebagai error.
|
| Nomor dokumennya dibawa serta supaya laporan hasil bisa menyebut dokumen
| mana yang gagal, bukan sekadar "dua dokumen gagal".
|--------------------------------------------------------------------------
*/
class BulkDocumentActionException extends RuntimeException
{
    public function __construct(
        public readonly string $documentNumber,
        string $message,
    ) {
        parent::__construct($message);
    }
}
