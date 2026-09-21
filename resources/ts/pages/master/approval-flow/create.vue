<script setup lang="ts">
import axios from '@axios'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'

import { getApiErrorMessage } from '@/utils/apiHelper'
import { formatNumberWithoutRp } from '@/utils/textFormatter'
import {
  documentTypeUsesAreaMatrix,
  documentTypeUsesTransactionCategory,
  getApprovalFlowDocumentTypeLabel,
  getApprovalFlowModuleCode,
  getDefaultApprovalFlowDocumentType,
  loadApprovalFlowDocumentTypes,
  normalizeApprovalFlowDocumentType,
} from '@/utils/approvalFlowDocumentType'

interface AxiosErrorShape {
  response?: {
    data?: {
      message?: string
      debug?: string
      errors?: Record<string, string[]>
    }
  }
}

interface PermissionModuleOption {
  id: number
  code: string
  name: string
  is_active?: boolean
}

interface MasterRoleOption {
  id: number
  name: string
  kode?: string | null
}

interface MasterUserOption {
  id: number
  name: string
  email?: string | null
}

interface MasterDepartmentOption {
  id: number
  title?: string
  name: string
  nama?: string | null
  kode?: string | null
  label?: string | null
}

type ApproverScope = 'GLOBAL' | 'SAME_BRANCH' | 'SELECTED_BRANCHES'

interface SpecialApproverForm {
  special_document_type_id: number | null
  approver_type: 'ROLE' | 'USER'
  approver_id: number | null
}

interface SpecialDocumentTypeOption {
  id: number
  code: string
  name: string
}

interface ApproverForm {
  approver_type: 'ROLE' | 'USER'
  approver_id: number | null
  approver_scope: ApproverScope
  branch_ids: number[]

  /*
   * Approver pengganti per Tipe Dokumen Khusus. Kosong berarti approver di
   * atas berlaku untuk semua dokumen -- perilaku seperti sebelum fitur ini.
   */
  special_approvers: SpecialApproverForm[]
}

interface ApprovalStepForm {
  local_key: string
  step_order: number
  label: string
  approval_mode: 'ANY' | 'ALL'
  approvers: ApproverForm[]
}

interface ApprovalFlowForm {
  document_type: string

  permission_module_id: number | null
  permission_module_code: string
  permission_module_name: string

  name: string
  description: string
  min_amount: number | null
  max_amount: number | null
  is_active: boolean

  area_type: 'HO' | 'CABANG' | ''
  cabang: string | null

  /*
   * Cakupan flow. Satu flow bisa mencakup beberapa department dan beberapa
   * keterangan transaksi, atau seluruhnya lewat penanda all_*.
   */
  all_departments: boolean
  department_ids: number[]

  all_transaction_categories: boolean
  transaction_category_ids: number[]

  steps: ApprovalStepForm[]
}

interface TransactionCategoryOption {
  id: number
  code: string
  title: string
}

const autocompleteMultiple: any = true

const route = useRoute()
const router = useRouter()

const isSubmitted = ref(false)
const submitLoading = ref(false)

const isLoadingRole = ref(false)
const isLoadingUser = ref(false)
const isLoadingDepartment = ref(false)
const isLoadingBranch = ref(false)
const isLoadingPermissionModule = ref(false)

const roleOptions = ref<MasterRoleOption[]>([])

/*
 * Tipe Dokumen Khusus untuk mengatur approver pengganti per step.
 * Bila master-nya kosong, bagian pengaturan ini tidak dimunculkan sama sekali.
 */
const specialDocumentTypeOptions = ref<SpecialDocumentTypeOption[]>([])

const loadSpecialDocumentTypes = async (): Promise<void> => {
  try {
    const response = await axios.get('/special-document-types/dropdown-select', {
      /*
       * scope=all: menu ini mengatur flow milik department LAIN, jadi daftar
       * tipe tidak boleh disaring berdasarkan department admin yang login.
       */
      params: { scope: 'all' },
      headers: { Accept: 'application/json' },
    })

    const data = Array.isArray(response?.data?.data) ? response.data.data : []

    specialDocumentTypeOptions.value = data.map((item: any) => ({
      id: Number(item.id),
      code: String(item.code || ''),
      name: String(item.name || '-'),
    }))
  } catch (error: unknown) {
    console.error('[Special Document Types] FETCH ERROR:', error)
    specialDocumentTypeOptions.value = []
  }
}

const addSpecialApprover = (stepIndex: number, approverIndex: number): void => {
  form.steps[stepIndex].approvers[approverIndex].special_approvers.push({
    special_document_type_id: null,
    approver_type: 'USER',
    approver_id: null,
  })
}

const removeSpecialApprover = (
  stepIndex: number,
  approverIndex: number,
  specialIndex: number,
): void => {
  form.steps[stepIndex].approvers[approverIndex].special_approvers.splice(specialIndex, 1)
}

const userOptions = ref<MasterUserOption[]>([])
const departmentOptions = ref<MasterDepartmentOption[]>([])
const branchOptions = ref<DropdownOption[]>([])

const APPROVAL_FLOW_INDEX_PATH = '/master/approval-flow'
const APPROVAL_FLOW_STORE_ENDPOINT = '/master/approval-flows'

const approverScopeOptions = [
  {
    title: 'Global',
    value: 'GLOBAL',
  },
  {
    title: 'Sesuai Cabang Dokumen',
    value: 'SAME_BRANCH',
  },
  {
    title: 'Cabang Tertentu',
    value: 'SELECTED_BRANCHES',
  },
]

const approverTypeOptions = [
  { title: 'Role', value: 'ROLE' },
  { title: 'User', value: 'USER' },
]

const areaTypeOptions = [
  { title: 'Head Office (HO)', value: 'HO' },
  { title: 'Cabang', value: 'CABANG' },
]

const approvalModeOptions = [
  { title: 'ANY', value: 'ANY' },
  { title: 'ALL', value: 'ALL' },
]

const getSelectedApproverId = (value: unknown): number | null => {
  if (value === null || value === undefined || value === '')
    return null

  return Number(value) || null
}

const normalizeApproverId = (value: unknown): number | null => {
  if (value === null || value === undefined || value === '')
    return null

  if (typeof value === 'object') {
    const objectValue = value as any

    return Number(objectValue.id ?? objectValue.value ?? 0) || null
  }

  return Number(value) || null
}

const isApproverAlreadySelectedInSameStep = (
  stepIndex: number,
  approverIndex: number,
  approverType: 'ROLE' | 'USER',
  approverId: number,
): boolean => {
  const step = form.steps[stepIndex]

  if (!step)
    return false

  return step.approvers.some((approver, index) => {
    if (index === approverIndex)
      return false

    return approver.approver_type === approverType
      && Number(getSelectedApproverId(approver.approver_id)) === Number(approverId)
  })
}

