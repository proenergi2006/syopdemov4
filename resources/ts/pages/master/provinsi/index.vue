<script setup lang="ts">
import axios from '@axios'
import { computed, ref, watch } from 'vue'

type Provinsi = {
  id: number
  kode: string
  nama: string
  is_active: boolean
  created_at?: string
  updated_at?: string
}

type ProvinsiForm = {
  id?: number
  kode: string
  nama: string
  is_active: boolean
}

// table
const loading = ref(false)
const rows = ref<Provinsi[]>([])

// filters
const searchQuery = ref('')
const selectedStatus = ref<string | null>(null)

// paging
const rowPerPage = ref(10)
const currentPage = ref(1)
const totalPage = ref(1)
const totalRows = ref(0)

// form dialog
const isDialogOpen = ref(false)
const isEdit = ref(false)
const formLoading = ref(false)
const formErrors = ref<Record<string, string>>({})

const form = ref<ProvinsiForm>({
  kode: '',
  nama: '',
  is_active: true,
})

// snackbar notif
const snackbar = ref(false)
const snackText = ref('')
const snackColor = ref<'success' | 'error' | 'warning' | 'info'>('success')
const snackTimeout = ref(3000)

const notify = (
  text: string,
  color: 'success' | 'error' | 'warning' | 'info' = 'success',
  timeout = 3000,
) => {
  snackText.value = text
  snackColor.value = color
  snackTimeout.value = timeout
  snackbar.value = true
}

// delete confirm dialog
const deleteDialog = ref(false)
const deleteLoading = ref(false)
const deleteTarget = ref<Provinsi | null>(null)

const openDelete = (row: Provinsi) => {
  deleteTarget.value = row
  deleteDialog.value = true
}
const closeDelete = () => {
  deleteDialog.value = false
  deleteTarget.value = null
}

const statusItems = [
  { title: 'Semua', value: null },
  { title: 'Aktif', value: 'true' },
  { title: 'Nonaktif', value: 'false' },
]

// ------------------------
// Fetch
// ------------------------
const fetchRows = async () => {
  loading.value = true
  try {
    const params: any = {
      page: currentPage.value,
      per_page: rowPerPage.value,
    }
    if (searchQuery.value) params.search = searchQuery.value
    if (selectedStatus.value !== null) params.is_active = selectedStatus.value

    // baseURL sudah /api => cukup /master/provinsi
    const { data } = await axios.get('/master/provinsi', { params })

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    if (currentPage.value > totalPage.value)
      currentPage.value = totalPage.value
  } catch (e: any) {
    console.error('[Provinsi] FETCH ERROR:', e?.response?.status, e?.response?.data || e)
    rows.value = []
    totalRows.value = 0
    totalPage.value = 1
    notify('Gagal memuat data provinsi', 'error')
  } finally {
    loading.value = false
  }
}

// debounce search
let t: any = null
watch(searchQuery, () => {
  clearTimeout(t)
  t = setTimeout(() => {
    currentPage.value = 1
    fetchRows()
  }, 400)
})

watch([selectedStatus, rowPerPage, currentPage], () => {
  fetchRows()
})

// initial fetch
fetchRows()

// pagination label
const paginationData = computed(() => {
  const firstIndex = totalRows.value ? ((currentPage.value - 1) * rowPerPage.value) + 1 : 0
  const lastIndex = rows.value.length + ((currentPage.value - 1) * rowPerPage.value)
  return `${firstIndex}-${lastIndex} of ${totalRows.value}`
})

// ------------------------
// Form actions
// ------------------------
const openCreate = () => {
  isEdit.value = false
  formErrors.value = {}
  form.value = { kode: '', nama: '', is_active: true }
  isDialogOpen.value = true
}

const openEdit = (row: Provinsi) => {
  isEdit.value = true
  formErrors.value = {}
  form.value = { id: row.id, kode: row.kode, nama: row.nama, is_active: row.is_active }
  isDialogOpen.value = true
}

const closeDialog = () => {
  isDialogOpen.value = false
}

const save = async () => {
  formLoading.value = true
  formErrors.value = {}

  const payload = {
    kode: form.value.kode,
    nama: form.value.nama,
    is_active: form.value.is_active,
  }

  try {
    if (isEdit.value && form.value.id) {
      await axios.put(`/master/provinsi/${form.value.id}`, payload)
      notify('Provinsi berhasil diperbarui', 'success')
    } else {
      await axios.post('/master/provinsi', payload)
      notify('Provinsi berhasil ditambahkan', 'success')
    }

    isDialogOpen.value = false
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Provinsi] SAVE ERROR:', res?.status, res?.data || e)

    if (res?.status === 422 && res.data?.errors) {
      Object.keys(res.data.errors).forEach(k => {
        formErrors.value[k] = res.data.errors[k][0]
      })
      notify('Validasi gagal, cek input', 'warning')
      return
    }

    notify(res?.data?.message || 'Gagal menyimpan data', 'error')
  } finally {
    formLoading.value = false
  }
}

