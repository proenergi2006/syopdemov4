<script setup lang="ts">
import axios from '@axios';
import { computed, onMounted, ref, watch } from 'vue';

type CabangOption = { id: number; nama: string; kode?: string }
type TransportirRow = {
  id: number
  nama_transportir: string
  nama_suplier: string | null
  lokasi_suplier: string | null
  alamat_suplier: string | null
  att_suplier: string | null
  telp_suplier: string | null
  fax_suplier: string | null
  is_fleet: number | null
  terms_suplier: string | null
  catatan: string | null
  is_active: boolean | number | null
  tipe_angkutan: string | null
  owner_suplier: number | null
}

type PageResp<T> = {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

// ====== list state
const rows = ref<TransportirRow[]>([])
const loading = ref(false)
const saving = ref(false)

const search = ref('')
const perPage = ref(10)
const page = ref(1)

// ====== options
const cabangOptions = ref<CabangOption[]>([])
const loadingCabang = ref(false)

const kepemilikanItems = [
  { title: 'Milik Sendiri', value: 1 },
  { title: 'Third Party', value: 2 },
]

const angkutanItems = [
  { title: 'Truck', value: 'Truck' },
  { title: 'Kapal', value: 'Kapal' },
  { title: 'Keduanya', value: 'Keduanya' },
]

// ====== modal state
const dialog = ref(false)
const isEdit = ref(false)
const editingId = ref<number | null>(null)

const emptyForm = (): TransportirRow => ({
  id: 0,
  nama_transportir: '',
  nama_suplier: '',
  lokasi_suplier: '',      // VARCHAR di DB, tapi inputnya select dari cabang
  alamat_suplier: '',
  att_suplier: '',
  telp_suplier: '',
  fax_suplier: '',
  is_fleet: 0,
  terms_suplier: '',
  catatan: '',
  is_active: 1,
  tipe_angkutan: 'Truck',
  owner_suplier: 1,
})

const form = ref<TransportirRow>(emptyForm())

// ====== snackbar
const snackbar = ref(false)
const snackText = ref('')
const snackColor = ref<'success' | 'error' | 'warning' | 'info'>('success')
const notify = (text: string, color: 'success' | 'error' | 'warning' | 'info' = 'success') => {
  snackText.value = text
  snackColor.value = color
  snackbar.value = true
}

// ====== helpers
const toInt01 = (v: any) => (v === true || v === 1 || v === '1') ? 1 : 0
const normalizeRowForForm = (r: TransportirRow) => {
  form.value = {
    ...emptyForm(),
    ...r,
    // pastikan number/boolean konsisten buat checkbox
    is_active: toInt01(r.is_active),
    is_fleet: r.is_fleet ? Number(r.is_fleet) : 0,
    owner_suplier: r.owner_suplier ? Number(r.owner_suplier) : 1,
    tipe_angkutan: r.tipe_angkutan ?? 'Truck',
    lokasi_suplier: r.lokasi_suplier ?? '',
    nama_suplier: r.nama_suplier ?? '',
    alamat_suplier: r.alamat_suplier ?? '',
    att_suplier: r.att_suplier ?? '',
    telp_suplier: r.telp_suplier ?? '',
    fax_suplier: r.fax_suplier ?? '',
    terms_suplier: r.terms_suplier ?? '',
    catatan: r.catatan ?? '',
  }
}

// ====== API calls
const fetchCabangOptions = async () => {
  loadingCabang.value = true
  try {
    const { data } = await axios.get('/master/cabang/options')
    cabangOptions.value = Array.isArray(data) ? data : (data?.data ?? [])
  } catch (e: any) {
    console.error('fetchCabangOptions error:', e?.response?.status, e?.response?.data || e)
    notify('Gagal load cabang options', 'error')
    cabangOptions.value = []
  } finally {
    loadingCabang.value = false
  }
}

const fetchList = async () => {
  loading.value = true
  try {
    const { data } = await axios.get<PageResp<TransportirRow>>('/master/transportir', {
      params: {
        search: search.value || undefined,
        per_page: perPage.value,
        page: page.value,
      },
    })

    rows.value = data.data || []
  } catch (e: any) {
    console.error('fetchList error:', e?.response?.status, e?.response?.data || e)
    notify('Gagal load data transportir', 'error')
    rows.value = []
  } finally {
    loading.value = false
  }
}

const openCreate = () => {
  isEdit.value = false
  editingId.value = null
  form.value = emptyForm()
  dialog.value = true
}

const openEdit = async (row: TransportirRow) => {
  isEdit.value = true
  editingId.value = row.id
  dialog.value = true

  // lebih aman: fetch detail by id biar lengkap (kalau list tidak return semua field)
  try {
    const { data } = await axios.get<TransportirRow>(`/master/transportir/${row.id}`)
    normalizeRowForForm(data)
  } catch (e: any) {
    console.warn('fetch detail failed, fallback row:', e?.response?.status, e?.response?.data || e)
    normalizeRowForForm(row)
  }
}

const save = async () => {
  // validasi minimal
  if (!form.value.nama_transportir?.trim()) {
    notify('Nama transportir wajib diisi', 'warning')
    return
  }

  saving.value = true
  try {
    const payload = {
      nama_transportir: form.value.nama_transportir,
      nama_suplier: form.value.nama_suplier || null,
      lokasi_suplier: form.value.lokasi_suplier || null,  // VARCHAR
      alamat_suplier: form.value.alamat_suplier || null,
      att_suplier: form.value.att_suplier || null,
      telp_suplier: form.value.telp_suplier || null,
      fax_suplier: form.value.fax_suplier || null,
      is_fleet: Number(form.value.is_fleet) ? 1 : 0,
      terms_suplier: form.value.terms_suplier || null,
      catatan: form.value.catatan || null,
      is_active: Number(form.value.is_active) ? 1 : 0,
      tipe_angkutan: form.value.tipe_angkutan || null,
      owner_suplier: form.value.owner_suplier ? Number(form.value.owner_suplier) : null,
    }

    if (isEdit.value && editingId.value) {
      await axios.put(`/master/transportir/${editingId.value}`, payload)
      notify('Berhasil update transportir', 'success')
    } else {
      await axios.post('/master/transportir', payload)
      notify('Berhasil tambah transportir', 'success')
    }

    dialog.value = false
    await fetchList()
  } catch (e: any) {
    console.error('save error:', e?.response?.status, e?.response?.data || e)
    notify(e?.response?.data?.message || 'Gagal simpan', 'error')
  } finally {
    saving.value = false
  }
}

const removeRow = async (row: TransportirRow) => {
  const ok = confirm(`Hapus transportir "${row.nama_transportir}"?`)
  if (!ok) return

  try {
    await axios.delete(`/master/transportir/${row.id}`)
    notify('Deleted', 'success')
    await fetchList()
  } catch (e: any) {
    console.error('delete error:', e?.response?.status, e?.response?.data || e)
    notify('Gagal delete', 'error')
  }
}

// ====== computed
const cabangItems = computed(() =>
  cabangOptions.value.map(c => ({
    title: `${c.nama}${c.kode ? ` (${c.kode})` : ''}`,
    value: c.nama, // ✅ simpan nama cabang ke lokasi_suplier (VARCHAR)
  })),
)

// ====== init
onMounted(async () => {
  await Promise.all([fetchCabangOptions(), fetchList()])
})

watch([perPage], async () => {
  page.value = 1
  await fetchList()
})
</script>

<template>
  <section>
    <VCard>
      <VCardText>
        <div class="d-flex align-center justify-space-between flex-wrap gap-3">
          <div>
            <h3 class="text-h6 mb-1">Master Transportir</h3>
            <div class="text-body-2 opacity-70">
              Data transportir & supplier.
            </div>
          </div>

          <div class="d-flex gap-2">
            <VTextField
              v-model="search"
              label="Search"
              density="compact"
              hide-details
              style="min-width: 260px"
              @keyup.enter="page = 1; fetchList()"
            />
            <VBtn color="primary" @click="openCreate">
              Tambah
            </VBtn>
          </div>
        </div>
      </VCardText>

      <VDivider />

      <VCardText>
        <VAlert v-if="loading" type="info" variant="tonal">Loading...</VAlert>

        <VTable v-else class="text-no-wrap">
          <thead>
            <tr>
              <th style="width: 70px;">#</th>
              <th>Nama Transportir</th>
              <th>Nama Supplier</th>
              <th>Lokasi</th>
              <th>Angkutan</th>
              <th>Fleet</th>
              <th>Active</th>
              <th style="width: 140px;">Aksi</th>
            </tr>
          </thead>

          <tbody>
            <tr v-if="!rows.length">
              <td colspan="8" class="text-center py-6 opacity-70">
                Tidak ada data.
              </td>
            </tr>

            <tr v-for="(r, idx) in rows" :key="r.id">
              <td>{{ idx + 1 + ((page - 1) * perPage) }}</td>
              <td>{{ r.nama_transportir }}</td>
              <td>{{ r.nama_suplier || '-' }}</td>
              <td>{{ r.lokasi_suplier || '-' }}</td>
              <td>{{ r.tipe_angkutan || '-' }}</td>
              <td>
                <VChip size="small" :color="Number(r.is_fleet) ? 'primary' : 'default'">
                  {{ Number(r.is_fleet) ? 'Ya' : 'Tidak' }}
                </VChip>
              </td>
              <td>
                <VChip size="small" :color="toInt01(r.is_active) ? 'success' : 'default'">
                  {{ toInt01(r.is_active) ? 'Active' : 'Non Active' }}
                </VChip>
              </td>
              <td>
                <div class="d-flex gap-2">
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

        <div class="d-flex align-center justify-space-between mt-4 flex-wrap gap-3">
          <VSelect
            v-model="perPage"
            label="Per page"
            density="compact"
            hide-details
            style="max-width: 160px"
            :items="[10, 25, 50, 100]"
          />
          <VPagination
            v-model="page"
            :length="Math.max(1, Math.ceil((rows.length ? 1 : 1)))"
            class="ms-auto"
            @update:modelValue="fetchList"
          />
        </div>
      </VCardText>
    </VCard>

    <!-- MODAL -->
    <VDialog v-model="dialog" max-width="1100">
      <VCard>
        <VCardText>
          <div class="d-flex align-center justify-space-between flex-wrap gap-3">
            <div>
              <h3 class="text-h6 mb-1">{{ isEdit ? 'Edit Transportir' : 'Tambah Transportir' }}</h3>
              <div class="text-body-2 opacity-70">Form di modal.</div>
            </div>

            <div class="d-flex gap-2">
              <VBtn variant="tonal" @click="dialog = false">Tutup</VBtn>
              <VBtn color="primary" :loading="saving" @click="save">Simpan</VBtn>
            </div>
          </div>
        </VCardText>

        <VDivider />

        <VCardText>
          <VRow>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.nama_transportir"
                label="Nama Transportir *"
                required
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.nama_suplier"
                label="Nama Supplier"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VSelect
                v-model="form.owner_suplier"
                :items="kepemilikanItems"
                label="Kepemilikan"
                item-title="title"
                item-value="value"
                clearable
              />
            </VCol>

            <VCol cols="12" md="6">
              <VSelect
                v-model="form.lokasi_suplier"
                :items="cabangItems"
                label="Lokasi"
                :loading="loadingCabang"
                clearable
                hint="DB tetap lokasi_suplier (VARCHAR)"
                persistent-hint
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.alamat_suplier"
                label="Alamat"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.att_suplier"
                label="ATT"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.telp_suplier"
                label="Telepon"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.fax_suplier"
                label="Fax"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VSelect
                v-model="form.tipe_angkutan"
                :items="angkutanItems"
                label="Angkutan Kirim"
                item-title="title"
                item-value="value"
                clearable
              />
            </VCol>

            <VCol cols="12" md="6" class="d-flex align-center gap-6">
              <VCheckbox
                v-model="form.is_fleet"
                :true-value="1"
                :false-value="0"
                label="Fleet"
              />
              <VCheckbox
                v-model="form.is_active"
                :true-value="1"
                :false-value="0"
                label="Active"
              />
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.catatan"
                label="Catatan"
                rows="4"
              />
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.terms_suplier"
                label="Terms Supplier"
                rows="3"
              />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snackbar" :color="snackColor" location="top end" :timeout="2500">
      {{ snackText }}
      <template #actions>
        <VBtn variant="text" @click="snackbar = false">Close</VBtn>
      </template>
    </VSnackbar>
  </section>
</template>
