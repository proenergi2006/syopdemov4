<script setup lang="ts">
import axios from '@axios';
import { computed, ref, watch, watchEffect } from 'vue';

type TransportirOption = { title: string; value: number }

type Sopir = {
  id_master: number
  id_transportir: number
  nama_sopir: string
  photo?: string | null
  photo_ori?: string | null
  is_active: number | boolean
  created_time?: string
  created_ip?: string
  created_by?: string
  lastupdate_time?: string | null
  lastupdate_ip?: string | null
  lastupdate_by?: string | null

  // optional: kalau backend join transportir (enak buat tabel)
  nama_transportir?: string | null
}

type SopirForm = {
  id_master?: number
  id_transportir: number | null
  nama_sopir: string
  is_active: boolean
  photo_file: File | null
}

// --------------------
// State
// --------------------
const loading = ref(false)
const rows = ref<Sopir[]>([])

// filters
const searchQuery = ref('')
const selectedActive = ref<string | null>(null) // 'true' | 'false' | null

// paging
const rowPerPage = ref(10)
const currentPage = ref(1)
const totalPage = ref(1)
const totalRows = ref(0)

// options
const transportirOptions = ref<TransportirOption[]>([])
const loadingTransportir = ref(false)

// dialog
const isDialogOpen = ref(false)
const isEdit = ref(false)
const formLoading = ref(false)
const formErrors = ref<Record<string, string>>({})

// delete dialog
const deleteDialog = ref(false)
const deleteLoading = ref(false)
const deleteTarget = ref<Sopir | null>(null)

// snackbar
const snackbar = ref(false)
const snackText = ref('')
const snackColor = ref<'success' | 'error' | 'warning' | 'info'>('success')
const snackTimeout = ref(2500)

const notify = (text: string, color: 'success' | 'error' | 'warning' | 'info' = 'success', timeout = 2500) => {
  snackText.value = text
  snackColor.value = color
  snackTimeout.value = timeout
  snackbar.value = true
}

// form
const form = ref<SopirForm>({
  id_transportir: null,
  nama_sopir: '',
  is_active: true,
  photo_file: null,
})

// --------------------
// Helpers
// --------------------
const activeItems = [
  { title: 'Semua', value: null },
  { title: 'Aktif', value: 'true' },
  { title: 'Nonaktif', value: 'false' },
]

const transportirTitleById = (id?: number | null) => {
  if (!id) return '-'
  const f = transportirOptions.value.find(x => Number(x.value) === Number(id))
  return f?.title ?? String(id)
}

const isActiveLabel = (v: any) => {
  const b = typeof v === 'boolean' ? v : Number(v) === 1
  return b ? 'active' : 'inactive'
}

const isActiveBool = (v: any) => {
  return typeof v === 'boolean' ? v : Number(v) === 1
}

const resetForm = () => {
  form.value = {
    id_transportir: null,
    nama_sopir: '',
    is_active: true,
    photo_file: null,
  }
  formErrors.value = {}
}

const buildFormData = (payload: SopirForm) => {
  const fd = new FormData()
  fd.append('id_transportir', String(payload.id_transportir ?? ''))
  fd.append('nama_sopir', payload.nama_sopir)
  fd.append('is_active', payload.is_active ? '1' : '0')
  if (payload.photo_file) fd.append('photo_file', payload.photo_file)
  return fd
}

// --------------------
// API
// --------------------
const fetchTransportirOptions = async () => {
  loadingTransportir.value = true
  try {
    const { data } = await axios.get('/master/transportir')
    // format yang diharapkan: [{id, nama_transportir}] atau [{value,title}]
    if (Array.isArray(data)) {
      transportirOptions.value = data.map((x: any) => {
        if ('title' in x && 'value' in x) return { title: String(x.title), value: Number(x.value) }
        return { title: String(x.nama_transportir ?? x.name ?? x.label ?? x.nama ?? x.title ?? x.id), value: Number(x.id ?? x.value) }
      })
    } else if (Array.isArray(data?.data)) {
      transportirOptions.value = data.data.map((x: any) => ({
        title: String(x.nama_transportir ?? x.name ?? x.label ?? x.nama ?? x.title ?? x.id),
        value: Number(x.id ?? x.value),
      }))
    } else {
      transportirOptions.value = []
    }
  } catch (e: any) {
    console.error('[Sopir] transportir options error:', e?.response?.status, e?.response?.data || e)
    transportirOptions.value = []
    notify('Gagal load transportir options', 'error')
  } finally {
    loadingTransportir.value = false
  }
}

