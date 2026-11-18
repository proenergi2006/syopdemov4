<script setup lang="ts">
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from 'vue'
import axiosIns from '@/plugins/axios'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'

interface PermissionModuleItem {
  id: number
  code: string
  name: string
  description: string | null
  route_prefix: string
  sort_order: number
  is_active: boolean
  approval_document_type: string | null
  approval_document_label: string | null
  approval_uses_area_matrix: boolean
  approval_uses_transaction_category: boolean
  permissions_count: number
  active_permissions_count: number
  created_at: string | null
  updated_at: string | null
}

interface PermissionItem {
  id: number
  module: string
  action: string
  code: string
  name: string
  description: string | null
  is_active: boolean
  requires_scope: boolean
  created_at: string | null
  updated_at: string | null
}

interface CreateModuleForm {
  code: string
  name: string
  description: string
  route_prefix: string
  sort_order: number

  /*
  | Pendaftaran module ini sebagai jenis dokumen Approval Flow.
  | Dikosongkan berarti module tidak punya approval flow sama sekali.
  */
  approval_document_type: string
  approval_document_label: string
  approval_uses_area_matrix: boolean
  approval_uses_transaction_category: boolean
}

interface ApprovalSettingsForm {
  approval_document_type: string
  approval_document_label: string
  approval_uses_area_matrix: boolean
  approval_uses_transaction_category: boolean
}

interface PermissionForm {
  action: string
  name: string
  description: string
  is_active: boolean
  requires_scope: boolean
}

interface FieldErrors {
  code?: string[]
  name?: string[]
  description?: string[]
  route_prefix?: string[]
  sort_order?: string[]
  approval_document_type?: string[]
  approval_document_label?: string[]
  is_active?: string[]
}

interface PermissionFieldErrors {
  action?: string[]
  name?: string[]
  description?: string[]
  is_active?: string[]
}

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

const moduleItems = ref<PermissionModuleItem[]>([])
const permissionItems = ref<PermissionItem[]>([])
const selectedModule = ref<PermissionModuleItem | null>(null)
const selectedPermission = ref<PermissionItem | null>(null)

const isLoading = ref(false)
const isLoadingDetail = ref(false)
const isSubmitting = ref(false)
const isSubmittingPermission = ref(false)
const isSubmittingEditPermission = ref(false)
const isUpdatingModuleStatus = ref(false)
const isUpdatingPermissionStatus = ref(false)
const isDeletingPermission = ref(false)
const isSubmittingApproval = ref(false)

const isCreateDialogOpen = ref(false)
const isDetailDialogOpen = ref(false)
const isCreatePermissionDialogOpen = ref(false)
const isEditPermissionDialogOpen = ref(false)
const isApprovalDialogOpen = ref(false)

const search = ref('')
const statusFilter = ref<'all' | '1' | '0'>('all')

const createFormRef = ref()
const createPermissionFormRef = ref()
const editPermissionFormRef = ref()
const approvalFormRef = ref()

const createForm = reactive<CreateModuleForm>({
  code: '',
  name: '',
  description: '',
  route_prefix: '',
  sort_order: 0,
  approval_document_type: '',
  approval_document_label: '',
  approval_uses_area_matrix: false,
  approval_uses_transaction_category: false,
})

const createPermissionForm = reactive<PermissionForm>({
  action: '',
  name: '',
  description: '',
  is_active: true,
  requires_scope: true,
})

const editPermissionForm = reactive<PermissionForm>({
  action: '',
  name: '',
  description: '',
  is_active: true,
  requires_scope: true,
})

const approvalForm = reactive<ApprovalSettingsForm>({
  approval_document_type: '',
  approval_document_label: '',
  approval_uses_area_matrix: false,
  approval_uses_transaction_category: false,
})

const fieldErrors = ref<FieldErrors>({})
const permissionFieldErrors = ref<PermissionFieldErrors>({})
const editPermissionFieldErrors = ref<PermissionFieldErrors>({})
const approvalFieldErrors = ref<FieldErrors>({})

let searchTimer: ReturnType<typeof setTimeout> | null = null

const statusOptions = [
  { title: 'Semua Status', value: 'all' },
  { title: 'Aktif', value: '1' },
  { title: 'Nonaktif', value: '0' },
]

const requiredRule = (value: unknown) => {
  if (
    value === null
    || value === undefined
    || String(value).trim() === ''
  )
    return 'Field ini wajib diisi.'

  return true
}

const moduleCodePreview = computed(() => {
  return createForm.code
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
})

const routePrefixPreview = computed(() => {
  let route = createForm.route_prefix.trim()

  if (!route)
    return ''

  if (!route.startsWith('/'))
    route = `/${route}`

  route = route.replace(/\/+/g, '/')

  if (route.length > 1)
    route = route.replace(/\/+$/g, '')

  return route
})

const createPermissionCodePreview = computed(() => {
  if (!selectedModule.value)
    return ''

  const action = createPermissionForm.action
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')

  return action
    ? `${selectedModule.value.code}.${action}`
    : `${selectedModule.value.code}.action`
})

const editPermissionCodePreview = computed(() => {
  if (!selectedModule.value)
    return ''

  const action = editPermissionForm.action
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')

  return action
    ? `${selectedModule.value.code}.${action}`
    : `${selectedModule.value.code}.action`
})

const hasActiveViewPermission = computed(() => {
  return permissionItems.value.some(permission => {
    return permission.action === 'view'
      && permission.is_active
  })
})

function resetCreateForm() {
  createForm.code = ''
  createForm.name = ''
  createForm.description = ''
  createForm.route_prefix = ''
  createForm.sort_order = 0
  createForm.approval_document_type = ''
  createForm.approval_document_label = ''
  createForm.approval_uses_area_matrix = false
  createForm.approval_uses_transaction_category = false

  fieldErrors.value = {}
  createFormRef.value?.resetValidation()
}

function openCreateDialog() {
  resetCreateForm()
  isCreateDialogOpen.value = true
}

function closeCreateDialog() {
  if (isSubmitting.value)
    return

  isCreateDialogOpen.value = false
  resetCreateForm()
}

function resetCreatePermissionForm() {
  const hasViewPermission = permissionItems.value.some(
    permission => permission.action === 'view',
  )

  createPermissionForm.action = hasViewPermission
    ? ''
    : 'view'

  createPermissionForm.name = ''
  createPermissionForm.description = ''
  createPermissionForm.is_active = true
  createPermissionForm.requires_scope = true

  permissionFieldErrors.value = {}
  createPermissionFormRef.value?.resetValidation()
}

function resetEditPermissionForm() {
  editPermissionForm.action = ''
  editPermissionForm.name = ''
  editPermissionForm.description = ''
  editPermissionForm.is_active = true
  editPermissionForm.requires_scope = true

  selectedPermission.value = null
  editPermissionFieldErrors.value = {}
  editPermissionFormRef.value?.resetValidation()
}

