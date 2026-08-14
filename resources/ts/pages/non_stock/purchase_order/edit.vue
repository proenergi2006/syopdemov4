<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, toRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import {
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showWarningToast,
  closeAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'
import {
  formatNumberWithoutRp,
  formatDate,
  sanitizeDecimalInput,
  parseDecimalInput,
  formatDecimalQty,
  toTitleCase,
  onlyNumberKeypress,
  formatSanitizedNumberInput,
} from '@/utils/textFormatter'
import { usePermissionStore } from '@/stores/permission'

interface PurchaseOrderForm {
  tanggal_po: string
  vendor_id: number | null
  vendor_name: string
  cabang: number | null
  id_department: number | null
  jenis_pembayaran: string
  top: number | null
  notes: string
  purchase_request_ids: number[]
  lampiran_po: File[]
}

interface ExistingPoAttachment {
  id: number
  filename?: string
  original_filename?: string
  filepath?: string
  file_size?: number
  mime_type?: string
}

interface VendorOption {
  id: number
  id_department?: number | null
  nama_vendor: string
  jenis_pembayaran?: string | null
  top?: number | null
  status_pkp?: string | null
}

interface PurchaseRequestOption {
  id: number
  public_id: string
  nomor_pr: string
  tanggal_pr: string
  cabang: string
  department: string
  total_amount: number
  items?: PurchaseOrderItem[]
  recommended_vendor_id?: number | null
  attachments?: Array<{
    id: number
    filename?: string
    original_filename?: string
    filepath?: string
    file_size?: number
    mime_type?: string
  }>
  recommended_vendor?: {
    id: number
    nama_vendor: string
    status_pkp?: string | null
    jenis_pembayaran?: string | null
    top?: number | null
  } | null
}

interface PurchaseOrderItem {
  purchase_request_id: number
  purchase_request_item_id: number
  nomor_pr: string
  nama_item: string
  qty_pr: number
  qty_po_existing: number
  qty_outstanding: number
  qty: number
  satuan_id: number
  satuan: string
  keterangan: string
  harga_unit: number
  subtotal: number
  is_selected: boolean
}

interface POItemState {
  is_selected: boolean
  qty: number
  harga_unit: number
}

const permissionStore = usePermissionStore()
const { t } = useI18n()

const canUpdate = computed(() => {
  return permissionStore.can('purchase_order.update')
})

/*
|--------------------------------------------------------------------------
| Department Scope Permission
|--------------------------------------------------------------------------
| Hak membuka halaman tetap memakai purchase_order.update.
| Batas department mengikuti scope purchase_order.create karena endpoint
| dropdown PR dan sumber PO menggunakan aturan department creator PO.
|--------------------------------------------------------------------------
*/

const departmentPermissionCode = 'purchase_order.create'

const departmentPermissionScope = computed(() => {
  return permissionStore.scope(
    departmentPermissionCode,
  )
})

const assignedEditDepartmentIds = computed<number[]>(() => {
  return permissionStore.departmentIds(
    departmentPermissionCode,
  )
})

const isCheckingPermission = ref(true)

const route = useRoute()
const router = useRouter()

const publicId = computed(() => String(route.query.id || ''))

const isSubmitted = ref(false)
const isSaving = ref(false)
const isLoadingDetail = ref(true)
const isInitialLoaded = ref(false)
const loadError = ref('')

const prPage = ref(1)
const prPerPage = ref<number | 'ALL'>(5)

const vendorList = ref<VendorOption[]>([])
const cabangList = ref<any[]>([])
const departmentList = ref<any[]>([])
const purchaseRequestList = ref<PurchaseRequestOption[]>([])
const poItems = ref<PurchaseOrderItem[]>([])
const visibleAttachmentMap = ref<Record<number, number>>({})

const existingPOItemMap = ref<Record<number, PurchaseOrderItem>>({})
const existingPOItemCompositeMap = ref<Record<string, PurchaseOrderItem>>({})
const poItemStateMap = ref<Record<number, POItemState>>({})
const initialPurchaseRequestIds = ref<number[]>([])
const previousSelectedPurchaseRequestIds = ref<number[]>([])

const isLoadingVendor = ref(false)
const isLoadingCabang = ref(false)
const isLoadingDepartment = ref(false)
const isLoadingPR = ref(false)

const form = reactive<PurchaseOrderForm>({
  tanggal_po: '',
  vendor_id: null,
  vendor_name: '',
  cabang: null,
  id_department: null,
  jenis_pembayaran: '',
  top: null,
  notes: '',
  purchase_request_ids: [],
  lampiran_po: [],
})

const existingLampiranPo = ref<ExistingPoAttachment[]>([])
const fileRef = ref<HTMLInputElement | null>(null)
const lampiranError = ref('')

const MAX_FILE_SIZE = 3 * 1024 * 1024
const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png']

const getExtension = (fileName: string): string => {
  return fileName.split('.').pop()?.toLowerCase() || ''
}

const triggerFileInput = (): void => {
  fileRef.value?.click()
}

const handleFileUpload = (event: Event): void => {
  const input = event.target as HTMLInputElement
  if (!input.files) return

  lampiranError.value = ''

  const invalidMessages: string[] = []

  for (const file of Array.from(input.files)) {
    const ext = getExtension(file.name)
    const validMime = ALLOWED_TYPES.includes(file.type)
    const validExt = ALLOWED_EXTENSIONS.includes(ext)

    if (!validMime && !validExt) {
      invalidMessages.push(t('common.attachment.invalidType', { file: file.name }))
      continue
    }

    if (file.size > MAX_FILE_SIZE) {
      invalidMessages.push(t('common.attachment.tooLarge', { file: file.name }))
      continue
    }

    const exists = form.lampiran_po.some(
      existing => existing.name === file.name && existing.size === file.size,
    )

    if (!exists) form.lampiran_po.push(file)
  }

  if (invalidMessages.length) {
    lampiranError.value = invalidMessages.join(' ')

    showWarningToast({
      title: t('common.attachment.invalidTitle'),
      text: invalidMessages.join(' '),
    })
  }

  input.value = ''
}

const removeLampiran = (index: number): void => {
  form.lampiran_po.splice(index, 1)
}

const removeExistingLampiran = (index: number): void => {
  existingLampiranPo.value.splice(index, 1)
}

const formatFileSize = (bytes: number): string => {
  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

const formatExistingFileSize = (bytes?: number): string => {
  if (!bytes) return '-'

  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

const getFileType = (file: File): string => {
  return file.type === 'application/pdf' ? 'PDF' : 'IMAGE'
}

const getExistingFileType = (file: ExistingPoAttachment): string => {
  const name = file.original_filename || file.filename || ''

  return getExtension(name) === 'pdf' ? 'PDF' : 'IMAGE'
}

/*
|--------------------------------------------------------------------------
| User dan Department Access
|--------------------------------------------------------------------------
*/

const currentUser = computed<Record<string, any>>(() => {
  try {
    return JSON.parse(
      localStorage.getItem('userData') || '{}',
    )
  }
  catch {
    return {}
  }
})

const ownDepartmentId = computed<number>(() => {
  return Number(
    currentUser.value?.department_id
    ?? currentUser.value?.departemen_id
    ?? 0,
  )
})

/*
|--------------------------------------------------------------------------
| Allowed Department IDs
|--------------------------------------------------------------------------
| null berarti seluruh department diizinkan.
|--------------------------------------------------------------------------
*/

const allowedEditDepartmentIds = computed<number[] | null>(() => {
  const scope = departmentPermissionScope.value

  if (scope === 'ALL')
    return null

  if (scope === 'OWN_DEPARTMENT') {
    return ownDepartmentId.value > 0
      ? [ownDepartmentId.value]
      : []
  }

  if (scope === 'ASSIGNED_DEPARTMENTS') {
    return Array.from(
      new Set(
        assignedEditDepartmentIds.value
          .map(id => Number(id))
          .filter(id => id > 0),
      ),
    )
  }

  return []
})

const availableDepartmentList = computed(() => {
  const allowedDepartmentIds
    = allowedEditDepartmentIds.value

  if (allowedDepartmentIds === null)
    return departmentList.value

  const allowedDepartmentSet = new Set(
    allowedDepartmentIds.map(id => Number(id)),
  )

  return departmentList.value.filter(department => {
    return allowedDepartmentSet.has(
      Number(department.id),
    )
  })
})

const isDepartmentLocked = computed<boolean>(() => {
  return departmentPermissionScope.value
    === 'OWN_DEPARTMENT'
})

const hasValidEditDepartmentScope = computed<boolean>(() => {
  return [
    'OWN_DEPARTMENT',
    'ASSIGNED_DEPARTMENTS',
    'ALL',
  ].includes(
    departmentPermissionScope.value,
  )
})

const canUseDepartmentForEdit = (
  departmentId: number | null,
): boolean => {
  const normalizedDepartmentId = Number(
    departmentId || 0,
  )

  if (normalizedDepartmentId <= 0)
    return false

  const allowedDepartmentIds
    = allowedEditDepartmentIds.value

  if (allowedDepartmentIds === null)
    return true

  return allowedDepartmentIds.includes(
    normalizedDepartmentId,
  )
}

const isDepartmentAvailableForEdit = (
  departmentId: number | null,
): boolean => {
  const normalizedDepartmentId = Number(
    departmentId || 0,
  )

  if (
    normalizedDepartmentId <= 0
    || !canUseDepartmentForEdit(
      normalizedDepartmentId,
    )
  ) {
    return false
  }

  return availableDepartmentList.value.some(
    department =>
      Number(department.id)
      === normalizedDepartmentId,
  )
}

const tanggalPO = useNativeDatePicker(toRef(form, 'tanggal_po'))

const required = (value: unknown): boolean => {
  return value !== '' && value !== null && value !== undefined
}

const formatMoney = (value: number | string | null | undefined): string => {
  return formatNumberWithoutRp(Number(value || 0))
}

const onlyNumber = (event: KeyboardEvent): void => {
  onlyNumberKeypress(event)
}

const normalizeText = (value: unknown): string => {
  return String(value || '')
    .trim()
    .toUpperCase()
    .replace(/\s+/g, ' ')
}

const getCompositeItemKey = (
  purchaseRequestId: number,
  namaItem: string,
): string => {
  return `${Number(purchaseRequestId || 0)}::${normalizeText(namaItem)}`
}

const getPRItemMergeKey = (item: any): string => {
  const id = Number(
    item.purchase_request_item_id
    ?? item.id
    ?? item.purchase_request_item?.id
    ?? item.purchaseRequestItem?.id
    ?? 0,
  )

  if (id) return `ID:${id}`

  return `NAME:${normalizeText(item.nama_item)}`
}

const mergePRItems = (
  existingItems: any[] = [],
  incomingItems: any[] = [],
): any[] => {
  const map = new Map<string, any>()

  existingItems.forEach(item => {
    map.set(getPRItemMergeKey(item), item)
  })

  incomingItems.forEach(item => {
    const key = getPRItemMergeKey(item)
    const existing = map.get(key)

    map.set(key, {
      ...existing,
      ...item,
    })
  })

  return Array.from(map.values())
}

const isCreditPayment = computed(() => {
  return String(form.jenis_pembayaran || '').toUpperCase() === 'TOP'
})

const selectedVendorStatusPKP = computed(() => {
  const vendor = vendorList.value.find(item => Number(item.id) === Number(form.vendor_id))

  return vendor?.status_pkp || 'NON_PKP'
})

const isVendorPKP = computed(() => {
  return String(selectedVendorStatusPKP.value).toUpperCase() === 'PKP'
})

const selectedPOItems = computed(() => {
  return poItems.value.filter(item => item.is_selected !== false)
})

const subtotal = computed(() => {
  return selectedPOItems.value.reduce((total, item) => {
    return total + (Number(item.qty || 0) * Number(item.harga_unit || 0))
  }, 0)
})

const dpp = computed(() => {
  return isVendorPKP.value ? (subtotal.value * 11) / 12 : 0
})

const ppn = computed(() => {
  return isVendorPKP.value ? Math.round(dpp.value * 0.12) : 0
})

const grandTotal = computed(() => {
  return isVendorPKP.value ? subtotal.value + ppn.value : subtotal.value
})

const prPerPageItems = [
  { title: '5', value: 5 },
  { title: '10', value: 10 },
  { title: '25', value: 25 },
  { title: '50', value: 50 },
  { title: 'All', value: 'ALL' },
]

const paginatedPurchaseRequests = computed(() => {
  if (prPerPage.value === 'ALL') return purchaseRequestList.value

  const start = (prPage.value - 1) * Number(prPerPage.value)
  const end = start + Number(prPerPage.value)

  return purchaseRequestList.value.slice(start, end)
})

const prTotalPage = computed(() => {
  if (prPerPage.value === 'ALL') return 1

  return Math.ceil(purchaseRequestList.value.length / Number(prPerPage.value)) || 1
})

const isAllSelected = computed(() => {
  if (!purchaseRequestList.value.length) return false

  return purchaseRequestList.value.every(pr =>
    form.purchase_request_ids.includes(pr.id),
  )
})

const groupedPOItems = computed(() => {
  const groups = new Map<string, PurchaseOrderItem[]>()

  poItems.value.forEach(item => {
    const key = item.nomor_pr || '-'

    if (!groups.has(key)) {
      groups.set(key, [])
    }

    groups.get(key)?.push(item)
  })

  return Array.from(groups.entries()).map(([nomor_pr, items]) => ({
    nomor_pr,
    items,
  }))
})

const selectedRecommendedVendors = computed(() => {
  const selectedPRs = purchaseRequestList.value.filter(pr =>
    form.purchase_request_ids.includes(pr.id),
  )

  const vendors = selectedPRs
    .map(pr => pr.recommended_vendor)
    .filter(Boolean) as any[]

  const unique = new Map<number, any>()

  vendors.forEach(vendor => {
    unique.set(Number(vendor.id), vendor)
  })

  return Array.from(unique.values())
})

const mergePurchaseRequests = (incoming: PurchaseRequestOption[]): void => {
  const map = new Map<number, PurchaseRequestOption>()

  purchaseRequestList.value.forEach(pr => {
    map.set(Number(pr.id), pr)
  })

  incoming.forEach(pr => {
    const prId = Number(pr.id)
    const existing = map.get(prId)

    /*
     * Ditampung ke variabel dulu agar TypeScript dapat mempersempit tipenya.
     * Pola sebelumnya (Array.isArray(existing?.items) ? existing.items : [])
     * aman saat runtime, tapi TS tetap menganggap `existing` bisa undefined.
     */
    const existingRawItems = existing?.items

    const existingItems = Array.isArray(existingRawItems)
      ? existingRawItems
      : []

    const incomingItems = Array.isArray(pr.items)
      ? pr.items
      : []

    const mergedItems = mergePRItems(existingItems, incomingItems)

    map.set(prId, {
      ...existing,
      ...pr,

      /*
      |--------------------------------------------------------------------------
      | PENTING
      |--------------------------------------------------------------------------
      | Jangan replace items mentah-mentah.
      | Karena dropdown-approved bisa tidak membawa item yang sudah fully masuk PO.
      |--------------------------------------------------------------------------
      */
      items: mergedItems,
    })
  })

  purchaseRequestList.value = Array.from(map.values())
}

const captureCurrentPOItemState = (): void => {
  poItems.value.forEach(item => {
    const key = Number(item.purchase_request_item_id)

    if (!key) return

    poItemStateMap.value[key] = {
      is_selected: item.is_selected !== false,
      qty: Number(item.qty || 0),
      harga_unit: Number(item.harga_unit || 0),
    }
  })
}

const getSavedPOItemState = (purchaseRequestItemId: number): POItemState | null => {
  return poItemStateMap.value[Number(purchaseRequestItemId)] || null
}

const getVisibleAttachmentCount = (prId: number): number => {
  return visibleAttachmentMap.value[prId] || 1
}

const visibleAttachments = (pr: PurchaseRequestOption) => {
  const attachments = pr.attachments || []
  const count = getVisibleAttachmentCount(pr.id)

  return attachments.slice(0, count)
}

const hasMoreAttachments = (pr: PurchaseRequestOption): boolean => {
  const attachments = pr.attachments || []

  return getVisibleAttachmentCount(pr.id) < attachments.length
}

const showMoreAttachments = (pr: PurchaseRequestOption): void => {
  visibleAttachmentMap.value[pr.id] = getVisibleAttachmentCount(pr.id) + 5
}

const showLessAttachments = (pr: PurchaseRequestOption): void => {
  visibleAttachmentMap.value[pr.id] = 1
}


/*
|--------------------------------------------------------------------------
| Detail Purchase Request
|--------------------------------------------------------------------------
*/
const purchaseRequestDetailDialog = ref(false)
const selectedPurchaseRequestDetail = ref<any>(null)

const purchaseRequestDetailItemPage = ref(1)
const purchaseRequestDetailItemPerPage = ref<number | 'ALL'>(5)

const purchaseRequestDetailItemPerPageItems = [
  { title: '5', value: 5 },
  { title: '10', value: 10 },
  { title: '20', value: 20 },
  { title: '50', value: 50 },
  { title: 'All', value: 'ALL' },
]

const purchaseRequestDetailItems = computed<any[]>(() => {
  const detail = selectedPurchaseRequestDetail.value as any

  const items =
    detail?.items
    ?? detail?.purchase_request_items
    ?? detail?.purchaseRequestItems
    ?? detail?.details
    ?? []

  return Array.isArray(items)
    ? items
    : []
})

const purchaseRequestDetailAttachments = computed<any[]>(() => {
  const detail = selectedPurchaseRequestDetail.value as any

  const attachments =
    detail?.attachments
    ?? detail?.files
    ?? detail?.lampiran
    ?? []

  return Array.isArray(attachments)
    ? attachments
    : []
})

const purchaseRequestDetailItemTotalPage = computed(() => {
  if (purchaseRequestDetailItemPerPage.value === 'ALL')
    return 1

  return Math.ceil(
    purchaseRequestDetailItems.value.length / Number(purchaseRequestDetailItemPerPage.value),
  ) || 1
})

const paginatedPurchaseRequestDetailItems = computed(() => {
  if (purchaseRequestDetailItemPerPage.value === 'ALL')
    return purchaseRequestDetailItems.value

  const start = (Number(purchaseRequestDetailItemPage.value) - 1) * Number(purchaseRequestDetailItemPerPage.value)
  const end = start + Number(purchaseRequestDetailItemPerPage.value)

  return purchaseRequestDetailItems.value.slice(start, end)
})

const purchaseRequestDetailTotalAmount = computed(() => {
  const detail = selectedPurchaseRequestDetail.value as any

  const value =
    detail?.total_amount
    ?? detail?.grand_total
    ?? detail?.total_nilai
    ?? detail?.total

  if (value !== null && value !== undefined)
    return Number(value || 0)

  return purchaseRequestDetailItems.value.reduce((total: number, item: any) => {
    const qty = Number(item.qty ?? item.quantity ?? 0)
    const hargaUnit = Number(item.harga_unit ?? item.price ?? item.unit_price ?? 0)
    const subtotalItem = Number(item.subtotal ?? item.total ?? 0)

    return total + (subtotalItem || (qty * hargaUnit))
  }, 0)
})

const getPurchaseRequestDetailStatusColor = (status?: string | null): string => {
  const normalized = String(status || '').trim().toUpperCase()

  if (normalized === 'APPROVED')
    return 'success'

  if (normalized === 'IN PROGRESS')
    return 'info'

  if (normalized === 'DRAFT')
    return 'warning'

  if (normalized === 'REJECTED')
    return 'error'

  return 'secondary'
}

const formatPurchaseRequestDetailFileSize = (size: number | string | null | undefined): string => {
  const bytes = Number(size || 0)

  if (!bytes)
    return '-'

  const kb = bytes / 1024

  if (kb < 1024)
    return `${kb.toFixed(2)} KB`

  return `${(kb / 1024).toFixed(2)} MB`
}

const openPurchaseRequestDetail = async (publicId: string): Promise<void> => {
  if (!publicId) {
    showErrorToast({
      title: t('common.alert.error'),
      text: t('purchaseOrder.create.toast.prPublicIdNotFound'),
    })

    return
  }

  try {
    purchaseRequestDetailItemPage.value = 1
    purchaseRequestDetailItemPerPage.value = 5

    showLoadingAlert(
      'Memuat detail Purchase Request',
      'Mohon tunggu sebentar',
    )

    const response = await axios.get(
      `/transaction/purchase-request/${encodeURIComponent(publicId)}`,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    selectedPurchaseRequestDetail.value = response.data?.data ?? null

    closeAlert()

    await nextTick()

    purchaseRequestDetailDialog.value = true
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, 'Gagal memuat detail Purchase Request.'),
    })
  }
}

const closePurchaseRequestDetail = (): void => {
  purchaseRequestDetailDialog.value = false
  selectedPurchaseRequestDetail.value = null
}

const loadVendors = async (showAlert = true): Promise<void> => {
  isLoadingVendor.value = true

  try {
    const res = await axios.get('/master/vendor/dropdown-select', {
      headers: { Accept: 'application/json' },
      params: {
        id_department: form.id_department,
      },
    })

    const data = Array.isArray(res.data?.data) ? res.data.data : []

    vendorList.value = data.map((item: any) => ({
      id: Number(item.id),
      id_department: item.id_department ? Number(item.id_department) : null,
      nama_vendor: item.nama_vendor || item.title || '-',
      jenis_pembayaran: item.jenis_pembayaran || null,
      top: item.top ? Number(item.top) : null,
      status_pkp: item.status_pkp || 'NON_PKP',
    }))
  } catch (error: unknown) {
    vendorList.value = []

    if (showAlert) {
      showErrorToast({
        title: t('common.alert.error'),
        text: getApiErrorMessage(error, 'Gagal memuat data vendor'),
      })
    }
  } finally {
    isLoadingVendor.value = false
  }
}

const fetchCabangList = async (showAlert = true): Promise<void> => {
  isLoadingCabang.value = true

  try {
    const response = await axios.get('/master/cabang/dropdown-select', {
      headers: { Accept: 'application/json' },
    })

    cabangList.value = Array.isArray(response.data?.data)
      ? response.data.data.map((item: any) => ({
          id: Number(item.id),
          title: `${item.inisial_cabang || '-'} - ${item.nama_cabang || item.title || '-'}`,
          nama: item.nama_cabang || item.title || '-',
          inisial_cabang: item.inisial_cabang || '',
        }))
      : []
  } catch (error: unknown) {
    cabangList.value = []

    if (showAlert) {
      showErrorToast({
        title: t('common.alert.error'),
        text: getApiErrorMessage(error, 'Gagal memuat data cabang'),
      })
    }
  } finally {
    isLoadingCabang.value = false
  }
}

const fetchDepartmentList = async (showAlert = true): Promise<void> => {
  isLoadingDepartment.value = true

  try {
    const response = await axios.get('/master/department/dropdown-select', {
      headers: { Accept: 'application/json' },
    })

    departmentList.value = Array.isArray(response.data?.data)
      ? response.data.data.map((item: any) => ({
          id: Number(item.id),
          kode: item.kode || '',
          nama: item.nama || item.title || '-',
          label: `${item.kode || '-'} - ${item.nama || item.title || '-'}`,
        }))
      : []
  } catch (error: unknown) {
    departmentList.value = []

    if (showAlert) {
      showErrorToast({
        title: t('common.alert.error'),
        text: getApiErrorMessage(error, 'Gagal memuat data department'),
      })
    }
  } finally {
    isLoadingDepartment.value = false
  }
}

const ensureSelectedVendorExists = (detail: any): void => {
  const vendorId = Number(
    detail?.vendor_data?.vendor_id
    ?? detail?.vendor_data?.id
    ?? detail?.vendor_id
    ?? 0,
  )

  if (!vendorId) return

  const exists = vendorList.value.some(item => Number(item.id) === vendorId)

  if (exists) return

  vendorList.value.unshift({
    id: vendorId,
    id_department: detail?.department_id ? Number(detail.department_id) : null,
    nama_vendor: detail?.vendor_data?.nama_vendor ?? detail?.vendor ?? `Vendor #${vendorId}`,
    jenis_pembayaran: detail?.vendor_data?.jenis_pembayaran ?? detail?.jenis_pembayaran ?? null,
    top: detail?.vendor_data?.top ? Number(detail.vendor_data.top) : null,
    status_pkp: detail?.vendor_data?.status_pkp ?? detail?.status_pkp ?? 'NON_PKP',
  })
}

const handleSelectVendor = (): void => {
  const vendor = vendorList.value.find(item => Number(item.id) === Number(form.vendor_id))

  if (!vendor) {
    form.vendor_name = ''
    form.jenis_pembayaran = ''
    form.top = null

    return
  }

  form.vendor_id = Number(vendor.id)
  form.vendor_name = vendor.nama_vendor || ''
  form.jenis_pembayaran = vendor.jenis_pembayaran || ''
  form.top = vendor.top ?? null
}

const loadPurchaseRequestsByFilter = async (): Promise<void> => {
  if (!form.cabang || !form.id_department) return

  visibleAttachmentMap.value = {}
  isLoadingPR.value = true

  try {
    const response = await axios.get('/transaction/purchase-request/dropdown-approved', {
      headers: { Accept: 'application/json' },
      params: {
        cabang: form.cabang,
        id_department: form.id_department,
      },
    })

    const rows: PurchaseRequestOption[] = Array.isArray(response.data?.data)
      ? response.data.data.map((item: any) => ({
          id: Number(item.id),
          public_id: item.public_id,
          nomor_pr: item.nomor_pr,
          tanggal_pr: item.tanggal_pr,
          cabang: item.cabang,
          department: item.department,
          total_amount: Number(item.total_amount || 0),
          recommended_vendor_id: item.recommended_vendor_id
            ? Number(item.recommended_vendor_id)
            : null,
          recommended_vendor: item.recommended_vendor || null,
          items: Array.isArray(item.items) ? item.items : [],
          attachments: Array.isArray(item.attachments) ? item.attachments : [],
        }))
      : []

    mergePurchaseRequests(rows)
  } catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, 'Gagal memuat Purchase Request'),
    })
  } finally {
    isLoadingPR.value = false
  }
}

