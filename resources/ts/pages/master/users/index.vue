<script setup lang="ts">
import axios from '@axios'
import { computed, nextTick, onMounted, ref, watch } from 'vue'

import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'

import { getApiErrorMessage } from '@/utils/apiHelper'

interface AxiosErrorShape {
  response?: {
    data?: {
      message?: string
      debug?: string
      errors?: Record<string, string[]>
    }
  }
}

interface RoleOption {
  id: number
  nama: string
  name?: string
  title?: string
}

interface CabangOption {
  id: number
  value?: number
  nama: string
  name?: string
  title?: string
}

interface DepartmentOption {
  id: number
  value?: number
  nama: string
  name?: string
  title?: string
  kode?: string | null
}

interface UserRole {
  id: number
  nama: string
  name?: string
}

interface UserCabang {
  id: number
  nama?: string
  nama_cabang?: string
  inisial_cabang?: string | null
}

interface UserDepartment {
  id: number
  nama?: string
  name?: string
  kode?: string | null
}

interface UserRow {
  id: number
  name: string
  username?: string | null
  email: string

  cabang_id?: number | null
  cabang?: UserCabang | null

  departemen_id?: number | null
  departemen?: UserDepartment | null

  roles?: UserRole[]
  role_id?: number | null
  role_ids?: number[]
  role_names?: string[]

  is_active: boolean

  signature_path?: string | null
  signature_uploaded_at?: string | null

  last_login_at?: string | null
  last_logout_at?: string | null

  created_at?: string | null
  updated_at?: string | null
}

interface UserForm {
  id?: number
  name: string
  username: string
  email: string
  is_active: boolean
  cabang_id: number | null
  departemen_id: number | null
  role_id: number | null
  password: string
  password_confirmation: string
}

interface UserAccessAssignmentRow {
  id: number
  user_id?: number | null
  branch_id: number
  department_id: number
  branch_name?: string | null
  branch_code?: string | null
  department_name?: string | null
  department_code?: string | null
  is_primary: boolean
  is_active: boolean
}

interface UserAccessAssignmentForm {
  id?: number | null
  branch_id: number | null
  department_id: number | null
  is_primary: boolean
  is_active: boolean
}

interface UserSummary {
  total_user: number
  total_active_user: number
  total_inactive_user: number
}

const userSummary = ref<UserSummary>({
  total_user: 0,
  total_active_user: 0,
  total_inactive_user: 0,
})

const isLoading = ref(false)
const isActionLoading = ref(false)
const isDialogOpen = ref(false)
const isEdit = ref(false)
const isSubmitting = ref(false)
const isSubmitted = ref(false)

const isAccessDialogOpen = ref(false)
const isLoadingAccessAssignments = ref(false)
const isSavingAccessAssignment = ref(false)
const isTogglingAccessAssignment = ref(false)

const keyword = ref('')
const selectedStatus = ref<'all' | 'active' | 'inactive'>('all')
const selectedRoleId = ref<number | null>(null)

const page = ref(1)
const perPage = ref(10)
const totalItems = ref(0)
const lastPage = ref(1)

const users = ref<UserRow[]>([])
const roleOptions = ref<RoleOption[]>([])
const cabangOptions = ref<CabangOption[]>([])
const departmentOptions = ref<DepartmentOption[]>([])

const selectedAccessUser = ref<UserRow | null>(null)
const accessAssignments = ref<UserAccessAssignmentRow[]>([])
const accessFormErrors = ref<Record<string, string>>({})

const formErrors = ref<Record<string, string>>({})

const form = ref<UserForm>({
  name: '',
  username: '',
  email: '',
  is_active: true,
  cabang_id: null,
  departemen_id: null,
  role_id: null,
  password: '',
  password_confirmation: '',
})

const accessForm = ref<UserAccessAssignmentForm>({
  id: null,
  branch_id: null,
  department_id: null,
  is_primary: false,
  is_active: true,
})

const statusOptions = [
  {
    title: 'Aktif',
    value: 'active',
  },
  {
    title: 'Nonaktif',
    value: 'inactive',
  },
  {
    title: 'Semua',
    value: 'all',
  },
]

const totalActiveUser = computed(() => {
  return userSummary.value.total_active_user
})

const totalInactiveUser = computed(() => {
  return userSummary.value.total_inactive_user
})

const hasFilter = computed(() => {
  return Boolean(keyword.value || selectedRoleId.value || selectedStatus.value !== 'active')
})

const paginationText = computed(() => {
  const firstIndex = totalItems.value ? ((page.value - 1) * perPage.value) + 1 : 0
  const lastIndex = users.value.length + ((page.value - 1) * perPage.value)

  return `${firstIndex}-${lastIndex} dari ${totalItems.value}`
})

const normalizeDropdownItems = (payload: any): any[] => {
  const rawItems = payload?.data?.data
    ?? payload?.data
    ?? payload
    ?? []

  if (!Array.isArray(rawItems))
    return []

  return rawItems.map((item: any) => {
    const id = Number(item.id ?? item.value)
    const kode = item.kode ?? item.code ?? null

    const name = String(
      item.title
        ?? item.label
        ?? item.nama
        ?? item.nama_cabang
        ?? item.name
        ?? '-',
    )

    let title = name

    if (kode && !String(title).includes(String(kode)))
      title = `${kode} - ${name}`

    return {
      id,
      value: id,
      nama: name,
      name,
      title,
      kode,
    }
  })
}

const fetchOptions = async (): Promise<void> => {
  try {
    const [roleResponse, cabangResponse, departmentResponse] = await Promise.all([
      axios.get('/master/roles', {
        params: { per_page: 9999 },
        headers: { Accept: 'application/json' },
      }),
      axios.get('/master/cabang/dropdown-select', {
        headers: { Accept: 'application/json' },
      }),
      axios.get('/master/department/dropdown-select', {
        headers: { Accept: 'application/json' },
      }),
    ])

    roleOptions.value = normalizeDropdownItems(roleResponse.data) as RoleOption[]
    cabangOptions.value = normalizeDropdownItems(cabangResponse.data) as CabangOption[]
    departmentOptions.value = normalizeDropdownItems(departmentResponse.data) as DepartmentOption[]
  } catch (error: any) {
    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal Memuat Dropdown',
      text: getApiErrorMessage(err, 'Gagal memuat data role, cabang, atau department.'),
    })
  }
}