function openCreatePermissionDialog() {
  if (!selectedModule.value)
    return

  resetCreatePermissionForm()
  isCreatePermissionDialogOpen.value = true
}

function closeCreatePermissionDialog() {
  if (isSubmittingPermission.value)
    return

  isCreatePermissionDialogOpen.value = false
  resetCreatePermissionForm()
}

function openEditPermissionDialog(permission: PermissionItem) {
  selectedPermission.value = permission

  editPermissionForm.action = permission.action ?? ''
  editPermissionForm.name = permission.name ?? ''
  editPermissionForm.description = permission.description ?? ''
  editPermissionForm.is_active = Boolean(permission.is_active)
  editPermissionForm.requires_scope = Boolean(permission.requires_scope)

  editPermissionFieldErrors.value = {}
  editPermissionFormRef.value?.resetValidation()

  isEditPermissionDialogOpen.value = true
}

function closeEditPermissionDialog() {
  if (isSubmittingEditPermission.value)
    return

  isEditPermissionDialogOpen.value = false
  resetEditPermissionForm()
}

async function fetchModules() {
  isLoading.value = true

  try {
    const params: Record<string, string | number> = {}

    if (search.value.trim())
      params.search = search.value.trim()

    if (statusFilter.value !== 'all')
      params.is_active = Number(statusFilter.value)

    const response = await axiosIns.get(
      '/master/permission-modules',
      { params },
    )

    const payload = response.data
    const responseData = payload?.data

    if (Array.isArray(responseData))
      moduleItems.value = responseData

    else if (Array.isArray(responseData?.modules))
      moduleItems.value = responseData.modules

    else if (Array.isArray(responseData?.data))
      moduleItems.value = responseData.data

    else if (Array.isArray(payload))
      moduleItems.value = payload

    else
      moduleItems.value = []
  }
  catch (error: any) {
    moduleItems.value = []

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal Memuat Data',
      text: getApiErrorMessage(
        err,
        'Gagal memuat Permission Module.',
      ),
    })
  }
  finally {
    isLoading.value = false
  }
}

async function loadModuleDetail(moduleId: number): Promise<boolean> {
  isLoadingDetail.value = true

  try {
    const response = await axiosIns.get(
      `/master/permission-modules/${moduleId}`,
    )

    const responseData = response.data?.data

    if (
      responseData?.module
      && selectedModule.value
    ) {
      selectedModule.value = {
        ...selectedModule.value,
        ...responseData.module,
      }
    }

    permissionItems.value = Array.isArray(
      responseData?.permissions,
    )
      ? responseData.permissions
      : []

    return true
  }
  catch (error: any) {
    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal Memuat Detail',
      text: getApiErrorMessage(
        err,
        'Gagal memuat detail Permission Module.',
      ),
    })

    return false
  }
  finally {
    isLoadingDetail.value = false
  }
}

async function openDetailDialog(moduleItem: PermissionModuleItem) {
  selectedModule.value = moduleItem
  permissionItems.value = []
  isDetailDialogOpen.value = true

  const success = await loadModuleDetail(moduleItem.id)

  if (!success) {
    isDetailDialogOpen.value = false
    selectedModule.value = null
    permissionItems.value = []
  }
}

function closeDetailDialog() {
  if (
    isLoadingDetail.value
    || isUpdatingModuleStatus.value
    || isSubmittingPermission.value
    || isSubmittingEditPermission.value
    || isUpdatingPermissionStatus.value
    || isDeletingPermission.value
  )
    return

  isDetailDialogOpen.value = false
  selectedModule.value = null
  selectedPermission.value = null
  permissionItems.value = []
}

async function hideDetailDialogForAlert() {
  isDetailDialogOpen.value = false
  await nextTick()
}

async function restoreDetailDialogAfterAlert() {
  if (selectedModule.value) {
    isDetailDialogOpen.value = true
    await nextTick()
  }
}

/*
|--------------------------------------------------------------------------
| Pengaturan Approval Flow pada module yang sudah ada
|--------------------------------------------------------------------------
| Dibuat sebagai dialog tersendiri, bukan isian yang menempel di dialog
| Kelola, karena dialog itu sudah penuh oleh daftar permission -- dan karena
| keempat nilai ini berubah jauh lebih jarang daripada permission.
|--------------------------------------------------------------------------
*/
async function openApprovalDialog() {
  if (!selectedModule.value)
    return

  approvalFieldErrors.value = {}

  approvalForm.approval_document_type
    = selectedModule.value.approval_document_type ?? ''

  approvalForm.approval_document_label
    = selectedModule.value.approval_document_label ?? ''

  approvalForm.approval_uses_area_matrix
    = Boolean(selectedModule.value.approval_uses_area_matrix)

  approvalForm.approval_uses_transaction_category
    = Boolean(selectedModule.value.approval_uses_transaction_category)

  isApprovalDialogOpen.value = true

  await nextTick()
}

function closeApprovalDialog() {
  if (isSubmittingApproval.value)
    return

  isApprovalDialogOpen.value = false
  approvalFieldErrors.value = {}
}

async function submitApprovalSettings() {
  if (!selectedModule.value)
    return

  approvalFieldErrors.value = {}

  const moduleData = selectedModule.value

  isSubmittingApproval.value = true

  try {
    showLoadingAlert(
      'Menyimpan Pengaturan Approval Flow',
      'Mohon tunggu sebentar.',
    )

    /*
    | Kolom module lain ikut dikirim apa adanya karena endpoint update
    | mewajibkannya. Yang berubah hanya keempat kolom approval.
    */
    const response = await axiosIns.put(
      `/master/permission-modules/${moduleData.id}`,
      {
        name: moduleData.name,
        description: moduleData.description,
        route_prefix: moduleData.route_prefix,
        sort_order: Number(moduleData.sort_order),
        is_active: moduleData.is_active,

        approval_document_type: approvalForm.approval_document_type,
        approval_document_label: approvalForm.approval_document_label,
        approval_uses_area_matrix: approvalForm.approval_uses_area_matrix,
        approval_uses_transaction_category:
          approvalForm.approval_uses_transaction_category,
      },
      {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    closeAlert()

    selectedModule.value = {
      ...moduleData,
      ...response.data?.data,
    }

    isApprovalDialogOpen.value = false

    showSuccessToast({
      title: 'Berhasil',
      text:
        response.data?.message
        ?? 'Pengaturan Approval Flow berhasil disimpan.',
    })

    await refreshPermissionModuleData()
  }
  catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    if (err.response?.status === 422) {
      approvalFieldErrors.value
        = (err.response?.data?.errors ?? {}) as FieldErrors
    }

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        'Gagal menyimpan pengaturan Approval Flow.',
      ),
    })
  }
  finally {
    isSubmittingApproval.value = false
  }
}

