<script setup lang="ts">
import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
import { useRouter } from 'vue-router'
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
import {
  onlyNumberKeypress,
  formatSanitizedNumberInput,
  sanitizeDecimalInput,
  parseDecimalInput,
  toTitleCase,
} from '@/utils/textFormatter'
import { useNativeDatePicker } from '@core/composable/useNativeDatePicker'
import { useDisplay } from 'vuetify'
import { usePermissionStore } from '@/stores/permission'

interface PrItem {
  nama_item: string
  master_material_group_id: number | null
  qty: number
  satuan: number | string | null
  spesifikasi: string
  keterangan: string
  harga_unit: number
  subtotal: number
}

interface MaterialGroupOption {
  id: number
  code: string
  name: string
  title: string
}

interface PurchaseRequestForm {
  tanggal_pr: string
  cabang: string | number | null
  id_department: string | number | null
  recommended_vendor_id: number | null
  kategori: string | null
  pr_type: string | null
  notes: string
  lampiran_requests: File[]
  items: PrItem[]
}

interface PurchaseRequestErrors {
  lampiran_request: string
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

interface UnitItem {
  id: number
  kode: string
  nama: string
  kategori: string
}

interface VendorOptionItem {
  id: number
  id_department: number | null
  label: string
  nama_vendor: string
  status_pkp: string
  jenis_pembayaran: string | null
  top: number
}

interface UserAccessAssignmentItem {
  id: number | null
  branch_id: number
  branch_name: string
  branch_code: string
  department_id: number
  department_code: string
  department_name: string
  is_primary: boolean
}

interface UserAccessBranchItem {
  id: number
  name: string
  code: string
  title: string
}

interface UserAccessDepartmentItem {
  id: number
  code: string
  name: string
  title: string
}

const router = useRouter()
const { mobile } = useDisplay()
const permissionStore = usePermissionStore()
const { t } = useI18n()

const isCheckingPermission = ref(true)

const isSubmitted = ref(false)
const isSaving = ref(false)

const fileRef = ref<HTMLInputElement | null>(null)

const MAX_FILE_SIZE = 3 * 1024 * 1024
const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png']

const cabangList = ref<any[]>([])
const isLoadingCabang = ref(false)

const departmentList = ref<any[]>([])
const isLoadingDepartment = ref(false)

const accessAssignmentList = ref<UserAccessAssignmentItem[]>([])
const departmentsByBranch = ref<Record<string, UserAccessDepartmentItem[]>>({})

const vendorList = ref<VendorOptionItem[]>([])
const isLoadingVendor = ref(false)

const units = ref<UnitItem[]>([])
const isLoadingUnits = ref(false)

const materialGroups = ref<MaterialGroupOption[]>([])
const isLoadingMaterialGroups = ref(false)

const itemDialog = ref(false)
const confirmCloseItemDialog = ref(false)
const itemDialogSaved = ref(false)
const tempItems = ref<PrItem[]>([])

const canCreate = computed(() => {
  return permissionStore.can('purchase_request.create')
})

const createEmptyItem = (): PrItem => ({
  nama_item: '',
  master_material_group_id: null,
  qty: 1,
  satuan: null,
  spesifikasi: '',
  keterangan: '',
  harga_unit: 0,
  subtotal: 0,
})

const form = reactive<PurchaseRequestForm>({
  tanggal_pr: '',
  cabang: null,
  id_department: null,
  recommended_vendor_id: null,
  kategori: null,
  pr_type: null,
  notes: '',
  lampiran_requests: [],
  items: [createEmptyItem()],
})

const prTypeList = [
  'Rutin',
  'Non Rutin',
]

const formatBranchTitle = (
  code: string | null | undefined,
  name: string | null | undefined,
): string => {
  const branchCode = String(code || '').trim()
  const branchName = String(name || '-').trim()

  return branchCode
    ? `${branchCode} - ${branchName}`
    : branchName
}

const formatDepartmentLabel = (
  code: string | null | undefined,
  name: string | null | undefined,
): string => {
  const departmentCode = String(code || '').trim()
  const departmentName = String(name || '-').trim()

  return departmentCode
    ? `${departmentCode} - ${departmentName}`
    : departmentName
}

const updateDepartmentListByBranch = (
  branchId: string | number | null | undefined,
  preserveSelectedDepartment = false,
): void => {
  if (!branchId) {
    departmentList.value = []

    if (!preserveSelectedDepartment)
      form.id_department = null

    return
  }

  const departments = departmentsByBranch.value[String(branchId)] || []

  departmentList.value = departments.map((item: UserAccessDepartmentItem) => ({
    id: Number(item.id),
    kode: item.code || '',
    nama: item.name || item.title || '-',
    label: item.title || formatDepartmentLabel(item.code, item.name),
  }))

  const departmentStillAvailable = departmentList.value.some(
    department => Number(department.id) === Number(form.id_department),
  )

  if (
    !preserveSelectedDepartment
    || (
      form.id_department
      && !departmentStillAvailable
    )
  ) {
    form.id_department = null
  }
}

const setUserDefaultBranchAndDepartment = async (): Promise<void> => {
  /*
  |--------------------------------------------------------------------------
  | Auto select hanya jika user punya 1 assignment saja.
  | Jika user punya lebih dari 1 akses cabang/department, form dibuat kosong
  | agar user wajib memilih cabang dan department secara manual.
  |--------------------------------------------------------------------------
  */
  form.cabang = null
  form.id_department = null
  form.recommended_vendor_id = null
  departmentList.value = []
  vendorList.value = []

  if (accessAssignmentList.value.length !== 1)
    return

  const onlyAssignment = accessAssignmentList.value[0]

  form.cabang = onlyAssignment.branch_id
  updateDepartmentListByBranch(form.cabang, true)
  form.id_department = onlyAssignment.department_id

  await handleDepartmentChange()
}

const tanggalPR = useNativeDatePicker(toRef(form, 'tanggal_pr'))

const errors = reactive<PurchaseRequestErrors>({
  lampiran_request: '',
})

const kategoriList = ['Baru', 'Perbaikan', 'Improvement', 'Regular', 'Lain-lain']

const today = (): string => new Date().toISOString().split('T')[0]

const required = (value: unknown): boolean => {
  return value !== '' && value !== null && value !== undefined
}

const getExtension = (fileName: string): string => {
  return fileName.split('.').pop()?.toLowerCase() || ''
}

const formatMoney = (value: number | null | undefined): string => {
  if (!value) return ''

  return new Intl.NumberFormat('id-ID').format(Number(value))
}

const onlyNumber = (e: KeyboardEvent): void => {
  onlyNumberKeypress(e)
}

const fetchCabangList = async (showAlert = true): Promise<void> => {
  isLoadingCabang.value = true
  isLoadingDepartment.value = true

  try {
    const response = await axios.get('/account/access-assignments', {
      headers: { Accept: 'application/json' },
    })

    const payload = response.data?.data || {}

    accessAssignmentList.value = Array.isArray(payload.assignments)
      ? payload.assignments.map((item: any) => ({
          id: item.id !== null && item.id !== undefined
            ? Number(item.id)
            : null,
          branch_id: Number(item.branch_id),
          branch_name: item.branch_name || '-',
          branch_code: item.branch_code || '',
          department_id: Number(item.department_id),
          department_code: item.department_code || '',
          department_name: item.department_name || '-',
          is_primary: Boolean(item.is_primary),
        }))
      : []

    cabangList.value = Array.isArray(payload.branches)
      ? payload.branches.map((item: UserAccessBranchItem) => ({
          id: Number(item.id),
          value: Number(item.id),
          title: item.title || formatBranchTitle(item.code, item.name),
          nama_cabang: item.name || item.title || '-',
          inisial_cabang: item.code || '',
        }))
      : []

    const rawDepartmentsByBranch = payload.departments_by_branch || {}
    const normalizedDepartmentsByBranch: Record<string, UserAccessDepartmentItem[]> = {}

    Object.entries(rawDepartmentsByBranch).forEach(([branchId, departments]) => {
      normalizedDepartmentsByBranch[String(branchId)] = Array.isArray(departments)
        ? departments.map((item: any) => ({
            id: Number(item.id),
            code: item.code || '',
            name: item.name || item.title || '-',
            title: item.title || formatDepartmentLabel(item.code, item.name),
          }))
        : []
    })

    departmentsByBranch.value = normalizedDepartmentsByBranch

    updateDepartmentListByBranch(form.cabang, true)
  } catch (error: unknown) {
    console.error('[Access Assignment] FETCH ERROR:', error)

    accessAssignmentList.value = []
    departmentsByBranch.value = {}
    cabangList.value = []
    departmentList.value = []

    if (showAlert) {
      showErrorToast({
        title: t('common.alert.error'),
        text: getApiErrorMessage(error, t('purchaseRequest.create.toast.loadAccessFailed')),
      })
    }
  } finally {
    isLoadingCabang.value = false
    isLoadingDepartment.value = false
  }
}

const fetchDepartmentList = async (showAlert = true): Promise<void> => {
  isLoadingDepartment.value = true

  try {
    if (!Object.keys(departmentsByBranch.value).length) {
      await fetchCabangList(showAlert)

      return
    }

    updateDepartmentListByBranch(form.cabang, true)
  } catch (error: unknown) {
    console.error('[Department Access] FETCH ERROR:', error)
    departmentList.value = []

    if (showAlert) {
      showErrorToast({
        title: t('common.alert.error'),
        text: getApiErrorMessage(error, t('purchaseRequest.create.toast.loadDepartmentFailed')),
      })
    }
  } finally {
    isLoadingDepartment.value = false
  }
}

const loadVendors = async (showAlert = true): Promise<void> => {
  isLoadingVendor.value = true

  try {
    const response = await axios.get('/master/vendor/dropdown-pr', {
      headers: { Accept: 'application/json' },
      params: {
        id_department: form.id_department || null,
      },
    })

    const data = Array.isArray(response.data?.data)
      ? response.data.data
      : Array.isArray(response.data)
        ? response.data
        : []

    vendorList.value = data.map((item: any) => {
      const normalizedStatusPkp = normalizeVendorStatusPkp(item.status_pkp)

      return {
        id: Number(item.id),
        id_department: item.id_department ? Number(item.id_department) : null,
        label: item.nama_vendor || item.title || '-',
        nama_vendor: item.nama_vendor || item.title || '-',
        status_pkp: normalizedStatusPkp,
        jenis_pembayaran: item.jenis_pembayaran || null,
        top: item.top !== null && item.top !== undefined ? Number(item.top) : 0,
      }
    })
  } catch (error: unknown) {
    console.error('[Vendor] FETCH ERROR:', error)
    vendorList.value = []

    if (showAlert) {
      showErrorToast({
        title: t('common.alert.error'),
        text: getApiErrorMessage(error, t('purchaseRequest.create.toast.loadVendorFailed')),
      })
    }
  } finally {
    isLoadingVendor.value = false
  }
}

const handleDepartmentChange = async (): Promise<void> => {
  form.recommended_vendor_id = null
  vendorList.value = []

  if (form.id_department) {
    await loadVendors(false)
  }
}

const handleBranchChange = async (): Promise<void> => {
  form.id_department = null
  form.recommended_vendor_id = null
  vendorList.value = []

  updateDepartmentListByBranch(form.cabang, false)
}

const loadUnits = async (showAlert = true): Promise<void> => {
  isLoadingUnits.value = true

  try {
    const response = await axios.get('/units/dropdown-select', {
      headers: { Accept: 'application/json' },
    })

    const payload = response?.data

    const data = Array.isArray(payload?.data)
      ? payload.data
      : Array.isArray(payload)
        ? payload
        : []

    units.value = data.map((item: any) => ({
      id: Number(item.id),
      kode: item.kode || '',
      nama: item.nama || '-',
      kategori: item.kategori || '',
    }))
  } catch (error: unknown) {
    console.error('[Units] FETCH ERROR:', error)
    units.value = []

    if (showAlert) {
      showErrorToast({
        title: t('common.alert.error'),
        text: getApiErrorMessage(error, t('purchaseRequest.create.toast.loadUnitFailed')),
      })
    }
  } finally {
    isLoadingUnits.value = false
  }
}

const loadMaterialGroups = async (showAlert = true): Promise<void> => {
  isLoadingMaterialGroups.value = true

  try {
    const response = await axios.get('/material-groups/dropdown-select', {
      headers: { Accept: 'application/json' },
    })

    const payload = response?.data

    const data = Array.isArray(payload?.data)
      ? payload.data
      : Array.isArray(payload)
        ? payload
        : []

    materialGroups.value = data.map((item: any) => ({
      id: Number(item.id),
      code: item.code || '',
      name: item.name || '-',
      title: item.title || `${item.code} - ${item.name}`,
    }))
  } catch (error: unknown) {
    console.error('[Material Groups] FETCH ERROR:', error)
    materialGroups.value = []

    if (showAlert) {
      showErrorToast({
        title: t('common.alert.error'),
        text: getApiErrorMessage(
          error,
          t('purchaseRequest.create.toast.loadMaterialGroupFailed'),
        ),
      })
    }
  } finally {
    isLoadingMaterialGroups.value = false
  }
}

const materialGroupFilter = (
  itemTitle: string,
  queryText: string,
  item: any,
): boolean => {
  const search = String(queryText ?? '').toLowerCase()

  if (!search)
    return true

  const raw = item?.raw ?? item

  return [raw?.code, raw?.name, raw?.title]
    .filter(Boolean)
    .some(field => String(field).toLowerCase().includes(search))
}

const unitFilter = (itemTitle: string, queryText: string, item: any): boolean => {
  const search = String(queryText ?? '').toLowerCase()
  const kode = String(item?.raw?.kode ?? '').toLowerCase()
  const nama = String(item?.raw?.nama ?? '').toLowerCase()
  const kategori = String(item?.raw?.kategori ?? '').toLowerCase()

  return kode.includes(search) || nama.includes(search) || kategori.includes(search)
}

const addItemRow = (): void => {
  form.items.push(createEmptyItem())
}

const resetItems = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: t('purchaseRequest.create.toast.resetItemsTitle'),
    text: t('purchaseRequest.create.toast.resetItemsText'),
    confirmButtonText: 'Ya, reset',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  form.items = [createEmptyItem()]
}

