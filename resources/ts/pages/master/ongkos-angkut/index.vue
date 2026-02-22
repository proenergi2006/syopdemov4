<script setup lang="ts">
import axios from '@axios';
import { computed, onMounted, ref, watch } from 'vue';

type Opt = { id: number; name: string }

type Row = {
  id: number
  id_transportir: number
  id_wil_angkut: number
  id_prov_angkut: number
  id_kab_angkut: number
  id_vol_angkut: number
  ongkos_angkut: number

  // optional kalau backend load relasi
  transportir?: any
  wilayahAngkut?: any
  provinsi?: any
  kabupaten?: any
  volume?: any
}

const loading = ref(false)
const rows = ref<Row[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(10)

const search = ref('')
const fTransportir = ref<number | null>(null)
const fWilayah = ref<number | null>(null)
const fVolume = ref<number | null>(null)

// ===== Options =====
const optTransportir = ref<Opt[]>([])
const optWilayah = ref<Opt[]>([])
const optVolume = ref<Opt[]>([])
const optProvinsi = ref<Opt[]>([])
const optKabupaten = ref<Opt[]>([]) // ✅ dependent provinsi

// ===== Helpers =====
function pickName(x: any): string {
  return (
    x?.nama_transportir ??
    x?.transportir ??
    x?.nama_perusahaan ??

    // wilayah angkut
    x?.wilayah_angkut ??
    x?.nama_wilayah_angkut ??
    x?.nama_wilayah ??
    x?.wilayah ??

    // prov/kab
    x?.nama_provinsi ??
    x?.provinsi ??
    x?.nama_kabupaten ??
    x?.kabupaten ??

    // volume
    x?.volume_angkut ??
    x?.nama_volume ??
    x?.volume ??

    // umum
    x?.nama ??
    x?.name ??
    x?.title ??
    x?.label ??
    x?.kode ??
    x?.keterangan ??
    String(x?.id ?? '')
  )
}

function toOpt(list: any[]): Opt[] {
  return (list || [])
    .map((x: any) => ({
      id: Number(x?.id ?? x?.id_master ?? x?.value ?? 0),
      name: String(pickName(x) ?? ''),
    }))
    .filter(x => x.id && x.name && x.name !== 'undefined')
}

const mapTransportir = computed(() => new Map(optTransportir.value.map(o => [o.id, o.name])))
const mapWilayah = computed(() => new Map(optWilayah.value.map(o => [o.id, o.name])))
const mapVolume = computed(() => new Map(optVolume.value.map(o => [o.id, o.name])))
const mapProvinsi = computed(() => new Map(optProvinsi.value.map(o => [o.id, o.name])))
const mapKabupaten = computed(() => new Map(optKabupaten.value.map(o => [o.id, o.name])))

function nTransportir(id: number) { return mapTransportir.value.get(Number(id)) ?? '-' }
function nWilayah(id: number) { return mapWilayah.value.get(Number(id)) ?? '-' }
function nVolume(id: number) { return mapVolume.value.get(Number(id)) ?? '-' }
function nProvinsi(id: number) { return mapProvinsi.value.get(Number(id)) ?? '-' }
function nKabupaten(id: number) { return mapKabupaten.value.get(Number(id)) ?? '-' }

function fmtRp(n: any) {
  const v = Number(n ?? 0)
  return new Intl.NumberFormat('id-ID').format(v)
}

// ===== Modal =====
const isOpen = ref(false)
const isEdit = ref(false)
const isSaving = ref(false)

const form = ref({
  id: null as number | null,
  id_transportir: null as number | null,
  id_wil_angkut: null as number | null,
  id_prov_angkut: null as number | null,
  id_kab_angkut: null as number | null,
  id_vol_angkut: null as number | null,
  ongkos_angkut: 0,
})

const dialogTitle = computed(() => (isEdit.value ? 'Edit Ongkos Angkut' : 'Tambah Ongkos Angkut'))
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / (perPage.value || 10))))

