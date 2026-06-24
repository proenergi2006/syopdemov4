<script setup lang="ts">
import axios from '@axios'
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { tglIndo, formatRangeDate } from '@/utils/dateFormatter'
import {
  showConfirmAlert,
  showErrorAlert,
  showLoadingAlert,
  showSuccessAlert,
  showWarningAlert,
  closeAlert,
} from '@/utils/alert'
const route = useRoute()
const router = useRouter()

// ======================
// MAIN DATA
// ======================
const data = ref<any>({
  load_port: {},
  discharge_port: {},
  transportir: {},
  vessel: {},
  vessel_tb: {},
  items: [],
})

// ======================
// FETCH DETAIL
// ======================
const fetchDetail = async () => {
  try {

    const res = await axios.get(
      `/inventory/shipping-instruction/${route.params.id}`
    )

    data.value = res.data 
    data.value.items = res.data.po_supplier ?? []

  } catch (err) {
    console.error('Failed fetch detail:', err)
  }
}

const currentStatus = computed(() => {
  switch (data.value.status) {
    case 0:
      return {
        text: 'Waiting Logistics Approval',
        color: 'warning',
      }

    case 1:
      return {
        text: 'Waiting CFO Approval',
        color: 'warning',
      }

    case 2:
      return {
        text: 'Waiting CEO Approval',
        color: 'warning',
      }

    case 3:
      return {
        text: 'Approved',
        color: 'success',
      }

    case 9:
      return {
        text: 'Rejected',
        color: 'error',
      }

    default:
      return {
        text: 'Unknown Status',
        color: 'secondary',
      }
  }
})
const canPrint = computed(() => {
  return data.value.status === 3
})