const getAvailableApproverItems = (
  stepIndex: number,
  approverIndex: number,
  approverType: 'ROLE' | 'USER',
): any[] => {
  const sourceItems = approverType === 'ROLE'
    ? roleOptions.value
    : userOptions.value

  return sourceItems.filter(item => {
    return !isApproverAlreadySelectedInSameStep(
      stepIndex,
      approverIndex,
      approverType,
      Number(item.id),
    )
  })
}

/*
 * Diambil mentah dari URL; dibakukan terhadap master pada onMounted setelah
 * daftar jenis dokumen selesai dimuat.
 */
const getInitialDocumentType = (): string => {
  return String(route.query.document_type || '')
}

const makeLocalKey = (): string => {
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`
}

const form = reactive<ApprovalFlowForm>({
  document_type: getInitialDocumentType(),
  permission_module_id: null,
  permission_module_code: '',
  permission_module_name: '',

  name: '',
  description: '',
  min_amount: null,
  max_amount: null,
  is_active: true,

  area_type: '',
  cabang: null,

  all_departments: false,
  department_ids: [],

  all_transaction_categories: false,
  transaction_category_ids: [],

  steps: [
    {
      local_key: makeLocalKey(),
      step_order: 1,
      label: '',
      approval_mode: 'ANY',
      approvers: [
        {
          approver_type: 'ROLE',
          approver_id: null,
          approver_scope: 'GLOBAL',
          branch_ids: [],
          special_approvers: [],
        },
      ],
    },
  ],
})

const expectedPermissionModuleCode = computed(() => {
  return matchDocumentTypeToModuleCode(
    form.document_type,
  )
})

const matchDocumentTypeToModuleCode = (
  documentType: string,
): string => {
  return getApprovalFlowModuleCode(documentType)
}

const loadPermissionModule = async (): Promise<void> => {
  isLoadingPermissionModule.value = true

  try {
    const response = await axios.get(
      '/master/permission-modules',
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    const rawItems
      = response.data?.data?.data
        ?? response.data?.data
        ?? response.data
        ?? []

    const modules: PermissionModuleOption[]
      = Array.isArray(rawItems)
        ? rawItems.map((item: any) => ({
            id: Number(item.id),
            code: String(item.code || ''),
            name: String(item.name || ''),
            is_active: Boolean(
              item.is_active ?? true,
            ),
          }))
        : []

    const selectedModule = modules.find(module => {
      return module.code
          === expectedPermissionModuleCode.value
        && module.is_active !== false
    })

    if (!selectedModule) {
      form.permission_module_id = null
      form.permission_module_code = ''
      form.permission_module_name = ''

      throw new Error(
        `Module ${expectedPermissionModuleCode.value} tidak ditemukan.`,
      )
    }

    form.permission_module_id
      = selectedModule.id

    form.permission_module_code
      = selectedModule.code

    form.permission_module_name
      = selectedModule.name
  }
  catch (error: any) {
    showErrorToast({
      title: 'Gagal Memuat Module',
      text: getApiErrorMessage(
        error,
        'Module approval flow tidak ditemukan.',
      ),
    })
  }
  finally {
    isLoadingPermissionModule.value = false
  }
}


/*
 * PR dan FPU dibedakan per area + department, sehingga kedua field itu wajib
 * diisi. Jenis dokumen lain tidak memakainya.
 */
const usesAreaMatrix = computed(() => documentTypeUsesAreaMatrix(form.document_type))

/*
 * Keterangan transaksi hanya dipakai jenis dokumen tertentu (FPU). Penentunya
 * master permission module, bukan pemeriksaan jenis dokumen di sini.
 */
const usesTransactionCategory = computed(
  () => documentTypeUsesTransactionCategory(form.document_type),
)

const transactionCategoryOptions = ref<TransactionCategoryOption[]>([])
const isLoadingTransactionCategory = ref(false)

const loadTransactionCategories = async (): Promise<void> => {
  isLoadingTransactionCategory.value = true

  try {
    const response = await axios.get(
      '/fund-request/transaction-categories/dropdown-select',
      {
        /*
         * include_inactive: flow lama bisa saja memakai kategori yang sudah
         * dinonaktifkan; tanpa ini pilihannya hilang diam-diam saat diedit.
         */
        params: { include_inactive: 1 },
        headers: { Accept: 'application/json' },
      },
    )

    const data = Array.isArray(response?.data?.data) ? response.data.data : []

    transactionCategoryOptions.value = data.map((item: any) => ({
      id: Number(item.id),
      code: String(item.code || ''),
      title: String(item.title || item.name || '-'),
    }))
  } catch (error: unknown) {
    console.error('[Transaction Categories] FETCH ERROR:', error)
    transactionCategoryOptions.value = []
  } finally {
    isLoadingTransactionCategory.value = false
  }
}

const documentTypeLabel = computed(() => {
  return getApprovalFlowDocumentTypeLabel(form.document_type)
})

const pageTitle = computed(() => {
  return `Tambah Approval Flow ${documentTypeLabel.value}`
})

const totalStep = computed(() => form.steps.length)

const totalApprover = computed(() => {
  return form.steps.reduce((total, step) => total + step.approvers.length, 0)
})

const amountRangePreview = computed(() => {
  const minAmount = Number(form.min_amount || 0)
  const maxAmount = Number(form.max_amount || 0)

  if (minAmount <= 0 && maxAmount <= 0)
    return 'Semua Nilai'

  if (minAmount > 0 && maxAmount <= 0)
    return `> ${formatAmount(minAmount)}`

  if (minAmount <= 0 && maxAmount > 0)
    return `≤ ${formatAmount(maxAmount)}`

  return `${formatAmount(minAmount)} - ${formatAmount(maxAmount)}`
})

const selectedDepartmentName = computed(() => {
  if (form.all_departments)
    return 'Semua Divisi'

  if (!form.department_ids.length)
    return '-'

  return form.department_ids
    .map(id => {
      const department = departmentOptions.value.find(
        item => Number(item.id) === Number(id),
      )

      return department?.title
        || department?.label
        || department?.nama
        || department?.name
        || '-'
    })
    .join(', ')
})

const selectedTransactionCategoryName = computed(() => {
  if (form.all_transaction_categories)
    return 'Semua Keterangan Transaksi'

  if (!form.transaction_category_ids.length)
    return '-'

  return form.transaction_category_ids
    .map(id => {
      return transactionCategoryOptions.value.find(
        item => Number(item.id) === Number(id),
      )?.title || '-'
    })
    .join(', ')
})

/**
 * Menyelaraskan field area + department dengan jenis dokumen terpilih.
 *
 * Dipanggil dari watcher dan sekali lagi pada onMounted setelah master jenis
 * dokumen selesai dimuat -- sebelum itu aturan area belum diketahui.
 */
const applyDocumentTypeDefaults = (): void => {
  const normalizedType = normalizeApprovalFlowDocumentType(form.document_type)

  form.document_type = normalizedType

  /*
   * Area dan department hanya berlaku bagi dokumen yang flow-nya dibedakan
   * per matriks area + department. Untuk jenis lain, nilainya dikosongkan
   * supaya tidak ikut terkirim.
   */
  if (!documentTypeUsesAreaMatrix(normalizedType)) {
    form.area_type = ''
    form.cabang = null
    form.all_departments = false
    form.department_ids = []
  } else if (!form.area_type) {
    form.area_type = 'HO'
  }

  /*
   * Keterangan transaksi hanya dipakai sebagian jenis dokumen. Untuk yang
   * lain, flow selalu tersimpan sebagai "semua kategori".
   */
  if (!documentTypeUsesTransactionCategory(normalizedType)) {
    form.all_transaction_categories = true
    form.transaction_category_ids = []
  }
}

watch(
  () => form.document_type,
  () => applyDocumentTypeDefaults(),
  { immediate: true },
)

watch(
  () => form.area_type,
  () => {
    form.cabang = null
  },
)

onMounted(async () => {
  /*
   * Harus lebih dulu: label, aturan area, dan pemetaan ke permission module
   * semuanya dibaca dari master jenis dokumen ini.
   */
  try {
    await loadApprovalFlowDocumentTypes()
  } catch (error: any) {
    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        error,
        'Gagal memuat daftar jenis dokumen approval flow.',
      ),
    })
  }

  form.document_type = normalizeApprovalFlowDocumentType(form.document_type)
    || getDefaultApprovalFlowDocumentType()

  applyDocumentTypeDefaults()

  await Promise.all([
    loadPermissionModule(),
    loadApproverOptions(),
    loadDepartmentOptions(),
    loadBranchOptions(),
    loadSpecialDocumentTypes(),
    loadTransactionCategories(),
  ])
})

interface DropdownOption {
  id: number
  title: string
  value: number | string
  name?: string
  email?: string | null
  kode?: string | null
  nama?: string | null
  nama_cabang?: string | null
  inisial_cabang?: string | null
}

const normalizeDropdownItems = (payload: any): DropdownOption[] => {
  const rawItems = payload?.data?.data
    ?? payload?.data
    ?? payload
    ?? []

  if (!Array.isArray(rawItems))
    return []

  return rawItems.map((item: any) => {
    const id = Number(item.id)
    const name = String(item.name || item.nama || item.nama_cabang || item.label || '-')
    const kode = item.kode || null
    const inisialCabang = item.inisial_cabang || null
    const namaCabang = item.nama_cabang || null
    const email = item.email || null

    let title = name

    if (inisialCabang && namaCabang)
      title = `${inisialCabang} - ${namaCabang}`
    else if (kode && name)
      title = `${kode} - ${name}`

    return {
      id,
      value: item.value ?? item.inisial_cabang ?? item.kode ?? item.id,
      title,
      name,
      email,
      kode,
      nama: item.nama || null,
      nama_cabang: namaCabang,
      inisial_cabang: inisialCabang,
    }
  })
}

const minAmountErrorMessage = computed(() => {
  const minAmount = Number(form.min_amount || 0)
  const maxAmount = Number(form.max_amount || 0)

  if (minAmount < 0)
    return 'Minimal nilai tidak boleh lebih kecil dari 0'

  if (minAmount > 0 && maxAmount > 0 && minAmount > maxAmount)
    return 'Minimal nilai tidak boleh lebih besar dari maksimal nilai'

  return ''
})

const maxAmountErrorMessage = computed(() => {
  const minAmount = Number(form.min_amount || 0)
  const maxAmount = Number(form.max_amount || 0)

  if (maxAmount < 0)
    return 'Maksimal nilai tidak boleh lebih kecil dari 0'

  if (minAmount > 0 && maxAmount > 0 && maxAmount < minAmount)
    return 'Maksimal nilai tidak boleh lebih kecil dari minimal nilai'

  return ''
})

const hasAmountError = computed(() => {
  return Boolean(minAmountErrorMessage.value || maxAmountErrorMessage.value)
})

const loadApproverOptions = async (): Promise<void> => {
  isLoadingRole.value = true
  isLoadingUser.value = true

  try {
    const [roleResponse, userResponse] = await Promise.all([
      axios.get('/master/dropdown/roles', {
        headers: { Accept: 'application/json' },
      }),
      axios.get('/master/dropdown/users', {
        headers: { Accept: 'application/json' },
      }),
    ])

    roleOptions.value = normalizeDropdownItems(roleResponse.data) as MasterRoleOption[]
    userOptions.value = normalizeDropdownItems(userResponse.data) as MasterUserOption[]
  } catch (error: any) {
    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal memuat data role atau user approver.'),
    })
  } finally {
    isLoadingRole.value = false
    isLoadingUser.value = false
  }
}

const loadDepartmentOptions = async (): Promise<void> => {
  isLoadingDepartment.value = true

  try {
    const response = await axios.get('/master/department/dropdown-select', {
      headers: { Accept: 'application/json' },
    })

    departmentOptions.value = normalizeDropdownItems(response.data) as MasterDepartmentOption[]
  } catch (error: any) {
    departmentOptions.value = []

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal memuat data department.'),
    })
  } finally {
    isLoadingDepartment.value = false
  }
}

const loadBranchOptions = async (): Promise<void> => {
  isLoadingBranch.value = true

  try {
    const response = await axios.get('/master/cabang/dropdown-select', {
      headers: { Accept: 'application/json' },
    })

    branchOptions.value = normalizeDropdownItems(response.data)
      .map(item => ({
        ...item,
        id: Number(item.id),
        value: Number(item.id),
      }))
      .filter(item => Number(item.id) > 0)
  } catch (error: any) {
    branchOptions.value = []

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal memuat data cabang.'),
    })
  } finally {
    isLoadingBranch.value = false
  }
}

const getDepartmentTitle = (item: any): string => {
  const kode = item?.kode ? String(item.kode) : ''
  const nama = item?.nama || item?.name || item?.label || ''

  if (kode && nama)
    return `${kode} - ${nama}`

  return String(nama || kode || '-')
}

const getApproverItems = (approverType: 'ROLE' | 'USER'): any[] => {
  return approverType === 'ROLE'
    ? roleOptions.value
    : userOptions.value
}

const getApproverTitle = (item: any): string => {
  return String(
    item?.name
      || item?.fullname
      || item?.email
      || item?.label
      || '-',
  )
}

const refreshStepOrder = (): void => {
  form.steps.forEach((step, index) => {
    step.step_order = index + 1
  })
}

const addStep = (): void => {
  form.steps.push({
    local_key: makeLocalKey(),
    step_order: form.steps.length + 1,
    label: '',
    approval_mode: 'ANY',
    approvers: [
      {
        approver_type: 'ROLE',
        approver_id: null,
        approver_scope: 'GLOBAL',
        branch_ids: [],
        special_approvers: [],
      },
    ],
  })
}

const removeStep = (stepIndex: number): void => {
  if (form.steps.length <= 1) {
    showErrorToast({
      title: 'Tidak Bisa Dihapus',
      text: 'Minimal harus ada 1 step approval.',
    })

    return
  }

  form.steps.splice(stepIndex, 1)
  refreshStepOrder()
}

const addApprover = (stepIndex: number): void => {
  form.steps[stepIndex].approvers.push({
    approver_type: 'ROLE',
    approver_id: null,
    approver_scope: 'GLOBAL',
    branch_ids: [],
    special_approvers: [],
  })
}

const removeApprover = (stepIndex: number, approverIndex: number): void => {
  const step = form.steps[stepIndex]

  if (step.approvers.length <= 1) {
    showErrorToast({
      title: 'Tidak Bisa Dihapus',
      text: 'Minimal harus ada 1 approver pada setiap step.',
    })

    return
  }

  step.approvers.splice(approverIndex, 1)
}

const onApproverTypeChange = (stepIndex: number, approverIndex: number): void => {
  form.steps[stepIndex].approvers[approverIndex].approver_id = null
}

const onApproverScopeChange = (stepIndex: number, approverIndex: number): void => {
  const approver = form.steps[stepIndex].approvers[approverIndex]

  if (approver.approver_scope !== 'SELECTED_BRANCHES')
    approver.branch_ids = []
}

const validateForm = (): boolean => {
  isSubmitted.value = true

  if (!form.document_type) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Jenis dokumen tidak valid.',
    })

    return false
  }

  if (!form.permission_module_id) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Module approval flow belum tersedia.',
    })

    return false
  }

  if (!form.name.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Nama approval flow wajib diisi.',
    })

    return false
  }

  const minAmount = Number(form.min_amount || 0)
  const maxAmount = Number(form.max_amount || 0)

  if (minAmount < 0) {
    showErrorToast({
        title: 'Validasi Gagal',
        text: 'Minimal nilai tidak boleh lebih kecil dari 0.',
    })

    return false
    }

    if (maxAmount < 0) {
    showErrorToast({
        title: 'Validasi Gagal',
        text: 'Maksimal nilai tidak boleh lebih kecil dari 0.',
    })

    return false
    }

    if (hasAmountError.value) {
    showErrorToast({
        title: 'Validasi Gagal',
        text: minAmountErrorMessage.value || maxAmountErrorMessage.value,
    })

    return false
    }

  if (usesAreaMatrix.value) {
    if (!form.area_type) {
      showErrorToast({
        title: 'Validasi Gagal',
        text: `Area type wajib dipilih untuk approval ${documentTypeLabel.value}.`,
      })

      return false
    }

    if (!form.all_departments && !form.department_ids.length) {
      showErrorToast({
        title: 'Validasi Gagal',
        text: `Department wajib dipilih untuk approval ${documentTypeLabel.value}.`,
      })

      return false
    }
  }

  if (
    usesTransactionCategory.value
    && !form.all_transaction_categories
    && !form.transaction_category_ids.length
  ) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: `Keterangan transaksi wajib dipilih untuk approval ${documentTypeLabel.value}.`,
    })

    return false
  }

  if (!form.steps.length) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Minimal harus ada 1 step approval.',
    })

    return false
  }

  for (const [stepIndex, step] of form.steps.entries()) {
    if (!step.approval_mode) {
      showErrorToast({
        title: 'Validasi Gagal',
        text: `Approval mode pada step ${stepIndex + 1} wajib dipilih.`,
      })

      return false
    }

    if (!step.approvers.length) {
      showErrorToast({
        title: 'Validasi Gagal',
        text: `Step ${stepIndex + 1} minimal memiliki 1 approver.`,
      })

      return false
    }

    const usedApproverInStep = new Set<string>()

    for (const [approverIndex, approver] of step.approvers.entries()) {
      if (!approver.approver_type) {
        showErrorToast({
          title: 'Validasi Gagal',
          text: `Tipe approver pada step ${stepIndex + 1}, approver ${approverIndex + 1} wajib dipilih.`,
        })

        return false
      }

      if (!approver.approver_id) {
        showErrorToast({
          title: 'Validasi Gagal',
          text: `Approver pada step ${stepIndex + 1}, approver ${approverIndex + 1} wajib dipilih.`,
        })

        return false
      }

      if (!approver.approver_scope) {
        showErrorToast({
          title: 'Validasi Gagal',
          text: `Scope approver pada step ${stepIndex + 1}, approver ${approverIndex + 1} wajib dipilih.`,
        })

        return false
      }

      if (
        approver.approver_scope === 'SELECTED_BRANCHES'
        && approver.branch_ids.length === 0
      ) {
        showErrorToast({
          title: 'Validasi Gagal',
          text: `Cabang yang ditangani pada step ${stepIndex + 1}, approver ${approverIndex + 1} wajib dipilih.`,
        })

        return false
      }

      const approverKey = `${approver.approver_type}-${approver.approver_id}`

      if (usedApproverInStep.has(approverKey)) {
        showErrorToast({
          title: 'Validasi Gagal',
          text: `Approver duplikat pada step ${stepIndex + 1}.`,
        })

        return false
      }

      usedApproverInStep.add(approverKey)
    }
  }

  return true
}

const buildPayload = () => {
  return {
    document_type: form.document_type,

    permission_module_id:
      form.permission_module_id,

    name: form.name.trim(),

    description:
      form.description?.trim() || null,

    min_amount:
      form.min_amount !== null
      && form.min_amount !== undefined
      && Number(form.min_amount) > 0
        ? Number(form.min_amount)
        : null,

    max_amount:
      form.max_amount !== null
      && form.max_amount !== undefined
      && Number(form.max_amount) > 0
        ? Number(form.max_amount)
        : null,

    is_active: Boolean(form.is_active),

    area_type:
      usesAreaMatrix.value
        ? form.area_type
        : null,

    cabang: null,

    /*
     * Cakupan flow. Backend menyimpan department_ids ke tabel pivot dan
     * mengisi creator_department_id dari elemen pertama untuk kompatibilitas.
     */
    all_departments: usesAreaMatrix.value && form.all_departments,

    department_ids:
      usesAreaMatrix.value && !form.all_departments
        ? form.department_ids
        : [],

    all_transaction_categories: usesTransactionCategory.value
      ? form.all_transaction_categories
      : true,

    transaction_category_ids:
      usesTransactionCategory.value && !form.all_transaction_categories
        ? form.transaction_category_ids
        : [],

    steps: form.steps.map(
      (step, stepIndex) => ({
        step_order: stepIndex + 1,
        label: step.label?.trim() || null,
        approval_mode:
          step.approval_mode || 'ANY',

        approvers: step.approvers.map(
          approver => ({
            approver_type:
              approver.approver_type,

            approver_id:
              Number(approver.approver_id),

            approver_scope:
              approver.approver_scope || 'GLOBAL',

            branch_ids:
              approver.approver_scope === 'SELECTED_BRANCHES'
                ? [...new Set(approver.branch_ids.map(branchId => Number(branchId)))].filter(branchId => branchId > 0)
                : [],

            /*
             * Hanya kirim yang lengkap. Baris kosong diabaikan agar admin bisa
             * menambah baris tanpa langsung wajib mengisinya.
             */
            special_approvers: (approver.special_approvers || [])
              .filter(special => special.special_document_type_id && special.approver_id)
              .map(special => ({
                special_document_type_id: Number(special.special_document_type_id),
                approver_type: special.approver_type,
                approver_id: Number(special.approver_id),
              })),
          }),
        ),
      }),
    ),
  }
}

const submit = async (): Promise<void> => {
  if (submitLoading.value)
    return

  if (!validateForm())
    return

  const confirm = await showConfirmAlert({
    title: 'Yakin Simpan?',
    text: 'Approval flow baru akan disimpan.',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  submitLoading.value = true

    try {
        showLoadingAlert('Menyimpan Approval Flow', 'Mohon tunggu sebentar')

        await axios.post(APPROVAL_FLOW_STORE_ENDPOINT, buildPayload(), {
            headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            },
        })

        closeAlert()

        await router.replace({
            path: APPROVAL_FLOW_INDEX_PATH,
            query: {
            document_type: form.document_type,
            success: 'created',
            },
        })
    } catch (error: any) {
        closeAlert()

        const err = error as AxiosErrorShape

        showErrorToast({
            title: 'Gagal',
            text: getApiErrorMessage(err, 'Gagal menyimpan approval flow.'),
        })
    } finally {
        submitLoading.value = false
    }
}

const goBack = async (): Promise<void> => {
  const hasInput = Boolean(
    form.name
      || form.description
      || form.min_amount
      || form.max_amount
      || form.steps.some(step => {
        return step.label || step.approvers.some(approver => approver.approver_id)
      }),
  )

  if (hasInput) {
    const confirm = await showConfirmAlert({
      title: 'Keluar dari halaman?',
      text: 'Data yang belum disimpan akan hilang.',
      confirmButtonText: 'Ya, keluar',
      cancelButtonText: 'Batal',
    })

    if (!confirm.isConfirmed)
      return
  }

  await router.push({
    path: APPROVAL_FLOW_INDEX_PATH,
    query: {
      document_type: form.document_type,
    },
  })
}

const formatAmount = (value: number | string | null | undefined): string => {
  if (value === null || value === undefined)
    return ''

  return `Rp ${formatNumberWithoutRp(Number(value || 0))}`
}
</script>

<template>
  <section>
    <VCard class="mb-6 rounded-lg approval-create-header">
      <VCardText>
        <div class="d-flex flex-column flex-md-row justify-space-between gap-4">
          <div>
            <div class="text-overline text-primary font-weight-bold mb-1 text-none">
              Master Approval
            </div>

            <h2 class="text-h5 font-weight-bold mb-1">
              {{ pageTitle }}
            </h2>

            <p class="text-body-2 text-medium-emphasis mb-0">
              Buat template approval berdasarkan jenis dokumen, batas nilai, dan approver.
            </p>
          </div>

          <div class="d-flex align-center gap-3">
            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-arrow-left"
              :disabled="submitLoading"
              class="text-none"
              @click="goBack"
            >
              Kembali
            </VBtn>

            <VBtn
              color="primary"
              prepend-icon="tabler-device-floppy"
              :loading="submitLoading"
              class="text-none"
              @click="submit"
            >
              Simpan Flow
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VRow>
      <VCol
        cols="12"
        lg="8"
      >
        <VCard class="mb-6 rounded-lg">
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="primary"
                variant="tonal"
                rounded
              >
                <VIcon icon="tabler-settings" />
              </VAvatar>
            </template>

            <VCardTitle>Informasi Approval Flow</VCardTitle>
            <VCardSubtitle>
              Tentukan nama flow, status, dan range nominal.
            </VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText>
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  :model-value="documentTypeLabel"
                  label="Jenis Dokumen"
                  density="comfortable"
                  readonly
                  disabled
                  prepend-inner-icon="tabler-file-description"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  :model-value="form.permission_module_name"
                  label="Module"
                  density="comfortable"
                  prepend-inner-icon="tabler-apps"
                  readonly
                  :loading="isLoadingPermissionModule"
                  :disabled="
                    submitLoading
                      || isLoadingPermissionModule
                  "
                  :error="
                    isSubmitted
                      && !form.permission_module_id
                  "
                  :error-messages="
                    isSubmitted
                      && !form.permission_module_id
                        ? ['Module belum tersedia']
                        : []
                  "
                />
              </VCol>

              <VCol cols="12">
                <VTextField
                  v-model="form.name"
                  label="Nama Approval Flow *"
                  :placeholder="`Contoh: Approval ${form.document_type} Cabang GA 1 Juta sampai 10 Juta`"
                  density="comfortable"
                  :disabled="submitLoading"
                  :error="isSubmitted && !form.name"
                  :error-messages="isSubmitted && !form.name ? ['Nama approval flow wajib diisi'] : []"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                v-model.number="form.min_amount"
                label="Minimal Nilai"
                placeholder="0"
                type="number"
                min="0"
                density="comfortable"
                prefix="Rp"
                :disabled="submitLoading"
                :error="Boolean(minAmountErrorMessage)"
                :error-messages="minAmountErrorMessage ? [minAmountErrorMessage] : []"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                v-model.number="form.max_amount"
                label="Maksimal Nilai"
                placeholder="Kosongkan untuk tanpa batas"
                type="number"
                min="0"
                density="comfortable"
                prefix="Rp"
                :disabled="submitLoading"
                :error="Boolean(maxAmountErrorMessage)"
                :error-messages="maxAmountErrorMessage ? [maxAmountErrorMessage] : []"
                />
              </VCol>

              <VCol cols="12">
                <VTextarea
                  v-model="form.description"
                  label="Deskripsi"
                  placeholder="Keterangan approval flow"
                  rows="3"
                  auto-grow
                  density="comfortable"
                  :disabled="submitLoading"
                />
              </VCol>

              <VCol cols="12">
                <VSwitch
                  v-model="form.is_active"
                  color="success"
                  inset
                  :label="form.is_active ? 'Flow Aktif' : 'Flow Nonaktif'"
                  :disabled="submitLoading"
                />
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <VCard
          v-if="usesAreaMatrix"
          class="mb-6 rounded-lg"
        >
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="warning"
                variant="tonal"
                rounded
              >
                <VIcon icon="tabler-filter-cog" />
              </VAvatar>
            </template>

            <!--
              Judul mengikuti jenis dokumen aktif: kartu ini dipakai bersama
              oleh seluruh dokumen bermatriks area (PR dan FPU).
            -->
            <VCardTitle>Kondisi Matrix {{ form.document_type }}</VCardTitle>
            <VCardSubtitle>
              Rule tambahan khusus {{ documentTypeLabel }}.
            </VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText>
            <VAlert
              color="info"
              variant="tonal"
              class="mb-5"
            >
              Flow {{ form.document_type }} ditentukan dari area, cabang, department dan nominal.
            </VAlert>

            <VRow>
                <VCol
                    cols="12"
                    md="6"
                >
                    <VSelect
                    v-model="form.area_type"
                    label="Area Type *"
                    :items="areaTypeOptions"
                    density="comfortable"
                    :disabled="submitLoading"
                    :error="isSubmitted && usesAreaMatrix && !form.area_type"
                    :error-messages="isSubmitted && usesAreaMatrix && !form.area_type ? ['Area type wajib dipilih'] : []"
                    />
                </VCol>

                <VCol
                    cols="12"
                    md="6"
                >
                    <!--
                      Satu flow dapat mencakup beberapa department sekaligus,
                      seperti baris "GA - IT - LOG" pada matriks.
                    -->
                    <VAutocomplete
                    v-model="form.department_ids"
                    label="Department *"
                    :items="departmentOptions"
                    item-title="title"
                    item-value="id"
                    :multiple="autocompleteMultiple"
                    chips
                    closable-chips
                    clearable
                    density="comfortable"
                    :loading="isLoadingDepartment"
                    :disabled="submitLoading || form.all_departments"
                    :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                    :error="isSubmitted && usesAreaMatrix && !form.all_departments && !form.department_ids.length"
                    :error-messages="isSubmitted && usesAreaMatrix && !form.all_departments && !form.department_ids.length ? ['Department wajib dipilih'] : []"
                    no-data-text="Department tidak ditemukan"
                    :placeholder="form.all_departments ? 'Berlaku untuk semua department' : 'Pilih satu atau beberapa department'"
                    />

                    <VSwitch
                    v-model="form.all_departments"
                    color="primary"
                    density="compact"
                    hide-details
                    class="mt-1"
                    label="Semua Divisi"
                    :disabled="submitLoading"
                    />
                </VCol>

                <!--
                  Keterangan transaksi hanya relevan bagi jenis dokumen yang
                  memakainya (FPU). Penentunya master permission module, bukan
                  pemeriksaan jenis dokumen di sini.
                -->
                <VCol
                    v-if="usesTransactionCategory"
                    cols="12"
                >
                    <VAutocomplete
                    v-model="form.transaction_category_ids"
                    label="Keterangan Transaksi *"
                    :items="transactionCategoryOptions"
                    item-title="title"
                    item-value="id"
                    :multiple="autocompleteMultiple"
                    chips
                    closable-chips
                    clearable
                    density="comfortable"
                    :loading="isLoadingTransactionCategory"
                    :disabled="submitLoading || form.all_transaction_categories"
                    :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                    :error="isSubmitted && usesTransactionCategory && !form.all_transaction_categories && !form.transaction_category_ids.length"
                    :error-messages="isSubmitted && usesTransactionCategory && !form.all_transaction_categories && !form.transaction_category_ids.length ? ['Keterangan transaksi wajib dipilih'] : []"
                    no-data-text="Keterangan transaksi tidak ditemukan"
                    :placeholder="form.all_transaction_categories ? 'Berlaku untuk semua keterangan transaksi' : 'Pilih satu atau beberapa keterangan transaksi'"
                    />

                    <VSwitch
                    v-model="form.all_transaction_categories"
                    color="primary"
                    density="compact"
                    hide-details
                    class="mt-1"
                    label="Semua Keterangan Transaksi"
                    :disabled="submitLoading"
                    />
                </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <VCard class="rounded-lg">
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="success"
                variant="tonal"
                rounded
              >
                <VIcon icon="tabler-route" />
              </VAvatar>
            </template>

            <VCardTitle>Step Approval</VCardTitle>
            <VCardSubtitle>
              Tambahkan urutan approval dan approver pada setiap step.
            </VCardSubtitle>

            <template #append>
              <VBtn
                color="primary"
                variant="tonal"
                prepend-icon="tabler-plus"
                class="text-none"
                :disabled="submitLoading"
                @click="addStep"
              >
                Tambah Step
              </VBtn>
            </template>
          </VCardItem>

          <VDivider />

          <VCardText>
            <div class="approval-step-list">
              <VCard
                v-for="(step, stepIndex) in form.steps"
                :key="step.local_key"
                variant="outlined"
                class="approval-step-card"
              >
                <VCardText>
                  <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-5">
                    <div class="d-flex align-center gap-3">
                      <VAvatar
                        color="primary"
                        variant="flat"
                        size="36"
                      >
                        <span class="text-caption font-weight-bold">
                          {{ stepIndex + 1 }}
                        </span>
                      </VAvatar>

                      <div>
                        <div class="text-subtitle-1 font-weight-bold">
                          Step {{ stepIndex + 1 }}
                        </div>
                        <div class="text-caption text-medium-emphasis">
                          {{ step.approval_mode === 'ALL' ? 'Semua approver wajib approve' : 'Salah satu approver cukup approve' }}
                        </div>
                      </div>
                    </div>

                    <VBtn
                      icon
                      variant="tonal"
                      color="error"
                      size="small"
                      :disabled="submitLoading || form.steps.length <= 1"
                      @click="removeStep(stepIndex)"
                    >
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </div>

                  <VRow class="align-start">
                    <VCol
                      cols="12"
                      md="6"
                    >
                      <VTextField
                        v-model="step.label"
                        label="Label Step"
                        placeholder="Contoh: Adm / ADH"
                        hide-details="auto"
                      />
                    </VCol>

                    <VCol
                      cols="12"
                      md="6"
                    >
                      <VSelect
                        v-model="step.approval_mode"
                        :items="approvalModeOptions"
                        item-title="title"
                        item-value="value"
                        label="Approval Mode"
                        hint="ANY: salah satu cukup, ALL: semua harus approve"
                        persistent-hint
                      />
                    </VCol>
                  </VRow>

                  <VDivider class="my-5" />

                  <div class="d-flex flex-column flex-sm-row align-sm-center justify-space-between gap-3 mb-4">
                    <div>
                      <div class="font-weight-bold">
                        Approver
                      </div>
                      <div class="text-caption text-medium-emphasis">
                        Tambahkan satu atau lebih approver pada step ini.
                      </div>
                    </div>

                    <VBtn
                      color="primary"
                      variant="tonal"
                      size="small"
                      prepend-icon="tabler-user-plus"
                      class="text-none"
                      :disabled="submitLoading"
                      @click="addApprover(stepIndex)"
                    >
                      Tambah Approver
                    </VBtn>
                  </div>

                  <div class="approver-list">
                    <div
                      v-for="(approver, approverIndex) in step.approvers"
                      :key="`${step.local_key}-approver-${approverIndex}`"
                      class="approver-row"
                    >
                      <VRow>
                        <VCol
                        cols="12"
                        md="3"
                        >
                        <VSelect
                            v-model="approver.approver_type"
                            label="Tipe Approver *"
                            :items="approverTypeOptions"
                            item-title="title"
                            item-value="value"
                            :return-object="false"
                            density="comfortable"
                            :disabled="submitLoading"
                            @update:model-value="() => onApproverTypeChange(stepIndex, approverIndex)"
                            />
                        </VCol>

                        <VCol
                          cols="12"
                          md="8"
                        >
                          <VAutocomplete
                            v-model="approver.approver_id"
                            label="Approver *"
                            :items="getAvailableApproverItems(stepIndex, approverIndex, approver.approver_type)"
                            item-title="title"
                            item-value="id"
                            :return-object="false"
                            clearable
                            density="comfortable"
                            :loading="approver.approver_type === 'ROLE' ? isLoadingRole : isLoadingUser"
                            :disabled="submitLoading"
                            :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                            :no-data-text="approver.approver_type === 'ROLE' ? 'Role tidak ditemukan / sudah dipilih' : 'User tidak ditemukan / sudah dipilih'"
                            :placeholder="approver.approver_type === 'ROLE' ? 'Pilih role approver' : 'Pilih user approver'"
                            @update:model-value="value => {
                                approver.approver_id = normalizeApproverId(value)
                            }"
                            />
                        </VCol>

                        <VCol
                          cols="12"
                          md="1"
                          class="d-flex align-center justify-end"
                        >
                          <VBtn
                            icon
                            variant="tonal"
                            color="error"
                            size="small"
                            :disabled="submitLoading || step.approvers.length <= 1"
                            @click="removeApprover(stepIndex, approverIndex)"
                          >
                            <VIcon icon="tabler-x" />
                          </VBtn>
                        </VCol>
                      </VRow>

                      <VRow class="mt-1">
                        <VCol
                          cols="12"
                          md="4"
                        >
                          <VSelect
                            v-model="approver.approver_scope"
                            label="Approver Scope *"
                            :items="approverScopeOptions"
                            item-title="title"
                            item-value="value"
                            :return-object="false"
                            density="comfortable"
                            :disabled="submitLoading"
                            hint="Tentukan cakupan cabang untuk approver ini"
                            persistent-hint
                            @update:model-value="() => onApproverScopeChange(stepIndex, approverIndex)"
                          />
                        </VCol>

                        <VCol
                          v-if="approver.approver_scope === 'SELECTED_BRANCHES'"
                          cols="12"
                          md="8"
                        >
                          <VAutocomplete
                            v-model="approver.branch_ids"
                            label="Cabang yang Ditangani *"
                            :items="branchOptions"
                            item-title="title"
                            item-value="id"
                            :return-object="false"
                            :multiple="autocompleteMultiple"
                            chips
                            closable-chips
                            clearable
                            density="comfortable"
                            :loading="isLoadingBranch"
                            :disabled="submitLoading"
                            :menu-props="{ location: 'bottom', offset: 8, maxHeight: 320 }"
                            :error="
                              isSubmitted
                                && approver.approver_scope === 'SELECTED_BRANCHES'
                                && approver.branch_ids.length === 0
                            "
                            :error-messages="
                              isSubmitted
                                && approver.approver_scope === 'SELECTED_BRANCHES'
                                && approver.branch_ids.length === 0
                                  ? ['Minimal pilih 1 cabang']
                                  : []
                            "
                            no-data-text="Cabang tidak ditemukan"
                            placeholder="Pilih satu atau lebih cabang"
                          />
                        </VCol>

                        <VCol
                          v-else
                          cols="12"
                          md="8"
                        >
                          <VAlert
                            color="info"
                            variant="tonal"
                            density="compact"
                            class="h-100"
                          >
                            <template v-if="approver.approver_scope === 'SAME_BRANCH'">
                              Approver hanya berlaku saat cabang akun sama dengan cabang dokumen.
                            </template>
                            <template v-else>
                              Approver berlaku tanpa pembatasan cabang.
                            </template>
                          </VAlert>
                        </VCol>
                        <!--
                          Approver pengganti untuk Tipe Dokumen Khusus.
                          Hanya muncul bila master tipe dokumen memang terisi,
                          sehingga tampilan lama tidak berubah bila fitur ini
                          tidak dipakai.
                        -->
                        <VCol
                          v-if="specialDocumentTypeOptions.length"
                          cols="12"
                        >
                          <VDivider class="mb-3" />

                          <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-2">
                            <div class="d-flex align-center gap-2">
                              <VIcon
                                icon="tabler-replace"
                                size="18"
                                class="text-medium-emphasis"
                              />

                              <div>
                                <div class="text-body-2 font-weight-medium">
                                  Approver Pengganti (Dokumen Khusus)
                                </div>

                                <div class="text-caption text-medium-emphasis">
                                  Opsional. Bila dokumen bertipe khusus, approver di atas digantikan oleh yang diatur di sini.
                                </div>
                              </div>
                            </div>

                            <VBtn
                              size="small"
                              variant="tonal"
                              color="primary"
                              prepend-icon="tabler-plus"
                              class="text-none"
                              :disabled="submitLoading"
                              @click="addSpecialApprover(stepIndex, approverIndex)"
                            >
                              Tambah Pengganti
                            </VBtn>
                          </div>

                          <VAlert
                            v-if="!approver.special_approvers.length"
                            color="secondary"
                            variant="tonal"
                            density="compact"
                          >
                            Belum ada pengganti. Approver di atas berlaku untuk semua dokumen.
                          </VAlert>

                          <VRow
                            v-for="(special, specialIndex) in approver.special_approvers"
                            :key="`special-${stepIndex}-${approverIndex}-${specialIndex}`"
                            class="align-center"
                          >
                            <VCol cols="12" md="4">
                              <VAutocomplete
                                v-model="special.special_document_type_id"
                                label="Tipe Dokumen *"
                                :items="specialDocumentTypeOptions"
                                item-title="name"
                                item-value="id"
                                density="comfortable"
                                :disabled="submitLoading"
                                :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                                placeholder="Pilih tipe dokumen"
                              />
                            </VCol>

                            <VCol cols="12" md="3">
                              <VSelect
                                v-model="special.approver_type"
                                label="Tipe Approver *"
                                :items="approverTypeOptions"
                                item-title="title"
                                item-value="value"
                                density="comfortable"
                                :disabled="submitLoading"
                                @update:model-value="special.approver_id = null"
                              />
                            </VCol>

                            <VCol cols="12" md="4">
                              <VAutocomplete
                                v-model="special.approver_id"
                                label="Approver Pengganti *"
                                :items="special.approver_type === 'ROLE' ? roleOptions : userOptions"
                                item-title="name"
                                item-value="id"
                                :return-object="false"
                                density="comfortable"
                                :disabled="submitLoading"
                                :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                                placeholder="Pilih approver pengganti"
                              />
                            </VCol>

                            <VCol cols="12" md="1" class="d-flex justify-end">
                              <VBtn
                                icon
                                size="small"
                                variant="text"
                                color="error"
                                :disabled="submitLoading"
                                @click="removeSpecialApprover(stepIndex, approverIndex, specialIndex)"
                              >
                                <VIcon icon="tabler-trash" />
                              </VBtn>
                            </VCol>
                          </VRow>
                        </VCol>

                      </VRow>
                    </div>
                  </div>
                </VCardText>
              </VCard>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        lg="4"
      >
        <VCard class="rounded-lg approval-summary-card">
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="info"
                variant="tonal"
                rounded
              >
                <VIcon icon="tabler-eye" />
              </VAvatar>
            </template>

            <VCardTitle>Preview Flow</VCardTitle>
            <VCardSubtitle>
              Ringkasan data
            </VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText>
            <div class="summary-list">
              <div class="summary-item">
                <div class="summary-label">
                  Jenis Dokumen
                </div>
                <div class="summary-value">
                  <VChip
                    color="primary"
                    variant="tonal"
                    size="small"
                  >
                    {{ documentTypeLabel }}
                  </VChip>
                </div>
              </div>

              <div class="summary-item">
                <div class="summary-label">
                  Status
                </div>
                <div class="summary-value">
                  <VChip
                    :color="form.is_active ? 'success' : 'secondary'"
                    variant="tonal"
                    size="small"
                  >
                    {{ form.is_active ? 'Aktif' : 'Nonaktif' }}
                  </VChip>
                </div>
              </div>

              <div class="summary-item">
                <div class="summary-label">
                  Range Nilai
                </div>
                <div class="summary-value font-weight-bold">
                  {{ amountRangePreview }}
                </div>
              </div>

              <template v-if="usesAreaMatrix">
                <div class="summary-item">
                    <div class="summary-label">
                    Area
                    </div>
                    <div class="summary-value">
                    {{ form.area_type || '-' }}
                    </div>
                </div>

                <div class="summary-item">
                    <div class="summary-label">
                    Department
                    </div>
                    <div class="summary-value">
                    {{ selectedDepartmentName }}
                    </div>
                </div>
            </template>

              <div
                v-if="usesTransactionCategory"
                class="summary-item"
              >
                <div class="summary-label">
                  Keterangan Transaksi
                </div>
                <div class="summary-value">
                  {{ selectedTransactionCategoryName }}
                </div>
              </div>

              <div class="summary-item">
                <div class="summary-label">
                  Total Step
                </div>
                <div class="summary-value">
                  <VChip
                    color="warning"
                    variant="tonal"
                    size="small"
                  >
                    {{ totalStep }} Step
                  </VChip>
                </div>
              </div>

              <div class="summary-item">
                <div class="summary-label">
                  Total Approver
                </div>
                <div class="summary-value">
                  <VChip
                    color="info"
                    variant="tonal"
                    size="small"
                  >
                    {{ totalApprover }} Approver
                  </VChip>
                </div>
              </div>
            </div>

            <VDivider class="my-5" />

            <div class="font-weight-bold mb-3">
              Alur Approval
            </div>

            <div class="preview-steps">
              <div
                v-for="(step, stepIndex) in form.steps"
                :key="`preview-${step.local_key}`"
                class="preview-step"
              >
                <div class="preview-step-number">
                  {{ stepIndex + 1 }}
                </div>

                <div class="preview-step-content">
                  <div class="font-weight-bold">
                    {{ step.label || `Step ${stepIndex + 1}` }}
                  </div>

                  <div class="text-caption text-medium-emphasis mb-2">
                    Mode: {{ step.approval_mode }}
                  </div>

                  <div class="d-flex flex-wrap gap-2">
                    <VChip
                      v-for="(approver, approverIndex) in step.approvers"
                      :key="`preview-approver-${stepIndex}-${approverIndex}`"
                      size="x-small"
                      variant="tonal"
                      color="primary"
                    >
                      {{ approver.approver_type }} · {{ approver.approver_scope }}
                    </VChip>
                  </div>
                </div>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </section>
</template>

<style scoped>
.approval-create-header {
  border-radius: 16px;
}

.approval-step-list {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.approval-step-card {
  border-radius: 16px;
}

.approver-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.approver-row {
  padding: 16px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  background: rgba(var(--v-theme-background), 0.45);
}

.approval-summary-card {
  position: sticky;
  inset-block-start: 90px;
}

.summary-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.summary-item {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.summary-label {
  color: rgba(var(--v-theme-on-surface), 0.62);
  font-size: 13px;
}

.summary-value {
  font-size: 13px;
  text-align: end;
}

.preview-steps {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.preview-step {
  display: flex;
  gap: 12px;
}

.preview-step-number {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 30px;
  border-radius: 50%;
  background: rgb(var(--v-theme-primary));
  color: white;
  font-size: 13px;
  font-weight: 700;
  block-size: 30px;
  inline-size: 30px;
}

.preview-step-content {
  flex: 1;
  padding-block-end: 14px;
  border-block-end: 1px dashed rgba(var(--v-border-color), var(--v-border-opacity));
}

.preview-step:last-child .preview-step-content {
  border-block-end: none;
  padding-block-end: 0;
}

@media (max-width: 960px) {
  .approval-summary-card {
    position: static;
  }

  .summary-item {
    align-items: flex-start;
  }
}
</style>