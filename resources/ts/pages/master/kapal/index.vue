<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from '@axios'
import { useRouter } from 'vue-router'
import {
  showConfirmAlert,
  showErrorAlert,
  showLoadingAlert,
  showSuccessAlert,
  closeAlert,
} from '@/utils/alert'

import { getApiErrorMessage } from '@/utils/apiHelper'
const router = useRouter()

const loading = ref(false)

const search = ref({
  asal_angkut: '',
  tujuan_angkut: '',
  transportir: '',
})

const items = ref<any[]>([])
const transportirs = ref<any[]>([])
const currentPage = ref(1)
const rowPerPage = ref(10)
const totalPage = ref(1)
const totalItems = ref(0)

const paginationData = computed(() => {
  const start = (currentPage.value - 1) * rowPerPage.value + 1
  const end = Math.min(currentPage.value * rowPerPage.value, totalItems.value)

  return `${start}-${end} of ${totalItems.value}`
})

const fetchData = async () => {
  loading.value = true
  
  try {
    const res = await axios.get('/master/oa-kapal', {
      params: {
        page: currentPage.value,
        per_page: rowPerPage.value,
        asal_angkut: search.value.asal_angkut,
        tujuan_angkut: search.value.tujuan_angkut,
        transportir: search.value.transportir,
      },
    })

    console.log(res.data.data)
    items.value = res.data.data

    totalPage.value = res.data.last_page
    totalItems.value = res.data.total
    currentPage.value = res.data.current_page
  } finally {
    loading.value = false
  }
}

watch(
  [search, currentPage, rowPerPage],
  async () => {
    await fetchData()
  },
  { deep: true }
)

watch(
  search,
  () => {
    currentPage.value = 1
  },
  { deep: true }
)

const fetchTransportir = async () => {
  const res = await axios.get('/transportir')

  transportirs.value = res.data
}

const editDialog = ref(false)

const form = ref<any>({
  id: null,
  transportir: null,
  nama_kapal: '',
  tipe_kapal: '',
  max_kapal: 0,
  asal_angkut: '',
  tujuan_angkut: '',
  volume_angkut: 0,
  harga_angkut: 0,
})

const filteredItems = ref<any[]>([])
const totalHarga = (row: any) => {
  return Number(row.harga_angkut || 0) * Number(row.volume_angkut || 0)
}

const formatNumber = (value: number) => {
  return new Intl.NumberFormat('id-ID').format(value)
}


const addData = () => {
  router.push('/master/kapal/create')
}

const deleteData = async (id: number) => {
  try {
    const confirm = await showConfirmAlert({
      title : '<h5>Apakah yakin ingin hapus?<h5>'
    })

    if (!confirm.isConfirmed)
      return


    await axios.delete(`/master/oa-kapal/${id}`)
    await showSuccessAlert({
      title: 'Berhasil',
      text: `Simpan Data berhasil`,
      timer: 1800,
      })
    await fetchData()
  }finally{
  loading.value = false
  }


}

const openEdit = (item: any) => {
  form.value = {
    id: item.id,
    transportir: item.id_transportir,
    nama_kapal: item.nama_kapal,
    tipe_kapal: item.tipe_kapal,
    max_kapal: item.max_kapal,
    asal_angkut: item.asal_angkut,
    tujuan_angkut: item.tujuan_angkut,
    volume_angkut: item.volume_angkut,
    harga_angkut: item.harga_angkut,
  }

  editDialog.value = true
}

const updateData = async () => {
   try {
    const confirm = await showConfirmAlert({
      title : '<h5>Apakah yakin ingin submit?<h5>'
    })

    if (!confirm.isConfirmed)
      return

    showLoadingAlert()
    
    loading.value = true

      await axios.put(`/master/oa-kapal/${form.value.id}`, form.value)

    editDialog.value = false
    fetchData()
    closeAlert()

    await showSuccessAlert({
      title: 'Berhasil',
      text: `Simpan Data berhasil`,
      timer: 1800,
      })

  } finally {
    loading.value = false
  }

}

onMounted(async () => {
  await Promise.all([
    fetchTransportir(),
    fetchData(),
  ])
})
</script>