const buildParams = (): Record<string, any> => {
  const params: Record<string, any> = {
    page: page.value,
    per_page: perPage.value,
  }

  if (keyword.value)
    params.search = keyword.value

  if (selectedRoleId.value)
    params.role_id = selectedRoleId.value

  if (selectedStatus.value !== 'all') {
    params.is_active = selectedStatus.value === 'active'
      ? 'true'
      : 'false'
  }

  return params
}

const assignResponseData = (responseData: any): void => {
  const paginator = responseData?.data?.data
    ? responseData.data
    : responseData?.data
      ? responseData
      : responseData

  if (Array.isArray(paginator)) {
    users.value = paginator
    totalItems.value = paginator.length
    lastPage.value = 1

    return
  }

  if (Array.isArray(paginator?.data)) {
    users.value = paginator.data
    page.value = Number(paginator.current_page ?? page.value)
    perPage.value = Number(paginator.per_page ?? perPage.value)
    totalItems.value = Number(paginator.total ?? paginator.data.length)
    lastPage.value = Number(paginator.last_page ?? 1)

    return
  }

  users.value = []
  totalItems.value = 0
  lastPage.value = 1
}

const fetchUsers = async (): Promise<void> => {
  isLoading.value = true

  try {
    const response = await axios.get('/master/users', {
      params: buildParams(),
      headers: {
        Accept: 'application/json',
      },
    })

    assignResponseData(response.data)

    userSummary.value = {
      total_user: Number(response.data.summary?.total_user ?? 0),
      total_active_user: Number(
        response.data.summary?.total_active_user ?? 0,
      ),
      total_inactive_user: Number(
        response.data.summary?.total_inactive_user ?? 0,
      ),
    }
  } catch (error: any) {
    const err = error as AxiosErrorShape

    users.value = []
    totalItems.value = 0
    lastPage.value = 1

    showErrorToast({
      title: 'Gagal Memuat Data',
      text: getApiErrorMessage(err, 'Gagal memuat data user.'),
    })
  } finally {
    isLoading.value = false
  }
}

const reloadData = async (): Promise<void> => {
  page.value = 1
  await fetchUsers()
}

const resetFilter = async (): Promise<void> => {
  keyword.value = ''
  selectedStatus.value = 'active'
  selectedRoleId.value = null
  page.value = 1

  await fetchUsers()
}

const getCabangText = (user: UserRow): string => {
  return user.cabang?.nama
    || user.cabang?.nama_cabang
    || user.cabang?.inisial_cabang
    || '-'
}

const getDepartmentText = (user: UserRow): string => {
  const kode = user.departemen?.kode || ''
  const nama = user.departemen?.nama || user.departemen?.name || ''

  if (kode && nama)
    return `${kode} - ${nama}`

  return nama || kode || '-'
}

const getCabangOptionTitle = (branchId: number | string | null | undefined): string => {
  const id = Number(branchId || 0)

  if (!id)
    return '-'

  const option = cabangOptions.value.find(item => Number(item.id) === id)

  return option?.title || option?.nama || `Cabang #${id}`
}

const getDepartmentOptionTitle = (departmentId: number | string | null | undefined): string => {
  const id = Number(departmentId || 0)

  if (!id)
    return '-'

  const option = departmentOptions.value.find(item => Number(item.id) === id)

  return option?.title || option?.nama || `Department #${id}`
}

const getAssignmentBranchTitle = (assignment: UserAccessAssignmentRow): string => {
  const code = String(assignment.branch_code || '').trim()
  const name = String(assignment.branch_name || '').trim()

  if (code && name)
    return `${code} - ${name}`

  return name || code || getCabangOptionTitle(assignment.branch_id)
}

const getAssignmentDepartmentTitle = (assignment: UserAccessAssignmentRow): string => {
  const code = String(assignment.department_code || '').trim()
  const name = String(assignment.department_name || '').trim()

  if (code && name)
    return `${code} - ${name}`

  return name || code || getDepartmentOptionTitle(assignment.department_id)
}

const getStatusColor = (user: UserRow): string => {
  return user.is_active ? 'success' : 'secondary'
}

const getStatusText = (user: UserRow): string => {
  return user.is_active ? 'Aktif' : 'Nonaktif'
}

const getPrimaryRoleId = (user: UserRow): number | null => {
  if (user.role_id)
    return Number(user.role_id)

  if (Array.isArray(user.role_ids) && user.role_ids.length)
    return Number(user.role_ids[0])

  if (Array.isArray(user.roles) && user.roles.length)
    return Number(user.roles[0].id)

  return null
}

const getUserRoleIdsPayload = (): number[] => {
  return form.value.role_id ? [Number(form.value.role_id)] : []
}

const resetForm = (): void => {
  isSubmitted.value = false
  formErrors.value = {}

  form.value = {
    name: '',
    username: '',
    email: '',
    is_active: true,
    cabang_id: null,
    departemen_id: null,
    role_id: null,
    password: '',
    password_confirmation: '',
  }
}

const openCreate = (): void => {
  resetForm()
  isEdit.value = false
  isDialogOpen.value = true
}

const openEdit = (user: UserRow): void => {
  isSubmitted.value = false
  formErrors.value = {}

  form.value = {
    id: user.id,
    name: user.name || '',
    username: user.username || '',
    email: user.email || '',
    is_active: Boolean(user.is_active),
    cabang_id: user.cabang?.id ?? user.cabang_id ?? null,
    departemen_id: user.departemen?.id ?? user.departemen_id ?? null,
    role_id: getPrimaryRoleId(user),
    password: '',
    password_confirmation: '',
  }

  isEdit.value = true
  isDialogOpen.value = true
}

const closeDialog = (): void => {
  if (isSubmitting.value)
    return

  isDialogOpen.value = false
  resetForm()
}

const validateForm = (): boolean => {
  isSubmitted.value = true
  formErrors.value = {}

  if (!form.value.name.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Nama user wajib diisi.',
    })

    return false
  }

  if (!form.value.email.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Email wajib diisi.',
    })

    return false
  }

  if (!form.value.role_id) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Role user wajib dipilih.',
    })

    return false
  }

  if (!isEdit.value && !form.value.password) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Password wajib diisi untuk user baru.',
    })

    return false
  }

  if (form.value.password && form.value.password !== form.value.password_confirmation) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Konfirmasi password tidak sesuai.',
    })

    return false
  }

  return true
}

