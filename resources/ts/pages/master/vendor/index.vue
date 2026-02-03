<script setup lang="ts">
import axios from '@axios'
import { computed, ref, watch } from 'vue'

type Vendor = {
  id: number
  id_accurate: string | null
  kode_vendor: string
  nama_vendor: string
  inisial_vendor: string | null
  is_active: boolean
  created_time?: string | null
  created_ip?: string | null
  created_by?: number | null
  lastupdate_time?: string | null
}

type VendorForm = {
  id?: number
  id_accurate: string | null
  kode_vendor: string
  nama_vendor: string
  inisial_vendor: string | null
  is_active: boolean
}

const loading = ref(false)
const rows = ref<Vendor[]>([])

// filters
const searchQuery = ref('')
const selectedStatus = ref<string | null>(null)

// paging
const rowPerPage = ref(10)
const currentPage = ref(1)
const totalPage = ref(1)
const totalRows = ref(0)

// dialog form
const isDialogOpen = ref(false)
const isEdit = ref(false)
const formLoading = ref(false)
const formErrors = ref<Record<string, string>>({})

const form = ref<VendorForm>({
  id_accurate: null,
  kode_vendor: '',
  nama_vendor: '',
  inisial_vendor: null,
  is_active: true,
})

// snackbar notif
const snackbar = ref(false)
const snackText = ref('')
const snackColor = ref<'success' | 'error' | 'warning' | 'info'>('success')
const snackTimeout = ref(3000)

const notify = (text: string, color: 'success' | 'error' | 'warning' | 'info' = 'success', timeout = 3000) => {
  snackText.value = text
  snackColor.value = color
  snackTimeout.value = timeout
  snackbar.value = true
}

// confirm delete dialog
const deleteDialog = ref(false)
const deleteLoading = ref(false)
const deleteTarget = ref<Vendor | null>(null)

const openDelete = (row: Vendor) => {
  deleteTarget.value = row
  deleteDialog.value = true
}

const closeDelete = () => {
  deleteDialog.value = false
  deleteTarget.value = null
}

const confirmDelete = async () => {
  if (!deleteTarget.value) return

  deleteLoading.value = true
  try {
    await axios.delete(`/master/vendor/${deleteTarget.value.id}`)
    notify(`Vendor "${deleteTarget.value.nama_vendor}" berhasil dihapus`, 'success')
    closeDelete()
    await fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Vendor] DELETE ERROR:', res?.status, res?.data || e)
    notify(res?.data?.message || 'Gagal menghapus vendor', 'error')
  } finally {
    deleteLoading.value = false
  }
}

const statusItems = [
  { title: 'Semua', value: null },
  { title: 'Aktif', value: 'true' },
  { title: 'Nonaktif', value: 'false' },
]