async function refreshPermissionModuleData() {
  await Promise.allSettled([
    refreshModuleDetail(),
    fetchModules(),
  ])
}

async function refreshModuleDetail() {
  if (!selectedModule.value)
    return

  await loadModuleDetail(selectedModule.value.id)
}

async function submitCreateModule() {
  fieldErrors.value = {}

  const validationResult
    = await createFormRef.value?.validate()

  if (!validationResult?.valid)
    return

  isSubmitting.value = true

  try {
    showLoadingAlert(
      'Menyimpan Module',
      'Mohon tunggu sebentar.',
    )

    const response = await axiosIns.post(
      '/master/permission-modules',
      {
        code: createForm.code,
        name: createForm.name,
        description:
          createForm.description.trim() || null,
        route_prefix: createForm.route_prefix,
        sort_order: Number(createForm.sort_order || 0),

        approval_document_type: createForm.approval_document_type,
        approval_document_label: createForm.approval_document_label,
        approval_uses_area_matrix: createForm.approval_uses_area_matrix,
        approval_uses_transaction_category: createForm.approval_uses_transaction_category,
        is_active: false,
      },
      {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    closeAlert()

    isCreateDialogOpen.value = false

    showSuccessToast({
      title: 'Berhasil',
      text:
        response.data?.message
        ?? 'Permission Module berhasil dibuat.',
    })

    resetCreateForm()
    await fetchModules()
  }
  catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    if (err.response?.status === 422) {
      fieldErrors.value
        = err.response.data?.errors ?? {}

      showErrorToast({
        title: 'Validasi Gagal',
        text: getApiErrorMessage(
          err,
          'Periksa kembali data yang diisi.',
        ),
      })

      return
    }

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        'Gagal membuat Permission Module.',
      ),
    })
  }
  finally {
    isSubmitting.value = false
  }
}

async function submitCreatePermission() {
  if (!selectedModule.value)
    return

  permissionFieldErrors.value = {}

  const validationResult
    = await createPermissionFormRef.value?.validate()

  if (!validationResult?.valid)
    return

  isSubmittingPermission.value = true

  try {
    showLoadingAlert(
      'Menyimpan Permission',
      'Mohon tunggu sebentar.',
    )

    const response = await axiosIns.post(
      `/master/permission-modules/${selectedModule.value.id}/permissions`,
      {
        action: createPermissionForm.action,
        name: createPermissionForm.name,
        description:
          createPermissionForm.description.trim() || null,
        is_active: createPermissionForm.is_active,
        requires_scope: createPermissionForm.requires_scope,
      },
      {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    closeAlert()

    isCreatePermissionDialogOpen.value = false

    showSuccessToast({
      title: 'Berhasil',
      text:
        response.data?.message
        ?? 'Permission berhasil dibuat.',
    })

    resetCreatePermissionForm()

    await Promise.all([
      refreshModuleDetail(),
      fetchModules(),
    ])
  }
  catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    if (err.response?.status === 422) {
      permissionFieldErrors.value
        = err.response.data?.errors ?? {}

      showErrorToast({
        title: 'Validasi Gagal',
        text: getApiErrorMessage(
          err,
          'Periksa kembali data permission.',
        ),
      })

      return
    }

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        'Gagal membuat permission.',
      ),
    })
  }
  finally {
    isSubmittingPermission.value = false
  }
}

async function submitUpdatePermission() {
  if (
    !selectedModule.value
    || !selectedPermission.value
  )
    return

  editPermissionFieldErrors.value = {}

  const validationResult
    = await editPermissionFormRef.value?.validate()

  if (!validationResult?.valid)
    return

  isSubmittingEditPermission.value = true

  try {
    showLoadingAlert(
      'Menyimpan Perubahan Permission',
      'Mohon tunggu sebentar.',
    )

    const response = await axiosIns.put(
      `/master/permission-modules/${selectedModule.value.id}/permissions/${selectedPermission.value.id}`,
      {
        action: editPermissionForm.action,
        name: editPermissionForm.name,
        description:
          editPermissionForm.description.trim() || null,
        is_active: editPermissionForm.is_active,
        requires_scope: editPermissionForm.requires_scope,
      },
      {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    closeAlert()

    isEditPermissionDialogOpen.value = false

    showSuccessToast({
      title: 'Berhasil',
      text:
        response.data?.message
        ?? 'Permission berhasil diperbarui.',
    })

    resetEditPermissionForm()

    await Promise.all([
      refreshModuleDetail(),
      fetchModules(),
    ])
  }
  catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    if (err.response?.status === 422) {
      editPermissionFieldErrors.value
        = err.response.data?.errors ?? {}

      showErrorToast({
        title: 'Validasi Gagal',
        text: getApiErrorMessage(
          err,
          'Periksa kembali data permission.',
        ),
      })

      return
    }

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        'Gagal memperbarui permission.',
      ),
    })
  }
  finally {
    isSubmittingEditPermission.value = false
  }
}

async function updateModuleStatus(nextStatus: boolean) {
  if (!selectedModule.value)
    return

  if (isUpdatingModuleStatus.value)
    return

  const moduleData = selectedModule.value

  await hideDetailDialogForAlert()

  const confirm = await showConfirmAlert({
    title: nextStatus
      ? 'Aktifkan Module?'
      : 'Nonaktifkan Module?',
    text: nextStatus
      ? `Module "${moduleData.name}" akan diaktifkan.`
      : `Module "${moduleData.name}" akan dinonaktifkan.`,
    confirmButtonText: nextStatus
      ? 'Ya, Aktifkan'
      : 'Ya, Nonaktifkan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) {
    await restoreDetailDialogAfterAlert()

    return
  }

  isUpdatingModuleStatus.value = true

  try {
    showLoadingAlert(
      nextStatus
        ? 'Mengaktifkan Module'
        : 'Menonaktifkan Module',
      'Mohon tunggu sebentar.',
    )

    const response = await axiosIns.put(
      `/master/permission-modules/${moduleData.id}`,
      {
        name: moduleData.name,
        description: moduleData.description,
        route_prefix: moduleData.route_prefix,
        sort_order: Number(moduleData.sort_order),
        is_active: nextStatus,
      },
      {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    closeAlert()

    selectedModule.value = {
      ...moduleData,
      ...response.data?.data,
    }

    showSuccessToast({
      title: 'Berhasil',
      text:
        response.data?.message
        ?? (
          nextStatus
            ? 'Permission Module berhasil diaktifkan.'
            : 'Permission Module berhasil dinonaktifkan.'
        ),
    })

    await refreshPermissionModuleData()
    await restoreDetailDialogAfterAlert()
  }
  catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        'Gagal memperbarui status Permission Module.',
      ),
    })

    await restoreDetailDialogAfterAlert()
  }
  finally {
    isUpdatingModuleStatus.value = false
  }
}