<template>
  <VContainer fluid>

    <!-- HEADER -->
    <VCard class="mb-4">
      <VCardText class="d-flex justify-space-between align-center">

        <div>
          <h4 class="text-h5 font-weight-bold">
            Master OA Kapal
          </h4>

          <div class="text-medium-emphasis">
            Data Ongkos Angkut Kapal
          </div>
        </div>

      </VCardText>
         <VCardText>

        <VRow>

          <VCol cols="12" md="3">
            <VTextField
              v-model="search.asal_angkut"
              label="Asal"
              clearable
            />
          </VCol>

          <VCol cols="12" md="3">
            <VTextField
              v-model="search.tujuan_angkut"
              label="Tujuan"
              clearable
            />
          </VCol>

          <VCol cols="12" md="3">
            <VSelect
              v-model="search.transportir"
              label="Transportir"
              :items="transportirs"
              item-title="nama_transportir"
              item-value="id"
              clearable
            />
          </VCol>

          <VCol
            cols="12"
            md="3"
            class="d-flex align-center"
          >
            <VBtn
              color="info"
              prepend-icon="tabler-search"
              @click="fetchData"
            >
              Search
            </VBtn>
          </VCol>

        </VRow>

      </VCardText>
    </VCard>


    <!-- TABLE -->
    <VCard>
        <VCardText class="d-flex flex-wrap gap-4 align-center">
       
        <VBtn
            color="primary"
            prepend-icon="tabler-plus"
            @click="addData"
        >
            Tambah Data
        </VBtn>

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">Loading...</VChip>
      </VCardText>

      <VTable>

        <thead>
          <tr>
            <th>No</th>
            <th>Transportir</th>
            <th>Volume Max (L)</th>
            <th>Asal</th>
            <th>Tujuan</th>
            <th>Volume Angkut</th>
            <th>Harga/Liter</th>
            <th>Total Harga</th>
            <th width="120">Aksi</th>
          </tr>
        </thead>

        <tbody>

          <tr v-if="loading">
            <td colspan="10" class="text-center py-6">
              Loading...
            </td>
          </tr>

          <tr v-else-if="items.length === 0">
            <td colspan="10" class="text-center py-6">
              Tidak ada data
            </td>
          </tr>

          <tr
            v-for="(item, index) in items"
            :key="item.id"
          >
            <td>{{ index + 1 }}</td>

            <td class="text-no-wrap">
             <div class="text-caption font-weight-bold">
                {{ item.transportir?.nama_suplier }}
              </div>
              {{ item.nama_kapal }} - {{ item.tipe_kapal }}
            </td>


            <td>
              {{ formatNumber(item.max_kapal) }}
            </td>

            <td>{{ item.asal_angkut }}</td>

            <td>{{ item.tujuan_angkut }}</td>

            <td>
              {{ formatNumber(item.volume_angkut) }}
            </td>

            <td>
              Rp {{ formatNumber(item.harga_angkut) }}
            </td>

            <td>
              Rp {{ formatNumber(totalHarga(item)) }}
            </td>

            <td>

              <VBtn
                icon="tabler-edit"
                size="small"
                variant="text"
                color="warning"
                @click="openEdit(item)"
              />

              <VBtn
                icon="tabler-trash"
                size="small"
                variant="text"
                color="error"
                @click="deleteData(item.id)"
              />

            </td>

          </tr>

        </tbody>

      </VTable>
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

    <VDialog v-model="editDialog" max-width="1000">
      <VCard>

        <VCardTitle>
          Edit OA Kapal
        </VCardTitle>

        <VDivider />

        <VCardText>
          <VRow>

            <VCol cols="12" md="6">
                <VSelect
                    v-model="form.transportir"
                    label="Transportir"
                    :items="transportirs"
                    item-title="nama_suplier"
                    item-value="id"
                    clearable
                  />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.nama_kapal" label="Nama Kapal" />
            </VCol>

            <VCol cols="12" md="3">
              <VTextField v-model="form.tipe_kapal" label="Tipe Kapal" />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.max_kapal"
                label="Max Kapal"
                type="number"
              />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField
                v-model="form.volume_angkut"
                label="Volume Angkut"
                type="number"
              />
            </VCol>

            <VCol cols="12" md="3">
              <VTextField
                v-model="form.harga_angkut"
                label="Harga / Liter"
                type="number"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.asal_angkut" label="Asal" />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField v-model="form.tujuan_angkut" label="Tujuan" />
            </VCol>

           

          </VRow>
        </VCardText>

        <VDivider />

        <VCardActions>
          <VSpacer />

          <VBtn
            variant="outlined"
            color="secondary"
            @click="editDialog = false"
          >
            Batal
          </VBtn>

          <VBtn
            color="primary"
            @click="updateData"
          >
            Simpan
          </VBtn>

        </VCardActions>

      </VCard>
    </VDialog>
  </VContainer>
</template>
<style>
.swal2-container.swal2-center {
  z-index: 99999 !important;
}
</style>