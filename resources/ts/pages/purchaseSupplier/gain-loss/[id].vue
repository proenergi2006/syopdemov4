<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from '@axios'
import { useRoute, useRouter } from 'vue-router'

import {
  showConfirmAlert,
  showErrorAlert,
  showLoadingAlert,
  showSuccessAlert,
  closeAlert,
} from '@/utils/alert'

import { getApiErrorMessage } from '@/utils/apiHelper'

const route = useRoute()
const router = useRouter()

const loading = ref(false)

const form = reactive({
  catatan: '',
  revert: 0,
})

const detail = ref<any>({})

const getDetail = async () => {
  try {
    loading.value = true

    const response = await axios.get(
      `/inventory/gain-loss/${route.params.id}`,
    )

    detail.value = response.data
  }
  catch (error) {
    getApiErrorMessage(error, 'Gagal mengambil data')
  }
  finally {
    loading.value = false
  }
}

const submitApproval = async () => {
  try {

    const confirm = await showConfirmAlert({
      title: form.revert  === 1
        ? 'Approve Gain & Loss?'
        : 'Reject Gain & Loss?',
    })

    if (!confirm.isConfirmed)
      return

    showLoadingAlert()

    await axios.post('inventory/gain-loss/approval', {
      id_master: route.params.id,
      revert :form.revert,
      catatan: form.catatan,
    })

    closeAlert()

      await showSuccessAlert({
        title: 'Berhasil',
        text: `Approve Gain Loss berhasil`,
        timer: 1800,
        })
        await getDetail()

  }
  catch (error) {
    closeAlert()
      getApiErrorMessage(error,'Gagal mengambil data')
  }
}

const formatMoney = (value: number | null): string => {
   if (value === null || value === undefined) return '0'
  return new Intl.NumberFormat('id-ID').format(value)
}

//file
const fileDialog = ref(false)
const selectedFile = ref<string | undefined>(undefined)
const openFile = (path: string) => {
  selectedFile.value = `/storage/${path}`
  fileDialog.value = true
}


