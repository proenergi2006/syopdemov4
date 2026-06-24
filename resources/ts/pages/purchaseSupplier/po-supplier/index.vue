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
import Swal from 'sweetalert2'

const router = useRouter()
const onEdit = (item: any) => {
  router.push({
  path: '/purchaseSupplier/po-supplier/add',
  query: { id: item }
})
}
const onGR = (id: any) => {
 router.push({
  path: '/purchaseSupplier/goods-receipt',
  query: {
    id: id,
  },
})
}
const onShip = (id: any) => {
 router.push({
  path: '/purchaseSupplier/shipping-instruction/form',
  query: {
    id: id,
  },
})
}


type InventoryPO = {
  id_master: number
  nomor_po: string
  tanggal_inven: string
  volume_po: number
  is_resubmission: number
  resubmission_count: number
  harga_tebus: number
  harga_po: number
  total_bl: number
  total_ri: number
  jenis_kirim: number
  jenis_harga: number
  cfo_tanggal: number
  ceo_tanggal: number
  disposisi_po?: number
  status_label?: string

  vendor?: { nama_vendor: string }
  produk?: { merk_dagang: string, jenis_produk: string }
  terminal?: { nama_terminal: string, lokasi_terminal:string }
}

const search = ref({
  search: '',
  terminal: '',
  vendor: '',
  tanggal_awal: '',
  tanggal_akhir: '',
})

// STATE
const rows = ref<InventoryPO[]>([])
const loading = ref(false)

const searchQuery = ref('')
const selectedStatus = ref('')

const rowPerPage = ref(10)
const currentPage = ref(1)

const totalData = ref(0)
const totalPage = ref(1)

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

// FETCH DATA
const getData = async () => {
  try {
    loading.value = true

    const res = await axios.get('/inventory/purchase-order', {
      params: {
        page: currentPage.value,
        per_page: rowPerPage.value,
        keyword: searchQuery.value,
        status: selectedStatus.value,
        search: search.value.search,
        terminal: search.value.terminal,
        vendor: search.value.vendor,
        tanggal_awal: search.value.tanggal_awal,
        tanggal_akhir: search.value.tanggal_akhir,
      },
    })

    console.log(res)
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

const printPO = async (id: any) => {
  try {
    showLoadingAlert('Memuat data cetak PO', 'Mohon menunggu')
    const response = await axios.get(`/inventory/purchase-order/print/${id}`, { responseType: 'blob' });
    const fileURL = URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
    window.open(fileURL); // bisa juga auto-print via iframe
  } catch (error) {
    console.error(error);
  }finally {
    closeAlert()
  }
};

const printPOGain = async (id: any) => {
  try {
    showLoadingAlert('Memuat data cetak PO', 'Mohon menunggu')
    const response = await axios.get(`/inventory/purchase-order/print-gain-loss/${id}`, { responseType: 'blob' });
    const fileURL = URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
    window.open(fileURL); // bisa juga auto-print via iframe
  } catch (error) {
    console.error(error);
  }finally {
    closeAlert()
  }
};

const canShowShipping = (v: any) => {
  return v.disposisi_po == 4 && v.jenis_kirim == 2
}

const canShowGR = (v: any) => {
  if (v.disposisi_po != 4) return false

  // Truck langsung bisa GR
  if (v.jenis_kirim == 1) return true

  // Ship harus sudah approve Shipping
  // return v.status == 1
}

const onCancel = async (id: number) => {
  const { value: reason } = await Swal.fire({
    title: 'Cancel PO',
    input: 'textarea',
    inputLabel: 'Alasan Cancel',
    inputPlaceholder: 'Masukkan alasan cancel',
    inputAttributes: {
      maxlength: '500',
    },
    showCancelButton: true,
    confirmButtonText: 'Ya, Cancel',
    cancelButtonText: 'Batal',
    inputValidator: value => {
      if (!value) {
        return 'Alasan cancel wajib diisi'
      }
    },
  })

  if (!reason) return

  try {
    showLoadingAlert('Menyimpan...', 'Mohon menunggu')

    await axios.post(`/inventory/purchase-order/${id}/cancel`, {
      cancel_reason: reason,
    })

    await showSuccessAlert({
      title: 'Berhasil',
      text: 'PO berhasil dicancel',
    })

    await getData()
  } catch (err) {
    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal cancel PO'),
    })
  } finally {
    closeAlert()
  }
}

