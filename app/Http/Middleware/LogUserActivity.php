<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogUserActivity
{
    /*
    |--------------------------------------------------------------------------
    | Method yang dicatat
    |--------------------------------------------------------------------------
    | Hanya request yang mengubah data (create/update/delete/action) yang
    | dicatat otomatis di sini. GET/HEAD sengaja tidak dicatat supaya
    | activity log tetap ringan dan tidak penuh oleh sekadar membuka halaman.
    |--------------------------------------------------------------------------
    */
    private const LOGGED_METHODS = [
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
    ];

    /*
    |--------------------------------------------------------------------------
    | Route yang sudah dicatat manual dengan pesan lebih baik
    |--------------------------------------------------------------------------
    | Supaya tidak dobel dengan log login/logout/session expired yang sudah
    | ditulis langsung di AuthController/EnsureSanctumTokenIsNotIdle.
    |--------------------------------------------------------------------------
    */
    private const EXCLUDED_PATH_PATTERNS = [
        'api/auth/logout',
    ];

    /*
    |--------------------------------------------------------------------------
    | Segmen path yang tidak bermakna untuk deskripsi
    |--------------------------------------------------------------------------
    | "api", "transaction", "master" hanyalah prefix pengelompokan route,
    | bukan nama modul/aksi sesungguhnya (lihat routes/api.php yang membungkus
    | hampir semua route transaksi dalam Route::prefix('transaction')).
    |--------------------------------------------------------------------------
    */
    private const IGNORED_SEGMENTS = [
        'api',
        'transaction',
        'master',
    ];

    /*
    |--------------------------------------------------------------------------
    | Segmen akhir path yang menandakan sebuah aksi spesifik (bukan CRUD biasa)
    |--------------------------------------------------------------------------
    | key => [label aksi (untuk deskripsi), event key (untuk kolom Event)]
    |--------------------------------------------------------------------------
    */
    private const ACTION_SEGMENTS = [
        'approve' => ['Approve', 'approve'],
        'reject' => ['Reject', 'reject'],
        'submit' => ['Submit', 'submit'],
        'post' => ['Posting', 'post'],
        'cancel' => ['Batalkan', 'cancel'],
        'edit' => ['Ubah', 'update'],
        'print' => ['Cetak', 'print'],
        'print-url' => ['Cetak', 'print'],
        'return-history' => ['Lihat Riwayat Retur', 'view'],
        'toggle-active' => ['Ubah Status Aktif', 'toggle'],
        'toggle-available' => ['Ubah Status Tersedia', 'toggle'],
        'read' => ['Tandai Dibaca', 'read'],
        'read-all' => ['Tandai Semua Dibaca', 'read'],
        'change-password' => ['Ganti Password', 'update'],
        'assign' => ['Assign', 'assign'],
        'unassign' => ['Batalkan Assign', 'unassign'],
        'store-signature' => ['Simpan Tanda Tangan', 'update'],
        'restore' => ['Pulihkan', 'restore'],
        'force-delete' => ['Hapus Permanen', 'delete'],
    ];

    /*
    |--------------------------------------------------------------------------
    | Label yang lebih manusiawi untuk nama modul/segmen path tertentu
    |--------------------------------------------------------------------------
    */
    private const SEGMENT_LABELS = [
        'purchase-request' => 'Purchase Requisition',
        'purchase-order' => 'Purchase Order',
        'goods-receive' => 'Goods Receipt',
        'goods-return' => 'Goods Return',
        'master-vendor' => 'Master Vendor',
        'vendor' => 'Vendor',
        'dashboard-modules' => 'Dashboard Module',
        'dashboard-module-groups' => 'Dashboard Module Group',
        'activity-log' => 'Activity Log',
        'user-permission' => 'User Permission',
        'permission-modules' => 'Permission Module',
        'permissions' => 'Permission',
        'roles' => 'Role',
        'users' => 'User',
        'notifications' => 'Notifikasi',
        'access-assignments' => 'Assignment Akses',
    ];

    public function handle(
        Request $request,
        Closure $next,
    ): Response {
        $response = $next($request);

        $this->logIfNeeded($request, $response);

        return $response;
    }

    private function logIfNeeded(
        Request $request,
        Response $response,
    ): void {
        if (!in_array($request->method(), self::LOGGED_METHODS, true)) {
            return;
        }

        if ($request->is(self::EXCLUDED_PATH_PATTERNS)) {
            return;
        }

        $user = $request->user();

        if (!$user) {
            return;
        }

        $statusCode = $response->getStatusCode();
        $isSuccess = $statusCode < 400;

        [$description, $eventKey] = $this->describe($request);

        activity('activity')
            ->causedBy($user)
            ->withProperties([
                'method' => $request->method(),
                'path' => '/' . ltrim($request->path(), '/'),
                'route_name' => $request->route()?->getName(),
                'status_code' => $statusCode,
                'ip' => $request->ip(),
            ])
            ->event($isSuccess ? $eventKey : $eventKey . '_failed')
            ->log($description);
    }

    /**
     * Bangun deskripsi & event key dari path request, bukan dari nama route,
     * karena banyak route aksi (approve/reject/cancel/dll) di routes/api.php
     * hanya mewarisi nama grup generik seperti "transaction." saja.
     *
     * @return array{0: string, 1: string}
     */
    private function describe(Request $request): array
    {
        $segments = $this->meaningfulPathSegments($request);

        if (empty($segments)) {
            return [
                $request->method() . ' /' . ltrim($request->path(), '/'),
                'request',
            ];
        }

        $lastSegment = end($segments);
        [$actionLabel, $eventKey] = self::ACTION_SEGMENTS[$lastSegment] ?? [null, null];

        if ($actionLabel !== null) {
            array_pop($segments);
        } else {
            [$actionLabel, $eventKey] = match ($request->method()) {
                'POST' => ['Tambah', 'create'],
                'PUT', 'PATCH' => ['Ubah', 'update'],
                'DELETE' => ['Hapus', 'delete'],
                default => [$request->method(), 'request'],
            };
        }

        $objectLabel = collect($segments)
            ->map(fn (string $segment): string => $this->labelForSegment($segment))
            ->unique()
            ->implode(' ');

        $description = trim($actionLabel . ' ' . $objectLabel);

        return [$description !== '' ? $description : $actionLabel, $eventKey];
    }

    /**
     * @return string[]
     */
    private function meaningfulPathSegments(Request $request): array
    {
        $segments = explode('/', trim($request->path(), '/'));

        return collect($segments)
            ->reject(fn (string $segment): bool => in_array($segment, self::IGNORED_SEGMENTS, true))
            ->reject(fn (string $segment): bool => $this->looksLikeIdentifier($segment))
            ->values()
            ->all();
    }

    private function looksLikeIdentifier(string $segment): bool
    {
        // ID numerik biasa (contoh: /users/12).
        if (ctype_digit($segment)) {
            return true;
        }

        // Public ID hasil Crypt::encryptString() panjangnya ~200 karakter,
        // jauh lebih panjang dari segmen path yang bermakna.
        if (strlen($segment) > 20) {
            return true;
        }

        return false;
    }

    private function labelForSegment(string $segment): string
    {
        if (isset(self::SEGMENT_LABELS[$segment])) {
            return self::SEGMENT_LABELS[$segment];
        }

        $normalized = str_replace(['-', '_'], ' ', $segment);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return ucwords(trim($normalized));
    }
}
