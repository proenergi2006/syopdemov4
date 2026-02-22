<script setup lang="ts">
import axios from '@axios';
import { computed, onMounted, ref, watch } from 'vue';

type Opt = { id: number; name: string }

type CustomerRow = {
  id: number
  marketing_id: number
  nama_perusahaan: string
  email: string
  alamat_perusahaan: string
  provinsi_id: number
  kabupaten_id: number
  postal_code?: string | null
  telepon: string
  fax?: string | null
  jenis_customer: string
  is_active: boolean

  marketing?: any
  provinsi?: any
  kabupaten?: any
}

const loading = ref(false)
const rows = ref<CustomerRow[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(10)

const search = ref('')
const fActive = ref<'ALL' | 'ACTIVE' | 'INACTIVE'>('ALL')

// options
const optMarketing = ref<Opt[]>([])
const optProvinsi = ref<Opt[]>([])
const optKabupaten = ref<Opt[]>([]) // dependent provinsi
const optJenisCustomer = ref([
  { title: 'Retail', value: 'Retail' },
  { title: 'Industri', value: 'Industri' },
  { title: 'BUMN', value: 'BUMN' },
  { title: 'Lainnya', value: 'Lainnya' },
])

function pickName(x: any): string {
  return (
    x?.name ??
    x?.nama ??
    x?.nama_provinsi ??
    x?.nama_kabupaten ??
    x?.kabupaten ??
    x?.provinsi ??
    x?.title ??
    x?.label ??
    String(x?.id ?? '')
  )
}
function toOpt(list: any[]): Opt[] {
  return (list || [])
    .map((x: any) => ({
      id: Number(x?.id ?? x?.value ?? x?.id_master ?? 0),
      name: String(pickName(x) ?? ''),
    }))
    .filter(x => x.id && x.name && x.name !== 'undefined')
}

const marketingMap = computed(() => new Map(optMarketing.value.map(o => [o.id, o.name])))
const provinsiMap = computed(() => new Map(optProvinsi.value.map(o => [o.id, o.name])))
const kabupatenMap = computed(() => new Map(optKabupaten.value.map(o => [o.id, o.name])))

function mName(id: number) { return marketingMap.value.get(Number(id)) ?? '-' }
function pName(id: number) { return provinsiMap.value.get(Number(id)) ?? '-' }
function kName(id: number) { return kabupatenMap.value.get(Number(id)) ?? '-' }

// modal
const isOpen = ref(false)
const isEdit = ref(false)
const isSaving = ref(false)

const form = ref({
  id: null as number | null,
  marketing_id: null as number | null,
  nama_perusahaan: '',
  email: '',
  alamat_perusahaan: '',
  provinsi_id: null as number | null,
  kabupaten_id: null as number | null,
  postal_code: '',
  telepon: '',
  fax: '',
  jenis_customer: '',
  is_active: true,
})

const dialogTitle = computed(() => (isEdit.value ? 'Edit Data Customer' : 'Tambah Data Customer'))
const pageCount = computed(() => Math.max(1, Math.ceil(total.value / (perPage.value || 10))))

function resetForm() {
  form.value = {
    id: null,
    marketing_id: null,
    nama_perusahaan: '',
    email: '',
    alamat_perusahaan: '',
    provinsi_id: null,
    kabupaten_id: null,
    postal_code: '',
    telepon: '',
    fax: '',
    jenis_customer: '',
    is_active: true,
  }
  optKabupaten.value = []
}

function openCreate() {
  resetForm()
  isEdit.value = false
  isOpen.value = true
}

async function openEdit(r: CustomerRow) {
  resetForm()
  isEdit.value = true
  isOpen.value = true

  form.value.id = r.id
  form.value.marketing_id = Number(r.marketing_id)
  form.value.nama_perusahaan = r.nama_perusahaan ?? ''
  form.value.email = r.email ?? ''
  form.value.alamat_perusahaan = r.alamat_perusahaan ?? ''
  form.value.provinsi_id = Number(r.provinsi_id)
  form.value.postal_code = r.postal_code ?? ''
  form.value.telepon = r.telepon ?? ''
  form.value.fax = r.fax ?? ''
  form.value.jenis_customer = r.jenis_customer ?? ''
  form.value.is_active = !!r.is_active

  // load kabupaten sesuai provinsi lalu set kabupaten_id
  await fetchKabupatenByProvinsi(form.value.provinsi_id)
  form.value.kabupaten_id = Number(r.kabupaten_id)
}

// ===== fetch options =====
async function fetchMarketingOptions() {
  try {
    // ambil user untuk marketing (kalau tidak ada endpoint khusus, ambil semua user)
    // jika kamu punya filter role marketing, bisa ditambah param query
    const { data } = await axios.get('/master/users', { params: { per_page: 999 } })
    const list = data?.data ?? data
    optMarketing.value = toOpt(list || []).map(x => ({
      id: x.id,
      // tampilkan nama user lebih bagus: "Nama (email)" kalau tersedia
      name: x.name,
    }))
    // kalau response user bukan {name}, fallback otomatis pickName() juga bisa
    // tapi di atas kita pakai toOpt, jadi biasanya sudah ok
  } catch (e) {
    console.error('fetch marketing options error', e)
    optMarketing.value = []
  }
}

async function fetchProvinsiOptions() {
  try {
    const { data } = await axios.get('/master/provinsi', { params: { per_page: 999 } })
    optProvinsi.value = toOpt(data?.data ?? data)
  } catch (e) {
    console.error('fetch provinsi options error', e)
    optProvinsi.value = []
  }
}

/**
 * dependent kabupaten: endpoint kamu /kabupaten/{provinsi}
 */
async function fetchKabupatenByProvinsi(provId: number | null) {
  if (!provId) {
    optKabupaten.value = []
    form.value.kabupaten_id = null
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

// watch provinsi -> reset kabupaten + fetch ulang
watch(
  () => form.value.provinsi_id,
  async (v, old) => {
    if (v !== old) {
      form.value.kabupaten_id = null
      await fetchKabupatenByProvinsi(v ? Number(v) : null)
    }
  }
)

// ===== fetch list =====
async function fetchList() {
  loading.value = true
  try {
    const params: any = {
      page: page.value,
      per_page: perPage.value,
    }
    if (search.value) params.search = search.value
    if (fActive.value === 'ACTIVE') params.is_active = true
    if (fActive.value === 'INACTIVE') params.is_active = false

    const { data } = await axios.get('/master/customers', { params })
    rows.value = data?.data ?? []
    total.value = Number(data?.total ?? rows.value.length)
  } catch (e) {
    console.error('fetch customer list error', e)
    rows.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

watch([page, perPage], () => fetchList())

watch([search, fActive], () => {
  page.value = 1
  fetchList()
})

// ===== CRUD =====
function validateForm(): string | null {
  if (!form.value.marketing_id) return 'Marketing wajib diisi'
  if (!form.value.nama_perusahaan?.trim()) return 'Nama Perusahaan wajib diisi'
  if (!form.value.email?.trim()) return 'Email wajib diisi'
  if (!form.value.alamat_perusahaan?.trim()) return 'Alamat Perusahaan wajib diisi'
  if (!form.value.provinsi_id) return 'Provinsi wajib diisi'
  if (!form.value.kabupaten_id) return 'Kabupaten/Kota wajib diisi'
  if (!form.value.telepon?.trim()) return 'Telepon wajib diisi'
  if (!form.value.jenis_customer?.trim()) return 'Jenis Customer wajib diisi'
  return null
}

async function save() {
  const err = validateForm()
  if (err) return alert(err)

  isSaving.value = true
  try {
    const payload = {
      marketing_id: form.value.marketing_id,
      nama_perusahaan: form.value.nama_perusahaan,
      email: form.value.email,
      alamat_perusahaan: form.value.alamat_perusahaan,
      provinsi_id: form.value.provinsi_id,
      kabupaten_id: form.value.kabupaten_id,
      postal_code: form.value.postal_code || null,
      telepon: form.value.telepon,
      fax: form.value.fax || null,
      jenis_customer: form.value.jenis_customer,
      is_active: form.value.is_active,
    }

    if (isEdit.value && form.value.id) {
      // ✅ apiResource update => PUT/PATCH
      await axios.put(`/master/customers/${form.value.id}`, payload)
    } else {
      await axios.post('/master/customers', payload)
    }

    isOpen.value = false
    await fetchList()
  } catch (e: any) {
    console.error('save customer error', e)
    alert(e?.response?.data?.message ?? 'Gagal simpan')
  } finally {
    isSaving.value = false
  }
}

async function removeRow(r: CustomerRow) {
  if (!confirm(`Hapus customer: ${r.nama_perusahaan}?`)) return
  try {
    await axios.delete(`/master/customers/${r.id}`)
    await fetchList()
  } catch (e) {
    console.error('delete customer error', e)
  }
}

onMounted(async () => {
  await fetchMarketingOptions()
  await fetchProvinsiOptions()
  await fetchList()
})
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between">
      <div>
        <h2 class="text-h5 mb-1">Customer</h2>
        <div class="text-caption text-medium-emphasis">
          Master data customer.
        </div>
      </div>

      <VBtn color="primary" @click="openCreate">
        + Tambah Customer
      </VBtn>
    </div>

    <!-- Filters -->
    <VCard>
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="8">
            <VTextField
              v-model="search"
              label="Cari (nama perusahaan / email / telepon)"
              variant="outlined"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" md="4">
            <VSelect
              v-model="fActive"
              :items="[
                { title: 'Semua', value: 'ALL' },
                { title: 'Aktif', value: 'ACTIVE' },
                { title: 'Nonaktif', value: 'INACTIVE' },
              ]"
              item-title="title"
              item-value="value"
              label="Status"
              variant="outlined"
              density="compact"
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
              <th>Nama Perusahaan</th>
              <th>Email</th>
              <th>Telepon</th>
              <th>Provinsi</th>
              <th>Kab/Kota</th>
              <th>Marketing</th>
              <th>Status</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>

          <tbody>
            <tr v-if="loading">
              <td colspan="8" class="text-center py-6 text-medium-emphasis">Loading...</td>
            </tr>

            <tr v-else-if="rows.length === 0">
              <td colspan="8" class="text-center py-6 text-medium-emphasis">Tidak ada data.</td>
            </tr>

            <tr v-else v-for="r in rows" :key="r.id">
              <td>{{ r.nama_perusahaan }}</td>
              <td>{{ r.email }}</td>
              <td>{{ r.telepon }}</td>
              <td>{{ r.provinsi ? pickName(r.provinsi) : pName(r.provinsi_id) }}</td>
              <td>{{ r.kabupaten ? pickName(r.kabupaten) : (r.provinsi_id === form.provinsi_id ? kName(r.kabupaten_id) : r.kabupaten_id) }}</td>
              <td>{{ r.marketing?.name ?? mName(r.marketing_id) }}</td>
              <td>
                <VChip size="small" :color="r.is_active ? 'success' : 'secondary'" variant="tonal">
                  {{ r.is_active ? 'Aktif' : 'Nonaktif' }}
                </VChip>
              </td>
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
    <VDialog v-model="isOpen" max-width="1100" persistent>
      <VCard class="rounded-lg">
        <VCardTitle class="text-h5 d-flex align-center justify-space-between">
          <span>{{ dialogTitle }}</span>

          <div class="d-flex gap-2">
            <VBtn variant="tonal" @click="isOpen = false" :disabled="isSaving">
              Cancel
            </VBtn>
            <VBtn color="primary" @click="save" :loading="isSaving" :disabled="isSaving">
              Save
            </VBtn>
          </div>
        </VCardTitle>

        <VDivider />

        <VCardText class="pt-6" style="max-height: 72vh; overflow: auto;">
          <VForm>
            <div class="text-subtitle-1 mb-4 d-flex align-center gap-2">
              <VIcon icon="tabler-edit" />
              Silahkan isi form dibawah ini
            </div>

            <VRow dense>
              <!-- Marketing -->
              <VCol cols="12" md="6">
                <VSelect
                  v-model="form.marketing_id"
                  :items="optMarketing"
                  item-title="name"
                  item-value="id"
                  label="Marketing *"
                  placeholder="Pilih salah satu"
                  variant="outlined"
                  density="compact"
                  clearable
                />
              </VCol>

              <!-- Nama Perusahaan -->
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.nama_perusahaan"
                  label="Nama Perusahaan *"
                  variant="outlined"
                  density="compact"
                />
                <div class="text-caption text-error mt-1">
                  * (Nama perusahaan harus sesuai dengan NPWP PT./CV.)
                </div>
              </VCol>

              <!-- Email -->
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.email"
                  label="Email *"
                  type="email"
                  variant="outlined"
                  density="compact"
                />
              </VCol>

              <!-- Alamat -->
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.alamat_perusahaan"
                  label="Alamat Perusahaan *"
                  variant="outlined"
                  density="compact"
                />
              </VCol>

              <!-- Provinsi -->
              <VCol cols="12" md="6">
                <VSelect
                  v-model="form.provinsi_id"
                  :items="optProvinsi"
                  item-title="name"
                  item-value="id"
                  label="Provinsi *"
                  placeholder="Pilih salah satu"
                  variant="outlined"
                  density="compact"
                  clearable
                />
              </VCol>

              <!-- Kabupaten -->
              <VCol cols="12" md="6">
                <VSelect
                  v-model="form.kabupaten_id"
                  :items="optKabupaten"
                  item-title="name"
                  item-value="id"
                  label="Kabupaten/Kota *"
                  placeholder="Pilih salah satu"
                  variant="outlined"
                  density="compact"
                  clearable
                  :disabled="!form.provinsi_id"
                  hint="Pilih provinsi dulu"
                  persistent-hint
                />
              </VCol>

              <!-- Postal Code -->
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.postal_code"
                  label="Postal Code"
                  variant="outlined"
                  density="compact"
                />
              </VCol>

              <!-- Telepon -->
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.telepon"
                  label="Telepon *"
                  variant="outlined"
                  density="compact"
                />
              </VCol>

              <!-- Fax -->
              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.fax"
                  label="Fax"
                  variant="outlined"
                  density="compact"
                />
              </VCol>

              <!-- Jenis Customer -->
              <VCol cols="12" md="6">
                <VSelect
                  v-model="form.jenis_customer"
                  :items="optJenisCustomer"
                  item-title="title"
                  item-value="value"
                  label="Jenis Customer *"
                  placeholder="Pilih salah satu"
                  variant="outlined"
                  density="compact"
                  clearable
                />
              </VCol>

              <!-- Status -->
              <VCol cols="12">
                <VSwitch
                  v-model="form.is_active"
                  label="Active"
                  inset
                />
              </VCol>
            </VRow>

            <div class="text-caption text-medium-emphasis mt-4">
              * Wajib Diisi
            </div>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>