const handleSelectPRFilter = async (): Promise<void> => {
  form.purchase_request_ids = []
  form.vendor_id = null
  form.vendor_name = ''
  form.jenis_pembayaran = ''
  form.top = null
  poItems.value = []
  purchaseRequestList.value = []
  vendorList.value = []
  existingPOItemMap.value = {}
  existingPOItemCompositeMap.value = {}
  poItemStateMap.value = {}
  initialPurchaseRequestIds.value = []
  previousSelectedPurchaseRequestIds.value = []
  prPage.value = 1

  if (form.id_department) {
    await loadVendors(false)
  }

  if (form.cabang && form.id_department) {
    await loadPurchaseRequestsByFilter()
  }
}

const getItemSatuanId = (item: any): number => {
  return Number(
    item.unit?.id
    ?? item.satuan_id
    ?? item.purchase_request_item?.unit?.id
    ?? item.purchase_request_item?.satuan_id
    ?? item.purchaseRequestItem?.unit?.id
    ?? item.purchaseRequestItem?.satuan_id
    ?? item.satuan?.id
    ?? 0,
  )
}

const getItemSatuanName = (item: any): string => {
  return (
    item.unit?.nama
    ?? item.unit?.kode
    ?? item.purchase_request_item?.unit?.nama
    ?? item.purchase_request_item?.unit?.kode
    ?? item.purchaseRequestItem?.unit?.nama
    ?? item.purchaseRequestItem?.unit?.kode
    ?? item.satuan?.nama
    ?? item.satuan?.kode
    ?? item.satuan
    ?? '-'
  )
}

