import { computed, ref } from 'vue'
import axios from '@axios'

/*
|--------------------------------------------------------------------------
| Jenis dokumen Approval Flow
|--------------------------------------------------------------------------
| Daftarnya TIDAK ditulis di sini. Sumbernya adalah master pada backend --
| permission_modules yang kolom approval_document_type-nya terisi -- dan
| dibaca lewat GET /master/approval-flows/document-types.
|
| Menambah jenis dokumen baru cukup mengisi kolom approval_document_type,
| approval_document_label, dan approval_uses_area_matrix pada permission
| module terkait. Tidak ada perubahan kode di frontend maupun backend.
|
| Modul ini menyimpan hasilnya di level module (bukan per komponen) supaya
| tiga halaman approval flow berbagi satu kali pemanggilan API.
|--------------------------------------------------------------------------
*/

export interface ApprovalFlowDocumentType {
  /** Kode sebagaimana tersimpan pada approval_flows.document_type. */
  value: string

  label: string
  module_code: string
  module_id: number

  /** Dokumen yang flow-nya dibedakan per area + department. */
  uses_area_matrix: boolean

  /** Dokumen yang flow-nya juga dibedakan per keterangan transaksi (FPU). */
  uses_transaction_category: boolean
}

const documentTypes = ref<ApprovalFlowDocumentType[]>([])

const isLoaded = ref(false)

/*
 * Menahan pemanggilan ganda ketika beberapa komponen memuat bersamaan.
 */
let inflightRequest: Promise<void> | null = null

export const approvalFlowDocumentTypesLoaded = computed(() => isLoaded.value)

export const approvalFlowDocumentTypeList = computed(() => documentTypes.value)

/**
 * Memuat daftar jenis dokumen dari master.
 *
 * @param force Memuat ulang walau sudah pernah berhasil.
 */
export const loadApprovalFlowDocumentTypes = async (
  force = false,
): Promise<void> => {
  if (isLoaded.value && !force)
    return

  if (inflightRequest)
    return inflightRequest

  inflightRequest = (async () => {
    try {
      const response = await axios.get('/master/approval-flows/document-types', {
        headers: { Accept: 'application/json' },
      })

      const data = Array.isArray(response?.data?.data)
        ? response.data.data
        : []

      documentTypes.value = data.map((item: any): ApprovalFlowDocumentType => ({
        value: String(item.value || ''),
        label: String(item.label || item.value || '-'),
        module_code: String(item.module_code || ''),
        module_id: Number(item.module_id || 0),
        uses_area_matrix: Boolean(item.uses_area_matrix),
        uses_transaction_category: Boolean(item.uses_transaction_category),
      }))

      isLoaded.value = true
    }
    finally {
      inflightRequest = null
    }
  })()

  return inflightRequest
}

const findDocumentType = (
  value: unknown,
): ApprovalFlowDocumentType | undefined => {
  const normalized = String(value || '').trim().toUpperCase()

  if (!normalized)
    return undefined

  return documentTypes.value.find(
    item => item.value.toUpperCase() === normalized,
  )
}

/**
 * Mengembalikan kode baku sesuai master.
 *
 * Kode yang belum dikenali dikembalikan apa adanya: daftar mungkin belum
 * selesai dimuat, dan data lama harus tetap tampil alih-alih hilang.
 */
export const normalizeApprovalFlowDocumentType = (value: unknown): string => {
  const rawValue = String(value || '').trim()

  if (!rawValue)
    return ''

  return findDocumentType(rawValue)?.value ?? rawValue
}

export const getApprovalFlowDocumentTypeLabel = (value: unknown): string => {
  const rawValue = String(value || '').trim()

  return findDocumentType(rawValue)?.label || rawValue || '-'
}

export const getApprovalFlowModuleCode = (value: unknown): string => {
  return findDocumentType(value)?.module_code ?? ''
}

/**
 * Apakah jenis dokumen ini dibedakan per area + department.
 */
export const documentTypeUsesAreaMatrix = (value: unknown): boolean => {
  return findDocumentType(value)?.uses_area_matrix ?? false
}

/**
 * Apakah jenis dokumen ini juga dibedakan per keterangan transaksi.
 */
export const documentTypeUsesTransactionCategory = (value: unknown): boolean => {
  return findDocumentType(value)?.uses_transaction_category ?? false
}

/**
 * Bentuk siap pakai untuk VSelect / VAutocomplete.
 */
export const approvalFlowDocumentTypeOptions = computed(() =>
  documentTypes.value.map(item => ({
    title: item.label,
    value: item.value,
  })),
)

/**
 * Jenis dokumen pertama pada master, dipakai sebagai pilihan awal ketika
 * halaman dibuka tanpa parameter document_type.
 */
export const getDefaultApprovalFlowDocumentType = (): string => {
  return documentTypes.value[0]?.value ?? ''
}
