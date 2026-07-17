<script setup lang="ts">
import { computed, onMounted, reactive, ref, toRef, nextTick } from 'vue'
import { useRouter } from 'vue-router'
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
import { watch } from 'vue'

interface PurchaseOrderForm {
  tanggal_po: string
  vendor_id: number | null
  cabang: number | null
  id_department: number | null
  jenis_pembayaran: string
  top: number | null
  notes: string
  purchase_request_ids: number[]
}

interface VendorOption {
  id: number
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

const permissionStore = usePermissionStore()

const canCreate = computed(() => {
  return permissionStore.can('purchase_order.create')
})

const createPermissionCode = 'purchase_order.create'

const createPermissionScope = computed(() => {
  return permissionStore.scope(
    createPermissionCode,
  )
})

const assignedCreateDepartmentIds = computed<number[]>(() => {
  return permissionStore.departmentIds(
    createPermissionCode,
  )
})

const isCheckingPermission = ref(true)

const router = useRouter()

const isSubmitted = ref(false)
const isSaving = ref(false)

const prPage = ref(1)
const prPerPage = ref<number | 'ALL'>(5)

const vendorList = ref<VendorOption[]>([])
const cabangList = ref<any[]>([])
const departmentList = ref<any[]>([])
const purchaseRequestList = ref<PurchaseRequestOption[]>([])
const poItems = ref<PurchaseOrderItem[]>([])

const isLoadingVendor = ref(false)
const isLoadingCabang = ref(false)
const isLoadingDepartment = ref(false)
const isLoadingPR = ref(false)

const form = reactive<PurchaseOrderForm>({
  tanggal_po: '',
  vendor_id: null,
  cabang: null,
  id_department: null,
  jenis_pembayaran: '',
  top: null,
  notes: '',
  purchase_request_ids: [],
})

const tanggalPO = useNativeDatePicker(toRef(form, 'tanggal_po'))

const today = (): string => new Date().toISOString().split('T')[0]

const required = (value: unknown): boolean => {
  return value !== '' && value !== null && value !== undefined
}

const formatMoney = (value: number | string | null | undefined): string => {
  return formatNumberWithoutRp(Number(value || 0))
}

const onlyNumber = (event: KeyboardEvent): void => {
  onlyNumberKeypress(event)
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

const toggleSelectAllPR = async (value: boolean): Promise<void> => {
  if (value) {
    form.purchase_request_ids = purchaseRequestList.value.map(pr => pr.id)
  } else {
    form.purchase_request_ids = []
  }

  await handleSelectPurchaseRequest()
}

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

const loadVendors = async (showAlert = true): Promise<void> => {
  isLoadingVendor.value = true

  try {
    const res = await axios.get('/master/vendor/dropdown-po', {
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
        title: 'Error',
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
        title: 'Error',
        text: getApiErrorMessage(error, 'Gagal memuat data cabang'),
      })
    }
  } finally {
    isLoadingCabang.value = false
  }
}

const currentUser = computed(() => {
  try {
    return JSON.parse(
      localStorage.getItem('userData') || '{}',
    )
  }
  catch {
    return {}
  }
})

/*
|--------------------------------------------------------------------------
| Department Access for Create PO
|--------------------------------------------------------------------------
*/

const ownDepartmentId = computed<number>(() => {
  return Number(
    currentUser.value?.department_id
    ?? currentUser.value?.departemen_id
    ?? 0,
  )
})

const parsePurchaseRequestDetailNumber = (
  value: unknown,
): number => {
  if (typeof value === 'number')
    return Number.isFinite(value) ? value : 0

  if (value === null || value === undefined)
    return 0

  let normalized = String(value)
    .trim()
    .replace(/[^\d,.-]/g, '')

  if (!normalized)
    return 0

  const isNegative = normalized.startsWith('-')

  normalized = normalized.replace(/-/g, '')

  const dotCount = (normalized.match(/\./g) || []).length
  const commaCount = (normalized.match(/,/g) || []).length
  const lastDotIndex = normalized.lastIndexOf('.')
  const lastCommaIndex = normalized.lastIndexOf(',')

  if (dotCount > 0 && commaCount > 0) {
    /*
    |--------------------------------------------------------------------------
    | Format campuran:
    | - 10.000.000,00  => Indonesia
    | - 10,000,000.00  => International / DB formatted
    |--------------------------------------------------------------------------
    */
    if (lastCommaIndex > lastDotIndex) {
      normalized = normalized
        .replace(/\./g, '')
        .replace(',', '.')
    }
    else {
      normalized = normalized
        .replace(/,/g, '')
    }
  }
  else if (commaCount > 0) {
    const parts = normalized.split(',')
    const decimalPart = parts[parts.length - 1] || ''

    if (
      commaCount === 1
      && decimalPart.length > 0
      && decimalPart.length <= 2
    ) {
      normalized = normalized.replace(',', '.')
    }
    else {
      normalized = normalized.replace(/,/g, '')
    }
  }
  else if (dotCount > 0) {
    const parts = normalized.split('.')
    const decimalPart = parts[parts.length - 1] || ''

    if (
      dotCount === 1
      && decimalPart.length > 0
      && decimalPart.length <= 2
    ) {
      /*
      |--------------------------------------------------------------------------
      | Decimal database:
      | 10000000.00
      | 9166666.67
      |--------------------------------------------------------------------------
      */
      normalized = normalized
    }
    else {
      /*
      |--------------------------------------------------------------------------
      | Separator ribuan:
      | 10.000.000
      |--------------------------------------------------------------------------
      */
      normalized = normalized.replace(/\./g, '')
    }
  }

  const number = Number(
    `${isNegative ? '-' : ''}${normalized}`,
  )

  return Number.isFinite(number) ? number : 0
}

const roundPurchaseRequestDetailMoney = (
  value: number,
): number => {
  return Math.round((Number(value || 0) + Number.EPSILON) * 100) / 100
}

const purchaseRequestDetailRecommendedVendor = computed(() => {
  const detail = selectedPurchaseRequestDetail.value || {}

  const vendor = detail.recommended_vendor
    ?? detail.recommendedVendor
    ?? detail.vendor
    ?? null

  if (vendor) {
    return {
      id: vendor.id ?? detail.recommended_vendor_id ?? null,
      nama_vendor:
        vendor.nama_vendor
        ?? vendor.name
        ?? vendor.label
        ?? detail.recommended_vendor_name
        ?? detail.vendor_name
        ?? '-',
      status_pkp:
        vendor.status_pkp
        ?? detail.status_pkp
        ?? 'NON_PKP',
      jenis_pembayaran:
        vendor.jenis_pembayaran
        ?? detail.jenis_pembayaran
        ?? null,
      top:
        vendor.top
        ?? detail.top
        ?? null,
    }
  }

  return {
    id: detail.recommended_vendor_id ?? null,
    nama_vendor:
      detail.recommended_vendor_name
      ?? detail.vendor_name
      ?? '-',
    status_pkp:
      detail.status_pkp
      ?? 'NON_PKP',
    jenis_pembayaran:
      detail.jenis_pembayaran
      ?? null,
    top:
      detail.top
      ?? null,
  }
})

const purchaseRequestDetailStatusPKPText = computed(() => {
  const rawStatus = String(
    purchaseRequestDetailRecommendedVendor.value?.status_pkp
    ?? selectedPurchaseRequestDetail.value?.status_pkp
    ?? 'NON_PKP',
  )
    .trim()
    .toUpperCase()
    .replace(/[\s-]+/g, '_')

  return rawStatus === 'PKP'
    ? 'PKP'
    : 'NON PKP'
})

const purchaseRequestDetailIsPKP = computed(() => {
  return purchaseRequestDetailStatusPKPText.value === 'PKP'
})

const purchaseRequestDetailJenisPembayaran = computed(() => {
  return purchaseRequestDetailRecommendedVendor.value?.jenis_pembayaran
    ?? selectedPurchaseRequestDetail.value?.jenis_pembayaran
    ?? '-'
})

const purchaseRequestDetailTop = computed(() => {
  const top = purchaseRequestDetailRecommendedVendor.value?.top
    ?? selectedPurchaseRequestDetail.value?.top
    ?? null

  if (top === null || top === undefined || top === '')
    return '-'

  const topNumber = Number(top)

  if (!Number.isFinite(topNumber) || topNumber <= 0)
    return '-'

  return `${topNumber} Hari`
})

const purchaseRequestDetailSubtotalBeforeTax = computed(() => {
  const itemSubtotal = purchaseRequestDetailItems.value.reduce(
    (total: number, item: any) => {
      const qty = parsePurchaseRequestDetailNumber(
        item.qty
        ?? item.quantity
        ?? 0,
      )

      const price = parsePurchaseRequestDetailNumber(
        item.harga_unit
        ?? item.price
        ?? item.unit_price
        ?? 0,
      )

      const subtotal = parsePurchaseRequestDetailNumber(
        item.subtotal
        ?? item.total
        ?? 0,
      )

      return total + (
        subtotal > 0
          ? subtotal
          : qty * price
      )
    },
    0,
  )

  if (itemSubtotal > 0)
    return roundPurchaseRequestDetailMoney(itemSubtotal)

  const detail = selectedPurchaseRequestDetail.value || {}
  const grandTotal = parsePurchaseRequestDetailNumber(
    detail.total_amount
    ?? detail.grand_total
    ?? detail.total
    ?? purchaseRequestDetailTotalAmount.value
    ?? 0,
  )

  const ppn = parsePurchaseRequestDetailNumber(
    detail.ppn
    ?? 0,
  )

  if (purchaseRequestDetailIsPKP.value && grandTotal > 0)
    return roundPurchaseRequestDetailMoney(Math.max(grandTotal - ppn, 0))

  return roundPurchaseRequestDetailMoney(grandTotal)
})

const purchaseRequestDetailDpp = computed(() => {
  if (!purchaseRequestDetailIsPKP.value)
    return 0

  const detail = selectedPurchaseRequestDetail.value || {}
  const savedDpp = parsePurchaseRequestDetailNumber(
    detail.dpp
    ?? 0,
  )

  if (savedDpp > 0)
    return roundPurchaseRequestDetailMoney(savedDpp)

  return roundPurchaseRequestDetailMoney(
    purchaseRequestDetailSubtotalBeforeTax.value * 11 / 12,
  )
})

const purchaseRequestDetailPpn = computed(() => {
  if (!purchaseRequestDetailIsPKP.value)
    return 0

  const detail = selectedPurchaseRequestDetail.value || {}
  const savedPpn = parsePurchaseRequestDetailNumber(
    detail.ppn
    ?? 0,
  )

  if (savedPpn > 0)
    return roundPurchaseRequestDetailMoney(savedPpn)

  return roundPurchaseRequestDetailMoney(
    purchaseRequestDetailDpp.value * 0.12,
  )
})

const purchaseRequestDetailGrandTotal = computed(() => {
  const detail = selectedPurchaseRequestDetail.value || {}

  const savedGrandTotal = parsePurchaseRequestDetailNumber(
    detail.total_amount
    ?? detail.grand_total
    ?? detail.total
    ?? 0,
  )

  if (savedGrandTotal > 0)
    return roundPurchaseRequestDetailMoney(savedGrandTotal)

  return roundPurchaseRequestDetailMoney(
    purchaseRequestDetailSubtotalBeforeTax.value
    + purchaseRequestDetailPpn.value,
  )
})

const purchaseRequestDetailTotalPOAmount = computed(() => {
  const detail = selectedPurchaseRequestDetail.value || {}

  const savedTotalPo = parsePurchaseRequestDetailNumber(
    detail.total_po
    ?? detail.total_po_amount
    ?? detail.total_ordered
    ?? 0,
  )

  if (savedTotalPo > 0)
    return roundPurchaseRequestDetailMoney(savedTotalPo)

  const purchaseOrders = Array.isArray(detail.purchase_orders)
    ? detail.purchase_orders
    : []

  const totalPo = purchaseOrders.reduce(
    (total: number, purchaseOrder: any) => {
      const status = String(purchaseOrder.status ?? '')
        .trim()
        .toUpperCase()

      if (['REJECTED', 'CANCELLED', 'CANCELED'].includes(status))
        return total

      return total + parsePurchaseRequestDetailNumber(
        purchaseOrder.total_nilai
        ?? purchaseOrder.total_amount
        ?? purchaseOrder.grand_total
        ?? purchaseOrder.total
        ?? 0,
      )
    },
    0,
  )

  return roundPurchaseRequestDetailMoney(totalPo)
})

const purchaseRequestDetailOutstandingAmount = computed(() => {
  return roundPurchaseRequestDetailMoney(
    Math.max(
      purchaseRequestDetailGrandTotal.value
      - purchaseRequestDetailTotalPOAmount.value,
      0,
    ),
  )
})

/*
|--------------------------------------------------------------------------
| Department IDs yang boleh digunakan
|--------------------------------------------------------------------------
|
| null:
| - seluruh department diizinkan.
|
| array kosong:
| - tidak ada department yang diizinkan.
|--------------------------------------------------------------------------
*/

const allowedCreateDepartmentIds = computed<number[] | null>(() => {
  const scope = createPermissionScope.value

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
        assignedCreateDepartmentIds.value
          .map(id => Number(id))
          .filter(id => id > 0),
      ),
    )
  }

  return []
})