const getPurchaseRequestItemId = (item: any): number => {
  return Number(
    item.purchase_request_item_id
    ?? item.purchase_request_item?.id
    ?? item.purchaseRequestItem?.id
    ?? item.id
    ?? 0,
  )
}

const getPOPurchaseRequestItemId = (item: any): number => {
  return Number(
    item.purchase_request_item?.id
    ?? item.purchaseRequestItem?.id
    ?? item.purchase_request_item_id
    ?? 0,
  )
}

const findExistingPOItem = (
  prItemId: number,
  purchaseRequestId: number,
  namaItem: string,
): PurchaseOrderItem | null => {
  if (prItemId && existingPOItemMap.value[prItemId]) {
    return existingPOItemMap.value[prItemId]
  }

  const compositeKey = getCompositeItemKey(purchaseRequestId, namaItem)

  return existingPOItemCompositeMap.value[compositeKey] || null
}

const updatePOItemSubtotal = (index: number): void => {
  const item = poItems.value[index]

  if (!item)
    return

  if (item.is_selected === false) {
    item.subtotal = 0
    return
  }

  item.subtotal = Number(item.qty || 0) * Number(item.harga_unit || 0)
}

const togglePOItemSelection = (item: PurchaseOrderItem): void => {
  const index = poItems.value.findIndex(row => {
    return Number(row.purchase_request_item_id) === Number(item.purchase_request_item_id)
  })

  updatePOItemSubtotal(index)

  poItemStateMap.value[Number(item.purchase_request_item_id)] = {
    is_selected: item.is_selected !== false,
    qty: Number(item.qty || 0),
    harga_unit: Number(item.harga_unit || 0),
  }
}

const handleSelectPurchaseRequest = (): void => {
  captureCurrentPOItemState()

  const previousSelectedSet = new Set(
    previousSelectedPurchaseRequestIds.value.map(id => Number(id)),
  )

  const currentSelectedSet = new Set(
    form.purchase_request_ids.map(id => Number(id)),
  )

  const selectedPRs = purchaseRequestList.value.filter(pr =>
    currentSelectedSet.has(Number(pr.id)),
  )

  const nextItems: PurchaseOrderItem[] = []

  selectedPRs.forEach(pr => {
    const prId = Number(pr.id)
    const prItems = pr.items || []
    const isInitialPR = initialPurchaseRequestIds.value.includes(prId)
    const isNewlySelectedPR = !previousSelectedSet.has(prId)
    const shouldAutoSelectAllItems = isNewlySelectedPR && isInitialLoaded.value

    if (isNewlySelectedPR) {
      prItems.forEach((item: any) => {
        const prItemId = getPurchaseRequestItemId(item)

        if (prItemId) {
          delete poItemStateMap.value[prItemId]
        }
      })

      Object.values(existingPOItemMap.value)
        .filter(item => Number(item.purchase_request_id) === prId)
        .forEach(item => {
          delete poItemStateMap.value[Number(item.purchase_request_item_id)]
        })
    }

    prItems.forEach((item: any) => {
      const prItemId = getPurchaseRequestItemId(item)
      const namaItem = item.nama_item || '-'
      const existingItem = findExistingPOItem(prItemId, prId, namaItem)
      const effectivePrItemId = prItemId || Number(existingItem?.purchase_request_item_id || 0)

      if (!effectivePrItemId) return

      const savedState = getSavedPOItemState(effectivePrItemId)

      const qtyOutstandingRaw = Number(item.qty_outstanding ?? item.qty ?? 0)
      const defaultHargaUnit = Number(item.harga_unit || existingItem?.harga_unit || 0)

      if (existingItem) {
        const qty = savedState
          ? Number(savedState.qty || 0)
          : Number(existingItem.qty || 0)

        const hargaUnit = savedState
          ? Number(savedState.harga_unit || 0)
          : Number(existingItem.harga_unit || defaultHargaUnit || 0)

        const isSelected = shouldAutoSelectAllItems
        ? true
        : savedState
          ? savedState.is_selected !== false
          : true

        nextItems.push({
          ...existingItem,
          purchase_request_id: prId,
          purchase_request_item_id: effectivePrItemId,
          nomor_pr: pr.nomor_pr || existingItem.nomor_pr,
          nama_item: existingItem.nama_item || namaItem,
          is_selected: isSelected,
          qty,
          harga_unit: hargaUnit,
          satuan_id: Number(existingItem.satuan_id || item.satuan_id || item.satuan?.id || item.unit?.id || 0),
          satuan: existingItem.satuan || item.satuan?.nama || item.satuan?.kode || item.unit?.nama || item.unit?.kode || item.satuan || '-',
          subtotal: isSelected
            ? Number(qty || 0) * hargaUnit
            : 0,
        })

        return
      }

      if (qtyOutstandingRaw <= 0) return

      const savedQty = savedState
        ? Number(savedState.qty || 0)
        : qtyOutstandingRaw

      const hargaUnit = savedState
        ? Number(savedState.harga_unit || 0)
        : defaultHargaUnit

      const defaultSelected = shouldAutoSelectAllItems
        ? true
        : isInitialPR
          ? false
          : true

      const isSelected = shouldAutoSelectAllItems
        ? true
        : savedState
          ? savedState.is_selected !== false
          : defaultSelected

      nextItems.push({
        purchase_request_id: prId,
        purchase_request_item_id: effectivePrItemId,
        nomor_pr: pr.nomor_pr,
        nama_item: namaItem,
        qty_pr: Number(item.qty || 0),
        qty_po_existing: Number(item.qty_po || 0),
        qty_outstanding: qtyOutstandingRaw,
        qty: savedQty,
        satuan_id: Number(item.satuan_id ?? item.satuan?.id ?? item.unit?.id ?? 0),
        satuan: item.satuan?.nama || item.satuan?.kode || item.unit?.nama || item.unit?.kode || item.satuan || '-',
        keterangan: item.keterangan || '-',
        harga_unit: hargaUnit,
        subtotal: isSelected ? Number(savedQty || 0) * hargaUnit : 0,
        is_selected: isSelected,
      })
    })

    if (!prItems.length) {
      Object.values(existingPOItemMap.value)
        .filter(item => Number(item.purchase_request_id) === prId)
        .forEach(existingItem => {
          const savedState = getSavedPOItemState(existingItem.purchase_request_item_id)

          const qty = savedState
            ? Number(savedState.qty || 0)
            : Number(existingItem.qty || 0)

          const hargaUnit = savedState
            ? Number(savedState.harga_unit || 0)
            : Number(existingItem.harga_unit || 0)

          const isSelected = shouldAutoSelectAllItems
          ? true
          : savedState
            ? savedState.is_selected !== false
            : true

          nextItems.push({
            ...existingItem,
            is_selected: isSelected,
            qty,
            harga_unit: hargaUnit,
            satuan_id: Number(existingItem.satuan_id || 0),
            satuan: existingItem.satuan || '-',
            subtotal: isSelected
              ? Number(qty || 0) * hargaUnit
              : 0,
          })
        })
    }
  })

  poItems.value = nextItems

  previousSelectedPurchaseRequestIds.value = form.purchase_request_ids.map(id => Number(id))
}

const toggleSelectAllPR = async (value: boolean | null): Promise<void> => {
  captureCurrentPOItemState()

  if (Boolean(value)) {
    form.purchase_request_ids = purchaseRequestList.value.map(pr => pr.id)
  } else {
    form.purchase_request_ids = []
  }

  handleSelectPurchaseRequest()
}