const removeItemRow = (index: number): void => {
  if (form.items.length <= 1) return

  form.items.splice(index, 1)
}

const updateItemSubtotal = (index: number): void => {
  const item = form.items[index]
  if (!item) return

  const qty = Number(item.qty || 0)
  const hargaUnit = Number(item.harga_unit || 0)

  item.subtotal = qty * hargaUnit
}

const normalizeVendorStatusPkp = (value: unknown): string => {
  const normalized = String(value || '')
    .trim()
    .toUpperCase()

  if ([
    'PKP',
    '1',
    'YA',
    'YES',
    'TRUE',
  ].includes(normalized)) {
    return 'PKP'
  }

  return 'NON_PKP'
}

const selectedRecommendedVendor = computed<VendorOptionItem | null>(() => {
  if (!form.recommended_vendor_id)
    return null

  return vendorList.value.find(vendor => {
    return Number(vendor.id) === Number(form.recommended_vendor_id)
  }) ?? null
})

const isSelectedVendorPkp = computed(() => {
  return normalizeVendorStatusPkp(selectedRecommendedVendor.value?.status_pkp) === 'PKP'
})

const selectedVendorStatusPkpLabel = computed(() => {
  return isSelectedVendorPkp.value ? 'PKP' : 'NON PKP'
})

