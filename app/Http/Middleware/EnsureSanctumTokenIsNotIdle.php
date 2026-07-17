<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureSanctumTokenIsNotIdle
{
    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $plainTextToken = $request->bearerToken();

        if (!$plainTextToken) {
            return response()->json([
                'success' => false,
                'message' => 'Token tidak ditemukan. Silakan login kembali.',
                'reason' => 'missing_token',
            ], 401);
        }

        $accessToken = PersonalAccessToken::findToken(
            $plainTextToken,
        );

        if (!$accessToken) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi tidak valid. Silakan login kembali.',
                'reason' => 'invalid_token',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Route yang tidak dihitung sebagai aktivitas user
        |--------------------------------------------------------------------------
        | API notifikasi/polling jangan memperpanjang idle session.
        |--------------------------------------------------------------------------
        */
        if ($this->shouldIgnoreAsUserActivity($request)) {
            return $next($request);
        }

        $idleTimeoutMinutes = (int) config(
            'auth_session.idle_timeout_minutes',
            60,
        );

        $absoluteTimeoutMinutes = (int) config(
            'auth_session.absolute_timeout_minutes',
            720,
        );

        $cacheKey = $this->activityCacheKey(
            (int) $accessToken->id,
        );

        $lastActivityValue = Cache::get($cacheKey);

        $lastActivityAt = $lastActivityValue
            ? Carbon::parse($lastActivityValue)
            : Carbon::parse($accessToken->created_at);

        if (
            $idleTimeoutMinutes > 0
            && $lastActivityAt
            ->copy()
            ->addMinutes($idleTimeoutMinutes)
            ->isPast()
        ) {
            Cache::forget($cacheKey);

            $accessToken->delete();

            return response()->json([
                'success' => false,
                'message' => 'Sesi Anda telah berakhir karena tidak ada aktivitas. Silakan login kembali.',
                'reason' => 'idle_timeout',
            ], 401);
        }

        $response = $next($request);

        /*
        |--------------------------------------------------------------------------
        | Update aktivitas hanya untuk request user yang nyata
        |--------------------------------------------------------------------------
        */
        Cache::put(
            $cacheKey,
            now()->toIso8601String(),
            now()->addMinutes(
                max(
                    $idleTimeoutMinutes,
                    $absoluteTimeoutMinutes,
                    60,
                ) + 60,
            ),
        );

        return $response;
    }

    private function activityCacheKey(int $tokenId): string
    {
        return 'auth:last_activity:' . $tokenId;
    }

    private function shouldIgnoreAsUserActivity(
        Request $request,
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | Sesuaikan pattern ini dengan route notifikasi SYOP V4.
        |--------------------------------------------------------------------------
        | Contoh path:
        | - api/notifications
        | - api/notifications/unread-count
        | - api/notification-badge
        |--------------------------------------------------------------------------
        */
        return $request->is([
            'api/notifications*',
        ]);
    }
}
