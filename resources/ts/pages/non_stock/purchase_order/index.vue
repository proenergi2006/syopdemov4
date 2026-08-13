<script setup lang="ts">
import { computed, onMounted, ref, watch, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import SignaturePad from 'signature_pad'

import {
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
  showErrorToast,
  closeAlert,
  showConfirmAlert,
} from '@/utils/alert'
import { useNavigationStore } from '@/stores/navigation'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'
import { useDeleteConfirm } from '@core/composable/useDeleteConfirm'
import { formatDate, formatStatusPKP, formatNumberWithoutRp, toTitleCase, formatDecimalQty } from '@/utils/textFormatter'
import { usePolling } from '@core/composable/usePolling'
import ApprovalHistoryDialog from '@core/components/ApprovalHistoryPODialog.vue'
import {
  defaultModuleAbilities,
  normalizeModuleAbilities,
  type ModuleAbilities,
} from '@/types/abilities'
import { usePermissionStore } from '@/stores/permission'

interface ApprovalHistoryItem {
  id?: number
  step_order: number | string
  label?: string | null
  approver_type?: string | null
  approver_id?: number | string | null
  approver_name_snapshot?: string | null
  status?: string | null
  approved_at?: string | null
  rejected_at?: string | null
  signed_at?: string | null
  notes?: string | null
}

interface PurchaseOrderItem {
  id: number
  public_id: string
  nomor_po: string | null
  tanggal_po: string | null
  vendor: string | null
  cabang: string | null
  department: string | null
  jenis_pembayaran: string | null
  top: number | null
  total_nilai: number | null
  status: string | null
  can_approve?: boolean
  can_update?: boolean
  can_delete?: boolean
  can_submit?: boolean
  is_owner?: boolean
  status_receive: string | null
  approved_at: string | null
}

interface PurchaseOrderApiResponse {
  success?: boolean
  status?: boolean
  data?: PurchaseOrderItem[]
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
  abilities?: ModuleAbilities
}

interface AxiosErrorShape {
  response?: {
    status?: number
    data?: {
      message?: string
      errors?: Record<string, string[]>
    }
  }
}

const permissionStore = usePermissionStore()

const navigationStore = useNavigationStore()

const canView = computed(() => {
  return permissionStore.can('purchase_order.view')
})

const canCreate = computed(() => {
  return permissionStore.can('purchase_order.create')
})

const canUpdate = computed(() => {
  return permissionStore.can('purchase_order.update')
})

const canDelete = computed(() => {
  return permissionStore.can('purchase_order.delete')
})

/*
 * Permission tersendiri, tanpa scope. Isi file tetap mengikuti visibility
 * daftar, jadi permission ini hanya menentukan boleh-tidaknya menarik data
 * keluar -- bukan data siapa yang terlihat.
 */
const canExport = computed(() => {
  return permissionStore.can('purchase_order.export')
})

const canCancelPO = computed(() => {
  return permissionStore.can('purchase_order.cancel')
})

const isCheckingPermission = ref(true)

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()

const loading = ref(false)
const rows = ref<PurchaseOrderItem[]>([])

// Signature Pad
const signatureDialog = ref(false)
const signatureCanvasRef = ref<HTMLCanvasElement | null>(null)
const signaturePad = ref<any>(null)
const signatureAgree = ref(false)
const signatureError = ref('')
const signatureLoading = ref(false)
const submitLoading = ref(false)
const approveLoading = ref(false)
const approveNotes = ref('')
const approveDialog = ref(false)

// Reject
const rejectDialog = ref(false)
const rejectTarget = ref<any>(null)
const rejectNotes = ref('')
const rejectLoading = ref(false)

// Cancel
const cancelDialog = ref(false)
const cancelTarget = ref<any>(null)
const cancelNotes = ref('')
const cancelLoading = ref(false)

const pendingAction = ref<'submit' | 'approve' | null>(null)
const selectedPo = ref<any>(null)

type PrintLanguage = 'id' | 'en'
const printLanguageDialog = ref(false)
const selectedPrintPublicId = ref<string | null>(null)
const printLoadingId = ref<string | null>(null)

const searchQuery = ref('')
const selectedStatus = ref('')
const onlyWaitingMyApproval = ref(false)

const tanggalMulai = ref<string | null>(null)
const tanggalSelesai = ref<string | null>(null)

const tanggalMulaiPicker = useNativeDatePicker(tanggalMulai)
const tanggalSelesaiPicker = useNativeDatePicker(tanggalSelesai)

const rowPerPage = ref(10)
const currentPage = ref(1)
const totalData = ref(0)
const totalPage = ref(1)

const loadError = ref(false)

const detailDialog = ref(false)
const detailError = ref('')
const detailPurchaseOrder = ref<any | null>(null)
const detailPurchaseOrderPublicId = ref<string | null>(null)
const visiblePrCount = ref(5)
const detailItemPage = ref(1)
const detailItemPerPage = ref<number | 'ALL'>(10)

const currentUser = ref<any | null>(null)

const isApprovalHistoryDialogOpen = ref(false)
const selectedApprovalHistory = ref<ApprovalHistoryItem[]>([])
const selectedPONomor = ref('-')

const abilities = ref<ModuleAbilities>(
  defaultModuleAbilities(),
)

const canApprovePO = (row: PurchaseOrderItem): boolean => {
  const status = String(row.status || '').toUpperCase()

  return status === 'IN PROGRESS'
    && row.can_approve === true
}

const openApprovalHistory = async (item: any): Promise<void> => {
  try {
    showLoadingAlert(t('purchaseOrder.list.toast.historyLoading'), t('common.alert.pleaseWait'))

    const res = await axios.get(`/transaction/purchase-order/${encodeURIComponent(item.public_id)}`, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    const data = res.data?.data ?? {}

    selectedPONomor.value = data.nomor_po ?? item.nomor_po ?? '-'
    selectedApprovalHistory.value = Array.isArray(data.approvals)
      ? data.approvals
      : []

    isApprovalHistoryDialogOpen.value = true
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('purchaseOrder.list.toast.historyLoadFailed')),
    })
  }
}

const visibleRelatedPurchaseRequests = computed(() => {
  const list = detailPurchaseOrder.value?.purchase_requests || []

  return list.slice(0, visiblePrCount.value)
})

const hasMoreRelatedPurchaseRequests = computed(() => {
  const list = detailPurchaseOrder.value?.purchase_requests || []

  return visiblePrCount.value < list.length
})

const showMoreRelatedPurchaseRequests = (): void => {
  visiblePrCount.value += 5
}

const detailItemPerPageItems = [
  { title: '10', value: 10 },
  { title: '20', value: 20 },
  { title: '50', value: 50 },
  { title: 'All', value: 'ALL' },
]

const paginatedDetailItems = computed(() => {
  const items = detailItems.value || []

  if (detailItemPerPage.value === 'ALL') return items

  const start = (detailItemPage.value - 1) * Number(detailItemPerPage.value)
  const end = start + Number(detailItemPerPage.value)

  return items.slice(start, end)
})

const detailItemTotalPage = computed(() => {
  const items = detailItems.value || []

  if (detailItemPerPage.value === 'ALL') return 1

  return Math.ceil(items.length / Number(detailItemPerPage.value)) || 1
})

const detailItems = computed(() => detailPurchaseOrder.value?.items || [])


type SimpleApprovalHistory = {
  id: number | string
  step_order: number
  label: string
  status: string
  processed_by: string
  processed_at: string | null
  notes: string | null
}

const detailApprovalHistories = computed<SimpleApprovalHistory[]>(() => {
  const detail = detailPurchaseOrder.value as any

  const rawHistories =
    detail?.approvals
    ?? detail?.approval_histories
    ?? detail?.approvalHistories
    ?? detail?.approval_history
    ?? detail?.approvalHistory
    ?? []

  if (!Array.isArray(rawHistories))
    return []

  return rawHistories
    .filter((item: any) => {
      const status = String(
        item.status
        ?? item.result
        ?? item.action
        ?? item.approval_status
        ?? '',
      ).toUpperCase()

      /*
      |--------------------------------------------------------------------------
      | Tampilkan juga tahap yang sedang WAITING
      |--------------------------------------------------------------------------
      | Supaya posisi approval saat ini tetap terlihat di ringkasan singkat,
      | bukan hanya tahap yang sudah selesai diproses (APPROVED/REJECTED).
      |--------------------------------------------------------------------------
      */
      return ['APPROVED', 'APPROVE', 'REJECTED', 'REJECT', 'WAITING'].includes(status)
    })
    .map((item: any, index: number): SimpleApprovalHistory => {
      const stepOrder = Number(
        item.step_order
        ?? item.approval_step_order
        ?? item.step
        ?? index + 1,
      )

      const status = String(
        item.status
        ?? item.result
        ?? item.action
        ?? item.approval_status
        ?? '',
      ).toUpperCase()

      const isProcessed = ['APPROVED', 'APPROVE', 'REJECTED', 'REJECT'].includes(status)

      return {
        id: item.id ?? `${stepOrder}-${index}`,
        step_order: stepOrder,
        label:
          item.label
          ?? item.approval_label
          ?? item.position
          ?? item.role_name
          ?? item.role
          ?? item.title
          ?? `Tahap ${stepOrder}`,
        status,
        /*
        |--------------------------------------------------------------------------
        | Diproses Oleh
        |--------------------------------------------------------------------------
        | Hanya tampilkan nama untuk tahap yang benar-benar sudah diproses.
        | Tahap WAITING belum diproses siapapun, walaupun nama calon approver
        | sudah tersimpan sebagai snapshot.
        |--------------------------------------------------------------------------
        */
        processed_by: isProcessed
          ? (
              item.processed_by_name
              ?? item.approved_by_name
              ?? item.rejected_by_name
              ?? item.user_name
              ?? item.approver_name
              ?? item.approver_name_snapshot
              ?? item.processor_name
              ?? '-'
            )
          : '-',
        processed_at:
          item.processed_at
          ?? item.approved_at
          ?? item.rejected_at
          ?? item.signed_at
          ?? item.updated_at
          ?? item.created_at
          ?? null,
        notes:
          item.notes
          ?? item.remark
          ?? item.keterangan
          ?? null,
      }
    })
    .sort((a, b) => {
      if (a.step_order !== b.step_order)
        return a.step_order - b.step_order

      return String(a.id).localeCompare(String(b.id))
    })
})

const getSimpleApprovalStatusLabel = (status: string): string => {
  const normalized = String(status || '').toUpperCase()

  if (['APPROVED', 'APPROVE'].includes(normalized))
    return 'Approved'

  if (['REJECTED', 'REJECT'].includes(normalized))
    return 'Rejected'

  if (['PENDING', 'WAITING', 'WAITING_APPROVAL'].includes(normalized))
    return 'Pending'

  if (['SKIPPED', 'SKIP'].includes(normalized))
    return 'Skipped'

  if (!normalized)
    return '-'

  return normalized
    .replaceAll('_', ' ')
    .toLowerCase()
    .replace(/\b\w/g, char => char.toUpperCase())
}

const getSimpleApprovalStatusColor = (status: string): string => {
  const normalized = String(status || '').toUpperCase()

  if (['APPROVED', 'APPROVE'].includes(normalized))
    return 'success'

  if (['REJECTED', 'REJECT'].includes(normalized))
    return 'error'

  if (['PENDING', 'WAITING', 'WAITING_APPROVAL'].includes(normalized))
    return 'warning'

  if (['SKIPPED', 'SKIP'].includes(normalized))
    return 'secondary'

  return 'primary'
}

const getSimpleApprovalStatusIcon = (status: string): string => {
  const normalized = String(status || '').toUpperCase()

  if (['APPROVED', 'APPROVE'].includes(normalized))
    return 'tabler-circle-check'

  if (['REJECTED', 'REJECT'].includes(normalized))
    return 'tabler-circle-x'

  if (['PENDING', 'WAITING', 'WAITING_APPROVAL'].includes(normalized))
    return 'tabler-clock'

  if (['SKIPPED', 'SKIP'].includes(normalized))
    return 'tabler-player-skip-forward'

  return 'tabler-circle-dot'
}

