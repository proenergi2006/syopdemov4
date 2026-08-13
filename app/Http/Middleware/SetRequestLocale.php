<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetRequestLocale
{
    private const SUPPORTED_LOCALES = ['id', 'en'];

    private const DEFAULT_LOCALE = 'id';

    /*
    |--------------------------------------------------------------------------
    | Set Locale untuk Request
    |--------------------------------------------------------------------------
    | Didaftarkan dua kali (lihat Kernel.php dan routes/api.php):
    |
    | 1. Global middleware (sebelum auth:sanctum resolve user) -- menangani
    |    guest route (login, reset-password, dll) via header X-App-Locale.
    | 2. Route middleware setelah auth:sanctum pada group yang butuh login --
    |    menimpa locale dengan preferensi tersimpan milik user yang login,
    |    supaya lebih diutamakan daripada header.
    |--------------------------------------------------------------------------
    */
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $locale = null;

        $user = $request->user();

        if ($user && $user->locale) {
            $locale = $user->locale;
        }

        if (!$locale) {
            $locale = $request->header('X-App-Locale');
        }

        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = self::DEFAULT_LOCALE;
        }

        App::setLocale($locale);

        return $next($request);
    }
}
