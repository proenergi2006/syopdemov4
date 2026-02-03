<script setup lang="ts">
import axios from '@axios';
import { computed, ref, watch } from 'vue';

type ProvinsiOpt = { id: number; nama: string; kode: string }
type Kabupaten = {
  id: number
  provinsi_id: number
  kode: string
  nama: string
  is_active: boolean
  provinsi?: { id: number; kode: string; nama: string }
}

type KabupatenForm = {
  id?: number
  provinsi_id: number | null
  kode: string
  nama: string
  is_active: boolean
}

// table
const loading = ref(false)
const rows = ref<Kabupaten[]>([])

// filters
const searchQuery = ref('')
const selectedStatus = ref<string | null>(null)
const selectedProvinsiId = ref<number | null>(null)

// paging
const rowPerPage = ref(10)
const currentPage = ref(1)
const totalPage = ref(1)
const totalRows = ref(0)

// provinsi dropdown (filter & form)
const provinsiLoading = ref(false)
const provinsiItems = ref<ProvinsiOpt[]>([])

// dialog form
const isDialogOpen = ref(false)
const isEdit = ref(false)
const formLoading = ref(false)
const formErrors = ref<Record<string, string>>({})

const form = ref<KabupatenForm>({
  provinsi_id: null,
  kode: '',
  nama: '',
  is_active: true,
})

// snackbar
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

// delete confirm
const deleteDialog = ref(false)
const deleteLoading = ref(false)
const deleteTarget = ref<Kabupaten | null>(null)