const statusItems = computed(() => [
  { title: t('purchaseOrder.list.filters.statusAll'), value: '' },
  { title: t('purchaseOrder.list.filters.statusDraft'), value: 'Draft' },
  { title: t('purchaseOrder.list.filters.statusInProgress'), value: 'In Progress' },
  { title: t('purchaseOrder.list.filters.statusApproved'), value: 'Approved' },
  { title: t('purchaseOrder.list.filters.statusRejected'), value: 'Rejected' },
  { title: t('purchaseOrder.list.filters.statusCancelled'), value: 'Cancelled' },
])

const paginationData = computed(() => {
  if (!totalData.value) return '0-0 of 0'

  const firstIndex = (currentPage.value - 1) * rowPerPage.value + 1
  const lastIndex = Math.min(currentPage.value * rowPerPage.value, totalData.value)

  return `${firstIndex}-${lastIndex} of ${totalData.value}`
})

const formatStatus = (status: string | null): string => {
  if (!status) return '-'

  const normalized = String(status).toLowerCase()

  if (normalized === 'draft') return 'Draft'
  if (normalized === 'in progress') return 'In Progress'
  if (normalized === 'approved') return 'Approved'
  if (normalized === 'rejected') return 'Rejected'
  if (normalized === 'cancelled') return 'Cancelled'

  return status
}

const formatStatusReceive = (status: string | null): string => {
  if (!status) return '-'

  const normalized = String(status).toLowerCase()

  if (normalized === 'open') return 'Open'
  if (normalized === 'partial') return 'Partial'
  if (normalized === 'completed') return 'Completed'

  return status
}

const getStatusColor = (status: string | null): string => {
  const normalized = String(status || '').toLowerCase()

  if (normalized === 'draft') return 'secondary'
  if (normalized === 'in progress') return 'warning'
  if (normalized === 'approved') return 'success'
  if (normalized === 'rejected') return 'error'
  if (normalized === 'cancelled') return 'error'

  return 'secondary'
}

const getStatusReceiveColor = (status: string | null): string => {
  const normalized = String(status || '').toLowerCase()

  if (normalized === 'open') return 'info'
  if (normalized === 'partial') return 'warning'
  if (normalized === 'completed') return 'success'

  return 'secondary'
}

const formatCurrency = (value: number | null): string => {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
  }).format(Number(value || 0))
}

const loadCurrentUser = async (): Promise<void> => {
  try {
    const res = await axios.get('/auth/me', {
      headers: { Accept: 'application/json' },
    })

    currentUser.value = res.data?.data || null

    // console.log('CURRENT USER', currentUser.value)
  } catch (error) {
    // console.error('[AUTH] Failed load current user', error)
    currentUser.value = null
  }
}

const handlePurchaseOrderRefresh = async (): Promise<void> => {
  await fetchPurchaseOrders()
}

const fetchPurchaseOrders = async (): Promise<void> => {
  loading.value = true
  loadError.value = false

  try {
    const response = await axios.get<PurchaseOrderApiResponse>(
      '/transaction/purchase-order',
      {
        headers: {
          Accept: 'application/json',
        },
        params: {
          search: searchQuery.value || undefined,
          status: selectedStatus.value || undefined,
          tanggal_mulai: tanggalMulai.value || undefined,
          tanggal_selesai: tanggalSelesai.value || undefined,
          waiting_my_approval: onlyWaitingMyApproval.value ? 1 : undefined,
          page: currentPage.value,
          per_page: rowPerPage.value,
        },
      },
    )

    const responseData = response.data

    rows.value = Array.isArray(responseData?.data)
      ? responseData.data
      : []

    abilities.value = normalizeModuleAbilities(
      responseData?.abilities,
    )

    const meta = responseData?.meta

    totalData.value = Number(meta?.total ?? rows.value.length ?? 0)
    totalPage.value = Number(meta?.last_page ?? 1)
    currentPage.value = Number(meta?.current_page ?? 1)
  } catch (error: unknown) {
    const err = error as AxiosErrorShape
    const status = err.response?.status

    /*
    * 401 berarti token tidak ada atau sudah kedaluwarsa.
    * Jangan tampilkan toast Unauthenticated karena user
    * sudah diarahkan kembali ke halaman login.
    */
    if (status === 401) {
      rows.value = []
      totalData.value = 0
      totalPage.value = 1

      return
    }

    loadError.value = true

    console.error(
      '[Purchase Order] FETCH ERROR:',
      err,
    )

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(
        err,
        t('purchaseOrder.list.toast.loadFailed'),
      ),
    })

    rows.value = []
    totalData.value = 0
    totalPage.value = 1
  } finally {
    loading.value = false
  }
}

// UsePolling
usePolling(fetchPurchaseOrders, {
  interval: 30000,
})

const calcPOTotal = (items: any[] = []) => {
  return items.reduce((total, item) => total + Number(item.subtotal || 0), 0)
}

const checkUserSignature = async (): Promise<boolean> => {
  const response = await axios.get('/master/user/check-signature', {
    headers: { Accept: 'application/json' },
  })

  return response.data?.has_signature === true
}

const openRejectPO = (po: any): void => {
  rejectTarget.value = po
  rejectNotes.value = ''
  rejectDialog.value = true
}

const rejectPurchaseOrder = async (): Promise<void> => {
  if (!rejectTarget.value || rejectLoading.value) return

  const target = { ...rejectTarget.value }
  const notes = rejectNotes.value || null

  // tutup modal notes dulu supaya SweetAlert tidak ketutup
  rejectDialog.value = false

  await nextTick()

  const confirm = await showConfirmAlert({
    title: t('purchaseOrder.list.toast.rejectConfirmTitle'),
    text: t('purchaseOrder.list.toast.rejectConfirmText', { nomor: target.nomor_po }),
    confirmButtonText: t('purchaseOrder.list.toast.rejectConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed) {
    // kalau batal, buka lagi modal notes agar catatan tidak hilang
    rejectDialog.value = true
    return
  }

  rejectLoading.value = true

  try {
    showLoadingAlert(t('purchaseOrder.list.toast.rejectLoading'), t('common.alert.pleaseWait'))

    const response = await axios.patch(`/transaction/purchase-order/${target.public_id}/reject`, {
      notes,
    }, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    rejectNotes.value = ''
    rejectTarget.value = null

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('purchaseOrder.list.toast.rejectSuccessFallback', { nomor: target.nomor_po }),
    })

    await fetchPurchaseOrders()
    await navigationStore.refreshBadges()

    // refresh modal detail jika sedang terbuka untuk PO ini
    if (detailDialog.value && detailPurchaseOrderPublicId.value === target.public_id)
      await openDetail(target.public_id)
  } catch (error: unknown) {
    closeAlert()

    // kalau gagal, modal notes dibuka lagi supaya user bisa koreksi/ulang
    rejectDialog.value = true

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('purchaseOrder.list.toast.rejectFailedFallback')),
    })
  } finally {
    rejectLoading.value = false
  }
}

const openCancelPO = (po: any): void => {
  cancelTarget.value = po
  cancelNotes.value = ''
  cancelDialog.value = true
}

const cancelPurchaseOrder = async (): Promise<void> => {
  if (!cancelTarget.value || cancelLoading.value) return

  if (!cancelNotes.value.trim()) {
    showErrorToast({
      title: t('common.alert.error'),
      text: t('purchaseOrder.list.toast.cancelNotesRequired'),
    })

    return
  }

  const target = { ...cancelTarget.value }
  const notes = cancelNotes.value.trim()

  // tutup modal notes dulu supaya SweetAlert tidak ketutup
  cancelDialog.value = false

  await nextTick()

  const confirm = await showConfirmAlert({
    title: t('purchaseOrder.list.toast.cancelConfirmTitle'),
    text: t('purchaseOrder.list.toast.cancelConfirmText', { nomor: target.nomor_po }),
    confirmButtonText: t('purchaseOrder.list.toast.cancelConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
    icon: 'warning',
  })

  if (!confirm.isConfirmed) {
    // kalau batal, buka lagi modal notes agar catatan tidak hilang
    cancelDialog.value = true
    return
  }

  cancelLoading.value = true

  try {
    showLoadingAlert(t('purchaseOrder.list.toast.cancelLoading'), t('common.alert.pleaseWait'))

    const response = await axios.patch(`/transaction/purchase-order/${target.public_id}/cancel`, {
      cancel_notes: notes,
    }, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    cancelNotes.value = ''
    cancelTarget.value = null

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('purchaseOrder.list.toast.cancelSuccessFallback', { nomor: target.nomor_po }),
    })

    await fetchPurchaseOrders()
    await navigationStore.refreshBadges()

    // refresh modal detail jika sedang terbuka untuk PO ini
    if (detailDialog.value && detailPurchaseOrderPublicId.value === target.public_id)
      await openDetail(target.public_id)
  } catch (error: unknown) {
    closeAlert()

    // kalau gagal, modal notes dibuka lagi supaya user bisa koreksi/ulang
    cancelDialog.value = true

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('purchaseOrder.list.toast.cancelFailedFallback')),
    })
  } finally {
    cancelLoading.value = false
  }
}

const openSubmitPO = async (po: any): Promise<void> => {
  selectedPo.value = po
  pendingAction.value = 'submit'

  const hasSignature = await checkUserSignature()

  if (!hasSignature) {
    openSignatureDialog()
    return
  }

  await submitPurchaseOrder()
}

const openApprovePO = async (
  po: any,
): Promise<void> => {
  selectedPo.value = po
  pendingAction.value = 'approve'

  try {
    const hasSignature
      = await checkUserSignature()

    if (!hasSignature) {
      await openSignatureDialog()

      return
    }

    /*
    |--------------------------------------------------------------------------
    | Tanda tangan sudah tersedia
    |--------------------------------------------------------------------------
    | Buka dialog catatan dan konfirmasi.
    | Jangan langsung menjalankan approve.
    |--------------------------------------------------------------------------
    */
    showApprovePODialog()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(
        error,
        t('purchaseOrder.list.toast.signatureCheckFailed'),
      ),
    })
  }
}

const openSignatureDialog = async (): Promise<void> => {
  signatureError.value = ''
  signatureAgree.value = false
  signatureDialog.value = true

  await nextTick()

  setTimeout(() => {
    initSignaturePad()
  }, 300)
}

const resizeSignatureCanvas = (): void => {
  const canvas = signatureCanvasRef.value
  if (!canvas) return

  const ratio = Math.max(window.devicePixelRatio || 1, 1)
  const rect = canvas.getBoundingClientRect()

  canvas.width = rect.width * ratio
  canvas.height = rect.height * ratio

  const context = canvas.getContext('2d')
  if (!context) return

  context.setTransform(ratio, 0, 0, ratio, 0, 0)

  signaturePad.value?.clear()
}

const initSignaturePad = (): void => {
  const canvas = signatureCanvasRef.value
  if (!canvas) return

  const rect = canvas.getBoundingClientRect()

  if (!rect.width || !rect.height) {
    setTimeout(initSignaturePad, 200)
    return
  }

  signaturePad.value = new SignaturePad(canvas, {
    minWidth: 0.8,
    maxWidth: 2.4,
    throttle: 16,
    penColor: 'black',
    backgroundColor: 'rgba(255,255,255,0)',
  })

  resizeSignatureCanvas()
}