const selectedVendorTaxDescription = computed(() => {
  if (!selectedRecommendedVendor.value)
    return t('purchaseRequest.create.vendorSection.taxDescNoVendor')

  if (isSelectedVendorPkp.value)
    return t('purchaseRequest.create.vendorSection.taxDescPkp')

  return t('purchaseRequest.create.vendorSection.taxDescNonPkp')
})

const calcSubtotalBeforeTax = (): number => {
  return form.items.reduce((total, item) => {
    return total + Number(item.subtotal || 0)
  }, 0)
}

const calcDpp = (): number => {
  if (!isSelectedVendorPkp.value)
    return 0

  return Number(((calcSubtotalBeforeTax() * 11) / 12).toFixed(2))
}

const calcPpn = (): number => {
  if (!isSelectedVendorPkp.value)
    return 0

  return Number((calcDpp() * 0.12).toFixed(2))
}

const calcGrandTotal = (): number => {
  return Number((calcSubtotalBeforeTax() + calcPpn()).toFixed(2))
}

const openItemFullscreen = (): void => {
  tempItems.value = JSON.parse(JSON.stringify(form.items))
  itemDialogSaved.value = false
  itemDialog.value = true
}

const closeItemDialog = (): void => {
  if (itemDialogSaved.value) {
    tempItems.value = []
    itemDialog.value = false
    return
  }

  confirmCloseItemDialog.value = true
}

const confirmCloseFullscreenItem = (): void => {
  confirmCloseItemDialog.value = false
  tempItems.value = []
  itemDialog.value = false
}

const saveItemsFromDialog = (): void => {
  if (!tempItems.value.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseRequest.create.toast.minOneItem'),
    })

    return
  }

  const invalidItemIndex = tempItems.value.findIndex(item => {
    return (
      !required(item.nama_item)
      || !item.master_material_group_id
      || !item.qty
      || Number(item.qty) <= 0
      || !required(item.satuan)
      || item.harga_unit === null
      || Number(item.harga_unit) <= 0
    )
  })

  if (invalidItemIndex !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseRequest.create.toast.completeItemRow', { number: invalidItemIndex + 1 }),
    })

    isSubmitted.value = true
    return
  }

  const normalizedItems = tempItems.value.map(item => {
    const qty = Number(item.qty || 0)
    const hargaUnit = Number(item.harga_unit || 0)

    return {
      ...item,
      qty,
      harga_unit: hargaUnit,
      subtotal: qty * hargaUnit,
    }
  })

  form.items = JSON.parse(JSON.stringify(normalizedItems))
  itemDialogSaved.value = true
  itemDialog.value = false
}

const triggerFileInput = (): void => {
  fileRef.value?.click()
}

const handleFileUpload = async (event: Event): Promise<void> => {
  const input = event.target as HTMLInputElement
  if (!input.files) return

  errors.lampiran_request = ''

  const invalidMessages: string[] = []

  for (const file of Array.from(input.files)) {
    const ext = getExtension(file.name)
    const validMime = ALLOWED_TYPES.includes(file.type)
    const validExt = ALLOWED_EXTENSIONS.includes(ext)

    if (!validMime && !validExt) {
      invalidMessages.push(`"${file.name}" bukan file PDF/JPG/JPEG/PNG.`)
      continue
    }

    if (file.size > MAX_FILE_SIZE) {
      invalidMessages.push(`"${file.name}" lebih dari 3MB.`)
      continue
    }

    const exists = form.lampiran_requests.some(
      existing => existing.name === file.name && existing.size === file.size,
    )

    if (!exists) form.lampiran_requests.push(file)
  }

  if (invalidMessages.length) {
    errors.lampiran_request = invalidMessages.join(' ')

    showWarningToast({
      title: t('purchaseRequest.create.toast.invalidFileTitle'),
      text: invalidMessages.join(' '),
    })
  }

  input.value = ''
}

const handleTempQtyInput = (value: string | number, index: number): void => {
  if (!tempItems.value[index]) return

  const sanitized = sanitizeDecimalInput(value, {
    maxIntegerLength: 12,
    maxDecimalLength: 2,
  })

  tempItems.value[index].qty = parseDecimalInput(sanitized)
  updateTempItemSubtotal(index)
}

const removeLampiran = (index: number): void => {
  form.lampiran_requests.splice(index, 1)
}

const formatFileSize = (bytes: number): string => {
  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

const getFileType = (file: File): string => {
  return file.type === 'application/pdf' ? 'PDF' : 'IMAGE'
}

const validateForm = async (): Promise<boolean> => {
  const kategoriIsValid = isITDepartment.value
    ? required(form.kategori)
    : true

  if (
    !required(form.tanggal_pr)
    || !required(form.cabang)
    || !required(form.id_department)
    || !kategoriIsValid
    || !required(form.pr_type)
  ) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseRequest.create.toast.completeRequiredData'),
    })

    return false
  }

  if (!form.items.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseRequest.create.toast.minOneItem'),
    })

    return false
  }

  if (form.items.some(item => !required(item.nama_item))) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseRequest.create.toast.itemNameRequired'),
    })

    return false
  }

  if (
    form.items.some(
      item => !item.qty || Number(item.qty) <= 0,
    )
  ) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseRequest.create.toast.itemQtyRequired'),
    })

    return false
  }

  if (
    form.items.some(
      item => !item.master_material_group_id,
    )
  ) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseRequest.create.toast.materialGroupRequired'),
    })

    return false
  }

  if (
    form.items.some(
      item => !required(item.satuan),
    )
  ) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseRequest.create.toast.itemUnitRequired'),
    })

    return false
  }

  if (
    form.items.some(
      item =>
        item.harga_unit === null
        || Number(item.harga_unit) <= 0,
    )
  ) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('purchaseRequest.create.toast.itemPriceRequired'),
    })

    return false
  }

  return true
}
const buildFormData = (): FormData => {
  const formData = new FormData()

  formData.append('tanggal_pr', String(form.tanggal_pr || ''))
  formData.append('cabang', String(form.cabang || ''))
  formData.append('id_department', String(form.id_department || ''))
  formData.append('recommended_vendor_id', form.recommended_vendor_id ? String(form.recommended_vendor_id) : '')
  formData.append('kategori', String(form.kategori || ''))
  formData.append('pr_type', String(form.pr_type || ''))
  formData.append('notes', String(form.notes || ''))
  formData.append('status_pkp', selectedRecommendedVendor.value ? normalizeVendorStatusPkp(selectedRecommendedVendor.value.status_pkp) : 'NON_PKP')
  formData.append('jenis_pembayaran', String(selectedRecommendedVendor.value?.jenis_pembayaran || ''))
  formData.append('top', String(selectedRecommendedVendor.value?.top ?? 0))
  formData.append('dpp', String(calcDpp()))
  formData.append('ppn', String(calcPpn()))
  formData.append('subtotal_before_tax', String(calcSubtotalBeforeTax()))
  formData.append('grand_total', String(calcGrandTotal()))

  formData.append(
    'items',
    JSON.stringify(
      form.items.map(item => ({
        nama_item: item.nama_item,
        master_material_group_id: item.master_material_group_id,
        qty: Number(item.qty || 0),
        satuan: item.satuan,
        spesifikasi: item.spesifikasi || '',
        keterangan: item.keterangan || '',
        harga_unit: Number(item.harga_unit || 0),
        subtotal: Number(item.subtotal || 0),
      })),
    ),
  )

  form.lampiran_requests.forEach(file => {
    formData.append('lampiran_request[]', file)
  })

  return formData
}

