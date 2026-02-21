<script setup lang="ts">
import axios from '@axios'
import { computed, ref, watch } from 'vue'

type HargaJual = {
  id:number
  periode_awal: string
  periode_akhir: string
  id_area: number
  pbbkb: number
  produk: number
  harga_normal: number
  harga_sm: number
  harga_om: number
  note_jual: string
  harga_ceo: number
  harga_coo: number
  created_time?: string
  created_ip?: string
  created_by?: string
  lastupdate_time?: string
  lastupdate_ip?: string
  lastupdate_by?: string
  getproduk?: {
    id: number
    merk_dagang: string
    jenis_produk: string
  }
  area?: {
    id: number
    nama_area: string
  }
}

type HargaJualForm = {
  id?: number
  periode_awal: string
  periode_akhir: string
  id_area: number | null
  pbbkb: number | null
  produk: number | null
  harga_normal: number
  harga_sm: number
  harga_om: number
  note_jual: string
  harga_ceo: number
  harga_coo: number
  label: string
}

// table
const loading = ref(false)
const rows = ref<HargaJual[]>([])

// filters
const searchQuery = ref('')
const selectedStatus = ref<string | null>(null)
const selectedArea = ref<number | null>(null)
const selectedProduk = ref<number | null>(null)

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

const form = ref<HargaJualForm>({
  id_area: null,
  pbbkb: null,
  produk: null,
  periode_awal:'',
  periode_akhir:'',
  harga_normal: 0,
  note_jual:'',
  harga_ceo: 0,
  harga_om: 0,
  harga_coo: 0,
  harga_sm: 0,
  label: '',
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
const deleteTarget = ref<HargaJual | null>(null)

const openDelete = (row: HargaJual) => {
  deleteTarget.value = row
  deleteDialog.value = true
}
const closeDelete = () => {
  deleteDialog.value = false
  deleteTarget.value = null
}


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
    if (selectedProduk.value) params.produk = selectedProduk.value
    if (selectedArea.value) params.id_area = selectedArea.value

    const { data } = await axios.get('/master/harga-jual', { params })

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    console.log(data)
    if (currentPage.value > totalPage.value)
      currentPage.value = totalPage.value
  } catch (e: any) {
    console.error('[Harga Jual] FETCH ERROR:', e?.response?.status, e?.response?.data || e)
    rows.value = []
    totalRows.value = 0
    totalPage.value = 1
    notify('Gagal memuat data Harga Dasar Jual', 'error')
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

const openEdit = (row: HargaJual) => {
  isEdit.value = true
  formErrors.value = {}
  form.value = {
    id: row.id,
    id_area: row.id_area,
    pbbkb: row.pbbkb,
    produk: row.produk,
    periode_awal: row.periode_awal,
    periode_akhir: row.periode_akhir,
    harga_normal: row.harga_normal,
    note_jual: row.note_jual,
    harga_ceo: row.harga_ceo,
    harga_coo: row.harga_coo,
    harga_om: row.harga_om,
    harga_sm: row.harga_sm,
    label:
      row.getproduk
        ? `${row.getproduk.merk_dagang} - ${row.getproduk.jenis_produk}`
        : ''
  }

  isDialogOpen.value = true

}

const closeDialog = () => {
  isDialogOpen.value = false
}

const save = async () => {
  formLoading.value = true
  formErrors.value = {}
  isSaving.value = true

  // payload wajib sesuai backend!
  const payload = {
    periode_awal: form.value.periode_awal,
    periode_akhir: form.value.periode_akhir,
    id_area: form.value.id_area,
    produk: form.value.produk,

    harga_normal: form.value.harga_normal,
    harga_sm: form.value.harga_sm,
    harga_om: form.value.harga_om,
    harga_coo: form.value.harga_coo,
    harga_ceo: form.value.harga_ceo,

    note_jual: form.value.note_jual,
  }

  try {
    if (isEdit.value) {
      await axios.put(`/master/harga-jual/${form.value.id}`, payload)

      notify('Harga Jual berhasil diperbarui', 'success')
    }

    isDialogOpen.value = false
    fetchRows()
  } catch (e: any) {
    const res = e?.response

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
    await axios.delete(`/master/harga-jual/${deleteTarget.value.id}`)
    notify(`Harga Jual - "${deleteTarget.value.harga_normal}" berhasil dihapus`, 'success')
    closeDelete()
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Harga Jual] DELETE ERROR:', res?.status, res?.data || e)
    notify(res?.data?.message || 'Gagal menghapus data', 'error')
  } finally {
    deleteLoading.value = false
  }
}

const areaList = ref<any[]>([])
const produkList = ref<any[]>([])

const getArea = async () => {
  const res = await axios.get('/area')
  areaList.value = res.data
}
const getProduk = async () => {
  const res = await axios.get('/produk')
  produkList.value = res.data
}

getArea()
getProduk()

console.log('produkList:', produkList)
const formatDate = (dateStr: string) => {
  const date = new Date(dateStr)
  const day = String(date.getDate()).padStart(2, '0')
  const month = String(date.getMonth() + 1).padStart(2, '0') // bulan mulai dari 0
  const year = date.getFullYear()
  return `${day}/${month}/${year}`
}

</script>

<template>
  <section>
     <VCard
      title="Filters"
      class="mb-6">
    
      <VCardText>
        <VRow>
            <VCol
            cols="12"
            sm="4"
          >
           <VTextField
              v-model="searchQuery"
              label="Cari Periode"
              placeholder="Cari Periode"
              density="compact"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            sm="4"
          >
              <VSelect
                v-model="selectedArea"
                :items="areaList"
                item-title="nama_area"
                item-value="id"
                label="Pilih Area"
                density="compact"
                clearable
                />
          </VCol>

          <VCol
            cols="12"
            sm="4"
          >
            <VSelect
            v-model="selectedProduk"
            :items="produkList"
             :item-title="produk => `${produk.merk_dagang} - ${produk.jenis_produk}`"
            item-value="id"
            label="Pilih Produk"
            density="compact"
            clearable
            />
          </VCol>

        
        </VRow>
      </VCardText>
    </VCard>
    <!-- Filters -->

    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn
            to="harga-jual/add"
          >
          <VIcon start icon="ri-add-circle-line"/> Tambah Data
          </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead class="bg-grey-lighten-4">
          <tr>
            <th scope="col" style="width: 3rem;">NO</th>
            <th scope="col">PERIODE</th>
            <th scope="col">AREA</th>
            <th scope="col">PRODUK</th>
            <th scope="col">HARGA DASAR</th>
            <th scope="col" class="text-center" style="width: 5rem;">AKSI</th>
          </tr>
        </thead>

        <tbody>
           <tr v-for="(p, index) in rows" >
            <td class="text-center">{{ index + 1 }}</td>
              <td class="text-medium-emphasis">{{formatDate(p.periode_awal)  +' - '+  formatDate(p.periode_akhir) }}</td>
            <td class="text-medium-emphasis">{{ p.area?.nama_area ?? '-' }}</td>
            <td class="text-medium-emphasis">{{ p.getproduk?.jenis_produk+ ' - '+ p.getproduk?.merk_dagang}}</td>
              <td class="text-medium-emphasis">{{ Number(p.harga_normal).toLocaleString('id-ID') }}</td>

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
  <VDialog v-model="isDialogOpen" max-width="1000">
    <VCard>
      <VCardTitle class="text-h6">
        Edit Harga Jual
      </VCardTitle>

      <VCardText class="d-flex flex-column gap-4">

        <VRow>
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.periode_awal"
              type="date"
              label="Periode Awal"
              :error-messages="formErrors.periode_awal"
            />
          </VCol>

          <VCol cols="12" md="6">
            <VTextField
              v-model="form.periode_akhir"
              type="date"
              label="Periode Akhir"
              :error-messages="formErrors.periode_akhir"
            />
          </VCol>
        </VRow>

        <VRow>
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.id_area"
              :items="areaList"
              item-title="nama_area"
              item-value="id"
              label="Area"
              :error-messages="formErrors.id_area"
            />
          </VCol>

          <VCol cols="12" md="6">
            <VSelect
              v-model="form.produk"
              :items="produkList"
              item-title="merk_dagang"
              item-value="id"
              :error-messages="formErrors.produk"
              label="Produk"
            />
          </VCol>
        </VRow>

     
       <VRow>
        <VCol cols="12" md="2">
          <VTextField v-model="form.harga_normal" type="number" label="Harga Normal" />
        </VCol>

        <VCol cols="12" md="2">
          <VTextField v-model="form.harga_sm" type="number" label="Harga SM" />
        </VCol>

        <VCol cols="12" md="2">
          <VTextField v-model="form.harga_om" type="number" label="Harga OM" />
        </VCol>

        
        <VCol cols="12" md="2">
          <VTextField v-model="form.harga_coo" type="number" label="Harga COO" />
        </VCol>
        
        <VCol cols="12" md="2">
          <VTextField v-model="form.harga_ceo" type="number" label="Harga CEO" />
        </VCol>
      </VRow>

        <VTextField
          v-model="form.note_jual"
          label="Catatan"
        />
      </VCardText>

      <VCardActions class="justify-end">
        <VBtn variant="text" @click="closeDialog">Batal</VBtn>
        <VBtn color="primary" variant="tonal" :loading="formLoading" @click="save">
          Simpan
        </VBtn>
      </VCardActions>
    </VCard>
    </VDialog>

    <!-- Delete Confirm -->
    <VDialog v-model="deleteDialog" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">Konfirmasi Hapus</VCardTitle>

        <VCardText>
          Apakah Anda yakin ingin menghapus data
          <b>{{ deleteTarget?.periode_awal +" - "+ deleteTarget?.periode_awal  }}</b>?
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
.hint-blue >>> .v-field__hint {
  color: #1976d2; /* biru material */
}
</style>

<route lang="yaml">
meta:
  action: read
  subject: Master
</route>
