<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\SpecialDocumentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SpecialDocumentTypeController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Dropdown Tipe Dokumen Khusus
    |--------------------------------------------------------------------------
    | Punya dua mode, karena dipakai dua konteks yang berbeda:
    |
    | 1. Default -- menyaring sesuai department user login.
    |    Dipakai form PR: pembuat dokumen hanya boleh memilih tipe yang memang
    |    diperuntukkan bagi department-nya. Untuk department lain hasilnya
    |    kosong sehingga pilihannya tidak muncul sama sekali.
    |
    | 2. scope=all -- seluruh tipe aktif, tanpa menyaring department.
    |    Dipakai menu Approval Flow: admin mengatur flow milik department LAIN,
    |    jadi menyaring berdasarkan department admin justru membuat daftarnya
    |    kosong dan pengaturan tidak bisa dilakukan.
    |
    | Isinya hanya katalog nama tipe dokumen, bukan data transaksi, sehingga
    | aman ditampilkan penuh pada konteks pengaturan.
    |--------------------------------------------------------------------------
    */
    public function dropdownSelect(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'data' => [],
                ], 401);
            }

            $showAll = strtolower(
                trim((string) $request->query('scope', '')),
            ) === 'all';

            $departmentId = (int) ($user->departemen_id ?? 0);

            $types = SpecialDocumentType::query()
                ->where('is_active', true)
                ->when(
                    !$showAll,
                    function ($query) use ($departmentId) {
                        $query->where(function ($subQuery) use ($departmentId) {
                            $subQuery
                                ->whereNull('department_id')
                                ->orWhere('department_id', $departmentId);
                        });
                    },
                )
                ->orderBy('name')
                ->with('department:id,kode,nama')
                ->get(['id', 'code', 'name', 'description', 'department_id'])
                ->map(function ($type) use ($showAll) {
                    $departmentCode = $type->department?->kode;

                    /*
                    | Kode department hanya disisipkan pada mode scope=all
                    | (menu Approval Flow), karena di sana daftarnya memuat
                    | tipe milik banyak department sekaligus.
                    |
                    | Pada form PR tidak perlu: user hanya melihat tipe milik
                    | department-nya sendiri, jadi nama apa adanya sudah jelas.
                    */
                    $title = $showAll && $departmentCode
                        ? $type->name . ' (' . $departmentCode . ')'
                        : $type->name;

                    return [
                        'id' => $type->id,
                        'value' => $type->id,
                        'code' => $type->code,
                        'name' => $type->name,
                        'description' => $type->description,

                        'department_code' => $departmentCode,

                        'title' => $title,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $types,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Special Document Type] Dropdown error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat Tipe Dokumen Khusus.',
                'data' => [],
            ], 500);
        }
    }
}
