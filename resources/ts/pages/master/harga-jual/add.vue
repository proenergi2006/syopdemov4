<script setup lang="ts">
import { useRouter } from 'vue-router'
import axios from '@axios'
import { ref } from 'vue'

const router = useRouter()

// MAIN FORM
const formMain = ref({
  periode_awal: '',
  periode_akhir: '',
})

// ROWS DETAIL
const hargaList = ref([
  {
    id_area: null,
    produk: null,
    harga_normal: "",
    harga_sm: "",
    harga_om: "",
    harga_coo: "",
    harga_ceo: "",
    note_jual: "",
  }
])

const addRow = () => {
  hargaList.value.push({
    id_area: null,
    produk: null,
    harga_normal: "",
    harga_sm: "",
    harga_om: "",
    harga_coo: "",
    harga_ceo: "",
    note_jual: "",
  })
}

const removeRow = (i: number) => {
  hargaList.value.splice(i, 1)
}

// DROPDOWNS
const areaList = ref<any[]>([])
const produkList = ref<any[]>([])

// FETCH
const getArea = async () => {
  const res = await axios.get("/area")
  areaList.value = res.data
}

const getProduk = async () => {
  const res = await axios.get("/produk")
  produkList.value = res.data
}

getArea()
getProduk()

// FORMAT RUPIAH
const format = (val: any) => {
  if (!val) return ""
  return val.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".")
}
const unformat = (val: string) => val.replace(/\./g, "")

const onPrice = (row: any, key: string, e: any) => {
  const angka = unformat(e.target.value)
  row[key] = format(angka)
}

// SAVE
const save = async () => {
  const payload = {
    periode_awal: formMain.value.periode_awal,
    periode_akhir: formMain.value.periode_akhir,
    list: hargaList.value.map(h => ({
      id_area: h.id_area,
      produk: h.produk,
      harga_normal: Number(unformat(h.harga_normal)),
      harga_sm: Number(unformat(h.harga_sm)),
      harga_om: Number(unformat(h.harga_om)),
      harga_coo: Number(unformat(h.harga_coo)),
      harga_ceo: Number(unformat(h.harga_ceo)),
      note_jual: h.note_jual ?? "", 
    }))
  }

  await axios.post("/master/harga-jual", payload)
  alert("Berhasil disimpan")
  router.back()
}
</script>

<template>
  <VCard title="Tambah Harga Jual">
    <VCardText>

      <!-- HEADER -->
      <VAlert type="info" variant="tonal" class="mb-4">
        Silahkan isi form dibawah ini
      </VAlert>

      <!-- PERIODE -->
      <VRow>
        <VCol cols="12" md="6">
          <VTextField label="Periode Awal *" type="date" v-model="formMain.periode_awal" />
        </VCol>
        <VCol cols="12" md="6">
          <VTextField label="Periode Akhir *" type="date" v-model="formMain.periode_akhir" />
        </VCol>
      </VRow>

      <!-- LIST HARGA JUAL -->
      <div class="d-flex align-center justify-space-between mt-6 mb-2">
        <h3>List Harga Jual</h3>
        <VBtn color="primary" icon @click="addRow">
          <VIcon icon="mdi-plus" />
        </VBtn>
      </div>

      <!-- EACH ROW -->
      <div v-for="(row, i) in hargaList" :key="i" class="mb-8">
        <VRow>
          <VCol cols="12" md="6">
            <VSelect
              v-model="row.id_area"
              :items="areaList"
              item-title="nama_area"
              item-value="id"
              label="Area *"
            />
          </VCol>

          <VCol cols="12" md="6">
            <VSelect
              v-model="row.produk"
              :items="produkList"
              item-title="merk_dagang"
              item-value="id"
              label="Produk *"
            />
          </VCol>
        </VRow>

        <!-- TABLE HEADER -->
        <VTable class="mt-3">
          <thead>
            <tr>
              <th style="text-align:center">Harga Dasar<br>(Pricelist yang dishare)</th>
              <th style="text-align:center">TIER I<br>BM</th>
              <th style="text-align:center">TIER II<br>OM</th>
              <th style="text-align:center">TIER III<br>COO</th>
              <th style="text-align:center">TIER III<br>CEO</th>
              <th style="text-align:center">Aksi</th>
            </tr>
          </thead>

          <tbody>
            <tr>
              <!-- Harga Dasar -->
              <td>
                <VTextField
                  density="compact"
                  v-model="row.harga_normal"
                  @input="onPrice(row,'harga_normal',$event)"
                />
              </td>
              <!-- BM -->
              <td>
                <VTextField
                  density="compact"
                  v-model="row.harga_sm"
                  @input="onPrice(row,'harga_sm',$event)"
                />
              </td>
              <!-- OM -->
              <td>
                <VTextField
                  density="compact"
                  v-model="row.harga_om"
                  @input="onPrice(row,'harga_om',$event)"
                />
              </td>
              <!-- COO -->
              <td>
                <VTextField
                  density="compact"
                  v-model="row.harga_coo"
                  @input="onPrice(row,'harga_coo',$event)"
                />
              </td>
              <!-- CEO -->
              <td>
                <VTextField
                  density="compact"
                  v-model="row.harga_ceo"
                  @input="onPrice(row,'harga_ceo',$event)"
                />
              </td>

              <td class="text-center">
                <VBtn icon color="red" @click="removeRow(i)">
                  <VIcon icon="mdi-delete" />
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
        <VRow class="mt-2">
          <VCol cols="12">
            <VTextarea
              v-model="row.note_jual"
              label="Catatan / Keterangan"
              placeholder="Tulis catatan tambahan…"
              variant="outlined"
              density="compact"
            />
          </VCol>
      </VRow>
      </div>

      <!-- BUTTONS -->
      <div class="d-flex gap-3 mt-5">
        <VBtn variant="outlined" color="secondary" @click="router.back()">Kembali</VBtn>
        <VBtn color="primary" @click="save">Simpan</VBtn>
      </div>
    </VCardText>
  </VCard>
</template>