function resetForm() {
  form.value = {
    id: null,
    id_transportir: null,
    id_wil_angkut: null,
    id_prov_angkut: null,
    id_kab_angkut: null,
    id_vol_angkut: null,
    ongkos_angkut: 0,
  }
  optKabupaten.value = []
}

function openCreate() {
  resetForm()
  isEdit.value = false
  isOpen.value = true
}

async function openEdit(r: Row) {
  resetForm()
  isEdit.value = true
  isOpen.value = true

  form.value.id = r.id
  form.value.id_transportir = Number(r.id_transportir)
  form.value.id_wil_angkut = Number(r.id_wil_angkut)
  form.value.id_prov_angkut = Number(r.id_prov_angkut)
  form.value.id_vol_angkut = Number(r.id_vol_angkut)
  form.value.ongkos_angkut = Number(r.ongkos_angkut ?? 0)

  // ✅ load kabupaten sesuai provinsi dulu
  await fetchKabupatenByProvinsi(form.value.id_prov_angkut)
  form.value.id_kab_angkut = Number(r.id_kab_angkut)
}

// ===== Fetch Options =====
async function fetchOptions() {
  try {
    const { data } = await axios.get('/master/transportir', { params: { per_page: 999 } })
    optTransportir.value = toOpt(data?.data ?? data)
  } catch (e) {
    console.error('fetch transportir opt error', e)
    optTransportir.value = []
  }

  try {
    const { data } = await axios.get('/master/wilayah-angkut', { params: { per_page: 999 } })
    optWilayah.value = toOpt(data?.data ?? data)
  } catch (e) {
    console.error('fetch wilayah opt error', e)
    optWilayah.value = []
  }

  try {
    const { data } = await axios.get('/master/volume', { params: { per_page: 999 } })
    optVolume.value = toOpt(data?.data ?? data)
  } catch (e) {
    console.error('fetch volume opt error', e)
    optVolume.value = []
  }

  try {
    const { data } = await axios.get('/master/provinsi', { params: { per_page: 999 } })
    optProvinsi.value = toOpt(data?.data ?? data)
  } catch (e) {
    console.error('fetch provinsi opt error', e)
    optProvinsi.value = []
  }
}

/**
 * ✅ Kabupaten dependent provinsi
 * Endpoint kamu: /kabupaten/{provinsi}
 */
async function fetchKabupatenByProvinsi(provId: number | null) {
  if (!provId) {
    optKabupaten.value = []
    form.value.id_kab_angkut = null
    return
  }

  try {
    const { data } = await axios.get(`/kabupaten/${provId}`)
    const list = data?.data ?? data
    optKabupaten.value = toOpt(list || [])
  } catch (e) {
    console.error('fetch kabupaten by provinsi error', e)
    optKabupaten.value = []
  }
}

// ketika user ganti provinsi, kabupaten reset dan reload
watch(
  () => form.value.id_prov_angkut,
  async (v, old) => {
    if (v !== old) {
      form.value.id_kab_angkut = null
      await fetchKabupatenByProvinsi(v ? Number(v) : null)
    }
  }
)

