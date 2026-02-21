<script setup lang="ts">
import axios from '@axios'
import { computed, ref, watch } from 'vue'

type Volume = {
  id: number
  volume_angkut: number
  is_active: boolean
  created_time?: string
  created_ip?: string
  created_by?: string
  lastupdate_time?: string
  lastupdate_ip?: string
  lastupdate_by?: string
}

type VolumeForm = {
  id?: number
  volume_angkut: number
  is_active: boolean
}

// table
const loading = ref(false)
const rows = ref<Volume[]>([])

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
const isSaving = ref(false)
const isEdit = ref(false)
const formLoading = ref(false)
const formErrors = ref<Record<string, string>>({})

const form = ref<VolumeForm>({
  volume_angkut: 0,
  is_active: true,
})

const volumeFormatted = computed({
  get() {
    if (!form.value.volume_angkut) return ''
    return new Intl.NumberFormat('id-ID').format(
      Number(form.value.volume_angkut)
    )
  },
  set(value: string) {
    // hapus semua selain angka
    const number = value.replace(/\D/g, '')
    form.value.volume_angkut = number ? Number(number) : 0
  },
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
const deleteTarget = ref<Volume | null>(null)

const openDelete = (row: Volume) => {
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

    // baseURL sudah /api => cukup /master/Produk
    const { data } = await axios.get('/master/volume', { params })

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    if (currentPage.value > totalPage.value)
      currentPage.value = totalPage.value
  } catch (e: any) {
    console.error('[volume] FETCH ERROR:', e?.response?.status, e?.response?.data || e)
    rows.value = []
    totalRows.value = 0
    totalPage.value = 1
    notify('Gagal memuat data volume', 'error')
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
  form.value = { volume_angkut: 0, is_active: true }
  isDialogOpen.value = true
}

const openEdit = (row: Volume) => {
  isEdit.value = true
  formErrors.value = {}
  form.value = { id: row.id, volume_angkut: row.volume_angkut, is_active: row.is_active }
  isDialogOpen.value = true
}

const closeDialog = () => {
  isDialogOpen.value = false
}

const save = async () => {
  formLoading.value = true
  formErrors.value = {}
  isSaving.value = true

  const payload = {
    volume_angkut: form.value.volume_angkut,
    is_active: form.value.is_active,
  }

  try {
    if (isEdit.value && form.value.id) {
      await axios.put(`/master/volume/${form.value.id}`, payload)
      notify('Volume Angkut berhasil diperbarui', 'success')
    } else {
      await axios.post('/master/volume', payload)
      notify('Volume Angkut berhasil ditambahkan', 'success')
    }

    isDialogOpen.value = false
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Volume] SAVE ERROR:', res?.status, res?.data || e)

    if (res?.status === 422 && res.data?.errors) {
      Object.keys(res.data.errors).forEach(k => {
        formErrors.value[k] = res.data.errors[k][0]
      })
      notify('Validasi gagal, cek input', 'warning')
      return
    }

    notify(res?.data?.message || 'Gagal menyimpan data', 'error')
  } finally {
    isSaving.value = false
    formLoading.value = false
  }
}

const confirmDelete = async () => {
  if (!deleteTarget.value) return

  deleteLoading.value = true
  try {
    await axios.delete(`/master/volume/${deleteTarget.value.id}`)
    notify(`Volume "${deleteTarget.value.volume_angkut}" berhasil dihapus`, 'success')
    closeDelete()
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Volume] DELETE ERROR:', res?.status, res?.data || e)
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
              label="Cari Volume"
              placeholder="Cari"
              density="compact"
              suffix="Liter"
              clearable
            />
            <template #append-inner>%</template>
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
           <VIcon start icon="ri-add-circle-line"/> Tambah Volume Angkut
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead class="bg-grey-lighten-4">
          <tr>
            <th scope="col" style="width: 3rem;">NO</th>
            <th scope="col">VOLUME ANGKUT</th>
            <th scope="col">STATUS</th>
            <th scope="col" class="text-center" style="width: 5rem;">AKSI</th>
          </tr>
        </thead>

        <tbody>
           <tr v-for="(p, index) in rows" :key="p.id">
            <td class="text-center">{{ index + 1 }}</td>
            <td class="text-medium-emphasis">
                {{ Number(p.volume_angkut).toLocaleString('id-ID') }}
            </td>

            <td>
              <VChip
                :color="p.is_active ? 'info' : 'error'"
                size="small"
                class="text-capitalize">
                {{ p.is_active ? 'active' : 'inactive' }}
              </VChip>
            </td>

            <td class="text-center" style="width: 5rem;">
              <VBtn size="34" class="mr-1" variant="tonal" color="primary" @click="openEdit(p)" >
                 <VIcon icon="ri-edit-2-line"/>
              </VBtn>
              <VBtn size="34" variant="tonal" color="error" @click="openDelete(p)" >
                 <VIcon icon="ri:delete-bin-line"/>
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
        <div class="d-flex align-center me-3" style="width: 171px;">
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
    <template>
    <VDialog v-model="isDialogOpen" max-width="640">
      <VCard>
        <VCardTitle class="text-h6">
          {{ isEdit ? 'Edit Volume' : 'Tambah Volume' }}
        </VCardTitle>

        
        <VCardText class="d-flex flex-column gap-3">
            <VRow>
                <VCol cols="12">
                    <VRow no-gutters>
                    <VCol cols="12" md="3">
                        <label for="volume">Volume Angkut *</label>
                    </VCol>

                    <VCol cols="12" md="9" >
                        <VTextField v-model="volumeFormatted" type="text" step="any" suffix="Liter"  />
                    </VCol>
                    </VRow>
                </VCol>
            </VRow>
          <VSwitch v-model="form.is_active" label="Aktif" inset />
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn color="error" variant="text" @click="closeDialog">Batal</VBtn>
          <VBtn  color="primary" variant="tonal" :loading="formLoading" @click="save">Simpan</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Confirm -->
    <VDialog v-model="deleteDialog" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">Konfirmasi Hapus</VCardTitle>

        <VCardText>
          Apakah Anda yakin ingin menghapus Volume
          <b>{{ deleteTarget?.volume_angkut }}</b> Liter?
          <div class="text-body-2 opacity-70 mt-2">
            Data yang sudah dihapus tidak dapat dikembalikan.
          </div>
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn variant="text" :disabled="deleteLoading" @click="closeDelete">Batal</VBtn>
          <VBtn color="error" :loading="deleteLoading" @click="confirmDelete">Hapus</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>


     <VDialog
          v-model="isSaving"
          width="300"
        >
      <VCard
        color="primary"
        width="300"
      >
        <VCardText class="pt-3 text-white">
          Menyimpan Data
          <VProgressLinear
            indeterminate
            class="mt-4"
            color="#fff"
          />
        </VCardText>
      </VCard>
    </VDialog>
    </template>

    <!-- Snackbar -->
    <VSnackbar
      v-model="snackbar"
      :timeout="snackTimeout"
      :color="snackColor"
      location="top end"
    >
      {{ snackText }}

      <template #actions>
        <VBtn variant="text" color="white" @click="snackbar = false">Close</VBtn>
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
