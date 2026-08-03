<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import axiosIns from '@/plugins/axios'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'

import { getApiErrorMessage } from '@/utils/apiHelper'
import { usePermissionStore } from '@/stores/permission'

interface AxiosErrorShape {
  response?: {
    status?: number
    data?: {
      message?: string
      debug?: string
      errors?: Record<string, string[]>
    }
  }
}

interface DashboardModuleGroupOption {
  id: number
  code: string
  name: string
  icon: string | null
  sort_order: number
  is_active: boolean
  modules_count?: number
}

interface DashboardModuleGroupForm {
  id: number | null
  code: string
  name: string
  icon: string
  sort_order: number
  is_active: boolean
}

interface PermissionOption {
  id: number
  code: string
  name: string
}

interface DashboardModuleItem {
  id: number
  dashboard_module_group_id: number
  code: string
  title: string
  short_title: string | null
  description: string | null
  icon: string | null
  color: string
  route_path: string | null
  permission_name: string | null
  features: string[] | null
  is_active: boolean
  is_available: boolean
  sort_order: number
  group?: {
    id: number
    code: string
    name: string
    icon: string | null
  } | null
}

interface DashboardModuleForm {
  id: number | null
  dashboard_module_group_id: number | null
  code: string
  title: string
  short_title: string
  description: string
  icon: string
  color: string
  route_path: string
  permission_name: string | null
  features: string[]
  is_active: boolean
  is_available: boolean
  sort_order: number
}

const router = useRouter()
const permissionStore = usePermissionStore()

const viewPermissionCode = 'dashboard_module.view'
const createPermissionCode = 'dashboard_module.create'
const updatePermissionCode = 'dashboard_module.update'
const deletePermissionCode = 'dashboard_module.delete'

const canView = computed(() => permissionStore.can(viewPermissionCode))
const canCreate = computed(() => permissionStore.can(createPermissionCode))
const canUpdate = computed(() => permissionStore.can(updatePermissionCode))
const canDelete = computed(() => permissionStore.can(deletePermissionCode))

const activeTab = ref<'modules' | 'groups'>('modules')

const isActionLoading = ref(false)
const isLoading = ref(false)
const isSaving = ref(false)
const isDialogVisible = ref(false)
const isEditMode = ref(false)

const isGroupActionLoading = ref(false)
const isGroupSaving = ref(false)
const isGroupDialogVisible = ref(false)
const isGroupEditMode = ref(false)

const modules = ref<DashboardModuleItem[]>([])
const groupOptions = ref<DashboardModuleGroupOption[]>([])
const permissionOptions = ref<PermissionOption[]>([])

const groupForm = ref<DashboardModuleGroupForm>({
  id: null,
  code: '',
  name: '',
  icon: '',
  sort_order: 0,
  is_active: true,
})

const search = ref('')
const groupFilter = ref<'ALL' | number>('ALL')
const statusFilter = ref<'ALL' | 'ACTIVE' | 'INACTIVE'>('ALL')
const availabilityFilter = ref<'ALL' | 'AVAILABLE' | 'COMING_SOON'>('ALL')
const page = ref(1)
const itemsPerPage = ref(10)

const form = ref<DashboardModuleForm>({
  id: null,
  dashboard_module_group_id: null,
  code: '',
  title: '',
  short_title: '',
  description: '',
  icon: '',
  color: 'primary',
  route_path: '',
  permission_name: null,
  features: [],
  is_active: true,
  is_available: false,
  sort_order: 0,
})

const itemsPerPageOptions = [
  { title: '10', value: 10 },
  { title: '25', value: 25 },
  { title: '50', value: 50 },
  { title: '100', value: 100 },
]

const statusOptions = [
  { title: 'Semua Status', value: 'ALL' },
  { title: 'Active', value: 'ACTIVE' },
  { title: 'Inactive', value: 'INACTIVE' },
]

const availabilityOptions = [
  { title: 'Semua Ketersediaan', value: 'ALL' },
  { title: 'Tersedia', value: 'AVAILABLE' },
  { title: 'Coming Soon', value: 'COMING_SOON' },
]

const colorOptions = [
  { title: 'Primary', value: 'primary' },
  { title: 'Secondary', value: 'secondary' },
  { title: 'Success', value: 'success' },
  { title: 'Info', value: 'info' },
  { title: 'Warning', value: 'warning' },
  { title: 'Error', value: 'error' },
]

const mdiIconOptions = [
  'mdi-view-dashboard-outline',
  'mdi-view-dashboard-variant-outline',
  'mdi-file-document-edit-outline',
  'mdi-file-sign',
  'mdi-package-variant-closed-check',
  'mdi-package-variant-closed-minus',
  'mdi-cart-outline',
  'mdi-truck-outline',
  'mdi-warehouse',
  'mdi-clipboard-list-outline',
  'mdi-chart-bar',
  'mdi-chart-pie',
  'mdi-currency-usd',
  'mdi-folder-outline',
  'mdi-view-grid-outline',
  'mdi-shield-check-outline',
  'mdi-account-cog-outline',
  'mdi-cog-outline',
]

const openMdiIconReference = (): void => {
  window.open('https://pictogrammers.com/library/mdi/', '_blank')
}

const isForbiddenResponse = (error: unknown): boolean => {
  const err = error as AxiosErrorShape

  return Number(err.response?.status ?? 0) === 403
}

const redirectToForbidden = async (): Promise<void> => {
  closeAlert()
  isDialogVisible.value = false
  isGroupDialogVisible.value = false

  await router.replace('/forbidden')
}