const handlePOQtyInput = (value: string | number, index: number): void => {
  const item = poItems.value[index]
  if (!item) return

  const sanitized = sanitizeDecimalInput(value, {
    maxIntegerLength: 12,
    maxDecimalLength: 2,
  })

  const qty = parseDecimalInput(sanitized)
  const maxQty = Number(item.qty_outstanding || 0)

  if (qty > maxQty) {
    item.qty = maxQty

    showWarningToast({
      title: t('purchaseOrder.create.toast.qtyExceedsOutstandingTitle'),
      text: t('purchaseOrder.create.toast.qtyExceedsOutstandingText', { item: item.nama_item, max: formatDecimalQty(maxQty) }),
    })
  } else {
    item.qty = qty
  }

  updatePOItemSubtotal(index)

  poItemStateMap.value[Number(item.purchase_request_item_id)] = {
    is_selected: item.is_selected !== false,
    qty: Number(item.qty || 0),
    harga_unit: Number(item.harga_unit || 0),
  }
}

const handlePOItemPriceInput = (event: Event, index: number): void => {
  const item = poItems.value[index]

  if (!item)
    return

  const target = event.target as HTMLInputElement

  const result = formatSanitizedNumberInput(
    target.value,
    formatMoney,
    {
      maxLength: 12,
      emptyAsZero: true,
    },
  )

  item.harga_unit = result.numeric ?? 0

  updatePOItemSubtotal(index)

  poItemStateMap.value[Number(item.purchase_request_item_id)] = {
    is_selected: item.is_selected !== false,
    qty: Number(item.qty || 0),
    harga_unit: Number(item.harga_unit || 0),
  }

  target.value = result.formatted
}

const handlePOItemPricePaste = (event: ClipboardEvent, index: number): void => {
  const item = poItems.value[index]

  if (!item)
    return

  const pastedText = event.clipboardData?.getData('text') || ''

  if (!/^\d+$/.test(pastedText.trim())) {
    event.preventDefault()

    showErrorToast({
      title: t('purchaseOrder.create.toast.invalidInputTitle'),
      text: t('purchaseOrder.create.toast.priceNumericOnly'),
    })

    return
  }

  const target = event.target as HTMLInputElement
  const harga = Number(pastedText)

  item.harga_unit = harga

  updatePOItemSubtotal(index)

  poItemStateMap.value[Number(item.purchase_request_item_id)] = {
    is_selected: item.is_selected !== false,
    qty: Number(item.qty || 0),
    harga_unit: Number(item.harga_unit || 0),
  }

  target.value = formatMoney(harga)
}

const mapEditDetailToForm = async (detail: any): Promise<void> => {
  form.tanggal_po = detail.tanggal_po || ''
  form.cabang = detail.cabang_id ? Number(detail.cabang_id) : null
  form.id_department = detail.department_id ? Number(detail.department_id) : null
  form.notes = detail.notes || ''

  existingLampiranPo.value = Array.isArray(detail.attachments)
    ? detail.attachments.map((file: any) => ({
        id: file.id,
        filename: file.filename,
        original_filename: file.original_filename,
        filepath: file.filepath,
        file_size: file.file_size,
        mime_type: file.mime_type,
      }))
    : []

  form.lampiran_po = []

  form.vendor_id = Number(
    detail?.vendor_data?.vendor_id
    ?? detail?.vendor_data?.id
    ?? detail?.vendor_id
    ?? 0,
  ) || null

  form.vendor_name = detail?.vendor_data?.nama_vendor ?? detail?.vendor ?? ''
  form.jenis_pembayaran = detail?.vendor_data?.jenis_pembayaran ?? detail?.jenis_pembayaran ?? ''
  form.top = detail?.vendor_data?.top ? Number(detail.vendor_data.top) : null

  const purchaseRequests = Array.isArray(detail.purchase_requests)
    ? detail.purchase_requests
    : []

  const prMap = new Map<number, any>()

  purchaseRequests.forEach((pr: any) => {
    prMap.set(Number(pr.id), pr)
  })

  const selectedPrIds = purchaseRequests.map((pr: any) => Number(pr.id))

  form.purchase_request_ids = selectedPrIds
  initialPurchaseRequestIds.value = selectedPrIds
  previousSelectedPurchaseRequestIds.value = []

  const existingPRRows: PurchaseRequestOption[] = purchaseRequests.map((pr: any) => ({
    id: Number(pr.id),
    public_id: pr.public_id || '',
    nomor_pr: pr.nomor_pr || '-',
    tanggal_pr: pr.tanggal_pr || '',
    cabang: detail.cabang || '-',
    department: detail.department || '-',
    total_amount: Number(pr.total_amount || 0),
    recommended_vendor_id: pr.recommended_vendor_id ? Number(pr.recommended_vendor_id) : null,
    recommended_vendor: pr.recommended_vendor || null,
    attachments: Array.isArray(pr.attachments) ? pr.attachments : [],
    items: Array.isArray(pr.items) ? pr.items : [],
  }))

  mergePurchaseRequests(existingPRRows)

  poItems.value = Array.isArray(detail.items)
    ? detail.items.map((item: any) => {
        const prItem = item.purchase_request_item || item.purchaseRequestItem || null

        const purchaseRequestId = Number(
          item.purchase_request_id
          || prItem?.purchase_request_id
          || item.purchase_request?.id
          || selectedPrIds[0]
          || 0,
        )

        const pr = prMap.get(purchaseRequestId)

        const currentQty = Number(item.qty || 0)
        const qtyPr = Number(prItem?.qty_pr || prItem?.qty || item.qty_pr || currentQty)
        const qtyPoFromPR = Number(prItem?.qty_po || item.qty_po || 0)
        const rawOutstanding = Number(prItem?.qty_outstanding || item.qty_outstanding || 0)

        const qtyPoExisting = Math.max(qtyPoFromPR - currentQty, 0)
        const editableOutstanding = rawOutstanding + currentQty
        const hargaUnit = Number(item.harga_unit || 0)
        const satuanId = getItemSatuanId(item)
        const purchaseRequestItemId = getPOPurchaseRequestItemId(item)

        return {
          purchase_request_id: purchaseRequestId,
          purchase_request_item_id: purchaseRequestItemId,
          nomor_pr: item.nomor_pr || pr?.nomor_pr || '-',
          nama_item: item.nama_item || prItem?.nama_item || '-',
          qty_pr: qtyPr,
          qty_po_existing: qtyPoExisting,
          qty_outstanding: editableOutstanding,
          qty: currentQty,
          satuan_id: satuanId,
          satuan: getItemSatuanName(item),
          keterangan: item.keterangan || '-',
          harga_unit: hargaUnit,
          subtotal: currentQty * hargaUnit,
          is_selected: true,
        }
      })
    : []

  existingPOItemMap.value = {}
  existingPOItemCompositeMap.value = {}
  poItemStateMap.value = {}

  poItems.value.forEach(item => {
    const prItemId = Number(item.purchase_request_item_id)
    const compositeKey = getCompositeItemKey(item.purchase_request_id, item.nama_item)

    if (prItemId) {
      existingPOItemMap.value[prItemId] = {
        ...item,
        is_selected: true,
      }

      poItemStateMap.value[prItemId] = {
        is_selected: true,
        qty: Number(item.qty || 0),
        harga_unit: Number(item.harga_unit || 0),
      }
    }

    existingPOItemCompositeMap.value[compositeKey] = {
      ...item,
      is_selected: true,
    }
  })

  if (form.id_department) {
    await loadVendors(false)
    ensureSelectedVendorExists(detail)
  }

  if (form.cabang && form.id_department) {
    await loadPurchaseRequestsByFilter()
    handleSelectPurchaseRequest()
  }

  if (form.vendor_id) {
    handleSelectVendor()
  }
}

const loadPurchaseOrderDetail = async (): Promise<void> => {
  if (!publicId.value) {
    loadError.value = 'ID Purchase Order tidak ditemukan.'
    isLoadingDetail.value = false
    return
  }

  isLoadingDetail.value = true
  isInitialLoaded.value = false
  loadError.value = ''

  try {
    const response = await axios.get(`/transaction/purchase-order/${publicId.value}/edit`, {
      headers: { Accept: 'application/json' },
    })

    const detail = response.data?.data

    if (!detail) {
      throw new Error('Data Purchase Order tidak ditemukan.')
    }

    const detailDepartmentId = Number(
      detail?.department_id
      ?? detail?.id_department
      ?? 0,
    )

    /*
    |--------------------------------------------------------------------------
    | Validasi department PO existing
    |--------------------------------------------------------------------------
    | Jangan mengubah department PO secara otomatis. User hanya boleh membuka
    | dan mengedit PO apabila department existing masih termasuk aksesnya.
    |--------------------------------------------------------------------------
    */
    if (
      !isDepartmentAvailableForEdit(
        detailDepartmentId,
      )
    ) {
      loadError.value
        = 'Anda tidak memiliki akses untuk mengubah Purchase Order pada department ini.'

      return
    }

    await mapEditDetailToForm(detail)

    isInitialLoaded.value = true
  } catch (error: unknown) {
    loadError.value = getApiErrorMessage(error, 'Gagal memuat detail Purchase Order.')
  } finally {
    isLoadingDetail.value = false
  }
}

const validateForm = async (): Promise<boolean> => {
  if (
    !required(form.vendor_id)
    || !required(form.tanggal_po)
    || !required(form.cabang)
    || !required(form.id_department)
    || !required(form.jenis_pembayaran)
  ) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseOrder.create.toast.completeRequiredData'),
    })

    return false
  }

  if (
    !isDepartmentAvailableForEdit(
      form.id_department,
    )
  ) {
    showErrorToast({
      title: t('purchaseOrder.create.toast.departmentNotAllowedTitle'),
      text: t('purchaseOrder.edit.toast.departmentNotAllowedText'),
    })

    return false
  }

  if (!existingLampiranPo.value.length && !form.lampiran_po.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseOrder.create.toast.minAttachmentRequired'),
    })

    return false
  }

  if (!form.purchase_request_ids.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseOrder.create.toast.selectMinOnePr'),
    })

    return false
  }

  if (!poItems.value.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseOrder.create.toast.itemsNotAvailable'),
    })

    return false
  }

  if (!selectedPOItems.value.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseOrder.create.toast.selectMinOneItem'),
    })

    return false
  }

  const selectedPurchaseRequestsWithoutItem = purchaseRequestList.value.filter(pr => {
    const isPrSelected = form.purchase_request_ids.includes(Number(pr.id))

    if (!isPrSelected) return false

    const hasSelectedItem = poItems.value.some(item => {
      return Number(item.purchase_request_id) === Number(pr.id)
        && item.is_selected !== false
    })

    return !hasSelectedItem
  })

  if (selectedPurchaseRequestsWithoutItem.length > 0) {
    const nomorPrList = selectedPurchaseRequestsWithoutItem
      .map(pr => pr.nomor_pr || '-')
      .join(', ')

    showWarningToast({
      title: t('common.alert.warning'),
      text: `Setiap PR yang dipilih wajib memiliki minimal 1 item PO. PR tanpa item: ${nomorPrList}`,
    })

    return false
  }

  for (const item of selectedPOItems.value) {
    if (!Number(item.satuan_id || 0)) {
      showErrorToast({
        title: t('purchaseOrder.create.toast.invalidUnitTitle'),
        text: t('purchaseOrder.create.toast.invalidUnitText', { item: item.nama_item }),
      })

      return false
    }
  }

  const invalidItemIndex = selectedPOItems.value.findIndex(item =>
    !item.purchase_request_id
    || !item.purchase_request_item_id
    || !item.qty
    || Number(item.qty) <= 0
    || Number(item.qty) > Number(item.qty_outstanding)
    || !item.nama_item
    || !item.satuan
    || Number(item.harga_unit) <= 0,
  )

  if (invalidItemIndex !== -1) {
    const item = selectedPOItems.value[invalidItemIndex]

    showWarningToast({
      title: t('common.alert.warning'),
      text: `Qty dan harga PO item "${item.nama_item || '-'}" wajib lebih dari 0, dan qty tidak boleh melebihi outstanding.`,
    })

    return false
  }

  const itemIds = selectedPOItems.value.map(item => Number(item.purchase_request_item_id))
  const uniqueItemIds = new Set(itemIds)

  if (itemIds.length !== uniqueItemIds.size) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseOrder.create.toast.duplicateItemWarning'),
    })

    return false
  }

  return true
}

