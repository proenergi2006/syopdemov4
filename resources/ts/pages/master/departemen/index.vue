<script setup lang="ts">
import axios from '@axios'
import {
  computed,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from 'vue'

import {
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
  showErrorToast,
  closeAlert,
  showConfirmAlert,
} from '@/utils/alert'

import DepartmentFormDialog, {
  type DepartmentPayload,
  type DepartmentRow,
} from '@core/components/DepartmentFormDialog.vue'

type DepartmentSummary = {
  total: number
  active: number
  inactive: number
}

type DepartmentMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

const endpoint = '/master/department'

/*
|--------------------------------------------------------------------------
| Data tabel
|--------------------------------------------------------------------------
*/
const loading = ref(false)
const rows = ref<DepartmentRow[]>([])

const summary = ref<DepartmentSummary>({
  total: 0,
  active: 0,
  inactive: 0,
})

const meta = ref<DepartmentMeta>({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  total: 0,
  from: null,
  to: null,
})

/*
|--------------------------------------------------------------------------
| Filter
|--------------------------------------------------------------------------
*/
const searchQuery = ref('')
const selectedStatus = ref<'true' | 'false' | null>(null)

const statusItems = [
  {
    title: 'Semua Status',
    value: null,
  },
  {
    title: 'Aktif',
    value: 'true',
  },
  {
    title: 'Nonaktif',
    value: 'false',
  },
]

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/
const currentPage = ref(1)
const rowPerPage = ref(10)

const rowPerPageItems = [
  10,
  20,
  30,
  50,
  100,
]

const totalPage = computed(() => {
  return Math.max(
    Number(meta.value.last_page || 1),
    1,
  )
})

const paginationData = computed(() => {
  if (meta.value.total === 0)
    return '0-0 dari 0'

  const firstIndex = (
    (currentPage.value - 1) * rowPerPage.value
  ) + 1

  const lastIndex = Math.min(
    firstIndex + rows.value.length - 1,
    meta.value.total,
  )

  return `${firstIndex}-${lastIndex} dari ${meta.value.total}`
})

/*
|--------------------------------------------------------------------------
| Modal tambah/edit
|--------------------------------------------------------------------------
*/
const formDialogOpen = ref(false)
const formMode = ref<'create' | 'edit'>('create')
const formLoading = ref(false)
const formErrors = ref<Record<string, string>>({})
const selectedDepartment = ref<DepartmentRow | null>(null)

const openCreateDialog = () => {
  formMode.value = 'create'
  selectedDepartment.value = null
  formErrors.value = {}
  formDialogOpen.value = true
}

const openEditDialog = (row: DepartmentRow) => {
  formMode.value = 'edit'
  selectedDepartment.value = {
    ...row,
  }
  formErrors.value = {}
  formDialogOpen.value = true
}

const closeFormDialog = () => {
  if (formLoading.value)
    return

  formDialogOpen.value = false
  selectedDepartment.value = null
  formErrors.value = {}
}

/*
|--------------------------------------------------------------------------
| Dialog hapus
|--------------------------------------------------------------------------
*/
const deleteDialogOpen = ref(false)
const deleteLoading = ref(false)
const deleteTarget = ref<DepartmentRow | null>(null)

const openDeleteDialog = (row: DepartmentRow) => {
  deleteTarget.value = row
  deleteDialogOpen.value = true
}

const closeDeleteDialog = () => {
  if (deleteLoading.value)
    return

  deleteDialogOpen.value = false
  deleteTarget.value = null
}

/*
|--------------------------------------------------------------------------
| Normalisasi error validation Laravel
|--------------------------------------------------------------------------
*/
const extractValidationErrors = (
  errors: Record<string, string[] | string>,
) => {
  const result: Record<string, string> = {}

  Object.entries(errors).forEach(([field, messages]) => {
    result[field] = Array.isArray(messages)
      ? String(messages[0] ?? '')
      : String(messages ?? '')
  })

  return result
}

/*
|--------------------------------------------------------------------------
| Fetch data
|--------------------------------------------------------------------------
*/
let latestRequestId = 0

const fetchRows = async () => {
  const requestId = ++latestRequestId

  loading.value = true

  try {
    const params: Record<string, string | number> = {
      page: currentPage.value,
      per_page: rowPerPage.value,
    }

    const search = searchQuery.value.trim()

    if (search !== '')
      params.search = search

    if (selectedStatus.value !== null)
      params.is_active = selectedStatus.value

    const response = await axios.get(endpoint, {
      params,
    })

    /*
    |--------------------------------------------------------------------------
    | Abaikan respons lama jika user cepat mengganti filter
    |--------------------------------------------------------------------------
    */
    if (requestId !== latestRequestId)
      return

    const responseData = response.data

    rows.value = Array.isArray(responseData?.data)
      ? responseData.data
      : []

    meta.value = {
      current_page: Number(
        responseData?.meta?.current_page ?? 1,
      ),
      last_page: Math.max(
        Number(responseData?.meta?.last_page ?? 1),
        1,
      ),
      per_page: Number(
        responseData?.meta?.per_page
        ?? rowPerPage.value,
      ),
      total: Number(
        responseData?.meta?.total ?? 0,
      ),
      from: responseData?.meta?.from ?? null,
      to: responseData?.meta?.to ?? null,
    }

    summary.value = {
      total: Number(
        responseData?.summary?.total
        ?? responseData?.meta?.total
        ?? 0,
      ),
      active: Number(
        responseData?.summary?.active ?? 0,
      ),
      inactive: Number(
        responseData?.summary?.inactive ?? 0,
      ),
    }

    if (
      currentPage.value > meta.value.last_page
      && meta.value.last_page > 0
    ) {
      currentPage.value = meta.value.last_page
    }
  } catch (error: any) {
    if (requestId !== latestRequestId)
      return

    console.error(
      '[Department] Fetch error:',
      error?.response?.status,
      error?.response?.data || error,
    )

    rows.value = []

    meta.value = {
      current_page: 1,
      last_page: 1,
      per_page: rowPerPage.value,
      total: 0,
      from: null,
      to: null,
    }

    summary.value = {
      total: 0,
      active: 0,
      inactive: 0,
    }

    showErrorToast({
      title: 'Gagal Memuat Data',
      text:
        error?.response?.data?.message
        || 'Gagal memuat data department.',
    })
  } finally {
    if (requestId === latestRequestId)
      loading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Simpan tambah/edit
|--------------------------------------------------------------------------
*/
const saveDepartment = async (
  payload: DepartmentPayload,
): Promise<void> => {
  if (formLoading.value)
    return

  const isEditMode = (
    formMode.value === 'edit'
    && selectedDepartment.value
  )

  const confirm = await showConfirmAlert({
    title: isEditMode
      ? 'Simpan Perubahan?'
      : 'Tambah Department?',

    text: isEditMode
      ? `Data department "${selectedDepartment.value?.nama}" akan diperbarui.`
      : 'Department baru akan ditambahkan ke dalam sistem.',

    confirmButtonText: isEditMode
      ? 'Ya, simpan'
      : 'Ya, tambah',

    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  formLoading.value = true
  formErrors.value = {}

  try {
    showLoadingAlert(
      isEditMode
        ? 'Memperbarui Department'
        : 'Menambahkan Department',
      'Mohon tunggu sebentar',
    )

    let response

    if (isEditMode && selectedDepartment.value) {
      response = await axios.put(
        `${endpoint}/${selectedDepartment.value.id}`,
        payload,
        {
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
        },
      )
    } else {
      response = await axios.post(
        endpoint,
        payload,
        {
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          },
        },
      )
    }

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text:
        response?.data?.message
        || (
          isEditMode
            ? 'Department berhasil diperbarui.'
            : 'Department berhasil ditambahkan.'
        ),
    })

    formDialogOpen.value = false
    selectedDepartment.value = null
    formErrors.value = {}

    await fetchRows()
  } catch (error: any) {
    closeAlert()

    const response = error?.response

    console.error(
      '[Department] Save error:',
      response?.status,
      response?.data || error,
    )

    if (
      response?.status === 422
      && response?.data?.errors
    ) {
      formErrors.value = extractValidationErrors(
        response.data.errors,
      )

      showWarningToast({
        title: 'Validasi Gagal',
        text: 'Silakan periksa kembali input department.',
      })

      return
    }

    showErrorToast({
      title: 'Gagal Menyimpan',
      text:
        response?.data?.message
        || 'Department gagal disimpan.',
    })
  } finally {
    formLoading.value = false
  }
}
/*
|--------------------------------------------------------------------------
| Hapus department
|--------------------------------------------------------------------------
*/
const deleteDepartment = async (
  department: DepartmentRow,
): Promise<void> => {
  if (deleteLoading.value)
    return

  const confirm = await showConfirmAlert({
    icon: 'question',
    title: 'Hapus Department?',
    text: `Department "${department.nama}" dengan kode "${department.kode}" akan dihapus dari sistem.`,
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  deleteLoading.value = true

  try {
    showLoadingAlert(
      'Menghapus Department',
      'Mohon tunggu sebentar',
    )

    const response = await axios.delete(
      `${endpoint}/${department.id}`,
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
        response?.data?.message
        || `Department "${department.nama}" berhasil dihapus.`,
    })

    const isLastRowOnPage = rows.value.length === 1
    const canMoveToPreviousPage = currentPage.value > 1

    if (isLastRowOnPage && canMoveToPreviousPage) {
      currentPage.value -= 1
    } else {
      await fetchRows()
    }
  } catch (error: any) {
    closeAlert()

    const response = error?.response

    console.error(
      '[Department] Delete error:',
      response?.status,
      response?.data || error,
    )

    if (response?.status === 409) {
      showWarningToast({
        title: 'Department Masih Digunakan',
        text:
          response?.data?.message
          || 'Department tidak dapat dihapus karena masih digunakan oleh data lain. Silakan nonaktifkan department.',
      })

      return
    }

    showErrorToast({
      title: 'Gagal Menghapus',
      text:
        response?.data?.message
        || 'Department gagal dihapus.',
    })
  } finally {
    deleteLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Reset filter
|--------------------------------------------------------------------------
*/
const resetFilter = () => {
  searchQuery.value = ''
  selectedStatus.value = null

  if (currentPage.value !== 1) {
    currentPage.value = 1
  } else {
    fetchRows()
  }
}

/*
|--------------------------------------------------------------------------
| Watchers
|--------------------------------------------------------------------------
*/
let searchTimer: ReturnType<typeof setTimeout> | undefined

watch(searchQuery, () => {
  if (searchTimer)
    clearTimeout(searchTimer)

  searchTimer = setTimeout(() => {
    if (currentPage.value !== 1) {
      currentPage.value = 1
    } else {
      fetchRows()
    }
  }, 400)
})

watch(selectedStatus, () => {
  if (currentPage.value !== 1) {
    currentPage.value = 1
  } else {
    fetchRows()
  }
})

watch(rowPerPage, () => {
  if (currentPage.value !== 1) {
    currentPage.value = 1
  } else {
    fetchRows()
  }
})

watch(currentPage, () => {
  fetchRows()
})

onMounted(() => {
  fetchRows()
})

onBeforeUnmount(() => {
  if (searchTimer)
    clearTimeout(searchTimer)
})
</script>

<template>
  <section>
    <!-- Header -->
    <VCard class="mb-6">
      <VCardText class="pa-6">
        <div
          class="d-flex flex-wrap align-center justify-space-between gap-4"
        >
          <div>
            <div class="text-primary font-weight-bold mb-1">
              Master Department
            </div>

            <h2 class="text-h4 font-weight-bold">
              Kelola Department
            </h2>

            <div class="text-body-2 text-medium-emphasis mt-2">
              Kelola kode, nama, dan status department perusahaan.
            </div>
          </div>

          <div class="d-flex flex-wrap gap-3">
            <VBtn
              color="secondary"
              variant="tonal"
              prepend-icon="mdi-refresh"
              :loading="loading"
              @click="fetchRows"
              class="text-none"
            >
              Refresh
            </VBtn>

            <VBtn
              color="primary"
              prepend-icon="mdi-office-building-plus-outline"
              @click="openCreateDialog"
              class="text-none"
            >
              Tambah Department
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- Summary -->
    <VRow class="mb-6">
      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="d-flex align-center pa-6">
            <div>
              <div class="text-body-1 text-medium-emphasis">
                Total Department
              </div>

              <div class="text-h4 font-weight-bold mt-2">
                {{ summary.total }}
              </div>
            </div>

            <VSpacer />

            <VAvatar
              color="primary"
              variant="tonal"
              rounded
              size="54"
            >
              <VIcon
                icon="mdi-office-building-outline"
                size="30"
              />
            </VAvatar>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="d-flex align-center pa-6">
            <div>
              <div class="text-body-1 text-medium-emphasis">
                Department Aktif
              </div>

              <div class="text-h4 font-weight-bold text-success mt-2">
                {{ summary.active }}
              </div>
            </div>

            <VSpacer />

            <VAvatar
              color="success"
              variant="tonal"
              rounded
              size="54"
            >
              <VIcon
                icon="mdi-check-decagram-outline"
                size="30"
              />
            </VAvatar>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="d-flex align-center pa-6">
            <div>
              <div class="text-body-1 text-medium-emphasis">
                Department Nonaktif
              </div>

              <div class="text-h4 font-weight-bold text-medium-emphasis mt-2">
                {{ summary.inactive }}
              </div>
            </div>

            <VSpacer />

            <VAvatar
              color="secondary"
              variant="tonal"
              rounded
              size="54"
            >
              <VIcon
                icon="mdi-office-building-remove-outline"
                size="30"
              />
            </VAvatar>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filter -->
    <VCard class="mb-6">
      <VCardText class="pa-6">
        <VRow align="center">
          <VCol
            cols="12"
            md="7"
          >
            <VTextField
              v-model="searchQuery"
              label="Cari Department"
              placeholder="Cari berdasarkan kode atau nama"
              prepend-inner-icon="mdi-magnify"
              clearable
              hide-details
            />
          </VCol>

          <VCol
            cols="12"
            md="3"
          >
            <VSelect
              v-model="selectedStatus"
              label="Status"
              :items="statusItems"
              item-title="title"
              item-value="value"
              prepend-inner-icon="mdi-filter-outline"
              hide-details
            />
          </VCol>

          <VCol
            cols="12"
            md="2"
          >
            <VBtn
              block
              height="46"
              color="secondary"
              variant="tonal"
              prepend-icon="mdi-filter-remove-outline"
              @click="resetFilter"
              class="text-none"
            >
              Reset
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard>
      <VProgressLinear
        v-if="loading"
        indeterminate
        color="primary"
      />

      <VTable class="department-table text-no-wrap">
        <thead>
          <tr>
            <th
              scope="col"
              style="width: 80px;"
            >
              NO.
            </th>

            <th scope="col">
              KODE
            </th>

            <th scope="col">
              NAMA DEPARTMENT
            </th>

            <th
              scope="col"
              style="width: 160px;"
            >
              STATUS
            </th>

            <th
              scope="col"
              class="text-center"
              style="width: 110px;"
            >
              AKSI
            </th>
          </tr>
        </thead>

        <tbody>
          <template v-if="rows.length > 0">
            <tr
              v-for="(department, index) in rows"
              :key="department.id"
            >
              <td class="text-medium-emphasis">
                {{
                  (
                    (currentPage - 1) * rowPerPage
                  ) + index + 1
                }}
              </td>

              <td>
                <VChip
                  color="primary"
                  variant="tonal"
                  size="small"
                  class="font-weight-medium"
                >
                  {{ department.kode }}
                </VChip>
              </td>

              <td>
                <div class="d-flex align-center">
                  <VAvatar
                    color="primary"
                    variant="tonal"
                    rounded
                    size="38"
                    class="me-3"
                  >
                    <VIcon
                      icon="mdi-office-building-outline"
                      size="21"
                    />
                  </VAvatar>

                  <div>
                    <div class="font-weight-medium">
                      {{ department.nama }}
                    </div>
                  </div>
                </div>
              </td>

              <td>
                <VChip
                  :color="
                    department.is_active
                      ? 'success'
                      : 'secondary'
                  "
                  variant="tonal"
                  size="small"
                >
                  <VIcon
                    start
                    size="16"
                    :icon="
                      department.is_active
                        ? 'mdi-check-circle-outline'
                        : 'mdi-close-circle-outline'
                    "
                  />

                  {{
                    department.is_active
                      ? 'Aktif'
                      : 'Nonaktif'
                  }}
                </VChip>
              </td>

              <td class="text-center">
                <VBtn
                  icon
                  size="small"
                  variant="text"
                  color="secondary"
                >
                  <VIcon
                    icon="mdi-dots-vertical"
                    size="22"
                  />

                  <VMenu activator="parent">
                    <VList min-width="170">
                      <VListItem
                        prepend-icon="mdi-pencil-outline"
                        title="Edit"
                        @click="openEditDialog(department)"
                      />

                      <VListItem
                        prepend-icon="mdi-delete-outline"
                        title="Hapus"
                        class="text-error"
                        :disabled="deleteLoading"
                        @click="deleteDepartment(department)"
                      />
                    </VList>
                  </VMenu>
                </VBtn>
              </td>
            </tr>
          </template>

          <tr v-else-if="!loading">
            <td
              colspan="5"
              class="text-center py-12"
            >
              <VAvatar
                color="secondary"
                variant="tonal"
                size="64"
                class="mb-4"
              >
                <VIcon
                  icon="mdi-database-off-outline"
                  size="32"
                />
              </VAvatar>

              <div class="text-h6">
                Data department tidak ditemukan
              </div>

              <div class="text-body-2 text-medium-emphasis mt-2">
                Tambahkan department baru atau ubah filter pencarian.
              </div>
            </td>
          </tr>
        </tbody>
      </VTable>

      <VDivider />

      <VCardText
        class="d-flex flex-wrap align-center justify-space-between gap-4 pa-4"
      >
        <div class="d-flex align-center gap-3">
          <span class="text-body-2 text-medium-emphasis">
            Baris per halaman:
          </span>

          <VSelect
            v-model="rowPerPage"
            :items="rowPerPageItems"
            density="compact"
            variant="outlined"
            hide-details
            style="width: 90px;"
          />
        </div>

        <div class="d-flex flex-wrap align-center gap-4">
          <span class="text-body-2 text-medium-emphasis">
            {{ paginationData }}
          </span>

          <VPagination
            v-model="currentPage"
            :length="totalPage"
            :total-visible="5"
            density="comfortable"
            size="small"
          />
        </div>
      </VCardText>
    </VCard>

    <!-- Modal tambah/edit -->
    <DepartmentFormDialog
      v-model="formDialogOpen"
      :mode="formMode"
      :department="selectedDepartment"
      :loading="formLoading"
      :errors="formErrors"
      @submit="saveDepartment"
    />
  </section>
</template>

<style lang="scss" scoped>
.department-table {
  thead th {
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.04em;
  }

  tbody tr {
    transition: background-color 0.2s ease;

    &:hover {
      background-color: rgba(var(--v-theme-primary), 0.035);
    }
  }

  td,
  th {
    padding-inline: 1.5rem;
  }
}

/*
|--------------------------------------------------------------------------
| SweetAlert harus berada di atas VDialog Vuetify
|--------------------------------------------------------------------------
*/
:global(.swal2-container) {
  z-index: 99999 !important;
}
</style>