const addTempItemRow = (): void => {
  tempItems.value.push(createEmptyItem())
}

const removeTempItemRow = (index: number): void => {
  if (tempItems.value.length <= 1) return

  tempItems.value.splice(index, 1)
}

const updateTempItemSubtotal = (index: number): void => {
  const item = tempItems.value[index]
  if (!item) return

  const qty = Number(item.qty || 0)
  const hargaUnit = Number(item.harga_unit || 0)

  item.subtotal = qty * hargaUnit
}

const handleTempItemPriceInput = (event: Event, index: number): void => {
  const target = event.target as HTMLInputElement

  const result = formatSanitizedNumberInput(target.value, formatMoney, {
    maxLength: 12,
    emptyAsZero: true,
  })

  if (!tempItems.value[index]) return

  tempItems.value[index].harga_unit = result.numeric ?? 0
  updateTempItemSubtotal(index)

  target.value = result.formatted
}

const handleTempItemPricePaste = (event: ClipboardEvent, index: number): void => {
  const pastedText = event.clipboardData?.getData('text') || ''

  if (!/^\d+$/.test(pastedText.trim())) {
    event.preventDefault()

    showErrorToast({
      title: t('purchaseRequest.create.toast.invalidInputTitle'),
      text: t('purchaseRequest.create.toast.priceNumericOnly'),
    })

    return
  }

  if (!tempItems.value[index]) return

  const target = event.target as HTMLInputElement
  const harga = Number(pastedText)

  tempItems.value[index].harga_unit = harga
  updateTempItemSubtotal(index)

  target.value = formatMoney(harga)
}

const calcTempGrandTotal = (): number => {
  return tempItems.value.reduce((total, item) => {
    return total + Number(item.subtotal || 0)
  }, 0)
}

const selectedDepartment = computed(() => {
  return departmentList.value.find(
    department =>
      Number(department.id) === Number(form.id_department),
  )
})

const isITDepartment = computed(() => {
  const departmentCode = String(
    selectedDepartment.value?.kode ?? '',
  )
    .trim()
    .toUpperCase()

  return departmentCode === 'IT'
})

watch(
  isITDepartment,
  isIT => {
    if (!isIT)
      form.kategori = null
  },
  { immediate: true },
)

const savePurchaseRequest = async (event?: Event): Promise<void> => {
  event?.preventDefault()
  event?.stopPropagation()

  if (isSaving.value) return

  isSubmitted.value = true

  const isValid = await validateForm()
  if (!isValid) return

  const confirm = await showConfirmAlert({
    title: t('purchaseRequest.create.toast.saveConfirmTitle'),
    text: t('purchaseRequest.create.toast.saveConfirmText'),
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) return

  isSaving.value = true

  try {
    showLoadingAlert(t('purchaseRequest.create.toast.savingData'), t('common.alert.pleaseWait'))

    const formData = buildFormData()

    await axios.post('/transaction/purchase-request', formData, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    await router.replace({
      path: '/non_stock/purchase_request',
      query: { success: 'created' },
    })
  } catch (error: unknown) {
    closeAlert()

    const err = error as AxiosErrorShape

    console.error('[Purchase Requisition] SAVE ERROR:', err)

    if (err?.response?.status === 401) {
      showErrorToast({
        title: t('purchaseRequest.create.toast.sessionExpiredTitle'),
        text: t('purchaseRequest.create.toast.sessionExpiredText'),
      })

      localStorage.removeItem('accessToken')
      localStorage.removeItem('userData')
      localStorage.removeItem('navItems')

      await router.replace('/login')
      return
    }

    showErrorToast({
      title: t('common.alert.error'),
      text:
        err?.response?.data?.message
        || getApiErrorMessage(error, 'Gagal menyimpan Purchase Requisition.'),
    })
  } finally {
    isSaving.value = false
  }
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: t('purchaseRequest.create.toast.cancelConfirmTitle'),
    text: t('purchaseRequest.create.toast.cancelConfirmText'),
    confirmButtonText: 'Ya, batal',
    cancelButtonText: 'Batal',
  })

  if (confirm.isConfirmed) {
    await router.replace('/non_stock/purchase_request')
  }
}

const goBack = async (): Promise<void> => {
  await router.replace({
    path: '/non_stock/purchase_request',
  })
}

onMounted(async () => {

  await permissionStore.loadPermissions()

  if (!canCreate.value) {
    await router.replace('/forbidden')
    return
  }

  isCheckingPermission.value = false

  form.tanggal_pr = today()

  await loadUnits(false)
  await loadMaterialGroups(false)
  await fetchCabangList(false)
  await setUserDefaultBranchAndDepartment()
})
</script>

