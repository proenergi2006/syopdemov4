<script setup lang="ts">
import axios from '@axios'
import { computed, ref, watch } from 'vue'

const route = useRoute()

watch(
  () => route.fullPath, // atau route.query jika kamu pake query params
  () => {
    fetchRows() // fetch data ulang setiap navigasi
  }
)
type HargaPertamina = {
  id: number
  periode_awal: string
  periode_akhir: string
  id_area: number
  id_produk: number
  harga_minyak: number
  created_time?: string
  created_ip?: string
  created_by?: string
  lastupdate_time?: string
  lastupdate_ip?: string
  lastupdate_by?: string
  produk?: {
    id: number
    merk_dagang: string
    jenis_produk: string
  }
  area?: {
    id: number
    nama_area: string
  }
}

type HargaPertaminaForm = {
  id?: number
  periode_awal: string
  periode_akhir: string
  id_area: number | null
  id_produk: number | null
  harga_minyak: number
}

// table
const loading = ref(false)
const rows = ref<HargaPertamina[]>([])

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

const form = ref<HargaPertaminaForm>({
  id_area: null,
  id_produk: null,
  periode_awal:'',
  periode_akhir:'',
  harga_minyak: 0,
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
const deleteTarget = ref<HargaPertamina | null>(null)

const openDelete = (row: HargaPertamina) => {
  deleteTarget.value = row
  deleteDialog.value = true
}
const closeDelete = () => {
  deleteDialog.value = false
  deleteTarget.value = null
}

const openEdit = (row: HargaPertamina) => {
  isEdit.value = true
  isDialogOpen.value = true
  formErrors.value = {}

  form.value = {
    id: row.id_produk, // sesuaikan kalau ada ID khusus
    periode_awal: row.periode_awal,
    periode_akhir: row.periode_akhir,
    id_area: row.id_area,
    id_produk: row.id_produk,
    harga_minyak: row.harga_minyak,
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
    if (selectedStatus.value !== null) params.is_active = selectedStatus.value
    if (selectedProduk.value) params.id_produk = selectedProduk.value
    if (selectedArea.value) params.id_area = selectedArea.value

    const { data } = await axios.get('/master/harga-pertamina', { params })

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalRows.value = Number(data?.total ?? 0)

    const lastPage = Number(data?.last_page ?? 1)
    totalPage.value = lastPage > 0 ? lastPage : 1

    if (currentPage.value > totalPage.value)
      currentPage.value = totalPage.value
  } catch (e: any) {
    console.error('[Harga Pertamina] FETCH ERROR:', e?.response?.status, e?.response?.data || e)
    rows.value = []
    totalRows.value = 0
    totalPage.value = 1
    notify('Gagal memuat data Harga Dasar Pertamina', 'error')
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


const save = async () => {
  formLoading.value = true
  formErrors.value = {}

  try {
    // PUT update
    await axios.put(`/master/harga-pertamina/${form.value.id}`, form.value)

    notify("Berhasil mengupdate data", "success")
    isDialogOpen.value = false
    fetchRows()
  } catch (e: any) {
    formErrors.value = e?.response?.data?.errors ?? {}
    notify("Gagal mengupdate data", "error")
  } finally {
    formLoading.value = false
  }
}

const confirmDelete = async () => {
  if (!deleteTarget.value) return

  deleteLoading.value = true
  try {
    await axios.delete(`/master/harga-pertamina/${deleteTarget.value.id}`)
    notify(`Harga Pertamina - "${deleteTarget.value.harga_minyak}" berhasil dihapus`, 'success')
    closeDelete()
    fetchRows()
  } catch (e: any) {
    const res = e?.response
    console.error('[Harga Pertamina] DELETE ERROR:', res?.status, res?.data || e)
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

    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <!-- <VBtn color="primary" @click="openCreate">
           <VIcon start icon="ri-add-circle-line"/> Tambah Data
        </VBtn> -->
        <VBtn
            to="harga-pertamina/add"
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
            <th scope="col">HARGA</th>
            <th scope="col" class="text-center" style="width: 5rem;">AKSI</th>
          </tr>
        </thead>

        <tbody>
           <tr v-for="(p, index) in rows" >
            <td class="text-center">{{ index + 1 }}</td>
            <td class="text-medium-emphasis">{{formatDate(p.periode_awal)  +' - '+  formatDate(p.periode_akhir) }}</td>
            <td class="text-medium-emphasis">{{ p.area?.nama_area ?? '-' }}</td>
            <td class="text-medium-emphasis">{{ p.produk?.jenis_produk+ '-'+ p.produk?.merk_dagang}}</td>
            <td class="text-medium-emphasis">{{ Number(p.harga_minyak).toLocaleString('id-ID') }}</td>

            <td class="text-center" style="width: 5rem;">
            <!-- <VBtn
              size="34"
              class="mr-1"
              variant="tonal"
              color="primary"
              :to="{
                path: '/harga-pertamina/add',
                query: {
                  awal: p.periode_awal,
                  akhir: p.periode_akhir,
                  area: p.id_area,
                  produk: p.id_produk,
                }
              }"
            >
              <VIcon icon="ri-edit-2-line"/>
            </VBtn> -->
            <VBtn
              size="34"
              class="mr-1"
              variant="tonal"
              color="primary"
              @click="openEdit(p)"
            >
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
  
      <VDialog v-model="isDialogOpen" max-width="600">
  <VCard>
    <VCardTitle class="text-h6">
      Edit Harga Pertamina
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

      <VSelect
        v-model="form.id_area"
        :items="areaList"
        item-title="nama_area"
        item-value="id"
        label="Area"
        :error-messages="formErrors.id_area"
      />

      <VSelect
        v-model="form.id_produk"
        :items="produkList"
        :item-title="p => `${p.merk_dagang} - ${p.jenis_produk}`"
        item-value="id"
        label="Produk"
        :error-messages="formErrors.id_produk"
      />

      <VTextField
        v-model="form.harga_minyak"
        type="number"
        label="Harga Minyak"
        :error-messages="formErrors.harga_minyak"
      />
    </VCardText>

    <VCardActions class="justify-end">
      <VBtn variant="text" @click="isDialogOpen = false">Batal</VBtn>
      <VBtn
        color="primary"
        variant="tonal"
        :loading="formLoading"
        @click="save"
      >
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

</style>

<route lang="yaml">
meta:
  action: read
  subject: Master
</route>
