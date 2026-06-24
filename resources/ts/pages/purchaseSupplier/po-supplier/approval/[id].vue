<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from '@axios'
import { useRoute } from 'vue-router'
import {
  showConfirmAlert,
  showErrorAlert,
  showLoadingAlert,
  showSuccessAlert,
  showWarningAlert,
  closeAlert,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
const route = useRoute()
const router = useRouter()

const id = route.params.id


const po = reactive<any>({
  nomor_po: '',
  tanggal_inven: '',
  volume_po: null,
  volume_bol: null,

  vendor: '',
  produk: '',
  terminal: '',
  nilai_pbbkb: '',
  iuran_migas: false,
  kd_tax: '',
  ongkos_angkut: '',
  kategori_plat: '',
  terms: '',
  terms_day: '',
  catatan_po: '',
  internal_notes: '',
  disposisi_po: 0,
  cfo_result: 0,
  cfo_pic: '',
  cfo_tanggal: '',
  cfo_summary: '',
  revert_cfo: '',
  revert_cfo_summary: '',
  ceo_result: 0,
  ceo_pic: '',
  ceo_tanggal: '',
  ceo_summary: '',
  revert_ceo_summary: '',

  pbbkb_po: 0,
  harga_tebus: 0,
  subtotal: 0,
  ppn: 0,
  pph_22: 0,
  nominal_migas: 0,
  total: 0,
})

const showHistory = ref(false)
const latestHistory = ref<any>(null)
const histories = ref<any[]>([])

// ====== APPROVAL STATE ======
const approvalCEO = reactive({
  decision: '' as '' | 'approve' | 'reject',
  note: '',
})

const approvalCFO = reactive({
  decision: '',
  note: '',
})


const isWaitingCEO = computed(() => {
  return po.disposisi_po === 2
})

const isWaitingCFO = computed(() => po.disposisi_po === 1)

const isApprovedCFO = computed(() => po.cfo_result === 1)

const isRejectedCFO = computed(() => po.cfo_result === 2)
// ====== LOADING ======
const loading = ref(false)

// ====== FETCH PO ======
const fetchPO = async (id: any) => {
  loading.value = true

  try {
    const res = await axios.get(`/inventory/purchase-order/${id}`)

    const data = res.data
    Object.assign(po, {
        nomor_po: data.nomor_po,
        tanggal_inven: data.tanggal_inven,
        volume_po: data.volume_po,
        volume_bol: data.volume_bol,

        vendor: data.vendor.nama_vendor,
        produk: data.produk.merk_dagang,
        terminal: data.terminal.nama_terminal,

        kategori_plat: data.kategori_plat,
        kd_tax: data.kd_tax,
        ongkos_kirim: data.ongkos_kirim,
        terms: data.terms,
        terms_day: data.terms_day,
        nilai_pbbkb: data.nilai_pbbkb,
        iuran_migas: data.iuran_migas,

        catatan_po: data.keterangan,
        internal_notes: data.internal_notes,
        harga_tebus: data.harga_tebus,
        pbbkb_po: data.pbbkb_po,
        nominal_migas: data.nominal_migas,
        subtotal: data.subtotal,
        ppn: data.ppn_12,
        pph_22: data.pph_22,
        total: data.total_order,
        disposisi_po: data.disposisi_po,
        cfo_result: data.cfo_result,
        cfo_summary: data.cfo_summary,
        cfo_pic: data.cfo_pic,
        cfo_tanggal: data.cfo_tanggal,
        ceo_result: data.ceo_result,
        ceo_pic: data.ceo_pic,
        ceo_tanggal: data.ceo_tanggal,
        ceo_summary: data.ceo_summary,
        revert_ceo_summary: data.revert_ceo_summary,

    })
  } catch (err) {
    console.error(err)
  } finally {
    loading.value = false
  }
}

const fetchHistory = async () => {
  const res = await axios.get(`/inventory/purchase-order/${id}/history`)
  latestHistory.value = res.data.latest || null
  histories.value = res.data.history || []
  console.log(res)
}

const confirmDialog = ref(false)
const loadingSubmit = ref(false)

const submitApproval = () => {
  confirmDialog.value = true
}

const isSaving = ref(false)
const doSubmit = async () => {

//   loadingSubmit.value = true
  const confirm = await showConfirmAlert({
    title: 'Apakah yakin ingin melakukan approval PO?',
    confirmButtonText: 'Ya, simpan',
    cancelButtonText: 'Batal',
  })
    if (!confirm.isConfirmed) return

    isSaving.value = true

    try {
      showLoadingAlert('Menyimpan data...', 'Mohon menunggu')

    let url = ''

    // CFO
    if (po.disposisi_po === 1) {
      url = `/inventory/purchase-order/${id}/approve-cfo`
    }

    // CEO
    if (po.disposisi_po === 2) {
      url = `/inventory/purchase-order/${id}/approve-ceo`
    }

    await axios.post(url, {
      decision: po.result,
      note: po.note,
    })

    // confirmDialog.value = false
    await showSuccessAlert({
      title: 'Berhasil',
      text: `Approve PO berhasil`,
      timer: 1800,
    })


    await fetchPO(id)

  } catch (err) {
    closeAlert()
    console.error(err)

    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal menghapus vendor'),
    })
  } finally {
    closeAlert()
    isSaving.value = false

    // loadingSubmit.value = false
  }
}
const userRoles = ref<string[]>([])

