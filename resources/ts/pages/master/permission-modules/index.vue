<script setup lang="ts">
import axiosIns from '@/plugins/axios'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import {
  computed,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
  nextTick
} from 'vue'

interface PermissionModuleItem {
  id: number
  code: string
  name: string
  description: string | null
  route_prefix: string
  sort_order: number
  is_active: boolean
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
  created_at: string | null
  updated_at: string | null
}

interface CreateModuleForm {
  code: string
  name: string
  description: string
  route_prefix: string
  sort_order: number
}

interface PermissionForm {
  action: string
  name: string
  description: string
  is_active: boolean
}

interface FieldErrors {
  code?: string[]
  name?: string[]
  description?: string[]
  route_prefix?: string[]
  sort_order?: string[]
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

const isCreateDialogOpen = ref(false)
const isDetailDialogOpen = ref(false)
const isCreatePermissionDialogOpen = ref(false)
const isEditPermissionDialogOpen = ref(false)

const search = ref('')
const statusFilter = ref<'all' | '1' | '0'>('all')

const createFormRef = ref()
const createPermissionFormRef = ref()
const editPermissionFormRef = ref()

const createForm = reactive<CreateModuleForm>({
  code: '',
  name: '',
  description: '',
  route_prefix: '',
  sort_order: 0,
})

const createPermissionForm = reactive<PermissionForm>({
  action: '',
  name: '',
  description: '',
  is_active: true,
})

const editPermissionForm = reactive<PermissionForm>({
  action: '',
  name: '',
  description: '',
  is_active: true,
})

const fieldErrors = ref<FieldErrors>({})
const permissionFieldErrors = ref<PermissionFieldErrors>({})
const editPermissionFieldErrors = ref<PermissionFieldErrors>({})

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
  ) {
    return 'Field ini wajib diisi.'
  }

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

  permissionFieldErrors.value = {}
  createPermissionFormRef.value?.resetValidation()
}

function resetEditPermissionForm() {
  editPermissionForm.action = ''
  editPermissionForm.name = ''
  editPermissionForm.description = ''
  editPermissionForm.is_active = true

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

    if (Array.isArray(responseData)) {
      moduleItems.value = responseData
    }
    else if (Array.isArray(responseData?.modules)) {
      moduleItems.value = responseData.modules
    }
    else if (Array.isArray(responseData?.data)) {
      moduleItems.value = responseData.data
    }
    else if (Array.isArray(payload)) {
      moduleItems.value = payload
    }
    else {
      moduleItems.value = []
    }
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
  ) {
    return
  }

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
        is_active: false,
      },
      {
        headers: {
          Accept: 'application/json',
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
      },
      {
        headers: {
          Accept: 'application/json',
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
  ) {
    return
  }

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
      },
      {
        headers: {
          Accept: 'application/json',
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
          Accept: 'application/json',
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
      },
      {
        headers: {
          Accept: 'application/json',
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
              @click="fetchModules"
              class="text-none"
            >
              Refresh
            </VBtn>

            <VBtn
              v-permission="'auth_permission_module.create'"
              color="primary"
              prepend-icon="tabler-plus"
              @click="openCreateDialog"
              class="text-none"
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
                    @click="openDetailDialog(item)"
                    class="text-none"
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
                class="text-none"
                v-permission="'auth_permission_module.update'"
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
                @click="openCreatePermissionDialog"
                class="text-none"
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
                @click="refreshModuleDetail"
                class="text-none"
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
  </div>
</template>