const saveSignatureAndContinue = async (): Promise<void> => {
  if (!signaturePad.value || signaturePad.value.isEmpty()) {
    signatureError.value = 'Tanda tangan wajib diisi.'
    return
  }

  if (!signatureAgree.value) {
    signatureError.value = 'Anda wajib menyetujui penggunaan tanda tangan digital.'
    return
  }

  try {
    signatureLoading.value = true

    const signature = signaturePad.value.toDataURL('image/png')

    await axios.post('/master/user/store-signature', {
      signature,
    }, {
      headers: { Accept: 'application/json' },
    })

    signatureDialog.value = false

    if (pendingAction.value === 'submit') {
      await submitPurchaseOrder()
    }

    if (pendingAction.value === 'approve') {
      showApprovePODialog()

      return
    }
  } catch (error) {
    console.error(error)
    signatureError.value = 'Gagal menyimpan tanda tangan digital.'
  } finally {
    signatureLoading.value = false
  }
}

const openPrintLanguageDialog = (
  publicId: string,
): void => {
  if (
    !publicId
    || printLoadingId.value
  ) {
    return
  }

  selectedPrintPublicId.value = publicId
  printLanguageDialog.value = true
}

const closePrintLanguageDialog = (): void => {
  if (printLoadingId.value)
    return

  printLanguageDialog.value = false
  selectedPrintPublicId.value = null
}

const printPurchaseOrder = async (
  language: PrintLanguage,
): Promise<void> => {
  const publicId = selectedPrintPublicId.value

  if (!publicId || printLoadingId.value)
    return

  printLoadingId.value = publicId
  printLanguageDialog.value = false

  const loadingTitle = language === 'en'
    ? 'Opening Purchase Order print...'
    : 'Membuka cetakan Purchase Order...'

  const loadingText = language === 'en'
    ? 'Please wait a moment'
    : 'Mohon tunggu sebentar'

  let printWindow: Window | null = null

  try {
    showLoadingAlert(
      loadingTitle,
      loadingText,
    )

    /*
    |--------------------------------------------------------------------------
    | Buka tab sebelum proses async agar tidak diblokir popup browser.
    |--------------------------------------------------------------------------
    */
    printWindow = window.open('', '_blank')

    if (!printWindow) {
      throw new Error(
        language === 'en'
          ? 'Popup was blocked. Please allow popups for this site.'
          : 'Popup diblokir browser. Mohon izinkan popup untuk situs ini.',
      )
    }

    /*
    |--------------------------------------------------------------------------
    | Tampilkan halaman loading sementara.
    |--------------------------------------------------------------------------
    */
    printWindow.document.open()

    printWindow.document.write(`
      <!DOCTYPE html>
      <html lang="${language}">
        <head>
          <meta charset="UTF-8">
          <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
          >

          <title>${loadingTitle}</title>

          <style>
            body {
              margin: 0;
              min-height: 100vh;
              display: flex;
              align-items: center;
              justify-content: center;
              font-family: Arial, sans-serif;
              background: #f7f7f7;
              color: #333;
            }

            .box {
              padding: 24px;
              text-align: center;
            }

            .spinner {
              width: 42px;
              height: 42px;
              margin: 0 auto 18px;
              border: 4px solid #ddd;
              border-top-color: #2563eb;
              border-radius: 50%;
              animation: spin 1s linear infinite;
            }

            @keyframes spin {
              to {
                transform: rotate(360deg);
              }
            }

            h3 {
              margin: 0 0 8px;
              font-size: 18px;
            }

            p {
              margin: 0;
              font-size: 14px;
              color: #666;
            }
          </style>
        </head>

        <body>
          <div class="box">
            <div class="spinner"></div>
            <h3>${loadingTitle}</h3>
            <p>${loadingText}</p>
          </div>
        </body>
      </html>
    `)

    printWindow.document.close()

    /*
    |--------------------------------------------------------------------------
    | Ambil signed URL dari backend.
    |
    | Request ini hanya mengambil URL kecil, bukan file PDF.
    |--------------------------------------------------------------------------
    */
    const response = await axios.post(
      `/transaction/purchase-order/${encodeURIComponent(publicId)}/print-url`,
      null,
      {
        params: {
          lang: language,
        },
        headers: {
          Accept: 'application/json',
        },
      },
    )

    if (response.data?.success === false) {
      throw new Error(
        response.data?.message
        || (
          language === 'en'
            ? 'Failed to create print URL.'
            : 'Gagal membuat URL cetak.'
        ),
      )
    }

    const printUrl = response.data?.url

    if (
      typeof printUrl !== 'string'
      || printUrl.trim() === ''
    ) {
      throw new Error(
        language === 'en'
          ? 'Print URL was not found.'
          : 'URL cetak tidak ditemukan.',
      )
    }

    /*
    |--------------------------------------------------------------------------
    | Mendukung URL absolute maupun relative dari backend.
    |--------------------------------------------------------------------------
    */
    const absolutePrintUrl = new URL(
      printUrl,
      window.location.origin,
    ).toString()

    if (printWindow.closed) {
      throw new Error(
        language === 'en'
          ? 'The print window was closed.'
          : 'Jendela cetak telah ditutup.',
      )
    }

    closeAlert()

    /*
    |--------------------------------------------------------------------------
    | Arahkan browser langsung ke endpoint PDF.
    |
    | Tidak lagi menggunakan:
    | - axios.get PDF
    | - responseType blob
    | - URL.createObjectURL
    |--------------------------------------------------------------------------
    */
    printWindow.location.replace(absolutePrintUrl)
  }
  catch (error: unknown) {
    closeAlert()

    if (printWindow && !printWindow.closed)
      printWindow.close()

    showErrorToast({
      title: 'Error',
      text: error instanceof Error
        ? error.message
        : getApiErrorMessage(
          error,
          language === 'en'
            ? 'Failed to print Purchase Order.'
            : 'Gagal mencetak Purchase Order.',
        ),
    })
  }
  finally {
    printLoadingId.value = null
    selectedPrintPublicId.value = null
  }
}

const getPRAttachmentUrl = (file: any): string => {
  return String(
    file?.file_url
      || file?.filepath
      || file?.file_path
      || '',
  )
}

const getPRAttachmentName = (file: any): string => {
  return String(
    file?.original_filename
      || file?.filename
      || file?.file_name
      || 'Lampiran PR',
  )
}

const getPRAttachments = (pr: any): any[] => {
  return Array.isArray(pr?.attachments)
    ? pr.attachments
    : []
}

const openDetail = async (publicId: string): Promise<void> => {
  detailError.value = ''
  detailPurchaseOrder.value = null
  detailPurchaseOrderPublicId.value = publicId
  visiblePrCount.value = 5
  detailItemPage.value = 1
  detailItemPerPage.value = 10

  try {
    showLoadingAlert(
      t('purchaseOrder.list.toast.detailLoadingTitle'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.get(`/transaction/purchase-order/${publicId}`, {
      headers: { Accept: 'application/json' },
    })

    detailPurchaseOrder.value = response.data?.data || null

    closeAlert()
    detailDialog.value = true
  } catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(err, t('purchaseOrder.list.toast.detailLoadFailed')),
    })
  }
}

const resetFilters = async (): Promise<void> => {
  searchQuery.value = ''
  selectedStatus.value = ''
  tanggalMulai.value = null
  tanggalSelesai.value = null
  onlyWaitingMyApproval.value = false
  currentPage.value = 1

  await fetchPurchaseOrders()
}

const goToCreate = (): void => {
  router.push('/non_stock/purchase_order/create')
}

/*
|--------------------------------------------------------------------------
| Export Excel
|--------------------------------------------------------------------------
| Tombolnya hanya tampil untuk pemilik izin create PO. Backend tetap
| memeriksa izin yang sama -- penyembunyian tombol semata soal tampilan,
| bukan pengaman.
|
| Filter yang sedang aktif ikut dikirim supaya isi file sama dengan daftar.
| responseType 'blob' wajib, tanpa itu biner xlsx diperlakukan sebagai teks
| dan file hasil unduhan rusak.
|--------------------------------------------------------------------------
*/
const isExporting = ref(false)

const exportExcel = async (): Promise<void> => {
  if (isExporting.value)
    return

  isExporting.value = true

  try {
    showLoadingAlert(
      t('purchaseOrder.list.toast.exportLoading'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.get('/transaction/purchase-order/export-excel', {
      params: {
        lang: locale.value === 'en' ? 'en' : 'id',
        search: searchQuery.value || undefined,
        status: selectedStatus.value || undefined,
        tanggal_mulai: tanggalMulai.value || undefined,
        tanggal_selesai: tanggalSelesai.value || undefined,
      },
      responseType: 'blob',
    })

    const disposition = String(response.headers?.['content-disposition'] ?? '')
    const matched = disposition.match(/filename="?([^";]+)"?/i)

    const fileName = matched?.[1]
      ? decodeURIComponent(matched[1].trim())
      : 'purchase_order.xlsx'

    const blobUrl = window.URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')

    link.href = blobUrl
    link.setAttribute('download', fileName)
    document.body.appendChild(link)
    link.click()
    link.remove()

    window.URL.revokeObjectURL(blobUrl)

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('purchaseOrder.list.toast.exportSuccess'),
    })
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: t('purchaseOrder.list.toast.exportFailed'),
    })

    console.error('[Purchase Order] EXPORT ERROR:', error)
  } finally {
    isExporting.value = false
  }
}

const goToEdit = (publicId: string): void => {
  router.push(`/non_stock/purchase_order/edit?id=${publicId}`)
}

const { openDeleteConfirm } = useDeleteConfirm()

const openDelete = async (row: any): Promise<void> => {
  if (String(row.status || '').toUpperCase() !== 'DRAFT') {
    showErrorToast({
      title: t('purchaseOrder.list.toast.deleteNotAllowedTitle'),
      text: t('purchaseOrder.list.toast.deleteNotAllowedText'),
    })

    return
  }

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: t('purchaseOrder.list.toast.deleteConfirmTitle'),
    html: t('purchaseOrder.list.toast.deleteConfirmHtml', { nomor: row.nomor_po }),
    confirmButtonText: t('purchaseOrder.list.toast.deleteConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed) return

  try {
    showLoadingAlert(t('purchaseOrder.list.toast.deleteLoading'), t('common.alert.pleaseWait'))

    const response = await axios.delete(
      `/transaction/purchase-order/${encodeURIComponent(row.public_id)}`,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    closeAlert()

    if (response.data?.success) {
      showSuccessToast({
        title: t('common.alert.success'),
        text: t('purchaseOrder.list.toast.deleteSuccessFallback', { nomor: row.nomor_po }),
      })

      await fetchPurchaseOrders()

      return
    }

    showErrorToast({
      title: t('common.alert.error'),
      text: response.data?.message || t('purchaseOrder.list.toast.deleteFailedFallback'),
    })
  } catch (error: any) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: error.response?.data?.message || t('purchaseOrder.list.toast.deleteFailedFallback'),
    })
  }
}

const submitPurchaseOrder = async (): Promise<void> => {
  if (!selectedPo.value || submitLoading.value) return

  const confirm = await showConfirmAlert({
    title: t('purchaseOrder.list.toast.submitConfirmTitle'),
    text: t('purchaseOrder.list.toast.submitConfirmText', { nomor: selectedPo.value.nomor_po }),
    confirmButtonText: t('purchaseOrder.list.toast.submitConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed) return

  submitLoading.value = true

  try {
    showLoadingAlert(t('purchaseOrder.list.toast.submitLoading'), t('common.alert.pleaseWait'))

    await axios.patch(`/transaction/purchase-order/${selectedPo.value.public_id}/submit`, {}, {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('purchaseOrder.list.toast.submitSuccessFallback', { nomor: selectedPo.value.nomor_po }),
    })

    await fetchPurchaseOrders()
    await navigationStore.refreshBadges()
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('purchaseOrder.list.toast.submitFailedFallback')),
    })
  } finally {
    submitLoading.value = false
  }
}

const showApprovePODialog = (): void => {
  if (!selectedPo.value)
    return

  approveNotes.value = ''
  approveDialog.value = true
}

