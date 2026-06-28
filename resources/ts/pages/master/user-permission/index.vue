<script setup lang="ts">
import axios from '@axios'
import {
  computed,
  onMounted,
  ref,
  watch,
} from 'vue'

import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
} from '@/utils/alert'

import { getApiErrorMessage } from '@/utils/apiHelper'
import { VAutocomplete } from 'vuetify/components'

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

type PermissionScope =
  | 'NONE'
  | 'OWN_DATA'
  | 'OWN_DEPARTMENT'
  | 'OWN_CABANG'
  | 'ASSIGNED_DEPARTMENTS'
  | 'ALL'

interface UserOption {
  id: number
  name: string
  username: string
  email: string
  department: string

  /*
   * Digunakan oleh pencarian VAutocomplete.
   * Tidak harus ditampilkan mentah di dropdown.
   */
  search_text: string
}

interface DepartmentOption {
  id: number
  kode?: string | null
  nama: string
  title: string
}

interface PermissionItem {
  id: number
  module: string
  action: string
  code: string
  name: string
  description?: string | null
  is_active: boolean
}

interface UserPermissionItem {
  id?: number
  user_id?: number
  permission_id: number
  scope: PermissionScope
  is_active: boolean
  department_ids: number[]
  permission?: PermissionItem | null
}

interface PermissionFormRow {
  permission_id: number
  module: string
  action: string
  code: string
  name: string
  description?: string | null
  is_permission_active: boolean

  is_checked: boolean
  scope: PermissionScope
  department_ids: number[]
}

interface UserPermissionPayloadItem {
  permission_id: number
  is_active: boolean
  is_allowed: boolean
  scope: PermissionScope
  department_ids: number[]
}

interface BulkUserPermissionPayload {
  user_id: number
  permissions: UserPermissionPayloadItem[]
}

const UserAutocomplete = VAutocomplete as any
const MultiAutocomplete = VAutocomplete as any

/*
|--------------------------------------------------------------------------
| Loading state
|--------------------------------------------------------------------------
*/
const isSubmitting = ref(false)
const isLoading = ref(false)
const isLoadingUser = ref(false)
const isLoadingPermission = ref(false)
const isLoadingDepartment = ref(false)
const isLoadingUserPermission = ref(false)

/*
|--------------------------------------------------------------------------
| Selection and filters
|--------------------------------------------------------------------------
*/

const selectedUserId = ref<number | null>(null)
const keyword = ref('')
const selectedModule = ref('all')

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/

const userOptions = ref<UserOption[]>([])
const departmentOptions = ref<DepartmentOption[]>([])
const permissions = ref<PermissionItem[]>([])
const permissionRows = ref<PermissionFormRow[]>([])
const initialSnapshot = ref('')

/*
|--------------------------------------------------------------------------
| Scope options
|--------------------------------------------------------------------------
*/

const scopeOptions: Array<{
  title: string
  value: PermissionScope
}> = [
  {
    title: 'No Scope',
    value: 'NONE',
  },
  {
    title: 'Own Data',
    value: 'OWN_DATA',
  },
  {
    title: 'Own Department',
    value: 'OWN_DEPARTMENT',
  },
  {
    title: 'Own Cabang',
    value: 'OWN_CABANG',
  },
  {
    title: 'Assigned Departments',
    value: 'ASSIGNED_DEPARTMENTS',
  },
  {
    title: 'All Data',
    value: 'ALL',
  },
]

/*
|--------------------------------------------------------------------------
| Normalizers
|--------------------------------------------------------------------------
*/

const normalizeBoolean = (
  value: unknown,
): boolean => {
  return value === true
    || value === 1
    || value === '1'
    || String(value).toLowerCase() === 'true'
}

const normalizeArrayPayload = (
  payload: any,
): any[] => {
  const rawItems
    = payload?.data?.data
      ?? payload?.data
      ?? payload
      ?? []

  return Array.isArray(rawItems)
    ? rawItems
    : []
}

const normalizeScope = (
  value: unknown,
): PermissionScope => {
  const scope = String(value || 'NONE')
    .trim()
    .toUpperCase()

  const allowedScopes: PermissionScope[] = [
    'NONE',
    'OWN_DATA',
    'OWN_DEPARTMENT',
    'OWN_CABANG',
    'ASSIGNED_DEPARTMENTS',
    'ALL',
  ]

  return allowedScopes.includes(
    scope as PermissionScope,
  )
    ? scope as PermissionScope
    : 'NONE'
}