async function updatePermissionStatus(
  permission: PermissionItem,
  nextStatus: boolean,
) {
  if (!selectedModule.value)
    return

  if (isUpdatingPermissionStatus.value)
    return

  await hideDetailDialogForAlert()

  const confirm = await showConfirmAlert({
    title: nextStatus
      ? 'Aktifkan Permission?'
      : 'Nonaktifkan Permission?',
    text: nextStatus
      ? `Permission "${permission.name}" akan diaktifkan.`
      : `Permission "${permission.name}" akan dinonaktifkan.`,
    confirmButtonText: nextStatus
      ? 'Ya, Aktifkan'
      : 'Ya, Nonaktifkan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) {
    await restoreDetailDialogAfterAlert()

    return
  }

  isUpdatingPermissionStatus.value = true

  try {
    showLoadingAlert(
      nextStatus
        ? 'Mengaktifkan Permission'
        : 'Menonaktifkan Permission',
      'Mohon tunggu sebentar.',
    )

    const moduleId = selectedModule.value.id

    const response = await axiosIns.put(
      `/master/permission-modules/${moduleId}/permissions/${permission.id}`,
      {
        action: permission.action,
        name: permission.name,
        description: permission.description,
        is_active: nextStatus,

        /* Ikut dikirim apa adanya: yang diubah tombol ini hanya statusnya. */
        requires_scope: permission.requires_scope,
      },
      {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text:
        response.data?.message
        ?? (
          nextStatus
            ? 'Permission berhasil diaktifkan.'
            : 'Permission berhasil dinonaktifkan.'
        ),
    })

    await refreshPermissionModuleData()
    await restoreDetailDialogAfterAlert()
  }
  catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        'Gagal memperbarui status permission.',
      ),
    })

    await restoreDetailDialogAfterAlert()
  }
  finally {
    isUpdatingPermissionStatus.value = false
  }
}

async function deletePermission(permission: PermissionItem) {
  if (!selectedModule.value)
    return

  if (isDeletingPermission.value)
    return

  await hideDetailDialogForAlert()

  const confirm = await showConfirmAlert({
    title: 'Hapus Permission?',
    text: `Permission "${permission.name}" akan dihapus. Permission yang sudah digunakan role/user kemungkinan akan ditolak oleh sistem.`,
    confirmButtonText: 'Ya, Hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) {
    await restoreDetailDialogAfterAlert()

    return
  }

  isDeletingPermission.value = true

  try {
    showLoadingAlert(
      'Menghapus Permission',
      'Mohon tunggu sebentar.',
    )

    const moduleId = selectedModule.value.id

    const response = await axiosIns.delete(
      `/master/permission-modules/${moduleId}/permissions/${permission.id}`,
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text:
        response.data?.message
        ?? 'Permission berhasil dihapus.',
    })

    await refreshPermissionModuleData()
    await restoreDetailDialogAfterAlert()
  }
  catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        'Gagal menghapus permission.',
      ),
    })

    await restoreDetailDialogAfterAlert()
  }
  finally {
    isDeletingPermission.value = false
  }
}

watch(search, () => {
  if (searchTimer)
    clearTimeout(searchTimer)

  searchTimer = setTimeout(() => {
    fetchModules()
  }, 500)
})

watch(statusFilter, () => {
  fetchModules()
})

onMounted(() => {
  fetchModules()
})

onBeforeUnmount(() => {
  if (searchTimer)
    clearTimeout(searchTimer)
})
</script>

