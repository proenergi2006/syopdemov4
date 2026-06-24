<script setup lang="ts">
import axios from '@axios'
import { ref, computed, onMounted, watch } from 'vue'
import { getApiErrorMessage } from '@/utils/apiHelper'
import {
  showConfirmAlert,
  showErrorAlert,
  showLoadingAlert,
  showSuccessAlert,
  showWarningAlert,
  closeAlert,
} from '@/utils/alert'
import { useRouter } from 'vue-router'

const router = useRouter()
const onEdit = (item: any) => {
  router.push({
  path: '/purchaseSupplier/po-supplier/add',
  query: { id: item }
})
}

type InventoryPO = {
  id_master: number
  tanggal_inven: string
  volume_po: number
  volume: number
  jenis: number
  harga_tebus: number
  harga_po: number
  disposisi_gain_loss?: number
  status_label?: string
  cfo_result: 0,
  cfo_pic: '',
  cfo_tanggal: '',
  cfo_summary: '',
  revert_cfo: '',
  revert_cfo_summary: '',
  ceo_result: 0,
  ceo_pic: '',
  ceo_tanggal: '',
  is_resubmission:number,
  resubmission_count:number,


  po?: { harga_po: number ,harga_tebus: number,nomor_po: string}
}

// STATE
const rows = ref<InventoryPO[]>([])
const loading = ref(false)

const rowPerPage = ref(10)
const currentPage = ref(1)

const totalData = ref(0)
const totalPage = ref(1)
const userRoles = ref<string[]>([])

// PAGINATION TEXT
const paginationData = computed(() => {
  const start = (currentPage.value - 1) * rowPerPage.value + 1
  const end = Math.min(currentPage.value * rowPerPage.value, totalData.value)
  return `${start}-${end} of ${totalData.value}`
})

// FORMAT DATE
const formatDate = (date: string) => {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('id-ID')
}
const getProfile = async () => {
  try {
    const res = await axios.get('/auth/me')

    userRoles.value = res.data.role

    // console.log('ROLE:', res)
  } catch (err) {
    console.error(err)
  }
}

const formatMoney = (value: number | null | undefined): string => {
  if (value === null || value === undefined) return '0'
  return new Intl.NumberFormat('id-ID').format(value)
}
const canApprove = (item: any) => {
  if (userRoles.value.includes('CFO') && item.disposisi_po === 1) return true
  if (userRoles.value.includes('CEO') && item.disposisi_po === 2) return true
  return false
}


const search = ref({
  keyword: '',
  status: '',
})

// FETCH DATA
const getData = async () => {
  try {
    loading.value = true

    const res = await axios.get('/inventory/gain-loss', {
      params: {
        page: currentPage.value,
        per_page: rowPerPage.value,
        keyword: search.value.keyword,
        status: search.value.status,
      },
    })

    // 🔥 SAFE MAPPING (anti undefined error)
    rows.value = res.data?.data ?? []
    totalData.value = res.data?.total ?? 0
    totalPage.value = res.data?.last_page ?? 1

  } catch (err) {
    console.error('FETCH ERROR:', err)
    rows.value = []
  } finally {
    loading.value = false
  }
}


const vendorList = ref<any[]>([])
const terminalList = ref<any[]>([])


const getVendor = async (): Promise<void> => {
  try {
    const res = await axios.get('/master/vendor/dropdown-select')

    const data = Array.isArray(res.data?.data)
      ? res.data.data
      : Array.isArray(res.data)
        ? res.data
        : []

    vendorList.value = data
  } catch (error: unknown) {
    vendorList.value = []

    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(error, 'Gagal memuat data vendor.'),
    })
  }
}

const getTerminal = async () => {
  const res = await axios.get('/terminal')
  terminalList.value = res.data.map((p: any) => ({
    id: p.id,
    nama_terminal: p.nama_terminal,
    lokasi_terminal: p.lokasi_terminal,
  }))
}

const formatNumber = (value: number) => {
  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 20,
  }).format(value)
}