const fetchModules = async () => {
  isLoading.value = true

  try {
    const response = await axiosIns.get('/master/dashboard-modules')

    modules.value = response.data?.data ?? []
  }
  catch (error: any) {
    if (isForbiddenResponse(error)) {
      await redirectToForbidden()

      return
    }

    showErrorToast({
      title: 'Gagal Memuat Dashboard Module',
      text: error.response?.data?.message
        ?? 'Terjadi kesalahan saat memuat data dashboard module.',
    })
  }
  finally {
    isLoading.value = false
  }
}

const fetchGroupOptions = async () => {
  try {
    const response = await axiosIns.get('/master/dashboard-modules/groups')

    groupOptions.value = response.data?.data ?? []
  }
  catch {
    // Tidak perlu mengganggu proses utama.
  }
}

const fetchPermissionOptions = async () => {
  try {
    const response = await axiosIns.get('/master/dashboard-modules/permission-options')

    permissionOptions.value = response.data?.data ?? []
  }
  catch {
    // Tidak perlu mengganggu proses utama.
  }
}

const groupSelectItems = computed(() => {
  return groupOptions.value.map(group => ({
    title: group.name,
    value: group.id,
    icon: group.icon ?? 'mdi-folder-outline',
  }))
})

const groupFilterItems = computed(() => {
  return [
    { title: 'Semua Group', value: 'ALL' },
    ...groupSelectItems.value,
  ]
})

const permissionSelectItems = computed(() => {
  return permissionOptions.value.map(permission => ({
    title: `${permission.name} (${permission.code})`,
    value: permission.code,
  }))
})

const groupName = (item: DashboardModuleItem): string => {
  return item.group?.name ?? '-'
}

const filteredModules = computed(() => {
  const keyword = search.value.trim().toLowerCase()

  return modules.value.filter(item => {
    const matchKeyword = !keyword
      || [
        item.title,
        item.short_title,
        item.code,
        item.route_path,
        item.permission_name,
      ]
        .filter(Boolean)
        .some(value => String(value).toLowerCase().includes(keyword))

    const matchGroup = groupFilter.value === 'ALL'
      || Number(item.dashboard_module_group_id) === Number(groupFilter.value)

    const matchStatus = statusFilter.value === 'ALL'
      || (statusFilter.value === 'ACTIVE' && item.is_active)
      || (statusFilter.value === 'INACTIVE' && !item.is_active)

    const matchAvailability = availabilityFilter.value === 'ALL'
      || (availabilityFilter.value === 'AVAILABLE' && item.is_available)
      || (availabilityFilter.value === 'COMING_SOON' && !item.is_available)

    return matchKeyword && matchGroup && matchStatus && matchAvailability
  })
})

const totalItems = computed(() => filteredModules.value.length)

const totalPages = computed(() => {
  return Math.max(1, Math.ceil(totalItems.value / itemsPerPage.value))
})

const paginatedModules = computed(() => {
  const start = (page.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value

  return filteredModules.value.slice(start, end)
})

watch([search, groupFilter, statusFilter, availabilityFilter, itemsPerPage], () => {
  page.value = 1
})

const getNextSortOrder = (): number => {
  const groupId = form.value.dashboard_module_group_id

  const siblings = modules.value.filter(item => {
    const sameGroup = Number(item.dashboard_module_group_id) === Number(groupId)
    const notCurrentItem = !form.value.id || Number(item.id) !== Number(form.value.id)

    return sameGroup && notCurrentItem
  })

  const maxOrder = siblings.reduce((max, item) => {
    const sortOrder = Number(item.sort_order || 0)

    return sortOrder > max ? sortOrder : max
  }, 0)

  return maxOrder + 10
}

const applyNextSortOrder = () => {
  if (!form.value.dashboard_module_group_id)
    return

  form.value.sort_order = getNextSortOrder()
}

watch(
  () => form.value.dashboard_module_group_id,
  () => {
    if (!isDialogVisible.value || isEditMode.value)
      return

    applyNextSortOrder()
  },
)

const resetForm = () => {
  form.value = {
    id: null,
    dashboard_module_group_id: groupOptions.value[0]?.id ?? null,
    code: '',
    title: '',
    short_title: '',
    description: '',
    icon: '',
    color: 'primary',
    route_path: '',
    permission_name: null,
    features: [],
    is_active: true,
    is_available: false,
    sort_order: 0,
  }
}

const resetFilter = () => {
  search.value = ''
  groupFilter.value = 'ALL'
  statusFilter.value = 'ALL'
  availabilityFilter.value = 'ALL'
  itemsPerPage.value = 10
  page.value = 1
}

const openCreateDialog = async () => {
  if (!canCreate.value) {
    await redirectToForbidden()

    return
  }

  resetForm()
  isEditMode.value = false
  isDialogVisible.value = true

  await nextTick()

  applyNextSortOrder()
}

const openEditDialog = async (item: DashboardModuleItem) => {
  if (!canUpdate.value) {
    await redirectToForbidden()

    return
  }

  isEditMode.value = true

  form.value = {
    id: item.id,
    dashboard_module_group_id: item.dashboard_module_group_id,
    code: item.code,
    title: item.title,
    short_title: item.short_title ?? '',
    description: item.description ?? '',
    icon: item.icon ?? '',
    color: item.color ?? 'primary',
    route_path: item.route_path ?? '',
    permission_name: item.permission_name,
    features: item.features ?? [],
    is_active: Boolean(item.is_active),
    is_available: Boolean(item.is_available),
    sort_order: item.sort_order ?? 0,
  }

  isDialogVisible.value = true
}

const closeDialog = () => {
  isDialogVisible.value = false
  resetForm()
}

const isDuplicateSortOrder = (): boolean => {
  const groupId = form.value.dashboard_module_group_id
  const sortOrder = Number(form.value.sort_order || 0)

  return modules.value.some(item => {
    const sameGroup = Number(item.dashboard_module_group_id) === Number(groupId)
    const sameOrder = Number(item.sort_order || 0) === sortOrder
    const notCurrentItem = !form.value.id || Number(item.id) !== Number(form.value.id)

    return sameGroup && sameOrder && notCurrentItem
  })
}

const validateForm = (): boolean => {
  if (!form.value.dashboard_module_group_id) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Group dashboard module wajib dipilih.',
    })

    return false
  }

  if (!form.value.code.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Code wajib diisi.',
    })

    return false
  }

  if (!form.value.title.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Title wajib diisi.',
    })

    return false
  }

  if (isDuplicateSortOrder()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Sort order sudah digunakan pada group yang sama.',
    })

    return false
  }

  return true
}