const fetchRows = async () => {
  loading.value = true
  try {
    const params: any = {
      page: currentPage.value,
      per_page: rowPerPage.value,
    }
    if (searchQuery.value) params.search = searchQuery.value
    if (selectedActive.value !== null) params.is_active = selectedActive.value

    const { data } = await axios.get('/master/sopir', { params })

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    if (currentPage.value > totalPage.value) currentPage.value = totalPage.value
  } catch (e: any) {
    console.error('[Sopir] FETCH ERROR:', e?.response?.status, e?.response?.data || e)
    rows.value = []
    totalRows.value = 0
    totalPage.value = 1
    notify('Gagal load data sopir', 'error')
  } finally {
    loading.value = false
  }
}

// --------------------
// Watchers (search debounce)
// --------------------
let t: any = null
watch(searchQuery, () => {
  clearTimeout(t)
  t = setTimeout(() => {
    currentPage.value = 1
    fetchRows()
  }, 400)
})

watch([selectedActive, rowPerPage, currentPage], () => {
  fetchRows()
})

watchEffect(() => {
  // initial load (sekali)
  fetchRows()
})

// pagination text
const paginationData = computed(() => {
  const firstIndex = totalRows.value ? ((currentPage.value - 1) * rowPerPage.value) + 1 : 0
  const lastIndex = rows.value.length + ((currentPage.value - 1) * rowPerPage.value)
  return `${firstIndex}-${lastIndex} of ${totalRows.value}`
})

// --------------------
// CRUD actions
// --------------------
const openCreate = async () => {
  isEdit.value = false
  resetForm()
  if (!transportirOptions.value.length) await fetchTransportirOptions()
  isDialogOpen.value = true
}

const openEdit = async (row: Sopir) => {
  isEdit.value = true
  formErrors.value = {}
  if (!transportirOptions.value.length) await fetchTransportirOptions()

  form.value = {
    id_master: row.id_master,
    id_transportir: row.id_transportir ?? null,
    nama_sopir: row.nama_sopir ?? '',
    is_active: isActiveBool(row.is_active),
    photo_file: null,
  }
  isDialogOpen.value = true
}

const closeDialog = () => {
  isDialogOpen.value = false
}

const save = async () => {
  formLoading.value = true
  formErrors.value = {}

  // simple client validate
  if (!form.value.id_transportir) {
    formErrors.value.id_transportir = 'Transportir wajib dipilih'
    formLoading.value = false
    return
  }
  if (!form.value.nama_sopir?.trim()) {
    formErrors.value.nama_sopir = 'Nama sopir wajib diisi'
    formLoading.value = false
    return
  }

  try {
    const fd = buildFormData(form.value)

    if (isEdit.value && form.value.id_master) {
      await axios.post(`/master/sopir/${form.value.id_master}?_method=PUT`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      notify('Berhasil update sopir', 'success')
    } else {
      await axios.post('/master/sopir', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      notify('Berhasil tambah sopir', 'success')
    }

    isDialogOpen.value = false
    await fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Sopir] SAVE ERROR:', res?.status, res?.data || e)

    if (res?.status === 422 && res.data?.errors) {
      Object.keys(res.data.errors).forEach(k => {
        formErrors.value[k] = res.data.errors[k][0]
      })
      notify('Validasi gagal', 'warning')
      return
    }

    notify(res?.data?.message || 'Gagal simpan data', 'error')
  } finally {
    formLoading.value = false
  }
}

const openDelete = (row: Sopir) => {
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
    await axios.delete(`/master/sopir/${deleteTarget.value.id_master}`)
    notify(`Sopir "${deleteTarget.value.nama_sopir}" berhasil dihapus`, 'success')
    closeDelete()
    await fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Sopir] DELETE ERROR:', res?.status, res?.data || e)
    notify(res?.data?.message || 'Gagal menghapus', 'error')
  } finally {
    deleteLoading.value = false
  }
}

