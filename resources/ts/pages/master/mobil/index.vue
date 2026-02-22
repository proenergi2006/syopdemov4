<script setup lang="ts">
import axios from '@axios';
import { computed, onMounted, ref, watch } from 'vue';

type TransportirOpt = { id: number; nama_transportir: string }

type MobilRow = {
  id_master: number
  id_transportir: number
  nomor_plat: string
  no_proyek?: string | null
  max_kap: number
  komp_tanki: string
  link_gps: string
  user_gps: string
  pass_gps: string
  membercode_gps: string
  is_active: number
  photo?: string | null
  photo_ori?: string | null
  photo_url?: string | null
  transportir_nama?: string | null
}

const loading = ref(false)
const rows = ref<MobilRow[]>([])
const total = ref(0)

const page = ref(1)
const perPage = ref(10)

const search = ref('')
const status = ref<'ALL' | 'ACTIVE' | 'INACTIVE'>('ALL')

const transportirOptions = ref<TransportirOpt[]>([])
const transportirMap = computed(() => {
  const m = new Map<number, string>()
  for (const t of transportirOptions.value) m.set(Number(t.id), t.nama_transportir)
  return m
})

// ===== Modal =====
const isOpen = ref(false)
const isEdit = ref(false)
const isSaving = ref(false)

const form = ref({
  id_master: null as number | null,
  id_transportir: null as number | null,
  nomor_plat: '',
  no_proyek: '',
  max_kap: 0,
  komp_tanki: '',
  link_gps: '',
  user_gps: '',
  pass_gps: '',
  membercode_gps: '',
  is_active: 1,
})

const photoFile = ref<File | null>(null)
const photoPreview = ref<string | null>(null)

const dialogTitle = computed(() => (isEdit.value ? 'Edit Mobil' : 'Tambah Mobil'))

const pageCount = computed(() => Math.max(1, Math.ceil(total.value / (perPage.value || 10))))

function resetForm() {
  form.value = {
    id_master: null,
    id_transportir: null,
    nomor_plat: '',
    no_proyek: '',
    max_kap: 0,
    komp_tanki: '',
    link_gps: '',
    user_gps: '',
    pass_gps: '',
    membercode_gps: '',
    is_active: 1,
  }

  photoFile.value = null
  if (photoPreview.value?.startsWith('blob:')) {
    try { URL.revokeObjectURL(photoPreview.value) } catch {}
  }
  photoPreview.value = null
}

function openCreate() {
  resetForm()
  isEdit.value = false
  isOpen.value = true
}

function openEdit(r: MobilRow) {
  resetForm()
  isEdit.value = true
  isOpen.value = true

  form.value.id_master = r.id_master
  form.value.id_transportir = r.id_transportir
  form.value.nomor_plat = r.nomor_plat ?? ''
  form.value.no_proyek = r.no_proyek ?? ''
  form.value.max_kap = Number(r.max_kap ?? 0)
  form.value.komp_tanki = r.komp_tanki ?? ''
  form.value.link_gps = r.link_gps ?? ''
  form.value.user_gps = r.user_gps ?? ''
  form.value.pass_gps = r.pass_gps ?? ''
  form.value.membercode_gps = r.membercode_gps ?? ''
  form.value.is_active = r.is_active ? 1 : 0

  // preview dari server jika ada
  if (r.photo_url) photoPreview.value = r.photo_url
}

function onPickPhoto(files: File[] | File | null) {
  const f = Array.isArray(files) ? files?.[0] : files
  photoFile.value = f ?? null

  if (photoPreview.value?.startsWith('blob:')) {
    try { URL.revokeObjectURL(photoPreview.value) } catch {}
  }
  photoPreview.value = f ? URL.createObjectURL(f) : null
}

watch(isOpen, v => {
  if (!v) {
    if (photoPreview.value?.startsWith('blob:')) {
      try { URL.revokeObjectURL(photoPreview.value) } catch {}
    }
  }
})