const buildPayload = (): Record<string, any> => {
  const payload: Record<string, any> = {
    name: form.value.name.trim(),
    username: form.value.username?.trim() || null,
    email: form.value.email.trim(),
    is_active: Boolean(form.value.is_active),
    cabang_id: form.value.cabang_id,
    departemen_id: form.value.departemen_id,

    /*
    |--------------------------------------------------------------------------
    | Backend existing masih menerima role_ids.
    | UI sekarang hanya single role, jadi dikirim sebagai array 1 item.
    |--------------------------------------------------------------------------
    */
    role_ids: getUserRoleIdsPayload(),
  }

  if (!isEdit.value || form.value.password) {
    payload.password = form.value.password
    payload.password_confirmation = form.value.password_confirmation
  }

  return payload
}

const submitForm = async (): Promise<void> => {
  if (isSubmitting.value)
    return

  if (!validateForm())
    return

  /*
  |--------------------------------------------------------------------------
  | Tutup dialog form dulu agar tidak menimpa SweetAlert confirm
  |--------------------------------------------------------------------------
  */
  isDialogOpen.value = false

  const confirm = await showConfirmAlert({
    title: isEdit.value ? 'Simpan Perubahan?' : 'Tambah User?',
    text: isEdit.value
      ? 'Data user akan diperbarui.'
      : 'User baru akan ditambahkan.',
    confirmButtonText: isEdit.value ? 'Ya, simpan' : 'Ya, tambah',
    cancelButtonText: 'Batal',
  })

  /*
  |--------------------------------------------------------------------------
  | Kalau user batal, buka lagi dialog form dengan data yang sama
  |--------------------------------------------------------------------------
  */
  if (!confirm.isConfirmed) {
    isDialogOpen.value = true
    return
  }

  isSubmitting.value = true

  try {
    showLoadingAlert('Menyimpan User', 'Mohon tunggu sebentar')

    if (isEdit.value && form.value.id) {
      await axios.put(`/master/users/${form.value.id}`, buildPayload(), {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      })
    } else {
      await axios.post('/master/users', buildPayload(), {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      })
    }

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: isEdit.value
        ? 'User berhasil diperbarui.'
        : 'User berhasil ditambahkan.',
    })

    isDialogOpen.value = false
    resetForm()
    await fetchUsers()
  } catch (error: any) {
    closeAlert()

    /*
    |--------------------------------------------------------------------------
    | Kalau gagal simpan, buka lagi dialog supaya user bisa koreksi
    |--------------------------------------------------------------------------
    */
    isDialogOpen.value = true

    const err = error as AxiosErrorShape

    if (error?.response?.status === 422 && error?.response?.data?.errors) {
      const errors = error.response.data.errors as Record<string, string[]>

      Object.keys(errors).forEach(key => {
        formErrors.value[key] = errors[key][0]
      })

      showErrorToast({
        title: 'Validasi Gagal',
        text: 'Silakan cek kembali input user.',
      })

      return
    }

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal menyimpan user.'),
    })
  } finally {
    isSubmitting.value = false
  }
}

const buildToggleActivePayload = (user: UserRow, isActive: boolean): Record<string, any> => {
  const roleId = getPrimaryRoleId(user)

  return {
    name: user.name,
    username: user.username || null,
    email: user.email,
    is_active: isActive,
    cabang_id: user.cabang?.id ?? user.cabang_id ?? null,
    departemen_id: user.departemen?.id ?? user.departemen_id ?? null,
    role_ids: roleId ? [roleId] : [],
  }
}

const toggleUserActive = async (user: UserRow): Promise<void> => {
  if (isActionLoading.value)
    return

  const nextStatus = !user.is_active

  const confirm = await showConfirmAlert({
    title: nextStatus ? 'Aktifkan User?' : 'Nonaktifkan User?',
    text: nextStatus
      ? `User "${user.name}" akan diaktifkan kembali.`
      : `User "${user.name}" akan dinonaktifkan dan tidak dapat digunakan untuk approval.`,
    confirmButtonText: nextStatus ? 'Ya, aktifkan' : 'Ya, nonaktifkan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  isActionLoading.value = true

  try {
    showLoadingAlert(
      nextStatus ? 'Mengaktifkan User' : 'Menonaktifkan User',
      'Mohon tunggu sebentar',
    )

    await axios.put(`/master/users/${user.id}`, buildToggleActivePayload(user, nextStatus), {
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: nextStatus
        ? 'User berhasil diaktifkan.'
        : 'User berhasil dinonaktifkan.',
    })

    await fetchUsers()
  } catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        nextStatus
          ? 'Gagal mengaktifkan user.'
          : 'Gagal menonaktifkan user.',
      ),
    })
  } finally {
    isActionLoading.value = false
  }
}

const normalizeAccessAssignmentItems = (payload: any): UserAccessAssignmentRow[] => {
  const rawItems = payload?.data?.data
    ?? payload?.data
    ?? payload
    ?? []

  if (!Array.isArray(rawItems))
    return []

  return rawItems.map((item: any) => {
    const branchId = Number(
      item.branch_id
        ?? item.cabang_id
        ?? item.branch?.id
        ?? item.cabang?.id
        ?? 0,
    )

    const departmentId = Number(
      item.department_id
        ?? item.departemen_id
        ?? item.department?.id
        ?? item.departemen?.id
        ?? 0,
    )

    return {
      id: Number(item.id),
      user_id: item.user_id ? Number(item.user_id) : selectedAccessUser.value?.id ?? null,
      branch_id: branchId,
      department_id: departmentId,
      branch_name: item.branch_name
        ?? item.branch?.nama_cabang
        ?? item.branch?.nama
        ?? item.cabang?.nama_cabang
        ?? item.cabang?.nama
        ?? null,
      branch_code: item.branch_code
        ?? item.branch?.inisial_cabang
        ?? item.cabang?.inisial_cabang
        ?? null,
      department_name: item.department_name
        ?? item.department?.nama
        ?? item.department?.name
        ?? item.departemen?.nama
        ?? item.departemen?.name
        ?? null,
      department_code: item.department_code
        ?? item.department?.kode
        ?? item.departemen?.kode
        ?? null,
      is_primary: Boolean(item.is_primary),
      is_active: item.is_active === undefined || item.is_active === null
        ? true
        : Boolean(item.is_active),
    }
  })
}

const resetAccessForm = (): void => {
  accessFormErrors.value = {}

  accessForm.value = {
    id: null,
    branch_id: null,
    department_id: null,
    is_primary: false,
    is_active: true,
  }
}