const buildPayload = () => {
  return {
    dashboard_module_group_id: form.value.dashboard_module_group_id,
    code: form.value.code.trim(),
    title: form.value.title.trim(),
    short_title: form.value.short_title.trim() || null,
    description: form.value.description.trim() || null,
    icon: form.value.icon.trim() || null,
    color: form.value.color || 'primary',
    route_path: form.value.route_path.trim() || null,
    permission_name: form.value.permission_name || null,
    features: form.value.features,
    is_active: Boolean(form.value.is_active),
    is_available: Boolean(form.value.is_available),
    sort_order: Number(form.value.sort_order || 0),
  }
}

const saveModule = async (): Promise<void> => {
  if (isSaving.value)
    return

  if (!validateForm())
    return

  /*
  |--------------------------------------------------------------------------
  | Tutup dialog dulu supaya SweetAlert tidak ketimpa VDialog.
  |--------------------------------------------------------------------------
  */
  isDialogVisible.value = false

  const confirm = await showConfirmAlert({
    title: isEditMode.value ? 'Simpan Perubahan Dashboard Module?' : 'Tambah Dashboard Module?',
    text: isEditMode.value
      ? 'Data dashboard module akan diperbarui.'
      : 'Dashboard module baru akan ditambahkan.',
    confirmButtonText: isEditMode.value ? 'Ya, simpan' : 'Ya, tambah',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) {
    isDialogVisible.value = true

    return
  }

  isSaving.value = true

  try {
    showLoadingAlert('Menyimpan Dashboard Module', 'Mohon tunggu sebentar.')

    const payload = buildPayload()

    if (isEditMode.value && form.value.id) {
      await axiosIns.put(`/master/dashboard-modules/${form.value.id}`, payload, {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      })
    }
    else {
      await axiosIns.post('/master/dashboard-modules', payload, {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      })
    }

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: isEditMode.value
        ? 'Dashboard module berhasil diperbarui.'
        : 'Dashboard module berhasil ditambahkan.',
    })

    resetForm()
    await fetchModules()
  }
  catch (error: any) {
    closeAlert()

    if (isForbiddenResponse(error)) {
      await redirectToForbidden()

      return
    }

    isDialogVisible.value = true

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal menyimpan dashboard module.'),
    })
  }
  finally {
    isSaving.value = false
  }
}

const toggleActive = async (item: DashboardModuleItem): Promise<void> => {
  if (isActionLoading.value)
    return

  if (!canUpdate.value) {
    await redirectToForbidden()

    return
  }

  const nextStatus = !item.is_active

  const confirm = await showConfirmAlert({
    title: nextStatus ? 'Aktifkan Dashboard Module?' : 'Nonaktifkan Dashboard Module?',
    text: nextStatus
      ? `Dashboard module "${item.title}" akan diaktifkan kembali.`
      : `Dashboard module "${item.title}" akan dinonaktifkan.`,
    confirmButtonText: nextStatus ? 'Ya, aktifkan' : 'Ya, nonaktifkan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  isActionLoading.value = true

  try {
    showLoadingAlert(
      nextStatus ? 'Mengaktifkan Dashboard Module' : 'Menonaktifkan Dashboard Module',
      'Mohon tunggu sebentar.',
    )

    await axiosIns.patch(`/master/dashboard-modules/${item.id}/toggle-active`, {}, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: nextStatus
        ? 'Dashboard module berhasil diaktifkan.'
        : 'Dashboard module berhasil dinonaktifkan.',
    })

    await fetchModules()
  }
  catch (error: any) {
    closeAlert()

    if (isForbiddenResponse(error)) {
      await redirectToForbidden()

      return
    }

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        nextStatus
          ? 'Gagal mengaktifkan dashboard module.'
          : 'Gagal menonaktifkan dashboard module.',
      ),
    })
  }
  finally {
    isActionLoading.value = false
  }
}

const toggleAvailable = async (item: DashboardModuleItem): Promise<void> => {
  if (isActionLoading.value)
    return

  if (!canUpdate.value) {
    await redirectToForbidden()

    return
  }

  const nextStatus = !item.is_available

  const confirm = await showConfirmAlert({
    title: nextStatus ? 'Tandai Tersedia?' : 'Tandai Coming Soon?',
    text: nextStatus
      ? `Dashboard module "${item.title}" akan ditandai tersedia dan dapat diakses user.`
      : `Dashboard module "${item.title}" akan ditandai coming soon.`,
    confirmButtonText: nextStatus ? 'Ya, tandai tersedia' : 'Ya, tandai coming soon',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  isActionLoading.value = true

  try {
    showLoadingAlert('Memperbarui Ketersediaan', 'Mohon tunggu sebentar.')

    await axiosIns.patch(`/master/dashboard-modules/${item.id}/toggle-available`, {}, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: nextStatus
        ? 'Dashboard module berhasil ditandai tersedia.'
        : 'Dashboard module berhasil ditandai coming soon.',
    })

    await fetchModules()
  }
  catch (error: any) {
    closeAlert()

    if (isForbiddenResponse(error)) {
      await redirectToForbidden()

      return
    }

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal memperbarui ketersediaan dashboard module.'),
    })
  }
  finally {
    isActionLoading.value = false
  }
}

