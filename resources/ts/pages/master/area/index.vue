<script setup lang="ts">
import axios from '@axios'
import { computed, ref, watch, watchEffect } from 'vue'

type Area = {
  id: number
  nama_area: string
  wapu: boolean
  lampiran_url?: string | null
  is_active: boolean
  created_time?: string
  created_ip?: string
  created_by?: string
  lastupdate_time?: string
}

type AreaForm = {
  id?: number
  nama_area: string
  wapu: boolean
  is_active: boolean

  // ✅ Vuetify VFileInput defaultnya File[]
  lampiranFiles: File[]

  // untuk edit: opsional hapus lampiran lama
  remove_lampiran: boolean
  lampiranExisting: string | null
}

const loading = ref(false)
const rows = ref<Area[]>([])

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

// snackbar notif
const snackbar = ref(false)
const snackText = ref('')
const snackColor = ref<'success' | 'error' | 'warning' | 'info'>('success')
const snackTimeout = ref(3000)

// confirm delete dialog
const deleteDialog = ref(false)
const deleteLoading = ref(false)
const deleteTarget = ref<Area | null>(null)

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

const statusItems = [
  { title: 'Semua', value: null },
  { title: 'Aktif', value: 'true' },
  { title: 'Nonaktif', value: 'false' },
]

const form = ref<AreaForm>({
  nama_area: '',
  wapu: false,
  is_active: true,
  lampiranFiles: [],
  remove_lampiran: false,
  lampiranExisting: null,
})

// kalau user pilih file baru, otomatis jangan remove lampiran lama
watch(
  () => form.value.lampiranFiles,
  files => {
    if (files?.length)
      form.value.remove_lampiran = false
  },
  { deep: true },
)

