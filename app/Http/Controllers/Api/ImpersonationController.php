<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImpersonationSession;
use App\Models\Permission;
use App\Models\User;
use App\Services\Auth\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

class ImpersonationController extends Controller
{
    public function __construct(
        private readonly ImpersonationService $penyamaran,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Daftar akun yang bisa dimasuki
    |--------------------------------------------------------------------------
    */
    public function candidates(Request $request): JsonResponse
    {
        $this->pastikanBoleh($request, ImpersonationService::PERMISSION_MULAI);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $cari = trim((string) ($validated['search'] ?? ''));

        $query = User::query()
            ->with([
                'roles:id,nama',
                'cabangData:id,nama_cabang,inisial_cabang',
            ])
            ->whereKeyNot($request->user()->id)
            ->whereNotIn('id', $this->idPemegangIzinMenyamar())
            ->orderBy('name');

        if ($cari !== '') {
            $query->where(function ($sub) use ($cari) {
                $sub->where('name', 'ILIKE', "%{$cari}%")
                    ->orWhere('username', 'ILIKE', "%{$cari}%")
                    ->orWhere('email', 'ILIKE', "%{$cari}%");
            });
        }

        $hasil = $query->paginate($perPage);

        $hasil->getCollection()->transform(function (User $user): array {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'is_active' => (bool) ($user->is_active ?? true),
                'role' => $user->roles->first()->nama ?? null,
                'cabang' => $user->cabangData
                    ? trim(
                        ($user->cabangData->inisial_cabang ?? '-')
                        . ' - '
                        . ($user->cabangData->nama_cabang ?? '-'),
                    )
                    : null,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => __('impersonation_messages.candidates_loaded'),
            'data' => $hasil,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Mulai menyamar
    |--------------------------------------------------------------------------
    */
    public function start(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'reason.required' => __('impersonation_messages.reason_required'),
            'reason.min' => __('impersonation_messages.reason_too_short'),
        ]);

        $hasil = $this->penyamaran->mulai(
            $request->user(),
            $userId,
            trim($validated['reason']),
            $request,
        );

        /** @var User $target */
        $target = $hasil['target'];

        /** @var ImpersonationSession $sesi */
        $sesi = $hasil['session'];

        return response()->json([
            'success' => true,
            'message' => __('impersonation_messages.started', [
                'name' => $target->name,
            ]),
            'token' => $hasil['token'],
            'data' => [
                'session_id' => $sesi->id,
                'expires_in_minutes' => (int) config(
                    'auth_session.impersonation_minutes',
                    60,
                ),
                'user' => [
                    'id' => $target->id,
                    'name' => $target->name,
                    'username' => $target->username,
                ],
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Kembali ke akun admin
    |--------------------------------------------------------------------------
    | Tidak ada pemeriksaan permission di sini, dan itu disengaja: yang sedang
    | login adalah user target, yang tentu tidak punya izin menyamar. Yang
    | memberi izin adalah baris sesi yang terikat pada id token -- seluruhnya
    | ditangani di ImpersonationService::stop().
    |--------------------------------------------------------------------------
    */
    public function stop(Request $request): JsonResponse
    {
        $hasil = $this->penyamaran->stop($request);

        /** @var User $admin */
        $admin = $hasil['admin'];

        return response()->json([
            'success' => true,
            'message' => __('impersonation_messages.stopped'),
            'token' => $hasil['token'],
            'data' => [
                'user' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'username' => $admin->username,
                ],
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Keadaan penyamaran saat ini
    |--------------------------------------------------------------------------
    | Dipanggil frontend setiap halaman dimuat, supaya banner tetap muncul
    | sesudah refresh dan tidak bergantung pada localStorage yang bisa basi.
    |--------------------------------------------------------------------------
    */
    public function status(Request $request): JsonResponse
    {
        $sesi = $this->penyamaran->sesiUntukRequest($request);

        if (!$sesi) {
            return response()->json([
                'success' => true,
                'data' => [
                    'impersonating' => false,
                ],
            ]);
        }

        $sesi->loadMissing(['admin:id,name,username', 'target:id,name,username']);

        return response()->json([
            'success' => true,
            'data' => [
                'impersonating' => true,
                'session_id' => $sesi->id,
                'started_at' => $sesi->started_at,
                'reason' => $sesi->reason,
                'admin' => $sesi->admin
                    ? [
                        'id' => $sesi->admin->id,
                        'name' => $sesi->admin->name,
                        'username' => $sesi->admin->username,
                    ]
                    : null,
                'target' => $sesi->target
                    ? [
                        'id' => $sesi->target->id,
                        'name' => $sesi->target->name,
                        'username' => $sesi->target->username,
                    ]
                    : null,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Riwayat penyamaran
    |--------------------------------------------------------------------------
    */
    public function history(Request $request): JsonResponse
    {
        $this->pastikanBoleh($request, ImpersonationService::PERMISSION_LIHAT);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'admin_user_id' => ['nullable', 'integer'],
            'target_user_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = ImpersonationSession::query()
            ->with(['admin:id,name,username', 'target:id,name,username'])
            ->orderByDesc('id');

        $cari = trim((string) ($validated['search'] ?? ''));

        if ($cari !== '') {
            $query->where(function ($sub) use ($cari) {
                $sub->where('reason', 'ILIKE', "%{$cari}%")
                    ->orWhereHas('admin', function ($q) use ($cari) {
                        $q->where('name', 'ILIKE', "%{$cari}%")
                            ->orWhere('username', 'ILIKE', "%{$cari}%");
                    })
                    ->orWhereHas('target', function ($q) use ($cari) {
                        $q->where('name', 'ILIKE', "%{$cari}%")
                            ->orWhere('username', 'ILIKE', "%{$cari}%");
                    });
            });
        }

        if (!empty($validated['admin_user_id'])) {
            $query->where('admin_user_id', (int) $validated['admin_user_id']);
        }

        if (!empty($validated['target_user_id'])) {
            $query->where('target_user_id', (int) $validated['target_user_id']);
        }

        if (!empty($validated['date_from'])) {
            $query->whereDate('started_at', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('started_at', '<=', $validated['date_to']);
        }

        $sesi = $query->paginate((int) ($validated['per_page'] ?? 25));

        /*
        | Jumlah aksi per sesi diambil sekali untuk seluruh halaman, bukan satu
        | query per baris.
        */
        $jumlahAksi = $this->jumlahAksiPerSesi(
            $sesi->getCollection()->pluck('id')->all(),
        );

        $sesi->getCollection()->transform(
            fn(ImpersonationSession $baris): array => $this->bentukSesi(
                $baris,
                $jumlahAksi[$baris->id] ?? 0,
            ),
        );

        return response()->json([
            'success' => true,
            'message' => __('impersonation_messages.history_loaded'),
            'data' => $sesi,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Satu sesi beserta aksi yang dilakukan di dalamnya
    |--------------------------------------------------------------------------
    | Ini yang membuat pilihan "cukup tercatat di log" berarti sesuatu. Tanpa
    | halaman yang bisa menjawab "apa saja yang dilakukan di sesi itu", jejak
    | hanya ada di tabel mentah yang tidak akan pernah dibuka orang.
    |--------------------------------------------------------------------------
    */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->pastikanBoleh($request, ImpersonationService::PERMISSION_LIHAT);

        $sesi = ImpersonationSession::query()
            ->with(['admin:id,name,username', 'target:id,name,username'])
            ->find($id);

        if (!$sesi) {
            return response()->json([
                'success' => false,
                'message' => __('impersonation_messages.session_not_found'),
            ], 404);
        }

        $aksi = Activity::query()
            ->where('log_name', 'activity')
            ->whereRaw(
                "properties->>'impersonation_session_id' = ?",
                [(string) $sesi->id],
            )
            ->orderBy('id')
            ->get()
            ->map(fn(Activity $baris): array => [
                'id' => $baris->id,
                'description' => $baris->description,
                'event' => $baris->event,
                'method' => $baris->properties['method'] ?? null,
                'path' => $baris->properties['path'] ?? null,
                'status_code' => $baris->properties['status_code'] ?? null,
                'ip' => $baris->properties['ip'] ?? null,
                'created_at' => $baris->created_at,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'message' => __('impersonation_messages.history_loaded'),
            'data' => [
                'session' => $this->bentukSesi($sesi, $aksi->count()),
                'actions' => $aksi,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pembantu
    |--------------------------------------------------------------------------
    */
    private function bentukSesi(
        ImpersonationSession $sesi,
        int $jumlahAksi,
    ): array {
        return [
            'id' => $sesi->id,
            'admin' => $sesi->admin
                ? [
                    'id' => $sesi->admin->id,
                    'name' => $sesi->admin->name,
                    'username' => $sesi->admin->username,
                ]
                : null,
            'target' => $sesi->target
                ? [
                    'id' => $sesi->target->id,
                    'name' => $sesi->target->name,
                    'username' => $sesi->target->username,
                ]
                : null,
            'reason' => $sesi->reason,
            'ip' => $sesi->ip,
            'user_agent' => $sesi->user_agent,
            'started_at' => $sesi->started_at,
            'ended_at' => $sesi->ended_at,
            'ended_reason' => $sesi->ended_reason,
            'is_running' => $sesi->sedangBerjalan(),
            'action_count' => $jumlahAksi,
        ];
    }

    /**
     * @param  array<int, int>  $idSesi
     * @return array<int, int>
     */
    private function jumlahAksiPerSesi(array $idSesi): array
    {
        if (empty($idSesi)) {
            return [];
        }

        /*
        | Nilai di properties bertipe teks, jadi id dibandingkan sebagai teks.
        | Placeholder dibangun dari jumlahnya, dan isinya id dari basis data
        | sendiri -- tidak ada masukan user yang masuk ke SQL di sini.
        */
        $idTeks = array_map('strval', $idSesi);

        $penanda = implode(',', array_fill(0, count($idTeks), '?'));

        try {
            $baris = Activity::query()
                ->where('log_name', 'activity')
                ->whereRaw(
                    "properties->>'impersonation_session_id' IN ({$penanda})",
                    $idTeks,
                )
                ->selectRaw("(properties->>'impersonation_session_id') as sesi, count(*) as jumlah")
                ->groupBy(DB::raw("(properties->>'impersonation_session_id')"))
                ->pluck('jumlah', 'sesi')
                ->all();

            $hasil = [];

            foreach ($baris as $sesi => $jumlah) {
                $hasil[(int) $sesi] = (int) $jumlah;
            }

            return $hasil;
        } catch (\Throwable $e) {
            /*
            | Jumlah aksi hanya angka pelengkap di daftar. Kalau query-nya
            | gagal, daftar riwayat tetap harus bisa dibuka.
            */
            Log::warning('[Impersonation] Gagal menghitung aksi per sesi', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * ID user yang sendirinya boleh menyamar, jadi tidak boleh disamari.
     *
     * Sengaja lebih ketat daripada User::hasPermission(): di sini SEMUA role
     * yang dimiliki user diperiksa, sementara hasPermission() hanya memeriksa
     * role aktifnya. Lebih ketat di daftar kandidat tidak merugikan, dan
     * pemeriksaan yang mengikat tetap ada di ImpersonationService::mulai().
     *
     * @return array<int, int>
     */
    private function idPemegangIzinMenyamar(): array
    {
        $permissionId = Permission::query()
            ->where('code', ImpersonationService::PERMISSION_MULAI)
            ->value('id');

        if (!$permissionId) {
            return [];
        }

        $lewatRole = DB::table('user_roles')
            ->join(
                'role_permissions',
                'role_permissions.role_id',
                '=',
                'user_roles.role_id',
            )
            ->where('role_permissions.permission_id', $permissionId)
            ->where('role_permissions.is_active', true)
            ->pluck('user_roles.user_id');

        $lewatLangsung = DB::table('user_permissions')
            ->where('permission_id', $permissionId)
            ->where('is_active', true)
            ->pluck('user_id');

        return $lewatRole
            ->merge($lewatLangsung)
            ->map(fn($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function pastikanBoleh(Request $request, string $permission): void
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission($permission)) {
            abort(403, __('impersonation_messages.forbidden'));
        }
    }
}