const availableDepartmentList = computed(() => {
  const allowedDepartmentIds
    = allowedCreateDepartmentIds.value

  /*
   * null berarti scope ALL.
   */
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
  return createPermissionScope.value === 'OWN_DEPARTMENT'
})

const hasValidCreateScope = computed<boolean>(() => {
  return [
    'OWN_DEPARTMENT',
    'ASSIGNED_DEPARTMENTS',
    'ALL',
  ].includes(
    createPermissionScope.value,
  )
})

const applyCreateDepartmentPermission = (): boolean => {
  const scope = createPermissionScope.value

  const allDepartmentIds = departmentList.value
    .map(department => Number(department.id))
    .filter(departmentId => departmentId > 0)

  /*
  |--------------------------------------------------------------------------
  | OWN DEPARTMENT
  |--------------------------------------------------------------------------
  */
  if (scope === 'OWN_DEPARTMENT') {
    const departmentId = Number(
      ownDepartmentId.value || 0,
    )

    const departmentExists
      = departmentId > 0
        && allDepartmentIds.includes(
          departmentId,
        )

    form.id_department = departmentExists
      ? departmentId
      : null

    return departmentExists
  }

  /*
  |--------------------------------------------------------------------------
  | ASSIGNED DEPARTMENTS
  |--------------------------------------------------------------------------
  */
  if (scope === 'ASSIGNED_DEPARTMENTS') {
    const assignedDepartmentSet = new Set(
      assignedCreateDepartmentIds.value
        .map(departmentId =>
          Number(departmentId),
        )
        .filter(departmentId =>
          departmentId > 0,
        ),
    )

    const availableIds = allDepartmentIds.filter(
      departmentId =>
        assignedDepartmentSet.has(
          departmentId,
        ),
    )

    if (!availableIds.length) {
      form.id_department = null

      return false
    }

    /*
     * Pertahankan department yang sudah dipilih
     * jika masih termasuk assignment.
     */
    if (
      form.id_department
      && availableIds.includes(
        Number(form.id_department),
      )
    ) {
      return true
    }

    /*
     * Jika hanya satu department, pilih otomatis.
     * Jika lebih dari satu, biarkan user memilih.
     */
    form.id_department
      = availableIds.length === 1
        ? availableIds[0]
        : null

    return true
  }

  /*
  |--------------------------------------------------------------------------
  | ALL
  |--------------------------------------------------------------------------
  */
  if (scope === 'ALL') {
    if (!allDepartmentIds.length) {
      form.id_department = null

      return false
    }

    /*
     * Pertahankan pilihan yang masih valid.
     */
    if (
      form.id_department
      && allDepartmentIds.includes(
        Number(form.id_department),
      )
    ) {
      return true
    }

    form.id_department = null

    return true
  }

  /*
  |--------------------------------------------------------------------------
  | Scope tidak diizinkan
  |--------------------------------------------------------------------------
  */
  form.id_department = null

  return false
}

