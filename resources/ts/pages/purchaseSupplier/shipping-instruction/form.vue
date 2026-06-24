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

const form = reactive({
  nomor_req: '',

  // document
  flag: '',

  // cargo
  cargo_name: '',
  country_origin: '',
  shipper: '',
  consignee: '',

  // commercial
  volume_po: 0,
  freight: 0,
  lead_time: 0,
  demurrage: 0,

  loss_type: '',
  loss: 0,
  satuan: '',

  // voyage
  transportir: null,
  tipe_kapal: null,
  id_vessel_tb: null,
  id_vessel: null,

  // port
  loading_port: null,
  discharging_port: null,

  eta_loading: '',
  eta_arrival: '',
  
  //approve
  created_at: '',
  created_by: null,
  status: 0,
  is_cancel:0,
  ket_cancel:'',

  // additional
  bl_ship: '',
  ket_ship: '',
  
  ket_log: '',
  log_pic: '',
  log_tanggal: '',

  cfo_result: 0,
  cfo_summary: '',
  cfo_pic: '',
  cfo_tanggal: '',

  ceo_result: 0,
  ceo_summary: '',
  ceo_pic: '',
  ceo_tanggal: '',
  
})


const po = reactive<any>({
  nomor_po: '',
  volume_po: 0,
 
})

const route = useRoute()
const router = useRouter()
const terminalList = ref<any[]>([])
const transportirList = ref<any[]>([])
const vesselList = ref<any[]>([])
const showTB = ref(false)

const payload = computed(() => ({
  id_vendor_po: id,

  nomor_req: form.nomor_req,
  volume_po: form.volume_po,

  flag: form.flag,

  cargo_name: form.cargo_name,
  country_origin: form.country_origin,

  shipper: form.shipper,
  consignee: form.consignee,

  freight: form.freight,
  lead_time: form.lead_time,
  demurrage: form.demurrage,

  loss_type: form.loss_type,
  loss: form.loss,
  satuan: form.satuan,

  transportir_id: form.transportir,

  tipe_kapal: form.tipe_kapal,

  vessel_tb_id: form.id_vessel_tb,
  vessel_id: form.id_vessel,

  loading_port_id: form.loading_port,
  discharging_port_id: form.discharging_port,

  eta_loading: form.eta_loading,
  eta_arrival: form.eta_arrival,

  bl_ship: form.bl_ship,
  ket_ship: form.ket_ship,
}))

const id = route.query.id


const fetchPO = async (id: any) => {

  try {
    const res = await axios.get(`/inventory/purchase-order/${id}`)

    const data = res.data
    Object.assign(po, {
        nomor_po: data.nomor_po,
        volume_po: data.volume_po,

    })
     payload.value.volume_po =data.volume_po

  } catch (err) {
    console.error(err)
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
const getTransportir = async () => {
  const res = await axios.get('/transportir')
  transportirList.value = res.data.map((p: any) => ({
    id: p.id,
    nama_transportir: p.nama_transportir,
    nama_suplier: p.nama_suplier,
  }))
}
const getVessel = async (transportirId?: number) => {
  const res = await axios.get('/oa-kapal', {
    params: {
      transportir_id: transportirId,
    },
  })

  vesselList.value = res.data.map((p: any) => ({
    id: p.id,
    nama_kapal: p.nama_kapal,
    nama: p.nama_kapal + ' - ' + p.tipe_kapal,
    tipe_kapal: p.tipe_kapal,
    asal_angkut: p.asal_angkut,
    tujuan_angkut: p.tujuan_angkut,
  }))
}

const saveDraft = async () => {
  try {

    showLoadingAlert()

    const res = await axios.post(
      '/shipping-instruction',
      {
        ...payload.value,
        status: 'draft',
      },
    )

    closeAlert()

    await showSuccessAlert({
      title: 'Berhasil',
      text: res.data?.message || 'Shipping Instruction disimpan sebagai Draft',
    })

  } catch (err : any) {

    closeAlert()

    await showErrorAlert({
      title: 'Error',
      text: err || 'Shipping Instruction gagal disimpan sebagai draft',
    })
  }
}
const cancelData = async (id: number) => {
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

    await axios.post(`/inventory/shipping-instruction/${siId.value}/cancel`, {
      cancel_reason: reason,
      id: siId.value

    })

    await showSuccessAlert({
      title: 'Berhasil',
      text: 'Shipping Instruction berhasil dicancel',
    })

    await checkSI(siId.value)
  } catch (err) {
    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(err, 'Gagal cancel SI'),
    })
  } finally {
    closeAlert()
  }
}

