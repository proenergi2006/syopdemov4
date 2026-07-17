<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import Swal from 'sweetalert2'
import axiosIns from '@/plugins/axios'
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
    status?: number
    data?: {
      message?: string
      debug?: string
      errors?: Record<string, string[]>
    }
  }
}

type MenuItem = {
  id: number
  parent_id: number | null
  name: string
  path: string | null
  route_name: string | null
  icon: string | null
  order_no: number
  permission_key: string | null
  show_in_sidebar: boolean
  is_active: boolean
  type: 'GROUP' | 'SIDEBAR_PAGE' | 'HIDDEN_PAGE' | string
  children?: MenuItem[]
}

type FlatMenuItem = MenuItem & {
  level: number
}

type MenuForm = {
  id: number | null
  type: 'GROUP' | 'SIDEBAR_PAGE' | 'HIDDEN_PAGE'
  parent_id: number | null
  name: string
  path: string
  route_name: string
  icon: string
  order_no: number
  permission_key: string
  show_in_sidebar: boolean
  is_active: boolean
}

const isActionLoading = ref(false)
const isLoading = ref(false)
const isSaving = ref(false)
const isDialogVisible = ref(false)
const isEditMode = ref(false)

const menus = ref<MenuItem[]>([])
const search = ref('')
const statusFilter = ref<'ALL' | 'ACTIVE' | 'INACTIVE'>('ALL')
const page = ref(1)
const itemsPerPage = ref(10)