const canUseDepartmentForCreate = (
  departmentId: number | null,
): boolean => {
  const normalizedDepartmentId = Number(
    departmentId || 0,
  )

  if (normalizedDepartmentId <= 0)
    return false

  const allowedDepartmentIds
    = allowedCreateDepartmentIds.value

  /*
   * null berarti scope ALL.
   */
  if (allowedDepartmentIds === null)
    return true

  return allowedDepartmentIds.includes(
    normalizedDepartmentId,
  )
}

// const setUserDefaultDepartment = (): void => {
//   form.id_department
//     = currentUser.value?.department_id
//       ? Number(currentUser.value.department_id)
//       : null
// }

const fetchDepartmentList = async (
  showAlert = true,
): Promise<void> => {
  isLoadingDepartment.value = true

  try {
    const response = await axios.get(
      '/master/department/dropdown-select',
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    departmentList.value = Array.isArray(response.data?.data)
      ? response.data.data.map((item: any) => ({
          id: Number(item.id),
          kode: item.kode || '',
          nama: item.nama || item.title || '-',
          label: `${item.kode || '-'} - ${item.nama || item.title || '-'}`,
        }))
      : []
  }
  catch (error: unknown) {
    console.error('[Department] FETCH ERROR:', error)

    departmentList.value = []

    if (showAlert) {
      showErrorToast({
        title: 'Error',
        text: getApiErrorMessage(
          error,
          'Gagal memuat data department.',
        ),
      })
    }
  }
  finally {
    isLoadingDepartment.value = false
  }
}

const handleSelectVendor = (): void => {
  const vendor = vendorList.value.find(item => item.id === Number(form.vendor_id))

  form.jenis_pembayaran = vendor?.jenis_pembayaran || ''
  form.top = vendor?.top || null
}

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

const handleSelectPRFilter = async (): Promise<void> => {
  form.purchase_request_ids = []
  form.vendor_id = null
  poItems.value = []
  purchaseRequestList.value = []
  vendorList.value = []
  prPage.value = 1

  if (form.id_department) {
    await loadVendors(false)
  }

  if (form.cabang && form.id_department) {
    await loadPurchaseRequestsByFilter()
  }
}

const loadPurchaseRequestsByFilter = async (): Promise<void> => {
  if (!form.cabang || !form.id_department) {
    purchaseRequestList.value = []
    visibleAttachmentMap.value = {}

    return
  }

  visibleAttachmentMap.value = {}
  isLoadingPR.value = true

  try {
    const response = await axios.get(
      '/transaction/purchase-request/dropdown-approved',
      {
        headers: {
          Accept: 'application/json',
        },
        params: {
          cabang: form.cabang,
          id_department: form.id_department,
        },
      },
    )

    purchaseRequestList.value = Array.isArray(response.data?.data)
      ? response.data.data.map((item: any) => ({
          id: Number(item.id),
          public_id: item.public_id,
          nomor_pr: item.nomor_pr,
          tanggal_pr: item.tanggal_pr,
          cabang: item.cabang,
          department: item.department,
          total_amount: Number(item.total_amount || 0),

          recommended_vendor_id:
            item.recommended_vendor_id
              ? Number(item.recommended_vendor_id)
              : null,

          recommended_vendor:
            item.recommended_vendor || null,

          items: Array.isArray(item.items)
            ? item.items
            : [],

          attachments: Array.isArray(item.attachments)
            ? item.attachments
            : [],
        }))
      : []
  }
  catch (error: unknown) {
    purchaseRequestList.value = []

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(
        error,
        'Gagal memuat Purchase Request',
      ),
    })
  }
  finally {
    isLoadingPR.value = false
  }
}