const submitData = async () => {
  const confirm = await showConfirmAlert({
   title: '<h5>Apakah yakin ingin menyimpan data?</h5>',
   confirmButtonText: 'Ya, simpan',
   cancelButtonText: 'Batal',
 })
  if (!confirm.isConfirmed) return
  try {

    showLoadingAlert()
    if (siId.value) {
      await axios.put(
        `/inventory/shipping-instruction/${siId.value}`,
         payload.value
      )

      await showSuccessAlert({
       title: 'Berhasil',
       text:  'Update Shipping Instruction berhasil',
      })
    } else {
      await axios.post(
        '/inventory/shipping-instruction',
         payload.value
      )
      await showSuccessAlert({
        title: 'Berhasil',
        text:  'Shipping Instruction berhasil disimpan',
      })
    }
    closeAlert()

  await checkSI(id)
  }
  catch (err) {
    closeAlert()

    await showErrorAlert({
      title: 'Error',
      text: getApiErrorMessage(err),
    })
  }
}
watch(
  () => form.tipe_kapal,
  value => {
    showTB.value = value === 'TB OB'
  },
  { immediate: true },
)

const isInit = ref(true)
watch(
  () => form.transportir,
  async value => {
    if (isInit.value) return

    form.id_vessel = null
    form.id_vessel_tb = null

    if (!value) {
      vesselList.value = []
      return
    }

    await getVessel(value)
  }
)

const siId = ref(null)

const checkSI = async (id: any) => {
  const res = await axios.get(`/inventory/shipping-instruction/by-po/${id}`)

  const data = res.data

  if (!data?.id_master) {
    
    return
  }

  siId.value = data.id_master

  if (data.is_cancel) {
    Object.assign(form, {
      nomor_req: data.nomor_req,
      is_cancel: data.is_cancel,
      ket_cancel: data.ket_cancel,
      created_at: data.created_at,
      created_by: data.created_by,
      status: data.status,
    })

    await getVessel(data.id_transportir)
    await fetchPO(id)
    return // ❗ STOP di sini
  }

  // ❗ NORMAL FLOW (non cancel)
  await getVessel(data.id_transportir)

  Object.assign(form, {
    nomor_req: data.nomor_req,
    volume_po: data.quantity,
    flag: data.flag,
    cargo_name: data.cargo_name,
    country_origin: data.country_origin,
    shipper: data.shipper,
    consignee: data.consignee,
    freight: data.freight,
    lead_time: data.leadtime,
    demurrage: data.demurrage,
    loss_type: data.losstype,
    loss: data.loss_tolerance,
    satuan: data.satuan,
    transportir: data.id_transportir,
    tipe_kapal: data.tipe_kapal == 2 ? 'Other' : 'TB OB',
    id_vessel: data.id_vessel,
    id_vessel_tb: data.id_vessel_tb,
    loading_port: data.loading_port,
    discharging_port: data.id_terminal_discharging,
    eta_loading: data.etl_date_first,
    eta_arrival: data.etl_date_last,
    bl_ship: data.bl_ship,
    ket_ship: data.ket_ship,
    created_at: data.created_at,
    created_by: data.created_by,
    status: data.status,
    ket_log: data.ket_log,
    log_pic: data.log_pic,
    log_tanggal: data.log_tanggal,

    cfo_result: data.cfo_result,
    cfo_summary: data.cfo_summary,
    cfo_pic: data.cfo_pic,
    cfo_tanggal: data.cfo_tanggal,

    ceo_result: data.ceo_result,
    ceo_summary: data.ceo_summary,
    ceo_pic: data.ceo_pic,
    ceo_tanggal: data.ceo_tanggal,
  })

  po.nomor_po = data.po_supplier.nomor_po
  po.volume_po = data.po_supplier.volume_po
  
}

const getTimelineColor = (status: any) => {
  if (status === 1) return 'success'
  if (status === 0) return 'warning'

  return 'grey'
}