const form = ref<MenuForm>({
  id: null,
  type: 'SIDEBAR_PAGE',
  parent_id: null,
  name: '',
  path: '',
  route_name: '',
  icon: '',
  order_no: 0,
  permission_key: '',
  show_in_sidebar: true,
  is_active: true,
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

const typeOptions = [
  { title: 'Group / Parent', value: 'GROUP' },
  { title: 'Sidebar Page', value: 'SIDEBAR_PAGE' },
  { title: 'Hidden Page', value: 'HIDDEN_PAGE' },
]

const tablerIconOptions = [
  'tabler-smart-home',
  'tabler-layout-dashboard',
  'tabler-settings',
  'tabler-lock',
  'tabler-user',
  'tabler-users',
  'tabler-shield',
  'tabler-license',
  'tabler-file-invoice',
  'tabler-file-dollar',
  'tabler-shopping-cart',
  'tabler-archive',
  'tabler-building',
  'tabler-building-store',
  'tabler-map',
  'tabler-map-2',
  'tabler-map-pin',
  'tabler-location-pin',
  'tabler-truck',
  'tabler-car',
  'tabler-ship',
  'tabler-gas-station',
  'tabler-receipt-dollar',
  'tabler-currency-rupiah',
  'tabler-cylinder',
  'tabler-user-check',
  'tabler-folder',
  'tabler-file',
  'tabler-list',
  'tabler-clipboard-list',
  'tabler-database',
  'tabler-report',
  'tabler-chart-bar',
  'tabler-chart-pie',
  'tabler-bell',
  'tabler-mail',
  'tabler-calendar',
  'tabler-search',
  'tabler-plus',
  'tabler-edit',
  'tabler-trash',
  'tabler-eye',
  'tabler-eye-off',
]

const openTablerIconReference = () => {
  window.open('https://tabler.io/icons', '_blank')
}

const flattenMenus = (
  items: MenuItem[],
  level = 0,
): FlatMenuItem[] => {
  const result: FlatMenuItem[] = []

  items.forEach(item => {
    result.push({
      ...item,
      level,
    })

    if (item.children?.length) {
      result.push(...flattenMenus(item.children, level + 1))
    }
  })

  return result
}

const flatMenus = computed(() => {
  return flattenMenus(menus.value)
})

const isDescendantOfCurrentMenu = (item: FlatMenuItem): boolean => {
  if (!form.value.id)
    return false

  let parentId = item.parent_id

  while (parentId) {
    if (Number(parentId) === Number(form.value.id))
      return true

    const parent = flatMenus.value.find(menu => Number(menu.id) === Number(parentId))

    parentId = parent?.parent_id ?? null
  }

  return false
}

const parentOptions = computed(() => {
  const options = [
    {
      title: 'Top Level',
      value: null,
      name: 'Top Level',
      subtitle: 'Menu utama, sejajar dengan Dashboard / Master / Non Trade',
      level: 0,
      type: 'TOP_LEVEL',
      icon: 'tabler-layout-sidebar',
      path: null,
    },
  ]

  const menuOptions = flatMenus.value
    .filter(item => {
      if (item.id === form.value.id)
        return false

      if (item.type === 'HIDDEN_PAGE')
        return false

      if (isDescendantOfCurrentMenu(item))
        return false

      return true
    })
    .map(item => ({
      title: item.name,
      value: item.id,
      name: item.name,
      subtitle: item.path || item.route_name || 'Group / Parent',
      level: item.level,
      type: item.type,
      icon: item.icon || (item.type === 'GROUP' ? 'tabler-folder' : 'tabler-file'),
      path: item.path,
    }))

  return [
    ...options,
    ...menuOptions,
  ]
})

const parentOptionTypeLabel = (type: string) => {
  if (type === 'TOP_LEVEL')
    return 'Top Level'

  if (type === 'GROUP')
    return 'Group'

  if (type === 'SIDEBAR_PAGE')
    return 'Page'

  return type
}

const parentOptionTypeColor = (type: string) => {
  if (type === 'TOP_LEVEL')
    return 'secondary'

  if (type === 'GROUP')
    return 'primary'

  if (type === 'SIDEBAR_PAGE')
    return 'success'

  return 'secondary'
}

const getNextOrderNo = (): number => {
  const parentId = form.value.parent_id

  const siblings = flatMenus.value.filter(item => {
    const sameParent = parentId === null
      ? item.parent_id === null
      : Number(item.parent_id) === Number(parentId)

    const notCurrentItem = !form.value.id
      || Number(item.id) !== Number(form.value.id)

    return sameParent && notCurrentItem
  })

  const maxOrder = siblings.reduce((max, item) => {
    const orderNo = Number(item.order_no || 0)

    return orderNo > max ? orderNo : max
  }, 0)

  return maxOrder + 1
}

const applyNextOrderNo = () => {
  form.value.order_no = getNextOrderNo()
}

const isDuplicateOrderNo = (): boolean => {
  const parentId = form.value.parent_id
  const orderNo = Number(form.value.order_no || 0)

  return flatMenus.value.some(item => {
    const sameParent = parentId === null
      ? item.parent_id === null
      : Number(item.parent_id) === Number(parentId)

    const sameOrder = Number(item.order_no || 0) === orderNo

    const notCurrentItem = !form.value.id
      || Number(item.id) !== Number(form.value.id)

    return sameParent && sameOrder && notCurrentItem
  })
}

watch(
  () => form.value.parent_id,
  () => {
    if (!isDialogVisible.value || isEditMode.value)
      return

    applyNextOrderNo()
  },
)

const filteredMenus = computed(() => {
  const keyword = search.value.trim().toLowerCase()

  return flatMenus.value.filter(item => {
    const matchKeyword = !keyword
      || [
        item.name,
        item.path,
        item.route_name,
        item.permission_key,
        item.type,
      ]
        .filter(Boolean)
        .some(value => String(value).toLowerCase().includes(keyword))

    const matchStatus =
      statusFilter.value === 'ALL'
      || (statusFilter.value === 'ACTIVE' && item.is_active)
      || (statusFilter.value === 'INACTIVE' && !item.is_active)

    return matchKeyword && matchStatus
  })
})

const totalItems = computed(() => filteredMenus.value.length)

const totalPages = computed(() => {
  return Math.max(
    1,
    Math.ceil(totalItems.value / itemsPerPage.value),
  )
})

const paginatedMenus = computed(() => {
  const start = (page.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value

  return filteredMenus.value.slice(start, end)
})

watch([search, statusFilter, itemsPerPage], () => {
  page.value = 1
})

watch(
  () => form.value.type,
  type => {
    if (type === 'GROUP') {
      form.value.path = ''
      form.value.route_name = ''
      form.value.permission_key = ''
      form.value.show_in_sidebar = true
      return
    }

    if (type === 'SIDEBAR_PAGE') {
      form.value.show_in_sidebar = true
      return
    }

    if (type === 'HIDDEN_PAGE') {
      form.value.show_in_sidebar = false
    }
  },
)

const fetchMenus = async () => {
  isLoading.value = true

  try {
    const response = await axiosIns.get('/master/menus')

    menus.value = response.data?.data?.tree ?? []
  } catch (error: any) {
    Swal.fire({
      icon: 'error',
      title: 'Gagal Memuat Menu',
      text:
        error.response?.data?.message
        ?? 'Terjadi kesalahan saat memuat data menu.',
    })
  } finally {
    isLoading.value = false
  }
}

const refreshNavigationStorage = async () => {
  try {
    const response = await axiosIns.get('/master/menus/navigation')

    localStorage.setItem(
      'navItems',
      JSON.stringify(response.data),
    )
  } catch {
    // Tidak perlu mengganggu proses utama.
  }
}

const resetForm = () => {
  form.value = {
    id: null,
    type: 'SIDEBAR_PAGE',
    parent_id: null,
    name: '',
    path: '',
    route_name: '',
    icon: '',
    order_no: 0,
    permission_key: '',
    show_in_sidebar: true,
    is_active: true,
  }
}

const resetFilter = () => {
  search.value = ''
  statusFilter.value = 'ALL'
  itemsPerPage.value = 10
  page.value = 1
}

const openCreateDialog = async () => {
  resetForm()
  isEditMode.value = false
  isDialogVisible.value = true

  await nextTick()

  applyNextOrderNo()
}

const openEditDialog = (item: MenuItem) => {
  isEditMode.value = true

  form.value = {
    id: item.id,
    type: item.type === 'GROUP'
      ? 'GROUP'
      : item.type === 'HIDDEN_PAGE'
        ? 'HIDDEN_PAGE'
        : 'SIDEBAR_PAGE',
    parent_id: item.parent_id,
    name: item.name ?? '',
    path: item.path ?? '',
    route_name: item.route_name ?? '',
    icon: item.icon ?? '',
    order_no: item.order_no ?? 0,
    permission_key: item.permission_key ?? '',
    show_in_sidebar: Boolean(item.show_in_sidebar),
    is_active: Boolean(item.is_active),
  }

  isDialogVisible.value = true
}

const closeDialog = () => {
  isDialogVisible.value = false
  resetForm()
}

const validateForm = (): boolean => {
  if (!form.value.name.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Nama menu wajib diisi.',
    })

    return false
  }

  if (form.value.type !== 'GROUP' && !form.value.path.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Path wajib diisi untuk Sidebar Page atau Hidden Page.',
    })

    return false
  }

  if (form.value.type === 'HIDDEN_PAGE' && form.value.show_in_sidebar) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Hidden Page tidak boleh tampil di sidebar.',
    })

    return false
  }

  if (Number(form.value.order_no || 0) <= 0) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Order harus lebih besar dari 0.',
    })

    return false
  }

  if (isDuplicateOrderNo()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Order sudah digunakan pada parent menu yang sama.',
    })

    return false
  }

  return true
}

