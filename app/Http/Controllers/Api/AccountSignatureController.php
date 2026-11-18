<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserSignature;
use App\Services\Account\SignatureImageService;
use App\Services\Account\UserSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pengelolaan tanda tangan digital milik user yang sedang masuk.
 *
 * Tidak ada permission yang diperiksa di sini: ini akun miliknya sendiri,
 * sebagaimana ubah password. Setiap query dibatasi ke user_id miliknya oleh
 * UserSignatureService, jadi id dari request tidak pernah bisa menjangkau
 * tanda tangan orang lain.
 */
class AccountSignatureController extends Controller
{
    public function __construct(
        private readonly UserSignatureService $slot,
        private readonly SignatureImageService $gambar,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->belumMasuk();
            }

            return response()->json([
                'success' => true,
                'message' => __('signature_messages.loaded'),
                'data' => $this->ringkasan($request),
            ], 200);
        } catch (\Throwable $e) {
            return $this->kacau($e, 'Index', 'load_failed');
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->belumMasuk();
            }

            $validated = $request->validate(
                $this->aturan(),
                $this->kalimat(),
            );

            if (($validated['mode'] ?? '') === UserSignature::SOURCE_UPLOAD) {
                $this->slot->tambahDariPng(
                    $user,
                    $this->gambar->dariUnggahan($request->file('file')),
                    $validated['label'] ?? null,
                );
            } else {
                $this->slot->tambahDariDataUrl(
                    $user,
                    (string) $validated['signature'],
                    $validated['label'] ?? null,
                );
            }

            return response()->json([
                'success' => true,
                'message' => __('signature_messages.created'),
                'data' => $this->ringkasan($request),
            ], 201);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->kacau($e, 'Store', 'create_failed');
        }
    }

    /**
     * Membersihkan foto tanpa menyimpannya, supaya user melihat hasilnya
     * lebih dulu. Foto kertas sulit diduga hasilnya; memaksa user menyimpan
     * dulu baru menilai berarti ia memakai slotnya untuk mencoba-coba.
     */
    public function preview(Request $request): JsonResponse
    {
        try {
            if (!$request->user()) {
                return $this->belumMasuk();
            }

            $request->validate([
                'file' => [
                    'required',
                    'file',
                    'image',
                    'mimes:jpg,jpeg,png,webp',
                    'max:8192',
                ],
            ], $this->kalimat());

            $png = $this->gambar->dariUnggahan($request->file('file'));

            return response()->json([
                'success' => true,
                'data' => [
                    'preview' => 'data:image/png;base64,' . base64_encode($png),
                ],
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->kacau($e, 'Preview', 'create_failed');
        }
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->belumMasuk();
            }

            $this->slot->aktifkan($user, $id);

            return response()->json([
                'success' => true,
                'message' => __('signature_messages.activated'),
                'data' => $this->ringkasan($request),
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->kacau($e, 'Activate', 'activate_failed');
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->belumMasuk();
            }

            $validated = $request->validate([
                'label' => ['required', 'string', 'max:60'],
            ], $this->kalimat());

            $this->slot->ubahLabel($user, $id, $validated['label']);

            return response()->json([
                'success' => true,
                'message' => __('signature_messages.label_updated'),
                'data' => $this->ringkasan($request),
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->kacau($e, 'Update', 'label_update_failed');
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return $this->belumMasuk();
            }

            $this->slot->hapus($user, $id);

            return response()->json([
                'success' => true,
                'message' => __('signature_messages.deleted'),
                'data' => $this->ringkasan($request),
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->kacau($e, 'Destroy', 'delete_failed');
        }
    }

    /* ================================================================== */
    /* Bagian dalam                                                       */
    /* ================================================================== */

    /**
     * @return array<string, mixed>
     */
    private function ringkasan(Request $request): array
    {
        /* Dibaca ulang dari basis data: signature_path baru saja berubah. */
        $user = $request->user()->fresh();

        $daftar = $this->slot->daftar($user);

        return [
            'signatures' => $daftar,
            'max' => UserSignature::MAKS_PER_USER,
            'remaining' => $this->slot->sisaSlot($user),
            'has_signature' => !empty($user->signature_path),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function aturan(): array
    {
        return [
            'mode' => [
                'required',
                Rule::in([
                    UserSignature::SOURCE_DRAW,
                    UserSignature::SOURCE_UPLOAD,
                ]),
            ],

            'label' => ['nullable', 'string', 'max:60'],

            'agree' => ['accepted'],

            'signature' => [
                'required_if:mode,' . UserSignature::SOURCE_DRAW,
                'nullable',
                'string',
            ],

            'file' => [
                'required_if:mode,' . UserSignature::SOURCE_UPLOAD,
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:8192',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function kalimat(): array
    {
        return [
            'mode.required' => __('signature_messages.source_required'),
            'mode.in' => __('signature_messages.source_required'),
            'signature.required_if' => __('signature_messages.source_required'),
            'file.required' => __('signature_messages.source_required'),
            'file.required_if' => __('signature_messages.source_required'),
            'file.image' => __('signature_messages.file_invalid'),
            'file.mimes' => __('signature_messages.file_invalid'),
            'file.max' => __('signature_messages.file_too_large'),
            'agree.accepted' => __('signature_messages.agree_required'),
            'label.required' => __('signature_messages.label_required'),
        ];
    }

    private function belumMasuk(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('account_messages.user_not_authenticated'),
        ], 401);
    }

    private function kacau(\Throwable $e, string $aksi, string $kunci): JsonResponse
    {
        Log::error('[Tanda Tangan] ' . $aksi . ' error', [
            'user_id' => auth()->id(),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return response()->json([
            'success' => false,
            'message' => __('signature_messages.' . $kunci),
        ], 500);
    }
}
