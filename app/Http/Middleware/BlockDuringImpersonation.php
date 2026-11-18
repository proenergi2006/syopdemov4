<?php

namespace App\Http\Middleware;

use App\Services\Auth\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menutup aksi yang tidak boleh dilakukan sambil menyamar.
 *
 * Penyamaran di aplikasi ini sengaja penuh: super admin boleh melakukan apa
 * pun yang user itu bisa, termasuk submit dan approve. Yang ditahan di sini
 * hanya satu golongan, dan alasannya bukan soal kewenangan:
 *
 * Mengubah password dan mengelola tanda tangan digital BUKAN "bertindak
 * sebagai user itu". Keduanya mengubah kredensial permanen milik orang lain,
 * dan akibatnya tidak hilang saat penyamaran berakhir -- user tersebut bisa
 * terkunci dari akunnya sendiri, atau tanda tangannya berubah tanpa ia tahu.
 */
class BlockDuringImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        $sesi = app(ImpersonationService::class)->sesiUntukRequest($request);

        if ($sesi === null) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => __('impersonation_messages.action_blocked'),
            'reason' => 'impersonation_blocked',
        ], 403);
    }
}
