<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\Queue\QueueHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Pemantauan antrean
|--------------------------------------------------------------------------
| Jalur ketiga dari pelaporan kesehatan antrean, di samping log dan email.
| Membaca potret dari service yang sama dengan perintah terjadwalnya, jadi
| angka di layar tidak mungkin berbeda dengan angka di email peringatan.
|
| Selain melihat, halaman ini juga bisa mengulang atau membuang job yang
| gagal. Tanpa itu, satu-satunya cara menindaklanjuti temuan adalah membuka
| terminal di server -- yang justru tidak selalu tersedia bagi orang yang
| menerima peringatannya.
|--------------------------------------------------------------------------
*/
class QueueHealthController extends Controller
{
    private const PERMISSION_VIEW = 'queue_monitor.view';
    private const PERMISSION_MANAGE = 'queue_monitor.manage';

    public function index(Request $request, QueueHealthService $service): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_VIEW)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke pemantauan antrean.',
            ], 403);
        }

        try {
            return response()->json([
                'success' => true,
                'message' => 'Kesehatan antrean berhasil dimuat.',
                'data' => $service->snapshot(),

                'abilities' => [
                    'can_view' => true,
                    'can_manage' => (bool) $user->hasPermission(self::PERMISSION_MANAGE),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Queue Monitor] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Kesehatan antrean gagal dimuat.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Mengembalikan job gagal ke antrean untuk dicoba lagi.
     *
     * Berguna untuk kegagalan yang sebabnya sudah diperbaiki -- misalnya SMTP
     * yang sempat mati, atau template yang baru saja dilengkapi.
     */
    public function retry(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_MANAGE)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengulang job gagal.',
            ], 403);
        }

        $validated = $request->validate([
            'uuid' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $uuid = $validated['uuid'] ?? null;

            /*
            | Tanpa uuid berarti seluruhnya. Jumlahnya dicatat lebih dulu
            | karena setelah diulang barisnya sudah tidak ada di failed_jobs.
            */
            $jumlah = $uuid === null
                ? DB::table('failed_jobs')->count()
                : DB::table('failed_jobs')->where('uuid', $uuid)->count();

            if ($jumlah === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Job gagal yang dimaksud tidak ditemukan.',
                ], 404);
            }

            Artisan::call('queue:retry', ['id' => [$uuid ?? 'all']]);

            Log::info('[Queue Monitor] Job gagal diulang', [
                'user_id' => $user->id,
                'uuid' => $uuid ?? 'all',
                'jumlah' => $jumlah,
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$jumlah} job dikembalikan ke antrean.",
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Queue Monitor] Retry error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Job gagal tidak bisa diulang.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Membuang job gagal yang memang tidak perlu dikirim lagi.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_MANAGE)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk membuang job gagal.',
            ], 403);
        }

        $validated = $request->validate([
            'uuid' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $uuid = $validated['uuid'] ?? null;

            $query = DB::table('failed_jobs');

            if ($uuid !== null) {
                $query->where('uuid', $uuid);
            }

            $jumlah = $query->count();

            if ($jumlah === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Job gagal yang dimaksud tidak ditemukan.',
                ], 404);
            }

            $query->delete();

            Log::warning('[Queue Monitor] Job gagal dibuang', [
                'user_id' => $user->id,
                'uuid' => $uuid ?? 'all',
                'jumlah' => $jumlah,
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$jumlah} job gagal dibuang.",
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Queue Monitor] Destroy error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Job gagal tidak bisa dibuang.',
                'debug' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