onMounted(() => {
  getDetail()
})
</script>
<template>
  <VContainer fluid>

    <!-- Header -->
    <VCard class="mb-6">
      <VCardText>
        <div class="d-flex justify-space-between align-center">
          <div>
            <h2 class="text-h5 font-weight-bold">
              Verifikasi Gain & Loss
            </h2>

            <div class="text-medium-emphasis">
              PO {{detail.po?.nomor_po}}
            </div>
          </div>

          <VChip
            color="success"
            size="large"
            prepend-icon="tabler-trending-up"
          >
            Gain
          </VChip>
        </div>
      </VCardText>
    </VCard>

    <!-- Summary -->
    <VRow class="mb-6">
      <VCol cols="12" md="4">
        <VCard>
          <VCardText>
            <div class="text-caption">Volume PO</div>
            <div class="text-h5 font-weight-bold">
             {{ formatMoney(detail.volume_po) }}
            </div>
            <div>Liter</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard>
          <VCardText>
            <div class="text-caption">Volume Terima</div>
            <div class="text-h5 font-weight-bold">
               {{ formatMoney(detail.volume_terima) }}
            </div>
            <div>Liter</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard
          :color="detail.jenis == 1 ? 'success' : 'error'"
          variant="tonal"
        >
          <VCardText>
            <div class="text-caption">
                {{ detail.jenis ==1 ?'Gain' :'Loss' }}
            </div>

            <div class="text-h5 font-weight-bold">
                {{ formatMoney(detail.volume) }} Ltr
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>

      <!-- LEFT -->
      <VCol cols="12" md="8">

        <!-- PO Info -->
        <VCard class="mb-6">
          <VCardTitle>
            Informasi PO
          </VCardTitle>

          <VDivider />

          <VCardText>
            <VRow>

              <VCol cols="12" md="6">
                <div class="text-caption text-medium-emphasis">
                  Supplier
                </div>

                <div class="font-weight-medium">
                    {{ detail.po?.vendor?.nama_vendor }}
                </div>
              </VCol>

              <VCol cols="12" md="6">
                <div class="text-caption text-medium-emphasis">
                  Produk
                </div>

                <div class="font-weight-medium">
                   {{ detail.po?.produk?.merk_dagang }} - {{ detail.po?.produk?.jenis_produk }}
                </div>
              </VCol>

              <VCol cols="12" md="6">
                <div class="text-caption text-medium-emphasis">
                  Terminal
                </div>

                <div class="font-weight-medium">
                   {{ detail.po?.terminal?.nama_terminal }} 
                </div>
              </VCol>

              <VCol cols="12" md="6">
                <div class="text-caption text-medium-emphasis">
                  Tanggal PO
                </div>

                <div class="font-weight-medium">
                    {{ detail.po?.tanggal_inven }}
                </div>
              </VCol>

            </VRow>
          </VCardText>
        </VCard>

        <!-- Detail Gain Loss -->
        <VCard class="mb-6">

          <VCardTitle>
            Detail Gain & Loss
          </VCardTitle>

          <VDivider />

          <VCardText>

            <VRow>

              <VCol cols="12" md="3">
                <VCard variant="tonal">
                  <VCardText>
                    <div class="text-caption">
                      Volume PO
                    </div>

                    <div class="text-h6">
                       {{ formatMoney(detail.volume_po) }}
                    </div>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol cols="12" md="3">
                <VCard variant="tonal">
                  <VCardText>
                    <div class="text-caption">
                      Volume Terima
                    </div>

                    <div class="text-h6">
                      {{ formatMoney(detail.volume_terima) }}
                    </div>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol cols="12" md="3">
                <VCard variant="tonal">
                  <VCardText>
                    <div class="text-caption">
                      Jenis
                    </div>

                    <VChip
                    :color="detail.jenis == 1 ? 'success' : 'error'"
                      size="small"
                    >
                      {{ detail.jenis ==1 ?'Gain' :'Loss' }}
                    </VChip>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol cols="12" md="3">
                <VCard
                  color="success"
                  variant="tonal"
                >
                  <VCardText>
                    <div class="text-caption">
                      Selisih
                    </div>

                    <div class="text-h6">
                      {{ formatMoney(detail.volume) }}

                    </div>
                  </VCardText>
                </VCard>
              </VCol>

            </VRow>

            <!-- Attachment -->
            <div class="mt-6" v-if="detail.file_upload">

              <div class="text-subtitle-2 mb-2">
                Attachment
              </div>

              <VList
                border
                rounded
              >
                <VListItem
                    prepend-icon="tabler-paperclip"
                    :title="detail.nama_file"
                    subtitle="Dokumen Gain Loss"
                    style="cursor:pointer"
                    @click="openFile(detail.file_upload)"
                />
              </VList>

            </div>

            <!-- Keterangan -->
            <div class="mt-6">

              <div class="text-subtitle-2 mb-2">
                Keterangan
              </div>

              <VCard variant="tonal">
                <VCardText>
                   {{ detail.ket }}
                </VCardText>
              </VCard>

            </div>

          </VCardText>

        </VCard>

      </VCol>

      <!-- RIGHT -->
      <VCol cols="12" md="4">

        <div
          style="
            position: sticky;
            top: 100px;
          "
        >
          <VCard>

            <VCardTitle>
              Approval CEO
            </VCardTitle>

            <VDivider />

            <VCardText  v-if="detail.disposisi_gain_loss == 1">

              <VAlert
                type="warning"
                variant="tonal"
                class="mb-4"
              >
                Terdapat Gain sebesar
                <strong>{{ formatMoney(detail.volume)}} Liter</strong>
                pada transaksi ini.
              </VAlert>

              <VRadioGroup v-model="form.revert">
                <VRadio
                  label="Approve"
                  :value="1"
                  color="success"
                />

                <VRadio
                  label="Reject"
                  :value="2"
                  color="error"
                />
              </VRadioGroup>

              <VTextarea
                label="Catatan CEO"
                rows="4"
              />

              <VBtn
                block
                color="primary"
                class="mt-4"
                @click="submitApproval"
              >
                Submit Approval
              </VBtn>

            </VCardText>

            <!-- SUDAH APPROVE -->
            <VCardText v-else-if="detail.disposisi_gain_loss == 2">

                <VAlert
                type="success"
                variant="tonal"
                class="mb-4"
                >
                Gain & Loss telah disetujui CEO.
                </VAlert>

                <div class="mb-3">
                <div class="text-caption">
                    Approved By
                </div>

                <div class="font-weight-medium">
                    {{ detail.ceo?.nama }}
                </div>
                </div>

                <div class="mb-3">
                <div class="text-caption">
                    Tanggal Approval
                </div>

                <div>
                    {{ detail.ceo_tanggal }}
                </div>
                </div>

                <div>
                <div class="text-caption">
                    Catatan CEO
                </div>

                <div>
                    {{ detail.ceo_summary || '-' }}
                </div>
                </div>

            </VCardText>

          </VCard>

        </div>

      </VCol>

    </VRow>

    <!-- Footer -->
    <div class="d-flex justify-end mt-6">
      <VBtn
        color="secondary"
        variant="tonal"
      >
        Kembali
      </VBtn>
    </div>
        <VDialog v-model="fileDialog" max-width="900">
      <VCard>
    
        <VCardTitle class="d-flex justify-space-between">
          File Preview
    
          <VBtn icon="mdi-close" variant="text" @click="fileDialog = false" />
        </VCardTitle>
    
        <VDivider />
    
        <VCardText style="height: 80vh">
          
          <!-- PDF -->
          <iframe
            v-if="selectedFile?.endsWith('.pdf')"
            :src="selectedFile"
            width="100%"
            height="100%"
          ></iframe>
    
          <!-- IMAGE -->
          <img
            v-else
            :src="selectedFile"
            style="max-width: 100%; max-height: 100%; object-fit: contain"
          />
    
        </VCardText>
    
      </VCard>
      </VDialog>

  </VContainer>
</template>