const approvePurchaseOrder = async (): Promise<void> => {
  if (
    !selectedPo.value
    || approveLoading.value
  ) {
    return
  }

  const target = {
    ...selectedPo.value,
  }

  /*
  |--------------------------------------------------------------------------
  | Catatan bersifat opsional
  |--------------------------------------------------------------------------
  */
  const notes
    = approveNotes.value.trim() || null

  /*
  |--------------------------------------------------------------------------
  | Dialog ini sekaligus menjadi konfirmasi
  |--------------------------------------------------------------------------
  | Tidak ada SweetAlert konfirmasi kedua.
  |--------------------------------------------------------------------------
  */
  approveDialog.value = false

  await nextTick()

  approveLoading.value = true

  try {
    showLoadingAlert(
      t('purchaseOrder.list.toast.approveLoading'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/transaction/purchase-order/${encodeURIComponent(target.public_id)}/approve`,
      {
        notes,
      },
      {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    const responseData
      = response.data?.data || {}

    /*
    |--------------------------------------------------------------------------
    | Jangan default ke APPROVED
    |--------------------------------------------------------------------------
    | Pada approval bertingkat, PO mungkin masih IN PROGRESS.
    |--------------------------------------------------------------------------
    */
    const newStatus
      = responseData.status
        || target.status
        || 'IN PROGRESS'

    const newNomorPO
      = responseData.nomor_po
        || target.nomor_po

    const isFinalApproved
      = Boolean(
        responseData.is_final_approved,
      )

    rows.value = rows.value.map(item => {
      if (
        item.public_id
        !== target.public_id
      ) {
        return item
      }

      return {
        ...item,

        nomor_po: newNomorPO,
        status: newStatus,

        /*
         * approved_at hanya diisi jika PO
         * benar-benar final approved.
         */
        approved_at: isFinalApproved
          ? (
              responseData.approved_at
              || new Date().toISOString()
            )
          : item.approved_at,
      }
    })

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),

      text:
        response.data?.message
        || t('purchaseOrder.list.toast.approveSuccessFallback', { nomor: target.nomor_po || '-' }),
    })

    approveNotes.value = ''
    selectedPo.value = null

    /*
     * Gunakan null jika tipe pendingAction
     * memang string | null.
     */
    pendingAction.value = null

    await fetchPurchaseOrders()
    await navigationStore.refreshBadges()

    /*
    |--------------------------------------------------------------------------
    | Refresh modal detail jika sedang terbuka untuk PO ini
    |--------------------------------------------------------------------------
    */
    if (detailDialog.value && detailPurchaseOrderPublicId.value === target.public_id)
      await openDetail(target.public_id)
  }
  catch (error: unknown) {
    closeAlert()

    /*
    |--------------------------------------------------------------------------
    | Tampilkan dialog kembali jika gagal
    |--------------------------------------------------------------------------
    | Catatan tidak dihapus agar user dapat mencoba lagi.
    |--------------------------------------------------------------------------
    */
    approveDialog.value = true

    showErrorToast({
      title: t('common.alert.error'),

      text: getApiErrorMessage(
        error,
        t('purchaseOrder.list.toast.approveFailedFallback'),
      ),
    })
  }
  finally {
    approveLoading.value = false
  }
}

watch(currentPage, async () => {
  await fetchPurchaseOrders()
})

watch(rowPerPage, async () => {
  currentPage.value = 1
  await fetchPurchaseOrders()
})

watch([searchQuery, selectedStatus, tanggalMulai, tanggalSelesai, onlyWaitingMyApproval], async () => {
  currentPage.value = 1
  await fetchPurchaseOrders()
})

watch(tanggalSelesai, async (newValue) => {
  if (!newValue || !tanggalMulai.value) return

  const startDate = new Date(tanggalMulai.value)
  const endDate = new Date(newValue)

  if (endDate < startDate) {
    tanggalSelesai.value = null

    showErrorToast({
      title: t('purchaseOrder.list.toast.invalidDateRangeTitle'),
      text: t('purchaseOrder.list.toast.invalidDateRangeText'),
    })
  }
})

watch(tanggalMulai, async (newValue) => {
  if (!newValue || !tanggalSelesai.value) return

  const startDate = new Date(newValue)
  const endDate = new Date(tanggalSelesai.value)

  if (endDate < startDate) {
    tanggalSelesai.value = null

    showErrorToast({
      title: t('purchaseOrder.list.toast.invalidDateRangeTitle'),
      text: t('purchaseOrder.list.toast.invalidDateRangeText'),
    })
  }
})

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canView.value) {
    await router.replace('/forbidden')
    return
  }

  isCheckingPermission.value = false

  await fetchPurchaseOrders()
  await loadCurrentUser()

  window.addEventListener('purchase-order:refresh', handlePurchaseOrderRefresh)

  fetchPurchaseOrders()

  window.addEventListener('resize', resizeSignatureCanvas)

  const success = route.query.success

  if (success) {
    await router.replace({
      path: '/non_stock/purchase_order',
      query: {},
    })

    setTimeout(() => {
      if (success === 'created') {
        showSuccessToast({
          title: t('common.alert.success'),
          text: t('purchaseOrder.list.toast.createdSuccess'),
        })
      }

      if (success === 'updated') {
        showSuccessToast({
          title: t('common.alert.success'),
          text: t('purchaseOrder.list.toast.updatedSuccess'),
        })
      }
    }, 300)
  }
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', resizeSignatureCanvas)
  window.removeEventListener('purchase-order:refresh', handlePurchaseOrderRefresh)
})
</script>

<template>
  <section>
    <!-- Filters -->
    <VCard class="mb-6 po-filter-card">
      <VCardText class="pa-5">
        <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-5">
          <div class="d-flex align-center gap-3">
            <VAvatar
              size="44"
              color="primary"
              variant="tonal"
            >
              <VIcon
                icon="tabler-filter"
                size="24"
              />
            </VAvatar>

            <div>
              <div class="text-h5 font-weight-bold">
                {{ t('purchaseOrder.list.filtersTitle') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('purchaseOrder.list.filtersSubtitle') }}
              </div>
            </div>
          </div>

          <VBtn
            color="secondary"
            variant="tonal"
            prepend-icon="tabler-refresh"
            class="text-none"
            :disabled="loading"
            @click="resetFilters"
          >
            {{ t('purchaseOrder.list.filters.resetButton') }}
          </VBtn>
        </div>

        <VRow class="po-filter-grid">
          <VCol
            cols="12"
            md="4"
          >
            <VTextField
              v-model="searchQuery"
              :label="t('purchaseOrder.list.filters.searchLabel')"
              :placeholder="t('purchaseOrder.list.filters.searchPlaceholder')"
              density="compact"
              prepend-inner-icon="tabler-search"
              clearable
              hide-details
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <AppDateTimePicker
              v-model="tanggalMulai"
              :label="t('purchaseOrder.list.filters.startDate')"
              density="compact"
              clearable
              :config="{ dateFormat: 'Y-m-d' }"
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <AppDateTimePicker
              v-model="tanggalSelesai"
              :label="t('purchaseOrder.list.filters.endDate')"
              density="compact"
              clearable
              :config="{ dateFormat: 'Y-m-d' }"
            />
          </VCol>

          <VCol
            cols="12"
            md="3"
          >
            <VSelect
              v-model="selectedStatus"
              :label="t('purchaseOrder.list.filters.statusLabel')"
              :items="statusItems"
              item-title="title"
              item-value="value"
              density="compact"
              prepend-inner-icon="tabler-progress-check"
              hide-details
            />
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <div
              class="approval-filter-box"
              :class="{ 'is-active': onlyWaitingMyApproval }"
            >
              <div class="d-flex align-center gap-3 min-w-0">
                <VAvatar
                  size="34"
                  :color="onlyWaitingMyApproval ? 'warning' : 'secondary'"
                  variant="tonal"
                >
                  <VIcon
                    icon="tabler-user-check"
                    size="19"
                  />
                </VAvatar>

                <div class="min-w-0">
                  <div class="text-caption text-medium-emphasis approval-filter-subtitle">
                    {{ t('purchaseOrder.list.onlyMyApproval') }}
                  </div>
                </div>
              </div>

              <VSwitch
                v-model="onlyWaitingMyApproval"
                color="warning"
                inset
                hide-details
              />
            </div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn color="primary" @click="goToCreate" class="text-none" v-if="canCreate" prepend-icon="tabler-plus"> {{ t('purchaseOrder.list.createButton') }}
        </VBtn>

        <!--
          Tombol mengikuti permission export tersendiri. Isi file selalu sama
          dengan daftar yang sedang tampil, termasuk filternya.
        -->
        <VBtn
          v-if="canExport"
          color="success"
          variant="tonal"
          prepend-icon="tabler-file-spreadsheet"
          class="text-none"
          :loading="isExporting"
          :disabled="loading"
          @click="exportExcel"
        >
          {{ t('purchaseOrder.list.exportButton') }}
        </VBtn>

        <VSpacer />

        <div class="d-flex align-center gap-2">
          <VChip
            v-if="loading"
            size="small"
            variant="tonal"
          >
            {{ t('purchaseOrder.list.toast.loadingText') }}
          </VChip>

          <VBtn
            v-else-if="loadError"
            size="small"
            color="error"
            variant="tonal"
            prepend-icon="tabler-refresh"
            @click="fetchPurchaseOrders"
          >
            {{ t('purchaseOrder.list.toast.reloadData') }}
          </VBtn>
        </div>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col" class="text-center">{{ t('purchaseOrder.list.table.no') }}</th>
            <th scope="col" class="text-center">{{ t('purchaseOrder.list.table.nomorPo') }}</th>
            <th scope="col" class="text-center">{{ t('purchaseOrder.list.table.tanggal') }}</th>
            <th scope="col" class="text-center">{{ t('purchaseOrder.list.table.cabang') }}</th>
            <th scope="col" class="text-center">{{ t('purchaseOrder.list.table.department') }}</th>
            <th scope="col" class="text-right">{{ t('purchaseOrder.list.table.total') }}</th>
            <th scope="col" class="text-center">{{ t('purchaseOrder.list.table.statusApproval') }}</th>
            <th scope="col" class="text-center">{{ t('purchaseOrder.list.table.statusGr') }}</th>
            <th scope="col" class="text-center" style="width: 5rem;">{{ t('purchaseOrder.list.table.actions') }}</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="(v, index) in rows" :key="v.id" :class="{
            'po-row-need-approval': canApprovePO(v),
          }">
            <td class="text-medium-emphasis text-center">
              {{ ((currentPage - 1) * rowPerPage) + Number(index) + 1 }}
            </td>

            <td>
              <VMenu location="bottom start">
                <template #activator="{ props }">
                  <div
                    v-bind="props"
                    class="po-number-action d-inline-flex flex-column gap-1"
                  >
                    <div class="d-flex align-center justify-center gap-1 font-weight-medium text-primary">
                      <span>{{ v.nomor_po || '-' }}</span>

                      <VIcon
                        icon="tabler-chevron-down"
                        size="16"
                      />
                    </div>

                    <VChip
                      v-if="canApprovePO(v)"
                      size="x-small"
                      color="warning"
                      variant="tonal"
                      class="po-approval-chip"
                    >
                      <VIcon
                        icon="tabler-alert-circle"
                        size="14"
                        start
                      />
                      {{ t('purchaseOrder.list.menu.waitingApprovalBadge') }}
                    </VChip>
                  </div>
                </template>

                  <VList>
                    <VListItem
                      href="javascript:void(0)"
                      @click="openDetail(v.public_id)"
                    >
                      <template #prepend>
                        <VIcon icon="tabler-eye" :size="20" class="me-3" />
                      </template>

                      <VListItemTitle>
                        {{ t('purchaseOrder.list.menu.viewDetail') }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem @click="openApprovalHistory(v)">
                      <template #prepend>
                        <VIcon icon="tabler-history" :size="20" class="me-3" />
                      </template>

                      <VListItemTitle>{{ t('purchaseOrder.list.menu.approvalHistory') }}</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="
                        String(v.status).toLowerCase() === 'approved'
                        && String(v.status).toLowerCase() !== 'rejected'
                      "
                      href="javascript:void(0)"
                      :disabled="printLoadingId === v.public_id"
                      @click="openPrintLanguageDialog(v.public_id)"
                    >
                      <template #prepend>
                        <VProgressCircular
                          v-if="printLoadingId === v.public_id"
                          indeterminate
                          size="18"
                          width="2"
                          class="me-3"
                        />

                        <VIcon
                          v-else
                          icon="tabler-printer"
                          :size="20"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle>
                        {{
                          printLoadingId === v.public_id
                            ? t('purchaseOrder.list.menu.printLoading')
                            : t('purchaseOrder.list.menu.print')
                        }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="canApprovePO(v)"
                      @click="openApprovePO(v)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-circle-check"
                          :size="20"
                          class="me-3 text-success"
                        />
                      </template>

                      <VListItemTitle class="text-success">
                        {{ t('purchaseOrder.list.menu.approve') }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="canApprovePO(v)"
                      @click="openRejectPO(v)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="mdi-close-circle-outline"
                          :size="20"
                          color="error"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle class="text-error">
                        {{ t('purchaseOrder.list.menu.reject') }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toUpperCase() === 'APPROVED' && String(v.status_receive).toUpperCase() === 'OPEN' && canCancelPO"
                      href="javascript:void(0)"
                      @click="openCancelPO(v)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-ban"
                          :size="20"
                          color="error"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle class="text-error">
                        {{ t('purchaseOrder.list.toast.cancelMenuLabel') }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="v.can_submit"
                      href="javascript:void(0)"
                      @click="openSubmitPO(v)"
                    >
                      <template #prepend>
                        <VIcon icon="mdi-send-outline" :size="20" class="me-3" />
                      </template>

                      <VListItemTitle>{{ t('purchaseOrder.list.menu.submit') }}</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toLowerCase() === 'draft' && canUpdate"
                      href="javascript:void(0)"
                      @click="goToEdit(v.public_id)"
                    >
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>{{ t('purchaseOrder.list.menu.edit') }}</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toLowerCase() === 'draft' && canDelete"
                      href="javascript:void(0)"
                      @click="openDelete(v)"
                    >
                      <template #prepend>
                        <VIcon icon="tabler-trash" :size="20" class="me-3 text-error" />
                      </template>
                      <VListItemTitle class="text-error">{{ t('purchaseOrder.list.menu.delete') }}</VListItemTitle>
                    </VListItem>
                  </VList>
              </VMenu>
            </td>

            <td class="text-medium-emphasis text-center">
              {{ formatDate(v.tanggal_po) }}
            </td>

            <td class="text-medium-emphasis text-center">{{ v.cabang || '-' }}</td>
            <td class="text-medium-emphasis text-center">{{ v.department || '-' }}</td>

            <td class="text-end text-medium-emphasis">
              {{ formatCurrency(v.total_nilai) }}
            </td>

            <td class="text-center">
              <VChip
                :color="getStatusColor(v.status)"
                size="small"
                class="text-capitalize"
              >
                {{ formatStatus(v.status) }}
              </VChip>
            </td>
            
            <td class="text-center">
              <VChip
                v-if="String(v.status || '').toUpperCase() === 'APPROVED'"
                :color="getStatusReceiveColor(v.status_receive)"
                size="small"
                class="text-capitalize"
              >
                {{ formatStatusReceive(v.status_receive) }}
              </VChip>

              <span
                v-else
                class="text-medium-emphasis"
              >
                -
              </span>
            </td>

            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />

                <VMenu activator="parent" location="bottom end">
                  <VList>
                    <VListItem
                      href="javascript:void(0)"
                      @click="openDetail(v.public_id)"
                    >
                      <template #prepend>
                        <VIcon icon="tabler-eye" :size="20" class="me-3" />
                      </template>

                      <VListItemTitle>
                        {{ t('purchaseOrder.list.menu.viewDetail') }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem @click="openApprovalHistory(v)">
                      <template #prepend>
                        <VIcon icon="tabler-history" :size="20" class="me-3" />
                      </template>

                      <VListItemTitle>{{ t('purchaseOrder.list.menu.approvalHistory') }}</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="
                        String(v.status).toLowerCase() === 'approved'
                        && String(v.status).toLowerCase() !== 'rejected'
                      "
                      href="javascript:void(0)"
                      :disabled="printLoadingId === v.public_id"
                      @click="openPrintLanguageDialog(v.public_id)"
                    >
                      <template #prepend>
                        <VProgressCircular
                          v-if="printLoadingId === v.public_id"
                          indeterminate
                          size="18"
                          width="2"
                          class="me-3"
                        />

                        <VIcon
                          v-else
                          icon="tabler-printer"
                          :size="20"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle>
                        {{
                          printLoadingId === v.public_id
                            ? t('purchaseOrder.list.menu.printLoading')
                            : t('purchaseOrder.list.menu.print')
                        }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="canApprovePO(v)"
                      @click="openApprovePO(v)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-circle-check"
                          :size="20"
                          class="me-3 text-success"
                        />
                      </template>

                      <VListItemTitle class="text-success">
                        {{ t('purchaseOrder.list.menu.approve') }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="canApprovePO(v)"
                      @click="openRejectPO(v)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="mdi-close-circle-outline"
                          :size="20"
                          color="error"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle class="text-error">
                        {{ t('purchaseOrder.list.menu.reject') }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toUpperCase() === 'APPROVED' && String(v.status_receive).toUpperCase() === 'OPEN' && canCancelPO"
                      href="javascript:void(0)"
                      @click="openCancelPO(v)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-ban"
                          :size="20"
                          color="error"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle class="text-error">
                        {{ t('purchaseOrder.list.toast.cancelMenuLabel') }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="v.can_submit"
                      href="javascript:void(0)"
                      @click="openSubmitPO(v)"
                    >
                      <template #prepend>
                        <VIcon icon="mdi-send-outline" :size="20" class="me-3" />
                      </template>

                      <VListItemTitle>{{ t('purchaseOrder.list.menu.submit') }}</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toLowerCase() === 'draft' && canUpdate"
                      href="javascript:void(0)"
                      @click="goToEdit(v.public_id)"
                    >
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>{{ t('purchaseOrder.list.menu.edit') }}</VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="String(v.status).toLowerCase() === 'draft' && canDelete"
                      href="javascript:void(0)"
                      @click="openDelete(v)"
                    >
                      <template #prepend>
                        <VIcon icon="tabler-trash" :size="20" class="me-3 text-error" />
                      </template>
                      <VListItemTitle class="text-error">{{ t('purchaseOrder.list.menu.delete') }}</VListItemTitle>
                    </VListItem>
                  </VList>
                </VMenu>
              </VBtn>
            </td>
          </tr>
        </tbody>

        <tfoot v-show="!rows.length && !loading">
          <tr>
            <td colspan="9" class="text-center">
              {{ t('purchaseOrder.list.pagination.noDataAvailable') }}
            </td>
          </tr>
        </tfoot>
      </VTable>

      <VDivider />

      <VCardText class="d-flex align-center flex-wrap justify-end gap-4 pa-2">
        <div class="d-flex align-center me-3" style="width: 220px;">
          <span class="text-no-wrap me-3">{{ t('purchaseOrder.list.pagination.rowsPerPage') }}</span>

          <VSelect
            v-model="rowPerPage"
            density="compact"
            variant="plain"
            class="user-pagination-select"
            :items="[10, 20, 30, 50]"
          />
        </div>

        <div class="d-flex align-center">
          <h6 class="text-sm font-weight-regular">
            {{ paginationData }}
          </h6>

          <VPagination
            v-model="currentPage"
            size="small"
            :total-visible="1"
            :length="totalPage"
          />
        </div>
      </VCardText>
    </VCard>

    <VDialog
      v-model="printLanguageDialog"
      max-width="460"
      persistent
    >
      <VCard class="rounded-lg">
        <VCardItem>
          <template #prepend>
            <VAvatar
              color="primary"
              variant="tonal"
              size="42"
              class="me-3"
            >
              <VIcon
                icon="tabler-language"
                size="23"
              />
            </VAvatar>
          </template>

          <VCardTitle>
            {{ t('purchaseOrder.list.printLanguageDialog.title') }}
          </VCardTitle>

          <VCardSubtitle>
            {{ t('purchaseOrder.list.printLanguageDialog.subtitle') }}
          </VCardSubtitle>
        </VCardItem>

        <VDivider />

        <VCardText class="pa-5">
          <VRow>
            <VCol
              cols="12"
              sm="6"
            >
              <VBtn
                block
                size="large"
                color="primary"
                variant="tonal"
                :disabled="Boolean(printLoadingId)"
                @click="printPurchaseOrder('id')"
                class="text-none"
              >
                {{ t('purchaseOrder.list.printLanguageDialog.indonesian') }}
              </VBtn>
            </VCol>

            <VCol
              cols="12"
              sm="6"
            >
              <VBtn
                block
                size="large"
                color="primary"
                variant="tonal"
                :disabled="Boolean(printLoadingId)"
                @click="printPurchaseOrder('en')"
                class="text-none"
              >
                {{ t('purchaseOrder.list.printLanguageDialog.english') }}
              </VBtn>
            </VCol>
          </VRow>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end pa-4">
          <VBtn
            variant="text"
            color="secondary"
            :disabled="Boolean(printLoadingId)"
            @click="closePrintLanguageDialog"
            class="text-none"
          >
            {{ t('purchaseOrder.list.printLanguageDialog.cancelButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="detailDialog"
      max-width="1250"
      persistent
      scrollable
    >
      <VCard class="po-detail-card">
        <VCardTitle class="po-detail-header px-6 py-5">
          <div class="d-flex align-center gap-3">
            <VAvatar color="primary" variant="tonal" size="42">
              <VIcon icon="tabler-file-invoice" />
            </VAvatar>

            <div>
              <div class="text-h6 font-weight-bold">
                {{ t('purchaseOrder.list.detailDialog.title') }}
              </div>
            </div>

            <VChip
              v-if="detailPurchaseOrder"
              size="small"
              variant="tonal"
              :color="getStatusColor(detailPurchaseOrder.status)"
              class="text-capitalize ms-2"
            >
              {{ formatStatus(detailPurchaseOrder.status) || '-' }}
            </VChip>
          </div>

          <VBtn
            icon
            variant="text"
            color="primary"
            @click="detailDialog = false"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>

        <VDivider />

        <VCardText class="pa-6">
          <div v-if="detailPurchaseOrder">
            <VRow class="mb-5">
              <VCol cols="12" md="8">
                <VCard class="h-100 rounded-lg po-info-card">
                  <VCardText>
                    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
                      <div>
                        <div class="text-caption text-medium-emphasis">
                          Purchase Order
                        </div>
                        <div class="text-h6 font-weight-bold">
                          {{ detailPurchaseOrder.nomor_po || '-' }}
                        </div>
                      </div>

                      <VChip
                        size="small"
                        color="primary"
                        variant="tonal"
                        prepend-icon="tabler-calendar"
                      >
                        {{ formatDate(detailPurchaseOrder.tanggal_po) || '-' }}
                      </VChip>
                    </div>

                    <VRow>
                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Vendor</div>
                          <div class="info-value">
                            {{ detailPurchaseOrder.vendor_data?.nama_vendor || detailPurchaseOrder.vendor || '-' }}
                          </div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Status PKP</div>
                          <div class="info-value">
                            {{
                              formatStatusPKP(
                                detailPurchaseOrder.status_pkp
                                  || detailPurchaseOrder.vendor_data?.status_pkp
                                  || 'NON_PKP',
                              )
                            }}
                          </div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Jenis Pembayaran</div>
                          <div class="info-value">
                            {{
                              detailPurchaseOrder.jenis_pembayaran
                                || detailPurchaseOrder.vendor_data?.jenis_pembayaran
                                || '-'
                            }}
                          </div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">TOP</div>
                          <div class="info-value">
                            {{
                              Number(
                                detailPurchaseOrder.top
                                  ?? detailPurchaseOrder.vendor_data?.top
                                  ?? 0,
                              ) > 0
                                ? `${Number(detailPurchaseOrder.top ?? detailPurchaseOrder.vendor_data?.top ?? 0)} Hari`
                                : '-'
                            }}
                          </div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Cabang</div>
                          <div class="info-value">{{ detailPurchaseOrder.cabang || '-' }}</div>
                        </div>
                      </VCol>

                      <VCol cols="12" md="6">
                        <div class="info-box">
                          <div class="info-label">Department</div>
                          <div class="info-value">{{ detailPurchaseOrder.department || '-' }}</div>
                        </div>
                      </VCol>

                      <VCol cols="12">
                        <div class="info-box">
                          <div class="info-label">Notes</div>
                          <div class="info-value">{{ detailPurchaseOrder.notes || '-' }}</div>
                        </div>
                      </VCol>
                    </VRow>

                    <VRow class="mt-4">
                      <VCol
                        cols="12"
                        md="6"
                      >
                        <div class="detail-info-box">
                          <div class="text-caption text-medium-emphasis">
                            Dibuat Oleh
                          </div>
                          <div class="text-subtitle-2 font-weight-bold">
                            {{ detailPurchaseOrder?.created_by_name || '-' }}
                          </div>
                        </div>
                      </VCol>

                      <VCol
                        cols="12"
                        md="6"
                      >
                        <div class="detail-info-box">
                          <div class="text-caption text-medium-emphasis">
                            Dibuat Pada
                          </div>
                          <div class="text-subtitle-2 font-weight-bold">
                            {{ detailPurchaseOrder?.created_at ? formatDate(detailPurchaseOrder.created_at) : '-' }}
                          </div>
                        </div>
                      </VCol>

                      <VCol
                        cols="12"
                        md="6"
                      >
                        <div class="detail-info-box">
                          <div class="text-caption text-medium-emphasis">
                            Disubmit Oleh
                          </div>
                          <div class="text-subtitle-2 font-weight-bold">
                            {{ detailPurchaseOrder?.submitted_by_name || '-' }}
                          </div>
                        </div>
                      </VCol>

                      <VCol
                        cols="12"
                        md="6"
                      >
                        <div class="detail-info-box">
                          <div class="text-caption text-medium-emphasis">
                            Disubmit Pada
                          </div>
                          <div class="text-subtitle-2 font-weight-bold">
                            {{ detailPurchaseOrder?.submitted_at ? formatDate(detailPurchaseOrder.submitted_at) : '-' }}
                          </div>
                        </div>
                      </VCol>
                    </VRow>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol cols="12" md="4">
                <VCard class="h-100 rounded-lg total-card">
                  <VCardText>
                    <div class="d-flex align-center justify-space-between mb-3">
                      <div class="text-caption text-medium-emphasis">
                        Purchase Requisition Terkait
                      </div>

                      <VChip
                        size="x-small"
                        color="primary"
                        variant="tonal"
                      >
                        {{ detailPurchaseOrder.purchase_requests?.length || 0 }} PR
                      </VChip>
                    </div>

                    <div
                      v-if="detailPurchaseOrder.purchase_requests?.length"
                      class="related-pr-scroll"
                    >
                      <div class="d-flex flex-column gap-2">
                        <TransitionGroup
                          name="pr-slide"
                          tag="div"
                          class="d-flex flex-column gap-2"
                        >
                          <div
                            v-for="pr in visibleRelatedPurchaseRequests"
                            :key="pr.id"
                            class="related-pr-item"
                          >
                            <div class="font-weight-bold text-primary related-pr-number">
                              {{ pr.nomor_pr }}
                            </div>

                            <div class="related-pr-meta mb-2">
                              <span>{{ formatDate(pr.tanggal_pr) }}</span>
                              <!-- <span>Rp {{ formatNumberWithoutRp(pr.total_amount || 0) }}</span> -->
                            </div>

                            <div
                              v-if="getPRAttachments(pr).length"
                              class="related-pr-attachments"
                            >
                              <div class="related-pr-attachment-title">
                                Lampiran PR
                              </div>

                              <VBtn
                                v-for="file in getPRAttachments(pr)"
                                :key="file.id"
                                :href="getPRAttachmentUrl(file)"
                                target="_blank"
                                rel="noopener noreferrer"
                                size="x-small"
                                variant="tonal"
                                color="primary"
                                prepend-icon="tabler-paperclip"
                                class="related-pr-attachment-btn text-none"
                                :disabled="!getPRAttachmentUrl(file)"
                              >
                                <span class="related-pr-attachment-text">
                                  {{ getPRAttachmentName(file) }}
                                </span>
                              </VBtn>
                            </div>

                            <div
                              v-else
                              class="text-caption text-disabled mt-2"
                            >
                              Tidak ada lampiran
                            </div>
                          </div>
                        </TransitionGroup>

                        <VBtn
                          v-if="hasMoreRelatedPurchaseRequests"
                          size="small"
                          variant="tonal"
                          color="primary"
                          block
                          prepend-icon="tabler-chevron-down"
                          @click="showMoreRelatedPurchaseRequests"
                        >
                          Tampilkan lainnya
                        </VBtn>
                      </div>
                    </div>

                    <VAlert
                      v-else
                      type="info"
                      variant="tonal"
                      density="compact"
                    >
                      Tidak ada Purchase Requisition terkait.
                    </VAlert>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>

            <!-- ATTACHMENTS -->
            <VCard
              flat
              class="rounded-md"
            >
              <VCardText>
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-3">
                  <div class="d-flex align-center gap-2">
                    <VIcon
                      icon="tabler-paperclip"
                      color="primary"
                    />
                    <div class="text-subtitle-1 font-weight-bold">
                      Lampiran Purchase Order
                    </div>
                  </div>

                  <VChip
                    size="small"
                    color="primary"
                    variant="tonal"
                  >
                    {{ detailPurchaseOrder.attachments?.length || 0 }} File
                  </VChip>
                </div>

                <div
                  v-if="detailPurchaseOrder.attachments?.length"
                  class="d-flex flex-wrap gap-2"
                >
                  <VBtn
                    v-for="(file, index) in detailPurchaseOrder.attachments"
                    :key="index"
                    :href="file.file_url || file.filepath"
                    target="_blank"
                    variant="tonal"
                    color="primary"
                    size="small"
                    prepend-icon="tabler-external-link"
                  >
                    {{ file.original_filename || 'Lampiran PO' }}
                  </VBtn>
                </div>

                <VAlert
                  v-else
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  Tidak ada lampiran purchase order.
                </VAlert>
              </VCardText>
            </VCard>

            <VCard flat class="rounded-lg">
              <VCardText>
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
                  <div class="text-subtitle-1 font-weight-bold">
                    Daftar Item Purchase Order
                  </div>

                  <VChip
                    size="small"
                    color="primary"
                    variant="tonal"
                    prepend-icon="tabler-list-details"
                  >
                    {{ detailItems.length || 0 }} Item
                  </VChip>
                </div>

                <div class="detail-po-table-wrapper">
                  <VTable class="detail-po-table">
                    <thead>
                      <tr>
                        <th class="text-center col-no">{{ t('common.itemTable.no') }}</th>
                        <th class="col-item">{{ t('common.itemTable.namaItem') }}</th>
                        <th class="col-note">{{ t('common.itemTable.keterangan') }}</th>
                        <th class="text-center col-qty">{{ t('common.itemTable.qtyPo') }}</th>
                        <th class="text-center col-qty">{{ t('purchaseOrder.list.table.sudahGr') }}</th>
                        <th class="text-center col-qty">{{ t('purchaseOrder.list.table.sisaGr') }}</th>
                        <th class="text-center col-unit">{{ t('common.itemTable.satuan') }}</th>
                        <th class="text-end col-money">{{ t('common.itemTable.hargaUnit') }}</th>
                        <th class="text-end col-money">{{ t('common.itemTable.subtotal') }}</th>
                      </tr>
                    </thead>

                    <tbody>
                      <tr
                        v-for="(item, index) in paginatedDetailItems"
                        :key="item.id || index"
                      >
                        <td class="text-center">
                          <div class="table-number">
                            {{ detailItemPerPage === 'ALL'
                              ? Number(index) + 1
                              : ((Number(detailItemPage) - 1) * Number(detailItemPerPage)) + Number(index) + 1
                            }}
                          </div>
                        </td>

                        <td>
                          <div class="item-main">
                            {{ toTitleCase(item.nama_item) || '-' }}
                          </div>
                        </td>

                        <td>
                          <div class="note-text">
                            {{ item.keterangan || '-' }}
                          </div>
                        </td>

                        <td class="text-center">
                          <div class="qty-wrapper">
                            <VChip
                              size="default"
                              color="warning"
                              variant="tonal"
                              class="qty-chip"
                            >
                              {{ formatDecimalQty(item.qty) }}
                            </VChip>
                          </div>
                        </td>

                        <td class="text-center">
                          <div class="qty-wrapper">
                            <VChip
                              size="default"
                              color="info"
                              variant="tonal"
                              class="qty-chip"
                            >
                              {{ formatDecimalQty(item.qty_received || 0) }}
                            </VChip>
                          </div>
                        </td>

                        <td class="text-center">
                          <div class="qty-wrapper">
                            <VChip
                              size="default"
                              :color="Number(item.qty_outstanding_receive || 0) <= 0 ? 'success' : 'warning'"
                              variant="tonal"
                              class="qty-chip"
                            >
                              {{ formatDecimalQty(item.qty_outstanding_receive || 0) }}
                            </VChip>
                          </div>
                        </td>

                        <td class="text-center">
                          <VChip
                            size="small"
                            color="secondary"
                            variant="tonal"
                          >
                            {{ item.satuan || '-' }}
                          </VChip>
                        </td>

                        <td class="text-end">
                          <div class="money-text">
                            Rp {{ formatNumberWithoutRp(item.harga_unit || 0) }}
                          </div>
                        </td>

                        <td class="text-end">
                          <div class="subtotal-text">
                            Rp {{ formatNumberWithoutRp(item.subtotal || 0) }}
                          </div>
                        </td>
                      </tr>

                      <tr v-if="!detailItems.length">
                        <td
                          colspan="9"
                          class="text-center text-medium-emphasis py-8"
                        >
                          Item belum tersedia.
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </div>
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-3">
                  <div class="text-caption text-medium-emphasis">
                    Total Item PO: {{ detailItems.length }}
                  </div>

                  <div class="d-flex align-center gap-3">
                    <VSelect
                      v-model="detailItemPerPage"
                      :items="detailItemPerPageItems"
                      item-title="title"
                      item-value="value"
                      density="compact"
                      hide-details
                      style="width: 110px;"
                      @update:model-value="detailItemPage = 1"
                    />

                    <VPagination
                      v-if="detailItemPerPage !== 'ALL' && detailItems.length > Number(detailItemPerPage)"
                      v-model="detailItemPage"
                      :length="detailItemTotalPage"
                      size="small"
                      :total-visible="3"
                    />
                  </div>
                </div>
                <VRow class="mt-4 align-stretch">
                  <VCol
                    cols="12"
                    md="7"
                    lg="8"
                    class="order-2 order-md-1"
                  >
                    <VCard
                      variant="tonal"
                      class="approval-simple-box h-100"
                    >
                      <VCardText class="py-3 px-4">
                        <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
                          <div class="d-flex align-center gap-2">
                            <VAvatar
                              size="30"
                              color="primary"
                              variant="tonal"
                            >
                              <VIcon
                                icon="tabler-route"
                                size="17"
                              />
                            </VAvatar>

                            <div>
                              <div class="text-subtitle-2 font-weight-bold">
                                History Approval
                              </div>

                              <div class="text-caption text-medium-emphasis">
                                Ringkasan proses approval PO
                              </div>
                            </div>
                          </div>

                          <VChip
                            size="x-small"
                            color="primary"
                            variant="tonal"
                          >
                            {{ detailApprovalHistories.length }} Tahap
                          </VChip>
                        </div>

                        <div
                          v-if="detailApprovalHistories.length"
                          class="approval-simple-list"
                        >
                          <div
                            v-for="history in detailApprovalHistories"
                            :key="history.id"
                            class="approval-simple-item"
                          >
                            <div class="approval-step-circle">
                              {{ history.step_order }}
                            </div>

                            <div class="approval-simple-content">
                              <div class="d-flex align-center justify-space-between flex-wrap gap-2">
                                <div class="font-weight-bold approval-simple-title">
                                  {{ history.label }}
                                </div>

                                <VChip
                                  size="x-small"
                                  variant="tonal"
                                  :color="getSimpleApprovalStatusColor(history.status)"
                                  :prepend-icon="getSimpleApprovalStatusIcon(history.status)"
                                >
                                  {{ getSimpleApprovalStatusLabel(history.status) }}
                                </VChip>
                              </div>

                              <div class="approval-simple-meta">
                                <span>
                                  <VIcon
                                    icon="tabler-user"
                                    size="14"
                                    class="me-1"
                                  />
                                  {{ history.processed_by }}
                                </span>

                                <span>
                                  <VIcon
                                    icon="tabler-clock"
                                    size="14"
                                    class="me-1"
                                  />
                                  {{ history.processed_at ? formatDate(history.processed_at) : '-' }}
                                </span>
                              </div>

                              <div
                                v-if="history.notes"
                                class="approval-simple-notes"
                              >
                                {{ history.notes }}
                              </div>
                            </div>
                          </div>
                        </div>

                        <VAlert
                          v-else
                          type="info"
                          variant="tonal"
                          density="compact"
                        >
                          Belum ada history approval.
                        </VAlert>
                      </VCardText>
                    </VCard>
                  </VCol>

                  <VCol
                    cols="12"
                    md="5"
                    lg="4"
                    class="order-1 order-md-2"
                  >
                    <VCard
                      variant="tonal"
                      class="summary-total-box h-100"
                    >
                      <VCardText class="py-3 px-4">
                        <template v-if="String(detailPurchaseOrder.vendor_data?.status_pkp).toUpperCase() === 'PKP'">
                          <div class="summary-row">
                            <span>Subtotal</span>
                            <strong>Rp {{ formatNumberWithoutRp(calcPOTotal(detailPurchaseOrder.items)) }}</strong>
                          </div>

                          <div class="summary-row">
                            <span>DPP</span>
                            <strong>Rp {{ formatNumberWithoutRp(detailPurchaseOrder.dpp || 0) }}</strong>
                          </div>

                          <div class="summary-row">
                            <span>PPN</span>
                            <strong>Rp {{ formatNumberWithoutRp(detailPurchaseOrder.ppn || 0) }}</strong>
                          </div>

                          <VDivider class="my-2" />
                        </template>

                        <div class="summary-row grand-total">
                          <span>Grand Total PO</span>
                          <strong>Rp {{ formatNumberWithoutRp(detailPurchaseOrder.total_nilai || calcPOTotal(detailPurchaseOrder.items)) }}</strong>
                        </div>
                      </VCardText>
                    </VCard>
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>
          </div>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end px-6 py-4">
          <VBtn
            v-if="detailPurchaseOrder && canApprovePO(detailPurchaseOrder)"
            color="success"
            variant="tonal"
            prepend-icon="tabler-circle-check"
            class="text-none"
            :disabled="approveLoading"
            @click="openApprovePO(detailPurchaseOrder)"
          >
            Approve
          </VBtn>

          <VBtn
            v-if="detailPurchaseOrder && canApprovePO(detailPurchaseOrder)"
            color="error"
            variant="tonal"
            prepend-icon="mdi-close-circle-outline"
            class="text-none"
            :disabled="rejectLoading"
            @click="openRejectPO(detailPurchaseOrder)"
          >
            Reject
          </VBtn>

          <VBtn
            variant="tonal"
            @click="detailDialog = false"
            class="text-none"
          >
            Tutup
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="signatureDialog"
      max-width="720"
      persistent
      scrollable
      class="signature-register-dialog"
    >
      <VCard class="signature-card">
        <VCardText class="pa-0">
          <div class="signature-header">
            <div class="signature-icon">
              ✍️
            </div>

            <div>
              <h3 class="signature-title">
                Registrasi Tanda Tangan Digital
              </h3>
              <p class="signature-subtitle">
                Tanda tangan ini cukup dibuat satu kali dan akan digunakan kembali pada proses transaksi berikutnya.
              </p>
            </div>
          </div>

          <div class="signature-alert">
            <strong>Mengapa diperlukan?</strong>
            <p>
              Sistem memerlukan tanda tangan digital Anda sebelum melakukan submit atau approval.
              Tanda tangan ini akan digunakan pada seluruh cetakan dokumen yang membutuhkan persetujuan,
              seperti proses submit ke approval maupun approval transaksi.
            </p>
          </div>

          <div class="signature-section-title">
            Silakan tanda tangan pada area berikut
          </div>

          <div class="signature-box">
            <canvas
              ref="signatureCanvasRef"
              class="signature-canvas"
            />
          </div>

          <div class="signature-action-row">
            <span class="signature-hint">
              Gunakan mouse, touchpad, atau layar sentuh.
            </span>

            <VBtn
              variant="text"
              color="error"
              size="small"
              :disabled="signatureLoading"
              @click="signaturePad?.clear()"
            >
              Clear
            </VBtn>
          </div>

          <div class="signature-agreement">
            <VCheckbox
              v-model="signatureAgree"
              density="compact"
              hide-details
              :disabled="signatureLoading"
            />

            <span>
              Saya menyetujui penggunaan tanda tangan digital ini sebagai identitas persetujuan saya
              pada dokumen dan transaksi yang memerlukan proses submit atau approval di sistem.
            </span>
          </div>

          <div
            v-if="signatureError"
            class="signature-error"
          >
            {{ signatureError }}
          </div>
        </VCardText>

        <VDivider />

        <VCardActions class="signature-footer">
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="signatureLoading"
            @click="signatureDialog = false"
            class="text-none"
          >
            Batal
          </VBtn>

          <VBtn
            color="primary"
            :loading="signatureLoading"
            @click="saveSignatureAndContinue"
            class="text-none"
          >
            Simpan & Lanjutkan
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="approveDialog"
      max-width="560"
      persistent
    >
      <VCard class="rounded-lg">
        <VCardItem>
          <template #prepend>
            <VAvatar
              color="success"
              variant="tonal"
              rounded
            >
              <VIcon icon="tabler-circle-check" />
            </VAvatar>
          </template>

          <VCardTitle>
            {{ t('purchaseOrder.list.toast.approveConfirmTitle') }}
          </VCardTitle>

          <VCardSubtitle>
            {{ t('purchaseOrder.list.toast.approveDialogSubtitle') }}
          </VCardSubtitle>
        </VCardItem>

        <VDivider />

        <VCardText class="pt-5">
          <VAlert
            color="success"
            variant="tonal"
            icon="tabler-info-circle"
            class="mb-5"
          >
            Purchase Order
            <strong>
              "{{ selectedPo?.nomor_po || '-' }}"
            </strong>
            {{ t('purchaseOrder.list.toast.approveAlertSuffix') }}
          </VAlert>

          <VTextarea
            v-model="approveNotes"
            :label="t('purchaseOrder.list.toast.approveNotesLabel')"
            :placeholder="t('purchaseOrder.list.toast.approveNotesPlaceholder')"
            variant="outlined"
            rows="4"
            auto-grow
            counter="2000"
            maxlength="2000"
            :disabled="approveLoading"
            :hint="t('purchaseOrder.list.toast.approveNotesHint')"
            persistent-hint
          />
        </VCardText>

        <VDivider />

        <VCardActions class="px-6 py-4">
          <VSpacer />

          <VBtn
            color="secondary"
            variant="tonal"
            :disabled="approveLoading"
            @click="approveDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="success"
            prepend-icon="tabler-circle-check"
            :loading="approveLoading"
            @click="approvePurchaseOrder"
          >
            {{ t('purchaseOrder.list.toast.approveButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="rejectDialog"
      max-width="560"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center gap-2">
          <VIcon
            icon="mdi-close-circle-outline"
            color="error"
          />
          {{ t('purchaseOrder.list.toast.rejectDialogTitle') }}
        </VCardTitle>

        <VDivider />

        <VCardText>
          <p class="text-body-2 mb-4">
            {{ t('purchaseOrder.list.toast.rejectDialogText') }}
            <strong>{{ rejectTarget?.nomor_po || '-' }}</strong>
          </p>

          <VTextarea
            v-model="rejectNotes"
            :label="t('purchaseOrder.list.toast.rejectNotesLabel')"
            :placeholder="t('purchaseOrder.list.toast.rejectNotesPlaceholder')"
            rows="4"
            auto-grow
            clearable
            :disabled="rejectLoading"
          />

          <div class="text-caption text-medium-emphasis mt-2">
            {{ t('purchaseOrder.list.toast.rejectNotesHint') }}
          </div>
        </VCardText>

        <VDivider />

        <VCardActions>
          <VSpacer />

          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="rejectLoading"
            @click="rejectDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="error"
            :loading="rejectLoading"
            @click="rejectPurchaseOrder"
          >
            {{ t('purchaseOrder.list.toast.rejectButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="cancelDialog"
      max-width="560"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center gap-2">
          <VIcon
            icon="tabler-ban"
            color="error"
          />
          {{ t('purchaseOrder.list.toast.cancelDialogTitle') }}
        </VCardTitle>

        <VDivider />

        <VCardText>
          <p class="text-body-2 mb-4">
            {{ t('purchaseOrder.list.toast.cancelDialogText') }}
            <strong>{{ cancelTarget?.nomor_po || '-' }}</strong>
          </p>

          <VAlert
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ t('purchaseOrder.list.toast.cancelIrreversibleWarning') }}
          </VAlert>

          <VTextarea
            v-model="cancelNotes"
            :label="t('purchaseOrder.list.toast.cancelNotesLabel')"
            :placeholder="t('purchaseOrder.list.toast.cancelNotesPlaceholder')"
            rows="4"
            auto-grow
            clearable
            :disabled="cancelLoading"
          />

          <div class="text-caption text-medium-emphasis mt-2">
            {{ t('purchaseOrder.list.toast.cancelNotesHint') }}
          </div>
        </VCardText>

        <VDivider />

        <VCardActions>
          <VSpacer />

          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="cancelLoading"
            @click="cancelDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="error"
            :loading="cancelLoading"
            @click="cancelPurchaseOrder"
          >
            {{ t('purchaseOrder.list.toast.cancelConfirmButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
    <ApprovalHistoryDialog
      v-model="isApprovalHistoryDialogOpen"
      :nomor-po="selectedPONomor"
      :approvals="selectedApprovalHistory"
    />
  </section>
</template>

<style lang="scss">
.text-capitalize { text-transform: capitalize; }
</style>

<style lang="scss" scoped>

.po-detail-card {
  border-radius: 10px !important;
}

.po-detail-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.10),
    rgba(var(--v-theme-primary), 0.02)
  );
}

.po-info-card,
.total-card {
  border: 1px solid rgba(var(--v-theme-primary), 0.14);
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.07),
    rgba(var(--v-theme-surface), 1)
  );
}

.info-box {
  min-height: 68px;
  padding: 14px 16px;
  border-radius: 14px;
  background: rgba(var(--v-theme-surface), 0.76);
  border: 1px solid rgba(var(--v-theme-primary), 0.10);
}

.info-label {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.56);
  margin-bottom: 4px;
}

.info-value {
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 0.86);
  word-break: break-word;
}

.related-pr-item {
  padding: 10px 12px;
  border-radius: 14px;
  background: rgba(var(--v-theme-primary), 0.08);
}

.related-pr-number {
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
  line-height: 1.3;
}

.related-pr-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 4px;
  color: rgba(var(--v-theme-on-surface), 0.62);
  font-size: 12px;
  white-space: nowrap;
}

.summary-row {
  display: flex;
  justify-content: space-between;
  gap: 50px;
  margin-block: 6px;
  font-size: medium;
  color: rgba(var(--v-theme-on-surface), 0.72);
}

.detail-po-table-wrapper {
  width: 100%;
  overflow-x: auto;
  border-radius: 18px;
  border: 1px solid rgba(var(--v-theme-primary), 0.08);
  background: white;
}

.detail-po-table {
  min-width: 980px;
}

.detail-po-table :deep(table) {
  border-collapse: separate;
  border-spacing: 0;
}

.detail-po-table th {
  background: rgba(var(--v-theme-primary), 0.05);
  color: rgba(var(--v-theme-on-surface), 0.78);
  font-size: 13px;
  font-weight: 700;
  padding: 16px 14px !important;
  white-space: nowrap;
  border-bottom: 1px solid rgba(var(--v-theme-primary), 0.08);
}

.detail-po-table td {
  padding: 14px !important;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.05);
  vertical-align: middle;
  background: white;
}

.detail-po-table tbody tr {
  transition: all 0.2s ease;
}

.detail-po-table tbody tr:hover td {
  background: rgba(var(--v-theme-primary), 0.025);
}

.col-no {
  width: 70px;
}

.col-item {
  width: 280px;
}

.col-qty {
  width: 130px;
}

.col-unit {
  width: 120px;
}

.col-money {
  width: 180px;
}

.col-note {
  width: 240px;
}

.table-number {
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 0.65);
}

.item-main {
  font-weight: 700;
  font-size: 14px;
  color: rgba(var(--v-theme-on-surface), 0.86);
  line-height: 1.4;
  white-space: normal;
  word-break: break-word;
}

.qty-wrapper {
  display: flex;
  justify-content: center;
}

.qty-chip {
  min-width: 44px;
  justify-content: center;
  font-weight: 700;
}

.money-text {
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.72);
  white-space: nowrap;
}

.subtotal-text {
  font-weight: 800;
  font-size: 14px;
  color: rgba(var(--v-theme-on-surface), 0.86);
  white-space: nowrap;
}

.note-text {
  color: rgba(var(--v-theme-on-surface), 0.64);
  line-height: 1.5;
  white-space: normal;
  word-break: break-word;
}

@media (max-width: 768px) {
  .detail-po-table {
    min-width: 900px;
  }
}

.item-title {
  font-weight: 700;
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
  line-height: 1.35;
}

.text-wrap-cell {
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
  color: rgba(var(--v-theme-on-surface), 0.68);
}

@media (max-width: 768px) {
  .detail-po-table,
  .detail-po-table :deep(table) {
    min-width: 860px;
    width: 860px;
  }
}

.summary-table {
  width: 100%;
  border-collapse: collapse;
}

.summary-table td {
  padding: 6px 20px;
}

.label-col {
  width: 100%;
}

.currency-col {
  width: 40px;
  text-align: right;
  white-space: nowrap;
  font-weight: 600;
}

.amount-col {
  width: 180px;
  text-align: right;
  white-space: nowrap;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.grand-total-row td {
  padding-top: 14px;
  font-size: 16px;
  font-weight: 700;
}

.divider-row td {
  padding-top: 10px;
  padding-bottom: 10px;
}

.user-pagination-select {
  .v-field__input,
  .v-field__append-inner {
    padding-block-start: 0.3rem;
  }
}

.vendor-detail-content {
  display: flex;
  flex-direction: column;
  gap: 32px;
}

.detail-section {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding-top: 20px;
}

.detail-section-title {
  font-size: 1.05rem;
  font-weight: 700;
  margin-bottom: 18px;
}

.detail-item {
  margin-bottom: 16px;
}

.detail-label {
  font-size: 0.78rem;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin-bottom: 4px;
}

.detail-value {
  font-size: 0.98rem;
  font-weight: 500;
  word-break: break-word;
  line-height: 1.6;
}

.pkp-split-row {
  align-items: stretch;
}

.pkp-col {
  padding-top: 4px;
  padding-bottom: 4px;
}

.pkp-col-right {
  border-left: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

@media (max-width: 959px) {
  .pkp-col-right {
    border-left: none;
    border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    margin-top: 16px;
    padding-top: 20px;
  }
}

// SCROLL LIST PR TERKAIT
.related-pr-scroll {
  max-height: 320px;
  overflow-y: auto;
  padding-right: 4px;
}

.related-pr-scroll::-webkit-scrollbar {
  width: 6px;
}

.related-pr-scroll::-webkit-scrollbar-thumb {
  border-radius: 999px;
  background: rgba(var(--v-theme-primary), 0.25);
}

.pr-slide-enter-active {
  transition: all 0.26s ease;
}

.pr-slide-enter-from {
  opacity: 0;
  transform: translateY(-8px);
}

.pr-slide-enter-to {
  opacity: 1;
  transform: translateY(0);
}

// Signature Pad

.signature-box {
  width: 100%;
  height: 220px;
  border: 2px dashed rgb(var(--v-theme-primary));
  border-radius: 14px;
  background: #fff;
  overflow: hidden;
}

.signature-canvas {
  width: 100%;
  height: 220px;
  display: block;
  cursor: crosshair;
}

.signature-register-dialog {
  .v-overlay__content {
    width: calc(100% - 32px);
    margin: 16px;
  }
}

.signature-card {
  border-radius: 22px;
  overflow: hidden;
}

.signature-header {
  display: flex;
  gap: 16px;
  padding: 24px 28px 16px;
  background: linear-gradient(135deg, rgba(var(--v-theme-primary), 0.12), rgba(var(--v-theme-primary), 0.03));
}

.signature-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 48px;
  width: 48px;
  height: 48px;
  border-radius: 16px;
  background: rgb(var(--v-theme-primary));
  color: white;
  font-size: 24px;
}

.signature-title {
  margin: 0;
  font-size: 22px;
  font-weight: 800;
  color: rgba(var(--v-theme-on-surface), 0.92);
}

.signature-subtitle {
  margin: 6px 0 0;
  font-size: 14px;
  line-height: 1.6;
  color: rgba(var(--v-theme-on-surface), 0.68);
}

.signature-alert {
  margin: 20px 28px 0;
  padding: 14px 16px;
  border-radius: 16px;
  background: rgba(var(--v-theme-warning), 0.12);
  border: 1px solid rgba(var(--v-theme-warning), 0.35);
  color: rgba(var(--v-theme-on-surface), 0.82);
}

.signature-alert strong {
  display: block;
  margin-bottom: 4px;
  font-size: 14px;
}

.signature-alert p {
  margin: 0;
  font-size: 13px;
  line-height: 1.65;
}

.signature-section-title {
  margin: 20px 28px 10px;
  font-size: 13px;
  font-weight: 700;
  color: rgba(var(--v-theme-on-surface), 0.78);
}

.signature-box {
  margin: 0 28px;
  width: auto;
  height: 240px;
  border: 2px dashed rgb(var(--v-theme-primary));
  border-radius: 18px;
  background: #fff;
  overflow: hidden;
}

.signature-canvas {
  width: 100%;
  height: 240px;
  display: block;
  cursor: crosshair;
  touch-action: none;
}

.signature-action-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  margin: 8px 28px 0;
}

.signature-hint {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.55);
}

.signature-agreement {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  margin: 18px 28px 0;
  font-size: 13px;
  line-height: 1.6;
  color: rgba(var(--v-theme-on-surface), 0.78);
  word-break: normal;
  overflow-wrap: anywhere;
}

.signature-error {
  margin: 10px 28px 0;
  font-size: 13px;
  color: rgb(var(--v-theme-error));
}

.signature-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 16px 28px;
}

@media (max-width: 600px) {
  .signature-header {
    padding: 20px 18px 14px;
  }

  .signature-title {
    font-size: 18px;
  }

  .signature-subtitle {
    font-size: 13px;
  }

  .signature-alert,
  .signature-section-title,
  .signature-box,
  .signature-action-row,
  .signature-agreement,
  .signature-error {
    margin-left: 18px;
    margin-right: 18px;
  }

  .signature-box {
    height: 190px;
  }

  .signature-canvas {
    height: 190px;
  }

  .signature-footer {
    padding: 14px 18px;
    flex-direction: column-reverse;
  }

  .signature-footer .v-btn {
    width: 100%;
  }
}


.po-filter-card {
  border: 1px solid rgba(var(--v-theme-primary), 0.08);
}

.po-filter-grid {
  row-gap: 16px;
}

.approval-filter-box {
  min-height: 40px;
  padding: 8px 14px;
  border-radius: 14px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgba(var(--v-theme-surface), 0.86);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  transition: all 0.2s ease;
}

.approval-filter-box.is-active {
  border-color: rgba(var(--v-theme-warning), 0.42);
  background: rgba(var(--v-theme-warning), 0.09);
}

.approval-filter-title {
  line-height: 1.25;
}

.approval-filter-subtitle {
  line-height: 1.25;
  white-space: normal;
}

.po-number-action {
  cursor: pointer;
  padding: 6px 8px;
  border-radius: 10px;
  transition: background-color 0.18s ease;
}

.po-number-action:hover {
  background: rgba(var(--v-theme-primary), 0.08);
}

@media (max-width: 960px) {
  .approval-filter-box {
    align-items: flex-start;
  }
}

.po-row-need-approval {
  background: rgba(var(--v-theme-warning), 0.055);

  &:hover {
    background: rgba(var(--v-theme-warning), 0.09);
  }
}

.related-pr-attachments {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 8px;
}

.related-pr-attachment-title {
  font-size: 11px;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.55);
}

.related-pr-attachment-btn {
  width: 100%;
  justify-content: flex-start;
  min-height: 30px;
  border-radius: 10px;
}

.related-pr-attachment-text {
  display: inline-block;
  max-width: 210px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: left;
}


.approval-simple-box {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: linear-gradient(
    135deg,
    rgba(var(--v-theme-primary), 0.08),
    rgba(var(--v-theme-surface), 0.96)
  );
}

.approval-simple-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-height: 190px;
  overflow-y: auto;
  padding-right: 4px;
}

.approval-simple-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px;
  border-radius: 14px;
  background: rgba(var(--v-theme-surface), 0.78);
  border: 1px solid rgba(var(--v-border-color), 0.55);
}

.approval-step-circle {
  width: 26px;
  height: 26px;
  min-width: 26px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary));
  font-size: 12px;
  font-weight: 700;
}

.approval-simple-content {
  min-width: 0;
  flex: 1;
}

.approval-simple-title {
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.86);
}

.approval-simple-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 6px;
  color: rgba(var(--v-theme-on-surface), 0.62);
  font-size: 12px;
}

.approval-simple-meta span {
  display: inline-flex;
  align-items: center;
  min-width: 0;
}

.approval-simple-notes {
  margin-top: 6px;
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.68);
  white-space: pre-line;
}

@media (max-width: 960px) {
  .approval-simple-list {
    max-height: none;
  }
}
</style>