const normalizeUserItems = (
  payload: any,
): UserOption[] => {
  return normalizeArrayPayload(payload)
    .map((item: any) => {
      const id = Number(
        item.id
        ?? item.value
        ?? 0,
      )

      const name = String(
        item.name
        ?? item.nama
        ?? item.label
        ?? '-',
      ).trim()

      const username = String(
        item.username
        ?? item.user_name
        ?? '',
      ).trim()

      const email = String(
        item.email
        ?? item.user_email
        ?? '',
      ).trim()

      const department = String(
        item.department
        ?? item.department_name
        ?? item.nama_department
        ?? '',
      ).trim()

      /*
       * Seluruh field ini dapat dicari.
       * Misalnya user mengetik:
       * - nama
       * - username
       * - email
       * - department
       */
      const searchText = [
        name,
        username,
        email,
        department,
      ]
        .filter(Boolean)
        .join(' ')

      return {
        id,
        name,
        username,
        email,
        department,
        search_text: searchText,
      }
    })
    .filter(item => item.id > 0)
}

const normalizeDepartmentItems = (
  payload: any,
): DepartmentOption[] => {
  return normalizeArrayPayload(payload)
    .map((item: any) => {
      const id = Number(
        item.id
        ?? item.value
        ?? 0,
      )

      const kode = item.kode
        ? String(item.kode)
        : null

      const nama = String(
        item.nama
        ?? item.name
        ?? item.nama_department
        ?? item.label
        ?? '-',
      )

      return {
        id,
        kode,
        nama,
        title: kode
          ? `${kode} - ${nama}`
          : nama,
      }
    })
    .filter(item => item.id > 0)
}

const normalizePermissionItems = (
  payload: any,
): PermissionItem[] => {
  return normalizeArrayPayload(payload)
    .map((item: any) => ({
      id: Number(item.id ?? 0),

      module: String(
        item.module ?? '',
      ),

      action: String(
        item.action ?? '',
      ),

      code: String(
        item.code ?? '',
      ),

      name: String(
        item.name
        ?? item.code
        ?? '-',
      ),

      description:
        item.description
        ?? null,

      is_active: normalizeBoolean(
        item.is_active ?? true,
      ),
    }))
    .filter(item => item.id > 0)
}

const normalizeUserPermissionItems = (
  payload: any,
): UserPermissionItem[] => {
  const rawItems
    = payload?.data?.permissions
      ?? payload?.permissions
      ?? payload?.data
      ?? []

  if (!Array.isArray(rawItems))
    return []

  return rawItems
    .map((item: any) => ({
      id: item.id
        ? Number(item.id)
        : undefined,

      user_id: item.user_id
        ? Number(item.user_id)
        : undefined,

      permission_id: Number(
        item.permission_id
        ?? item.permission?.id
        ?? 0,
      ),

      scope: normalizeScope(
        item.scope,
      ),

      is_active: normalizeBoolean(
        item.is_active ?? true,
      ),

      department_ids: Array.isArray(
        item.department_ids,
      )
        ? item.department_ids
            .map((id: unknown) => Number(id))
            .filter((id: number) => id > 0)
        : [],

      permission:
        item.permission
        ?? null,
    }))
    .filter(item => item.permission_id > 0)
}

/*
|--------------------------------------------------------------------------
| Permission rules
|--------------------------------------------------------------------------
*/

const supportsScope = (
  permission: PermissionItem | PermissionFormRow,
): boolean => {
  const action = String(
    permission.action || '',
  ).toLowerCase()

  /*
  |--------------------------------------------------------------------------
  | Permission VIEW existing menggunakan scope
  |--------------------------------------------------------------------------
  */
  if (action === 'view')
    return true

  /*
  |--------------------------------------------------------------------------
  | Create PO membutuhkan scope department
  |--------------------------------------------------------------------------
  */
  return permission.code === 'purchase_order.create'
}

const getDefaultScope = (
  permission: PermissionItem,
): PermissionScope => {
  if (supportsScope(permission))
    return 'OWN_DEPARTMENT'

  return 'NONE'
}