<template>
  <div
    v-if="isCheckingPermission"
    class="d-flex justify-center align-center"
    style="min-height: 300px;"
  >
    <VProgressCircular indeterminate />
  </div>

  <div v-else>
    <section>
    <VRow>
    <VCol cols="12">
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between">
          <div>
            <div class="text-h6 font-weight-bold">
              {{ t('purchaseRequest.create.header.title') }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('purchaseRequest.create.header.subtitle') }}
            </div>
          </div>

          <VBtn
            prepend-icon="mdi-arrow-left"
            variant="text"
            color="secondary"
            @click="goBack"
            class="text-none"
          >
            {{ t('purchaseRequest.create.header.backButton') }}
          </VBtn>
        </VCardTitle>

        <VDivider />

        <VCardText>
          <VRow>
            <VCol cols="12" md="4">
              <AppDateTimePicker
                v-model="form.tanggal_pr"
                :label="t('purchaseRequest.create.fields.tanggalPr')"
                :placeholder="t('purchaseRequest.create.placeholders.tanggalPr')"
                :config="{ dateFormat: 'Y-m-d' }"
                :error="isSubmitted && !form.tanggal_pr"
                :error-messages="isSubmitted && !form.tanggal_pr ? [t('purchaseRequest.create.validation.tanggalPr')] : []"
              />
            </VCol>

            <VCol cols="12" md="4">
              <VAutocomplete
                v-model="form.cabang"
                :label="t('purchaseRequest.create.fields.cabang')"
                :items="cabangList"
                item-title="title"
                item-value="value"
                density="comfortable"
                :loading="isLoadingCabang"
                :menu-props="{
                  location: 'bottom',
                  offset: 8,
                  maxHeight: 300,
                }"
                :clearable="cabangList.length > 1"
                :error="isSubmitted && !form.cabang"
                :error-messages="isSubmitted && !form.cabang ? [t('purchaseRequest.create.validation.cabang')] : []"
                :no-data-text="t('purchaseRequest.create.noData.cabang')"
                :placeholder="t('purchaseRequest.create.placeholders.cabang')"
                @update:model-value="handleBranchChange"
              >
                <template #append-inner>
                  <VTooltip
                    v-if="!isLoadingCabang && cabangList.length === 0"
                    :text="t('purchaseRequest.create.reload.cabang')"
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

            <VCol cols="12" md="4">
              <VAutocomplete
                v-model="form.id_department"
                :label="t('purchaseRequest.create.fields.department')"
                :items="departmentList"
                item-title="label"
                item-value="id"
                density="comfortable"
                :menu-props="{
                  location: 'bottom',
                  offset: 8,
                  maxHeight: 300,
                  maxWidth: 100,
                }"
                :clearable="departmentList.length > 1"
                :disabled="!form.cabang"
                :loading="isLoadingDepartment"
                :error="isSubmitted && !form.id_department"
                :error-messages="isSubmitted && !form.id_department ? [t('purchaseRequest.create.validation.department')] : []"
                :no-data-text="t('purchaseRequest.create.noData.department')"
                :placeholder="t('purchaseRequest.create.placeholders.department')"
                @update:model-value="handleDepartmentChange"
              >
                <template #append-inner>
                  <VProgressCircular
                    v-if="isLoadingDepartment"
                    indeterminate
                    size="18"
                    width="2"
                  />

                  <VTooltip
                    v-else-if="departmentList.length === 0"
                    :text="t('purchaseRequest.create.reload.department')"
                    location="top"
                  >
                    <template #activator="{ props }">
                      <VBtn
                        v-bind="props"
                        icon
                        size="x-small"
                        variant="text"
                        color="primary"
                        @click.stop.prevent="fetchDepartmentList(true)"
                      >
                        <VIcon icon="tabler-refresh" />
                      </VBtn>
                    </template>
                  </VTooltip>
                </template>
              </VAutocomplete>
            </VCol>

            <VCol
              v-if="isITDepartment"
              cols="12"
              md="4"
            >
              <VAutocomplete
                v-model="form.kategori"
                :label="t('purchaseRequest.create.fields.kategori')"
                :items="kategoriList"
                clearable
                density="comfortable"
                :menu-props="{
                  location: 'bottom',
                  offset: 8,
                  maxHeight: 300,
                }"
                :error="
                  isSubmitted
                    && isITDepartment
                    && !form.kategori
                "
                :error-messages="
                  isSubmitted
                    && isITDepartment
                    && !form.kategori
                    ? [t('purchaseRequest.create.validation.kategori')]
                    : []
                "
                :no-data-text="t('purchaseRequest.create.noData.kategori')"
                :placeholder="t('purchaseRequest.create.placeholders.kategori')"
              />
            </VCol>

            <VCol cols="12" md="4">
              <VAutocomplete
                v-model="form.pr_type"
                :label="t('purchaseRequest.create.fields.tipePr')"
                :items="prTypeList"
                clearable
                density="comfortable"
                :menu-props="{
                  location: 'bottom',
                  offset: 8,
                  maxHeight: 300,
                }"
                :error="isSubmitted && !form.pr_type"
                :error-messages="isSubmitted && !form.pr_type ? [t('purchaseRequest.create.validation.tipePr')] : []"
                :no-data-text="t('purchaseRequest.create.noData.tipePr')"
                :placeholder="t('purchaseRequest.create.placeholders.tipePr')"
              />
            </VCol>
          </VRow>
          <VRow>
            <!-- DAFTAR ITEM SUMMARY -->
            <VCol cols="12">
              <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-4 mb-3">
                <div>
                  <div class="text-subtitle-1 font-weight-bold">
                    {{ t('purchaseRequest.create.items.sectionTitle') }}
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('purchaseRequest.create.items.sectionSubtitle') }}
                  </div>
                </div>

                <div class="d-flex align-center flex-wrap gap-2">
                  <VBtn
                    type="button"
                    color="primary"
                    variant="tonal"
                    size="small"
                    prepend-icon="tabler-plus"
                    @click="openItemFullscreen"
                    class="text-none"
                  >
                    {{ t('purchaseRequest.create.items.addButton') }}
                  </VBtn>

                  <VBtn
                    type="button"
                    color="error"
                    variant="outlined"
                    size="small"
                    @click="resetItems"
                    class="text-none"
                  >
                    {{ t('purchaseRequest.create.items.resetButton') }}
                  </VBtn>
                </div>
              </div>

              <VCard
                flat
                class="item-summary-card"
              >
                <VCardText>
                  <VAlert
                    v-if="!form.items.length || form.items.every(item => !item.nama_item)"
                    type="info"
                    variant="tonal"
                    density="compact"
                  >
                    {{ t('purchaseRequest.create.items.emptyAlert', { action: t('purchaseRequest.create.items.addButton') }) }}
                  </VAlert>

                  <div v-else class="d-flex flex-column gap-3">
                    <div
                      v-for="(item, index) in form.items"
                      :key="`summary-item-${index}`"
                      class="item-summary-row"
                    >
                      <div class="d-flex align-start gap-3">
                        <VAvatar
                          size="30"
                          color="primary"
                          variant="tonal"
                        >
                          {{ index + 1 }}
                        </VAvatar>

                        <div class="flex-grow-1">
                          <div class="font-weight-bold">
                            {{ toTitleCase(item.nama_item) || '-' }}
                          </div>

                          <VChip
                            v-if="item.master_material_group_id"
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
                            {{
                              materialGroups.find(
                                group => Number(group.id) === Number(item.master_material_group_id),
                              )?.title || '-'
                            }}
                          </VChip>

                          <div class="text-caption text-medium-emphasis mt-1">
                            {{ t('purchaseRequest.create.items.qtyLabel') }}: <strong>{{ item.qty || 0 }}</strong>
                            <span class="mx-1">•</span>
                            {{ t('purchaseRequest.create.items.satuanLabel') }}:
                            <strong>
                              {{
                                units.find(unit => Number(unit.id) === Number(item.satuan))?.nama
                                || item.satuan
                                || '-'
                              }}
                            </strong>
                            <span class="mx-1">•</span>
                            {{ t('purchaseRequest.create.items.hargaLabel') }}:
                            <strong>Rp {{ formatMoney(item.harga_unit) || '0' }}</strong>
                          </div>

                          <div
                            v-if="item.keterangan"
                            class="text-caption text-medium-emphasis mt-1 text-pre-line"
                          >
                            {{ t('purchaseRequest.create.items.keteranganLabel') }}: <br> {{ item.keterangan }}
                          </div>
                        </div>

                        <div class="text-end">
                          <div class="text-caption text-medium-emphasis">
                            {{ t('purchaseRequest.create.items.subtotalLabel') }}
                          </div>
                          <div class="font-weight-bold">
                            Rp {{ formatMoney(item.subtotal) || '0' }}
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <VDivider class="my-4" />

                  <div class="pr-tax-summary-wrapper">
                    <div class="pr-tax-summary">
                      <div class="pr-tax-row">
                        <span>{{ t('purchaseRequest.create.summary.subtotalItem') }}</span>
                        <strong>Rp {{ formatMoney(calcSubtotalBeforeTax()) || '0' }}</strong>
                      </div>

                      <template v-if="isSelectedVendorPkp">
                        <div class="pr-tax-row">
                          <span>{{ t('purchaseRequest.create.summary.dpp') }}</span>
                          <strong>Rp {{ formatMoney(calcDpp()) || '0' }}</strong>
                        </div>

                        <div class="pr-tax-row">
                          <span>{{ t('purchaseRequest.create.summary.ppn') }}</span>
                          <strong>Rp {{ formatMoney(calcPpn()) || '0' }}</strong>
                        </div>
                      </template>

                      <div
                        v-else
                        class="pr-tax-row pr-tax-row-muted"
                      >
                        <span>{{ t('purchaseRequest.create.summary.ppn') }}</span>
                        <strong>{{ t('purchaseRequest.create.summary.ppnNotCalculated') }}</strong>
                      </div>

                      <VDivider class="my-2" />

                      <div class="pr-tax-row pr-tax-grand-total">
                        <span>{{ t('purchaseRequest.create.summary.grandTotal') }}</span>
                        <strong>Rp {{ formatMoney(calcGrandTotal()) || '0' }}</strong>
                      </div>
                    </div>
                  </div>
                </VCardText>
              </VCard>
            </VCol>

            <!-- VENDOR REKOMENDASI -->
            <VCol cols="12">
              <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-4 mb-2">
                <div>
                  <div class="text-subtitle-1 font-weight-bold">
                    {{ t('purchaseRequest.create.vendorSection.title') }}
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('purchaseRequest.create.vendorSection.subtitle') }}
                  </div>
                </div>
              </div>

              <VDivider class="mb-4" />
            </VCol>

            <VCol cols="12" md="6">
              <VAutocomplete
                v-model="form.recommended_vendor_id"
                :label="t('purchaseRequest.create.fields.vendorRecommended')"
                :items="vendorList"
                item-title="label"
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
                :no-data-text="t('purchaseRequest.create.noData.vendor')"
                :placeholder="t('purchaseRequest.create.placeholders.vendor')"
              >
                <template #item="{ props, item }">
                  <VListItem
                    v-bind="props"
                    :title="item.raw?.nama_vendor || item.raw?.label || '-'"
                  >
                    <template #subtitle>
                      <div class="d-flex align-center flex-wrap gap-2 mt-1">
                        <VChip
                          size="x-small"
                          variant="tonal"
                          :color="normalizeVendorStatusPkp(item.raw?.status_pkp) === 'PKP' ? 'success' : 'secondary'"
                        >
                          {{ normalizeVendorStatusPkp(item.raw?.status_pkp) === 'PKP' ? 'PKP' : 'NON PKP' }}
                        </VChip>

                        <span v-if="item.raw?.jenis_pembayaran">
                          {{ item.raw.jenis_pembayaran }}
                        </span>

                        <span v-if="Number(item.raw?.top || 0) > 0">
                          TOP {{ Number(item.raw?.top || 0) }} Hari
                        </span>
                      </div>
                    </template>
                  </VListItem>
                </template>

                <template #selection="{ item }">
                  <div class="d-flex align-center gap-2 text-truncate">
                    <span class="text-truncate">
                      {{ item.raw?.nama_vendor || item.raw?.label || '-' }}
                    </span>

                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="normalizeVendorStatusPkp(item.raw?.status_pkp) === 'PKP' ? 'success' : 'secondary'"
                    >
                      {{ normalizeVendorStatusPkp(item.raw?.status_pkp) === 'PKP' ? 'PKP' : 'NON PKP' }}
                    </VChip>
                  </div>
                </template>

                <template #append-inner>
                  <VProgressCircular
                    v-if="isLoadingVendor"
                    indeterminate
                    size="18"
                    width="2"
                  />

                  <VTooltip
                    v-else-if="vendorList.length === 0"
                    :text="t('purchaseRequest.create.reload.vendor')"
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

            <VCol cols="12" md="6">
              <VCard
                flat
                class="vendor-tax-card"
                :class="{ 'vendor-tax-card-pkp': isSelectedVendorPkp }"
              >
                <VCardText class="pa-4">
                  <div class="d-flex align-start gap-3">
                    <VAvatar
                      :color="isSelectedVendorPkp ? 'success' : 'secondary'"
                      variant="tonal"
                      size="42"
                    >
                      <VIcon icon="tabler-receipt-tax" />
                    </VAvatar>

                    <div class="flex-grow-1">
                      <div class="d-flex align-center flex-wrap gap-2 mb-1">
                        <div class="font-weight-bold">
                          {{ t('purchaseRequest.create.vendorSection.taxStatusTitle') }}
                        </div>

                        <VChip
                          size="small"
                          variant="tonal"
                          :color="isSelectedVendorPkp ? 'success' : 'secondary'"
                        >
                          {{ selectedVendorStatusPkpLabel }}
                        </VChip>
                      </div>

                      <div class="text-body-2 text-medium-emphasis">
                        {{ selectedVendorTaxDescription }}
                      </div>

                      <div
                        v-if="selectedRecommendedVendor"
                        class="vendor-payment-info mt-3"
                      >
                        <div>
                          <span>{{ t('purchaseRequest.create.vendorSection.paymentType') }}</span>
                          <strong>{{ selectedRecommendedVendor.jenis_pembayaran || '-' }}</strong>
                        </div>

                        <div>
                          <span>{{ t('purchaseRequest.create.vendorSection.top') }}</span>
                          <strong>
                            {{ Number(selectedRecommendedVendor.top || 0) > 0 ? `${Number(selectedRecommendedVendor.top)} ${t('purchaseRequest.create.vendorSection.days')}` : '-' }}
                          </strong>
                        </div>
                      </div>
                    </div>
                  </div>
                </VCardText>
              </VCard>
            </VCol>

            <!-- LAMPIRAN -->
            <VCol cols="12">
              <div class="mt-4">
                <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-2">
                  <div class="text-subtitle-1 font-weight-bold">
                    {{ t('purchaseRequest.create.attachment.title') }}
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
                      + {{ t('purchaseRequest.create.attachment.addButton') }}
                    </VBtn>
                  </div>
                </div>

                <VDivider class="mb-4" />

                <VAlert
                  v-if="errors.lampiran_request"
                  type="warning"
                  variant="tonal"
                  class="mb-4"
                >
                  {{ errors.lampiran_request }}
                </VAlert>

                <VAlert
                  v-if="!form.lampiran_requests.length"
                  type="info"
                  variant="tonal"
                >
                  {{ t('purchaseRequest.create.attachment.empty') }}
                </VAlert>

                <VList
                  v-else
                  density="comfortable"
                  border
                  rounded
                >
                  <VListItem
                    v-for="(file, index) in form.lampiran_requests"
                    :key="`${file.name}-${file.size}-${index}`"
                  >
                    <template #prepend>
                      <VIcon
                        :icon="getFileType(file) === 'PDF' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                      />
                    </template>

                    <VListItemTitle class="text-body-2">
                      {{ file.name }}
                    </VListItemTitle>

                    <VListItemSubtitle>
                      {{ getFileType(file) }} • {{ formatFileSize(file.size) }}
                    </VListItemSubtitle>

                    <template #append>
                      <VBtn
                        type="button"
                        color="error"
                        variant="text"
                        size="small"
                        @click="removeLampiran(index)"
                      >
                        {{ t('purchaseRequest.create.attachment.deleteButton') }}
                      </VBtn>
                    </template>
                  </VListItem>
                </VList>
              </div>
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                :label="t('purchaseRequest.create.fields.notes')"
                :placeholder="t('purchaseRequest.create.placeholders.notes')"
                rows="3"
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
              {{ t('purchaseRequest.create.buttons.cancel') }}
            </VBtn>

            <VBtn
              type="button"
              color="primary"
              :loading="isSaving"
              @click.prevent.stop="savePurchaseRequest($event)"
              class="text-none"
            >
              {{ t('purchaseRequest.create.buttons.save') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>
    </VRow>
    <VDialog
      v-model="itemDialog"
      fullscreen
      scrollable
    >
      <VCard>
        <VToolbar color="primary">
          <VBtn
            icon
            variant="text"
            color="white"
            @click="closeItemDialog"
          >
            <VIcon icon="tabler-x" />
          </VBtn>

          <VToolbarTitle>{{ t('purchaseRequest.create.itemDialog.title') }}</VToolbarTitle>

          <VSpacer />

          <VBtn
            variant="flat"
            class="me-3 text-none"
            @click="saveItemsFromDialog"
          >
            {{ t('purchaseRequest.create.itemDialog.saveButton') }}
          </VBtn>
        </VToolbar>

        <VCardText class="pa-4 item-fullscreen-body">
          <div class="item-fullscreen-table-wrapper">
            <VTable class="item-fullscreen-table">
              <thead>
                <tr>
                  <th class="col-no">{{ t('purchaseRequest.create.itemDialog.tableNo') }}</th>
                  <th class="col-name">{{ t('purchaseRequest.create.itemDialog.tableNamaItem') }}</th>
                  <th class="col-qty">{{ t('purchaseRequest.create.itemDialog.tableQty') }}</th>
                  <th class="col-unit">{{ t('purchaseRequest.create.itemDialog.tableSatuan') }}</th>
                  <th class="col-price">{{ t('purchaseRequest.create.itemDialog.tableHargaSatuan') }}</th>
                  <th class="col-subtotal">{{ t('purchaseRequest.create.itemDialog.tableSubtotal') }}</th>
                  <th class="col-note">{{ t('purchaseRequest.create.itemDialog.tableKeterangan') }}</th>
                  <th class="col-action text-center">{{ t('purchaseRequest.create.itemDialog.tableAksi') }}</th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="(item, index) in tempItems"
                  :key="`temp-item-${index}`"
                >
                  <td>{{ index + 1 }}</td>

                  <td>
                    <div class="d-flex flex-column ga-2">
                      <VTextField
                        v-model="item.nama_item"
                        :placeholder="t('purchaseRequest.create.placeholders.namaItem')"
                        density="compact"
                        hide-details="auto"
                        variant="outlined"
                        class="fullscreen-field"
                        :error="isSubmitted && !item.nama_item"
                        :error-messages="isSubmitted && !item.nama_item ? [t('purchaseRequest.create.validation.namaItem')] : []"
                      />

                      <VAutocomplete
                        v-model="item.master_material_group_id"
                        :items="materialGroups"
                        item-title="title"
                        item-value="id"
                        :placeholder="t('purchaseRequest.create.fields.materialGroupPlaceholder')"
                        density="compact"
                        hide-details="auto"
                        variant="outlined"
                        class="fullscreen-field material-group-field"
                        :loading="isLoadingMaterialGroups"
                        :custom-filter="materialGroupFilter"
                        :error="isSubmitted && !item.master_material_group_id"
                        :error-messages="
                          isSubmitted && !item.master_material_group_id
                            ? [t('purchaseRequest.create.toast.materialGroupRequired')]
                            : []
                        "
                      >
                        <template #prepend-inner>
                          <VIcon
                            icon="tabler-category"
                            size="16"
                            class="text-medium-emphasis"
                          />
                        </template>

                        <template #no-data>
                          <div class="px-4 py-2 d-flex align-center justify-space-between ga-2">
                            <span class="text-caption text-medium-emphasis">
                              {{ t('purchaseRequest.create.toast.loadMaterialGroupFailed') }}
                            </span>

                            <VBtn
                              size="x-small"
                              variant="tonal"
                              :loading="isLoadingMaterialGroups"
                              @click.stop.prevent="loadMaterialGroups(true)"
                            >
                              {{ t('purchaseRequest.create.toast.reloadMaterialGroup') }}
                            </VBtn>
                          </div>
                        </template>
                      </VAutocomplete>
                    </div>
                  </td>

                  <td>
                    <VTextField
                      :model-value="item.qty"
                      type="text"
                      inputmode="decimal"
                      min="0.01"
                      :placeholder="t('purchaseRequest.create.placeholders.qty')"
                      density="compact"
                      hide-details="auto"
                      variant="outlined"
                      class="fullscreen-field"
                      :error="isSubmitted && (!item.qty || Number(item.qty) <= 0)"
                      :error-messages="isSubmitted && (!item.qty || Number(item.qty) <= 0) ? [t('purchaseRequest.create.validation.qty')] : []"
                      @update:model-value="value => handleTempQtyInput(value, index)"
                    />
                  </td>

                  <td>
                    <VAutocomplete
                      v-model="item.satuan"
                      :items="units"
                      item-title="nama"
                      item-value="id"
                      :placeholder="t('purchaseRequest.create.placeholders.satuan')"
                      density="compact"
                      hide-details="auto"
                      variant="outlined"
                      class="fullscreen-field"
                      :loading="isLoadingUnits"
                      :menu-props="{
                        location: 'bottom',
                        offset: 8,
                        maxHeight: 300,
                      }"
                      :custom-filter="unitFilter"
                      :error="isSubmitted && !item.satuan"
                      :error-messages="isSubmitted && !item.satuan ? [t('purchaseRequest.create.validation.satuan')] : []"
                    >
                      <template #append-inner>
                        <VProgressCircular
                          v-if="isLoadingUnits"
                          indeterminate
                          size="16"
                          width="2"
                        />

                        <VTooltip
                          v-else-if="units.length === 0"
                          :text="t('purchaseRequest.create.reload.satuan')"
                          location="top"
                        >
                          <template #activator="{ props }">
                            <VBtn
                              v-bind="props"
                              icon
                              size="x-small"
                              variant="text"
                              color="primary"
                              @click.stop.prevent="loadUnits(true)"
                            >
                              <VIcon icon="tabler-refresh" />
                            </VBtn>
                          </template>
                        </VTooltip>
                      </template>

                      <!--
                        Slot prop sengaja diberi nama `unitOption`, bukan `item`,
                        agar tidak menutupi variabel `item` dari v-for baris tabel.
                      -->
                      <template #item="{ props, item: unitOption }">
                        <VListItem
                          v-bind="props"
                          :title="`${unitOption.raw?.kode ?? ''} - ${unitOption.raw?.nama ?? ''}`"
                          :subtitle="unitOption.raw?.kategori ?? ''"
                        />
                      </template>

                      <template #selection="{ item: unitOption }">
                        <span v-if="unitOption?.raw?.kode">
                          {{ unitOption.raw.kode }}
                        </span>
                      </template>
                    </VAutocomplete>
                  </td>

                  <td>
                    <VTextField
                      :model-value="formatMoney(item.harga_unit)"
                      :placeholder="t('purchaseRequest.create.placeholders.hargaSatuan')"
                      density="compact"
                      hide-details="auto"
                      variant="outlined"
                      inputmode="numeric"
                      class="text-right-field fullscreen-field"
                      :error="isSubmitted && (item.harga_unit === null || Number(item.harga_unit) <= 0)"
                      :error-messages="isSubmitted && (item.harga_unit === null || Number(item.harga_unit) <= 0) ? [t('purchaseRequest.create.validation.harga')] : []"
                      @keypress="onlyNumber"
                      @input="handleTempItemPriceInput($event, index)"
                      @paste.prevent="handleTempItemPricePaste($event, index)"
                    />
                  </td>

                  <td>
                    <VTextField
                      :model-value="formatMoney(item.subtotal)"
                      density="compact"
                      hide-details
                      variant="outlined"
                      readonly
                      class="text-right-field fullscreen-field"
                    />
                  </td>

                  <td>
                    <VTextarea
                      v-model="item.keterangan"
                      :placeholder="t('purchaseRequest.create.placeholders.keterangan')"
                      density="compact"
                      hide-details
                      variant="outlined"
                      class="fullscreen-field fullscreen-textarea"
                      rows="2"
                      auto-grow
                    />
                  </td>

                  <td class="text-center">
                    <VBtn
                      v-if="tempItems.length > 1"
                      icon
                      color="error"
                      variant="text"
                      size="small"
                      @click="removeTempItemRow(index)"
                    >
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>

                <tr v-if="!tempItems.length">
                  <td
                    colspan="8"
                    class="text-center text-medium-emphasis py-6"
                  >
                    {{ t('purchaseRequest.create.itemDialog.emptyRow') }}
                  </td>
                </tr>
              </tbody>
            </VTable>
            <div class="d-flex justify-space-between align-center mt-4">
              <VBtn
                color="primary"
                variant="tonal"
                prepend-icon="tabler-plus"
                @click="addTempItemRow"
                class="text-none"
              >
                {{ t('purchaseRequest.create.itemDialog.addRowButton') }}
              </VBtn>

              <div class="text-body-1 font-weight-bold">
                {{ t('purchaseRequest.create.itemDialog.subtotalItem') }}
                <strong>{{ formatMoney(calcTempGrandTotal()) }}</strong>
              </div>
            </div>
          </div>
        </VCardText>
      </VCard>
    </VDialog>

    <VDialog
      v-model="confirmCloseItemDialog"
      max-width="460"
      persistent
    >
      <VCard>
        <VCardTitle class="text-h6">
          {{ t('purchaseRequest.create.itemDialog.closeConfirmTitle') }}
        </VCardTitle>

        <VCardText>
          {{ t('purchaseRequest.create.itemDialog.closeConfirmText') }}
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            @click="confirmCloseItemDialog = false"
            class="text-none"
          >
            {{ t('purchaseRequest.create.itemDialog.closeConfirmCancel') }}
          </VBtn>

          <VBtn
            color="primary"
            @click="confirmCloseFullscreenItem"
            class="text-none"
          >
            {{ t('purchaseRequest.create.itemDialog.closeConfirmOk') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
  </div>
  
</template>

<style scoped>
.item-fullscreen-body {
  overflow-x: hidden;
}

.item-fullscreen-table-wrapper {
  width: 100%;
  overflow-x: auto;
}

.item-fullscreen-table {
  width: 100%;
  min-width: 980px;
  table-layout: fixed;
}

.item-fullscreen-table th,
.item-fullscreen-table td {
  padding: 10px 8px !important;
  vertical-align: top;
}

.item-fullscreen-table .col-no {
  width: 44px;
}

/*
 * Kolom ini memuat dua field bertumpuk (Nama Item + Material Group),
 * sehingga dilebarkan sedikit agar teks "KODE - Nama Grup" tidak terpotong.
 */
.item-fullscreen-table .col-name {
  width: 260px;
}

.item-fullscreen-table .material-group-field :deep(.v-field__input) {
  font-size: 0.8125rem;
  min-height: 34px;
  padding-block: 0;
}

.item-fullscreen-table .material-group-field :deep(.v-field__prepend-inner) {
  padding-inline-end: 4px;
}

.item-fullscreen-table .col-qty {
  width: 80px;
}

.item-fullscreen-table .col-unit {
  width: 130px;
}

.item-fullscreen-table .col-price {
  width: 160px;
}

.item-fullscreen-table .col-subtotal {
  width: 160px;
}

.item-fullscreen-table .col-note {
  width: 220px;
}

.item-fullscreen-table .col-action {
  width: 70px;
}

.fullscreen-field :deep(.v-field__input) {
  min-height: 38px !important;
  padding-top: 6px !important;
  padding-bottom: 6px !important;
  font-size: 14px;
}

.fullscreen-field :deep(.v-messages) {
  font-size: 11px;
  line-height: 1.1;
}

@media (max-width: 1200px) {
  .item-fullscreen-table {
    min-width: 900px;
  }

  .item-fullscreen-table .col-name {
    width: 230px;
  }

  .item-fullscreen-table .col-note {
    width: 180px;
  }
}
.fullscreen-textarea {
  min-width: 260px;
}

.fullscreen-textarea :deep(textarea) {
  line-height: 1.4;
  resize: vertical;
}

.pr-tax-summary-wrapper {
  display: flex;
  justify-content: flex-end;
}

.pr-tax-summary {
  width: 100%;
  max-width: 380px;
  padding: 14px 16px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  background: rgba(var(--v-theme-surface), 0.88);
}

.pr-tax-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.875rem;
  padding-block: 4px;
}

.pr-tax-row strong {
  color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity));
  font-weight: 700;
  text-align: end;
  white-space: nowrap;
}

.pr-tax-row-muted strong {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-weight: 600;
}

.pr-tax-grand-total {
  color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity));
  font-size: 1rem;
  font-weight: 800;
}

.pr-tax-grand-total strong {
  color: rgb(var(--v-theme-primary));
  font-size: 1.05rem;
}

.vendor-tax-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  background: rgba(var(--v-theme-on-surface), 0.025);
}

.vendor-tax-card-pkp {
  border-color: rgba(var(--v-theme-success), 0.28);
  background: rgba(var(--v-theme-success), 0.055);
}

.vendor-payment-info {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.vendor-payment-info > div {
  padding: 10px 12px;
  border-radius: 12px;
  background: rgba(var(--v-theme-surface), 0.82);
}

.vendor-payment-info span {
  display: block;
  margin-bottom: 2px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.75rem;
}

.vendor-payment-info strong {
  display: block;
  overflow: hidden;
  color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity));
  font-size: 0.875rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 600px) {
  .pr-tax-summary-wrapper {
    justify-content: stretch;
  }

  .pr-tax-summary {
    max-width: none;
  }

  .vendor-payment-info {
    grid-template-columns: minmax(0, 1fr);
  }

  .pr-tax-row {
    align-items: flex-start;
  }
}
</style>