const openDelete = (row: Kabupaten) => {
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

// fetch provinsi (buat dropdown)
const fetchProvinsi = async () => {
  provinsiLoading.value = true
  try {
    // ambil banyak (kalau provinsi cuma puluhan aman)
    const { data } = await axios.get('/master/provinsi', { params: { per_page: 200 } })
    const list = Array.isArray(data?.data) ? data.data : []
    provinsiItems.value = list.map((p: any) => ({ id: p.id, nama: p.nama, kode: p.kode }))
  } catch (e: any) {
    console.error('[Kabupaten] FETCH PROVINSI ERROR:', e?.response?.status, e?.response?.data || e)
    provinsiItems.value = []
    notify('Gagal memuat list provinsi', 'error')
  } finally {
    provinsiLoading.value = false
  }
}

// fetch kabupaten
const fetchRows = async () => {
  loading.value = true
  try {
    const params: any = {
      page: currentPage.value,
      per_page: rowPerPage.value,
    }
    if (searchQuery.value) params.search = searchQuery.value
    if (selectedStatus.value !== null) params.is_active = selectedStatus.value
    if (selectedProvinsiId.value) params.provinsi_id = selectedProvinsiId.value

    const { data } = await axios.get('/master/kabupaten', { params })

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    if (currentPage.value > totalPage.value)
      currentPage.value = totalPage.value
  } catch (e: any) {
    console.error('[Kabupaten] FETCH ERROR:', e?.response?.status, e?.response?.data || e)
    rows.value = []
    totalRows.value = 0
    totalPage.value = 1
    notify('Gagal memuat data kabupaten', 'error')
  } finally {
    loading.value = false
  }
}

// initial
fetchProvinsi().then(fetchRows)

// debounce search
let t: any = null
watch(searchQuery, () => {
  clearTimeout(t)
  t = setTimeout(() => {
    currentPage.value = 1
    fetchRows()
  }, 400)
})

// refetch on filter/paging change
watch([selectedStatus, selectedProvinsiId, rowPerPage, currentPage], () => {
  fetchRows()
})

// pagination label
const paginationData = computed(() => {
  const firstIndex = totalRows.value ? ((currentPage.value - 1) * rowPerPage.value) + 1 : 0
  const lastIndex = rows.value.length + ((currentPage.value - 1) * rowPerPage.value)
  return `${firstIndex}-${lastIndex} of ${totalRows.value}`
})

// actions
const openCreate = () => {
  isEdit.value = false
  formErrors.value = {}
  form.value = { provinsi_id: null, kode: '', nama: '', is_active: true }
  isDialogOpen.value = true
}

const openEdit = (row: Kabupaten) => {
  isEdit.value = true
  formErrors.value = {}
  form.value = {
    id: row.id,
    provinsi_id: row.provinsi_id,
    kode: row.kode,
    nama: row.nama,
    is_active: row.is_active,
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
    provinsi_id: form.value.provinsi_id,
    kode: form.value.kode,
    nama: form.value.nama,
    is_active: form.value.is_active,
  }

  try {
    if (!payload.provinsi_id) {
      formErrors.value.provinsi_id = 'Provinsi wajib dipilih'
      notify('Validasi gagal, cek input', 'warning')
      return
    }

    if (isEdit.value && form.value.id) {
      await axios.put(`/master/kabupaten/${form.value.id}`, payload)
      notify('Kabupaten berhasil diperbarui', 'success')
    } else {
      await axios.post('/master/kabupaten', payload)
      notify('Kabupaten berhasil ditambahkan', 'success')
    }

    isDialogOpen.value = false
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Kabupaten] SAVE ERROR:', res?.status, res?.data || e)

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
    await axios.delete(`/master/kabupaten/${deleteTarget.value.id}`)
    notify(`Kabupaten "${deleteTarget.value.nama}" berhasil dihapus`, 'success')
    closeDelete()
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Kabupaten] DELETE ERROR:', res?.status, res?.data || e)
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
          <VCol cols="12" sm="4">
            <VTextField
              v-model="searchQuery"
              label="Cari (kode/nama)"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" sm="4">
            <VSelect
              v-model="selectedProvinsiId"
              label="Provinsi"
              :items="provinsiItems"
              item-title="nama"
              item-value="id"
              density="compact"
              clearable
              :loading="provinsiLoading"
            />
          </VCol>

          <VCol cols="12" sm="4">
            <VSelect
              v-model="selectedStatus"
              label="Status"
              :items="statusItems"
              item-title="title"
              item-value="value"
              density="compact"
              clearable
              clear-icon="mdi-close"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn color="primary" @click="openCreate">
          + Tambah Kabupaten
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col">PROVINSI</th>
            <th scope="col">KODE</th>
            <th scope="col">NAMA</th>
            <th scope="col">STATUS</th>
            <th scope="col" class="text-center" style="width: 5rem;">ACTIONS</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="k in rows" :key="k.id">
            <td class="text-medium-emphasis">
              {{ k.provinsi?.nama ?? '-' }}
            </td>
            <td class="text-medium-emphasis">{{ k.kode }}</td>
            <td class="text-medium-emphasis">{{ k.nama }}</td>
            <td>
              <VChip :color="k.is_active ? 'success' : 'secondary'" size="small" class="text-capitalize">
                {{ k.is_active ? 'active' : 'inactive' }}
              </VChip>
            </td>

            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />
                <VMenu activator="parent">
                  <VList>
                    <VListItem href="javascript:void(0)" @click="openEdit(k)">
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Edit</VListItemTitle>
                    </VListItem>

                    <VListItem href="javascript:void(0)" @click="openDelete(k)">
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
            <td colspan="5" class="text-center">
              No data available
            </td>
          </tr>
        </tfoot>
      </VTable>

      <VDivider />

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

    <!-- Form Dialog -->
    <VDialog v-model="isDialogOpen" max-width="560">
      <VCard>
        <VCardTitle class="text-h6">
          {{ isEdit ? 'Edit Kabupaten' : 'Tambah Kabupaten' }}
        </VCardTitle>

        <VCardText class="d-flex flex-column gap-3">
          <VSelect
            v-model="form.provinsi_id"
            label="Provinsi"
            :items="provinsiItems"
            item-title="nama"
            item-value="id"
            :loading="provinsiLoading"
            :error-messages="formErrors.provinsi_id"
            clearable
          />

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
          Kamu yakin ingin menghapus kabupaten
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
    <VSnackbar v-model="snackbar" :timeout="snackTimeout" :color="snackColor" location="top end">
      {{ snackText }}
      <template #actions>
        <VBtn variant="text" @click="snackbar = false">Close</VBtn>
      </template>
    </VSnackbar>
  </section>
</template>

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
