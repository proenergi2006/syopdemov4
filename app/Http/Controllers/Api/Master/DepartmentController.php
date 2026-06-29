<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    /**
     * Menampilkan daftar department dengan filter dan pagination.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->input('per_page', 10);

            if ($perPage <= 0) {
                $perPage = 10;
            }

            if ($perPage > 100) {
                $perPage = 100;
            }

            $search = trim((string) $request->input('search', ''));
            $status = $this->resolveBooleanFilter(
                $request->input('is_active')
            );

            /*
            |--------------------------------------------------------------------------
            | Statistik seluruh department
            |--------------------------------------------------------------------------
            | Tidak terpengaruh pagination dan filter.
            |--------------------------------------------------------------------------
            */
            $summary = Department::query()
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw(
                    'SUM(CASE WHEN is_active = TRUE THEN 1 ELSE 0 END) AS active'
                )
                ->selectRaw(
                    'SUM(CASE WHEN is_active = FALSE THEN 1 ELSE 0 END) AS inactive'
                )
                ->first();

            /*
            |--------------------------------------------------------------------------
            | Query tabel department
            |--------------------------------------------------------------------------
            */
            $query = Department::query();

            if ($search !== '') {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('kode', 'ILIKE', "%{$search}%")
                        ->orWhere('nama', 'ILIKE', "%{$search}%");
                });
            }

            if ($status !== null) {
                $query->where('is_active', $status);
            }

            $departments = $query
                ->orderBy('nama', 'asc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Data department berhasil dimuat.',

                /*
                |--------------------------------------------------------------------------
                | Tetap berupa array agar tidak merusak index lama
                |--------------------------------------------------------------------------
                */
                'data' => $departments->items(),

                'meta' => [
                    'current_page' => $departments->currentPage(),
                    'last_page' => $departments->lastPage(),
                    'per_page' => $departments->perPage(),
                    'total' => $departments->total(),
                    'from' => $departments->firstItem(),
                    'to' => $departments->lastItem(),
                ],

                'summary' => [
                    'total' => (int) ($summary->total ?? 0),
                    'active' => (int) ($summary->active ?? 0),
                    'inactive' => (int) ($summary->inactive ?? 0),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Department] Index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data department.',
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 10,
                    'total' => 0,
                    'from' => null,
                    'to' => null,
                ],
                'summary' => [
                    'total' => 0,
                    'active' => 0,
                    'inactive' => 0,
                ],
            ], 500);
        }
    }

    /**
     * Menampilkan department aktif untuk dropdown.
     */
    public function dropdownSelect(Request $request): JsonResponse
    {
        try {
            $search = trim((string) $request->input('search', ''));

            $query = Department::query()
                ->where('is_active', true)
                ->orderBy('nama', 'asc');

            if ($search !== '') {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery
                        ->where('kode', 'ILIKE', "%{$search}%")
                        ->orWhere('nama', 'ILIKE', "%{$search}%");
                });
            }

            $departments = $query
                ->get()
                ->map(function (Department $department) {
                    return [
                        'id' => $department->id,
                        'value' => $department->id,
                        'title' => $department->nama,
                        'kode' => $department->kode,
                        'nama' => $department->nama,
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'message' => 'Data department berhasil dimuat.',
                'data' => $departments,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Department] Dropdown error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data department.',
                'data' => [],
            ], 500);
        }
    }

    /**
     * Menyimpan department baru.
     */
    public function store(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | Normalisasi input
        |--------------------------------------------------------------------------
        | Kode disimpan uppercase supaya tidak ada variasi IT, it, atau It.
        |--------------------------------------------------------------------------
        */
        $request->merge([
            'kode' => strtoupper(
                trim((string) $request->input('kode', ''))
            ),
            'nama' => trim(
                (string) $request->input('nama', '')
            ),
        ]);

        $validated = $request->validate([
            'kode' => [
                'required',
                'string',
                'max:20',
                Rule::unique('departments', 'kode'),
            ],
            'nama' => [
                'required',
                'string',
                'max:120',
                Rule::unique('departments', 'nama'),
            ],
            'is_active' => [
                'nullable',
                'boolean',
            ],
        ], [
            'kode.required' => 'Kode department wajib diisi.',
            'kode.max' => 'Kode department maksimal 20 karakter.',
            'kode.unique' => 'Kode department sudah digunakan.',

            'nama.required' => 'Nama department wajib diisi.',
            'nama.max' => 'Nama department maksimal 120 karakter.',
            'nama.unique' => 'Nama department sudah digunakan.',

            'is_active.boolean' => 'Status department tidak valid.',
        ]);

        try {
            $department = DB::transaction(function () use ($validated) {
                return Department::create([
                    'kode' => $validated['kode'],
                    'nama' => $validated['nama'],
                    'is_active' => array_key_exists('is_active', $validated)
                        ? (bool) $validated['is_active']
                        : true,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Department berhasil ditambahkan.',
                'data' => $department->fresh(),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[Department] Store error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->except([
                    'password',
                    'password_confirmation',
                ]),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Department gagal ditambahkan.',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Menampilkan detail department.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $department = Department::query()->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Detail department berhasil dimuat.',
                'data' => $department,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Department tidak ditemukan.',
                'data' => null,
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[Department] Show error', [
                'department_id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail department.',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Memperbarui department.
     */
    public function update(
        Request $request,
        string $id
    ): JsonResponse {
        $department = Department::query()->findOrFail($id);

        $request->merge([
            'kode' => strtoupper(
                trim((string) $request->input('kode', ''))
            ),
            'nama' => trim(
                (string) $request->input('nama', '')
            ),
        ]);

        $validated = $request->validate([
            'kode' => [
                'required',
                'string',
                'max:20',
                Rule::unique('departments', 'kode')
                    ->ignore($department->id),
            ],
            'nama' => [
                'required',
                'string',
                'max:120',
                Rule::unique('departments', 'nama')
                    ->ignore($department->id),
            ],
            'is_active' => [
                'nullable',
                'boolean',
            ],
        ], [
            'kode.required' => 'Kode department wajib diisi.',
            'kode.max' => 'Kode department maksimal 20 karakter.',
            'kode.unique' => 'Kode department sudah digunakan.',

            'nama.required' => 'Nama department wajib diisi.',
            'nama.max' => 'Nama department maksimal 120 karakter.',
            'nama.unique' => 'Nama department sudah digunakan.',

            'is_active.boolean' => 'Status department tidak valid.',
        ]);

        try {
            DB::transaction(function () use (
                $department,
                $validated
            ) {
                $department->update([
                    'kode' => $validated['kode'],
                    'nama' => $validated['nama'],
                    'is_active' => array_key_exists(
                        'is_active',
                        $validated
                    )
                        ? (bool) $validated['is_active']
                        : $department->is_active,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Department berhasil diperbarui.',
                'data' => $department->fresh(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[Department] Update error', [
                'department_id' => $department->id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Department gagal diperbarui.',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Menghapus department.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $department = Department::query()->findOrFail($id);

            DB::transaction(function () use ($department) {
                $department->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Department berhasil dihapus.',
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Department tidak ditemukan.',
            ], 404);
        } catch (QueryException $e) {
            /*
            |--------------------------------------------------------------------------
            | PostgreSQL foreign key violation
            |--------------------------------------------------------------------------
            | Kode 23503 berarti department masih digunakan tabel lain.
            |--------------------------------------------------------------------------
            */
            $sqlState = $e->errorInfo[0] ?? $e->getCode();

            if ((string) $sqlState === '23503') {
                return response()->json([
                    'success' => false,
                    'message' => 'Department tidak dapat dihapus karena masih digunakan oleh user, permission, atau transaksi lain. Nonaktifkan department jika sudah tidak digunakan.',
                ], 409);
            }

            Log::error('[Department] Destroy query error', [
                'department_id' => $id,
                'message' => $e->getMessage(),
                'error_info' => $e->errorInfo,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Department gagal dihapus.',
            ], 500);
        } catch (\Throwable $e) {
            Log::error('[Department] Destroy error', [
                'department_id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Department gagal dihapus.',
            ], 500);
        }
    }

    /**
     * Mengubah filter request menjadi boolean.
     */
    private function resolveBooleanFilter(mixed $value): ?bool
    {
        if (
            $value === null
            || $value === ''
            || $value === 'all'
        ) {
            return null;
        }

        return filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
    }
}
