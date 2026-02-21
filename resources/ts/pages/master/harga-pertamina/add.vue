<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import axios from '@axios'
import { ref, onMounted } from 'vue'

const router = useRouter()
const route = useRoute()

const isEdit = ref(false)
const editId = ref<number | null>(null)

type DetailHarga = {
  id_area: number | null
  id_produk: number | null
  harga_minyak: number | null
}

const formMain = ref({
  periode_awal: '',
  periode_akhir: '',
})

const formDetails = ref<DetailHarga[]>([
  { id_area: null, id_produk: null, harga_minyak: null }
])

const addRow = () => {
  formDetails.value.push({ id_area: null, id_produk: null, harga_minyak: null })
}

const removeRow = (index: number) => {
  formDetails.value.splice(index, 1)
}

const areaList = ref<any[]>([])
const produkList = ref<any[]>([])

const getArea = async () => {
  const res = await axios.get('/area')
  areaList.value = res.data
}
const getProduk = async () => {
  const res = await axios.get('/produk')
  produkList.value = res.data.map((p: any) => ({
    id: p.id,
    label: `${p.merk_dagang} - ${p.jenis_produk}`,
  }))
}

// ================================
// LOAD DATA FOR EDIT
// ================================
const loadDetail = async (id: number) => {
  try {
    const res = await axios.get(`/master/harga-pertamina/${id}`)

    const data = res.data

    formMain.value.periode_awal = data.periode_awal
    formMain.value.periode_akhir = data.periode_akhir

    formDetails.value = data.details.map((d: any) => ({
      id_area: d.id_area,
      id_produk: d.id_produk,
      harga_minyak: d.harga_minyak
    }))

  } catch (error) {
    console.error(error)
    alert('Gagal memuat data')
  }
}

// ================================
// SAVE (ADD / EDIT)
// ================================
const save = async () => {
  if (!formMain.value.periode_awal || !formMain.value.periode_akhir) {
    alert('Periode harus diisi')
    return
  }

  const payload = {
    periode_awal: formMain.value.periode_awal,
    periode_akhir: formMain.value.periode_akhir,
    details: formDetails.value,
  }

  try {
    if (isEdit.value && editId.value) {
      await axios.put(`/master/harga-pertamina/${editId.value}`, payload)
    } else {
      await axios.post('/master/harga-pertamina', payload)
    }

    alert('Data tersimpan')
    router.back()

  } catch (e) {
    console.error(e)
    alert('Gagal menyimpan data')
  }
}
onMounted(async () => {
  await getArea()
  await getProduk()

  if (route.params.id) {
    isEdit.value = true
    editId.value = Number(route.params.id)
    await loadDetail(editId.value)
  }
})

const goBack = () => router.back()

const formatNumber = (value: any) => {
  if (value === null || value === undefined) return ''
  return value.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".")
}

const unformatNumber = (value: any) => {
  if (!value) return null
  return Number(value.toString().replace(/\./g, ''))
}
</script>

<template>
  <VCard title="Tambah Harga Dasar Pertamina">
    <VCardText>
      <VAlert type="info" variant="tonal" class="mb-4">
        Silahkan isi form dibawah ini
      </VAlert>

      <!-- Periode -->
      <VRow>
        <VCol cols="12" md="6">
          <VTextField
            v-model="formMain.periode_awal"
            label="Periode Awal *"
            type="date"
          />
        </VCol>

        <VCol cols="12" md="6">
          <VTextField
            v-model="formMain.periode_akhir"
            label="Periode Akhir *"
            type="date"
          />
        </VCol>
      </VRow>

      <!-- Table -->
      <VTable class="mt-4">
        <thead>
          <tr>
            <th>Area</th>
            <th>Produk</th>
            <th>Harga</th>
            <th width="50"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in formDetails" :key="index">
            <td>
              <VSelect
                v-model="row.id_area"
                :items="areaList"
                item-title="nama_area"
                item-value="id"
                placeholder="Pilih salah satu"
                density="compact"
              />
            </td>

            <td>
         <VSelect
            v-model="row.id_produk"
            :items="produkList"
            item-title="label"
            item-value="id"
            placeholder="Pilih salah satu"
            density="compact"
          />
            </td>

            <td>
             <VTextField
              :model-value="formatNumber(row.harga_minyak)"
              density="compact"
              @input="row.harga_minyak = unformatNumber($event.target.value)"
            />
            </td>

            <td class="text-center">
              <VBtn
                icon
                color="error"
                variant="tonal"
                @click="removeRow(index)"
                v-if="formDetails.length > 1"
              >
                <VIcon icon="mdi-delete" />
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>

      <div class="mt-2">
        <VBtn
          icon
          color="primary"
          variant="tonal"
          @click="addRow"
        >
          <VIcon icon="mdi-plus" />
        </VBtn>
      </div>

      <!-- Action Buttons -->
      <div class="mt-6 d-flex gap-3">
        <VBtn variant="outlined" color="secondary" @click="goBack">
          Kembali
        </VBtn>

        <VBtn color="primary" @click="save">
          Save
        </VBtn>
      </div>
    </VCardText>
  </VCard>
</template>