const validateAccessForm = (): boolean => {
  accessFormErrors.value = {}

  if (!accessForm.value.branch_id) {
    accessFormErrors.value.branch_id = 'Cabang wajib dipilih.'
  }

  if (!accessForm.value.department_id) {
    accessFormErrors.value.department_id = 'Department wajib dipilih.'
  }

  if (accessForm.value.branch_id && accessForm.value.department_id) {
    const branchId = Number(accessForm.value.branch_id)
    const departmentId = Number(accessForm.value.department_id)
    const currentId = Number(accessForm.value.id || 0)

    const duplicate = accessAssignments.value.some(assignment => {
      return Number(assignment.id) !== currentId
        && Number(assignment.branch_id) === branchId
        && Number(assignment.department_id) === departmentId
    })

    if (duplicate) {
      accessFormErrors.value.department_id = 'Kombinasi cabang dan department sudah ada.'
    }
  }

  if (Object.keys(accessFormErrors.value).length) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: Object.values(accessFormErrors.value)[0] || 'Data akses user belum valid.',
    })

    return false
  }

  return true
}

const buildAccessAssignmentPayload = (): Record<string, any> => {
  return {
    branch_id: accessForm.value.branch_id,
    department_id: accessForm.value.department_id,
    is_primary: Boolean(accessForm.value.is_primary),
    is_active: Boolean(accessForm.value.is_active),
  }
}

const extractAccessAssignments = (responseData: any): UserAccessAssignmentRow[] => {
  const rawData = responseData?.data?.assignments
    ?? responseData?.assignments
    ?? responseData?.data?.data?.assignments
    ?? responseData?.data?.data
    ?? responseData?.data
    ?? responseData
    ?? []

  const assignments = Array.isArray(rawData)
    ? rawData
    : Array.isArray(rawData?.assignments)
      ? rawData.assignments
      : []

  return normalizeAccessAssignmentItems(assignments)
}

const fetchAccessAssignments = async (): Promise<void> => {
  if (!selectedAccessUser.value?.id)
    return

  isLoadingAccessAssignments.value = true

  try {
    const response = await axios.get(
      `/master/users/${selectedAccessUser.value.id}/access-assignments`,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    accessAssignments.value = extractAccessAssignments(response.data)
  } catch (error: any) {
    accessAssignments.value = []

    showErrorToast({
      title: 'Gagal Memuat Access Assignment',
      text: getApiErrorMessage(error, 'Gagal memuat data access assignment user.'),
    })
  } finally {
    isLoadingAccessAssignments.value = false
  }
}

const openAccessDialog = async (user: UserRow): Promise<void> => {
  selectedAccessUser.value = user
  accessAssignments.value = []
  resetAccessForm()
  isAccessDialogOpen.value = true

  await fetchAccessAssignments()
}

const closeAccessDialog = (): void => {
  if (isSavingAccessAssignment.value || isTogglingAccessAssignment.value)
    return

  isAccessDialogOpen.value = false
  selectedAccessUser.value = null
  accessAssignments.value = []
  resetAccessForm()
}

const editAccessAssignment = (assignment: UserAccessAssignmentRow): void => {
  accessFormErrors.value = {}

  accessForm.value = {
    id: Number(assignment.id),
    branch_id: Number(assignment.branch_id),
    department_id: Number(assignment.department_id),
    is_primary: Boolean(assignment.is_primary),
    is_active: Boolean(assignment.is_active),
  }
}

const submitAccessAssignment = async (): Promise<void> => {
  if (isSavingAccessAssignment.value || !selectedAccessUser.value?.id)
    return

  if (!validateAccessForm())
    return

  const isEditAccess = Boolean(accessForm.value.id)

  isSavingAccessAssignment.value = true

  try {
    showLoadingAlert(
      isEditAccess ? 'Menyimpan Akses User' : 'Menambah Akses User',
      'Mohon tunggu sebentar',
    )

    const response = isEditAccess && accessForm.value.id
      ? await axios.put(
          `/master/users/${selectedAccessUser.value.id}/access-assignments/${accessForm.value.id}`,
          buildAccessAssignmentPayload(),
          {
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
            },
          },
        )
      : await axios.post(
          `/master/users/${selectedAccessUser.value.id}/access-assignments`,
          buildAccessAssignmentPayload(),
          {
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
            },
          },
        )

    const responseAssignments = extractAccessAssignments(response.data)

    if (responseAssignments.length)
      accessAssignments.value = responseAssignments
    else
      await fetchAccessAssignments()

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: isEditAccess
        ? 'User access assignment berhasil diperbarui.'
        : 'User access assignment berhasil ditambahkan.',
    })

    resetAccessForm()
    await fetchUsers()
  } catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    if (error?.response?.status === 422 && error?.response?.data?.errors) {
      const errors = error.response.data.errors as Record<string, string[]>

      Object.keys(errors).forEach(key => {
        accessFormErrors.value[key] = errors[key][0]
      })
    }

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal menyimpan user access assignment.'),
    })
  } finally {
    isSavingAccessAssignment.value = false
  }
}

const toggleAccessAssignmentActive = async (assignment: UserAccessAssignmentRow): Promise<void> => {
  if (isTogglingAccessAssignment.value || !selectedAccessUser.value?.id)
    return

  const nextStatus = !assignment.is_active

  /*
  |--------------------------------------------------------------------------
  | Tutup sementara dialog access assignment
  |--------------------------------------------------------------------------
  | Supaya SweetAlert confirm tidak muncul di belakang VDialog.
  |--------------------------------------------------------------------------
  */
  const shouldReopenDialog = isAccessDialogOpen.value

  isAccessDialogOpen.value = false
  await nextTick()

  const confirm = await showConfirmAlert({
    title: nextStatus ? 'Aktifkan Akses?' : 'Nonaktifkan Akses?',
    text: nextStatus
      ? 'Akses cabang dan department ini akan diaktifkan kembali.'
      : 'Akses cabang dan department ini akan dinonaktifkan.',
    confirmButtonText: nextStatus ? 'Ya, aktifkan' : 'Ya, nonaktifkan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) {
    if (shouldReopenDialog)
      isAccessDialogOpen.value = true

    return
  }

  isTogglingAccessAssignment.value = true

  try {
    showLoadingAlert(
      nextStatus ? 'Mengaktifkan Akses' : 'Menonaktifkan Akses',
      'Mohon tunggu sebentar',
    )

    const response = await axios.put(
      `/master/users/${selectedAccessUser.value.id}/access-assignments/${assignment.id}`,
      {
        branch_id: assignment.branch_id,
        department_id: assignment.department_id,
        is_primary: assignment.is_primary,
        is_active: nextStatus,
      },
      {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    closeAlert()

    const assignments = extractAccessAssignments(response.data)

    if (assignments.length) {
      accessAssignments.value = normalizeAccessAssignmentItems(assignments)
    } else {
      await fetchAccessAssignments()
    }

    if (accessForm.value.id === assignment.id)
      resetAccessForm()

    await fetchUsers()

    showSuccessToast({
      title: 'Berhasil',
      text: nextStatus
        ? 'Akses berhasil diaktifkan.'
        : 'Akses berhasil dinonaktifkan.',
    })
  } catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        nextStatus
          ? 'Gagal mengaktifkan akses.'
          : 'Gagal menonaktifkan akses.',
      ),
    })
  } finally {
    isTogglingAccessAssignment.value = false

    if (shouldReopenDialog)
      isAccessDialogOpen.value = true
  }
}

