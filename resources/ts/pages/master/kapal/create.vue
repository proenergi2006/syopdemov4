<script setup lang="ts">
import { reactive, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from '@axios'
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
const transportirs = ref([])

const form = reactive({
  items: [
    {
      id_transportir: null,
      nama_kapal: '',
      tipe_kapal: '',
      max_kapal: 0,
      asal_angkut: '',
      tujuan_angkut: '',
      volume_angkut: 0,
      harga_angkut: 0,
    },
  ],
})

const fetchTransportir = async () => {
  const res = await axios.get('/transportir')
  transportirs.value = res.data
}

const addRow = () => {
  form.items.push({
    id_transportir: null,
    nama_kapal: '',
    tipe_kapal: '',
    max_kapal: 0,
    asal_angkut: '',
    tujuan_angkut: '',
    volume_angkut: 0,
    harga_angkut: 0,
  })
}

const removeRow = (index: number) => {
  if (form.items.length > 1) {
    form.items.splice(index, 1)
  }
}

const parseNumber = (v: string) =>
  Number(v.replace(/[^\d]/g, ''))

const formatMoney = (v: number) =>
  new Intl.NumberFormat('id-ID').format(v)

const submit = async () => {
  try {
    const confirm = await showConfirmAlert({
      title : 'Apakah yakin ingin submit?'
    })

    if (!confirm.isConfirmed)
      return

    showLoadingAlert()
    
    loading.value = true

    await axios.post('/master/oa-kapal', {
      items: form.items,
    })

    
    closeAlert()

    await showSuccessAlert({
      title: 'Berhasil',
      text: `Simpan Data berhasil`,
      timer: 1800,
      })

    router.push('/master/kapal')
  } finally {
    loading.value = false
  }
}

onMounted(fetchTransportir)
</script>

<template>
  <VContainer>

    <VCard>

      <!-- HEADER -->
      <VCardTitle class="d-flex justify-space-between align-center">
        <span>Tambah OA Kapal</span>

        <VBtn
          size="small"
          variant="outlined"
          color="primary"
          prepend-icon="tabler-plus"
          @click="addRow"
        >
          Tambah
        </VBtn>
      </VCardTitle>

      <VDivider />

      <!-- BODY -->
      <VCardText>

        <div
          v-for="(item, index) in form.items"
          :key="index"
          class="border rounded pa-4 mb-4"
        >

          <div class="d-flex justify-space-between mb-3">
            <!-- <b>Row {{ index + 1 }}</b> -->

            <VBtn
              v-if="form.items.length > 1"
              icon="tabler-trash"
              size="small"
              variant="text"
              color="error"
              @click="removeRow(index)"
            />
          </div>

          <VRow>

            <VCol cols="12" md="6">
              <VSelect
                v-model="item.id_transportir"
                label="Transportir"
                :items="transportirs"
                item-title="nama_suplier"
                item-value="id"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField v-model="item.nama_kapal" label="Nama Kapal" />
            </VCol>
          </VRow>

          <VRow>
            
            <VCol cols="12" md="3">
              <VTextField v-model="item.tipe_kapal" label="Tipe Kapal" />
            </VCol>
  
            <VCol cols="12" md="3">
              <VTextField
                label="Max Kapal"
                suffix="L"
                :model-value="formatMoney(item.max_kapal)"
                @update:model-value="val => item.max_kapal = parseNumber(val)"
              />
              </VCol>
                <VCol cols="12" md="3">
                <VTextField
                  label="Volume Angkut"
                  suffix="L"
                  :model-value="formatMoney(item.volume_angkut)"
                  @update:model-value="val => item.volume_angkut = parseNumber(val)"
                />
              </VCol>

              <VCol cols="12" md="3">
                <VTextField
                  label="Harga / Liter"
                  prefix="Rp"
                  :model-value="formatMoney(item.harga_angkut)"
                  @update:model-value="val => item.harga_angkut = parseNumber(val)"
                />
              </VCol>
          </VRow>
          <VRow>

            <VCol cols="12" md="6">
              <VTextField v-model="item.asal_angkut" label="Asal" />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField v-model="item.tujuan_angkut" label="Tujuan" />
            </VCol>
          </VRow>

          


        </div>

      </VCardText>

      <VDivider class="mb-4"/>

      <!-- ACTION -->
      <VCardActions>
        <VSpacer />

        <VBtn
          variant="tonal"
          color="secondary"
          @click="router.push('/master/kapal')"
        >
          Kembali
        </VBtn>

        <VBtn
          color="primary"
          variant="flat"
          :loading="loading"
          @click="submit"
        >
          Simpan 
        </VBtn>

      </VCardActions>

    </VCard>

  </VContainer>
</template>