// ------------------------
// Fetch server-side
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

    const { data } = await axios.get('/master/vendor', { params })

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    if (currentPage.value > totalPage.value)
      currentPage.value = totalPage.value
  } catch (e: any) {
    const res = e?.response
    console.error('[Vendor] FETCH ERROR:', res?.status, res?.data || e)
    rows.value = []
    totalRows.value = 0
    totalPage.value = 1
    notify('Gagal memuat data vendor', 'error')
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

// initial load
fetchRows()

// ------------------------
// Pagination text
// ------------------------
const paginationData = computed(() => {
  const firstIndex = totalRows.value ? ((currentPage.value - 1) * rowPerPage.value) + 1 : 0
  const lastIndex = rows.value.length + ((currentPage.value - 1) * rowPerPage.value)
  return `${firstIndex}-${lastIndex} of ${totalRows.value}`
})

// ------------------------
// Form Actions
// ------------------------
const openCreate = () => {
  isEdit.value = false
  formErrors.value = {}
  form.value = {
    id_accurate: null,
    kode_vendor: '',
    nama_vendor: '',
    inisial_vendor: null,
    is_active: true,
  }
  isDialogOpen.value = true
}

const openEdit = (row: Vendor) => {
  isEdit.value = true
  formErrors.value = {}
  form.value = {
    id: row.id,
    id_accurate: row.id_accurate ?? null,
    kode_vendor: row.kode_vendor,
    nama_vendor: row.nama_vendor,
    inisial_vendor: row.inisial_vendor ?? null,
    is_active: !!row.is_active,
  }
  isDialogOpen.value = true
}

const closeDialog = () => {
  isDialogOpen.value = false
}

const save = async () => {
  formLoading.value = true
  formErrors.value = {}

  const payload = {
    id_accurate: form.value.id_accurate,
    kode_vendor: form.value.kode_vendor,
    nama_vendor: form.value.nama_vendor,
    inisial_vendor: form.value.inisial_vendor,
    is_active: form.value.is_active,
  }

  try {
    if (isEdit.value && form.value.id) {
      await axios.put(`/master/vendor/${form.value.id}`, payload)
      notify('Vendor berhasil diupdate', 'success')
    } else {
      await axios.post('/master/vendor', payload)
      notify('Vendor berhasil ditambahkan', 'success')
    }

    isDialogOpen.value = false
    await fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Vendor] SAVE ERROR:', res?.status, res?.data || e)

    if (res?.status === 422 && res.data?.errors) {
      Object.keys(res.data.errors).forEach(k => {
        formErrors.value[k] = res.data.errors[k][0]
      })
      notify('Validasi gagal, cek input', 'warning')
      return
    }

    notify(res?.data?.message || 'Gagal menyimpan vendor', 'error')
  } finally {
    formLoading.value = false
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
              label="Cari (kode/nama/inisial)"
              placeholder="Cari vendor..."
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
          + Tambah Vendor
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col">KODE</th>
            <th scope="col">NAMA VENDOR</th>
            <th scope="col">INISIAL</th>
            <th scope="col">ID ACCURATE</th>
            <th scope="col">STATUS</th>
            <th scope="col" class="text-center" style="width: 5rem;">ACTIONS</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="v in rows" :key="v.id">
            <td class="text-medium-emphasis">{{ v.kode_vendor }}</td>
            <td class="text-medium-emphasis">{{ v.nama_vendor }}</td>
            <td class="text-medium-emphasis">{{ v.inisial_vendor ?? '-' }}</td>
            <td class="text-medium-emphasis">{{ v.id_accurate ?? '-' }}</td>
            <td>
              <VChip :color="v.is_active ? 'success' : 'secondary'" size="small" class="text-capitalize">
                {{ v.is_active ? 'active' : 'inactive' }}
              </VChip>
            </td>

            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />

                <VMenu activator="parent">
                  <VList>
                    <VListItem href="javascript:void(0)" @click="openEdit(v)">
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Edit</VListItemTitle>
                    </VListItem>

                    <VListItem href="javascript:void(0)" @click="openDelete(v)">
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
            <td colspan="6" class="text-center">
              No data available
            </td>
          </tr>
        </tfoot>
      </VTable>

      <VDivider />

      <!-- Footer pagination -->
      <VCardText class="d-flex align-center flex-wrap justify-end gap-4 pa-2">
        <div class="d-flex align-center me-3" style="width: 220px;">
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
    <VDialog v-model="isDialogOpen" max-width="560">
      <VCard>
        <VCardTitle class="text-h6">
          {{ isEdit ? 'Edit Vendor' : 'Tambah Vendor' }}
        </VCardTitle>

        <VCardText class="d-flex flex-column gap-3">
          <VTextField
            v-model="form.kode_vendor"
            label="Kode Vendor"
            :error-messages="formErrors.kode_vendor"
          />

          <VTextField
            v-model="form.nama_vendor"
            label="Nama Vendor"
            :error-messages="formErrors.nama_vendor"
          />

          <VTextField
            v-model="form.inisial_vendor"
            label="Inisial Vendor"
            placeholder="Contoh: PRG"
            :error-messages="formErrors.inisial_vendor"
          />

          <VTextField
            v-model="form.id_accurate"
            label="ID Accurate"
            placeholder="Optional"
            :error-messages="formErrors.id_accurate"
          />

          <VSwitch v-model="form.is_active" label="Aktif" inset />
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" :disabled="formLoading" @click="closeDialog">Batal</VBtn>
          <VBtn color="primary" :loading="formLoading" @click="save">Simpan</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Confirm Delete -->
    <VDialog v-model="deleteDialog" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">Konfirmasi Hapus</VCardTitle>

        <VCardText>
          Kamu yakin ingin menghapus vendor
          <b>{{ deleteTarget?.nama_vendor }}</b>
          (kode: <b>{{ deleteTarget?.kode_vendor }}</b>)?
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
        <VBtn variant="text" @click="snackbar = false">Tutup</VBtn>
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