const buildPayload = () => {
  const items = selectedPOItems.value.map(item => {
    const qty = Number(item.qty || 0)
    const hargaUnit = Number(item.harga_unit || 0)

    return {
      purchase_request_id: Number(item.purchase_request_id),
      purchase_request_item_id: Number(item.purchase_request_item_id),
      nama_item: item.nama_item,
      qty,
      satuan: Number(item.satuan_id || 0),
      keterangan: item.keterangan,
      harga_unit: hargaUnit,
      subtotal: qty * hargaUnit,
      qty_pr: Number(item.qty_pr || 0),
      qty_po_existing: Number(item.qty_po_existing || 0),
      qty_outstanding: Number(item.qty_outstanding || 0),
    }
  })

  const purchaseRequestIds = Array.from(
    new Set(items.map(item => Number(item.purchase_request_id))),
  )

  return {
    tanggal_po: form.tanggal_po,
    vendor_id: Number(form.vendor_id),
    cabang: Number(form.cabang),
    id_department: Number(form.id_department),
    jenis_pembayaran: form.jenis_pembayaran,
    top: isCreditPayment.value ? Number(form.top || 0) : null,
    notes: form.notes || '',
    purchase_request_ids: purchaseRequestIds,
    subtotal: Number(subtotal.value || 0),
    dpp: Number(dpp.value || 0),
    ppn: Number(ppn.value || 0),
    total_nilai: Number(grandTotal.value || 0),
    items,
  }
}

const buildFormData = (): FormData => {
  const payload = buildPayload()
  const formData = new FormData()

  formData.append('_method', 'PUT')

  formData.append('tanggal_po', payload.tanggal_po)
  formData.append('vendor_id', String(payload.vendor_id))
  formData.append('cabang', String(payload.cabang))
  formData.append('id_department', String(payload.id_department))

  formData.append('jenis_pembayaran', payload.jenis_pembayaran || '')

  if (payload.top !== null)
    formData.append('top', String(payload.top))

  formData.append('notes', payload.notes || '')

  payload.purchase_request_ids.forEach(id => {
    formData.append('purchase_request_ids[]', String(id))
  })

  formData.append('subtotal', String(payload.subtotal))
  formData.append('dpp', String(payload.dpp))
  formData.append('ppn', String(payload.ppn))
  formData.append('total_nilai', String(payload.total_nilai))

  payload.items.forEach((item, index) => {
    Object.entries(item).forEach(([key, value]) => {
      formData.append(`items[${index}][${key}]`, String(value))
    })
  })

  formData.append(
    'existing_attachment_ids',
    JSON.stringify(existingLampiranPo.value.map(file => file.id)),
  )

  form.lampiran_po.forEach(file => {
    formData.append('lampiran_po[]', file)
  })

  return formData
}

const updatePurchaseOrder = async (): Promise<void> => {
  if (isSaving.value) return

  isSubmitted.value = true

  const isValid = await validateForm()
  if (!isValid) return

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: t('purchaseOrder.edit.toast.updateConfirmTitle'),
    text: t('purchaseOrder.edit.toast.updateConfirmText'),
    confirmButtonText: t('purchaseOrder.edit.toast.updateConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed) return

  isSaving.value = true

  try {
    showLoadingAlert(t('purchaseOrder.form.updatingData'), t('common.alert.pleaseWait'))

    await axios.post(`/transaction/purchase-order/${publicId.value}`, buildFormData(), {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    await router.replace({
      path: '/non_stock/purchase_order',
      query: { success: 'updated' },
    })
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, 'Gagal memperbarui Purchase Order'),
    })
  } finally {
    isSaving.value = false
  }
}

const goBack = async (): Promise<void> => {
  await router.replace('/non_stock/purchase_order')
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    icon: 'question',
    title: t('purchaseOrder.edit.toast.cancelConfirmTitle'),
    text: t('purchaseOrder.edit.toast.cancelConfirmText'),
    confirmButtonText: t('purchaseOrder.edit.toast.cancelConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (confirm.isConfirmed) {
    await router.replace('/non_stock/purchase_order')
  }
}

onMounted(async () => {
  /*
   * Force reload agar direct permission terbaru langsung digunakan.
   */
  await permissionStore.loadPermissions(true)

  if (
    !canUpdate.value
    || !hasValidEditDepartmentScope.value
  ) {
    await router.replace('/forbidden')

    return
  }

  isLoadingDetail.value = true
  isInitialLoaded.value = false

  try {
    await Promise.all([
      fetchCabangList(false),
      fetchDepartmentList(false),
    ])

    await loadPurchaseOrderDetail()
  }
  catch (error: unknown) {
    loadError.value = getApiErrorMessage(
      error,
      'Gagal memuat data Purchase Order.',
    )

    isLoadingDetail.value = false
  }
  finally {
    isCheckingPermission.value = false
  }
})
</script>