// ------------------------
// Fetch list (server-side pagination)
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

    // ✅ endpoint area (sesuaikan kalau beda)
    const { data } = await axios.get('/master/area', { params })

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    if (currentPage.value > totalPage.value)
      currentPage.value = totalPage.value
  } catch (e: any) {
    console.error('[Area] FETCH ERROR:', e?.response?.status, e?.response?.data || e)
    rows.value = []
    totalRows.value = 0
    totalPage.value = 1
    notify('Gagal memuat data Area', 'error')
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

watchEffect(() => {
  fetchRows()
})

// ------------------------
// Pagination text
// ------------------------
const paginationData = computed(() => {
  const firstIndex = totalRows.value ? ((currentPage.value - 1) * rowPerPage.value) + 1 : 0
  const lastIndex = rows.value.length + ((currentPage.value - 1) * rowPerPage.value)
  return `${firstIndex}-${lastIndex} of ${totalRows.value}`
})

// ------------------------
// Dialog actions
// ------------------------
const openCreate = () => {
  isEdit.value = false
  formErrors.value = {}
  form.value = {
    nama_area: '',
    wapu: false,
    is_active: true,
    lampiranFiles: [],
    remove_lampiran: false,
    lampiranExisting: null,
  }
  isDialogOpen.value = true
}

const openEdit = (row: Area) => {
  isEdit.value = true
  formErrors.value = {}

  form.value = {
    id: row.id,
    nama_area: row.nama_area,
    wapu: !!row.wapu,
    is_active: !!row.is_active,
    lampiranFiles: [],
    remove_lampiran: false,
    lampiranExisting: row.lampiran_url ?? null,
  }
  isDialogOpen.value = true
}

const closeDialog = () => {
  isDialogOpen.value = false
}

// ------------------------
// Save (multipart for upload)
// ------------------------
const save = async () => {
  formLoading.value = true
  formErrors.value = {}

  try {
    const fd = new FormData()
    fd.append('nama_area', form.value.nama_area)
    fd.append('wapu', String(form.value.wapu ? 1 : 0))
    fd.append('is_active', String(form.value.is_active ? 1 : 0))

    // file upload (ambil file pertama)
    const file = form.value.lampiranFiles?.[0] ?? null
    if (file)
      fd.append('lampiran', file)

    // kalau edit & user minta hapus lampiran lama (dan tidak upload baru)
    if (isEdit.value && form.value.id && form.value.remove_lampiran && !file)
      fd.append('remove_lampiran', '1')

    if (isEdit.value && form.value.id) {
      // Laravel: PUT multipart kadang perlu _method
      fd.append('_method', 'PUT')
      await axios.post(`/master/area/${form.value.id}`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      notify('Area berhasil diupdate', 'success')
    } else {
      await axios.post('/master/area', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      notify('Area berhasil ditambahkan', 'success')
    }

    isDialogOpen.value = false
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Area] SAVE ERROR:', res?.status, res?.data || e)

    if (res?.status === 422 && res.data?.errors) {
      Object.keys(res.data.errors).forEach(k => {
        formErrors.value[k] = res.data.errors[k][0]
      })
      notify('Validasi gagal, cek input', 'warning')
    } else {
      notify(res?.data?.message || 'Gagal menyimpan data', 'error')
    }
  } finally {
    formLoading.value = false
  }
}

// ------------------------
// Delete
// ------------------------
const openDelete = (row: Area) => {
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
    await axios.delete(`/master/area/${deleteTarget.value.id}`)
    notify(`Area "${deleteTarget.value.nama_area}" berhasil dihapus`, 'success')
    closeDelete()
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Area] DELETE ERROR:', res?.status, res?.data || e)
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
              label="Cari (nama area)"
              placeholder="Cari (nama area)"
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
          + Tambah Area
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col">NAMA AREA</th>
            <th scope="col">WAPU</th>
            <th scope="col">LAMPIRAN</th>
            <th scope="col">STATUS</th>
            <th scope="col" class="text-center" style="width: 5rem;">ACTIONS</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="a in rows" :key="a.id">
            <td class="text-medium-emphasis">{{ a.nama_area }}</td>

            <td>
              <VChip :color="a.wapu ? 'primary' : 'secondary'" size="small" variant="tonal">
                {{ a.wapu ? 'Yes' : 'No' }}
              </VChip>
            </td>

            <td class="text-medium-emphasis">
              <template v-if="a.lampiran_url">
                <a :href="a.lampiran_url" target="_blank" rel="noopener">Download</a>
              </template>
              <template v-else>
                -
              </template>
            </td>

            <td>
              <VChip :color="a.is_active ? 'success' : 'secondary'" size="small" class="text-capitalize">
                {{ a.is_active ? 'active' : 'inactive' }}
              </VChip>
            </td>

            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />

                <VMenu activator="parent">
                  <VList>
                    <VListItem href="javascript:void(0)" @click="openEdit(a)">
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Edit</VListItemTitle>
                    </VListItem>

                    <VListItem href="javascript:void(0)" @click="openDelete(a)">
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
    <VDialog v-model="isDialogOpen" max-width="560">
      <VCard>
        <VCardTitle class="text-h6">
          {{ isEdit ? 'Edit Area' : 'Tambah Area' }}
        </VCardTitle>

        <VCardText class="d-flex flex-column gap-3">
          <VTextField
            v-model="form.nama_area"
            label="Nama Area"
            :error-messages="formErrors.nama_area"
          />

          <VSwitch v-model="form.wapu" label="WAPU" inset />

          <VSwitch v-model="form.is_active" label="Aktif" inset />

          <VDivider class="my-2" />

          <!-- Lampiran existing -->
          <div v-if="isEdit && form.lampiranExisting" class="text-body-2">
            Lampiran saat ini:
            <a :href="form.lampiranExisting" target="_blank" rel="noopener">Download</a>
          </div>

          <!-- Remove existing (only edit & punya existing & belum upload baru) -->
          <VCheckbox
            v-if="isEdit && form.lampiranExisting"
            v-model="form.remove_lampiran"
            label="Hapus lampiran lama"
            density="compact"
          />

          <!-- ✅ VFileInput v-model harus File[] -->
          <VFileInput
            v-model="form.lampiranFiles"
            label="Upload lampiran (opsional)"
            density="compact"
            prepend-icon="mdi-paperclip"
            show-size
            clearable
          />
          <div class="text-caption text-medium-emphasis">
            Jika upload file baru, lampiran lama akan terganti.
          </div>
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" :disabled="formLoading" @click="closeDialog">Batal</VBtn>
          <VBtn color="primary" :loading="formLoading" @click="save">Simpan</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete confirm -->
    <VDialog v-model="deleteDialog" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">Konfirmasi Hapus</VCardTitle>

        <VCardText>
          Kamu yakin ingin menghapus area
          <b>{{ deleteTarget?.nama_area }}</b>?
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