/*
|--------------------------------------------------------------------------
| Snapshot
|--------------------------------------------------------------------------
| Digunakan untuk mendeteksi perubahan yang belum disimpan.
|--------------------------------------------------------------------------
*/

const normalizeDepartmentIds = (
  departmentIds: number[],
): number[] => {
  return Array.from(
    new Set(
      (departmentIds || [])
        .map(id => Number(id))
        .filter(id => id > 0),
    ),
  ).sort((a, b) => a - b)
}

const buildSnapshot = (): string => {
  const snapshot = permissionRows.value
    .map(row => ({
      permission_id: Number(
        row.permission_id,
      ),

      is_checked: Boolean(
        row.is_checked,
      ),

      scope: row.is_checked
        ? row.scope
        : 'NONE',

      department_ids:
        row.is_checked
        && row.scope === 'ASSIGNED_DEPARTMENTS'
          ? normalizeDepartmentIds(
              row.department_ids,
            )
          : [],
    }))
    .sort(
      (a, b) =>
        a.permission_id
        - b.permission_id,
    )

  return JSON.stringify(snapshot)
}

const saveInitialSnapshot = (): void => {
  initialSnapshot.value = buildSnapshot()
}

/*
|--------------------------------------------------------------------------
| Build rows
|--------------------------------------------------------------------------
*/

const buildEmptyPermissionRows = (): void => {
  permissionRows.value = permissions.value.map(
    permission => ({
      permission_id: permission.id,
      module: permission.module,
      action: permission.action,
      code: permission.code,
      name: permission.name,
      description: permission.description,
      is_permission_active: permission.is_active,
      is_checked: false,
      scope: getDefaultScope(permission),
      department_ids: [],
    }),
  )

  saveInitialSnapshot()
}

const buildRowsFromUserPermissions = (
  userPermissions: UserPermissionItem[],
): void => {
  const permissionMap = new Map<
    number,
    UserPermissionItem
  >()

  userPermissions.forEach(item => {
    permissionMap.set(
      Number(item.permission_id),
      item,
    )
  })

  permissionRows.value = permissions.value.map(
    permission => {
      const assignedPermission
        = permissionMap.get(
          Number(permission.id),
        )

      const isChecked = Boolean(
        assignedPermission?.is_active,
      )

      return {
        permission_id: permission.id,
        module: permission.module,
        action: permission.action,
        code: permission.code,
        name: permission.name,
        description: permission.description,
        is_permission_active: permission.is_active,

        is_checked: isChecked,

        scope: isChecked
          ? normalizeScope(
              assignedPermission?.scope,
            )
          : getDefaultScope(permission),

        department_ids: isChecked
          ? normalizeDepartmentIds(
              assignedPermission?.department_ids
              ?? [],
            )
          : [],
      }
    },
  )

  saveInitialSnapshot()
}

/*
|--------------------------------------------------------------------------
| Computed
|--------------------------------------------------------------------------
*/

const hasChanges = computed<boolean>(() => {
  return buildSnapshot()
    !== initialSnapshot.value
})

const totalAssignedDepartmentPermission = computed(() => {
  return permissionRows.value.filter(
    row =>
      row.is_checked
      && row.scope === 'ASSIGNED_DEPARTMENTS',
  ).length
})

const selectedUser = computed<UserOption | null>(() => {
  if (!selectedUserId.value)
    return null

  return userOptions.value.find(
    user =>
      Number(user.id)
      === Number(selectedUserId.value),
  ) ?? null
})

const moduleOptions = computed(() => {
  const modules = Array.from(
    new Set(
      permissions.value
        .map(item => item.module)
        .filter(Boolean),
    ),
  )

  return [
    {
      title: 'Semua Module',
      value: 'all',
    },

    ...modules.map(module => ({
      title: formatModuleName(module),
      value: module,
    })),
  ]
})

const filteredPermissionRows = computed(() => {
  const search = keyword.value
    .trim()
    .toLowerCase()

  return permissionRows.value.filter(row => {
    const matchModule
      = selectedModule.value === 'all'
        || row.module === selectedModule.value

    const matchKeyword
      = !search
        || row.name.toLowerCase().includes(search)
        || row.code.toLowerCase().includes(search)
        || row.action.toLowerCase().includes(search)
        || row.module.toLowerCase().includes(search)

    return matchModule && matchKeyword
  })
})

