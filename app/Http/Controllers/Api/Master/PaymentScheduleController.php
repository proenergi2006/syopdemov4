<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\PaymentSchedule;
use App\Models\PaymentScheduleCutoff;
use App\Services\FundRequest\PaymentScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/*
|--------------------------------------------------------------------------
| Master jadwal pembayaran Finance
|--------------------------------------------------------------------------
| Sebuah jadwal adalah sekumpulan batas setor yang berlaku bersama, beserta
| cakupannya. Batas-batasnya dikirim sekaligus dengan jadwalnya -- bukan
| dikelola terpisah -- karena satu batas sendirian tidak punya arti.
|
| Cakupan ganda (modul + keterangan transaksi) membuat dua jadwal bisa
| sama-sama cocok pada satu dokumen. Yang paling khusus menang; aturannya ada
| di PaymentScheduleService agar layar dan mesin perhitungan tidak berbeda
| pendapat.
|--------------------------------------------------------------------------
*/
class PaymentScheduleController extends Controller
{
    private const PERMISSION_PREFIX = 'master_payment_schedule';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.view')) {
            return response()->json([
                'success' => false,
                'message' => __('payment_schedule_messages.index.forbidden'),
            ], 403);
        }

        $jadwal = PaymentSchedule::query()
            ->with(['cutoffs', 'transactionCategory:id,name'])
            ->orderByRaw('CASE WHEN transaction_category_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN document_type IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $jadwal->map(fn (PaymentSchedule $s): array => $this->transform($s))->all(),

            'abilities' => [
                'can_view' => true,
                'can_create' => $user->hasPermission(self::PERMISSION_PREFIX . '.create'),
                'can_update' => $user->hasPermission(self::PERMISSION_PREFIX . '.update'),
                'can_delete' => $user->hasPermission(self::PERMISSION_PREFIX . '.delete'),
            ],

            'options' => [
                'document_types' => collect(PaymentSchedule::documentTypeOptions())
                    ->map(fn (string $label, string $value): array => [
                        'value' => $value,
                        'label' => $label,
                    ])
                    ->values()
                    ->all(),

                'days' => collect(range(1, 7))
                    ->map(fn (int $iso): array => [
                        'value' => $iso,
                        'label' => PaymentSchedule::namaHari($iso),
                    ])
                    ->all(),

                'transaction_categories' => DB::table('fund_request_transaction_categories')
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->all(),
            ],
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->simpan($request, null);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->simpan($request, $id);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.delete')) {
            return response()->json([
                'success' => false,
                'message' => __('payment_schedule_messages.destroy.forbidden'),
            ], 403);
        }

        $jadwal = PaymentSchedule::find($id);

        if (!$jadwal) {
            return response()->json([
                'success' => false,
                'message' => __('payment_schedule_messages.not_found'),
            ], 404);
        }

        /*
        | Jadwal umum adalah jaring pengaman: tanpa itu, dokumen yang tidak
        | cocok ke jadwal khusus mana pun akan diterima tanpa tanggal bayar
        | sama sekali, dan pemohonnya tidak diberi tahu apa-apa.
        */
        if ($jadwal->document_type === null && $jadwal->transaction_category_id === null) {
            $lain = PaymentSchedule::query()
                ->whereNull('document_type')
                ->whereNull('transaction_category_id')
                ->where('id', '!=', $jadwal->id)
                ->exists();

            if (!$lain) {
                return response()->json([
                    'success' => false,
                    'message' => __('payment_schedule_messages.destroy.last_fallback'),
                ], 422);
            }
        }

        $jadwal->delete();

        return response()->json([
            'success' => true,
            'message' => __('payment_schedule_messages.destroy.success'),
        ], 200);
    }

    /**
     * Menyimpan jadwal beserta seluruh batas setornya sekaligus.
     */
    private function simpan(Request $request, ?int $id): JsonResponse
    {
        $user = $request->user();
        $aksi = $id === null ? 'create' : 'update';

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.' . $aksi)) {
            return response()->json([
                'success' => false,
                'message' => __("payment_schedule_messages.{$aksi}.forbidden"),
            ], 403);
        }

        $jadwal = $id === null ? null : PaymentSchedule::find($id);

        if ($id !== null && !$jadwal) {
            return response()->json([
                'success' => false,
                'message' => __('payment_schedule_messages.not_found'),
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'document_type' => ['nullable', Rule::in(PaymentSchedule::DOCUMENT_TYPES)],
            'transaction_category_id' => ['nullable', 'integer', 'exists:fund_request_transaction_categories,id'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'cutoffs' => ['required', 'array', 'min:1', 'max:14'],
            'cutoffs.*.cutoff_day' => ['required', 'integer', 'between:1,7'],
            'cutoffs.*.cutoff_time' => ['required', 'date_format:H:i'],
            'cutoffs.*.payment_day' => ['required', 'integer', 'between:1,7'],
            'cutoffs.*.payment_week_offset' => ['required', 'integer', 'between:0,4'],
        ]);

        /*
        | Dua jadwal dengan cakupan yang sama persis membuat hasilnya
        | bergantung pada urutan baris, dan itu bukan hal yang boleh ditebak
        | orang dari layar.
        */
        $kembar = PaymentSchedule::query()
            ->where('document_type', $validated['document_type'] ?? null)
            ->where('transaction_category_id', $validated['transaction_category_id'] ?? null)
            ->when($id !== null, fn ($q) => $q->where('id', '!=', $id))
            ->exists();

        if ($kembar) {
            return response()->json([
                'success' => false,
                'message' => __('payment_schedule_messages.duplicate_scope'),
            ], 422);
        }

        try {
            $hasil = DB::transaction(function () use ($validated, $jadwal, $user): PaymentSchedule {
                $isi = [
                    'name' => trim($validated['name']),
                    'document_type' => $validated['document_type'] ?? null,
                    'transaction_category_id' => $validated['transaction_category_id'] ?? null,
                    'is_active' => (bool) $validated['is_active'],
                    'notes' => $validated['notes'] ?? null,
                    'updated_by' => $user->id,
                ];

                if ($jadwal) {
                    $jadwal->update($isi);
                } else {
                    $isi['created_by'] = $user->id;
                    $jadwal = PaymentSchedule::create($isi);
                }

                /*
                | Batasnya ditulis ulang seluruhnya, bukan dicocokkan satu per
                | satu. Jumlahnya sedikit dan tidak ada yang menunjuk ke baris
                | tertentu, jadi menyamakan isi jauh lebih sederhana daripada
                | melacak mana yang ditambah, diubah, atau dihapus.
                */
                PaymentScheduleCutoff::where('payment_schedule_id', $jadwal->id)->delete();

                foreach ($validated['cutoffs'] as $baris) {
                    PaymentScheduleCutoff::create([
                        'payment_schedule_id' => $jadwal->id,
                        'cutoff_day' => $baris['cutoff_day'],
                        'cutoff_time' => $baris['cutoff_time'] . ':00',
                        'payment_day' => $baris['payment_day'],
                        'payment_week_offset' => $baris['payment_week_offset'],
                    ]);
                }

                return $jadwal;
            });
        } catch (\Throwable $e) {
            Log::error('[Jadwal Bayar] Simpan gagal', [
                'payment_schedule_id' => $id,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => __("payment_schedule_messages.{$aksi}.failed"),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => __("payment_schedule_messages.{$aksi}.success"),
            'data' => $this->transform($hasil->fresh(['cutoffs', 'transactionCategory'])),
        ], $id === null ? 201 : 200);
    }

    /**
     * Contoh hasil perhitungan, supaya aturannya bisa dilihat tanpa menunggu
     * ada dokumen sungguhan yang diterima.
     */
    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission(self::PERMISSION_PREFIX . '.view')) {
            return response()->json([
                'success' => false,
                'message' => __('payment_schedule_messages.index.forbidden'),
            ], 403);
        }

        $validated = $request->validate([
            'document_type' => ['nullable', Rule::in(PaymentSchedule::DOCUMENT_TYPES)],
            'transaction_category_id' => ['nullable', 'integer'],
        ]);

        $service = app(PaymentScheduleService::class);

        /* Satu pekan penuh, supaya tiap hari terlihat jatuh ke rombongan mana. */
        $mulai = now()->startOfWeek(\Carbon\CarbonInterface::MONDAY);
        $hasil = [];

        foreach (range(0, 6) as $i) {
            $saat = $mulai->copy()->addDays($i)->setTime(10, 0);

            $bayar = $service->nextPaymentDate(
                $saat,
                $validated['document_type'] ?? null,
                isset($validated['transaction_category_id'])
                    ? (int) $validated['transaction_category_id']
                    : null,
            );

            $hasil[] = [
                'received_day' => PaymentSchedule::namaHari($saat->dayOfWeekIso),
                'received_date' => $saat->toDateString(),
                'payment_day' => $bayar ? PaymentSchedule::namaHari($bayar->dayOfWeekIso) : null,
                'payment_date' => $bayar?->toDateString(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'schedule' => $service->resolve(
                    $validated['document_type'] ?? null,
                    isset($validated['transaction_category_id'])
                        ? (int) $validated['transaction_category_id']
                        : null,
                )?->name,

                'rows' => $hasil,
            ],
        ], 200);
    }

    private function transform(PaymentSchedule $jadwal): array
    {
        return [
            'id' => $jadwal->id,
            'name' => $jadwal->name,

            'document_type' => $jadwal->document_type,
            'document_type_label' => PaymentSchedule::documentTypeLabel($jadwal->document_type),

            'transaction_category_id' => $jadwal->transaction_category_id,
            'transaction_category_name' => $jadwal->transactionCategory?->name,

            'is_active' => (bool) $jadwal->is_active,
            'notes' => $jadwal->notes,

            /* Jadwal umum tidak boleh dihapus, dan layar perlu tahu itu. */
            'is_fallback' => $jadwal->document_type === null
                && $jadwal->transaction_category_id === null,

            'cutoffs' => $jadwal->cutoffs->map(fn (PaymentScheduleCutoff $c): array => [
                'id' => $c->id,
                'cutoff_day' => $c->cutoff_day,
                'cutoff_day_name' => $c->cutoff_day_name,
                'cutoff_time' => substr((string) $c->cutoff_time, 0, 5),
                'payment_day' => $c->payment_day,
                'payment_day_name' => $c->payment_day_name,
                'payment_week_offset' => $c->payment_week_offset,
                'description' => $c->description,
            ])->all(),
        ];
    }
}