const goToPreviousPage = async (): Promise<void> => {
  if (page.value <= 1)
    return

  page.value -= 1
  await fetchUsers()
}

const goToNextPage = async (): Promise<void> => {
  if (page.value >= lastPage.value)
    return

  page.value += 1
  await fetchUsers()
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null

watch(keyword, () => {
  if (searchTimeout)
    clearTimeout(searchTimeout)

  searchTimeout = setTimeout(async () => {
    page.value = 1
    await fetchUsers()
  }, 500)
})

watch([selectedRoleId, selectedStatus], async () => {
  page.value = 1
  await fetchUsers()
})

onMounted(async () => {
  await Promise.all([
    fetchOptions(),
    fetchUsers(),
  ])
})
</script>

<template>
  <section>
    <VCard class="mb-6 rounded-lg">
      <VCardText>
        <div class="d-flex flex-column flex-md-row justify-space-between gap-4">
          <div>
            <div class="text-overline text-primary font-weight-bold mb-1 text-none">
              Master User
            </div>

            <h2 class="text-h5 font-weight-bold mb-1">
              Kelola Akun User
            </h2>
          </div>

          <div class="d-flex align-center gap-3">
            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-refresh"
              :loading="isLoading"
              class="text-none"
              @click="fetchUsers"
            >
              Refresh
            </VBtn>

            <VBtn
              color="primary"
              prepend-icon="tabler-user-plus"
              class="text-none"
              @click="openCreate"
            >
              Tambah User
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VRow class="mb-6">
      <VCol cols="12" md="4">
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  Total User
                </div>
                <div class="text-h5 font-weight-bold">
                  {{ totalItems }}
                </div>
              </div>

              <VAvatar color="primary" variant="tonal" rounded>
                <VIcon icon="tabler-users" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  User Aktif
                </div>
                <div class="text-h5 font-weight-bold text-success">
                  {{ totalActiveUser }}
                </div>
              </div>

              <VAvatar color="success" variant="tonal" rounded>
                <VIcon icon="tabler-user-check" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard class="rounded-lg">
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  User Nonaktif
                </div>
                <div class="text-h5 font-weight-bold text-secondary">
                  {{ totalInactiveUser }}
                </div>
              </div>

              <VAvatar color="secondary" variant="tonal" rounded>
                <VIcon icon="tabler-user-off" />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard class="mb-6 rounded-lg">
      <VCardText>
        <VRow>
          <VCol cols="12" sm="6" lg="3">
            <VTextField
              v-model="keyword"
              label="Cari user"
              placeholder="Cari nama, username, atau email..."
              prepend-inner-icon="tabler-search"
              clearable
              density="comfortable"
            />
          </VCol>

          <VCol cols="12" sm="6" lg="5">
            <VAutocomplete
              v-model="selectedRoleId"
              label="Role"
              :items="roleOptions"
              item-title="title"
              item-value="id"
              :return-object="false"
              clearable
              density="comfortable"
              no-data-text="Role tidak ditemukan"
              placeholder="Pilih role"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 300,
                eager: true,
              }"
            />
          </VCol>

          <VCol cols="12" sm="6" lg="2">
            <VSelect
              v-model="selectedStatus"
              label="Status"
              :items="statusOptions"
              item-title="title"
              item-value="value"
              :return-object="false"
              density="comfortable"
              :menu-props="{
                location: 'bottom',
                offset: 8,
                maxHeight: 250,
              }"
            />
          </VCol>

          <VCol cols="12" sm="6" lg="2" class="d-flex align-center">
            <VBtn
              block
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-filter-off"
              :disabled="!hasFilter"
              class="text-none"
              @click="resetFilter"
            >
              Reset
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard class="rounded-lg">
      <VCardText>
        <div class="d-flex flex-column flex-md-row align-md-center justify-space-between gap-3 mb-5">
          <div>
            <h3 class="text-h6 font-weight-bold mb-1">
              Daftar User
            </h3>

            <p class="text-body-2 text-medium-emphasis mb-0">
              Setiap user memiliki satu role utama untuk kebutuhan approval.
            </p>
          </div>

          <VChip color="primary" variant="tonal">
            {{ totalItems }} User
          </VChip>
        </div>

        <div v-if="isLoading" class="py-4">
          <VSkeletonLoader
            v-for="n in 4"
            :key="n"
            type="list-item-avatar-two-line"
            class="mb-3"
          />
        </div>

        <div v-else-if="!users.length" class="py-10 text-center">
          <VAvatar color="secondary" variant="tonal" size="64" class="mb-4">
            <VIcon icon="tabler-user-off" size="34" />
          </VAvatar>

          <div class="text-h6 font-weight-semibold mb-1">
            User belum tersedia
          </div>

          <div class="text-body-2 text-medium-emphasis mb-5">
            Silakan tambahkan user untuk kebutuhan login dan approval.
          </div>

          <VBtn
            v-permission="'auth_user.create'"
            color="primary"
            prepend-icon="tabler-user-plus"
            class="text-none"
            @click="openCreate"
          >
            Tambah User
          </VBtn>
        </div>

        <div
          v-else
          class="user-table-wrapper"
        >
          <VTable class="user-table">
            <thead>
              <tr>
                <th>User</th>
                <th>Role</th>
                <th>Cabang</th>
                <th>Department</th>
                <th>Status</th>
                <th>Last Login</th>
                <th class="text-center" style="width: 190px;">
                  Actions
                </th>
              </tr>
            </thead>

            <tbody>
              <tr v-for="user in users" :key="user.id">
                <td>
                  <div class="user-cell">
                    <VAvatar color="primary" variant="tonal" size="40" class="user-avatar">
                      <VIcon icon="tabler-user" />
                    </VAvatar>

                    <div class="user-info">
                      <div class="font-weight-bold user-name">
                        {{ user.name }}
                      </div>

                      <div class="text-caption text-medium-emphasis user-email">
                        {{ user.email }}
                      </div>

                      <div v-if="user.username" class="text-caption text-medium-emphasis user-username">
                        Username: {{ user.username }}
                      </div>
                    </div>
                  </div>
                </td>

                <td>
                  <div class="role-chip-list">
                    <VChip
                      v-for="role in user.role_names || []"
                      :key="role"
                      size="x-small"
                      color="primary"
                      variant="tonal"
                      class="role-chip"
                    >
                      {{ role }}
                    </VChip>

                    <span v-if="!user.role_names?.length" class="text-medium-emphasis">
                      -
                    </span>
                  </div>
                </td>

                <td class="text-medium-emphasis">
                  <div class="table-text-wrap">
                    {{ getCabangText(user) }}
                  </div>
                </td>

                <td class="text-medium-emphasis">
                  <div class="table-text-wrap">
                    {{ getDepartmentText(user) }}
                  </div>
                </td>

                <td>
                  <VChip
                    :color="getStatusColor(user)"
                    size="small"
                    variant="tonal"
                  >
                    {{ getStatusText(user) }}
                  </VChip>
                </td>

                <td class="text-medium-emphasis">
                  <div class="table-text-wrap last-login-text">
                    {{ user.last_login_at || '-' }}
                  </div>
                </td>

                <td class="text-center">
                  <div class="d-flex justify-center gap-2">
                    <VBtn
                      v-permission="'auth_user.update'"
                      icon
                      size="small"
                      color="primary"
                      variant="tonal"
                      @click="openEdit(user)"
                    >
                      <VIcon icon="tabler-edit" />
                      <VTooltip activator="parent" location="top">
                        Edit User
                      </VTooltip>
                    </VBtn>

                    <VBtn
                      v-permission="'auth_user.update'"
                      icon
                      size="small"
                      color="info"
                      variant="tonal"
                      @click="openAccessDialog(user)"
                    >
                      <VIcon icon="tabler-building-community" />
                      <VTooltip activator="parent" location="top">
                        Kelola Akses Cabang/Department
                      </VTooltip>
                    </VBtn>

                    <VBtn
                      icon
                      size="small"
                      :color="user.is_active ? 'warning' : 'success'"
                      variant="tonal"
                      :loading="isActionLoading"
                      @click="toggleUserActive(user)"
                    >
                      <VIcon :icon="user.is_active ? 'tabler-user-off' : 'tabler-user-check'" />
                      <VTooltip activator="parent" location="top">
                        {{ user.is_active ? 'Nonaktifkan User' : 'Aktifkan User' }}
                      </VTooltip>
                    </VBtn>
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>

        <VDivider v-if="users.length" class="my-5" />

        <div
          v-if="users.length"
          class="d-flex flex-column flex-md-row justify-space-between align-md-center gap-3"
        >
          <div class="text-body-2 text-medium-emphasis">
            {{ paginationText }}
          </div>

          <div class="d-flex align-center gap-2">
            <VSelect
              v-model="perPage"
              :items="[5, 10, 25, 50]"
              density="compact"
              hide-details
              style="max-width: 100px;"
              @update:model-value="reloadData"
            />

            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-chevron-left"
              :disabled="page <= 1"
              class="text-none"
              @click="goToPreviousPage"
            >
              Prev
            </VBtn>

            <VBtn
              variant="tonal"
              color="secondary"
              append-icon="tabler-chevron-right"
              :disabled="page >= lastPage"
              class="text-none"
              @click="goToNextPage"
            >
              Next
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VDialog
      v-model="isDialogOpen"
      max-width="720"
      persistent
    >
      <VCard class="rounded-lg">
        <VCardItem>
          <template #prepend>
            <VAvatar color="primary" variant="tonal" rounded>
              <VIcon :icon="isEdit ? 'tabler-user-edit' : 'tabler-user-plus'" />
            </VAvatar>
          </template>

          <VCardTitle>
            {{ isEdit ? 'Edit User' : 'Tambah User' }}
          </VCardTitle>

          <VCardSubtitle>
            {{ isEdit ? 'Perbarui data akun user.' : 'Tambahkan akun user baru.' }}
          </VCardSubtitle>

          <template #append>
            <VBtn
              icon
              variant="text"
              color="secondary"
              :disabled="isSubmitting"
              @click="closeDialog"
            >
              <VIcon icon="tabler-x" />
            </VBtn>
          </template>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VRow>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.name"
                label="Nama User *"
                placeholder="Nama lengkap"
                density="comfortable"
                :disabled="isSubmitting"
                :error="isSubmitted && !form.name"
                :error-messages="formErrors.name || (isSubmitted && !form.name ? 'Nama user wajib diisi' : '')"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.username"
                label="Username"
                placeholder="Username login"
                density="comfortable"
                :disabled="isSubmitting"
                :error-messages="formErrors.username"
              />
            </VCol>

            <VCol cols="12">
              <VTextField
                v-model="form.email"
                label="Email *"
                placeholder="user@company.com"
                type="email"
                density="comfortable"
                :disabled="isSubmitting"
                :error="isSubmitted && !form.email"
                :error-messages="formErrors.email || (isSubmitted && !form.email ? 'Email wajib diisi' : '')"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VAutocomplete
                v-model="form.cabang_id"
                label="Cabang"
                :items="cabangOptions"
                item-title="title"
                item-value="id"
                :return-object="false"
                clearable
                density="comfortable"
                :disabled="isSubmitting"
                no-data-text="Cabang tidak ditemukan"
                :menu-props="{
                  location: 'bottom',
                  offset: 8,
                  maxHeight: 300,
                }"
                :error-messages="formErrors.cabang_id"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VAutocomplete
                v-model="form.departemen_id"
                label="Department"
                :items="departmentOptions"
                item-title="title"
                item-value="id"
                :return-object="false"
                clearable
                density="comfortable"
                :disabled="isSubmitting"
                no-data-text="Department tidak ditemukan"
                :menu-props="{
                  location: 'bottom',
                  offset: 8,
                  maxHeight: 300,
                }"
                :error-messages="formErrors.departemen_id"
              />
            </VCol>

            <VCol cols="12">
              <VAutocomplete
                v-model="form.role_id"
                label="Role User *"
                :items="roleOptions"
                item-title="title"
                item-value="id"
                :return-object="false"
                clearable
                density="comfortable"
                :disabled="isSubmitting"
                no-data-text="Role tidak ditemukan"
                placeholder="Pilih role user"
                :menu-props="{
                  location: 'bottom',
                  offset: 8,
                  maxHeight: 300,
                }"
                :error="isSubmitted && !form.role_id"
                :error-messages="formErrors.role_ids || formErrors.role_id || (isSubmitted && !form.role_id ? 'Role user wajib dipilih' : '')"
              />
            </VCol>

            <VCol cols="12">
              <VSwitch
                v-model="form.is_active"
                color="success"
                inset
                :label="form.is_active ? 'User Aktif' : 'User Nonaktif'"
                :disabled="isSubmitting"
              />
            </VCol>

            <VCol v-if="isEdit" cols="12">
              <VAlert color="info" variant="tonal">
                Kosongkan password jika tidak ingin mengubah password user.
              </VAlert>
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.password"
                label="Password"
                placeholder="Password"
                type="password"
                density="comfortable"
                :disabled="isSubmitting"
                :error="isSubmitted && !isEdit && !form.password"
                :error-messages="formErrors.password || (isSubmitted && !isEdit && !form.password ? 'Password wajib diisi' : '')"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.password_confirmation"
                label="Konfirmasi Password"
                placeholder="Konfirmasi password"
                type="password"
                density="comfortable"
                :disabled="isSubmitting"
                :error-messages="formErrors.password_confirmation"
              />
            </VCol>
          </VRow>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-5 justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            :disabled="isSubmitting"
            class="text-none"
            @click="closeDialog"
          >
            Batal
          </VBtn>

          <VBtn
            color="primary"
            prepend-icon="tabler-device-floppy"
            :loading="isSubmitting"
            class="text-none"
            @click="submitForm"
          >
            {{ isEdit ? 'Simpan Perubahan' : 'Simpan User' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="isAccessDialogOpen"
      max-width="980"
      persistent
    >
      <VCard class="rounded-lg">
        <VCardItem>
          <template #prepend>
            <VAvatar color="info" variant="tonal" rounded>
              <VIcon icon="tabler-building-community" />
            </VAvatar>
          </template>

          <VCardTitle>
            Kelola Access Assignment
          </VCardTitle>

          <VCardSubtitle>
            Atur akses cabang dan department tambahan untuk user.
          </VCardSubtitle>

          <template #append>
            <VBtn
              icon
              variant="text"
              color="secondary"
              :disabled="isSavingAccessAssignment || isTogglingAccessAssignment"
              @click="closeAccessDialog"
            >
              <VIcon icon="tabler-x" />
            </VBtn>
          </template>
        </VCardItem>

        <VDivider />

        <VCardText>
          <VAlert
            color="info"
            variant="tonal"
            class="mb-5"
          >
            Access assignment dipakai untuk pilihan cabang/department pada PR dan scope data yang bisa diakses user. Role user tetap mengikuti role utama di master user.
          </VAlert>

          <VCard
            v-if="selectedAccessUser"
            flat
            class="access-user-summary mb-5"
          >
            <VCardText>
              <div class="d-flex align-start gap-3">
                <VAvatar color="primary" variant="tonal" size="42">
                  <VIcon icon="tabler-user" />
                </VAvatar>

                <div class="flex-grow-1">
                  <div class="font-weight-bold">
                    {{ selectedAccessUser.name }}
                  </div>

                  <div class="text-caption text-medium-emphasis">
                    {{ selectedAccessUser.email }}
                  </div>

                  <div class="d-flex flex-wrap gap-2 mt-3">
                    <VChip size="small" color="primary" variant="tonal">
                      Master Cabang: {{ getCabangText(selectedAccessUser) }}
                    </VChip>

                    <VChip size="small" color="primary" variant="tonal">
                      Master Department: {{ getDepartmentText(selectedAccessUser) }}
                    </VChip>
                  </div>
                </div>
              </div>
            </VCardText>
          </VCard>

          <VCard flat class="access-form-card mb-5">
            <VCardText>
              <div class="text-subtitle-1 font-weight-bold mb-4">
                {{ accessForm.id ? 'Edit Access Assignment' : 'Tambah Access Assignment' }}
              </div>

              <VRow>
                <VCol cols="12" md="4">
                  <VAutocomplete
                    v-model="accessForm.branch_id"
                    label="Cabang *"
                    :items="cabangOptions"
                    item-title="title"
                    item-value="id"
                    :return-object="false"
                    clearable
                    density="comfortable"
                    :disabled="isSavingAccessAssignment"
                    no-data-text="Cabang tidak ditemukan"
                    placeholder="Pilih cabang"
                    :menu-props="{
                      location: 'bottom',
                      offset: 8,
                      maxHeight: 300,
                    }"
                    :error-messages="accessFormErrors.branch_id"
                  />
                </VCol>

                <VCol cols="12" md="4">
                  <VAutocomplete
                    v-model="accessForm.department_id"
                    label="Department *"
                    :items="departmentOptions"
                    item-title="title"
                    item-value="id"
                    :return-object="false"
                    clearable
                    density="comfortable"
                    :disabled="isSavingAccessAssignment"
                    no-data-text="Department tidak ditemukan"
                    placeholder="Pilih department"
                    :menu-props="{
                      location: 'bottom',
                      offset: 8,
                      maxHeight: 300,
                    }"
                    :error-messages="accessFormErrors.department_id"
                  />
                </VCol>

                <VCol cols="12" md="4" class="d-flex flex-column justify-center gap-2">
                  <!-- <VCheckbox
                    v-model="accessForm.is_primary"
                    label="Jadikan akses utama"
                    color="primary"
                    density="compact"
                    hide-details
                    :disabled="isSavingAccessAssignment"
                  /> -->

                  <VSwitch
                    v-model="accessForm.is_active"
                    color="success"
                    density="compact"
                    inset
                    hide-details
                    :label="accessForm.is_active ? 'Aktif' : 'Nonaktif'"
                    :disabled="isSavingAccessAssignment"
                  />
                </VCol>

                <VCol cols="12" class="d-flex justify-end gap-2">
                  <VBtn
                    v-if="accessForm.id"
                    variant="tonal"
                    color="secondary"
                    class="text-none"
                    :disabled="isSavingAccessAssignment"
                    @click="resetAccessForm"
                  >
                    Batal Edit
                  </VBtn>

                  <VBtn
                    color="primary"
                    prepend-icon="tabler-device-floppy"
                    class="text-none"
                    :loading="isSavingAccessAssignment"
                    @click="submitAccessAssignment"
                  >
                    {{ accessForm.id ? 'Update Akses' : 'Tambah Akses' }}
                  </VBtn>
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-3">
            <div>
              <div class="text-subtitle-1 font-weight-bold">
                Daftar Access Assignment
              </div>
              <div class="text-caption text-medium-emphasis">
                Kombinasi cabang dan department yang dapat digunakan user.
              </div>
            </div>

            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-refresh"
              class="text-none"
              :loading="isLoadingAccessAssignments"
              @click="fetchAccessAssignments"
            >
              Refresh
            </VBtn>
          </div>

          <div v-if="isLoadingAccessAssignments" class="py-3">
            <VSkeletonLoader
              v-for="n in 3"
              :key="n"
              type="list-item-two-line"
              class="mb-2"
            />
          </div>

          <VAlert
            v-else-if="!accessAssignments.length"
            type="info"
            variant="tonal"
          >
            User belum memiliki access assignment. Tambahkan minimal satu akses sesuai master cabang dan department user.
          </VAlert>

          <div
            v-else
            class="access-table-wrapper"
          >
            <VTable class="access-table">
              <thead>
                <tr>
                  <th>Cabang</th>
                  <th>Department</th>
                  <th>Status</th>
                  <th>Utama</th>
                  <th class="text-center" style="width: 150px;">
                    Actions
                  </th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="assignment in accessAssignments"
                  :key="assignment.id"
                >
                  <td>
                    <div class="table-text-wrap">
                      {{ getAssignmentBranchTitle(assignment) }}
                    </div>
                  </td>

                  <td>
                    <div class="table-text-wrap">
                      {{ getAssignmentDepartmentTitle(assignment) }}
                    </div>
                  </td>

                  <td>
                    <VChip
                      :color="assignment.is_active ? 'success' : 'secondary'"
                      size="small"
                      variant="tonal"
                    >
                      {{ assignment.is_active ? 'Aktif' : 'Nonaktif' }}
                    </VChip>
                  </td>

                  <td>
                    <VChip
                      v-if="assignment.is_primary"
                      color="primary"
                      size="small"
                      variant="tonal"
                    >
                      Utama
                    </VChip>

                    <span v-else class="text-medium-emphasis">
                      -
                    </span>
                  </td>

                  <td class="text-center">
                    <div class="d-flex justify-center gap-2">
                      <VBtn
                        icon
                        size="small"
                        color="primary"
                        variant="tonal"
                        :disabled="isSavingAccessAssignment || isTogglingAccessAssignment"
                        @click="editAccessAssignment(assignment)"
                      >
                        <VIcon icon="tabler-edit" />
                        <VTooltip activator="parent" location="top">
                          Edit Akses
                        </VTooltip>
                      </VBtn>

                      <VBtn
                        icon
                        size="small"
                        :color="assignment.is_active ? 'warning' : 'success'"
                        variant="tonal"
                        :loading="isTogglingAccessAssignment"
                        @click="toggleAccessAssignmentActive(assignment)"
                      >
                        <VIcon :icon="assignment.is_active ? 'tabler-circle-off' : 'tabler-circle-check'" />
                        <VTooltip activator="parent" location="top">
                          {{ assignment.is_active ? 'Nonaktifkan Akses' : 'Aktifkan Akses' }}
                        </VTooltip>
                      </VBtn>
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-5 justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            :disabled="isSavingAccessAssignment || isTogglingAccessAssignment"
            @click="closeAccessDialog"
          >
            Tutup
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>

