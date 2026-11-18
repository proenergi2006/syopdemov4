<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\PettyCashCeiling;
use App\Services\FundRequest\PettyCash\PettyCashLedgerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/*
|--------------------------------------------------------------------------
| Master plafon petty cash
|--------------------------------------------------------------------------
| Satu baris per cabang. Keberadaan barisnya yang menentukan sebuah cabang
| memakai petty cash atau tidak -- jadi menonaktifkan plafon adalah cara
| mematikan fitur ini untuk satu cabang, tanpa menyentuh permission siapa pun.
|
| MENGUBAH PLAFON TIDAK MENGUBAH SALDO. Menaikkan plafon tidak menambah uang
| di tangan ADH; yang membawanya adalah top up berikutnya, yang nominalnya
| memang dihitung dari plafon baru. Kalau saldo ikut naik seketika, sistem
| akan mengizinkan pencairan atas uang yang belum dikirim siapa pun.
|
| Satu-satunya cara mengubah saldo tanpa dokumen adalah penyesuaian, dan itu
| permission tersendiri yang menuntut alasan.
|--------------------------------------------------------------------------
*/
class PettyCashCeilingController extends Controller
{
    public function __construct(
        private readonly PettyCashLedgerService $ledger,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('master_petty_cash_ceiling.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk melihat plafon petty cash.',
            ], 403);
        }

        $baris = PettyCashCeiling::query()
            ->with([
                'branchData:id,nama_cabang,inisial_cabang',
                'departmentData:id,nama,kode',
            ])
            ->orderBy('branch_id')
            ->get();

        /*
        | Saldo seluruh plafon diambil dalam SATU kueri. Memanggil balance()
        | di dalam perulangan akan melahirkan satu kueri per baris daftar.
        */
        $saldo = $this->ledger->balances($baris->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return response()->json([
            'success' => true,
            'data' => $baris->map(function (PettyCashCeiling $row) use ($saldo) {
                $s = $saldo[$row->id] ?? 0.0;

                return [
                    'public_id' => $row->encrypted_id,
                    'branch_id' => (int) $row->branch_id,
                    'branch' => $row->branchData?->nama_cabang,
                    'branch_code' => $row->branchData?->inisial_cabang,
                    'department_id' => (int) $row->department_id,
                    'department' => $row->departmentData?->nama,
                    'ceiling_amount' => (float) $row->ceiling_amount,
                    'claim_threshold' => (float) $row->claim_threshold,
                    'warning_percentage' => (int) $row->warning_percentage,
                    'balance' => $s,

                    /* Berapa yang akan dibawa top up berikutnya. */
                    'topup_amount' => max(0.0, (float) $row->ceiling_amount - $s),

                    'warning_threshold' => $row->warningThreshold(),
                    'below_warning' => $s < $row->warningThreshold(),
                    'is_active' => (bool) $row->is_active,
                    'notes' => $row->notes,
                ];
            })->values(),
        ], 200);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('master_petty_cash_ceiling.create')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menetapkan plafon petty cash.',
            ], 403);
        }

        $validated = $request->validate([
            'branch_id' => [
                'required',
                'integer',
                Rule::exists('cabang', 'id')->where('is_active', true),

                /*
                | Satu plafon hidup per cabang. Diperiksa di sini supaya
                | pesannya bisa dibaca; indeks uniknya tetap ada sebagai
                | penjaga terakhir terhadap dua penyimpanan bersamaan.
                */
                Rule::unique('petty_cash_ceilings', 'branch_id')->whereNull('deleted_at'),
            ],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'ceiling_amount' => ['required', 'numeric', 'min:1'],
            'claim_threshold' => ['required', 'numeric', 'min:1'],
            'warning_percentage' => ['required', 'integer', 'min:1', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'branch_id' => 'cabang',
            'department_id' => 'department',
            'ceiling_amount' => 'plafon',
            'claim_threshold' => 'ambang claim',
            'warning_percentage' => 'ambang peringatan',
        ]);

        if ((float) $validated['claim_threshold'] > (float) $validated['ceiling_amount']) {
            return response()->json([
                'success' => false,
                'message' => 'Ambang claim tidak boleh lebih besar daripada plafonnya sendiri. '
                    . 'Satu claim pun tidak akan bisa dibayar.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $plafon = PettyCashCeiling::create([
                'branch_id' => (int) $validated['branch_id'],
                'department_id' => (int) $validated['department_id'],
                'ceiling_amount' => $validated['ceiling_amount'],
                'claim_threshold' => $validated['claim_threshold'],
                'warning_percentage' => (int) $validated['warning_percentage'],
                'is_active' => true,
                'notes' => $this->bersihkan($validated['notes'] ?? null),
                'created_by' => $user->id,
            ]);

            /*
            | Saldo awalnya masuk lewat buku mutasi, bukan dibaca dari kolom
            | plafon. Dengan begitu plafon yang kelak diubah tidak mengubah
            | arti riwayat yang sudah lewat.
            */
            $this->ledger->recordOpening($plafon, $user);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Plafon petty cash berhasil ditetapkan. Saldo awalnya sudah tercatat.',
                'data' => ['public_id' => $plafon->encrypted_id],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal('store', $e);
        }
    }

    public function update(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('master_petty_cash_ceiling.update')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk mengubah plafon petty cash.',
            ], 403);
        }

        $validated = $request->validate([
            'ceiling_amount' => ['required', 'numeric', 'min:1'],
            'claim_threshold' => ['required', 'numeric', 'min:1'],
            'warning_percentage' => ['required', 'integer', 'min:1', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'ceiling_amount' => 'plafon',
            'claim_threshold' => 'ambang claim',
            'warning_percentage' => 'ambang peringatan',
        ]);

        if ((float) $validated['claim_threshold'] > (float) $validated['ceiling_amount']) {
            return response()->json([
                'success' => false,
                'message' => 'Ambang claim tidak boleh lebih besar daripada plafonnya sendiri.',
            ], 422);
        }

        try {
            $plafon = $this->temukan($publicId);

            $plafon->update([
                'ceiling_amount' => $validated['ceiling_amount'],
                'claim_threshold' => $validated['claim_threshold'],
                'warning_percentage' => (int) $validated['warning_percentage'],
                'is_active' => (bool) $validated['is_active'],
                'notes' => $this->bersihkan($validated['notes'] ?? null),
                'updated_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Plafon petty cash berhasil diperbarui. Saldo berjalannya tidak berubah.',
            ], 200);
        } catch (ModelNotFoundException) {
            return $this->tidakDitemukan();
        } catch (\Throwable $e) {
            return $this->gagal('update', $e);
        }
    }

    public function destroy(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('master_petty_cash_ceiling.delete')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus plafon petty cash.',
            ], 403);
        }

        try {
            $plafon = $this->temukan($publicId);

            /*
            | Plafon yang masih punya formulir berjalan tidak boleh dihapus:
            | formulirnya akan menunjuk plafon yang sudah tidak ada, dan
            | pencairannya akan gagal tanpa sebab yang terlihat ADH.
            */
            $berjalan = DB::table('petty_cashes')
                ->where('petty_cash_ceiling_id', $plafon->id)
                ->whereNull('deleted_at')
                ->whereIn('status', ['DRAFT', 'IN PROGRESS', 'APPROVED'])
                ->count();

            if ($berjalan > 0) {
                return response()->json([
                    'success' => false,
                    'message' => sprintf(
                        'Masih ada %d formulir Petty Cash yang berjalan di cabang ini. '
                        . 'Selesaikan atau batalkan dulu, atau nonaktifkan plafonnya saja.',
                        $berjalan,
                    ),
                ], 422);
            }

            $plafon->update(['is_active' => false, 'updated_by' => $user->id]);
            $plafon->delete();

            return response()->json([
                'success' => true,
                'message' => 'Plafon petty cash berhasil dihapus. Buku mutasinya tetap tersimpan.',
            ], 200);
        } catch (ModelNotFoundException) {
            return $this->tidakDitemukan();
        } catch (\Throwable $e) {
            return $this->gagal('destroy', $e);
        }
    }

    /**
     * Buku mutasi sebuah plafon.
     *
     * Inilah jawaban atas "kenapa saldonya sekian": daftar, bukan tebakan.
     */
    public function ledger(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('master_petty_cash_ceiling.view')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk melihat buku mutasi kas kecil.',
            ], 403);
        }

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        try {
            $plafon = $this->temukan($publicId);
        } catch (ModelNotFoundException) {
            return $this->tidakDitemukan();
        }

        $halaman = $plafon->ledgers()
            ->with('creator:id,name')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50);

        return response()->json([
            'success' => true,
            'data' => $halaman->through(fn ($m) => [
                'entry_type' => $m->entry_type,
                'amount' => (float) $m->amount,
                'balance_after' => (float) $m->balance_after,
                'reference_type' => $m->reference_type,
                'reference_id' => $m->reference_id,
                'notes' => $m->notes,
                'created_by' => $m->creator?->name,
                'created_at' => $m->created_at,
            ]),
            'meta' => [
                'branch' => $plafon->branchData?->nama_cabang,
                'ceiling_amount' => (float) $plafon->ceiling_amount,
                'balance' => $this->ledger->balance($plafon->id),
            ],
        ], 200);
    }

    /**
     * Penyesuaian saldo manual.
     *
     * Jalan keluar untuk keadaan yang tidak punya dokumen: selisih hasil
     * hitung fisik, atau pencairan keliru yang sudah tidak bisa dibatalkan.
     * Alasannya WAJIB, karena baris tanpa dokumen hanya bisa dipertanggung
     * jawabkan oleh keterangannya.
     */
    public function adjust(string $publicId, Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->hasPermission('master_petty_cash_ceiling.adjust')) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menyesuaikan saldo kas kecil.',
            ], 403);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['required', 'string', 'min:5', 'max:2000'],
        ], [], [
            'amount' => 'nominal penyesuaian',
            'notes' => 'alasan',
        ]);

        DB::beginTransaction();

        try {
            $plafon = $this->temukan($publicId);

            $nominal = (float) $validated['amount'];

            /*
            | Saldo tidak boleh dibuat negatif oleh penyesuaian. Kas kecil yang
            | saldonya minus tidak punya arti di dunia nyata, dan angka itu
            | akan menjalar ke setiap pemeriksaan kecukupan sesudahnya.
            */
            DB::table('petty_cash_ceilings')->where('id', $plafon->id)->lockForUpdate()->value('id');

            $saldo = $this->ledger->balance($plafon->id);

            if ($saldo + $nominal < 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => sprintf(
                        'Penyesuaian ini membuat saldo menjadi negatif (Rp %s). Saldo sekarang Rp %s.',
                        angka($saldo + $nominal, 0),
                        angka($saldo, 0),
                    ),
                ], 422);
            }

            $mutasi = $this->ledger->recordAdjustment(
                $plafon,
                $nominal,
                $this->bersihkan($validated['notes']),
                $user,
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Saldo kas kecil berhasil disesuaikan dan tercatat di buku mutasi.',
                'data' => ['balance_after' => (float) $mutasi->balance_after],
            ], 200);
        } catch (ModelNotFoundException) {
            DB::rollBack();

            return $this->tidakDitemukan();
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->gagal('adjust', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Dalam
    |--------------------------------------------------------------------------
    */

    private function temukan(string $publicId): PettyCashCeiling
    {
        return PettyCashCeiling::query()
            ->with('branchData:id,nama_cabang')
            ->findOrFail(\Illuminate\Support\Facades\Crypt::decryptString($publicId));
    }

    private function bersihkan(?string $teks): ?string
    {
        $teks = trim((string) $teks);

        if ($teks === '') {
            return null;
        }

        return htmlspecialchars(strip_tags($teks), ENT_QUOTES, 'UTF-8');
    }

    private function tidakDitemukan(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Plafon petty cash tidak ditemukan.',
        ], 404);
    }

    private function gagal(string $aksi, \Throwable $e): JsonResponse
    {
        Log::error('[Petty Cash Ceiling] ' . $aksi . ' error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Proses plafon petty cash gagal.',
            'debug' => app()->environment('local') ? $e->getMessage() : null,
        ], 500);
    }
}