<template>
  <div>
    <VCard class="mb-6">
      <VCardText>
        <div class="d-flex flex-wrap align-center justify-space-between gap-4">
          <div>
            <h4 class="text-h4 mb-1">
              Permission Modules
            </h4>

            <p class="text-body-1 mb-0 text-medium-emphasis">
              Kelola module, route prefix, dan permission aplikasi.
            </p>
          </div>

          <div class="d-flex align-center gap-3">
            <VBtn
              variant="outlined"
              prepend-icon="tabler-refresh"
              :loading="isLoading"
              class="text-none"
              @click="fetchModules"
            >
              Refresh
            </VBtn>

            <VBtn
              v-permission="'auth_permission_module.create'"
              color="primary"
              prepend-icon="tabler-plus"
              class="text-none"
              @click="openCreateDialog"
            >
              Tambah Module
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VCard>
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="8"
          >
            <VTextField
              v-model="search"
              label="Cari Permission Module"
              placeholder="Cari nama, code, atau route prefix"
              prepend-inner-icon="tabler-search"
              clearable
              hide-details
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <VSelect
              v-model="statusFilter"
              :items="statusOptions"
              item-title="title"
              item-value="value"
              label="Status"
              hide-details
            />
          </VCol>
        </VRow>
      </VCardText>

      <VDivider />

      <VCardText class="py-3">
        <div class="text-body-2 text-medium-emphasis">
          Total data:
          <strong>{{ moduleItems.length }}</strong>
          Permission Module
        </div>
      </VCardText>

      <VDivider />

      <VProgressLinear
        v-if="isLoading"
        indeterminate
        color="primary"
      />

      <div class="overflow-x-auto">
        <VTable class="text-no-wrap">
          <thead>
            <tr>
              <th>Module</th>
              <th>Code</th>
              <th>Route Prefix</th>
              <th class="text-center">
                Permissions
              </th>
              <th class="text-center">
                Urutan
              </th>
              <th class="text-center">
                Status
              </th>
              <th class="text-center">
                Aksi
              </th>
            </tr>
          </thead>

          <tbody>
            <tr v-if="isLoading">
              <td
                colspan="7"
                class="text-center py-8 text-medium-emphasis"
              >
                Memuat Permission Module...
              </td>
            </tr>

            <template v-else>
              <tr
                v-for="item in moduleItems"
                :key="item.id"
              >
                <td>
                  <div class="py-3">
                    <div class="font-weight-medium">
                      {{ item.name }}
                    </div>

                    <div
                      v-if="item.description"
                      class="text-body-2 text-medium-emphasis"
                    >
                      {{ item.description }}
                    </div>
                  </div>
                </td>

                <td>
                  <VChip
                    size="small"
                    variant="tonal"
                  >
                    {{ item.code }}
                  </VChip>
                </td>

                <td>
                  <code>{{ item.route_prefix }}</code>
                </td>

                <td class="text-center">
                  <VChip
                    size="small"
                    variant="tonal"
                    color="primary"
                  >
                    {{ item.active_permissions_count }}
                    /
                    {{ item.permissions_count }}
                  </VChip>
                </td>

                <td class="text-center">
                  {{ item.sort_order }}
                </td>

                <td class="text-center">
                  <VChip
                    size="small"
                    variant="tonal"
                    :color="item.is_active ? 'success' : 'secondary'"
                  >
                    {{ item.is_active ? 'Aktif' : 'Nonaktif' }}
                  </VChip>
                </td>

                <td class="text-center">
                  <VBtn
                    size="small"
                    color="primary"
                    variant="tonal"
                    prepend-icon="tabler-settings"
                    class="text-none"
                    @click="openDetailDialog(item)"
                  >
                    Kelola
                  </VBtn>
                </td>
              </tr>

              <tr v-if="moduleItems.length === 0">
                <td
                  colspan="7"
                  class="text-center py-8 text-medium-emphasis"
                >
                  Belum ada Permission Module.
                </td>
              </tr>
            </template>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VDialog
      v-model="isCreateDialogOpen"
      max-width="760"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between px-6 py-5">
          <div>
            <div class="text-h5 font-weight-medium">
              Tambah Permission Module
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              Daftarkan module baru beserta route utama aplikasi.
            </div>
          </div>

          <VBtn
            icon
            variant="text"
            color="secondary"
            :disabled="isSubmitting"
            @click="closeCreateDialog"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>

        <VDivider />

        <VForm
          ref="createFormRef"
          @submit.prevent="submitCreateModule"
        >
          <VCardText class="pa-6">
            <VAlert
              type="info"
              variant="tonal"
              density="comfortable"
              class="mb-6"
            >
              Module baru akan dibuat dalam kondisi nonaktif.
              Buat permission
              <strong>module.view</strong>
              terlebih dahulu sebelum mengaktifkan module.
            </VAlert>

            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="createForm.name"
                  label="Nama Module"
                  placeholder="Contoh: Master Customer"
                  :rules="[requiredRule]"
                  :error-messages="fieldErrors.name"
                  maxlength="150"
                  hide-details="auto"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="createForm.code"
                  label="Code Module"
                  placeholder="Contoh: master_customer"
                  :rules="[requiredRule]"
                  :error-messages="fieldErrors.code"
                  :hint="
                    moduleCodePreview
                      ? `Akan disimpan sebagai: ${moduleCodePreview}`
                      : 'Gunakan nama unik untuk module.'
                  "
                  persistent-hint
                  maxlength="100"
                />
              </VCol>

              <VCol cols="12">
                <VTextarea
                  v-model="createForm.description"
                  label="Deskripsi"
                  placeholder="Jelaskan fungsi dan cakupan module ini"
                  :error-messages="fieldErrors.description"
                  rows="3"
                  auto-grow
                  hide-details="auto"
                />
              </VCol>

              <VCol
                cols="12"
                md="8"
              >
                <VTextField
                  v-model="createForm.route_prefix"
                  label="Route Prefix"
                  placeholder="Contoh: /master/customers"
                  prepend-inner-icon="tabler-route"
                  :rules="[requiredRule]"
                  :error-messages="fieldErrors.route_prefix"
                  :hint="
                    routePrefixPreview
                      ? `Akan disimpan sebagai: ${routePrefixPreview}`
                      : 'Route harus sesuai dengan URL halaman frontend.'
                  "
                  persistent-hint
                  maxlength="255"
                />
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <VTextField
                  v-model.number="createForm.sort_order"
                  label="Urutan"
                  type="number"
                  min="0"
                  max="999999"
                  :error-messages="fieldErrors.sort_order"
                  hint="Semakin kecil akan tampil lebih dahulu."
                  persistent-hint
                />
              </VCol>

              <!--
                Pendaftaran ke Approval Flow.

                Sebelumnya keempat kolom ini hanya bisa diisi lewat seeder,
                sehingga module yang dibuat dari layar ini tersimpan rapi
                lalu tidak pernah muncul di Approval Flow -- tanpa ada
                keterangan apa pun yang menjelaskan kenapa.
              -->
              <VCol cols="12">
                <VDivider class="mb-4" />

                <div class="text-subtitle-1 font-weight-medium">
                  Approval Flow
                </div>

                <div class="text-body-2 text-medium-emphasis mb-4">
                  Isi bila module ini punya alur persetujuan. Dikosongkan
                  berarti module tidak muncul di layar Approval Flow.
                </div>
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="createForm.approval_document_type"
                  label="Jenis Dokumen"
                  :error-messages="fieldErrors.approval_document_type"
                  hint="Contoh: FPU, CLAIM, BDV. Otomatis disimpan huruf besar."
                  persistent-hint
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="createForm.approval_document_label"
                  label="Label Dokumen"
                  :disabled="!createForm.approval_document_type"
                  :error-messages="fieldErrors.approval_document_label"
                  hint="Yang dibaca orang di layar Approval Flow. Kosong akan memakai nama module."
                  persistent-hint
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <!--
                  Dimatikan selagi jenis dokumen kosong: tanpa jenis dokumen,
                  kedua setelan ini tidak pernah terbaca oleh siapa pun.
                -->
                <VSwitch
                  v-model="createForm.approval_uses_area_matrix"
                  :disabled="!createForm.approval_document_type"
                  label="Pakai Kondisi Matrix"
                  hint="Alurnya wajib mengisi area type dan department. Dimatikan berarti alur berlaku global."
                  persistent-hint
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VSwitch
                  v-model="createForm.approval_uses_transaction_category"
                  :disabled="!createForm.approval_document_type"
                  label="Pakai Keterangan Transaksi"
                  hint="Alurnya bisa dibatasi keterangan transaksi tertentu."
                  persistent-hint
                />
              </VCol>
            </VRow>
          </VCardText>

          <VDivider />

          <VCardActions class="justify-end gap-3 px-6 py-4">
            <VBtn
              variant="outlined"
              color="secondary"
              :disabled="isSubmitting"
              @click="closeCreateDialog"
            >
              Batal
            </VBtn>

            <VBtn
              v-permission="'auth_permission_module.create'"
              type="submit"
              color="primary"
              prepend-icon="tabler-device-floppy"
              :loading="isSubmitting"
            >
              Simpan Module
            </VBtn>
          </VCardActions>
        </VForm>
      </VCard>
    </VDialog>

    <VDialog
      v-model="isDetailDialogOpen"
      max-width="1120"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between px-6 py-5">
          <div>
            <div class="text-h5 font-weight-medium">
              Kelola Permission Module
            </div>

            <div
              v-if="selectedModule"
              class="text-body-2 text-medium-emphasis mt-1"
            >
              {{ selectedModule.name }}
            </div>
          </div>

          <VBtn
            icon
            variant="text"
            color="secondary"
            :disabled="
              isLoadingDetail
                || isUpdatingModuleStatus
                || isSubmittingPermission
                || isSubmittingEditPermission
                || isUpdatingPermissionStatus
                || isDeletingPermission
            "
            @click="closeDetailDialog"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>

        <VProgressLinear
          v-if="isLoadingDetail"
          indeterminate
          color="primary"
        />

        <VDivider />

        <VCardText
          v-if="selectedModule"
          class="pa-6"
        >
          <VRow class="mb-2">
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
                  <div class="text-caption mb-2">
                    Code Module
                  </div>

                  <div class="font-weight-medium">
                    <code>{{ selectedModule.code }}</code>
                  </div>
                </VCardText>
              </VCard>
            </VCol>

            <VCol
              cols="12"
              md="5"
            >
              <VCard
                variant="tonal"
                class="h-100"
              >
                <VCardText>
                  <div class="text-caption mb-2">
                    Route Prefix
                  </div>

                  <div class="font-weight-medium">
                    <code>{{ selectedModule.route_prefix }}</code>
                  </div>
                </VCardText>
              </VCard>
            </VCol>

            <VCol
              cols="12"
              md="3"
            >
              <VCard
                variant="tonal"
                class="h-100"
              >
                <VCardText>
                  <div class="text-caption mb-2">
                    Status Module
                  </div>

                  <VChip
                    size="small"
                    variant="tonal"
                    :color="
                      selectedModule.is_active
                        ? 'success'
                        : 'secondary'
                    "
                  >
                    {{
                      selectedModule.is_active
                        ? 'Aktif'
                        : 'Nonaktif'
                    }}
                  </VChip>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>

          <VAlert
            v-if="selectedModule.description"
            type="info"
            variant="tonal"
            class="mb-5"
          >
            {{ selectedModule.description }}
          </VAlert>

          <VAlert
            v-if="
              !selectedModule.is_active
                && !hasActiveViewPermission
            "
            type="warning"
            variant="tonal"
            density="comfortable"
            class="mb-5"
          >
            Module baru dapat diaktifkan setelah permission
            <strong>{{ selectedModule.code }}.view</strong>
            tersedia dan berstatus aktif.
          </VAlert>

          <VCard
            variant="outlined"
            class="mb-5"
          >
            <VCardText>
              <div class="d-flex flex-wrap align-center justify-space-between gap-4">
                <div>
                  <div class="text-subtitle-1 font-weight-medium mb-1">
                    Approval Flow
                  </div>

                  <div
                    v-if="selectedModule.approval_document_type"
                    class="text-body-2"
                  >
                    Terdaftar sebagai jenis dokumen
                    <code>{{ selectedModule.approval_document_type }}</code>
                    dengan label
                    <strong>{{ selectedModule.approval_document_label }}</strong>.
                  </div>

                  <div
                    v-else
                    class="text-body-2 text-medium-emphasis"
                  >
                    Module ini belum terdaftar, sehingga tidak muncul di layar
                    Approval Flow.
                  </div>

                  <div
                    v-if="selectedModule.approval_document_type"
                    class="d-flex flex-wrap gap-2 mt-3"
                  >
                    <VChip
                      size="small"
                      variant="tonal"
                      :color="
                        selectedModule.approval_uses_area_matrix
                          ? 'primary'
                          : 'secondary'
                      "
                    >
                      {{
                        selectedModule.approval_uses_area_matrix
                          ? 'Pakai Kondisi Matrix'
                          : 'Berlaku Global'
                      }}
                    </VChip>

                    <VChip
                      v-if="selectedModule.approval_uses_transaction_category"
                      size="small"
                      variant="tonal"
                      color="primary"
                    >
                      Pakai Keterangan Transaksi
                    </VChip>
                  </div>
                </div>

                <VBtn
                  v-permission="'auth_permission_module.update'"
                  variant="tonal"
                  color="primary"
                  prepend-icon="tabler-settings"
                  :disabled="
                    isLoadingDetail
                      || isUpdatingModuleStatus
                  "
                  @click="openApprovalDialog"
                >
                  Atur Approval Flow
                </VBtn>
              </div>
            </VCardText>
          </VCard>

          <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
            <div>
              <h5 class="text-h5">
                Daftar Permission
              </h5>

              <div class="text-body-2 text-medium-emphasis">
                Permission yang terdaftar pada module
                <code>{{ selectedModule.code }}</code>.
              </div>
            </div>

            <div class="d-flex flex-wrap align-center gap-3">
              <VBtn
                v-permission="'auth_permission_module.update'"
                class="text-none"
                :color="
                  selectedModule.is_active
                    ? 'warning'
                    : 'success'
                "
                variant="tonal"
                :prepend-icon="
                  selectedModule.is_active
                    ? 'tabler-player-pause'
                    : 'tabler-player-play'
                "
                :loading="isUpdatingModuleStatus"
                :disabled="
                  isLoadingDetail
                    || isSubmittingPermission
                    || isSubmittingEditPermission
                    || isUpdatingPermissionStatus
                    || isDeletingPermission
                "
                @click="
                  updateModuleStatus(
                    !selectedModule.is_active,
                  )
                "
              >
                {{
                  selectedModule.is_active
                    ? 'Nonaktifkan Module'
                    : 'Aktifkan Module'
                }}
              </VBtn>

              <VBtn
                v-permission="'auth_permission_module.create'"
                color="primary"
                prepend-icon="tabler-plus"
                :disabled="
                  isLoadingDetail
                    || isUpdatingModuleStatus
                    || isSubmittingEditPermission
                    || isUpdatingPermissionStatus
                    || isDeletingPermission
                "
                class="text-none"
                @click="openCreatePermissionDialog"
              >
                Tambah Permission
              </VBtn>

              <VBtn
                variant="outlined"
                prepend-icon="tabler-refresh"
                :loading="isLoadingDetail"
                :disabled="
                  isUpdatingModuleStatus
                    || isSubmittingPermission
                    || isSubmittingEditPermission
                    || isUpdatingPermissionStatus
                    || isDeletingPermission
                "
                class="text-none"
                @click="refreshModuleDetail"
              >
                Refresh
              </VBtn>
            </div>
          </div>

          <VDivider />

          <VProgressLinear
            v-if="isLoadingDetail"
            indeterminate
            color="primary"
          />

          <div class="overflow-x-auto">
            <VTable class="text-no-wrap">
              <thead>
                <tr>
                  <th>Permission</th>
                  <th>Action</th>
                  <th>Code</th>
                  <th class="text-center">
                    Status
                  </th>
                  <th class="text-center">
                    Aksi
                  </th>
                </tr>
              </thead>

              <tbody>
                <tr v-if="isLoadingDetail">
                  <td
                    colspan="5"
                    class="text-center py-8 text-medium-emphasis"
                  >
                    Memuat permission...
                  </td>
                </tr>

                <template v-else>
                  <tr
                    v-for="permission in permissionItems"
                    :key="permission.id"
                  >
                    <td>
                      <div class="py-3">
                        <div class="font-weight-medium">
                          {{ permission.name }}
                        </div>

                        <div
                          v-if="permission.description"
                          class="text-body-2 text-medium-emphasis"
                        >
                          {{ permission.description }}
                        </div>
                      </div>
                    </td>

                    <td>
                      <VChip
                        size="small"
                        variant="tonal"
                      >
                        {{ permission.action }}
                      </VChip>
                    </td>

                    <td>
                      <code>{{ permission.code }}</code>
                    </td>

                    <td class="text-center">
                      <VChip
                        size="small"
                        variant="tonal"
                        :color="
                          permission.is_active
                            ? 'success'
                            : 'secondary'
                        "
                      >
                        {{
                          permission.is_active
                            ? 'Aktif'
                            : 'Nonaktif'
                        }}
                      </VChip>
                    </td>

                    <td class="text-center">
                      <div class="d-flex justify-center gap-2">
                        <VBtn
                          v-permission="'auth_permission_module.update'"
                          icon
                          size="small"
                          color="primary"
                          variant="tonal"
                          :disabled="
                            isLoadingDetail
                              || isUpdatingModuleStatus
                              || isSubmittingPermission
                              || isSubmittingEditPermission
                              || isUpdatingPermissionStatus
                              || isDeletingPermission
                          "
                          @click="openEditPermissionDialog(permission)"
                        >
                          <VIcon icon="tabler-edit" />
                          <VTooltip
                            activator="parent"
                            location="top"
                          >
                            Edit Permission
                          </VTooltip>
                        </VBtn>

                        <VBtn
                          v-permission="'auth_permission_module.update'"
                          icon
                          size="small"
                          :color="
                            permission.is_active
                              ? 'warning'
                              : 'success'
                          "
                          variant="tonal"
                          :loading="isUpdatingPermissionStatus"
                          :disabled="
                            isLoadingDetail
                              || isUpdatingModuleStatus
                              || isSubmittingPermission
                              || isSubmittingEditPermission
                              || isDeletingPermission
                          "
                          @click="
                            updatePermissionStatus(
                              permission,
                              !permission.is_active,
                            )
                          "
                        >
                          <VIcon
                            :icon="
                              permission.is_active
                                ? 'tabler-eye-off'
                                : 'tabler-eye'
                            "
                          />
                          <VTooltip
                            activator="parent"
                            location="top"
                          >
                            {{
                              permission.is_active
                                ? 'Nonaktifkan Permission'
                                : 'Aktifkan Permission'
                            }}
                          </VTooltip>
                        </VBtn>

                        <VBtn
                          v-permission="'auth_permission_module.delete'"
                          icon
                          size="small"
                          color="error"
                          variant="tonal"
                          :loading="isDeletingPermission"
                          :disabled="
                            isLoadingDetail
                              || isUpdatingModuleStatus
                              || isSubmittingPermission
                              || isSubmittingEditPermission
                              || isUpdatingPermissionStatus
                          "
                          @click="deletePermission(permission)"
                        >
                          <VIcon icon="tabler-trash" />
                          <VTooltip
                            activator="parent"
                            location="top"
                          >
                            Hapus Permission
                          </VTooltip>
                        </VBtn>
                      </div>
                    </td>
                  </tr>

                  <tr v-if="permissionItems.length === 0">
                    <td
                      colspan="5"
                      class="text-center py-8 text-medium-emphasis"
                    >
                      Module ini belum mempunyai permission.
                    </td>
                  </tr>
                </template>
              </tbody>
            </VTable>
          </div>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end px-6 py-4">
          <VBtn
            variant="outlined"
            :disabled="
              isLoadingDetail
                || isUpdatingModuleStatus
                || isSubmittingPermission
                || isSubmittingEditPermission
                || isUpdatingPermissionStatus
                || isDeletingPermission
            "
            @click="closeDetailDialog"
          >
            Tutup
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="isCreatePermissionDialogOpen"
      max-width="700"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between px-6 py-5">
          <div>
            <div class="text-h5 font-weight-medium">
              Tambah Permission
            </div>

            <div
              v-if="selectedModule"
              class="text-body-2 text-medium-emphasis mt-1"
            >
              Module:
              <code>{{ selectedModule.code }}</code>
            </div>
          </div>

          <VBtn
            icon
            variant="text"
            color="secondary"
            :disabled="isSubmittingPermission"
            @click="closeCreatePermissionDialog"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>

        <VDivider />

        <VForm
          ref="createPermissionFormRef"
          @submit.prevent="submitCreatePermission"
        >
          <VCardText class="pa-6">
            <VAlert
              v-if="
                selectedModule
                  && !permissionItems.some(
                    permission => permission.action === 'view',
                  )
              "
              type="info"
              variant="tonal"
              density="comfortable"
              class="mb-6"
            >
              Buat permission
              <strong>{{ selectedModule.code }}.view</strong>
              terlebih dahulu agar module dapat diaktifkan.
            </VAlert>

            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="createPermissionForm.action"
                  label="Action"
                  placeholder="Contoh: view"
                  :rules="[requiredRule]"
                  :error-messages="permissionFieldErrors.action"
                  :hint="`Code permission: ${createPermissionCodePreview}`"
                  persistent-hint
                  maxlength="50"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="createPermissionForm.name"
                  label="Nama Permission"
                  placeholder="Contoh: Lihat Master Customer"
                  :rules="[requiredRule]"
                  :error-messages="permissionFieldErrors.name"
                  maxlength="150"
                  hide-details="auto"
                />
              </VCol>

              <VCol cols="12">
                <VTextarea
                  v-model="createPermissionForm.description"
                  label="Deskripsi"
                  placeholder="Jelaskan akses yang diberikan permission ini"
                  :error-messages="permissionFieldErrors.description"
                  rows="3"
                  auto-grow
                  hide-details="auto"
                />
              </VCol>

              <VCol cols="12">
                <VSwitch
                  v-model="createPermissionForm.is_active"
                  label="Aktifkan permission setelah dibuat"
                  color="success"
                  inset
                  :error-messages="permissionFieldErrors.is_active"
                  hide-details="auto"
                />
              </VCol>

              <VCol cols="12">
                <VSwitch
                  v-model="createPermissionForm.requires_scope"
                  label="Requires Scope (permission ini bisa punya scope per-role)"
                  color="primary"
                  inset
                  hide-details="auto"
                />
              </VCol>
            </VRow>
          </VCardText>

          <VDivider />

          <VCardActions class="justify-end gap-3 px-6 py-4">
            <VBtn
              variant="outlined"
              color="secondary"
              :disabled="isSubmittingPermission"
              @click="closeCreatePermissionDialog"
            >
              Batal
            </VBtn>

            <VBtn
              v-permission="'auth_permission_module.create'"
              type="submit"
              color="primary"
              prepend-icon="tabler-device-floppy"
              :loading="isSubmittingPermission"
            >
              Simpan Permission
            </VBtn>
          </VCardActions>
        </VForm>
      </VCard>
    </VDialog>

    <VDialog
      v-model="isEditPermissionDialogOpen"
      max-width="700"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between px-6 py-5">
          <div>
            <div class="text-h5 font-weight-medium">
              Edit Permission
            </div>

            <div
              v-if="selectedModule"
              class="text-body-2 text-medium-emphasis mt-1"
            >
              Module:
              <code>{{ selectedModule.code }}</code>
            </div>
          </div>

          <VBtn
            icon
            variant="text"
            color="secondary"
            :disabled="isSubmittingEditPermission"
            @click="closeEditPermissionDialog"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>

        <VDivider />

        <VForm
          ref="editPermissionFormRef"
          @submit.prevent="submitUpdatePermission"
        >
          <VCardText class="pa-6">
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="editPermissionForm.action"
                  label="Action"
                  placeholder="Contoh: view"
                  :rules="[requiredRule]"
                  :error-messages="editPermissionFieldErrors.action"
                  :hint="`Code permission: ${editPermissionCodePreview}`"
                  persistent-hint
                  maxlength="50"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="editPermissionForm.name"
                  label="Nama Permission"
                  placeholder="Contoh: Lihat Master Customer"
                  :rules="[requiredRule]"
                  :error-messages="editPermissionFieldErrors.name"
                  maxlength="150"
                  hide-details="auto"
                />
              </VCol>

              <VCol cols="12">
                <VTextarea
                  v-model="editPermissionForm.description"
                  label="Deskripsi"
                  placeholder="Jelaskan akses yang diberikan permission ini"
                  :error-messages="editPermissionFieldErrors.description"
                  rows="3"
                  auto-grow
                  hide-details="auto"
                />
              </VCol>

              <VCol cols="12">
                <VSwitch
                  v-model="editPermissionForm.is_active"
                  label="Permission aktif"
                  color="success"
                  inset
                  :error-messages="editPermissionFieldErrors.is_active"
                  hide-details="auto"
                />
              </VCol>

              <VCol cols="12">
                <VSwitch
                  v-model="editPermissionForm.requires_scope"
                  label="Requires Scope (permission ini bisa punya scope per-role)"
                  color="primary"
                  inset
                  hide-details="auto"
                />
              </VCol>
            </VRow>
          </VCardText>

          <VDivider />

          <VCardActions class="justify-end gap-3 px-6 py-4">
            <VBtn
              variant="outlined"
              color="secondary"
              :disabled="isSubmittingEditPermission"
              @click="closeEditPermissionDialog"
            >
              Batal
            </VBtn>

            <VBtn
              v-permission="'auth_permission_module.update'"
              type="submit"
              color="primary"
              prepend-icon="tabler-device-floppy"
              :loading="isSubmittingEditPermission"
            >
              Simpan Perubahan
            </VBtn>
          </VCardActions>
        </VForm>
      </VCard>
    </VDialog>

    <VDialog
      v-model="isApprovalDialogOpen"
      max-width="620"
      persistent
    >
      <VCard v-if="selectedModule">
        <VCardText class="d-flex align-center justify-space-between pa-6 pb-4">
          <div>
            <h5 class="text-h5">
              Pengaturan Approval Flow
            </h5>

            <div class="text-body-2 text-medium-emphasis">
              Module <code>{{ selectedModule.code }}</code>
            </div>
          </div>

          <VBtn
            icon
            variant="text"
            size="small"
            :disabled="isSubmittingApproval"
            @click="closeApprovalDialog"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardText>

        <VDivider />

        <VForm
          ref="approvalFormRef"
          @submit.prevent="submitApprovalSettings"
        >
          <VCardText class="pa-6">
            <!--
              Catatan ini sengaja ada. Mendaftarkan jenis dokumen di sini
              hanya membuat module muncul di layar Approval Flow; yang
              membentuk baris approval saat dokumen diajukan tetap kode
              module itu sendiri. Tanpa keterangan ini, orang bisa mengisi
              kolom di bawah lalu menunggu approval yang tidak akan pernah
              terbentuk.
            -->
            <VAlert
              type="info"
              variant="tonal"
              density="comfortable"
              class="mb-5"
            >
              Pengaturan ini menentukan module muncul atau tidak di layar
              Approval Flow, beserta aturan pengisian alurnya. Pembentukan
              baris approval saat dokumen diajukan tetap mengikuti kode
              module yang bersangkutan.
            </VAlert>

            <VRow>
              <VCol cols="12">
                <VTextField
                  v-model="approvalForm.approval_document_type"
                  label="Jenis Dokumen"
                  :error-messages="approvalFieldErrors.approval_document_type"
                  hint="Contoh: FPU, CLAIM, BDV. Dikosongkan akan melepas module ini dari Approval Flow."
                  persistent-hint
                />
              </VCol>

              <VCol cols="12">
                <VTextField
                  v-model="approvalForm.approval_document_label"
                  label="Label Dokumen"
                  :disabled="!approvalForm.approval_document_type"
                  :error-messages="approvalFieldErrors.approval_document_label"
                  hint="Yang dibaca orang di layar Approval Flow. Kosong akan memakai nama module."
                  persistent-hint
                />
              </VCol>

              <VCol cols="12">
                <VSwitch
                  v-model="approvalForm.approval_uses_area_matrix"
                  :disabled="!approvalForm.approval_document_type"
                  label="Pakai Kondisi Matrix"
                  hint="Alurnya wajib mengisi area type dan department. Dimatikan berarti alur berlaku global."
                  persistent-hint
                />
              </VCol>

              <VCol cols="12">
                <VSwitch
                  v-model="approvalForm.approval_uses_transaction_category"
                  :disabled="!approvalForm.approval_document_type"
                  label="Pakai Keterangan Transaksi"
                  hint="Alurnya bisa dibatasi keterangan transaksi tertentu."
                  persistent-hint
                />
              </VCol>

              <!--
                Peringatan saat pendaftaran dicabut: alur yang terlanjur dibuat
                tidak ikut terhapus, tetapi berhenti terbaca.
              -->
              <VCol
                v-if="
                  selectedModule.approval_document_type
                    && !approvalForm.approval_document_type
                "
                cols="12"
              >
                <VAlert
                  type="warning"
                  variant="tonal"
                  density="comfortable"
                >
                  Jenis dokumen dikosongkan. Module ini akan hilang dari layar
                  Approval Flow, dan alur yang sudah dibuat untuknya berhenti
                  terbaca walaupun datanya tetap tersimpan.
                </VAlert>
              </VCol>
            </VRow>
          </VCardText>

          <VDivider />

          <VCardActions class="justify-end gap-3 px-6 py-4">
            <VBtn
              variant="outlined"
              color="secondary"
              :disabled="isSubmittingApproval"
              @click="closeApprovalDialog"
            >
              Batal
            </VBtn>

            <VBtn
              v-permission="'auth_permission_module.update'"
              type="submit"
              color="primary"
              prepend-icon="tabler-device-floppy"
              :loading="isSubmittingApproval"
            >
              Simpan
            </VBtn>
          </VCardActions>
        </VForm>
      </VCard>
    </VDialog>
  </div>
</template>