const onClose = async (id: number) => {
  const { value: formValues } = await Swal.fire({
    title: 'Close PO',
    html: `
      <div style="text-align:left">
        <label>Tanggal Close</label>
        <input id="swal-tanggal" type="date" class="swal2-input">

        <label>Volume Close</label>
        <input id="swal-volume" type="number" class="swal2-input" placeholder="Volume Close">
      </div>
    `,
    focusConfirm: false,
    showCancelButton: true,
    confirmButtonText: 'Simpan',
    cancelButtonText: 'Batal',

    preConfirm: () => {
      const tanggal = (
        document.getElementById('swal-tanggal') as HTMLInputElement
      )?.value

      const volume = (
        document.getElementById('swal-volume') as HTMLInputElement
      )?.value

      if (!tanggal) {
        Swal.showValidationMessage('Tanggal Close wajib diisi')
        return false
      }

      if (!volume || Number(volume) <= 0) {
        Swal.showValidationMessage('Volume Close wajib diisi')
        return false
      }

      return {
        tanggal_close: tanggal,
        volume_close: Number(volume),
      }
    },
  })

  if (!formValues) return

  try {
    showLoadingAlert('Menyimpan...', 'Mohon menunggu')

    await axios.post(
      `/inventory/purchase-order/${id}/close`,
      formValues,
    )

    await showSuccessAlert({
      title: 'Berhasil',
      text: 'PO berhasil di-close',
    })

    await getData()
  } catch (err) {
    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal close PO'),
    })
  } finally {
    closeAlert()
  }
}
const loadingExport = ref(false)