const deleteModule = async (item: DashboardModuleItem): Promise<void> => {
  if (isActionLoading.value)
    return

  if (!canDelete.value) {
    await redirectToForbidden()

    return
  }

  const confirm = await showConfirmAlert({
    title: 'Hapus Dashboard Module?',
    text: `Dashboard module "${item.title}" akan dihapus secara permanen.`,
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  isActionLoading.value = true

  try {
    showLoadingAlert('Menghapus Dashboard Module', 'Mohon tunggu sebentar.')

    const response = await axiosIns.delete(`/master/dashboard-modules/${item.id}`, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || 'Dashboard module berhasil dihapus.',
    })

    await fetchModules()
  }
  catch (error: any) {
    closeAlert()

    if (isForbiddenResponse(error)) {
      await redirectToForbidden()

      return
    }

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal menghapus dashboard module.'),
    })
  }
  finally {
    isActionLoading.value = false
  }
}

const resetGroupForm = () => {
  groupForm.value = {
    id: null,
    code: '',
    name: '',
    icon: '',
    sort_order: 0,
    is_active: true,
  }
}

const getNextGroupSortOrder = (): number => {
  const maxOrder = groupOptions.value.reduce((max, item) => {
    const notCurrentItem = !groupForm.value.id || Number(item.id) !== Number(groupForm.value.id)
    const sortOrder = Number(item.sort_order || 0)

    return notCurrentItem && sortOrder > max ? sortOrder : max
  }, 0)

  return maxOrder + 10
}

const applyNextGroupSortOrder = () => {
  groupForm.value.sort_order = getNextGroupSortOrder()
}

const openCreateGroupDialog = async () => {
  if (!canCreate.value) {
    await redirectToForbidden()

    return
  }

  resetGroupForm()
  isGroupEditMode.value = false
  isGroupDialogVisible.value = true

  await nextTick()

  applyNextGroupSortOrder()
}

const openEditGroupDialog = async (item: DashboardModuleGroupOption) => {
  if (!canUpdate.value) {
    await redirectToForbidden()

    return
  }

  isGroupEditMode.value = true

  groupForm.value = {
    id: item.id,
    code: item.code,
    name: item.name,
    icon: item.icon ?? '',
    sort_order: item.sort_order ?? 0,
    is_active: Boolean(item.is_active),
  }

  isGroupDialogVisible.value = true
}

const closeGroupDialog = () => {
  isGroupDialogVisible.value = false
  resetGroupForm()
}

const isDuplicateGroupSortOrder = (): boolean => {
  const sortOrder = Number(groupForm.value.sort_order || 0)

  return groupOptions.value.some(item => {
    const sameOrder = Number(item.sort_order || 0) === sortOrder
    const notCurrentItem = !groupForm.value.id || Number(item.id) !== Number(groupForm.value.id)

    return sameOrder && notCurrentItem
  })
}

const validateGroupForm = (): boolean => {
  if (!groupForm.value.code.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Code group wajib diisi.',
    })

    return false
  }

  if (!groupForm.value.name.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Nama group wajib diisi.',
    })

    return false
  }

  if (isDuplicateGroupSortOrder()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Sort order group sudah digunakan.',
    })

    return false
  }

  return true
}

const buildGroupPayload = () => {
  return {
    code: groupForm.value.code.trim(),
    name: groupForm.value.name.trim(),
    icon: groupForm.value.icon.trim() || null,
    sort_order: Number(groupForm.value.sort_order || 0),
    is_active: Boolean(groupForm.value.is_active),
  }
}

const saveGroup = async (): Promise<void> => {
  if (isGroupSaving.value)
    return

  if (!validateGroupForm())
    return

  /*
  |--------------------------------------------------------------------------
  | Tutup dialog dulu supaya SweetAlert tidak ketimpa VDialog.
  |--------------------------------------------------------------------------
  */
  isGroupDialogVisible.value = false

  const confirm = await showConfirmAlert({
    title: isGroupEditMode.value ? 'Simpan Perubahan Group?' : 'Tambah Dashboard Module Group?',
    text: isGroupEditMode.value
      ? 'Data group akan diperbarui.'
      : 'Group baru akan ditambahkan.',
    confirmButtonText: isGroupEditMode.value ? 'Ya, simpan' : 'Ya, tambah',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) {
    isGroupDialogVisible.value = true

    return
  }

  isGroupSaving.value = true

  try {
    showLoadingAlert('Menyimpan Group', 'Mohon tunggu sebentar.')

    const payload = buildGroupPayload()

    if (isGroupEditMode.value && groupForm.value.id) {
      await axiosIns.put(`/master/dashboard-modules/groups/${groupForm.value.id}`, payload, {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      })
    }
    else {
      await axiosIns.post('/master/dashboard-modules/groups', payload, {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      })
    }

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: isGroupEditMode.value
        ? 'Dashboard module group berhasil diperbarui.'
        : 'Dashboard module group berhasil ditambahkan.',
    })

    resetGroupForm()
    await fetchGroupOptions()
  }
  catch (error: any) {
    closeAlert()

    if (isForbiddenResponse(error)) {
      await redirectToForbidden()

      return
    }

    isGroupDialogVisible.value = true

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal menyimpan dashboard module group.'),
    })
  }
  finally {
    isGroupSaving.value = false
  }
}