const goToEdit = (public_id: number): void => {
  router.push(`/purchaseSupplier/gain-loss/${public_id}`)
  // router.push({
  // path: `/purchaseSupplier/po-supplier/approve/${po_number}`,
  // query: {
  //   id: id_master
  // }
// })
}
const getRowClass = (item: any) => {

  // CEO role
  if (userRoles.value.includes('CEO') && item.ceo_result === 0) {
    return 'bg-grey-100'
  }

  return ''
}

// AUTO FETCH
onMounted(() => {
  getData()
  getVendor()
  getTerminal()
  getProfile()
})

// kalau page berubah
watch(
  [search, currentPage, rowPerPage],
  async () => {
    await getData()
  },
  { deep: true }
)

// kalau per page berubah
watch(rowPerPage, () => {
  currentPage.value = 1
  getData()
})

const getStatusLabel = (val: unknown) => {
  const map: Record<number, string> = {
    1: 'Menunggu Approval',
    2: 'Terverifikasi',
    3: 'Ditolak'
  }

  return map[Number(val)] ?? '-'
}

const chipColor: Record<number, string> = {
  1: 'info',
  2: 'success',
  3: 'error',
}
const statusItems = [
  { title: 'Semua', value: '' },
  { title: 'Pending', value: 1 },
  { title: 'Approve', value: 2 },
  { title: 'Reject', value: 3 },
]

</script>
<template>
  <section>
    <!-- Filters -->
    

    <VCard class="mb-4 pa-4">
      <h3 class="mb-3">PENCARIAN</h3>

      <VRow>
        <VCol cols="12" md="6">
          <VTextField
            v-model="search.keyword"
            label="Kata Kunci"
            density="comfortable"
            clearable
          />
        </VCol>
      
        <VCol cols="12" sm="3">
          <VSelect
            v-model="search.status"
            label="Status"
            :items="statusItems"
            item-title="title"
            item-value="value"
            density="comfortable"
            clearable
          />
        </VCol>
      </VRow>

      <!-- <div class="d-flex gap-2 mt-4">
        <VBtn color="info">
          Cari
        </VBtn>
      </div> -->
    </VCard>
    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">

        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">
          Loading...
        </VChip>
      </VCardText>

      <VDivider />

      <VTable >
        <thead>
         <tr>
           <th>No </th>
           <th>Kode PO</th>
           <th>Harga Dasar</th>
           <th>Volume</th>
           <th>Jenis</th>
           <th>Status</th>
           <th>Aksi</th>
         </tr>
       </thead>
        <tbody>
          <tr
            v-for="(v, index) in rows"
            :key="v.id_master"
            :class="getRowClass(v)"
          >
            <td>
              {{ (currentPage - 1) * rowPerPage + index + 1 }}
            </td>

            <td class="text-no-wrap">{{ v.po?.nomor_po || '-' }}
            </td>

            <td> PO : {{ formatMoney(v.po?.harga_po) }} <br> RI : {{ formatMoney(v.po?.harga_tebus) }}</td>

            <td> 
              <div>{{ formatMoney(v.volume) || '-' }}</div>
            </td>

            <td class="text-no-wrap">{{ v.jenis == 1 ? 'Gain':'Loss' }}</td>



            <td>
              <VChip size="small"  :color="chipColor[v.disposisi_gain_loss??0]">
               {{ getStatusLabel(v.disposisi_gain_loss) }}
              </VChip>
            </td>

              <td class="text-center" style="width: 5rem;">
                <VBtn size="34" class="mr-1" variant="tonal" color="primary" @click="goToEdit(v.id_master)">
                  <VIcon icon="ri-information-2-line"/>
                </VBtn>
              </td>
          </tr>
        </tbody>

       <tfoot v-if="!rows.length && !loading">
        <tr>
          <td colspan="9" class="text-center">
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
  </section>
</template>
<style>
.row-pending {
  background-color: #f5f5f5 !important;
  opacity: 0.7;
}
</style>