const visibleAttachmentMap = ref<Record<number, number>>({})

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
    return parsePurchaseRequestDetailNumber(value)

  return purchaseRequestDetailItems.value.reduce((total: number, item: any) => {
    const qty = parsePurchaseRequestDetailNumber(item.qty ?? item.quantity ?? 0)
    const hargaUnit = parsePurchaseRequestDetailNumber(item.harga_unit ?? item.price ?? item.unit_price ?? 0)
    const subtotalItem = parsePurchaseRequestDetailNumber(item.subtotal ?? item.total ?? 0)

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
      title: 'Error',
      text: 'Public ID Purchase Request tidak ditemukan.',
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
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat detail Purchase Request.'),
    })
  }
}

const closePurchaseRequestDetail = (): void => {
  purchaseRequestDetailDialog.value = false
  selectedPurchaseRequestDetail.value = null
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
}

const handleSelectPurchaseRequest = (): void => {
  const previousItemStateMap = new Map<number, {
    is_selected: boolean
    qty: number
    harga_unit: number
    subtotal: number
  }>()

  poItems.value.forEach(item => {
    previousItemStateMap.set(Number(item.purchase_request_item_id), {
      is_selected: item.is_selected !== false,
      qty: Number(item.qty || 0),
      harga_unit: Number(item.harga_unit || 0),
      subtotal: Number(item.subtotal || 0),
    })
  })

  const selectedPRs = purchaseRequestList.value.filter(pr =>
    form.purchase_request_ids.includes(pr.id),
  )

  poItems.value = selectedPRs.flatMap(pr =>
    (pr.items || [])
      .filter((item: any) => Number(item.qty_outstanding ?? item.qty ?? 0) > 0)
      .map((item: any) => {
        const purchaseRequestItemId = Number(item.id)
        const qtyOutstanding = Number(item.qty_outstanding ?? item.qty ?? 0)
        const defaultHargaUnit = Number(item.harga_unit || 0)

        const previousState = previousItemStateMap.get(purchaseRequestItemId)

        const qty = previousState
          ? Number(previousState.qty || 0)
          : qtyOutstanding

        const hargaUnit = previousState
          ? Number(previousState.harga_unit || 0)
          : defaultHargaUnit

        const isSelected = previousState
          ? previousState.is_selected !== false
          : true

        return {
          purchase_request_id: pr.id,
          purchase_request_item_id: purchaseRequestItemId,
          nomor_pr: pr.nomor_pr,
          nama_item: item.nama_item || '-',
          is_selected: isSelected,
          qty_pr: Number(item.qty || 0),
          qty_po_existing: Number(item.qty_po || 0),
          qty_outstanding: qtyOutstanding,
          qty,
          satuan_id: Number(item.satuan_id ?? item.satuan?.id ?? 0),
          satuan: item.satuan?.nama || item.satuan || '-',
          keterangan: item.keterangan || '-',
          harga_unit: hargaUnit,
          subtotal: isSelected ? qty * hargaUnit : 0,
        }
      }),
  )
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
      title: 'Qty melebihi outstanding',
      text: `Qty PO untuk item "${item.nama_item}" maksimal ${formatDecimalQty(maxQty)}.`,
    })
  } else {
    item.qty = qty
  }

  updatePOItemSubtotal(index)
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
      title: 'Input tidak valid',
      text: 'Harga hanya boleh berupa angka (0-9).',
    })

    return
  }

  const target = event.target as HTMLInputElement
  const harga = Number(pastedText)

  item.harga_unit = harga

  updatePOItemSubtotal(index)

  target.value = formatMoney(harga)
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Batalkan?',
    text: 'Data yang sudah diisi akan hilang.',
    confirmButtonText: 'Ya, batal',
    cancelButtonText: 'Batal',
  })

  if (confirm.isConfirmed) {
    await router.replace('/non_trade/purchase_order')
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
      title: 'Warning',
      text: 'Lengkapi data wajib.',
    })

    return false
  }

  if (
    !canUseDepartmentForCreate(
      form.id_department,
    )
  ) {
    showErrorToast({
      title: 'Department Tidak Diizinkan',
      text: 'Anda tidak memiliki akses membuat Purchase Order untuk department yang dipilih.',
    })

    return false
  }

  if (!form.purchase_request_ids.length) {
    showWarningToast({
      title: 'Warning',
      text: 'Pilih minimal satu Purchase Request.',
    })

    return false
  }

  if (!poItems.value.length) {
    showWarningToast({
      title: 'Warning',
      text: 'Item Purchase Order belum tersedia.',
    })

    return false
  }

  if (!selectedPOItems.value.length) {
    showWarningToast({
      title: 'Warning',
      text: 'Pilih minimal satu item Purchase Order.',
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
      title: 'Warning',
      text: `Setiap PR yang dipilih wajib memiliki minimal 1 item PO. PR tanpa item: ${nomorPrList}`,
    })

    return false
  }

  for (const item of selectedPOItems.value) {
    if (!Number(item.satuan_id || 0)) {
      showErrorToast({
        title: 'Satuan tidak valid',
        text: `Satuan untuk item ${item.nama_item} belum memiliki ID satuan.`,
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
      title: 'Warning',
      text: `Qty PO item "${item.nama_item || '-'}" wajib lebih dari 0, tidak boleh melebihi outstanding, dan harga wajib lebih dari 0.`,
    })

    return false
  }

  const itemIds = selectedPOItems.value.map(item => Number(item.purchase_request_item_id))
  const uniqueItemIds = new Set(itemIds)

  if (itemIds.length !== uniqueItemIds.size) {
    showWarningToast({
      title: 'Warning',
      text: 'Terdapat item PR yang duplikat pada Purchase Order.',
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

const savePurchaseOrder = async (): Promise<void> => {

  const payload = buildPayload()

  console.log(
    '[Purchase Order] CREATE PAYLOAD:',
    payload,
  )

  if (isSaving.value) return

  isSubmitted.value = true

  const isValid = await validateForm()
  if (!isValid) return

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Simpan Purchase Order?',
    text: 'Pastikan data purchase order sudah benar.',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  isSaving.value = true

  try {
    showLoadingAlert('Menyimpan data...', 'Mohon tunggu sebentar')

    await axios.post('/transaction/purchase-order', buildPayload(), {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    await router.replace({
      path: '/non_trade/purchase_order',
      query: { success: 'created' },
    })
  } catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal menyimpan Purchase Order'),
    })
  } finally {
    isSaving.value = false
  }
}

const goBack = async (): Promise<void> => {
  await router.replace('/non_trade/purchase_order')
}

watch(
  [
    () => form.cabang,
    () => form.id_department,
  ],
  async (
    [newCabang, newDepartment],
    [oldCabang, oldDepartment],
  ) => {
    const isFilterChanged
      = newCabang !== oldCabang
        || newDepartment !== oldDepartment

    if (!isFilterChanged)
      return

    /*
     * Reset pilihan PR lama karena cabang
     * atau department sudah berubah.
     */
    form.purchase_request_ids = []
    purchaseRequestList.value = []
    visibleAttachmentMap.value = {}
    prPage.value = 1

    if (!newCabang || !newDepartment)
      return

    await loadPurchaseRequestsByFilter()
  },
)

watch(
  () => form.id_department,
  async newDepartmentId => {
    form.vendor_id = null
    vendorList.value = []

    if (!newDepartmentId)
      return

    await loadVendors(false)
  },
  {
    immediate: true,
  },
)

watch(
  [
    createPermissionScope,
    assignedCreateDepartmentIds,
    () => departmentList.value.length,
  ],
  () => {
    if (!departmentList.value.length)
      return

    /*
     * Hanya sesuaikan pilihan department.
     * Jangan menampilkan toast dari watcher.
     */
    applyCreateDepartmentPermission()
  },
)

onMounted(async () => {
  await permissionStore.loadPermissions(true)

  if (
    !canCreate.value
    || !hasValidCreateScope.value
  ) {
    await router.replace('/forbidden')

    return
  }

  form.tanggal_po = today()

  await Promise.all([
    fetchCabangList(false),
    fetchDepartmentList(false),
  ])

  /*
   * Fungsi langsung memeriksa master department,
   * permission scope, dan department akun.
   */
  const hasDepartmentAccess
    = applyCreateDepartmentPermission()

  if (!hasDepartmentAccess) {
    showErrorToast({
      title: 'Department Tidak Tersedia',
      text:
        createPermissionScope.value
          === 'OWN_DEPARTMENT'
          ? 'Department pada akun Anda belum tersedia atau belum sesuai dengan master department.'
          : createPermissionScope.value
            === 'ASSIGNED_DEPARTMENTS'
            ? 'Tidak ada department yang diberikan pada permission Create Purchase Order.'
            : createPermissionScope.value
              === 'ALL'
              ? 'Master department belum tersedia.'
              : 'Anda tidak memiliki akses department untuk membuat Purchase Order.',
    })
  }

  isCheckingPermission.value = false
})
</script>

<template>
  <section>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between">
        <div>
          <div class="text-h6 font-weight-bold">
            Form Purchase Order
          </div>
          <div class="text-body-2 text-medium-emphasis">
            Silakan lengkapi data purchase order dengan benar
          </div>
        </div>

        <VBtn
          prepend-icon="mdi-arrow-left"
          variant="text"
          color="secondary"
          @click="goBack"
          class="text-none"
        >
          Kembali
        </VBtn>
      </VCardTitle>

      <VDivider />

      <VCardText>
        <VRow>
          <VCol cols="12" md="6">
            <AppDateTimePicker
              v-model="form.tanggal_po"
              label="Tanggal PO *"
              placeholder="Pilih tanggal PO"
              :config="{ dateFormat: 'Y-m-d' }"
              :error="isSubmitted && !form.tanggal_po"
              :error-messages="isSubmitted && !form.tanggal_po ? ['Tanggal PO wajib diisi'] : []"
            />
          </VCol>
          <!-- <VCol cols="12" md="6">
            <div class="position-relative">
              <VTextField
                :model-value="tanggalPO.displayValue.value"
                label="Tanggal PO *"
                placeholder="DD/MM/YYYY"
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

          <VCol cols="12" md="6"></VCol>

          <VCol cols="12" md="6">
            <VAutocomplete
              v-model="form.cabang"
              label="Cabang *"
              :items="cabangList"
              item-title="title"
              item-value="id"
              clearable
              density="comfortable"
              :loading="isLoadingCabang"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
              :error="isSubmitted && !form.cabang"
              :error-messages="isSubmitted && !form.cabang ? ['Cabang wajib dipilih'] : []"
              placeholder="Pilih Cabang"
              @update:model-value="handleSelectPRFilter"
            >
              <template #append-inner>
                <VTooltip
                  v-if="!isLoadingCabang && cabangList.length === 0"
                  text="Reload data cabang"
                  location="top"
                >
                  <template #activator="{ props }">
                    <VBtn
                      v-bind="props"
                      icon
                      size="x-small"
                      variant="text"
                      color="primary"
                      @click.stop.prevent="fetchCabangList(true)"
                    >
                      <VIcon icon="tabler-refresh" />
                    </VBtn>
                  </template>
                </VTooltip>
              </template>
            </VAutocomplete>
          </VCol>

          <VCol cols="12" md="6">
            <VAutocomplete
              v-model="form.id_department"
              label="Department *"
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
                createPermissionScope === 'OWN_DEPARTMENT'
                  ? 'Department otomatis mengikuti department akun Anda.'
                  : createPermissionScope === 'ASSIGNED_DEPARTMENTS'
                    ? 'Hanya department yang ditetapkan pada direct permission yang dapat dipilih.'
                    : createPermissionScope === 'ALL'
                      ? 'Anda dapat membuat Purchase Order untuk seluruh department.'
                      : 'Anda tidak memiliki akses department untuk membuat Purchase Order.'
              "
              no-data-text="Tidak ada department yang diizinkan"
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
                      createPermissionScope === 'OWN_DEPARTMENT'
                        ? 'Department akun login tidak ditemukan.'
                        : createPermissionScope === 'ASSIGNED_DEPARTMENTS'
                          ? 'Pilih salah satu department yang telah ditetapkan.'
                          : createPermissionScope === 'ALL'
                            ? 'Department wajib dipilih.'
                            : 'Anda tidak memiliki akses department untuk membuat Purchase Order.',
                    ]
                  : []
              "
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
              Pilih cabang dan department terlebih dahulu untuk menampilkan Purchase Request.
            </VAlert>

            <div v-else>
              <VTable class="border rounded">
                <thead>
                  <tr>
                    <th class="text-center" style="width: 50px;">
                      <VCheckbox
                        :model-value="isAllSelected"
                        hide-details
                        density="compact"
                        color="primary"
                        @update:model-value="toggleSelectAllPR"
                      />
                    </th>
                    <th>Nomor PR</th>
                    <th>Lampiran</th>
                    <th class="text-center">Tanggal</th>
                    <th class="text-center">Cabang</th>
                    <th class="text-center">Department</th>
                    <th class="text-end">Total PR</th>
                  </tr>
                </thead>

                <tbody>
                  <tr v-if="isLoadingPR">
                    <td colspan="6" class="text-center py-6">
                      Memuat Purchase Request...
                    </td>
                  </tr>

                  <tr v-else-if="!purchaseRequestList.length">
                    <td colspan="7" class="text-center text-medium-emphasis py-6">
                      Tidak ada Purchase Request tersedia untuk department ini.
                    </td>
                  </tr>

                  <tr
                    v-for="pr in paginatedPurchaseRequests"
                    v-else
                    :key="pr.id"
                  >
                    <td class="text-center">
                      <VCheckbox
                        v-model="form.purchase_request_ids"
                        :value="pr.id"
                        hide-details
                        density="compact"
                        color="primary"
                        @update:model-value="handleSelectPurchaseRequest"
                      />
                    </td>

                    <td class="font-weight-medium pr-number-cell">
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

                    <td class="pr-attachment-cell">
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
                            <VIcon
                              icon="tabler-paperclip"
                              size="16"
                              class="me-1"
                            />
                            <span>{{ file.original_filename || file.filename || 'Lampiran PR' }}</span>
                          </a>
                        </TransitionGroup>

                        <div class="d-flex gap-2 mt-2">
                          <VBtn
                            v-if="hasMoreAttachments(pr)"
                            size="x-small"
                            variant="text"
                            color="primary"
                            prepend-icon="tabler-chevron-down"
                            @click.stop="showMoreAttachments(pr)"
                          >
                            Tampilkan lainnya
                          </VBtn>

                          <VBtn
                            v-if="getVisibleAttachmentCount(pr.id) > 1"
                            size="x-small"
                            variant="text"
                            color="secondary"
                            prepend-icon="tabler-chevron-up"
                            @click.stop="showLessAttachments(pr)"
                          >
                            Tampilkan lebih sedikit
                          </VBtn>
                        </div>
                      </div>

                      <span
                        v-else
                        class="text-medium-emphasis text-caption"
                      >
                        Tidak ada lampiran
                      </span>
                    </td>

                    <td class="text-center">
                      {{ formatDate(pr.tanggal_pr) }}
                    </td>

                    <td class="text-center">{{ pr.cabang || '-' }}</td>
                    <td class="text-center">{{ pr.department || '-' }}</td>

                    <td class="text-end">
                      Rp {{ formatNumberWithoutRp(pr.total_amount) }}
                    </td>
                  </tr>
                </tbody>
              </VTable>

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
                Purchase Request wajib dipilih
              </div>
            </div>
          </VCol>

          <VCol cols="12">
            <div class="text-subtitle-1 font-weight-bold mb-3">
              Item Purchase Order
            </div>

            <VAlert
              v-if="!poItems.length"
              type="info"
              variant="tonal"
              class="mb-0"
            >
              Item akan muncul setelah PR dipilih.
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
                        Nomor PR
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
                          <th class="text-center col-check">Pilih</th>
                          <th class="col-item">Nama Item</th>
                          <th class="text-center col-qty">Qty PR</th>
                          <th class="text-center col-qty">Qty Sudah PO</th>
                          <th class="text-center col-qty">Outstanding</th>
                          <th class="text-center col-input">Qty PO</th>
                          <th class="text-center col-unit">Satuan</th>
                          <th class="text-end col-price">Harga</th>
                          <th class="text-end col-money">Total</th>
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
                              placeholder="Harga satuan"
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
                              Tidak dipilih
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
                    <span>Subtotal</span>
                    <strong>Rp {{ formatNumberWithoutRp(subtotal) }}</strong>
                  </div>

                  <div class="d-flex justify-space-between mb-2">
                    <span>DPP</span>
                    <strong>Rp {{ formatNumberWithoutRp(dpp) }}</strong>
                  </div>

                  <div class="d-flex justify-space-between mb-2">
                    <span>PPN</span>
                    <strong>Rp {{ formatNumberWithoutRp(ppn) }}</strong>
                  </div>

                  <VDivider class="my-3" />
                </template>

                <div class="d-flex justify-space-between">
                  <span>Grand Total</span>
                  <strong class="text-success">
                    Rp {{ formatNumberWithoutRp(grandTotal) }}
                  </strong>
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12">
            <VCard
              variant="tonal"
              class="rounded-xl"
            >
              <VCardText>
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-3">
                  <div>
                    <div class="text-subtitle-1 font-weight-bold">
                      Rekomendasi Vendor dari PR
                    </div>
                    <div class="text-caption text-medium-emphasis">
                      Rekomendasi berikut berasal dari PR yang dipilih
                    </div>
                  </div>

                  <VChip
                    size="small"
                    color="primary"
                    variant="tonal"
                  >
                    {{ selectedRecommendedVendors.length }} Rekomendasi
                  </VChip>
                </div>

                <VAlert
                  v-if="!form.purchase_request_ids.length"
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  Pilih Purchase Request terlebih dahulu untuk melihat vendor rekomendasi.
                </VAlert>

                <VAlert
                  v-else-if="!selectedRecommendedVendors.length"
                  type="warning"
                  variant="tonal"
                  density="compact"
                >
                  Tidak ada rekomendasi vendor
                </VAlert>

                <div
                  v-else
                  class="d-flex flex-wrap gap-2"
                >
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
              label="Vendor PO *"
              :items="vendorList"
              item-title="nama_vendor"
              item-value="id"
              clearable
              density="comfortable"
              :disabled="!form.id_department"
              :loading="isLoadingVendor"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
              }"
              :error="isSubmitted && !form.vendor_id"
              :error-messages="isSubmitted && !form.vendor_id ? ['Vendor wajib dipilih'] : []"
              placeholder="Pilih vendor untuk PO"
              @update:model-value="handleSelectVendor"
            >
              <template #append-inner>
                  <VProgressCircular
                    v-if="isLoadingVendor"
                    indeterminate
                    size="18"
                    width="2"
                  />

                  <VTooltip
                    v-else-if="vendorList.length === 0"
                    text="Reload data vendor"
                    location="top"
                  >
                    <template #activator="{ props }">
                      <VBtn
                        v-bind="props"
                        icon
                        size="x-small"
                        variant="text"
                        color="primary"
                        @click.stop.prevent="loadVendors(true)"
                      >
                        <VIcon icon="tabler-refresh" />
                      </VBtn>
                    </template>
                  </VTooltip>
                </template>
                </VAutocomplete>
          </VCol>

          <VCol cols="12" md="6"></VCol>

          <VCol cols="12" md="6">
            <VTextField
              v-model="form.jenis_pembayaran"
              label="Jenis Pembayaran *"
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
              label="TOP (Hari) *"
              readonly
              density="comfortable"
              placeholder="Contoh: 30"
              :error="isSubmitted && !form.top"
              :error-messages="isSubmitted && !form.top ? ['TOP wajib diisi'] : []"
            />
          </VCol>

          <VCol cols="12">
            <VTextarea
              v-model="form.notes"
              label="Catatan"
              placeholder="Catatan tambahan..."
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
            Batal
          </VBtn>

          <VBtn
            type="button"
            color="primary"
            :loading="isSaving"
            @click="savePurchaseOrder"
            class="text-none"
          >
            Simpan
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
                  Purchase Request Detail
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
                      Nomor PR
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
                      Cabang / Department
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
                      Grand Total PR
                    </div>

                    <div class="text-h6 font-weight-bold">
                      Rp {{ formatNumberWithoutRp(purchaseRequestDetailGrandTotal) }}
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
                  Tanggal PR
                </div>

                <div class="font-weight-medium">
                  {{ formatDate(selectedPurchaseRequestDetail.tanggal_pr) || '-' }}
                </div>

                <div class="text-caption text-medium-emphasis mt-4">
                  Requester
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
                  Cabang
                </div>

                <div class="font-weight-medium">
                  {{ selectedPurchaseRequestDetail.cabang || selectedPurchaseRequestDetail.cabang_name || '-' }}
                </div>

                <div class="text-caption text-medium-emphasis mt-4">
                  Department
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
                  Catatan
                </div>

                <div class="font-weight-medium white-space-pre-line">
                  {{ selectedPurchaseRequestDetail.notes || selectedPurchaseRequestDetail.keterangan || '-' }}
                </div>
              </VCol>
            </VRow>

            <VRow class="mt-4">
              <VCol cols="12">
                <VCard
                  class="rounded-md pr-recommended-vendor-detail-card"
                  variant="tonal"
                  color="warning"
                >
                  <VCardText>
                    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
                      <div>
                        <div class="text-subtitle-1 font-weight-bold">
                          Vendor Rekomendasi
                        </div>

                        <div class="text-body-2 text-medium-emphasis">
                          Snapshot vendor pada saat Purchase Request dibuat atau diubah.
                        </div>
                      </div>

                      <VChip
                        size="small"
                        :color="purchaseRequestDetailIsPKP ? 'success' : 'secondary'"
                        variant="flat"
                      >
                        {{ purchaseRequestDetailStatusPKPText }}
                      </VChip>
                    </div>

                    <VRow>
                      <VCol
                        cols="12"
                        md="4"
                      >
                        <div class="info-box">
                          <div class="info-label">
                            Nama Vendor
                          </div>

                          <div class="info-value">
                            {{ purchaseRequestDetailRecommendedVendor.nama_vendor || '-' }}
                          </div>
                        </div>
                      </VCol>

                      <VCol
                        cols="12"
                        md="4"
                      >
                        <div class="info-box">
                          <div class="info-label">
                            Jenis Pembayaran
                          </div>

                          <div class="info-value">
                            {{ purchaseRequestDetailJenisPembayaran || '-' }}
                          </div>
                        </div>
                      </VCol>

                      <VCol
                        cols="12"
                        md="4"
                      >
                        <div class="info-box">
                          <div class="info-label">
                            TOP
                          </div>

                          <div class="info-value">
                            {{ purchaseRequestDetailTop }}
                          </div>
                        </div>
                      </VCol>
                    </VRow>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>

            <VDivider class="my-6" />

            <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
              <div>
                <h3 class="text-h6 font-weight-bold mb-1">
                  Lampiran
                </h3>

                <div class="text-body-2 text-medium-emphasis">
                  Dokumen pendukung Purchase Request.
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
              Tidak ada lampiran.
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
                    <th>Nama File</th>
                    <th width="160">Ukuran</th>
                    <th width="180">Tipe</th>
                    <th width="120" class="text-center">Aksi</th>
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
                          Lihat File
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
                  Item Purchase Request
                </h3>

                <div class="text-body-2 text-medium-emphasis">
                  Detail item yang diajukan pada Purchase Request.
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
                    <th width="50">No</th>
                    <th>Item</th>
                    <th class="text-end">Qty</th>
                    <th class="text-center">Satuan</th>
                    <th class="text-end">Harga</th>
                    <th class="text-end">Subtotal</th>
                    <th>Keterangan</th>
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
                      Item Purchase Request belum tersedia.
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
            <VRow class="mt-4 justify-end">
              <VCol
                cols="12"
                md="5"
              >
                <VCard
                  class="rounded-md pr-detail-tax-summary-card"
                  variant="tonal"
                  color="primary"
                >
                  <VCardText>
                    <div class="pr-detail-tax-row">
                      <span>Subtotal Item</span>
                      <strong>
                        Rp {{ formatNumberWithoutRp(purchaseRequestDetailSubtotalBeforeTax) }}
                      </strong>
                    </div>

                    <template v-if="purchaseRequestDetailIsPKP">
                      <div class="pr-detail-tax-row">
                        <span>DPP</span>
                        <strong>
                          Rp {{ formatNumberWithoutRp(purchaseRequestDetailDpp) }}
                        </strong>
                      </div>

                      <div class="pr-detail-tax-row">
                        <span>PPN</span>
                        <strong>
                          Rp {{ formatNumberWithoutRp(purchaseRequestDetailPpn) }}
                        </strong>
                      </div>
                    </template>

                    <VDivider class="my-3" />

                    <div class="pr-detail-tax-row pr-detail-tax-grand-total">
                      <span>Grand Total PR</span>
                      <strong>
                        Rp {{ formatNumberWithoutRp(purchaseRequestDetailGrandTotal) }}
                      </strong>
                    </div>

                    <div class="pr-detail-tax-row">
                      <span>Total Sudah PO</span>
                      <strong>
                        Rp {{ formatNumberWithoutRp(purchaseRequestDetailTotalPOAmount) }}
                      </strong>
                    </div>

                    <VDivider class="my-3" />

                    <div class="pr-detail-tax-row pr-detail-tax-outstanding">
                      <span>Total Outstanding</span>
                      <strong>
                        Rp {{ formatNumberWithoutRp(purchaseRequestDetailOutstandingAmount) }}
                      </strong>
                    </div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>

          </div>
        </VCardText>

        <VCardActions class="justify-end pa-6 pt-0">
          <VBtn
            variant="tonal"
            color="secondary"
            @click="closePurchaseRequestDetail"
          >
            Tutup
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style lang="scss" scoped>
.pr-attachment-cell {
  min-width: 150px;
  max-width: 150px;
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

.pr-recommended-vendor-detail-card {
  border: 1px solid rgba(var(--v-theme-warning), 0.2);
}

.pr-detail-tax-summary-card {
  border: 1px solid rgba(var(--v-theme-primary), 0.16);
}

.pr-detail-tax-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-block-end: 10px;
  color: rgba(var(--v-theme-on-surface), 0.72);
  font-size: 0.95rem;
}

.pr-detail-tax-row:last-child {
  margin-block-end: 0;
}

.pr-detail-tax-row strong {
  color: rgba(var(--v-theme-on-surface), 0.86);
  font-size: 1rem;
  font-weight: 700;
  text-align: end;
  white-space: nowrap;
}

.pr-detail-tax-grand-total {
  font-size: 1rem;
  font-weight: 700;
}

.pr-detail-tax-grand-total strong {
  color: rgb(var(--v-theme-primary));
  font-size: 1.08rem;
}

.pr-detail-tax-outstanding {
  font-weight: 700;
}

.pr-detail-tax-outstanding strong {
  color: rgb(var(--v-theme-warning));
  font-size: 1.04rem;
}

@media (max-width: 600px) {
  .pr-detail-tax-row {
    align-items: flex-start;
    flex-direction: column;
    gap: 4px;
  }

  .pr-detail-tax-row strong {
    text-align: start;
    white-space: normal;
  }
}

</style>