const toggleGroupActive = async (item: DashboardModuleGroupOption): Promise<void> => {
  if (isGroupActionLoading.value)
    return

  if (!canUpdate.value) {
    await redirectToForbidden()

    return
  }

  const nextStatus = !item.is_active

  const confirm = await showConfirmAlert({
    title: nextStatus ? 'Aktifkan Group?' : 'Nonaktifkan Group?',
    text: nextStatus
      ? `Group "${item.name}" akan diaktifkan kembali.`
      : `Group "${item.name}" akan dinonaktifkan, seluruh module di dalamnya akan ikut disembunyikan dari dashboard.`,
    confirmButtonText: nextStatus ? 'Ya, aktifkan' : 'Ya, nonaktifkan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  isGroupActionLoading.value = true

  try {
    showLoadingAlert(
      nextStatus ? 'Mengaktifkan Group' : 'Menonaktifkan Group',
      'Mohon tunggu sebentar.',
    )

    await axiosIns.patch(`/master/dashboard-modules/groups/${item.id}/toggle-active`, {}, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: nextStatus
        ? 'Dashboard module group berhasil diaktifkan.'
        : 'Dashboard module group berhasil dinonaktifkan.',
    })

    await fetchGroupOptions()
  }
  catch (error: any) {
    closeAlert()

    if (isForbiddenResponse(error)) {
      await redirectToForbidden()

      return
    }

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        nextStatus
          ? 'Gagal mengaktifkan dashboard module group.'
          : 'Gagal menonaktifkan dashboard module group.',
      ),
    })
  }
  finally {
    isGroupActionLoading.value = false
  }
}

const deleteGroup = async (item: DashboardModuleGroupOption): Promise<void> => {
  if (isGroupActionLoading.value)
    return

  if (!canDelete.value) {
    await redirectToForbidden()

    return
  }

  const confirm = await showConfirmAlert({
    title: 'Hapus Dashboard Module Group?',
    text: `Group "${item.name}" akan dihapus. Group yang masih memiliki dashboard module tidak dapat dihapus.`,
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  isGroupActionLoading.value = true

  try {
    showLoadingAlert('Menghapus Group', 'Mohon tunggu sebentar.')

    const response = await axiosIns.delete(`/master/dashboard-modules/groups/${item.id}`, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || 'Dashboard module group berhasil dihapus.',
    })

    await fetchGroupOptions()
  }
  catch (error: any) {
    closeAlert()

    if (isForbiddenResponse(error)) {
      await redirectToForbidden()

      return
    }

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal menghapus dashboard module group.'),
    })
  }
  finally {
    isGroupActionLoading.value = false
  }
}