const exportExcel = async () => {
  try {
    loadingExport.value = true

    showLoadingAlert('Export Excel', 'Mohon menunggu...')

    const response = await axios.get('/inventory/purchase-order/export', {
      params: {
        keyword: searchQuery.value,
        status: selectedStatus.value,
        search: search.value.search,
        terminal: search.value.terminal,
        vendor: search.value.vendor,
        tanggal_awal: search.value.tanggal_awal,
        tanggal_akhir: search.value.tanggal_akhir,
      },
      responseType: 'blob',
    })

    const url = window.URL.createObjectURL(new Blob([response.data]))

    const link = document.createElement('a')
    link.href = url
    link.setAttribute(
      'download',
      `Rekap-PO-${new Date().toISOString().slice(0, 10)}.xlsx`,
    )

    document.body.appendChild(link)
    link.click()
    link.remove()

    URL.revokeObjectURL(url)
  } catch (err) {
    console.error(err)
    showErrorAlert({
      title: 'Error',
      text: 'Gagal export Excel',
    })
  } finally {
    loadingExport.value = false
    closeAlert()
  }
}
// AUTO FETCH
onMounted(() => {
  getData()
  getVendor()
  getTerminal()
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
// watch(rowPerPage, () => {
//   currentPage.value = 1
//   getData()
// })

const getStatusLabel = (val: unknown) => {
  const map: Record<number, string> = {
    1: 'Menunggu CFO',
    2: 'Menunggu CEO',
    3: 'Ditolak CFO',
    4: 'Terverifikasi',
    5: 'Ditolak CEO',
  }

  return map[Number(val)] ?? '-'
}

const refreshTable = async () => {
  loading.value = true
  try {
    await getData()
  } finally {
    loading.value = false
  }
}
const resetFilters = async (): Promise<void> => {
  searchQuery.value = ''
  selectedStatus.value =''
  search.value.search =''
  search.value.terminal  =''
  search.value.vendor =''
  search.value.tanggal_awal =''
  search.value.tanggal_akhir =''

  await getData()
}
const chipColor: Record<number, string> = {
  1: 'info',
  2: 'info',
  3: 'error',
  4: 'success',
  5: 'error',
}


</script>
<template>
  <section>
    <!-- Filters -->
    

    <VCard class="mb-4 pa-4">
      <h3 class="mb-3">PENCARIAN</h3>

      <VRow>
        <VCol cols="12" md="6">
          <VTextField
            v-model="search.search"
            label="Kata Kunci"
            density="comfortable"
            clearable
          />
        </VCol>
      
        <VCol cols="12" md="6">
          <VSelect
            v-model="search.terminal"
            label="Terminal/Depot"
            :items="terminalList"
            item-title="nama_terminal"
            item-value="id"
            density="comfortable"
            clearable
          />
        </VCol>

        <VCol cols="12" md="6">
         <VAutocomplete
           v-model="search.vendor"
          label="Vendor *"
          :items="vendorList"
          item-title="nama_vendor"
          item-value="id"
          clearable
          density="comfortable"
          :menu-props="{ maxHeight: 300 }"
        ></VAutocomplete>
        </VCol>

        <VCol cols="12" md="3">
          <VTextField
            v-model="search.tanggal_awal"
            label="Tanggal Awal"
            type="date"
            density="comfortable"
            clearable
          />
        </VCol>

        <VCol cols="12" md="3">
          <VTextField
            v-model="search.tanggal_akhir"
            label="Tanggal Akhir"
            type="date"
            density="comfortable"
            clearable
          />
        </VCol>
      </VRow>

      <div class="d-flex gap-2 mt-4">
        <VBtn
          color="success"
          prepend-icon="mdi-file-excel"
          :loading="loadingExport"
          @click="exportExcel"
        >
          Export Excel
        </VBtn>
        <VBtn
          variant="outlined"
          color="secondary"
          @click="resetFilters"
        >
          Reset Filter
        </VBtn>
      </div>

    </VCard>
    <!-- Table -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
       <VBtn
        to="po-supplier/add"
        >
        <VIcon start icon="ri-add-circle-line"/> Tambah Data
        </VBtn>
      <VTooltip location="bottom">
        <template #activator="{ props }">
          <VBtn
            v-bind="props"
            icon="mdi-refresh"
            variant="tonal"
            size="small"
            :loading="loading"
            @click="refreshTable"
          />
        </template>

        Refresh Table
      </VTooltip>
        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">
          Loading...
        </VChip>
      </VCardText>

      <VDivider />

      <VTable class="custom-table">
        <thead>
         <tr>
           <th>No </th>
           <th>Nomor PO</th>
           <th>Tanggal</th>
           <th>Vendor / Terminal</th>
           <th>Produk</th>
           <th>Volume</th>
           <th>Harga Tebus</th>
           <th>Disposisi</th>
           <th>Aksi</th>
         </tr>
       </thead>
        <tbody>
          <tr v-for="(v, index) in rows" :key="v.id_master">
            <td>
              {{ (currentPage - 1) * rowPerPage + index + 1 }}
            </td>

            <td class="text-no-wrap">
              {{ v.nomor_po || '-' }} 
              <br>
                <VChip v-if="v.is_resubmission == 1"
                  size="small"
                  color="warning"
                  class="mb-0"
                >
                   Pengajuan ulang ke - {{ v.resubmission_count }}
                </VChip>
            </td>

            <td>{{ formatDate(v.tanggal_inven) }}</td>

            <td> 
              <div><strong>{{ v.vendor?.nama_vendor || '-' }}</strong></div>
              <div class="text-caption text-grey">
                {{ v.terminal?.nama_terminal+' - '+ v.terminal?.lokasi_terminal|| '-' }}
              </div>
            </td>

            <td class="text-caption text-no-wrap">{{ v.produk?.jenis_produk +' - '+v.produk?.merk_dagang  || '-' }}</td>

            <td class="text-end text-no-wrap">
              PO : {{ formatNumber(v.volume_po ?? 0) }}
              <br>
              BL : {{ formatNumber(v.total_bl ?? 0) }}
              <br>
              RI : {{ formatNumber(v.total_ri ?? 0) }}
            </td>

            <td class="text-right text-no-wrap">PO: {{ formatNumber(v.harga_po) }}
              <br> RI: {{ formatNumber(v.harga_tebus) }}
            </td>

            <td>
               <VChip
                  size="small"
                  :color="chipColor[v.disposisi_po  ?? 0]"
                  class="mb-0"
                >
                  {{ getStatusLabel(v.disposisi_po) }}
                </VChip>

             <span class="text-caption">
                {{ v.ceo_tanggal }}
              </span>
            </td>

              <td class="text-center" style="width: 7rem;">
                <VBtn size="x-small" color="success" variant="text" v-if="v.disposisi_po==4" icon>
                  <VIcon icon="ri-printer-fill" />

                  <VMenu activator="parent">
                    <VList>
                      <VListItem @click="printPO(v.id_master)">
                        <template #prepend>
                          <VIcon icon="ri-file-2-line" size="x-small" class="me-3" />
                        </template>
                        <VListItemTitle class="text-sm">Tanpa Gain/Loss</VListItemTitle>
                      </VListItem>

                      <VListItem @click="printPOGain(v.id_master)">
                        <template #prepend>
                          <VIcon icon="ri-file-list-2-line" size="x-small" class="me-3" />
                        </template>
                        <VListItemTitle class="text-sm">Dengan Gain/Loss</VListItemTitle>
                      </VListItem>
                    </VList>
                  </VMenu>
                </VBtn>
                <VBtn
                  v-if="canShowShipping(v)"
                  size="x-small"
                  color="info"
                  variant="tonal"
                  icon
                  @click="onShip(v.id_master)"
                >
                  <VIcon icon="mdi-cargo-ship" />
                  <VTooltip activator="parent">Shipping Instruction</VTooltip>
                </VBtn>
                <VBtn
                  v-if="canShowGR(v)"
                  size="x-small"
                  color="primary"
                  variant="tonal"
                  icon
                  @click="onGR(v.id_master)"
                >
                  <VIcon icon="mdi-truck" />
                  <VTooltip activator="parent" location="bottom">Goods Receipt</VTooltip>
                </VBtn>
                <VBtn size="small" color="default" variant="plain" icon>
                  <VIcon icon="mdi-dots-vertical" />

                  <VMenu activator="parent">
                    <VList>
                      <!-- <VListItem href="javascript:void(0)" >
                        <template #prepend>
                          <VIcon icon="tabler-eye" :size="20" class="me-3" />
                        </template>
                        <VListItemTitle class="text-sm">Lihat Detail</VListItemTitle>
                      </VListItem> -->

                      <VListItem @click="onEdit(v.id_master)">
                        <template #prepend>
                          <VIcon icon="mdi-pencil-outline" :size="20" class="me-3" />
                        </template>
                        <VListItemTitle class="text-sm">Edit</VListItemTitle>
                      </VListItem>
                      <VListItem @click="onClose(v.id_master)"  v-if="v.disposisi_po==4">
                        <template #prepend>
                          <VIcon icon="mdi-window-close" :size="20" class="me-3" />
                        </template>
                        <VListItemTitle class="text-sm">Close</VListItemTitle>
                      </VListItem>
                      <VListItem @click="onCancel(v.id_master)">
                        <template #prepend>
                          <VIcon icon="mdi-cancel" :size="20" class="me-3" />
                        </template>
                        <VListItemTitle class="text-sm">Cancel</VListItemTitle>
                      </VListItem>
                      <!-- <VListItem @click="onShip(v.id_master)" v-if="canShowShipping(v)">
                        <template #prepend>
                          <VIcon icon="mdi-cargo-ship" :size="20" class="me-3" />
                        </template>
                        <VListItemTitle class="text-sm">Shipping Instruction</VListItemTitle>
                      </VListItem>
                      <VListItem @click="onGR(v.id_master)"  v-if="canShowGR(v)">
                        <template #prepend>
                          <VIcon icon="mdi-truck" :size="20" class="me-3" />
                        </template>
                        <VListItemTitle class="text-sm">Goods Receipt</VListItemTitle>
                      </VListItem> -->
                    </VList>
                  </VMenu>
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
  .custom-table th,
  .custom-table td {
    padding: 12px 16px;
  }
</style>