const confirmDelete = async () => {
  if (!deleteTarget.value) return

  deleteLoading.value = true
  try {
    await axios.delete(`/master/provinsi/${deleteTarget.value.id}`)
    notify(`Provinsi "${deleteTarget.value.nama}" berhasil dihapus`, 'success')
    closeDelete()
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Provinsi] DELETE ERROR:', res?.status, res?.data || e)
    notify(res?.data?.message || 'Gagal menghapus data', 'error')
  } finally {
    deleteLoading.value = false
  }
}
</script>

<template>
  <section>
    <!-- Filters -->
    <VCard title="Filters" class="mb-6">
      <VCardText>
        <VRow>
          <VCol cols="12" sm="6">
            <VTextField
              v-model="searchQuery"
              label="Cari (kode/nama)"
              placeholder="Cari (kode/nama)"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" sm="6">
            <VSelect
              v-model="selectedStatus"
              label="Status"
              :items="statusItems"
              item-title="title"
              item-value="value"
              clearable
              clear-icon="mdi-close"
              density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn color="primary" @click="openCreate">
          + Tambah Provinsi
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col">KODE</th>
            <th scope="col">NAMA</th>
            <th scope="col">STATUS</th>
            <th scope="col" class="text-center" style="width: 5rem;">ACTIONS</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="p in rows" :key="p.id">
            <td class="text-medium-emphasis">{{ p.kode }}</td>
            <td class="text-medium-emphasis">{{ p.nama }}</td>
            <td>
              <VChip
                :color="p.is_active ? 'success' : 'secondary'"
                size="small"
                class="text-capitalize"
              >
                {{ p.is_active ? 'active' : 'inactive' }}
              </VChip>
            </td>

            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />

                <VMenu activator="parent">
                  <VList>
                    <VListItem href="javascript:void(0)" @click="openEdit(p)">
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Edit</VListItemTitle>
                    </VListItem>

                    <VListItem href="javascript:void(0)" @click="openDelete(p)">
                      <template #prepend>
                        <VIcon icon="mdi-delete-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Delete</VListItemTitle>
                    </VListItem>
                  </VList>
                </VMenu>
              </VBtn>
            </td>
          </tr>
        </tbody>

        <tfoot v-show="!rows.length && !loading">
          <tr>
            <td colspan="4" class="text-center">
              No data available
            </td>
          </tr>
        </tfoot>
      </VTable>

      <VDivider />

      <!-- Footer pagination -->
      <VCardText class="d-flex align-center flex-wrap justify-end gap-4 pa-2">
        <div class="d-flex align-center me-3" style="width: 200px;">
          <span class="text-no-wrap me-3">Rows per page:</span>

          <VSelect
            v-model="rowPerPage"
            density="compact"
            variant="plain"
            class="user-pagination-select"
            :items="[10, 20, 30, 50]"
          />
        </div>

        <div class="d-flex align-center">
          <h6 class="text-sm font-weight-regular">
            {{ paginationData }}
          </h6>

          <VPagination
            v-model="currentPage"
            size="small"
            :total-visible="1"
            :length="totalPage"
          />
        </div>
      </VCardText>
    </VCard>

    <!-- Dialog Form -->
    <VDialog v-model="isDialogOpen" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">
          {{ isEdit ? 'Edit Provinsi' : 'Tambah Provinsi' }}
        </VCardTitle>

        <VCardText class="d-flex flex-column gap-3">
          <VTextField v-model="form.kode" label="Kode" :error-messages="formErrors.kode" />
          <VTextField v-model="form.nama" label="Nama" :error-messages="formErrors.nama" />
          <VSwitch v-model="form.is_active" label="Aktif" inset />
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" @click="closeDialog">Batal</VBtn>
          <VBtn color="primary" :loading="formLoading" @click="save">Simpan</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Confirm -->
    <VDialog v-model="deleteDialog" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">Konfirmasi Hapus</VCardTitle>

        <VCardText>
          Kamu yakin ingin menghapus provinsi
          <b>{{ deleteTarget?.nama }}</b>
          (kode: <b>{{ deleteTarget?.kode }}</b>)?
          <div class="text-body-2 opacity-70 mt-2">
            Data yang sudah dihapus tidak bisa dikembalikan.
          </div>
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" :disabled="deleteLoading" @click="closeDelete">Batal</VBtn>
          <VBtn color="error" :loading="deleteLoading" @click="confirmDelete">Hapus</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Snackbar -->
    <VSnackbar
      v-model="snackbar"
      :timeout="snackTimeout"
      :color="snackColor"
      location="top end"
    >
      {{ snackText }}

      <template #actions>
        <VBtn variant="text" @click="snackbar = false">Close</VBtn>
      </template>
    </VSnackbar>
  </section>
</template>

<style lang="scss">
.text-capitalize { text-transform: capitalize; }
</style>

<style lang="scss" scoped>
.user-pagination-select {
  .v-field__input,
  .v-field__append-inner {
    padding-block-start: 0.3rem;
  }
}
</style>

<route lang="yaml">
meta:
  action: read
  subject: Master
</route>