onMounted(async () => {
  try {
    await permissionStore.loadPermissions(true)
  }
  catch {
    await redirectToForbidden()

    return
  }

  if (!canView.value) {
    await redirectToForbidden()

    return
  }

  await Promise.all([
    fetchModules(),
    fetchGroupOptions(),
    fetchPermissionOptions(),
  ])
})
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard>
        <VCardItem>
          <div>
            <VCardTitle class="text-h5">
              Kelola Dashboard Module
            </VCardTitle>

            <VCardSubtitle>
              Kelola module dan group yang tampil pada halaman launcher dashboard.
            </VCardSubtitle>
          </div>
        </VCardItem>

        <VTabs v-model="activeTab">
          <VTab
            value="modules"
            class="text-none"
          >
            <VIcon
              icon="tabler-apps"
              start
            />
            Dashboard Module
          </VTab>

          <VTab
            value="groups"
            class="text-none"
          >
            <VIcon
              icon="tabler-folder"
              start
            />
            Dashboard Module Group
          </VTab>
        </VTabs>

        <VDivider />

        <VWindow v-model="activeTab">
          <VWindowItem value="modules">
            <VCardItem>
              <div class="d-flex align-center justify-space-between flex-wrap gap-4">
                <div class="text-body-2 text-medium-emphasis">
                  Module akan tampil sebagai card pada halaman launcher dashboard.
                </div>

                <VBtn
                  v-if="canCreate"
                  color="primary"
                  prepend-icon="tabler-plus"
                  class="text-none"
                  @click="openCreateDialog"
                >
                  Tambah Dashboard Module
                </VBtn>
              </div>
            </VCardItem>

            <VDivider />

            <VCardText>
              <VRow>
                <VCol
                  cols="12"
                  md="4"
                >
                  <VTextField
                    v-model="search"
                    label="Search"
                    placeholder="Cari title, code, route..."
                    prepend-inner-icon="tabler-search"
                    clearable
                    density="comfortable"
                  />
                </VCol>

                <VCol
                  cols="12"
                  sm="4"
                  md="2"
                >
                  <VSelect
                    v-model="groupFilter"
                    :items="groupFilterItems"
                    item-title="title"
                    item-value="value"
                    label="Group"
                    density="comfortable"
                  />
                </VCol>

                <VCol
                  cols="12"
                  sm="4"
                  md="2"
                >
                  <VSelect
                    v-model="statusFilter"
                    :items="statusOptions"
                    label="Status"
                    density="comfortable"
                  />
                </VCol>

                <VCol
                  cols="12"
                  sm="4"
                  md="2"
                >
                  <VSelect
                    v-model="availabilityFilter"
                    :items="availabilityOptions"
                    label="Ketersediaan"
                    density="comfortable"
                  />
                </VCol>

                <VCol
                  cols="12"
                  md="2"
                >
                  <VBtn
                    color="secondary"
                    variant="tonal"
                    prepend-icon="tabler-refresh"
                    block
                    class="text-none"
                    @click="resetFilter"
                  >
                    Reset Filter
                  </VBtn>
                </VCol>
              </VRow>
            </VCardText>

            <VDivider />

            <VCardText class="pa-0">
              <VTable class="text-no-wrap">
                <thead>
                  <tr>
                    <th class="text-left">
                      Module
                    </th>
                    <th class="text-left">
                      Group
                    </th>
                    <th class="text-left">
                      Route Path
                    </th>
                    <th class="text-left">
                      Permission
                    </th>
                    <th class="text-left">
                      Order
                    </th>
                    <th class="text-left">
                      Status
                    </th>
                    <th class="text-left">
                      Ketersediaan
                    </th>
                    <th class="text-center">
                      Action
                    </th>
                  </tr>
                </thead>

                <tbody>
                  <tr v-if="isLoading">
                    <td
                      colspan="8"
                      class="text-center py-8"
                    >
                      <VProgressCircular
                        indeterminate
                        color="primary"
                        class="me-2"
                      />
                      Memuat data dashboard module...
                    </td>
                  </tr>

                  <tr v-else-if="paginatedModules.length === 0">
                    <td
                      colspan="8"
                      class="text-center py-8 text-disabled"
                    >
                      Data dashboard module belum tersedia.
                    </td>
                  </tr>

                  <tr
                    v-for="item in paginatedModules"
                    v-else
                    :key="item.id"
                  >
                    <td>
                      <div class="d-flex align-center gap-2">
                        <VAvatar
                          :color="item.color"
                          variant="tonal"
                          size="36"
                          rounded="lg"
                        >
                          <VIcon
                            :icon="item.icon ?? 'mdi-view-dashboard-outline'"
                            size="20"
                          />
                        </VAvatar>

                        <div>
                          <div class="font-weight-medium">
                            {{ item.title }}
                          </div>

                          <div class="text-caption text-disabled">
                            {{ item.code }}
                          </div>
                        </div>
                      </div>
                    </td>

                    <td>
                      {{ groupName(item) }}
                    </td>

                    <td>
                      <code v-if="item.route_path">
                        {{ item.route_path }}
                      </code>

                      <span
                        v-else
                        class="text-disabled"
                      >
                        -
                      </span>
                    </td>

                    <td>
                      <span
                        v-if="item.permission_name"
                        class="text-body-2"
                      >
                        {{ item.permission_name }}
                      </span>

                      <span
                        v-else
                        class="text-disabled"
                      >
                        -
                      </span>
                    </td>

                    <td>
                      {{ item.sort_order }}
                    </td>

                    <td>
                      <VChip
                        size="small"
                        variant="tonal"
                        :color="item.is_active ? 'success' : 'error'"
                      >
                        {{ item.is_active ? 'Active' : 'Inactive' }}
                      </VChip>
                    </td>

                    <td>
                      <VChip
                        size="small"
                        variant="tonal"
                        :color="item.is_available ? 'success' : 'warning'"
                      >
                        {{ item.is_available ? 'Tersedia' : 'Coming Soon' }}
                      </VChip>
                    </td>

                    <td class="text-right">
                      <div class="d-flex justify-end gap-1">
                        <VBtn
                          v-if="canUpdate"
                          icon
                          size="small"
                          variant="text"
                          color="primary"
                          class="text-none"
                          @click="openEditDialog(item)"
                        >
                          <VIcon icon="tabler-edit" />
                          <VTooltip
                            activator="parent"
                            location="top"
                          >
                            Edit
                          </VTooltip>
                        </VBtn>

                        <VBtn
                          v-if="canUpdate"
                          icon
                          size="small"
                          variant="text"
                          :color="item.is_available ? 'warning' : 'success'"
                          class="text-none"
                          @click="toggleAvailable(item)"
                        >
                          <VIcon :icon="item.is_available ? 'tabler-clock' : 'tabler-circle-check'" />
                          <VTooltip
                            activator="parent"
                            location="top"
                          >
                            {{ item.is_available ? 'Tandai Coming Soon' : 'Tandai Tersedia' }}
                          </VTooltip>
                        </VBtn>

                        <VBtn
                          v-if="canUpdate"
                          icon
                          size="small"
                          variant="text"
                          :color="item.is_active ? 'warning' : 'success'"
                          class="text-none"
                          @click="toggleActive(item)"
                        >
                          <VIcon :icon="item.is_active ? 'tabler-eye-off' : 'tabler-eye'" />
                          <VTooltip
                            activator="parent"
                            location="top"
                          >
                            {{ item.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                          </VTooltip>
                        </VBtn>

                        <VBtn
                          v-if="canDelete"
                          icon
                          size="small"
                          variant="text"
                          color="error"
                          class="text-none"
                          @click="deleteModule(item)"
                        >
                          <VIcon icon="tabler-trash" />
                          <VTooltip
                            activator="parent"
                            location="top"
                          >
                            Delete
                          </VTooltip>
                        </VBtn>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>

            <VDivider />

            <VCardText>
              <div class="d-flex align-center justify-space-between flex-wrap gap-4">
                <div class="text-body-2 text-disabled">
                  Total {{ totalItems }} dashboard module
                </div>

                <VPagination
                  v-model="page"
                  :length="totalPages"
                  total-visible="5"
                />

                <VSelect
                  v-model="itemsPerPage"
                  :items="itemsPerPageOptions"
                  label="Tampilkan"
                  density="compact"
                  hide-details
                  style="max-width: 110px;"
                />
              </div>
            </VCardText>
          </VWindowItem>

          <VWindowItem value="groups">
            <VCardItem>
              <div class="d-flex align-center justify-space-between flex-wrap gap-4">
                <div class="text-body-2 text-medium-emphasis">
                  Group digunakan untuk mengelompokkan dashboard module pada halaman launcher.
                </div>

                <VBtn
                  v-if="canCreate"
                  color="primary"
                  prepend-icon="tabler-plus"
                  class="text-none"
                  @click="openCreateGroupDialog"
                >
                  Tambah Group
                </VBtn>
              </div>
            </VCardItem>

            <VDivider />

            <VCardText class="pa-0">
              <VTable class="text-no-wrap">
                <thead>
                  <tr>
                    <th class="text-left">
                      Group
                    </th>
                    <th class="text-left">
                      Code
                    </th>
                    <th class="text-left">
                      Order
                    </th>
                    <th class="text-left">
                      Jumlah Module
                    </th>
                    <th class="text-left">
                      Status
                    </th>
                    <th class="text-center">
                      Action
                    </th>
                  </tr>
                </thead>

                <tbody>
                  <tr v-if="groupOptions.length === 0">
                    <td
                      colspan="6"
                      class="text-center py-8 text-disabled"
                    >
                      Data dashboard module group belum tersedia.
                    </td>
                  </tr>

                  <tr
                    v-for="item in groupOptions"
                    v-else
                    :key="item.id"
                  >
                    <td>
                      <div class="d-flex align-center gap-2">
                        <VAvatar
                          color="secondary"
                          variant="tonal"
                          size="36"
                          rounded="lg"
                        >
                          <VIcon
                            :icon="item.icon ?? 'mdi-folder-outline'"
                            size="20"
                          />
                        </VAvatar>

                        <div class="font-weight-medium">
                          {{ item.name }}
                        </div>
                      </div>
                    </td>

                    <td>
                      <code>{{ item.code }}</code>
                    </td>

                    <td>
                      {{ item.sort_order }}
                    </td>

                    <td>
                      {{ item.modules_count ?? 0 }}
                    </td>

                    <td>
                      <VChip
                        size="small"
                        variant="tonal"
                        :color="item.is_active ? 'success' : 'error'"
                      >
                        {{ item.is_active ? 'Active' : 'Inactive' }}
                      </VChip>
                    </td>

                    <td class="text-right">
                      <div class="d-flex justify-end gap-1">
                        <VBtn
                          v-if="canUpdate"
                          icon
                          size="small"
                          variant="text"
                          color="primary"
                          class="text-none"
                          @click="openEditGroupDialog(item)"
                        >
                          <VIcon icon="tabler-edit" />
                          <VTooltip
                            activator="parent"
                            location="top"
                          >
                            Edit
                          </VTooltip>
                        </VBtn>

                        <VBtn
                          v-if="canUpdate"
                          icon
                          size="small"
                          variant="text"
                          :color="item.is_active ? 'warning' : 'success'"
                          class="text-none"
                          @click="toggleGroupActive(item)"
                        >
                          <VIcon :icon="item.is_active ? 'tabler-eye-off' : 'tabler-eye'" />
                          <VTooltip
                            activator="parent"
                            location="top"
                          >
                            {{ item.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                          </VTooltip>
                        </VBtn>

                        <VBtn
                          v-if="canDelete"
                          icon
                          size="small"
                          variant="text"
                          color="error"
                          class="text-none"
                          :disabled="Boolean(item.modules_count)"
                          @click="deleteGroup(item)"
                        >
                          <VIcon icon="tabler-trash" />
                          <VTooltip
                            activator="parent"
                            location="top"
                          >
                            {{ item.modules_count ? 'Masih memiliki module' : 'Delete' }}
                          </VTooltip>
                        </VBtn>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>

            <VDivider />

            <VCardText>
              <div class="text-body-2 text-disabled">
                Total {{ groupOptions.length }} dashboard module group
              </div>
            </VCardText>
          </VWindowItem>
        </VWindow>
      </VCard>
    </VCol>
  </VRow>

  <VDialog
    v-model="isDialogVisible"
    max-width="800"
    persistent
  >
    <VCard>
      <VCardItem>
        <VCardTitle>
          {{ isEditMode ? 'Edit Dashboard Module' : 'Tambah Dashboard Module' }}
        </VCardTitle>

        <VCardSubtitle>
          Module akan tampil pada halaman launcher dashboard sesuai group dan permission.
        </VCardSubtitle>
      </VCardItem>

      <VDivider />

      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <VSelect
              v-model="form.dashboard_module_group_id"
              :items="groupSelectItems"
              item-title="title"
              item-value="value"
              label="Group"
              density="comfortable"
            />
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VTextField
              v-model="form.code"
              label="Code"
              placeholder="Contoh: PURCHASE_ORDER"
              density="comfortable"
              hint="Kode unik, gunakan huruf kapital dan underscore."
              persistent-hint
            />
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VTextField
              v-model="form.title"
              label="Title"
              placeholder="Contoh: Purchase Order"
              density="comfortable"
            />
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VTextField
              v-model="form.short_title"
              label="Short Title"
              placeholder="Contoh: PO"
              density="comfortable"
            />
          </VCol>

          <VCol cols="12">
            <VTextarea
              v-model="form.description"
              label="Description"
              rows="2"
              density="comfortable"
            />
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VCombobox
              v-model="form.icon"
              :items="mdiIconOptions"
              label="Icon"
              placeholder="Contoh: mdi-view-dashboard-outline"
              density="comfortable"
              clearable
              hint="Gunakan nama icon MDI, contoh: mdi-cart-outline."
              persistent-hint
            >
              <template #prepend-inner>
                <VIcon
                  v-if="form.icon"
                  :icon="form.icon"
                  size="20"
                />
              </template>

              <template #append-inner>
                <VBtn
                  icon
                  size="x-small"
                  variant="text"
                  color="primary"
                  class="text-none"
                  @click.stop="openMdiIconReference"
                >
                  <VIcon icon="tabler-external-link" />
                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    Buka referensi MDI Icon
                  </VTooltip>
                </VBtn>
              </template>

              <template #item="{ props, item }">
                <VListItem
                  v-bind="props"
                  :title="undefined"
                >
                  <template #prepend>
                    <VIcon
                      :icon="item.raw"
                      size="20"
                    />
                  </template>

                  <VListItemTitle>
                    {{ item.raw }}
                  </VListItemTitle>
                </VListItem>
              </template>
            </VCombobox>
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VSelect
              v-model="form.color"
              :items="colorOptions"
              label="Color"
              density="comfortable"
            >
              <template #selection="{ item }">
                <div class="d-flex align-center gap-2">
                  <VAvatar
                    :color="item.raw.value"
                    size="14"
                  />

                  <span>{{ item.raw.title }}</span>
                </div>
              </template>

              <template #item="{ props, item }">
                <VListItem v-bind="props">
                  <template #prepend>
                    <VAvatar
                      :color="item.raw.value"
                      size="14"
                    />
                  </template>
                </VListItem>
              </template>
            </VSelect>
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VTextField
              v-model="form.route_path"
              label="Route Path"
              placeholder="Contoh: /dashboards/purchase-order"
              density="comfortable"
              hint="Halaman tujuan ketika user klik module ini."
              persistent-hint
            />
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VSelect
              v-model="form.permission_name"
              :items="permissionSelectItems"
              item-title="title"
              item-value="value"
              label="Permission"
              clearable
              density="comfortable"
              hint="Kosongkan jika module dapat diakses semua user."
              persistent-hint
            />
          </VCol>

          <VCol
            cols="12"
            md="8"
          >
            <VCombobox
              v-model="form.features"
              label="Features"
              placeholder="Ketik lalu tekan enter"
              multiple
              chips
              closable-chips
              density="comfortable"
              hint="Opsional, daftar highlight fitur module."
              persistent-hint
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <VTextField
              v-model.number="form.sort_order"
              label="Sort Order"
              type="number"
              density="comfortable"
              append-inner-icon="tabler-refresh"
              @click:append-inner="applyNextSortOrder"
            />
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VSwitch
              v-model="form.is_active"
              label="Active"
              color="success"
            />
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VSwitch
              v-model="form.is_available"
              label="Tersedia (bukan Coming Soon)"
              color="success"
            />
          </VCol>
        </VRow>
      </VCardText>

      <VDivider />

      <VCardActions>
        <VSpacer />

        <VBtn
          variant="tonal"
          color="secondary"
          class="text-none"
          :disabled="isSaving"
          @click="closeDialog"
        >
          Batal
        </VBtn>

        <VBtn
          color="primary"
          class="text-none"
          :loading="isSaving"
          @click="saveModule"
        >
          Simpan
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VDialog
    v-model="isGroupDialogVisible"
    max-width="600"
    persistent
  >
    <VCard>
      <VCardItem>
        <VCardTitle>
          {{ isGroupEditMode ? 'Edit Dashboard Module Group' : 'Tambah Dashboard Module Group' }}
        </VCardTitle>

        <VCardSubtitle>
          Group digunakan untuk mengelompokkan dashboard module pada halaman launcher.
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
              v-model="groupForm.code"
              label="Code"
              placeholder="Contoh: NON_TRADE"
              density="comfortable"
              hint="Kode unik, gunakan huruf kapital dan underscore."
              persistent-hint
            />
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VTextField
              v-model="groupForm.name"
              label="Nama Group"
              placeholder="Contoh: Non Trade"
              density="comfortable"
            />
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VCombobox
              v-model="groupForm.icon"
              :items="mdiIconOptions"
              label="Icon"
              placeholder="Contoh: mdi-folder-outline"
              density="comfortable"
              clearable
              hint="Gunakan nama icon MDI, contoh: mdi-folder-outline."
              persistent-hint
            >
              <template #prepend-inner>
                <VIcon
                  v-if="groupForm.icon"
                  :icon="groupForm.icon"
                  size="20"
                />
              </template>

              <template #item="{ props, item }">
                <VListItem
                  v-bind="props"
                  :title="undefined"
                >
                  <template #prepend>
                    <VIcon
                      :icon="item.raw"
                      size="20"
                    />
                  </template>

                  <VListItemTitle>
                    {{ item.raw }}
                  </VListItemTitle>
                </VListItem>
              </template>
            </VCombobox>
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <VTextField
              v-model.number="groupForm.sort_order"
              label="Sort Order"
              type="number"
              density="comfortable"
              append-inner-icon="tabler-refresh"
              @click:append-inner="applyNextGroupSortOrder"
            />
          </VCol>

          <VCol cols="12">
            <VSwitch
              v-model="groupForm.is_active"
              label="Active"
              color="success"
            />
          </VCol>
        </VRow>
      </VCardText>

      <VDivider />

      <VCardActions>
        <VSpacer />

        <VBtn
          variant="tonal"
          color="secondary"
          class="text-none"
          :disabled="isGroupSaving"
          @click="closeGroupDialog"
        >
          Batal
        </VBtn>

        <VBtn
          color="primary"
          class="text-none"
          :loading="isGroupSaving"
          @click="saveGroup"
        >
          Simpan
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