// ===== Fetch List =====
async function fetchList() {
  loading.value = true
  try {
    const params: any = {
      page: page.value,
      per_page: perPage.value,
    }
    if (search.value) params.search = search.value
    if (fTransportir.value) params.id_transportir = fTransportir.value
    if (fWilayah.value) params.id_wil_angkut = fWilayah.value
    if (fVolume.value) params.id_vol_angkut = fVolume.value

    const { data } = await axios.get('/master/ongkos-angkut', { params })
    rows.value = data?.data ?? []
    total.value = Number(data?.total ?? rows.value.length)
  } catch (e) {
    console.error('fetchList error', e)
    rows.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

watch([page, perPage], () => fetchList())

watch([search, fTransportir, fWilayah, fVolume], () => {
  page.value = 1
  fetchList()
})

// ===== CRUD =====
async function save() {
  if (!form.value.id_transportir) return alert('Transportir wajib diisi')
  if (!form.value.id_wil_angkut) return alert('Wilayah wajib diisi')
  if (!form.value.id_prov_angkut) return alert('Provinsi wajib diisi')
  if (!form.value.id_kab_angkut) return alert('Kabupaten wajib diisi')
  if (!form.value.id_vol_angkut) return alert('Volume wajib diisi')

  isSaving.value = true
  try {
    const payload = {
      id_transportir: form.value.id_transportir,
      id_wil_angkut: form.value.id_wil_angkut,
      id_prov_angkut: form.value.id_prov_angkut,
      id_kab_angkut: form.value.id_kab_angkut,
      id_vol_angkut: form.value.id_vol_angkut,
      ongkos_angkut: Number(form.value.ongkos_angkut ?? 0),
    }

    if (isEdit.value && form.value.id) {
      // ✅ FIX: apiResource update harus PUT/PATCH
      await axios.put(`/master/ongkos-angkut/${form.value.id}`, payload)
      // atau PATCH:
      // await axios.patch(`/master/ongkos-angkut/${form.value.id}`, payload)
    } else {
      await axios.post('/master/ongkos-angkut', payload)
    }

    isOpen.value = false
    await fetchList()
  } catch (e: any) {
    console.error('save error', e)
    alert(e?.response?.data?.message ?? 'Gagal simpan')
  } finally {
    isSaving.value = false
  }
}

async function removeRow(r: Row) {
  if (!confirm('Hapus data ongkos angkut ini?')) return
  try {
    await axios.delete(`/master/ongkos-angkut/${r.id}`)
    await fetchList()
  } catch (e) {
    console.error('delete error', e)
  }
}

onMounted(async () => {
  await fetchOptions()
  await fetchList()
})
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between">
      <div>
        <h2 class="text-h5 mb-1">Ongkos Angkut</h2>
        <div class="text-caption text-medium-emphasis">
          Master ongkos angkut berdasarkan transportir, wilayah, provinsi/kabupaten, dan volume.
        </div>
      </div>

      <VBtn color="primary" @click="openCreate">
        + Tambah Ongkos
      </VBtn>
    </div>

    <!-- Filters -->
    <VCard>
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="5">
            <VTextField
              v-model="search"
              label="Cari (transportir / wilayah / prov / kab / volume)"
              variant="outlined"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" md="3">
            <VSelect
              v-model="fTransportir"
              :items="optTransportir"
              item-title="name"
              item-value="id"
              label="Transportir"
              variant="outlined"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" md="2">
            <VSelect
              v-model="fWilayah"
              :items="optWilayah"
              item-title="name"
              item-value="id"
              label="Wilayah"
              variant="outlined"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" md="2">
            <VSelect
              v-model="fVolume"
              :items="optVolume"
              item-title="name"
              item-value="id"
              label="Volume"
              variant="outlined"
              density="compact"
              clearable
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard>
      <VCardText>
        <div class="d-flex align-center justify-space-between mb-3">
          <div class="text-caption text-medium-emphasis">
            Total: <b>{{ total }}</b>
          </div>

          <div class="d-flex gap-2 align-center">
            <div class="text-caption">Per page</div>
            <VSelect
              v-model="perPage"
              :items="[10, 20, 50, 100]"
              density="compact"
              variant="outlined"
              style="width: 110px"
            />
          </div>
        </div>

        <VTable class="rounded-lg" density="compact">
          <thead>
            <tr>
              <th>Transportir</th>
              <th>Wilayah</th>
              <th>Provinsi</th>
              <th>Kabupaten</th>
              <th>Volume</th>
              <th class="text-right">Ongkos (Rp)</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>

          <tbody>
            <tr v-if="loading">
              <td colspan="7" class="text-center py-6 text-medium-emphasis">Loading...</td>
            </tr>

            <tr v-else-if="rows.length === 0">
              <td colspan="7" class="text-center py-6 text-medium-emphasis">Tidak ada data.</td>
            </tr>

            <tr v-else v-for="r in rows" :key="r.id">
              <td>{{ r.transportir?.nama_transportir ?? nTransportir(r.id_transportir) }}</td>
              <td>{{ r.wilayahAngkut ? pickName(r.wilayahAngkut) : nWilayah(r.id_wil_angkut) }}</td>
              <td>{{ r.provinsi ? pickName(r.provinsi) : nProvinsi(r.id_prov_angkut) }}</td>
              <td>{{ r.kabupaten ? pickName(r.kabupaten) : nKabupaten(r.id_kab_angkut) }}</td>
              <td>{{ r.volume ? pickName(r.volume) : nVolume(r.id_vol_angkut) }}</td>
              <td class="text-right">{{ fmtRp(r.ongkos_angkut) }}</td>
              <td class="text-right">
                <div class="d-flex gap-2 justify-end">
                  <VBtn size="small" variant="tonal" @click="openEdit(r)">Edit</VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="removeRow(r)">Hapus</VBtn>
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>

        <div class="d-flex justify-end mt-4">
          <VPagination v-model="page" :length="pageCount" :total-visible="7" density="comfortable" />
        </div>
      </VCardText>
    </VCard>

    <!-- Modal -->
    <VDialog v-model="isOpen" max-width="980" persistent>
      <VCard class="rounded-lg">
        <VCardTitle class="text-h5 d-flex align-center justify-space-between">
          <span>{{ dialogTitle }}</span>

          <div class="d-flex gap-2">
            <VBtn variant="tonal" @click="isOpen = false" :disabled="isSaving">Cancel</VBtn>
            <VBtn color="primary" @click="save" :loading="isSaving" :disabled="isSaving">Save</VBtn>
          </div>
        </VCardTitle>

        <VDivider />

        <VCardText class="pt-6" style="max-height: 72vh; overflow: auto;">
          <VForm>
            <VRow dense>
              <VCol cols="12" md="6">
                <VSelect
                  v-model="form.id_transportir"
                  :items="optTransportir"
                  item-title="name"
                  item-value="id"
                  label="Transportir *"
                  variant="outlined"
                  density="compact"
                  clearable
                />
              </VCol>

              <VCol cols="12" md="6">
                <VSelect
                  v-model="form.id_wil_angkut"
                  :items="optWilayah"
                  item-title="name"
                  item-value="id"
                  label="Wilayah Angkut *"
                  variant="outlined"
                  density="compact"
                  clearable
                />
              </VCol>

              <VCol cols="12" md="6">
                <VSelect
                  v-model="form.id_prov_angkut"
                  :items="optProvinsi"
                  item-title="name"
                  item-value="id"
                  label="Provinsi *"
                  variant="outlined"
                  density="compact"
                  clearable
                />
              </VCol>

              <VCol cols="12" md="6">
                <VSelect
                  v-model="form.id_kab_angkut"
                  :items="optKabupaten"
                  item-title="name"
                  item-value="id"
                  label="Kabupaten *"
                  variant="outlined"
                  density="compact"
                  clearable
                  :disabled="!form.id_prov_angkut"
                  hint="Pilih provinsi dulu untuk memuat kabupaten"
                  persistent-hint
                />
              </VCol>

              <VCol cols="12" md="6">
                <VSelect
                  v-model="form.id_vol_angkut"
                  :items="optVolume"
                  item-title="name"
                  item-value="id"
                  label="Volume *"
                  variant="outlined"
                  density="compact"
                  clearable
                />
              </VCol>

              <VCol cols="12" md="6">
                <VTextField
                  v-model.number="form.ongkos_angkut"
                  label="Ongkos Angkut (Rp) *"
                  type="number"
                  min="0"
                  variant="outlined"
                  density="compact"
                />
                <div class="text-caption text-medium-emphasis mt-1">
                  Preview: Rp {{ fmtRp(form.ongkos_angkut) }}
                </div>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>