const buildPayload = () => {
  const type = String(form.value.type || '').toUpperCase()
  const isGroup = type === 'GROUP'
  const isHiddenPage = type === 'HIDDEN_PAGE'
  const isSidebarPage = type === 'SIDEBAR_PAGE'

  return {
    parent_id: form.value.parent_id,
    name: form.value.name.trim(),
    path: isGroup ? null : form.value.path.trim(),
    route_name: isGroup ? null : (form.value.route_name.trim() || null),
    icon: form.value.icon.trim() || null,
    order_no: Number(form.value.order_no || 0),
    permission_key: null,
    show_in_sidebar: isGroup || isSidebarPage ? true : false,
    is_active: Boolean(form.value.is_active),
  }
}

const saveMenu = async (): Promise<void> => {
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
    title: isEditMode.value ? 'Simpan Perubahan Menu?' : 'Tambah Menu?',
    text: isEditMode.value
      ? 'Data menu akan diperbarui.'
      : 'Menu baru akan ditambahkan.',
    confirmButtonText: isEditMode.value ? 'Ya, simpan' : 'Ya, tambah',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed) {
    isDialogVisible.value = true
    return
  }

  isSaving.value = true

  try {
    showLoadingAlert('Menyimpan Menu', 'Mohon tunggu sebentar.')

    const payload = buildPayload()

    if (isEditMode.value && form.value.id) {
      await axiosIns.put(`/master/menus/${form.value.id}`, payload, {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      })
    } else {
      await axiosIns.post('/master/menus', payload, {
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
      })
    }

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: isEditMode.value
        ? 'Menu berhasil diperbarui.'
        : 'Menu berhasil ditambahkan.',
    })

    resetForm()
    await fetchMenus()
    await refreshNavigationStorage()
  } catch (error: any) {
    closeAlert()

    isDialogVisible.value = true

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal menyimpan menu.'),
    })
  } finally {
    isSaving.value = false
  }
}