const getProfile = async () => {
  try {
    const res = await axios.get('/auth/me')

    userRoles.value = res.data.role

    console.log('ROLE:', res)
  } catch (err) {
    console.error(err)
  }
}
const canApprove = (item: any) => {
  if (userRoles.value.includes('CFO') && item.disposisi_po === 1) return true
  if (userRoles.value.includes('CEO') && item.disposisi_po === 2) return true
  return false
}

const statusMap: Record<number, {
  text: string
  color: string
  icon: string
}> = {
  1: {
    text: 'Waiting CFO',
    color: 'warning',
    icon: 'tabler-clock',
  },

  2: {
    text: 'Waiting CEO',
    color: 'info',
    icon: 'tabler-user-check',
  },

  3: {
    text: 'Rejected CFO',
    color: 'error',
    icon: 'tabler-x',
  },

  4: {
    text: 'Approved',
    color: 'success',
    icon: 'tabler-check',
  },

  5: {
    text: 'Rejected CEO',
    color: 'error',
    icon: 'tabler-x',
  },
}

const noteLabel = computed(() => {

  if (po.result === '1') {
    return 'Catatan Approval'
  }

  if (po.result === '2') {
    return 'Catatan Reject'
  }

  return 'Catatan'
})
const formatMoney = (value: number | null): string => {
   if (value === null || value === undefined) return '0'
  return new Intl.NumberFormat('id-ID').format(value)
}



