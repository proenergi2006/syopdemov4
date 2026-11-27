<?php

namespace App\Exceptions;

use App\Http\Middleware\ExplainUploadFailure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Throwable;

class Handler extends ExceptionHandler
{

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->is('api/*')) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return redirect()->guest(route('login'));
    }

    /*
    |--------------------------------------------------------------------------
    | Permintaan ke API SELALU dijawab JSON
    |--------------------------------------------------------------------------
    | Bawaannya menentukan bentuk jawaban dari header Accept. Begitu header itu
    | tidak menyebut json -- dibuang proxy, ditimpa gateway, atau permintaannya
    | diulang tanpa header aslinya -- galat validasi tidak lagi dijawab 422,
    | melainkan PENGALIHAN HALAMAN 302 ke beranda.
    |
    | Bagi layar, 302 bukan kegagalan. Browser mengikutinya, mendarat di
    | halaman yang menjawab 200, dan axios menganggap pengirimannya berhasil.
    |
    | AKIBATNYA TERUKUR PADA REALISASI FPU: lampirannya tidak sampai di server,
    | dokumennya TIDAK tersimpan, namun pengisinya tetap dipindahkan ke daftar
    | beserta pesan "berhasil dibuat" -- lalu mencari dokumen yang tidak pernah
    | ada. Penjelasan yang sudah disiapkan ExplainUploadFailure ikut hilang
    | bersama pengalihan itu, padahal justru kalimat itulah yang dibutuhkan.
    |
    | Dipasang di sini, bukan di satu controller: galat yang dilempar MIDDLEWARE
    | tidak pernah melewati controller mana pun, dan seluruh kegagalan unggah
    | lampiran dilempar dari sana.
    |
    | Tidak ada permintaan ke /api/* yang berguna dijawab halaman HTML --
    | pemanggilnya selalu kode, tidak pernah mata.
    |--------------------------------------------------------------------------
    */
    protected function shouldReturnJson($request, Throwable $e)
    {
        if ($request->is('api/*')) {
            return true;
        }

        return parent::shouldReturnJson($request, $e);
    }

    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        /*
        |----------------------------------------------------------------------
        | Kiriman melebihi post_max_size
        |----------------------------------------------------------------------
        | Dilempar ValidatePostSize sebelum PHP sempat mengurai apa pun. Ketika
        | itu terjadi, $_POST DAN $_FILES sama-sama dikosongkan -- jadi yang
        | sampai ke layar selama ini adalah rentetan "field wajib diisi" untuk
        | isian yang jelas-jelas sudah diisi, dan lampirannya seolah tidak
        | pernah dipilih. Menyesatkan betul.
        |
        | Batas ini menghitung SELURUH isi formulir sekaligus, bukan per file.
        | Tiga lampiran 2 MB bisa lolos satu per satu tetapi tetap tertolak
        | bersama-sama, jadi kalimatnya memang harus berbeda dari kalimat
        | kegagalan per file di ExplainUploadFailure.
        |
        | Dikirim dalam dua bentuk sekaligus -- "message" dan "errors" -- supaya
        | terbaca baik oleh layar yang menampilkan galat per field maupun yang
        | hanya menampilkan pesan umum.
        |----------------------------------------------------------------------
        */
        $this->renderable(function (PostTooLargeException $e, $request) {
            $pesan = __('upload.post_too_large', [
                'limit' => ExplainUploadFailure::batasSatuPengiriman(),
            ]);

            /*
            | Sengaja BUKAN back()->withErrors(). ValidatePostSize dilempar
            | dari global middleware, yang berjalan sebelum StartSession milik
            | grup web -- memanggil session di sini berakhir dengan
            | "Session store not set on request", menimpa pesan yang justru
            | sedang kita usahakan terbaca.
            */
            if (!$request->expectsJson()) {
                return response($pesan, 413);
            }

            return response()->json([
                'message' => $pesan,
                'errors' => ['attachments' => [$pesan]],
            ], 413);
        });
    }
}