const approvalTimeline = computed(() => [
  {
    title: 'Logistic Manager',
    note: form.ket_log,
    pic: form.log_pic,
    date: form.log_tanggal,
    status: form.status >= 1 ? 'APPROVED' : 'PENDING',
  },
  {
    title: 'CFO',
    note: form.cfo_summary,
    pic: form.cfo_pic,
    date: form.cfo_tanggal,
    status: form.cfo_tanggal
      ? (form.cfo_result == 1 ? 'APPROVED' : 'REJECTED')
      : 'PENDING',
  },
  {
    title: 'CEO',
    note: form.ceo_summary,
    pic: form.ceo_pic,
    date: form.ceo_tanggal,
    status: form.ceo_tanggal
      ? (form.ceo_result == 1 ? 'APPROVED' : 'REJECTED')
      : 'PENDING',
  },
])

const activities = computed(() => {
  const logs = []

  if (form.is_cancel) {
    logs.push({
      title: `Cancel SI ${form.nomor_req}`,
      subtitle: `${form.ket_cancel ?? '-'}`,
      color: 'error',
    })
  }

  return logs
})

 onMounted(async () =>  {
     await getTerminal()
  await getTransportir()

  await checkSI(id)
})

</script>
<template>
  <VRow>
    <!-- MAIN CONTENT -->
    <VCol cols="12" md="8">
      <VCard>
        <VCardText>

          <!-- HEADER -->
          <div class="d-flex justify-space-between align-center mb-6">
            <div class="d-flex align-center">

              
              <VAvatar
                color="info"
                rounded
                class="me-3"
                variant="tonal"
              >
                <VIcon icon="tabler-ship" />
              </VAvatar>
              
                  <div>
                    <h5 class="text-h5 font-weight-bold">
                      Shipping Instruction Request
                    </h5>

                    <div class="text-medium-emphasis">
                      Silakan isi form di bawah ini
                    </div>
                  </div>
            
            </div>

            <VChip
              color="warning"
              variant="tonal"
            >
              Draft
            </VChip>
          </div>

          <!-- CARGO -->
          <div class="section-title">
            Cargo Information
          </div>

          <VRow>
            <VCol cols="12" md="6">
               <VTextField
                label="Nomor PO"
                v-model="po.nomor_po"
                readonly
                variant="outlined"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField label="Nomor Shipping"
               v-model="form.nomor_req"
              />
            </VCol>

            <VCol cols="12" md="6">
               <VTextField
                  v-model="form.volume_po"
                  label="Volume PO"
                  suffix="Liter"
                />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField 
               v-model="form.flag"
               label="Flag" />
            </VCol>

            <VCol cols="12" md="6">
              
              <VTextField 
               v-model="form.freight"
               label="Freight"
                suffix="/L" />
            </VCol>
          <VCol cols="12" md="6">
            <label class="text-caption">Loss Tolerance *</label>
            <div class="d-flex align-center ga-2 pa-2 border rounded">

              <VSelect
                v-model="form.loss_type"
                :items="['R1','R2','R3','R4']"
                density="compact"
                variant="outlined"
                style="max-width: 110px"
                hide-details
              />

              <VTextField
                v-model="form.loss"
                type="number"
                density="compact"
                variant="outlined"
                hide-details
                style="max-width: 120px; text-align:right"
              />

              <span>%</span>

              <VSelect
                v-model="form.satuan"
                :items="['GOV','GSV']"
                density="compact"
                variant="outlined"
                style="max-width: 110px"
                hide-details
              />

            </div>
          </VCol>

            <VCol cols="12" md="6">
              <VTextField   
              v-model="form.country_origin"
               label="Country Origin"
               />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField 
               v-model="form.cargo_name"
              label="Cargo Name" />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField 
               v-model="form.lead_time"
                label="Lead Time" 
               suffix="Hari"
               />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField 
               v-model="form.demurrage"
                label="Biaya Demurage" 
               suffix="/24 Jam"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField 
               v-model="form.shipper"
              label="Shipper" 
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField 
              v-model="form.consignee"
              label="Cosignee"
               />
            </VCol>
          </VRow>

          <VDivider class="my-4" />

          <!-- VESSEL -->
          <div class="section-title">
            Vessel Information
          </div>

          <VRow>
            <VCol cols="12" md="6">
                <VAutocomplete
                  v-model="form.transportir"
                  label="Transportir *"
                  :items="transportirList"
                  item-title="nama_transportir"
                  item-value="id"
                  clearable
                  no-filter
                  density="comfortable"
                  :menu-props="{  maxHeight: 300,
                  attach: 'body' }"
                  action-refresh
                  @refresh="getTerminal"
                  placeholder="Pilih Transportir"
                >
                
                  <template #item="{ props, item }">
                    <VListItem
                      v-bind="props"
                    >
                      <div class="text-caption text-medium-emphasis">
                        {{ item.raw?.nama_suplier || '-' }}
                      </div>
                    </VListItem>
                  </template>
                </VAutocomplete>
            </VCol>

            <VCol cols="12" md="6">
              <VSelect
                  label="Tipe Kapal *"
                  v-model="form.tipe_kapal"
                  :items="['TB OB', 'Other']"
                  variant="outlined"
                />
            </VCol>

              <VCol cols="12" md="12" v-if="form.tipe_kapal">

                <VSheet class="mt-2 pa-4 mb-4"
                border rounded="lg">
    
                  <div class="text-subtitle-2 font-weight-bold mb-3">
                    Vessel *
                  </div>
                  <VRow>
                    <VCol cols="12" md="6" v-if="showTB">
                      <VAutocomplete
                        v-model="form.id_vessel_tb"
                        label="Vessel (TB) *"
                        :items="vesselList"
                        item-title="nama"
                        item-value="id"
                        clearable
                        no-filter
                        density="comfortable"
                        :menu-props="{  maxHeight: 300,
                        attach: 'body' }"
                        action-refresh
                        @refresh="getVessel"
                        placeholder="Pilih Vessel (TB)"
                      >
                      
                        <template #item="{ props, item }">
                          <VListItem
                            v-bind="props"
                          >
                            <div class="text-caption text-medium-emphasis">
                              {{ item.raw?.asal_angkut + ' - ' + item.raw?.tujuan_angkut }}
                            </div>
                          </VListItem>
                        </template>
                      </VAutocomplete>
                    </VCol>
        
                    <VCol cols="12" md="6">
                        <VAutocomplete
                          v-model="form.id_vessel"
                          label="Vessel *"
                          :items="vesselList"
                          item-title="nama"
                          item-value="id"
                          clearable
                          no-filter
                          density="comfortable"
                          :menu-props="{  maxHeight: 300,
                          attach: 'body' }"
                          action-refresh
                          @refresh="getVessel"
                          placeholder="Pilih Vessel"
                        >
                        
                          <template #item="{ props, item }">
                            <VListItem
                              v-bind="props"
                            >
                              <div class="text-caption text-medium-emphasis">
                                {{ item.raw?.asal_angkut + ' - ' + item.raw?.tujuan_angkut }}
                              </div>
                            </VListItem>
                          </template>
                        </VAutocomplete>
                    </VCol>
        
                  </VRow>
                </VSheet>
              </VCol>
          </VRow>

          <VDivider class="my-6" />
        
          <VRow>
            <VCol cols="12" md="6">
                <VAutocomplete
                  v-model="form.loading_port"
                  label="Loading Port *"
                  :items="terminalList"
                  item-title="nama_terminal"
                  item-value="id"
                  clearable
                  no-filter
                  density="comfortable"
                  :menu-props="{  maxHeight: 300,
                  attach: 'body' }"
                  action-refresh
                  @refresh="getTerminal"
                  placeholder="Pilih Loading Port"
                >
                
                  <template #item="{ props, item }">
                    <VListItem
                      v-bind="props"
                    >
                      <div class="text-caption text-medium-emphasis">
                        {{ item.raw?.lokasi_terminal || '-' }}
                      </div>
                    </VListItem>
                  </template>
                </VAutocomplete>
            </VCol>

            <VCol cols="12" md="6">
                <VAutocomplete
                  v-model="form.discharging_port"
                  label="Discharging Port *"
                  :items="terminalList"
                  item-title="nama_terminal"
                  item-value="id"
                  clearable
                  no-filter
                  density="comfortable"
                  :menu-props="{  maxHeight: 300,
                  attach: 'body' }"
                  action-refresh
                  @refresh="getTerminal"
                  placeholder="Pilih Discharge Port"
                >
                
                  <template #item="{ props, item }">
                    <VListItem
                      v-bind="props"
                    >
                      <div class="text-caption text-medium-emphasis">
                        {{ item.raw?.lokasi_terminal || '-' }}
                      </div>
                    </VListItem>
                  </template>
                </VAutocomplete>
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
               v-model="form.eta_loading"
                label="ETA Loading"
                type="date"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.eta_arrival"
                label="ETA Arrival"
                type="date"
              />
            </VCol>
          </VRow>

          <VDivider class="my-6" />

          <!-- REMARK -->
          <div class="section-title">
            Additional Information
          </div>

          <VTextarea
            v-model="form.bl_ship"
            rows="3"
            label="BL Ship on Board"
          />
          <VTextarea
            v-model="form.ket_ship"
            class="mt-3"
            rows="3"
            label="Catatan Purchasing"
          />

        </VCardText>
           <VCardText class="d-flex justify-end gap-3">
          <VBtn
            variant="outlined"
            color="secondary"
          >
            Back
          </VBtn>

          <VBtn
            color="warning"
            variant="tonal"
          >
            Save Draft
          </VBtn>

          <VBtn
            color="error"
            variant="tonal"
            @click="cancelData"
          >
           Cancel
          </VBtn>

          <VBtn color="primary"  @click="submitData">
            Submit
          </VBtn>
        </VCardText>
      </VCard>

    </VCol>

  <!-- SIDEBAR -->
  <VCol
    cols="12"
    lg="4"
  >

    <VCard class="sticky-sidebar">

      <VCardText>

        <!-- SUMMARY -->
        <div class="sidebar-title">
          Document Summary
        </div>

        <VList density="compact">

          <VListItem>
            <template #prepend>
              <VIcon icon="tabler-file-text" />
            </template>

            {{ po.nomor_po }}
          </VListItem>

          <VListItem>
            <template #prepend>
              <VIcon icon="tabler-droplet" />
            </template>

            {{ po.volume_po }} Liter
          </VListItem>

        </VList>

        <VDivider class="my-4" />

      <VTimeline
      density="compact"
      side="end"
    >
      <VTimelineItem
        v-for="(item, i) in approvalTimeline"
        :key="i"
        :dot-color="
          item.status === 'APPROVED'
            ? 'success'
            : item.status === 'REJECTED'
              ? 'error'
              : 'warning'
        "
      >
        <div class="d-flex justify-space-between align-center mb-1">
          <span class="font-weight-bold">
            {{ item.title }}
          </span>

          <VChip
            size="x-small"
            :color="
              item.status === 'APPROVED'
                ? 'success'
                : item.status === 'REJECTED'
                  ? 'error'
                  : 'warning'
            "
          >
            {{ item.status }}
          </VChip>
        </div>

        <div
          v-if="item.pic"
          class="text-caption"
        >
          {{ item.pic }}
        </div>

        <div
          v-if="item.date"
          class="text-caption text-medium-emphasis"
        >
          {{ item.date }}
        </div>

        <VAlert
          v-if="item.note"
          variant="tonal"
          density="compact"
          class="mt-2"
        >
          {{ item.note }}
        </VAlert>
      </VTimelineItem>
    </VTimeline>
        <VDivider class="my-4" />

       <div class="sidebar-title">
        Recent Activity
      </div>
      <VCard color="error" variant="tonal">
        <VCardText class="pa-1">

          <VList density="compact">
            <VListItem
              v-for="(item, index) in activities"
              :key="index"
            >
              <template #prepend>
                <VIcon icon="tabler-circle" :color="item.color" size="x-small"/>
              </template>

              <VListItemTitle class="text-caption">
                {{ item.title }}
              </VListItemTitle>

              <VListItemSubtitle>
                Ket : {{ item.subtitle }}
              </VListItemSubtitle>
            </VListItem>

            <VListItem v-if="activities.length === 0">
              <VListItemTitle>
                No recent activity
              </VListItemTitle>
            </VListItem>
          </VList>

        </VCardText>
      </VCard>
      </VCardText>

    </VCard>

  </VCol>

  </VRow>
</template>

<style scoped>
.section-title {
  font-size: 1rem;
  font-weight: 700;
  margin-bottom: 16px;
}
</style>