const toggleActive = async (item: MenuItem): Promise<void> => {
  if (isActionLoading.value)
    return

  const nextStatus = !item.is_active

  const confirm = await showConfirmAlert({
    title: nextStatus ? 'Aktifkan Menu?' : 'Nonaktifkan Menu?',
    text: nextStatus
      ? `Menu "${item.name}" akan diaktifkan kembali.`
      : `Menu "${item.name}" akan dinonaktifkan. Jika masih memiliki child aktif, proses akan ditolak.`,
    confirmButtonText: nextStatus ? 'Ya, aktifkan' : 'Ya, nonaktifkan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  isActionLoading.value = true

  try {
    showLoadingAlert(
      nextStatus ? 'Mengaktifkan Menu' : 'Menonaktifkan Menu',
      'Mohon tunggu sebentar.',
    )

    await axiosIns.patch(`/master/menus/${item.id}/toggle-active`, {}, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: nextStatus
        ? 'Menu berhasil diaktifkan.'
        : 'Menu berhasil dinonaktifkan.',
    })

    await fetchMenus()
    await refreshNavigationStorage()
  } catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(
        err,
        nextStatus
          ? 'Gagal mengaktifkan menu.'
          : 'Gagal menonaktifkan menu.',
      ),
    })
  } finally {
    isActionLoading.value = false
  }
}

const deleteMenu = async (item: MenuItem): Promise<void> => {
  if (isActionLoading.value)
    return

  const confirm = await showConfirmAlert({
    title: 'Hapus Menu?',
    text: `Menu "${item.name}" akan dihapus. Jika menu sudah digunakan role, menu akan dinonaktifkan.`,
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  isActionLoading.value = true

  try {
    showLoadingAlert('Menghapus Menu', 'Mohon tunggu sebentar.')

    const response = await axiosIns.delete(`/master/menus/${item.id}`, {
      headers: {
        Accept: 'application/json',
      },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || 'Menu berhasil diproses.',
    })

    await fetchMenus()
    await refreshNavigationStorage()
  } catch (error: any) {
    closeAlert()

    const err = error as AxiosErrorShape

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(err, 'Gagal menghapus menu.'),
    })
  } finally {
    isActionLoading.value = false
  }
}

const typeColor = (type: string) => {
  if (type === 'GROUP') return 'primary'
  if (type === 'SIDEBAR_PAGE') return 'success'
  if (type === 'HIDDEN_PAGE') return 'warning'

  return 'secondary'
}

const typeLabel = (type: string) => {
  if (type === 'GROUP') return 'Group'
  if (type === 'SIDEBAR_PAGE') return 'Sidebar Page'
  if (type === 'HIDDEN_PAGE') return 'Hidden Page'

  return type
}