// ====== INIT ======
onMounted(() => {
  fetchPO(id)
  getProfile()
  fetchHistory()
})
</script>
<template>
    <section>
         <VCard class="mb-6" rounded="md">

            <VCardText>
                <div class="d-flex justify-space-between align-center flex-wrap ga-3">
                
                <div>
                    <h2 class="text-h5 font-weight-bold">
                    Verifikasi PO Supplier
                    </h2>
                </div>

                <VChip color="primary" variant="tonal">
                    Status: Review
                </VChip>

                </div>
            </VCardText>

            <VDivider />

            <VCardText>
                <div class="d-grid flex-column ga-5">

                    <div class="d-flex">
                        <strong style="width:140px">Nomor PO</strong>:  {{ po.nomor_po || '-' }}
                    </div>

                    <div class="d-flex">
                        <strong style="width:140px">Tanggal</strong>: {{ po.tanggal_inven || '-' }}
                    </div>

                    <div class="d-flex">
                        <strong style="width:140px">Volume PO</strong>: {{ formatMoney(po.volume_po) || '-' }} Liter
                    </div>

                    <div class="d-flex">
                        <strong style="width:140px">Volume BOL</strong>: {{ formatMoney(po.volume_bol) || '-' }} Liter
                    </div>

                </div>
                <VRow class="mt-2">

                    <!-- DATA SAAT INI -->
                    <VCol cols="12" md="6">
                    <VCard variant="tonal" color="primary" rounded="md">

                    <VCardTitle color="primary" class="font-weight-bold">
                        Data Saat Ini
                    </VCardTitle>

                    <VDivider />

                    <VCardText class="pt-4">

                        <div class="d-flex flex-column ga-3">

                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Vendor</span>
                            <strong> {{ po.vendor || '-' }}</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Produk</span>
                            <strong> {{ po.produk || '-' }}</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Terminal</span>
                            <strong> {{ po.terminal || '-' }}</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Harga Dasar</span>
                            <strong> {{ po.harga_tebus || '-' }}</strong>
                        </div>
                        <div v-if="po.ongkos_angkut" class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Ongkos Angkut</span>
                            <strong> {{ po.ongkos_angkut || '-' }}</strong>
                        </div>
                        <div v-if="po.ongkos_angkut" class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Kategori Plat</span>
                            <strong> {{ po.kategori_plat || '-' }}</strong>
                        </div>
                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Kode Tax</span>
                            <strong> {{ po.kd_tax || '-' }}</strong>
                        </div>
                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Terms</span>
                            <strong> {{ po.terms || '-' }}</strong>
                        </div>
                        <div v-if="po.terms == 'NET'" class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Terms Day</span>
                            <strong> {{ po.terms_day || '-' }}</strong>
                        </div>

                        <VDivider class="my-2" />

                        <div class="d-flex justify-space-between">
                            <span>Harga Dasar</span>
                            <strong>{{ formatMoney(po.harga_tebus) || '-' }}</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span>Subtotal</span>
                            <strong>{{ formatMoney(po.subtotal) || '-' }}</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span>PPN</span>
                            <strong>{{ formatMoney(po.ppn) || '-' }}</strong>
                        </div>
                        <div v-if="Number(po.pph_22) !== 0" class="d-flex justify-space-between">
                            <span>PBBKB</span>
                            <strong>{{ formatMoney(po.pbbkb_po) || '-' }}</strong>
                        </div>
                        <div  v-if="Number(po.pph_22) !== 0" class="d-flex justify-space-between">
                            <span>PPH 22</span>
                            <strong>{{ formatMoney(po.pph_22) || '-' }}</strong>
                        </div>
                        <div v-if="po.iuran_migas" class="d-flex justify-space-between">
                            <span>Iuran Migas</span>
                            <strong>{{ formatMoney(po.nominal_migas) || '-' }}</strong>
                        </div>

                        <div class="d-flex justify-space-between text-h6">
                            <span>Total</span>
                            <strong>{{ formatMoney(po.total) || '-' }}</strong>
                        </div>
                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Catatan</span>
                            <strong>{{ po.catatan_po || '-' }}</strong>
                        </div>
                        <div class="d-flex justify-space-between">
                            <span class="text-medium-emphasis">Internal Notes</span>
                            <strong>{{ po.internal_notes || '-' }}</strong>
                        </div>

                        </div>

                    </VCardText>

                    </VCard>
                </VCol>

                <!-- PERUBAHAN TERAKHIR -->
                <!-- <VCol cols="12" md="6">
                    <VCard variant="tonal" color="error" class="pa-4">
                        <div class="font-weight-bold mb-2">Sebelum</div>

                        <div class="d-flex justify-space-between">
                            <span>Volume</span>
                            <strong>{{ latestHistory.volume_po }}</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span>Harga</span>
                            <strong>{{ latestHistory.harga_tebus }}</strong>
                        </div>

                        <div class="d-flex justify-space-between">
                            <span>Total</span>
                            <strong>{{ latestHistory.total_order }}</strong>
                        </div>

                        </VCard>
                </VCol> -->

                </VRow>
            </VCardText>
        

        </VCard>
       <div class="mt-6" v-if="histories.length > 0">
        <VCard class="mb-4">
            <VCardTitle>Riwayat Pengajuan PO</VCardTitle>
            <VDivider/>
            <VCardText>
                
            <VExpansionPanels multiple>
                <VExpansionPanel
                v-for="(h, index) in histories"
                :key="index"
                >
                <VExpansionPanelTitle>
                    Riwayat Pengajuan ke - {{ index + 1 }}
                </VExpansionPanelTitle>
    
                <VExpansionPanelText>
                    <VCard variant="tonal" class="pa-4">
    
                    <div class="d-flex flex-column ga-2">
    
                        <div class="d-flex justify-space-between">
                        <strong>Nomor PO</strong>
                        <span>{{ h.nomor_po }}</span>
                        </div>
    
                        <div class="d-flex justify-space-between">
                        <strong>Volume</strong>
                        <span>{{ h.volume_po }} L</span>
                        </div>
    
                        <div class="d-flex justify-space-between">
                        <strong>Harga</strong>
                        <span>{{ h.harga_tebus }}</span>
                        </div>
    
                        <div class="d-flex justify-space-between">
                        <strong>Total</strong>
                        <span>{{ h.total_order }}</span>
                        </div>
    
                        <div class="d-flex justify-space-between">
                        <strong>Updated By</strong>
                        <span>{{ h.lastupdate_by }}</span>
                        </div>
    
                        <div class="d-flex justify-space-between">
                        <strong>Tanggal</strong>
                        <span>{{ h.lastupdate_time }}</span>
                        </div>
    
                    </div>
    
                    </VCard>
                </VExpansionPanelText>
                </VExpansionPanel>
            </VExpansionPanels>
            </VCardText>
        </VCard>

        </div>
        <VRow>
           <!-- TIMELINE -->
            <VCol cols="12" md="6">

                <VCard>

                    <VCardTitle>
                    Approval Timeline
                    </VCardTitle>

                    <VDivider />

                    <VCardText>

                    <VTimeline
                        side="end"
                        density="compact"
                    >

                        <!-- SUBMIT -->
                        <VTimelineItem
                        dot-color="primary"
                        icon="tabler-send"
                        >
                        <div class="font-weight-bold">
                            Pengajuan
                        </div>

                        <div class="text-caption">
                            User submit PO
                        </div>
                        </VTimelineItem>

                        <!-- CFO -->
                        <VTimelineItem
                        :dot-color="statusMap[po.disposisi_po]?.color || 'grey'"
                        :icon="statusMap[po.disposisi_po]?.icon"
                        >

                        <div class="d-flex justify-space-between align-center">

                            <div>

                            <div class="font-weight-bold">
                                CFO
                            </div>

                            <div class="text-caption text-medium-emphasis">

                                <template v-if="isWaitingCFO">
                                Waiting Approval
                                </template>

                                <template v-else-if="isApprovedCFO">
                                Approved by {{ po.cfo_pic }} - {{ po.cfo_tanggal }}
                                </template>

                                <template v-else-if="isRejectedCFO">
                                Rejected by {{ po.cfo_pic }} - {{ po.cfo_tanggal }}
                                </template>
                                <template v-else>
                                Pending
                                </template>

                            </div>

                            </div>

                          <VChip
                        :color="statusMap[po.disposisi_po]?.color || 'grey'"
                        variant="tonal"
                        size="small"
                        >
                      {{ isApprovedCFO === true ? 'Approved' : isRejectedCFO === true ? 'Rejected' : 'Pending' }}
                        </VChip>

                        </div>

                        <div
                            v-if="po.cfo_summary"
                        class="text-body-2 mt-2 border rounded pa-4 mb-2"
                        >
                        Catatan CFO : {{ po.cfo_summary }}
                        </div>

                        </VTimelineItem>

                        <!-- CEO -->
                        <VTimelineItem
                        :dot-color="
                            isWaitingCEO
                            ? 'warning'
                            : po.ceo_result === 1
                                ? 'success'
                                : po.ceo_result === 2
                                ? 'error'
                                : 'grey'
                        "
                        :icon="
                            isWaitingCEO
                            ? 'tabler-clock'
                            : po.ceo_result === 1
                                ? 'tabler-check'
                                : po.ceo_result === 2
                                ? 'tabler-x'
                                : 'tabler-minus'
                        "
                        >

                        <div class="d-flex justify-space-between align-center">

                            <div>

                            <div class="font-weight-bold">
                                CEO
                            </div>

                            <div class="text-caption text-medium-emphasis">

                                <template v-if="isWaitingCEO">
                                Waiting Approval
                                </template>

                                <template v-else-if="po.ceo_result === 1">
                                Approved  {{ po.ceo_pic }} - {{ po.ceo_tanggal }}
                                </template>

                                <template v-else-if="po.ceo_result === 2">
                                Rejected  {{ po.ceo_pic }} - {{ po.ceo_tanggal }}
                                </template>

                                <template v-else>
                                Menunggu CFO
                                </template>

                            </div>

                            </div>

                            <VChip
                            :color="
                                isWaitingCEO
                                ? 'warning'
                                : po.ceo_result === 1
                                    ? 'success'
                                    : po.ceo_result === 2
                                    ? 'error'
                                    : 'grey'
                            "
                            variant="tonal"
                            size="small"
                            >
                            {{
                                isWaitingCEO
                                ? 'Waiting'
                                : po.ceo_result === 1
                                    ? 'Approved'
                                    : po.ceo_result === 2
                                    ? 'Rejected'
                                    : 'Pending'
                            }}
                            </VChip>

                        </div>
                        <div
                            v-if="po.ceo_summary"
                            class="text-body-2 mt-2 border rounded pa-4 mb-2"
                            >
                            Catatan ceo : {{ po.ceo_summary }}
                        </div>
                        </VTimelineItem>

                    </VTimeline>

                    </VCardText>

                </VCard>

            </VCol>
         

            <!-- FORM APPROVAL -->
            <VCol cols="12" md="6">

            <VCard
                 v-if="
                    (userRoles.includes('CFO') && po.disposisi_po === 1) ||
                    (userRoles.includes('CEO') && po.disposisi_po === 2)
                "
            >

                <VCardTitle>
                {{
                    po.disposisi_po === 1
                    ? 'Approval CFO'
                    : 'Approval CEO'
                }}
                </VCardTitle>

                <VDivider />

                <VCardText>

                <VRadioGroup
                    v-model="po.result"
                    inline
                >
                    <VRadio
                    label="Approve"
                    value="1"
                    />

                    <VRadio
                    label="Reject"
                    value="2"
                    />
                </VRadioGroup>

                <VTextarea
                    v-model="po.note"
                    :label="noteLabel"
                    rows="3"
                    class="mt-3"
                />

                <VBtn
                    color="primary"
                    class="mt-4"
                    @click="doSubmit"
                >
                    Submit Approval
                </VBtn>

                </VCardText>

            </VCard>

            </VCol>
        </VRow>
 
    <VDialog v-model="confirmDialog" max-width="400">
        <VCard>
            <VCardTitle>
            Konfirmasi
            </VCardTitle>

            <VCardText>
            {{
                po.result === '1'
                ? 'apakah yakin ingin Approve PO ini?'
                : 'Apakah yakin ingin Reject PO ini?'
            }}
            </VCardText>

            <VCardActions>
            <VSpacer />

            <VBtn variant="text" @click="confirmDialog = false">
                Batal
            </VBtn>

            <VBtn color="primary" variant="flat" @click="doSubmit">
                Ya, Lanjut
            </VBtn>
            </VCardActions>
        </VCard>
    </VDialog>
    <VDialog
    v-model="loadingSubmit"
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
      <VCardActions class="pa-4 d-flex justify-end">

          <VBtn
              variant="tonal" color="secondary" size="large"  @click="router.back()"
              class="mt-2"
          >
              Kembali
          </VBtn>
      </VCardActions>
    </section>
</template>