// ======================
// FORMAT NUMBER
// ======================
const formatNumber = (val: number | string |any) => {
  return new Intl.NumberFormat('id-ID').format(val)
}
const cetak = async (tipe: string) => {
try {
    showLoadingAlert(`Memuat data cetak ${tipe}`, 'Mohon menunggu')
    const response = await axios.get(`/inventory/shipping-instruction/print/${route.params.id}?tipe=${tipe}`, { responseType: 'blob' });
    const fileURL = URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
    window.open(fileURL)
}catch (error) {
    console.error(error);
  }finally {
    closeAlert()
  }
}
const userRoles = ref<string[]>([])
const getProfile = async () => {
  try {
    const res = await axios.get('/auth/me')

    userRoles.value = res.data.role

    // console.log('ROLE:', res)
  } catch (err) {
    console.error(err)
  }
}
const canApprove = computed(() => {
  if (
    userRoles.value.includes('Logistic Manager')
    && data.value.status === 0
  ) return true

  if (
    userRoles.value.includes('CFO')
    && data.value.status === 1
  ) return true

  if (
    userRoles.value.includes('CEO')
    && data.value.status === 2
  ) return true

  return false
})
const loadingApprove = ref(false)
const approvalForm = ref({
  status: 'APPROVED',
  note: '',
})
const submitApproval = async () => {
  try {
    loadingApprove.value = true

    await axios.post(
      `/inventory/shipping-instruction/${route.params.id}/approve`,
      {
      status: approvalForm.value.status,
      note: approvalForm.value.note,
      role: userRoles.value
    }
    )

    await fetchDetail()
alert()
  } catch (err: any) {
alert('gagl')

  } finally {
    loadingApprove.value = false
  }
}
// ======================
// INIT
// ======================
onMounted(() => {
  fetchDetail()
  getProfile()
})
</script>
<template>
  <VContainer fluid>

    <!-- HEADER -->
    <VCard class="mb-4">
      <VCardText>

        <div class="d-flex justify-space-between align-center">

          <div>
            <div class="text-overline">
              SHIPPING INSTRUCTION
            </div>

            <div class="text-h6 font-weight-bold">
              {{ data.nomor_req }}
            </div>

            <div class="text-caption">
              Created by  {{ data.created_by }}({{ data.created_at }})
            </div>
          </div>

           <div class="d-flex align-center ga-2">

             <VBtn
                variant="tonal" color="secondary"  @click="router.back()"
                class="mr-2"
              >
                Kembali
            </VBtn>
             <VMenu v-if="canPrint">
              <template #activator="{ props }">
                  <VBtn
                  color="success"
                  prepend-icon="tabler-printer"
                  v-bind="props"
                  >
                  Cetak Dokumen
                  </VBtn>
              </template>
  
              <VList>
  
                  <VListItem @click="cetak('shipping_request')">
                  <VListItemTitle>
                      Shipping Request
                  </VListItemTitle>
                  </VListItem>
  
                  <VListItem @click="cetak('shipping_instruction')">
                  <VListItemTitle>
                      Shipping Instruction
                  </VListItemTitle>
                  </VListItem>
  
                  <VListItem @click="cetak('LO')">
                  <VListItemTitle>
                      LO
                  </VListItemTitle>
                  </VListItem>
  
                  <VListItem @click="cetak('spal')">
                  <VListItemTitle>
                      SPAL
                  </VListItemTitle>
                  </VListItem>
  
              </VList>
            </VMenu>
           </div>
        </div>

      </VCardText>
    </VCard>

    <!-- SUMMARY -->


    <!-- DETAIL -->
    <VRow>

      <VCol md="8">
     <!-- <VRow class="mb-4">

      <VCol md="4">
        <VCard>
          <VCardText>
            <div class="text-caption">Vessel</div>
            <div class="font-weight-bold">
              {{ data.vessel_name }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol md="4">
        <VCard>
          <VCardText>
            <div class="text-caption">Lead Time</div>
            <div class="font-weight-bold">
              {{ data.leadtime }} Hari
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol md="4">
        <VCard>
          <VCardText>
            <div class="text-caption">Biaya Demurrage</div>
            <div class="font-weight-bold">
              Rp {{ formatNumber(data.demurrage) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

    </VRow> -->

        <VCard>

          <VCardItem title="Detail Information">
            <template #prepend>
              <VIcon icon="tabler-ship" />
            </template>
          </VCardItem>

          <VDivider />

          <VCardText>

            <VRow>

              <VCol md="6">
                <div class="label">Request Date</div>
                <div class="value">{{ tglIndo(data.created_at) }}</div>
              </VCol>

              <VCol md="6">
                <div class="label">Shipping Line</div>
                <div class="value">{{ data.transportir.nama_suplier }}</div>
              </VCol>

              <VCol md="6">
                <div class="label">Loading Port</div>
                <div class="value">{{ data.load_port.nama_terminal }}</div>
              </VCol>

              <VCol md="6">
                <div class="label">Discharging Port</div>
                <div class="value">{{ data.discharge_port.nama_terminal }}</div>
              </VCol>

              <VCol md="6">
                <div class="label">Laycan</div>
                <div>
                {{ formatRangeDate(data.etl_date_first, data.etl_date_last) }}
                </div>
              </VCol>

              <VCol md="6">
                <div class="label">Loss Tolerance</div>
                <div class="value">
                  {{ data.losstype }}
                  {{ data.loss_tolerance }}%
                ({{ data.satuan }})
                </div>
              </VCol>

              <VCol md="6">
                <div class="label">Shipper</div>
                <div class="value">{{ data.shipper }}</div>
              </VCol>

              <VCol md="6">
                <div class="label">Consignee</div>
                <div class="value">{{ data.consignee }}</div>
              </VCol>
              <VCol md="6">
                <div class="label">Vessel</div>
                <div class="value">{{ data.vessel.tipe_kapal }} {{ data.vessel.nama_kapal }}</div>
              </VCol>
             

              <VCol md="6" v-if="data.id_vessel_tb">
                <div class="label">Vessel (TB)</div>
                <div class="value">{{ data.vessel_tb.tipe_kapal }} {{ data.vessel_tb.nama_kapal }}</div>
              </VCol>
               <VCol md="6">
                <div class="label">Lead Time</div>
                <div class="value">{{ data.leadtime }} Days</div>
              </VCol>

              <VCol md="6">
                <div class="label">Port of Loading</div>
                <div class="value">{{ data.vessel.asal_angkut }}</div>
              </VCol>
              <VCol md="6">
                <div class="label">Port of Discharging</div>
                <div class="value">{{ data.vessel.tujuan_angkut }}</div>
              </VCol>

              
              <VCol md="6">
                <div class="label">Biaya Demurrage</div>
                <div class="value">Rp {{ data.demurrage }}</div>
              </VCol>
              <VCol md="6">
                <div class="label"> BL Ship on Board :</div>
                <div class="value"> {{ data.bl_ship }}</div>
              </VCol>
        
            </VRow>
              <div class="mt-6">

              <div class="text-subtitle-2 mb-2">
                Catatan Shipping :
              </div>

              <VCard variant="tonal">
                <VCardText>
                   {{ data.ket_ship }}
                </VCardText>
              </VCard>

            </div>

          </VCardText>

        </VCard>

      </VCol>

      <!-- APPROVAL FLOW -->
      <VCol md="4">

    <VCard>
  <VCardItem title="Approval Flow">
    <template #prepend>
      <VIcon icon="tabler-checklist" />
    </template>
  </VCardItem>

  <VDivider />

  <VCardText>

  <VAlert
    :color="currentStatus.color"
    variant="tonal"
    class="mb-4"
  >
    {{ currentStatus.text }}
  </VAlert>
    <!-- Notes -->

    <div class="text-subtitle-2 mb-3">
      Approval Notes
    </div>

    <VList density="compact">

  <!-- LOGISTIK -->

  <VListItem>

    <template #prepend>
      <VIcon
        :color="data.status >= 1 ? 'success' : 'warning'"
        :icon="data.status >= 1 ? 'tabler-check' : 'tabler-clock'"
      />
    </template>

    <VListItemTitle>
      Logistics
    </VListItemTitle>

    <VListItemSubtitle>
      {{
        data.status >= 1
          ? data.note_logistik || 'Approved'
          : 'Waiting Approval'
      }}
    </VListItemSubtitle>

  </VListItem>

  <!-- CFO -->

  <VListItem>

    <template #prepend>
      <VIcon
        :color="data.cfo_result == 1 ? 'success' : 'secondary'"
        :icon="data.cfo_result == 1 ? 'tabler-check' : 'tabler-minus'"
      />
    </template>

    <VListItemTitle>
      CFO
    </VListItemTitle>

    <VListItemSubtitle>
      {{
        data.cfo_result == 1
          ? data.cfo_summary || 'Approved'
          : 'Pending'
      }}
    </VListItemSubtitle>

  </VListItem>

  <!-- CEO -->

  <VListItem>

    <template #prepend>
      <VIcon
        :color="data.ceo_result == 1 ? 'success' : 'secondary'"
        :icon="data.ceo_result == 1 ? 'tabler-check' : 'tabler-minus'"
      />
    </template>

    <VListItemTitle>
      CEO
    </VListItemTitle>

    <VListItemSubtitle>
      {{
        data.ceo_result == 1
          ? data.ceo_summary || 'Approved'
          : 'Pending'
      }}
    </VListItemSubtitle>

  </VListItem>

</VList>
    <VDivider class="my-4" />

  <!-- Form Approval -->
  <div v-if="canApprove">

    <div class="text-subtitle-2 mb-3">
      Approval Decision
    </div>

    <VRadioGroup
      inline
      v-model="approvalForm.status"
    >
      <VRadio
        label="Approve"
        :value=1
      />

      <VRadio
        label="Reject"
        :value=2
      />
    </VRadioGroup>

    <VTextarea
     v-model="approvalForm.note"
      label="Approval Notes"
      rows="3"
      class="mt-2"
    />

    <VBtn
    color="primary"
    block
    @click="submitApproval"
  >
    Submit Approval
  </VBtn>

  </div>

  </VCardText>
</VCard>
      </VCol>

    </VRow>

    <!-- CARGO -->
    <VCard class="mt-4">

      <VCardItem title="Other Information">

        <template #append>
          <!-- <VChip color="primary">
            {{ data.items?.length || 0 }} Cargo
          </VChip> -->
        </template>

      </VCardItem>

      <VDivider />

      <VTable>

        <thead>
          <tr>
            <th>Vendor</th>
            <th>PO</th>
            <th>Qty</th>
            <th>Freight</th>
            <th>Total</th>
            <th>Cargo</th>
          </tr>
        </thead>

        <tbody>

         <tr>
        <td>{{ data.po_supplier?.vendor?.nama_vendor ?? '-' }}</td>
        <td>{{ data.po_supplier?.nomor_po ?? '-' }}</td>
        <td>{{ formatNumber(data.quantity) }}</td>
        <td>Rp {{ formatNumber(data.freight) }}</td>
        <td>Rp {{ formatNumber(data.quantity*data.freight) }}</td>
        <td>{{ data.cargo_name }}</td>
        </tr>
        </tbody>

      </VTable>

    </VCard>

  </VContainer>
</template>