onMounted(() => {
  fetchMenus()
})
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard>
        <VCardItem>
          <div class="d-flex align-center justify-space-between flex-wrap gap-4">
            <div>
              <VCardTitle class="text-h5">
                Menu Management
              </VCardTitle>

              <VCardSubtitle>
                Kelola parent menu, sidebar page, dan hidden route.
              </VCardSubtitle>
            </div>

            <VBtn
                color="primary"
                prepend-icon="tabler-plus"
                @click="openCreateDialog"
                class="text-none"
                >
                Tambah Menu
            </VBtn>
          </div>
        </VCardItem>

        <VDivider />

        <VCardText>
            <VRow class="align-center">
                <VCol
                cols="12"
                md="4"
                >
                <VTextField
                    v-model="search"
                    label="Search"
                    placeholder="Cari menu, path, permission..."
                    prepend-inner-icon="tabler-search"
                    clearable
                    density="comfortable"
                />
                </VCol>

                <VCol
                cols="12"
                md="3"
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
                md="2"
                >
                <VBtn
                    color="secondary"
                    variant="tonal"
                    prepend-icon="tabler-refresh"
                    block
                    @click="resetFilter"
                    class="text-none"
                >
                    Reset Filter
                </VBtn>
                </VCol>

                <VCol
                cols="12"
                md="2"
                class="ms-auto"
                >
                <VSelect
                    v-model="itemsPerPage"
                    :items="itemsPerPageOptions"
                    label="Tampilkan"
                    density="comfortable"
                />
                </VCol>
            </VRow>
        </VCardText>

        <VDivider />

        <VCardText class="pa-0">
          <VTable class="text-no-wrap">
            <thead>
              <tr>
                <th class="text-left">
                  Menu
                </th>
                <th class="text-left">
                  Type
                </th>
                <th class="text-left">
                  Path
                </th>
                <th class="text-left">
                  Order
                </th>
                <th class="text-left">
                  Sidebar
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
                  Memuat data menu...
                </td>
              </tr>

              <tr
                v-else-if="paginatedMenus.length === 0"
              >
                <td
                  colspan="8"
                  class="text-center py-8 text-disabled"
                >
                  Data menu belum tersedia.
                </td>
              </tr>

              <tr
                v-for="item in paginatedMenus"
                v-else
                :key="item.id"
              >
                <td>
                  <div
                    class="d-flex align-center gap-2"
                    :style="{ paddingLeft: `${item.level * 24}px` }"
                  >
                    <VIcon
                      v-if="item.level > 0"
                      icon="tabler-corner-down-right"
                      size="18"
                      color="secondary"
                    />

                    <VIcon
                      v-if="item.icon"
                      :icon="item.icon"
                      size="20"
                    />

                    <div>
                      <div class="font-weight-medium">
                        {{ item.name }}
                      </div>

                      <div
                        v-if="item.route_name"
                        class="text-caption text-disabled"
                      >
                        {{ item.route_name }}
                      </div>
                    </div>
                  </div>
                </td>

                <td>
                  <VChip
                    size="small"
                    variant="tonal"
                    :color="typeColor(item.type)"
                  >
                    {{ typeLabel(item.type) }}
                  </VChip>
                </td>

                <td>
                  <code v-if="item.path">
                    {{ item.path }}
                  </code>

                  <span
                    v-else
                    class="text-disabled"
                  >
                    -
                  </span>
                </td>

                <td>
                  {{ item.order_no }}
                </td>

                <td>
                  <VChip
                    size="small"
                    variant="tonal"
                    :color="item.show_in_sidebar ? 'success' : 'warning'"
                  >
                    {{ item.show_in_sidebar ? 'Show' : 'Hidden' }}
                  </VChip>
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
                      icon
                      size="small"
                      variant="text"
                      color="primary"
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
                      icon
                      size="small"
                      variant="text"
                      :color="item.is_active ? 'warning' : 'success'"
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
                      icon
                      size="small"
                      variant="text"
                      color="error"
                      @click="deleteMenu(item)"
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
              Total {{ totalItems }} menu
            </div>

            <VPagination
              v-model="page"
              :length="totalPages"
              total-visible="5"
            />
          </div>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
  <VDialog
    v-model="isDialogVisible"
    max-width="760"
    persistent
    >
    <VCard>
        <VCardItem>
        <VCardTitle>
            {{ isEditMode ? 'Edit Menu' : 'Tambah Menu' }}
        </VCardTitle>

        <VCardSubtitle>
            Kelola group, sidebar page, atau hidden route.
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
            v-model="form.type"
            :items="typeOptions"
            item-title="title"
            item-value="value"
            label="Tipe Menu"
            density="comfortable"
            />
            </VCol>

            <VCol
            cols="12"
            md="6"
            >
                <VSelect
                v-model="form.parent_id"
                :items="parentOptions"
                item-title="title"
                item-value="value"
                label="Parent Menu"
                clearable
                density="comfortable"
                hint="Pilih Top Level jika menu ingin menjadi menu utama. Pilih parent jika menu ingin masuk ke dalam group tertentu."
                persistent-hint
                >
                <template #selection="{ item }">
                    <div class="d-flex align-center gap-2">
                    <VIcon
                        :icon="item.raw.icon"
                        size="18"
                    />

                    <span>{{ item.raw.name }}</span>

                    <VChip
                        size="x-small"
                        variant="tonal"
                        :color="parentOptionTypeColor(item.raw.type)"
                    >
                        {{ parentOptionTypeLabel(item.raw.type) }}
                    </VChip>
                    </div>
                </template>

                <template #item="{ props, item }">
                    <VListItem
                    v-bind="props"
                    :title="undefined"
                    :subtitle="undefined"
                    class="py-2"
                    >
                    <template #prepend>
                        <div
                        class="d-flex align-center"
                        :style="{ width: `${item.raw.level * 22 + 28}px` }"
                        >
                        <VIcon
                            v-if="item.raw.level > 0"
                            icon="tabler-corner-down-right"
                            size="16"
                            color="secondary"
                            class="me-1"
                        />

                        <VIcon
                            :icon="item.raw.icon"
                            size="20"
                        />
                        </div>
                    </template>

                    <VListItemTitle class="font-weight-medium">
                        {{ item.raw.name }}
                    </VListItemTitle>

                    <VListItemSubtitle>
                        {{ item.raw.subtitle }}
                    </VListItemSubtitle>

                    <template #append>
                        <VChip
                        size="x-small"
                        variant="tonal"
                        :color="parentOptionTypeColor(item.raw.type)"
                        >
                        {{ parentOptionTypeLabel(item.raw.type) }}
                        </VChip>
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
                v-model="form.name"
                label="Nama Menu"
                placeholder="Contoh: Vendor"
                density="comfortable"
            />
            </VCol>

            <VCol
            cols="12"
            md="6"
            >
                <VTextField
                v-model.number="form.order_no"
                label="Order"
                type="number"
                density="comfortable"
                hint="Nomor urutan dalam parent menu yang sama."
                persistent-hint
                append-inner-icon="tabler-refresh"
                @click:append-inner="applyNextOrderNo"
                />
            </VCol>

            <VCol
            cols="12"
            md="6"
            >
            <VCombobox
                v-model="form.icon"
                :items="tablerIconOptions"
                label="Icon"
                placeholder="Contoh: tabler-settings"
                density="comfortable"
                clearable
                hint="Gunakan nama icon Tabler, contoh: tabler-settings, tabler-user, tabler-shopping-cart."
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
                    @click.stop="openTablerIconReference"
                    >
                    <VIcon icon="tabler-external-link" />
                    <VTooltip
                        activator="parent"
                        location="top"
                    >
                        Buka referensi Tabler Icon
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
            <VTextField
                v-model="form.route_name"
                label="Route Name"
                placeholder="Contoh: master-vendor"
                :disabled="form.type === 'GROUP'"
                density="comfortable"
            />
            </VCol>

            <VCol cols="12">
            <VTextField
                v-model="form.path"
                label="Path"
                placeholder="Contoh: /master/vendor"
                :disabled="form.type === 'GROUP'"
                density="comfortable"
            />
            </VCol>

            <!-- <VCol cols="12">
                <VTextField
                    v-model="form.permission_key"
                    label="Permission Key"
                    placeholder="Contoh: vendor.view"
                    :disabled="form.type === 'GROUP'"
                    density="comfortable"
                />
            </VCol> -->

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
        </VRow>
        </VCardText>

        <VDivider />

        <VCardActions>
        <VSpacer />

        <VBtn
            variant="tonal"
            color="secondary"
            :disabled="isSaving"
            @click="closeDialog"
        >
            Batal
        </VBtn>

        <VBtn
            color="primary"
            :loading="isSaving"
            @click="saveMenu"
        >
            Simpan
        </VBtn>
        </VCardActions>
    </VCard>
    </VDialog>
</template>