const groupedPermissionRows = computed(() => {
  const groupMap = new Map<
    string,
    PermissionFormRow[]
  >()

  filteredPermissionRows.value.forEach(row => {
    if (!groupMap.has(row.module))
      groupMap.set(row.module, [])

    groupMap.get(row.module)?.push(row)
  })

  return Array.from(
    groupMap.entries(),
  ).map(([module, items]) => ({
    module,
    title: formatModuleName(module),
    items,
  }))
})

const totalDirectPermission = computed(() => {
  return permissionRows.value.filter(
    row => row.is_checked,
  ).length
})

/*
|--------------------------------------------------------------------------
| Formatting
|--------------------------------------------------------------------------
*/

const formatModuleName = (
  module: string,
): string => {
  if (!module)
    return '-'

  return module
    .split('_')
    .map(
      word =>
        word.charAt(0).toUpperCase()
        + word.slice(1),
    )
    .join(' ')
}

/*
|--------------------------------------------------------------------------
| Fetch dropdowns and permissions
|--------------------------------------------------------------------------
*/

const fetchUsers = async (): Promise<void> => {
  isLoadingUser.value = true

  try {
    const response = await axios.get(
      '/master/dropdown/users',
      {
        headers: {
          Accept: 'application/json',
        },
      },
    )

    userOptions.value = normalizeUserItems(
      response.data,
    )
  }
  catch (error: unknown) {
    userOptions.value = []

    showErrorToast({
      title: 'Gagal Memuat User',
      text: getApiErrorMessage(
        error,
        'Gagal memuat daftar user.',
      ),
    })
  }
  finally {
    isLoadingUser.value = false
  }
}

const fetchDepartments = async (): Promise<void> => {
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

    departmentOptions.value
      = normalizeDepartmentItems(
        response.data,
      )
  }
  catch (error: unknown) {
    departmentOptions.value = []

    showErrorToast({
      title: 'Gagal Memuat Department',
      text: getApiErrorMessage(
        error,
        'Gagal memuat daftar department.',
      ),
    })
  }
  finally {
    isLoadingDepartment.value = false
  }
}

const fetchPermissions = async (): Promise<void> => {
  isLoadingPermission.value = true

  try {
    const response = await axios.get(
      '/master/permissions',
      {
        params: {
          per_page: 9999,
        },
        headers: {
          Accept: 'application/json',
        },
      },
    )

    permissions.value
      = normalizePermissionItems(
        response.data,
      )
  }
  catch (error: unknown) {
    permissions.value = []
    permissionRows.value = []

    showErrorToast({
      title: 'Gagal Memuat Permission',
      text: getApiErrorMessage(
        error,
        'Gagal memuat master permission.',
      ),
    })
  }
  finally {
    isLoadingPermission.value = false
  }
}