.access-user-summary,
.access-form-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  background: rgba(var(--v-theme-background), 0.45);
}

.access-table-wrapper {
  overflow-x: auto;
  overflow-y: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
}

.access-table-wrapper :deep(.v-table__wrapper) {
  overflow-x: visible !important;
  overflow-y: visible !important;
}

.access-table {
  min-width: 760px;
}

.access-table :deep(table) {
  inline-size: 100%;
  min-width: 760px;
}

.access-table th {
  color: rgba(var(--v-theme-on-surface), 0.72);
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
  text-transform: uppercase;
  background: rgba(var(--v-theme-background), 0.55);
}

.access-table td {
  padding-block: 12px;
  vertical-align: middle;
}

.user-table-wrapper {
  overflow-x: auto;
  overflow-y: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
}

/* Matikan scrollbar bawaan VTable supaya tidak double */
.user-table-wrapper :deep(.v-table__wrapper) {
  overflow-x: visible !important;
  overflow-y: visible !important;
}

.user-table {
  min-width: 1160px;
}

/* Pastikan table tidak bikin scroll internal lagi */
.user-table :deep(table) {
  inline-size: 100%;
  min-width: 1160px;
}

.user-table th {
  color: rgba(var(--v-theme-on-surface), 0.72);
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
  text-transform: uppercase;
  background: rgba(var(--v-theme-background), 0.55);
}

.user-table td {
  padding-block: 14px;
  vertical-align: middle;
}

.user-cell {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 250px;
}

.user-avatar {
  flex: 0 0 auto;
}

.user-info {
  min-width: 0;
}

.user-name {
  max-width: 210px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-email,
.user-username {
  max-width: 230px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.role-chip-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  min-width: 180px;
  max-width: 260px;
}

.role-chip {
  max-width: 220px;
}

.role-chip :deep(.v-chip__content) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.table-text-wrap {
  min-width: 120px;
  max-width: 180px;
  line-height: 1.35;
  white-space: normal;
  word-break: break-word;
}

.last-login-text {
  min-width: 130px;
}

@media (max-width: 960px) {
  .user-table-wrapper {
    margin-inline: -4px;
  }

  .user-table,
  .user-table :deep(table) {
    min-width: 1060px;
  }

  .user-name {
    max-width: 180px;
  }

  .user-email,
  .user-username {
    max-width: 200px;
  }

  .role-chip-list {
    max-width: 220px;
  }
}

@media (max-width: 600px) {
  .user-table-wrapper {
    border-radius: 12px;
  }

  .user-table,
  .user-table :deep(table) {
    min-width: 980px;
  }
}
</style>