<template>
  <section>
    <VCard
      v-if="isLoadingDetail"
      class="mb-6 rounded-lg"
      elevation="2"
    >
      <VCardText class="pa-6">
        <div class="d-flex align-center">
          <VProgressCircular
            indeterminate
            color="primary"
            size="28"
            width="3"
            class="me-4"
          />

          <div>
            <div class="text-h6 font-weight-medium">
              {{ t('purchaseOrder.form.loadingTitle') }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('common.alert.pleaseWait') }}
            </div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VCard
      v-else-if="loadError"
      class="mb-6 rounded-lg"
      elevation="3"
    >
      <VCardText class="pa-6">
        <div class="d-flex align-start justify-space-between flex-wrap gap-4">
          <div class="d-flex align-start">
            <VAvatar
              size="44"
              color="error"
              variant="tonal"
              class="me-4"
            >
              <VIcon icon="tabler-alert-circle" size="24" />
            </VAvatar>

            <div>
              <div class="text-h6 font-weight-bold text-error mb-1">
                {{ loadError }}
              </div>

              <div class="text-caption text-disabled mt-2">
                {{ t('purchaseOrder.form.errorHint') }}
              </div>
            </div>
          </div>

          <div class="d-flex ga-2 flex-wrap">
            <VBtn
              color="primary"
              :loading="isLoadingDetail"
              prepend-icon="tabler-refresh"
              @click="loadPurchaseOrderDetail"
            >
              {{ t('purchaseOrder.form.retryButton') }}
            </VBtn>

            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-arrow-left"
              @click="goBack"
              class="text-none"
            >
              {{ t('purchaseOrder.form.backButton') }}
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VCard v-else-if="isInitialLoaded">
      <VCardTitle class="d-flex align-center justify-space-between">
        <div>
          <div class="text-h6 font-weight-bold">
            {{ t('purchaseOrder.form.editTitle') }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t('purchaseOrder.form.editSubtitle') }}
          </div>
        </div>

        <VBtn
          prepend-icon="mdi-arrow-left"
          variant="text"
          color="secondary"
          @click="goBack"
          class="text-none"
        >
          {{ t('purchaseOrder.form.backButton') }}
        </VBtn>
      </VCardTitle>

      <VDivider />

      <VCardText>
        <VRow>
          <VCol cols="12" md="6">
            <AppDateTimePicker
              v-model="form.tanggal_po"
              :label="t('purchaseOrder.create.fields.tanggalPo')"
              :placeholder="t('purchaseOrder.placeholders.tanggalPo')"
              :config="{ dateFormat: 'Y-m-d' }"
              :error="isSubmitted && !form.tanggal_po"
              :error-messages="isSubmitted && !form.tanggal_po ? ['Tanggal PO wajib diisi'] : []"
            />
          </VCol>
          <!-- <VCol cols="12" md="6">
            <div class="position-relative">
              <VTextField
                :model-value="tanggalPO.displayValue.value"
                :label="t('purchaseOrder.create.fields.tanggalPo')"
                :placeholder="t('purchaseOrder.placeholders.dateFormat')"
                readonly
                append-inner-icon="tabler-calendar"
                :error="isSubmitted && !form.tanggal_po"
                :error-messages="isSubmitted && !form.tanggal_po ? ['Tanggal PO wajib diisi'] : []"
                @click="tanggalPO.openPicker"
                @click:append-inner="tanggalPO.openPicker"
              />

              <input
                :ref="(el) => {
                  tanggalPO.nativeDateRef.value = el as HTMLInputElement | null
                }"
                type="date"
                :value="form.tanggal_po"
                class="native-date-hidden"
                tabindex="-1"
                aria-hidden="true"
                @change="tanggalPO.onDateChange"
              >
            </div>
          </VCol> -->

          <VCol cols="12" md="6" />

          <VCol cols="12" md="6">
            <VAutocomplete
              v-model="form.cabang"
              :label="t('purchaseOrder.create.fields.cabang')"
              :items="cabangList"
              item-title="title"
              item-value="id"
              clearable
              density="comfortable"
              :loading="isLoadingCabang"
              :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
              :error="isSubmitted && !form.cabang"
              :error-messages="isSubmitted && !form.cabang ? ['Cabang wajib dipilih'] : []"
              :placeholder="t('purchaseOrder.placeholders.cabang')"
              @update:model-value="handleSelectPRFilter"
            />
          </VCol>

          <VCol cols="12" md="6">
            <VAutocomplete
              v-model="form.id_department"
              :label="t('purchaseOrder.create.fields.department')"
              :items="availableDepartmentList"
              item-title="label"
              item-value="id"
              density="comfortable"
              :loading="isLoadingDepartment"
              :disabled="
                isCheckingPermission
                  || isDepartmentLocked
              "
              :clearable="!isDepartmentLocked"
              persistent-hint
              :hint="
                departmentPermissionScope === 'OWN_DEPARTMENT'
                  ? 'Department dikunci sesuai department akun Anda.'
                  : departmentPermissionScope === 'ASSIGNED_DEPARTMENTS'
                    ? 'Hanya department yang ditetapkan pada direct permission yang dapat dipilih.'
                    : departmentPermissionScope === 'ALL'
                      ? 'Anda dapat mengubah Purchase Order untuk seluruh department.'
                      : 'Anda tidak memiliki akses department untuk mengubah Purchase Order.'
              "
              :no-data-text="t('purchaseOrder.noData.department')"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
              :error="
                isSubmitted
                  && !form.id_department
              "
              :error-messages="
                isSubmitted && !form.id_department
                  ? [
                      departmentPermissionScope === 'OWN_DEPARTMENT'
                        ? 'Department akun login tidak ditemukan.'
                        : departmentPermissionScope === 'ASSIGNED_DEPARTMENTS'
                          ? 'Pilih salah satu department yang telah ditetapkan.'
                          : departmentPermissionScope === 'ALL'
                            ? 'Department wajib dipilih.'
                            : 'Anda tidak memiliki akses department untuk mengubah Purchase Order.',
                    ]
                  : []
              "
              :placeholder="t('purchaseOrder.placeholders.department')"
              @update:model-value="handleSelectPRFilter"
            >
              <template #append-inner>
                <VProgressCircular
                  v-if="isLoadingDepartment"
                  indeterminate
                  size="18"
                  width="2"
                />

                <VIcon
                  v-else-if="
                    isDepartmentLocked
                      && form.id_department
                  "
                  icon="tabler-lock"
                  size="18"
                  color="secondary"
                />
              </template>
            </VAutocomplete>
          </VCol>

          <VCol cols="12">
            <div class="text-subtitle-1 font-weight-bold mb-3">
              Pilih Purchase Request *
            </div>

            <VAlert
              v-if="!form.cabang || !form.id_department"
              type="info"
              variant="tonal"
            >
              {{ t('purchaseOrder.prSection.selectBranchFirst') }}
            </VAlert>

            <div v-else>
              <div class="pr-select-table-wrapper">
                <VTable class="pr-select-table border rounded">
                  <thead>
                    <tr>
                      <th class="text-center col-check">
                        <VCheckbox
                          :model-value="isAllSelected"
                          hide-details
                          density="compact"
                          color="primary"
                          @update:model-value="toggleSelectAllPR"
                        />
                      </th>

                      <th class="col-pr">{{ t('purchaseOrder.prSection.tableNomorPr') }}</th>
                      <th class="col-attachment">{{ t('purchaseOrder.prSection.tableLampiran') }}</th>
                      <th class="text-center col-date">{{ t('purchaseOrder.prSection.tableTanggal') }}</th>
                      <th class="col-cabang">{{ t('purchaseOrder.prSection.tableCabang') }}</th>
                      <th class="col-department">{{ t('purchaseOrder.prSection.tableDepartment') }}</th>
                      <th class="text-end col-total">{{ t('purchaseOrder.prSection.tableTotalPr') }}</th>
                    </tr>
                  </thead>

                  <tbody>
                    <tr v-if="isLoadingPR">
                      <td colspan="7" class="text-center py-6">
                        {{ t('purchaseOrder.prSection.loading') }}
                      </td>
                    </tr>

                    <tr v-else-if="!purchaseRequestList.length">
                      <td colspan="7" class="text-center text-medium-emphasis py-6">
                        {{ t('purchaseOrder.prSection.emptyEdit') }}
                      </td>
                    </tr>

                    <tr
                      v-for="pr in paginatedPurchaseRequests"
                      v-else
                      :key="pr.id"
                    >
                      <td class="text-center col-check">
                        <VCheckbox
                          v-model="form.purchase_request_ids"
                          :value="pr.id"
                          hide-details
                          density="compact"
                          color="primary"
                          @update:model-value="handleSelectPurchaseRequest"
                        />
                      </td>

                      <td class="col-pr font-weight-medium pr-number-cell">
                        <VBtn
                          variant="text"
                          color="primary"
                          class="pr-number-action text-none px-0"
                          :disabled="!pr.public_id"
                          @click.stop="openPurchaseRequestDetail(pr.public_id)"
                        >
                          <span class="pr-number-text">
                            {{ pr.nomor_pr || '-' }}
                          </span>

                          <VIcon
                            icon="tabler-eye"
                            size="16"
                            class="ms-1"
                          />
                        </VBtn>
                      </td>

                      <td class="col-attachment pr-attachment-cell">
                        <div v-if="pr.attachments?.length">
                          <TransitionGroup
                            name="attachment-slide"
                            tag="div"
                            class="d-flex flex-column gap-1"
                          >
                            <a
                              v-for="file in visibleAttachments(pr)"
                              :key="file.id"
                              :href="file.filepath"
                              target="_blank"
                              class="pr-attachment-link"
                            >
                              <VIcon icon="tabler-paperclip" size="16" class="me-1" />
                              <span>{{ file.original_filename || file.filename || 'Lampiran PR' }}</span>
                            </a>
                          </TransitionGroup>

                          <div class="d-flex flex-wrap gap-1 mt-2">
                            <VBtn
                              v-if="hasMoreAttachments(pr)"
                              size="x-small"
                              variant="text"
                              color="primary"
                              prepend-icon="tabler-chevron-down"
                              @click.stop="showMoreAttachments(pr)"
                            >
                              {{ t('purchaseOrder.prSection.showMore') }}
                            </VBtn>

                            <VBtn
                              v-if="getVisibleAttachmentCount(pr.id) > 1"
                              size="x-small"
                              variant="text"
                              color="secondary"
                              prepend-icon="tabler-chevron-up"
                              @click.stop="showLessAttachments(pr)"
                            >
                              {{ t('purchaseOrder.prSection.showLess') }}
                            </VBtn>
                          </div>
                        </div>

                        <span v-else class="text-medium-emphasis text-caption">
                          {{ t('purchaseOrder.prSection.noAttachment') }}
                        </span>
                      </td>

                      <td class="text-center col-date">
                        {{ formatDate(pr.tanggal_pr) }}
                      </td>

                      <td class="col-cabang">
                        {{ pr.cabang || '-' }}
                      </td>

                      <td class="col-department">
                        {{ pr.department || '-' }}
                      </td>

                      <td class="text-end col-total">
                        Rp {{ formatNumberWithoutRp(pr.total_amount) }}
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </div>

              <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-3">
                <div class="text-caption text-medium-emphasis">
                  Total Purchase Request: {{ purchaseRequestList.length }}
                </div>

                <div class="d-flex align-center gap-3">
                  <VSelect
                    v-model="prPerPage"
                    :items="prPerPageItems"
                    item-title="title"
                    item-value="value"
                    density="compact"
                    hide-details
                    style="width: 110px;"
                    @update:model-value="prPage = 1"
                  />

                  <VPagination
                    v-if="prPerPage !== 'ALL' && purchaseRequestList.length > Number(prPerPage)"
                    v-model="prPage"
                    :length="prTotalPage"
                    size="small"
                    :total-visible="3"
                  />
                </div>
              </div>

              <div
                v-if="isSubmitted && !form.purchase_request_ids.length"
                class="text-error text-caption mt-2"
              >
                {{ t('purchaseOrder.prSection.required') }}
              </div>
            </div>
          </VCol>

          <VCol cols="12">
            <div class="text-subtitle-1 font-weight-bold mb-3">
              {{ t('purchaseOrder.itemSection.title') }}
            </div>

            <VAlert
              v-if="!poItems.length"
              type="info"
              variant="tonal"
              class="mb-0"
            >
              {{ t('purchaseOrder.itemSection.emptyHint') }}
            </VAlert>

            <div
              v-else
              class="d-flex flex-column gap-4"
            >
              <VCard
                v-for="group in groupedPOItems"
                :key="group.nomor_pr"
                class="po-item-group-card"
              >
                <VCardText>
                  <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
                    <div>
                      <div class="text-caption text-medium-emphasis">
                        {{ t('purchaseOrder.prDetail.nomorPr') }}
                      </div>
                      <div class="text-subtitle-2 font-weight-bold">
                        {{ group.nomor_pr }}
                      </div>
                    </div>

                    <VChip
                      size="small"
                      color="primary"
                      variant="tonal"
                    >
                      {{ group.items.filter((item: any) => item.is_selected !== false).length }} / {{ group.items.length }} Item
                    </VChip>
                  </div>

                  <div class="po-item-table-wrapper">
                    <VTable class="po-item-table">
                      <thead>
                        <tr>
                          <th class="text-center col-check">{{ t('purchaseOrder.itemSection.tablePilih') }}</th>
                          <th class="col-item">{{ t('purchaseOrder.itemSection.tableNamaItem') }}</th>
                          <th class="text-center col-qty">{{ t('purchaseOrder.itemSection.tableQtyPr') }}</th>
                          <th class="text-center col-qty">{{ t('purchaseOrder.itemSection.tableQtySudahPo') }}</th>
                          <th class="text-center col-qty">{{ t('purchaseOrder.itemSection.tableOutstanding') }}</th>
                          <th class="text-center col-input">{{ t('purchaseOrder.itemSection.tableQtyPo') }}</th>
                          <th class="text-center col-unit">{{ t('purchaseOrder.itemSection.tableSatuan') }}</th>
                          <th class="text-end col-price">{{ t('purchaseOrder.itemSection.tableHarga') }}</th>
                          <th class="text-end col-money">{{ t('purchaseOrder.itemSection.tableTotal') }}</th>
                        </tr>
                      </thead>

                      <tbody>
                        <tr
                          v-for="item in group.items"
                          :key="`${item.purchase_request_item_id}`"
                          :class="{ 'po-item-row-disabled': item.is_selected === false }"
                        >
                          <td class="text-center col-check">
                            <VCheckbox
                              v-model="item.is_selected"
                              density="compact"
                              hide-details
                              color="primary"
                              @update:model-value="togglePOItemSelection(item)"
                            />
                          </td>

                          <td class="col-item">
                            <div class="item-name">
                              {{ toTitleCase(item.nama_item) || '-' }}
                            </div>
                          </td>

                          <td class="text-center">
                            {{ formatDecimalQty(item.qty_pr) }}
                          </td>

                          <td class="text-center">
                            {{ formatDecimalQty(item.qty_po_existing) }}
                          </td>

                          <td class="text-center">
                            <VChip
                              size="default"
                              color="warning"
                              variant="tonal"
                            >
                              {{ formatDecimalQty(item.qty_outstanding) }}
                            </VChip>
                          </td>

                          <td class="text-center">
                            <VTextField
                              :model-value="item.qty"
                              type="text"
                              inputmode="decimal"
                              density="compact"
                              hide-details="auto"
                              variant="outlined"
                              class="qty-po-field"
                              :disabled="item.is_selected === false"
                              :error="item.is_selected !== false && isSubmitted && (!item.qty || Number(item.qty) <= 0 || Number(item.qty) > Number(item.qty_outstanding))"
                              :error-messages="item.is_selected !== false && isSubmitted && (!item.qty || Number(item.qty) <= 0 || Number(item.qty) > Number(item.qty_outstanding))
                                ? [`Max ${formatDecimalQty(item.qty_outstanding)}`]
                                : []"
                              @update:model-value="value => handlePOQtyInput(value, poItems.findIndex(row => row.purchase_request_item_id === item.purchase_request_item_id))"
                            />
                          </td>

                          <td class="text-center">
                            {{ item.satuan }}
                          </td>

                          <td class="text-end">
                            <VTextField
                              :model-value="formatMoney(item.harga_unit)"
                              :placeholder="t('purchaseOrder.placeholders.hargaSatuan')"
                              prefix="Rp"
                              density="compact"
                              hide-details="auto"
                              variant="outlined"
                              inputmode="numeric"
                              class="po-price-field"
                              :disabled="item.is_selected === false"
                              :error="item.is_selected !== false && isSubmitted && Number(item.harga_unit || 0) <= 0"
                              :error-messages="item.is_selected !== false && isSubmitted && Number(item.harga_unit || 0) <= 0 ? ['Harga wajib diisi'] : []"
                              @keypress="onlyNumber"
                              @input="handlePOItemPriceInput($event, poItems.findIndex(row => row.purchase_request_item_id === item.purchase_request_item_id))"
                              @paste.prevent="handlePOItemPricePaste($event, poItems.findIndex(row => row.purchase_request_item_id === item.purchase_request_item_id))"
                            />
                          </td>

                          <td class="text-end font-weight-bold">
                            <span v-if="item.is_selected !== false">
                              Rp {{ formatNumberWithoutRp(item.subtotal) }}
                            </span>

                            <span
                              v-else
                              class="text-disabled"
                            >
                              {{ t('purchaseOrder.itemSection.notSelected') }}
                            </span>
                          </td>
                        </tr>
                      </tbody>
                    </VTable>
                  </div>
                </VCardText>
              </VCard>
            </div>
          </VCol>

          <VCol cols="12" md="4" offset-md="8">
            <VCard variant="tonal">
              <VCardText>
                <template v-if="isVendorPKP">
                  <div class="d-flex justify-space-between mb-2">
                    <span>{{ t('purchaseOrder.summary.subtotal') }}</span>
                    <strong>Rp {{ formatNumberWithoutRp(subtotal) }}</strong>
                  </div>

                  <div class="d-flex justify-space-between mb-2">
                    <span>{{ t('purchaseOrder.summary.dpp') }}</span>
                    <strong>Rp {{ formatNumberWithoutRp(dpp) }}</strong>
                  </div>

                  <div class="d-flex justify-space-between mb-2">
                    <span>{{ t('purchaseOrder.summary.ppn') }}</span>
                    <strong>Rp {{ formatNumberWithoutRp(ppn) }}</strong>
                  </div>

                  <VDivider class="my-3" />
                </template>

                <div class="d-flex justify-space-between">
                  <span><b>Grand Total</b></span>
                  <strong class="text-success">
                    Rp {{ formatNumberWithoutRp(grandTotal) }}
                  </strong>
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12">
            <VCard variant="tonal" class="rounded-xl">
              <VCardText>
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-3">
                  <div>
                    <div class="text-subtitle-1 font-weight-bold">
                      {{ t('purchaseOrder.vendorSection.title') }}
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      {{ t('purchaseOrder.vendorSection.subtitle') }}
                    </div>
                  </div>

                  <VChip size="small" color="primary" variant="tonal">
                    {{ selectedRecommendedVendors.length }} Rekomendasi
                  </VChip>
                </div>

                <VAlert
                  v-if="!form.purchase_request_ids.length"
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  {{ t('purchaseOrder.vendorSection.selectPrFirst') }}
                </VAlert>

                <VAlert
                  v-else-if="!selectedRecommendedVendors.length"
                  type="warning"
                  variant="tonal"
                  density="compact"
                >
                  {{ t('purchaseOrder.vendorSection.empty') }}
                </VAlert>

                <div v-else class="d-flex flex-wrap gap-2">
                  <VChip
                    v-for="vendor in selectedRecommendedVendors"
                    :key="vendor.id"
                    color="success"
                    variant="tonal"
                    prepend-icon="tabler-building-store"
                  >
                    {{ vendor.nama_vendor }}
                  </VChip>
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12" md="6">
            <VAutocomplete
              v-model="form.vendor_id"
              :label="t('purchaseOrder.create.fields.vendor')"
              :items="vendorList"
              item-title="nama_vendor"
              item-value="id"
              clearable
              density="comfortable"
              :disabled="!form.id_department"
              :loading="isLoadingVendor"
              :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
              :error="isSubmitted && !form.vendor_id"
              :error-messages="isSubmitted && !form.vendor_id ? ['Vendor wajib dipilih'] : []"
              :placeholder="t('purchaseOrder.placeholders.vendor')"
              @update:model-value="handleSelectVendor"
            />
          </VCol>

          <VCol cols="12" md="6" />

          <VCol cols="12" md="6">
            <VTextField
              v-model="form.jenis_pembayaran"
              :label="t('purchaseOrder.create.fields.jenisPembayaran')"
              readonly
              density="comfortable"
              :error="isSubmitted && !form.jenis_pembayaran"
              :error-messages="isSubmitted && !form.jenis_pembayaran ? ['Jenis pembayaran wajib diisi'] : []"
            />
          </VCol>

          <VCol
            v-if="isCreditPayment"
            cols="12"
            md="6"
          >
            <VTextField
              v-model.number="form.top"
              :label="t('purchaseOrder.create.fields.top')"
              readonly
              density="comfortable"
              :placeholder="t('purchaseOrder.placeholders.top')"
              :error="isSubmitted && !form.top"
              :error-messages="isSubmitted && !form.top ? ['TOP wajib diisi'] : []"
            />
          </VCol>

          <!-- LAMPIRAN -->
          <VCol cols="12">
            <div class="mt-4">
              <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-2">
                <div>
                  <div class="text-subtitle-1 font-weight-bold">
                    {{ t('common.attachment.title') }} *
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('purchaseOrder.form.attachmentExistingHint') }}
                  </div>
                </div>

                <div class="d-flex gap-2">
                  <input
                    ref="fileRef"
                    type="file"
                    multiple
                    accept=".pdf,.jpg,.jpeg,.png"
                    class="d-none"
                    @change="handleFileUpload"
                  />

                  <VBtn
                    type="button"
                    color="primary"
                    variant="outlined"
                    size="small"
                    @click="triggerFileInput"
                    class="text-none"
                  >
                    {{ t('common.attachment.addButton') }}
                  </VBtn>
                </div>
              </div>

              <VDivider class="mb-4" />

              <VAlert
                v-if="lampiranError"
                type="warning"
                variant="tonal"
                class="mb-4"
              >
                {{ lampiranError }}
              </VAlert>

              <VAlert
                v-if="!existingLampiranPo.length && !form.lampiran_po.length"
                :type="isSubmitted ? 'warning' : 'info'"
                variant="tonal"
              >
                {{ t('common.attachment.empty') }}
              </VAlert>

              <VList
                v-else
                density="comfortable"
                border
                rounded
              >
                <!-- Lampiran lama dari BE -->
                <VListItem
                  v-for="(file, index) in existingLampiranPo"
                  :key="`existing-${file.id}-${index}`"
                >
                  <template #prepend>
                    <VIcon
                      :icon="getExistingFileType(file) === 'PDF'
                        ? 'mdi-file-pdf-box'
                        : 'mdi-file-image-outline'"
                      :color="getExistingFileType(file) === 'PDF' ? 'error' : 'primary'"
                    />
                  </template>

                  <VListItemTitle class="text-body-2">
                    <a
                      :href="file.filepath"
                      target="_blank"
                      class="text-decoration-none"
                    >
                      {{ file.original_filename || file.filename || 'Lampiran lama' }}
                    </a>
                  </VListItemTitle>

                  <VListItemSubtitle>
                    {{ getExistingFileType(file) }}
                    •
                    {{ formatExistingFileSize(file.file_size) }}
                    • File Lama
                  </VListItemSubtitle>

                  <template #append>
                    <VBtn
                      type="button"
                      color="error"
                      variant="text"
                      size="small"
                      @click="removeExistingLampiran(index)"
                    >
                      {{ t('common.attachment.remove') }}
                    </VBtn>
                  </template>
                </VListItem>

                <!-- Lampiran baru dari FE -->
                <VListItem
                  v-for="(file, index) in form.lampiran_po"
                  :key="`new-${file.name}-${file.size}-${index}`"
                >
                  <template #prepend>
                    <VIcon
                      :icon="getFileType(file) === 'PDF'
                        ? 'mdi-file-pdf-box'
                        : 'mdi-file-image-outline'"
                      :color="getFileType(file) === 'PDF' ? 'error' : 'primary'"
                    />
                  </template>

                  <VListItemTitle class="text-body-2">
                    {{ file.name }}
                  </VListItemTitle>

                  <VListItemSubtitle>
                    {{ getFileType(file) }}
                    •
                    {{ formatFileSize(file.size) }}
                    • File Baru
                  </VListItemSubtitle>

                  <template #append>
                    <VBtn
                      type="button"
                      color="error"
                      variant="text"
                      size="small"
                      @click="removeLampiran(index)"
                    >
                      {{ t('common.attachment.remove') }}
                    </VBtn>
                  </template>
                </VListItem>
              </VList>
            </div>
          </VCol>

          <VCol cols="12">
            <VTextarea
              v-model="form.notes"
              :label="t('purchaseOrder.create.fields.notes')"
              :placeholder="t('purchaseOrder.placeholders.notes')"
              rows="4"
              auto-grow
            />
          </VCol>
        </VRow>

        <VDivider class="mt-6 mb-4" />

        <div class="d-flex justify-end gap-3">
          <VBtn
            type="button"
            color="secondary"
            variant="outlined"
            @click.prevent.stop="confirmCancel"
            class="text-none"
          >
            {{ t('purchaseOrder.form.cancelButton') }}
          </VBtn>

          <VBtn
            type="button"
            color="primary"
            :loading="isSaving"
            @click="updatePurchaseOrder"
            class="text-none"
          >
            {{ t('purchaseOrder.form.updateButton') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>
    <!--
    |--------------------------------------------------------------------------
    | Detail Purchase Request
    |--------------------------------------------------------------------------
    -->
    <VDialog
      v-model="purchaseRequestDetailDialog"
      max-width="1100"
      persistent
      scrollable
    >
      <VCard
        v-if="selectedPurchaseRequestDetail"
        class="rounded-lg overflow-hidden"
      >
        <VCardText class="pa-0">
          <div class="pa-6 bg-primary text-white">
            <div class="d-flex flex-wrap align-start justify-space-between gap-4">
              <div>
                <div class="text-caption text-uppercase mb-1 opacity-80">
                  {{ t('purchaseOrder.prDetail.title') }}
                </div>

                <h2 class="text-h5 font-weight-bold mb-2">
                  {{ selectedPurchaseRequestDetail.nomor_pr || '-' }}
                </h2>

                <div class="d-flex flex-wrap gap-2">
                  <VChip
                    :color="getPurchaseRequestDetailStatusColor(selectedPurchaseRequestDetail.status)"
                    variant="flat"
                    size="small"
                  >
                    {{ toTitleCase(selectedPurchaseRequestDetail.status || '') || '-' }}
                  </VChip>

                  <VChip
                    v-if="selectedPurchaseRequestDetail.status_po"
                    color="white"
                    variant="tonal"
                    size="small"
                  >
                    PO: {{ toTitleCase(selectedPurchaseRequestDetail.status_po || '') }}
                  </VChip>
                </div>
              </div>

              <VBtn
                icon
                variant="text"
                color="white"
                @click="closePurchaseRequestDetail"
              >
                <VIcon icon="tabler-x" />
              </VBtn>
            </div>
          </div>

          <div class="pa-6">
            <VRow>
              <VCol
                cols="12"
                md="4"
              >
                <VCard
                  variant="tonal"
                  color="primary"
                  class="h-100"
                >
                  <VCardText>
                    <div class="text-caption text-medium-emphasis mb-1">
                      {{ t('purchaseOrder.prDetail.nomorPr') }}
                    </div>

                    <div class="text-h6 font-weight-bold">
                      {{ selectedPurchaseRequestDetail.nomor_pr || '-' }}
                    </div>

                    <div class="text-body-2 mt-1">
                      {{ formatDate(selectedPurchaseRequestDetail.tanggal_pr) || '-' }}
                    </div>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <VCard
                  variant="tonal"
                  color="success"
                  class="h-100"
                >
                  <VCardText>
                    <div class="text-caption text-medium-emphasis mb-1">
                      {{ t('purchaseOrder.prDetail.cabangDepartment') }}
                    </div>

                    <div class="text-h6 font-weight-bold">
                      {{ selectedPurchaseRequestDetail.cabang || selectedPurchaseRequestDetail.cabang_name || '-' }}
                    </div>

                    <div class="text-body-2 mt-1">
                      {{ selectedPurchaseRequestDetail.department || selectedPurchaseRequestDetail.department_name || '-' }}
                    </div>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <VCard
                  variant="tonal"
                  color="info"
                  class="h-100"
                >
                  <VCardText>
                    <div class="text-caption text-medium-emphasis mb-1">
                      {{ t('purchaseOrder.prDetail.totalPr') }}
                    </div>

                    <div class="text-h6 font-weight-bold">
                      Rp {{ formatNumberWithoutRp(purchaseRequestDetailTotalAmount) }}
                    </div>

                    <div class="text-body-2 mt-1">
                      {{ purchaseRequestDetailItems.length }} Item
                    </div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>

            <VRow class="mt-2">
              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('purchaseOrder.prDetail.tanggalPr') }}
                </div>

                <div class="font-weight-medium">
                  {{ formatDate(selectedPurchaseRequestDetail.tanggal_pr) || '-' }}
                </div>

                <div class="text-caption text-medium-emphasis mt-4">
                  {{ t('purchaseOrder.prDetail.requester') }}
                </div>

                <div class="font-weight-medium">
                  {{ selectedPurchaseRequestDetail.requester_name || selectedPurchaseRequestDetail.created_by_name || selectedPurchaseRequestDetail.created_by || '-' }}
                </div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('purchaseOrder.prDetail.cabang') }}
                </div>

                <div class="font-weight-medium">
                  {{ selectedPurchaseRequestDetail.cabang || selectedPurchaseRequestDetail.cabang_name || '-' }}
                </div>

                <div class="text-caption text-medium-emphasis mt-4">
                  {{ t('purchaseOrder.prDetail.department') }}
                </div>

                <div class="font-weight-medium">
                  {{ selectedPurchaseRequestDetail.department || selectedPurchaseRequestDetail.department_name || '-' }}
                </div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('purchaseOrder.prDetail.catatan') }}
                </div>

                <div class="font-weight-medium white-space-pre-line">
                  {{ selectedPurchaseRequestDetail.notes || selectedPurchaseRequestDetail.keterangan || '-' }}
                </div>
              </VCol>
            </VRow>

            <VDivider class="my-6" />

            <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
              <div>
                <h3 class="text-h6 font-weight-bold mb-1">
                  {{ t('purchaseOrder.prDetail.lampiran') }}
                </h3>

                <div class="text-body-2 text-medium-emphasis">
                  {{ t('purchaseOrder.prDetail.lampiranSubtitle') }}
                </div>
              </div>

              <VChip
                color="primary"
                variant="tonal"
                prepend-icon="tabler-paperclip"
              >
                {{ purchaseRequestDetailAttachments.length }} File
              </VChip>
            </div>

            <VAlert
              v-if="!purchaseRequestDetailAttachments.length"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('purchaseOrder.prDetail.lampiranEmpty') }}
            </VAlert>

            <div
              v-else
              class="pr-detail-table-wrapper"
            >
              <VTable class="text-no-wrap rounded border">
                <thead>
                  <tr>
                    <th width="60">
                      No
                    </th>
                    <th>{{ t('purchaseOrder.prDetail.fileNama') }}</th>
                    <th width="160">{{ t('purchaseOrder.prDetail.fileUkuran') }}</th>
                    <th width="180">{{ t('purchaseOrder.prDetail.fileTipe') }}</th>
                    <th width="120" class="text-center">{{ t('purchaseOrder.prDetail.fileAksi') }}</th>
                  </tr>
                </thead>

                <tbody>
                  <tr
                    v-for="(attachment, index) in purchaseRequestDetailAttachments"
                    :key="attachment.id || attachment.public_id || index"
                  >
                    <td>
                      {{ Number(index) + 1 }}
                    </td>

                    <td>
                      <div class="d-flex align-center">
                        <VIcon
                          icon="tabler-file"
                          size="18"
                          class="me-2"
                        />

                        <div>
                          <div class="font-weight-medium">
                            {{ attachment.file_original_name || attachment.original_filename || attachment.filename || attachment.file_name || '-' }}
                          </div>

                          <div class="text-caption text-medium-emphasis">
                            {{ attachment.file_name || attachment.filename || '-' }}
                          </div>
                        </div>
                      </div>
                    </td>

                    <td>
                      {{ formatPurchaseRequestDetailFileSize(attachment.file_size || attachment.size) }}
                    </td>

                    <td>
                      {{ attachment.file_mime_type || attachment.mime_type || '-' }}
                    </td>

                    <td class="text-center">
                      <VBtn
                        v-if="attachment.file_url || attachment.filepath || attachment.path"
                        icon
                        size="small"
                        variant="text"
                        color="primary"
                        :href="attachment.file_url || attachment.filepath || attachment.path"
                        target="_blank"
                      >
                        <VIcon icon="tabler-eye" />

                        <VTooltip
                          activator="parent"
                          location="top"
                        >
                          {{ t('purchaseOrder.prDetail.viewFile') }}
                        </VTooltip>
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>

            <VDivider class="my-6" />

            <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
              <div>
                <h3 class="text-h6 font-weight-bold mb-1">
                  {{ t('purchaseOrder.prDetail.itemTitle') }}
                </h3>

                <div class="text-body-2 text-medium-emphasis">
                  {{ t('purchaseOrder.prDetail.itemSubtitle') }}
                </div>
              </div>

              <VChip
                size="small"
                color="primary"
                variant="tonal"
                prepend-icon="tabler-list-details"
              >
                {{ purchaseRequestDetailItems.length }} Item
              </VChip>
            </div>

            <div class="pr-detail-table-wrapper">
              <VTable class="text-no-wrap rounded border">
                <thead>
                  <tr>
                    <th width="50">{{ t('purchaseOrder.prDetail.itemNo') }}</th>
                    <th>{{ t('purchaseOrder.prDetail.itemNama') }}</th>
                    <th class="text-end">{{ t('purchaseOrder.prDetail.itemQty') }}</th>
                    <th class="text-center">{{ t('purchaseOrder.prDetail.itemSatuan') }}</th>
                    <th class="text-end">{{ t('purchaseOrder.prDetail.itemHarga') }}</th>
                    <th class="text-end">{{ t('purchaseOrder.prDetail.itemSubtotal') }}</th>
                    <th>{{ t('purchaseOrder.prDetail.itemKeterangan') }}</th>
                  </tr>
                </thead>

                <tbody>
                  <tr
                    v-for="(item, index) in paginatedPurchaseRequestDetailItems"
                    :key="item.id || item.public_id || index"
                  >
                    <td>
                      {{ purchaseRequestDetailItemPerPage === 'ALL'
                        ? Number(index) + 1
                        : ((Number(purchaseRequestDetailItemPage) - 1) * Number(purchaseRequestDetailItemPerPage)) + Number(index) + 1
                      }}
                    </td>

                    <td>
                      <div class="font-weight-medium">
                        {{ toTitleCase(item.nama_item || item.item_name || '-') }}
                      </div>

                      <!--
                        Material group ditampilkan di bawah nama item.
                        Item lama yang belum punya grup tidak menampilkan
                        apa pun, bukan tanda hubung, agar tidak berisik.
                      -->
                      <VChip
                        v-if="item.material_group?.name && item.material_group.name !== '-'"
                        size="x-small"
                        color="primary"
                        variant="tonal"
                        class="mt-1"
                      >
                        <VIcon
                          icon="tabler-category"
                          size="12"
                          start
                        />
                        {{ item.material_group.name }}
                      </VChip>

                      <div
                        v-if="item.spesifikasi"
                        class="text-caption text-medium-emphasis"
                      >
                        {{ item.spesifikasi }}
                      </div>
                    </td>

                    <td class="text-end">
                      {{ formatDecimalQty(item.qty ?? item.quantity ?? 0) }}
                    </td>

                    <td class="text-center">
                      {{ item.satuan?.nama || item.satuan_name || item.satuan || item.unit || '-' }}
                    </td>

                    <td class="text-end">
                      Rp {{ formatNumberWithoutRp(item.harga_unit ?? item.price ?? item.unit_price ?? 0) }}
                    </td>

                    <td class="text-end font-weight-bold">
                      Rp {{ formatNumberWithoutRp(item.subtotal ?? item.total ?? (Number(item.qty || 0) * Number(item.harga_unit || 0))) }}
                    </td>

                    <td>
                      {{ item.keterangan || item.notes || '-' }}
                    </td>
                  </tr>

                  <tr v-if="!purchaseRequestDetailItems.length">
                    <td
                      colspan="7"
                      class="text-center py-8 text-medium-emphasis"
                    >
                      {{ t('purchaseOrder.prDetail.itemEmpty') }}
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>

            <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-3">
              <div class="text-caption text-medium-emphasis">
                Total Item PR: {{ purchaseRequestDetailItems.length }}
              </div>

              <div class="d-flex align-center gap-3">
                <VSelect
                  v-model="purchaseRequestDetailItemPerPage"
                  :items="purchaseRequestDetailItemPerPageItems"
                  item-title="title"
                  item-value="value"
                  density="compact"
                  hide-details
                  style="width: 110px;"
                  @update:model-value="purchaseRequestDetailItemPage = 1"
                />

                <VPagination
                  v-if="purchaseRequestDetailItemPerPage !== 'ALL' && purchaseRequestDetailItems.length > Number(purchaseRequestDetailItemPerPage)"
                  v-model="purchaseRequestDetailItemPage"
                  :length="purchaseRequestDetailItemTotalPage"
                  size="small"
                  :total-visible="3"
                />
              </div>
            </div>
          </div>
        </VCardText>

        <VCardActions class="justify-end pa-6 pt-0">
          <VBtn
            variant="tonal"
            color="secondary"
            @click="closePurchaseRequestDetail"
          >
            {{ t('purchaseOrder.prDetail.closeButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style lang="scss" scoped>
.pr-attachment-cell {
  min-width: 220px;
  max-width: 280px;
  vertical-align: middle;
}

.pr-attachment-link {
  display: inline-flex;
  align-items: center;
  max-width: 100%;
  padding: 4px 8px;
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.08);
  color: rgb(var(--v-theme-primary));
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
}

.pr-attachment-link span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.attachment-slide-enter-active {
  transition: all 0.22s ease;
}

.attachment-slide-enter-from {
  opacity: 0;
  transform: translateY(-6px);
}

.attachment-slide-enter-to {
  opacity: 1;
  transform: translateY(0);
}

.po-item-group-card {
  border-radius: 18px;
}

.po-item-table-wrapper {
  width: 100%;
  overflow-x: auto;
  border-radius: 14px;
}

.po-item-table {
  width: 100%;
  min-width: 1080px;
  table-layout: fixed;
}

.po-item-table th,
.po-item-table td {
  padding: 10px 8px !important;
  vertical-align: middle;
}

.po-item-table th {
  white-space: nowrap;
  background: rgba(var(--v-theme-primary), 0.05);
  font-weight: 700;
}

.po-item-table .col-item {
  width: 200px;
}

.po-item-table .col-qty {
  width: 115px;
}

.po-item-table .col-input {
  width: 130px;
}

.po-item-table .col-unit {
  width: 90px;
}

.po-item-table .col-price {
  width: 260px;
}

.po-item-table .col-money {
  width: 210px;
}

.item-name {
  font-weight: 600;
  line-height: 1.35;
  white-space: normal;
  word-break: break-word;
  overflow-wrap: anywhere;
}

.qty-po-field :deep(.v-field__input) {
  min-height: 36px !important;
  padding-block: 4px !important;
  text-align: center;
}

.po-price-field :deep(.v-field__input) {
  min-height: 36px !important;
  padding-block: 4px !important;
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.po-price-field :deep(.v-field__prefix) {
  padding-inline-start: 8px;
  color: rgba(var(--v-theme-on-surface), 0.62);
  font-weight: 600;
}

@media (max-width: 1280px) {
  .po-item-table {
    min-width: 1040px;
  }

  .po-item-table .col-item {
    width: 220px;
  }

  .po-item-table .col-price {
    width: 260px;
  }

  .po-item-table .col-money {
    width: 210px;
  }
}

.col-check {
  width: 72px;
  min-width: 72px;
}

.po-item-row-disabled {
  opacity: 0.55;
  background-color: rgba(var(--v-theme-surface-variant), 0.25);
}

.po-item-row-disabled .item-name {
  text-decoration: line-through;
}

.pr-number-cell {
  min-width: 230px;
  white-space: nowrap;
}

.pr-number-action {
  justify-content: flex-start;
  letter-spacing: normal;
  min-inline-size: auto;
  text-align: start;
}

.pr-number-action :deep(.v-btn__content) {
  max-width: 100%;
}

.pr-number-text {
  display: inline-block;
  max-width: 220px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pr-detail-table-wrapper {
  width: 100%;
  overflow-x: auto;
  border-radius: 12px;
}

.white-space-pre-line {
  white-space: pre-line;
}
</style>