const fetchUserPermissions = async (): Promise<void> => {
  const userId = Number(
    selectedUserId.value || 0,
  )

  if (!userId) {
    buildEmptyPermissionRows()

    return
  }

  isLoadingUserPermission.value = true

  try {
    const response = await axios.get(
      '/master/user-permissions',
      {
        params: {
          user_id: userId,
        },
        headers: {
          Accept: 'application/json',
        },
      },
    )

    const userPermissions
      = normalizeUserPermissionItems(
        response.data,
      )

    buildRowsFromUserPermissions(
      userPermissions,
    )
  }
  catch (error: unknown) {
    buildEmptyPermissionRows()

    showErrorToast({
      title: 'Gagal Memuat User Permission',
      text: getApiErrorMessage(
        error,
        'Gagal memuat direct permission user.',
      ),
    })
  }
  finally {
    isLoadingUserPermission.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Permission interactions
|--------------------------------------------------------------------------
*/

const onTogglePermission = (
  row: PermissionFormRow,
): void => {
  if (!row.is_checked) {
    row.scope = 'NONE'
    row.department_ids = []

    return
  }

  if (
    supportsScope(row)
    && row.scope === 'NONE'
  ) {
    row.scope = 'OWN_DEPARTMENT'
  }

  if (!supportsScope(row)) {
    row.scope = 'NONE'
    row.department_ids = []
  }
}

const onScopeChange = (
  row: PermissionFormRow,
): void => {
  if (row.scope !== 'NONE')
    row.is_checked = true

  if (
    row.scope
    !== 'ASSIGNED_DEPARTMENTS'
  ) {
    row.department_ids = []
  }
}

/*
|--------------------------------------------------------------------------
| Submit validation
|--------------------------------------------------------------------------
*/

const validateBeforeSubmit = (): boolean => {
  if (!selectedUserId.value) {
    showWarningToast({
      title: 'User Belum Dipilih',
      text: 'Pilih user terlebih dahulu.',
    })

    return false
  }

  /*
   * Permission yang mendukung scope tidak boleh aktif
   * dengan scope NONE.
   */
  const invalidScopePermission
    = permissionRows.value.find(row => {
      return row.is_checked
        && supportsScope(row)
        && row.scope === 'NONE'
    })

  if (invalidScopePermission) {
    showWarningToast({
      title: 'Scope Belum Dipilih',
      text: `Pilih scope untuk permission "${invalidScopePermission.name}".`,
    })

    return false
  }

  /*
   * ASSIGNED_DEPARTMENTS wajib memiliki department.
   */
  const invalidAssignedPermission
    = permissionRows.value.find(row => {
      return row.is_checked
        && row.scope === 'ASSIGNED_DEPARTMENTS'
        && normalizeDepartmentIds(
          row.department_ids,
        ).length === 0
    })

  if (invalidAssignedPermission) {
    showWarningToast({
      title: 'Department Belum Dipilih',
      text: `Pilih minimal satu department untuk permission "${invalidAssignedPermission.name}".`,
    })

    return false
  }

  return true
}

/*
|--------------------------------------------------------------------------
| Build payload
|--------------------------------------------------------------------------
| Seluruh permission dikirim.
|
| Checked:
| - dibuat atau diperbarui sebagai direct permission.
|
| Unchecked:
| - direct permission dihapus oleh backend.
| - permission kembali mengikuti role.
|--------------------------------------------------------------------------
*/

const buildPayload = (): BulkUserPermissionPayload => {
  const userId = Number(
    selectedUserId.value || 0,
  )

  return {
    user_id: userId,

    permissions: permissionRows.value.map(row => {
      const isActive = Boolean(
        row.is_checked,
      )

      let scope: PermissionScope = 'NONE'
      let departmentIds: number[] = []

      if (
        isActive
        && supportsScope(row)
      ) {
        scope = row.scope
      }

      if (
        isActive
        && scope === 'ASSIGNED_DEPARTMENTS'
      ) {
        departmentIds = normalizeDepartmentIds(
          row.department_ids,
        )
      }

      return {
        permission_id: Number(
          row.permission_id,
        ),

        is_active: isActive,
        is_allowed: isActive,

        scope: isActive
          ? scope
          : 'NONE',

        department_ids:
          departmentIds,
      }
    }),
  }
}

/*
|--------------------------------------------------------------------------
| Reset
|--------------------------------------------------------------------------
*/

const resetChanges = async (): Promise<void> => {
  if (
    !selectedUserId.value
    || !hasChanges.value
    || isSubmitting.value
  ) {
    return
  }

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Reset Perubahan?',
    text: 'Perubahan yang belum disimpan akan dikembalikan ke konfigurasi direct permission terakhir.',
    confirmButtonText: 'Ya, reset',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  await fetchUserPermissions()
}

/*
|--------------------------------------------------------------------------
| Submit direct permission
|--------------------------------------------------------------------------
*/

const submitPermission = async (): Promise<void> => {
  if (isSubmitting.value)
    return

  if (!validateBeforeSubmit())
    return

  if (!hasChanges.value) {
    showWarningToast({
      title: 'Tidak Ada Perubahan',
      text: 'Belum ada perubahan direct permission yang perlu disimpan.',
    })

    return
  }

  const activePermissionCount
    = permissionRows.value.filter(
      row => row.is_checked,
    ).length

  const userName
    = selectedUser.value?.name
      || '-'

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Simpan Direct Permission?',
    text:
      `Direct permission untuk "${userName}" akan disimpan. `
      + `Total permission aktif: ${activePermissionCount}. `
      + 'Permission yang tidak dicentang akan kembali mengikuti role.',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  isSubmitting.value = true

  try {
    showLoadingAlert(
      'Menyimpan Direct Permission',
      'Mohon tunggu sebentar.',
    )

    const response = await axios.put(
      '/master/user-permissions/bulk',
      buildPayload(),
      {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    closeAlert()

    /*
     * Muat ulang dari database supaya frontend menggunakan
     * data final hasil normalisasi backend.
     */
    await fetchUserPermissions()

    const savedCount = Number(
      response.data?.data?.saved_count
      ?? 0,
    )

    const deletedCount = Number(
      response.data?.data?.deleted_count
      ?? 0,
    )

    showSuccessToast({
      title: 'Berhasil',
      text:
        `Direct permission berhasil disimpan. `
        + `${savedCount} permission disimpan dan `
        + `${deletedCount} permission dihapus.`,
    })
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Gagal Menyimpan',
      text: getApiErrorMessage(
        error,
        'Gagal menyimpan direct permission user.',
      ),
    })
  }
  finally {
    isSubmitting.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Watch selected user
|--------------------------------------------------------------------------
*/

watch(
  selectedUserId,
  async (newUserId, oldUserId) => {
    if (
      Number(newUserId || 0)
      === Number(oldUserId || 0)
    ) {
      return
    }

    await fetchUserPermissions()
  },
)

/*
|--------------------------------------------------------------------------
| Mounted
|--------------------------------------------------------------------------
*/

onMounted(async () => {
  isLoading.value = true

  try {
    await Promise.all([
      fetchUsers(),
      fetchDepartments(),
      fetchPermissions(),
    ])

    buildEmptyPermissionRows()
  }
  finally {
    isLoading.value = false
  }
})
</script>

<template>
  <div>
    <VCard class="mb-6">
      <VCardItem>
        <VCardTitle>
          Permission per User
        </VCardTitle>

        <VCardSubtitle>
          Direct permission akan menimpa permission role untuk kode yang sama.
        </VCardSubtitle>
      </VCardItem>

      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="5"
          >
            <UserAutocomplete
                v-model="selectedUserId"
                :items="userOptions"
                item-title="search_text"
                item-value="id"
                label="Pilih User"
                placeholder="Cari nama, username, email, atau department"
                prepend-inner-icon="tabler-user-search"
                clearable
                auto-select-first
                :loading="isLoadingUser"
                :menu-props="{
                    maxHeight: 420,
                }"
                no-data-text="User tidak ditemukan"
                >
                <!--
                    Tampilan user yang sudah dipilih.
                -->
                <template #selection="{ item }">
                    <div class="d-flex flex-column py-1">
                    <span class="font-weight-medium">
                        {{ item.raw.name }}
                    </span>

                    <span class="text-caption text-medium-emphasis">
                        {{
                        [
                            item.raw.username,
                            item.raw.email,
                        ]
                            .filter(Boolean)
                            .join(' • ')
                            || '-'
                        }}
                    </span>
                    </div>
                </template>

                <!--
                    Tampilan setiap user di dropdown.
                -->
                <template #item="{ props, item }">
                    <VListItem
                    v-bind="props"
                    :title="item.raw.name"
                    >
                    <template #subtitle>
                        <div class="d-flex flex-column mt-1">
                        <span
                            v-if="item.raw.username"
                            class="text-caption"
                        >
                            Username: {{ item.raw.username }}
                        </span>

                        <span
                            v-if="item.raw.email"
                            class="text-caption"
                        >
                            {{ item.raw.email }}
                        </span>

                        <span
                            v-if="item.raw.department"
                            class="text-caption text-medium-emphasis"
                        >
                            {{ item.raw.department }}
                        </span>
                        </div>
                    </template>
                    </VListItem>
                </template>
                </UserAutocomplete>
          </VCol>

          <VCol
            cols="12"
            md="3"
          >
            <VTextField
              v-model="keyword"
              label="Cari Permission"
              placeholder="Nama atau kode permission"
              clearable
              prepend-inner-icon="tabler-search"
            />
          </VCol>

          <VCol
            cols="12"
            md="3"
          >
            <VSelect
              v-model="selectedModule"
              :items="moduleOptions"
              item-title="title"
              item-value="value"
              label="Module"
            />
          </VCol>
        </VRow>

        <VAlert
          v-if="selectedUser"
          type="info"
          variant="tonal"
          class="mt-4"
        >
          User terpilih:
          <strong>{{ selectedUser.name }}</strong>

          <br>

          Total direct permission:
          <strong>{{ totalDirectPermission }}</strong>

          <div class="mt-2">
            Status perubahan:

            <VChip
                v-if="hasChanges"
                color="warning"
                size="small"
                variant="tonal"
                class="ms-2"
            >
                Belum Disimpan
            </VChip>

            <VChip
                v-else
                color="success"
                size="small"
                variant="tonal"
                class="ms-2"
            >
                Tersimpan
            </VChip>
            </div>
        </VAlert>

        <div
            v-if="selectedUserId"
            class="d-flex flex-wrap justify-end gap-3 mt-5"
            >
            <VBtn
                variant="outlined"
                color="secondary"
                prepend-icon="tabler-refresh"
                :disabled="
                !hasChanges
                    || isSubmitting
                    || isLoadingUserPermission
                "
                @click="resetChanges"
                class="text-none"
            >
                Reset
            </VBtn>

            <VBtn
                v-permission="'auth_user_permission.update'"
                color="primary"
                prepend-icon="tabler-device-floppy"
                :loading="isSubmitting"
                :disabled="
                !hasChanges
                    || isLoadingUserPermission
                "
                @click="submitPermission"
                class="text-none"
            >
                Simpan Direct Permission
            </VBtn>
        </div>
      </VCardText>
    </VCard>

    <VProgressLinear
      v-if="
        isLoading
          || isLoadingPermission
          || isLoadingUserPermission
      "
      indeterminate
      class="mb-4"
    />

    <VAlert
      v-if="!selectedUserId"
      type="warning"
      variant="tonal"
      class="mb-6"
    >
      Pilih user terlebih dahulu untuk melihat dan mengatur direct permission.
    </VAlert>

    <template v-else>
      <VCard
        v-for="group in groupedPermissionRows"
        :key="group.module"
        class="mb-6"
      >
        <VCardItem>
          <VCardTitle>
            {{ group.title }}
          </VCardTitle>
        </VCardItem>

        <VDivider />

        <VTable>
          <thead>
            <tr>
              <th style="width: 70px;">
                Aktif
              </th>

              <th>
                Permission
              </th>

              <th style="width: 260px;">
                Scope
              </th>

              <th style="width: 350px;">
                Assigned Departments
              </th>
            </tr>
          </thead>

          <tbody>
            <tr
              v-for="row in group.items"
              :key="row.permission_id"
            >
              <td>
                <VCheckbox
                  v-model="row.is_checked"
                  hide-details
                  :disabled="!row.is_permission_active"
                  @update:model-value="
                    onTogglePermission(row)
                  "
                />
              </td>

              <td>
                <div class="font-weight-medium">
                  {{ row.name }}
                </div>

                <div class="text-caption text-medium-emphasis">
                  {{ row.code }}
                </div>

                <div
                  v-if="row.description"
                  class="text-caption mt-1"
                >
                  {{ row.description }}
                </div>
              </td>

              <td>
                <VSelect
                  v-if="supportsScope(row)"
                  v-model="row.scope"
                  :items="scopeOptions"
                  item-title="title"
                  item-value="value"
                  density="compact"
                  hide-details
                  :disabled="!row.is_checked"
                  @update:model-value="
                    onScopeChange(row)
                  "
                />

                <VChip
                  v-else
                  size="small"
                  color="secondary"
                  variant="tonal"
                >
                  No Scope
                </VChip>
              </td>

              <td>
                <MultiAutocomplete
                    v-if="
                        row.is_checked
                        && row.scope === 'ASSIGNED_DEPARTMENTS'
                    "
                    v-model="row.department_ids"
                    :items="departmentOptions"
                    item-title="title"
                    item-value="id"
                    multiple
                    chips
                    closable-chips
                    clearable
                    auto-select-first
                    density="compact"
                    hide-details
                    placeholder="Cari dan pilih department"
                    prepend-inner-icon="tabler-building"
                    no-data-text="Department tidak ditemukan"
                    :menu-props="{
                        maxHeight: 360,
                        closeOnContentClick: false,
                    }"
                    />

                <span
                  v-else
                  class="text-medium-emphasis"
                >
                  -
                </span>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>