// ===== Helpers =====
function transportirName(id: number) {
  return transportirMap.value.get(Number(id)) ?? '-'
}

// ===== Fetch =====
async function fetchTransportirOptions() {
  try {
    const { data } = await axios.get('/master/transportir', { params: { per_page: 999 } })
    const list = data?.data ?? data
    transportirOptions.value = (list || []).map((x: any) => ({
      id: Number(x.id ?? x.id_master ?? x.id_transportir),
      nama_transportir: x.nama_transportir,
    }))
  } catch (e) {
    console.error('fetchTransportirOptions error', e)
    transportirOptions.value = []
  }
}

async function fetchList() {
  loading.value = true
  try {
    const params: any = {
      page: page.value,
      per_page: perPage.value,
    }

    if (search.value) params.search = search.value

    // kirim angka biar konsisten di backend
    if (status.value === 'ACTIVE') params.is_active = 1
    if (status.value === 'INACTIVE') params.is_active = 0

    const { data } = await axios.get('/master/transportir-mobil', { params })

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

watch([page, perPage], () => {
  fetchList()
})

// filter -> reset page
watch([search, status], () => {
  page.value = 1
  fetchList()
})

async function save() {
  if (!form.value.id_transportir) return
  if (!form.value.nomor_plat) return

  isSaving.value = true
  try {
    const fd = new FormData()
    fd.append('id_transportir', String(form.value.id_transportir))
    fd.append('nomor_plat', form.value.nomor_plat)
    fd.append('no_proyek', form.value.no_proyek ?? '')
    fd.append('max_kap', String(form.value.max_kap ?? 0))
    fd.append('komp_tanki', form.value.komp_tanki ?? '')
    fd.append('link_gps', form.value.link_gps ?? '')
    fd.append('user_gps', form.value.user_gps ?? '')
    fd.append('pass_gps', form.value.pass_gps ?? '')
    fd.append('membercode_gps', form.value.membercode_gps ?? '')
    fd.append('is_active', String(form.value.is_active ?? 1))
    if (photoFile.value) fd.append('photo', photoFile.value)

    if (isEdit.value && form.value.id_master) {
      await axios.post(`/master/transportir-mobil/${form.value.id_master}`, fd)
    } else {
      await axios.post('/master/transportir-mobil', fd)
    }

    isOpen.value = false
    await fetchList()
  } catch (e) {
    console.error('save error', e)
  } finally {
    isSaving.value = false
  }
}

async function removeRow(r: MobilRow) {
  if (!confirm(`Hapus mobil ${r.nomor_plat}?`)) return
  try {
    await axios.delete(`/master/transportir-mobil/${r.id_master}`)
    await fetchList()
  } catch (e) {
    console.error('delete error', e)
  }
}

onMounted(async () => {
  await fetchTransportirOptions()
  await fetchList()
})
</script>

<template>
  <div class="d-flex flex-column gap-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between">
      <div>
        <h2 class="text-h5 mb-1">Mobil Transportir</h2>
        <div class="text-caption text-medium-emphasis">
          Master data mobil (plat, GPS, kompartemen, foto).
        </div>
      </div>

      <VBtn color="primary" @click="openCreate">
        + Tambah Mobil
      </VBtn>
    </div>

    <!-- Filters -->
    <VCard>
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="8">
            <VTextField
              v-model="search"
              label="Cari (nomor plat / no proyek)"
              variant="outlined"
              density="compact"
              clearable
            />
          </VCol>

          <VCol cols="12" md="4">
            <VSelect
              v-model="status"
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

    <!-- TABLE (VTable) -->
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
              <th>Nomor Plat</th>
              <th>Transportir</th>
              <th class="text-right">Max Kap</th>
              <th>Status</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>

          <tbody>
            <tr v-if="loading">
              <td colspan="5" class="text-center py-6 text-medium-emphasis">
                Loading...
              </td>
            </tr>

            <tr v-else-if="rows.length === 0">
              <td colspan="5" class="text-center py-6 text-medium-emphasis">
                Tidak ada data.
              </td>
            </tr>

            <tr v-else v-for="r in rows" :key="r.id_master">
              <td>{{ r.nomor_plat }}</td>
              <td>{{ r.transportir_nama || transportirName(r.id_transportir) }}</td>
              <td class="text-right">{{ r.max_kap }}</td>
              <td>
                <VChip size="small" :color="r.is_active ? 'success' : 'secondary'" variant="tonal">
                  {{ r.is_active ? 'Aktif' : 'Nonaktif' }}
                </VChip>
              </td>
              <td class="text-right">
                <div class="d-flex gap-2 justify-end">
                  <VBtn size="small" variant="tonal" @click="openEdit(r)">
                    Edit
                  </VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="removeRow(r)">
                    Hapus
                  </VBtn>
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>

        <div class="d-flex justify-end mt-4">
          <VPagination
            v-model="page"
            :length="pageCount"
            :total-visible="7"
            density="comfortable"
          />
        </div>
      </VCardText>
    </VCard>

    <!-- Modal (tetap) -->
    <VDialog v-model="isOpen" max-width="980" persistent>
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
            <VRow dense>
              <VCol cols="12">
                <VSelect
                  v-model="form.id_transportir"
                  :items="transportirOptions"
                  item-title="nama_transportir"
                  item-value="id"
                  label="Transportir *"
                  placeholder="Pilih transportir"
                  variant="outlined"
                  density="compact"
                  clearable
                />
              </VCol>

              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.nomor_plat"
                  label="Nomor Plat *"
                  variant="outlined"
                  density="compact"
                />
              </VCol>

              <VCol cols="12" md="6">
                <VTextField
                  v-model="form.no_proyek"
                  label="No Proyek"
                  variant="outlined"
                  density="compact"
                />
              </VCol>

              <VCol cols="12" md="6">
                <VTextField
                  v-model.number="form.max_kap"
                  label="Max Kapasitas"
                  type="number"
                  min="0"
                  variant="outlined"
                  density="compact"
                />
              </VCol>

              <VCol cols="12" md="6">
                <VSwitch
                  v-model="form.is_active"
                  :true-value="1"
                  :false-value="0"
                  label="Active"
                  inset
                />
              </VCol>

              <VCol cols="12">
                <VTextarea
                  v-model="form.komp_tanki"
                  label="Kompartemen Tanki"
                  rows="3"
                  auto-grow
                  variant="outlined"
                  density="compact"
                />
              </VCol>

              <VCol cols="12" md="6">
                <VTextField v-model="form.link_gps" label="Link GPS" variant="outlined" density="compact" />
              </VCol>

              <VCol cols="12" md="6">
                <VTextField v-model="form.user_gps" label="User GPS" variant="outlined" density="compact" />
              </VCol>

              <VCol cols="12" md="6">
                <VTextField v-model="form.pass_gps" label="Password GPS" type="password" variant="outlined" density="compact" />
              </VCol>

              <VCol cols="12" md="6">
                <VTextField v-model="form.membercode_gps" label="Member Code GPS" variant="outlined" density="compact" />
              </VCol>

              <VCol cols="12" md="7">
                <VFileInput
                  label="Photo (optional)"
                  prepend-icon="tabler-paperclip"
                  accept="image/*"
                  variant="outlined"
                  density="compact"
                  show-size
                  @update:modelValue="onPickPhoto"
                />
              </VCol>

              <VCol cols="12" md="5">
                <div
                  class="d-flex align-center justify-center rounded-lg pa-2"
                  style="border: 1px dashed rgba(0,0,0,.2); min-height: 110px;"
                >
                  <VImg v-if="photoPreview" :src="photoPreview" max-height="140" contain />
                  <div v-else class="text-caption text-medium-emphasis">
                    Preview foto akan tampil di sini
                  </div>
                </div>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>