// file input handler
const onPickPhoto = (files: File[] | File | null) => {
  // Vuetify kadang kirim File[] / File / null
  if (!files) {
    form.value.photo_file = null
    return
  }
  if (Array.isArray(files)) {
    form.value.photo_file = files[0] ?? null
    return
  }
  form.value.photo_file = files
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
              label="Cari (nama sopir / transportir)"
              placeholder="Cari (nama sopir / transportir)"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" sm="6">
            <VSelect
              v-model="selectedActive"
              label="Status"
              :items="activeItems"
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
          + Tambah Sopir
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th scope="col">NAMA SOPIR</th>
            <th scope="col">TRANSPORTIR</th>
            <th scope="col">PHOTO</th>
            <th scope="col">STATUS</th>
            <th scope="col" class="text-center" style="width: 5rem;">ACTIONS</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="r in rows" :key="r.id_master">
            <td class="text-medium-emphasis">
              <div class="d-flex flex-column">
                <span class="font-weight-medium">{{ r.nama_sopir }}</span>
                <span class="text-caption text-medium-emphasis">#{{ r.id_master }}</span>
              </div>
            </td>

            <td class="text-medium-emphasis">
              {{ r.nama_transportir || transportirTitleById(r.id_transportir) }}
            </td>

            <td>
              <VAvatar size="34" variant="tonal">
                <VImg v-if="r.photo" :src="r.photo" cover />
                <span v-else class="text-caption">NA</span>
              </VAvatar>
            </td>

            <td>
              <VChip
                :color="isActiveBool(r.is_active) ? 'success' : 'secondary'"
                size="small"
                class="text-capitalize"
              >
                {{ isActiveLabel(r.is_active) }}
              </VChip>
            </td>

            <td class="text-center" style="width: 5rem;">
              <VBtn size="x-small" color="default" variant="plain" icon>
                <VIcon size="24" icon="mdi-dots-vertical" />

                <VMenu activator="parent">
                  <VList>
                    <VListItem href="javascript:void(0)" @click="openEdit(r)">
                      <template #prepend>
                        <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                      </template>
                      <VListItemTitle>Edit</VListItemTitle>
                    </VListItem>

                    <VListItem href="javascript:void(0)" @click="openDelete(r)">
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
            <td colspan="5" class="text-center">No data available</td>
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
    <VDialog v-model="isDialogOpen" max-width="640">
      <VCard>
        <VCardTitle class="text-h6">
          {{ isEdit ? 'Edit Sopir' : 'Tambah Sopir' }}
        </VCardTitle>

        <VCardText class="d-flex flex-column gap-3">
          <VSelect
            v-model="form.id_transportir"
            :items="transportirOptions"
            item-title="title"
            item-value="value"
            label="Transportir"
            :loading="loadingTransportir"
            :error-messages="formErrors.id_transportir"
            clearable
          />

          <VTextField
            v-model="form.nama_sopir"
            label="Nama Sopir"
            :error-messages="formErrors.nama_sopir"
          />

          <VFileInput
            label="Photo (optional)"
            accept="image/*"
            prepend-icon="mdi-camera"
            :model-value="form.photo_file ? [form.photo_file] : []"
            @update:model-value="onPickPhoto"
            show-size
            clearable
          />

          <VSwitch v-model="form.is_active" label="Aktif" inset />
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
          Kamu yakin ingin menghapus sopir
          <b>{{ deleteTarget?.nama_sopir }}</b>
          ?
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
