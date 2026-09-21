<?php

namespace App\Services\ApprovalFlow;

use App\Models\PermissionModule;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Daftar jenis dokumen Approval Flow
|--------------------------------------------------------------------------
| Sumber datanya adalah permission_modules yang kolom approval_document_type
| -nya terisi. Tidak ada daftar jenis dokumen yang ditulis di kode.
|
| Menambah jenis dokumen baru cukup mengisi approval_document_type,
| approval_document_label, dan approval_uses_area_matrix pada permission
| module terkait -- tanpa mengubah controller maupun frontend.
|--------------------------------------------------------------------------
*/
class ApprovalDocumentTypeService
{
    /**
     * Cache satu request. Daftar ini dibaca berkali-kali dalam sekali
     * pemanggilan API (normalisasi, label, module, aturan area).
     *
     * @var Collection<int, PermissionModule>|null
     */
    private ?Collection $cache = null;

    /**
     * Seluruh jenis dokumen yang aktif, terurut seperti daftar module.
     *
     * @return Collection<int, PermissionModule>
     */
    public function all(): Collection
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        return $this->cache = PermissionModule::query()
            ->approvalDocumentType()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Membuang hasil cache. Dipakai setelah daftar module berubah.
     */
    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * Mencari module berdasarkan kode jenis dokumen, tanpa membedakan
     * huruf besar/kecil -- 'vendor', 'Vendor', dan 'VENDOR' sama saja.
     */
    public function find(?string $documentType): ?PermissionModule
    {
        $needle = strtoupper(trim((string) $documentType));

        if ($needle === '') {
            return null;
        }

        return $this->all()->first(
            fn(PermissionModule $module) => strtoupper(
                trim((string) $module->approval_document_type),
            ) === $needle,
        );
    }

    /**
     * Mengembalikan kode baku sebagaimana tersimpan pada master.
     *
     * @throws ValidationException bila jenis dokumen tidak terdaftar.
     */
    public function normalize(?string $documentType): string
    {
        $module = $this->find($documentType);

        if (!$module) {
            throw ValidationException::withMessages([
                'document_type' => [
                    'Jenis dokumen approval flow tidak valid.',
                ],
            ]);
        }

        return (string) $module->approval_document_type;
    }

    /**
     * Label tampilan. Kode yang tidak terdaftar dikembalikan apa adanya
     * supaya data lama tetap terbaca, bukan berubah menjadi kosong.
     */
    public function label(?string $documentType): string
    {
        $module = $this->find($documentType);

        if ($module) {
            return (string) (
                $module->approval_document_label
                ?: $module->name
            );
        }

        return trim((string) $documentType) ?: '-';
    }

    /**
     * Code permission_modules untuk jenis dokumen tersebut.
     *
     * @throws ValidationException bila jenis dokumen belum dipetakan.
     */
    public function permissionModuleCode(?string $documentType): string
    {
        $module = $this->find($documentType);

        if (!$module) {
            throw ValidationException::withMessages([
                'permission_module_id' => [
                    'Module untuk jenis dokumen tersebut belum dikonfigurasi.',
                ],
            ]);
        }

        return (string) $module->code;
    }

    /**
     * Apakah flow jenis dokumen ini dibedakan per area + department.
     *
     * Dokumen seperti ini (PR, FPU) wajib mengisi Area dan Department, dan
     * daftar step-nya tidak boleh digabung antar-flow karena tiap flow
     * berdiri sendiri.
     */
    public function usesAreaMatrix(?string $documentType): bool
    {
        return (bool) $this->find($documentType)?->approval_uses_area_matrix;
    }

    /**
     * Apakah flow jenis dokumen ini juga dibedakan per keterangan transaksi.
     *
     * Berlaku untuk FPU. Penentunya kolom
     * permission_modules.approval_uses_transaction_category, bukan pemeriksaan
     * jenis dokumen di dalam kode.
     */
    public function usesTransactionCategory(?string $documentType): bool
    {
        return (bool) $this->find($documentType)?->approval_uses_transaction_category;
    }

    /**
     * Bentuk ringkas untuk dropdown di frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toOptions(): array
    {
        return $this->all()
            ->map(fn(PermissionModule $module) => [
                'value' => (string) $module->approval_document_type,
                'label' => (string) (
                    $module->approval_document_label
                    ?: $module->name
                ),
                'module_code' => (string) $module->code,
                'module_id' => (int) $module->id,
                'uses_area_matrix' => (bool) $module->approval_uses_area_matrix,
                'uses_transaction_category'
                => (bool) $module->approval_uses_transaction_category,
            ])
            ->values()
            ->all();
    }
}
