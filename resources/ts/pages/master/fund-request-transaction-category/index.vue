<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from '@axios'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { usePermissionStore } from '@/stores/permission'

/*
|--------------------------------------------------------------------------
| Master Keterangan Transaksi (modul Pengajuan Dana)
|--------------------------------------------------------------------------
| Dipakai FPU sebagai salah satu penentu approval flow. Label pada halaman ini
| memakai Bahasa Indonesia, mengikuti halaman master lain yang belum memakai
| i18n -- bukan pola baru.
|--------------------------------------------------------------------------
*/

interface CategoryRow {
  id: number
  code: string

  /** ADVANCE atau CLAIM -- satu baris melayani satu modul saja. */
  document_type: string

  name: string

  /** Kolom "Batasan / Ketentuan" pada matriks Claim. */
  description: string | null

  claimable_status: number | null
  claimable_label: string | null
  required_documents: string | null

  sort_order: number
  requires_business_trip: boolean
  is_active: boolean

  usage_approval_flows: number
  usage_cash_advances: number
  usage_claims: number
  is_deletable: boolean
}

interface Abilities {
  can_view: boolean
  can_create: boolean
  can_update: boolean
  can_delete: boolean
}

const router = useRouter()
const permissionStore = usePermissionStore()

const ENDPOINT = '/fund-request/transaction-categories'

/*
|--------------------------------------------------------------------------
| Pilihan tetap
|--------------------------------------------------------------------------
| Satu master menampung dua daftar yang berbeda. Baris FPU tidak memiliki
| "Dapat Diklaim?" maupun dokumen pendukung wajib, jadi kedua isian itu
| hanya ditawarkan saat modulnya Claim.
|--------------------------------------------------------------------------
*/
const documentTypeItems = [
  { title: 'FPU', value: 'ADVANCE' },
  { title: 'Claim', value: 'CLAIM' },
]

const claimableItems = [
  { title: 'Ya', value: 1 },
  { title: 'Tidak', value: 2 },
  { title: 'Ya, terbatas', value: 3 },
  { title: 'Sesuai kebijakan', value: 4 },
]

/*
 * Nilainya disimpan sebagai angka, jadi pemetaannya ditampilkan di bawah
 * isian -- kolom yang sama dibaca juga lewat API dan tools database, dan di
 * sana yang terlihat hanya angkanya.
 */
const claimableHint = claimableItems
  .map(item => `${item.value} = ${item.title}`)
  .join(' · ')

const documentTypeLabel = (value: string | null | undefined): string =>
  documentTypeItems.find(item => item.value === String(value || '').toUpperCase())?.title
    || String(value || '-')

const isCheckingPermission = ref(true)

const loading = ref(false)
const loadError = ref(false)
const rows = ref<CategoryRow[]>([])

const search = ref('')
const selectedStatus = ref<'all' | 'true' | 'false'>('all')
const selectedDocumentType = ref<'all' | 'ADVANCE' | 'CLAIM'>('all')

const currentPage = ref(1)
const rowPerPage = ref(10)
const totalPage = ref(1)
const totalItems = ref(0)

const summary = reactive({
  total: 0,
  active: 0,
  inactive: 0,
})

const defaultAbilities = (): Abilities => ({
  can_view: false,
  can_create: false,
  can_update: false,
  can_delete: false,
})

const abilities = ref<Abilities>(defaultAbilities())

const canView = computed(() => permissionStore.can('fund_request_transaction_category.view'))
const canCreate = computed(() => permissionStore.can('fund_request_transaction_category.create'))
const canUpdate = computed(() => permissionStore.can('fund_request_transaction_category.update'))
const canDelete = computed(() => permissionStore.can('fund_request_transaction_category.delete'))

const statusOptions = [
  { title: 'Semua Status', value: 'all' },
  { title: 'Aktif', value: 'true' },
  { title: 'Nonaktif', value: 'false' },
]

const paginationData = computed(() => {
  const start = totalItems.value === 0
    ? 0
    : (currentPage.value - 1) * rowPerPage.value + 1

  const end = Math.min(currentPage.value * rowPerPage.value, totalItems.value)

  return `${start}-${end} of ${totalItems.value}`
})

/*
|--------------------------------------------------------------------------
| Dialog form
|--------------------------------------------------------------------------
*/
const dialog = ref(false)
const isSubmitted = ref(false)
const isSaving = ref(false)
const editingId = ref<number | null>(null)

const form = reactive({
  code: '',
  document_type: 'ADVANCE',
  name: '',
  description: '',
  claimable_status: null as number | null,
  required_documents: '',

  /* FPU dengan keterangan transaksi ini wajib menunjuk dokumen Perdin. */
  requires_business_trip: false,

  sort_order: 0,
  is_active: true,
})

/** Kedua isian khas Claim hanya berlaku pada modul Claim. */
const isClaimForm = computed(() => form.document_type === 'CLAIM')

const isEditMode = computed(() => editingId.value !== null)

const dialogTitle = computed(() =>
  isEditMode.value ? 'Ubah Keterangan Transaksi' : 'Tambah Keterangan Transaksi',
)

const resetForm = (): void => {
  editingId.value = null
  isSubmitted.value = false

  form.code = ''

  /*
   * Modul mengikuti penyaring yang sedang aktif supaya menambah data saat
   * sedang melihat daftar Claim tidak perlu memilih ulang modulnya.
   */
  form.document_type = selectedDocumentType.value === 'CLAIM' ? 'CLAIM' : 'ADVANCE'

  form.name = ''
  form.description = ''
  form.claimable_status = null
  form.required_documents = ''

  /*
   * Kelipatan 10 memberi ruang menyisipkan kategori baru di antara yang
   * sudah ada tanpa harus menomori ulang semuanya.
   */
  form.sort_order = (summary.total + 1) * 10
  form.requires_business_trip = false
  form.is_active = true
}

const openCreate = (): void => {
  resetForm()
  dialog.value = true
}

const openEdit = (row: CategoryRow): void => {
  resetForm()

  editingId.value = row.id

  form.code = row.code
  form.document_type = String(row.document_type || 'ADVANCE').toUpperCase()
  form.name = row.name
  form.description = row.description || ''
  form.claimable_status = row.claimable_status ?? null
  form.required_documents = row.required_documents || ''
  form.sort_order = Number(row.sort_order || 0)
  form.requires_business_trip = Boolean(row.requires_business_trip)
  form.is_active = Boolean(row.is_active)

  dialog.value = true
}

const closeDialog = (): void => {
  if (isSaving.value)
    return

  dialog.value = false
}

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/
const fetchData = async (): Promise<void> => {
  loading.value = true
  loadError.value = false

  try {
    const response = await axios.get(ENDPOINT, {
      headers: { Accept: 'application/json' },
      params: {
        page: currentPage.value,
        per_page: rowPerPage.value,
        search: search.value || undefined,
        is_active: selectedStatus.value === 'all' ? undefined : selectedStatus.value,
        document_type: selectedDocumentType.value === 'all' ? undefined : selectedDocumentType.value,
      },
    })

    const payload = response.data

    rows.value = Array.isArray(payload?.data) ? payload.data : []

    abilities.value = { ...defaultAbilities(), ...(payload?.abilities || {}) }

    summary.total = Number(payload?.summary?.total ?? 0)
    summary.active = Number(payload?.summary?.active ?? 0)
    summary.inactive = Number(payload?.summary?.inactive ?? 0)

    totalItems.value = Number(payload?.meta?.total ?? rows.value.length ?? 0)
    totalPage.value = Number(payload?.meta?.last_page ?? 1)
    currentPage.value = Number(payload?.meta?.current_page ?? 1)
  }
  catch (error: unknown) {
    const status = (error as any)?.response?.status

    // 401 sudah ditangani interceptor: user diarahkan ke halaman login.
    if (status === 401) {
      rows.value = []

      return
    }

    loadError.value = true

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(error, 'Gagal memuat master keterangan transaksi.'),
    })

    rows.value = []
    totalItems.value = 0
    totalPage.value = 1
  }
  finally {
    loading.value = false
  }
}

const resetFilter = async (): Promise<void> => {
  search.value = ''
  selectedStatus.value = 'all'
  selectedDocumentType.value = 'all'
  currentPage.value = 1

  await fetchData()
}

/*
|--------------------------------------------------------------------------
| Aksi
|--------------------------------------------------------------------------
*/
const submitForm = async (): Promise<void> => {
  if (isSaving.value)
    return

  isSubmitted.value = true

  if (!form.code.trim() || !form.name.trim()) {
    showErrorToast({
      title: 'Validasi Gagal',
      text: 'Kode dan nama keterangan transaksi wajib diisi.',
    })

    return
  }

  isSaving.value = true

  try {
    const payload = {
      code: form.code.trim(),
      document_type: form.document_type,
      name: form.name.trim(),
      description: form.description.trim() || null,

      /*
       * Dikosongkan untuk FPU. Backend juga membersihkannya, tetapi
       * mengirim yang tidak berlaku hanya membuat payload menyesatkan.
       */
      claimable_status: isClaimForm.value ? form.claimable_status : null,
      required_documents: isClaimForm.value
        ? (form.required_documents.trim() || null)
        : null,

      /* Tidak berlaku pada baris Claim -- Claim tidak mengenal Perdin. */
      requires_business_trip: isClaimForm.value ? false : form.requires_business_trip,

      sort_order: Number(form.sort_order || 0),
      is_active: form.is_active,
    }

    const response = isEditMode.value
      ? await axios.put(`${ENDPOINT}/${editingId.value}`, payload, {
        headers: { Accept: 'application/json' },
      })
      : await axios.post(ENDPOINT, payload, {
        headers: { Accept: 'application/json' },
      })

    dialog.value = false

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || 'Keterangan transaksi berhasil disimpan.',
    })

    await fetchData()
  }
  catch (error: unknown) {
    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(error, 'Keterangan transaksi gagal disimpan.'),
    })
  }
  finally {
    isSaving.value = false
  }
}

const toggleStatus = async (row: CategoryRow): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: row.is_active ? 'Nonaktifkan keterangan transaksi?' : 'Aktifkan keterangan transaksi?',
    text: row.is_active
      ? `"${row.name}" tidak akan muncul lagi pada form ${documentTypeLabel(row.document_type)}. Approval flow dan dokumen lama tetap utuh.`
      : `"${row.name}" akan muncul kembali pada form ${documentTypeLabel(row.document_type)}.`,
    confirmButtonText: 'Ya, lanjutkan',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  try {
    showLoadingAlert('Memproses...', 'Mohon tunggu sebentar')

    const response = await axios.patch(
      `${ENDPOINT}/${row.id}/toggle-status`,
      {},
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || 'Status berhasil diubah.',
    })

    await fetchData()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(error, 'Status gagal diubah.'),
    })
  }
}

const removeRow = async (row: CategoryRow): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: 'Hapus keterangan transaksi?',
    text: `"${row.name}" akan dihapus permanen dari master.`,
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!confirm.isConfirmed)
    return

  try {
    showLoadingAlert('Menghapus data...', 'Mohon tunggu sebentar')

    const response = await axios.delete(`${ENDPOINT}/${row.id}`, {
      headers: { Accept: 'application/json' },
    })

    closeAlert()

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || 'Keterangan transaksi berhasil dihapus.',
    })

    await fetchData()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: 'Gagal',
      text: getApiErrorMessage(error, 'Keterangan transaksi gagal dihapus.'),
    })
  }
}

/**
 * Penjelasan kenapa tombol hapus tidak tersedia.
 */
const usageHint = (row: CategoryRow): string => {
  if (row.is_deletable)
    return 'Hapus'

  const bagian: string[] = []

  if (row.usage_approval_flows > 0)
    bagian.push(`${row.usage_approval_flows} approval flow`)

  if (row.usage_cash_advances > 0)
    bagian.push(`${row.usage_cash_advances} FPU`)

  return `Tidak bisa dihapus, masih dipakai ${bagian.join(' dan ')}. Nonaktifkan saja.`
}

/*
|--------------------------------------------------------------------------
| Watcher
|--------------------------------------------------------------------------
*/
let searchTimeout: ReturnType<typeof setTimeout> | null = null

watch(search, () => {
  if (searchTimeout)
    clearTimeout(searchTimeout)

  searchTimeout = setTimeout(async () => {
    currentPage.value = 1
    await fetchData()
  }, 500)
})

watch(selectedStatus, async () => {
  currentPage.value = 1
  await fetchData()
})

watch(selectedDocumentType, async () => {
  currentPage.value = 1
  await fetchData()
})

watch(currentPage, async () => {
  await fetchData()
})

watch(rowPerPage, async () => {
  currentPage.value = 1
  await fetchData()
})

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canView.value) {
    await router.replace('/forbidden')

    return
  }

  isCheckingPermission.value = false

  await fetchData()
})
</script>

<template>
  <div
    v-if="isCheckingPermission"
    class="d-flex justify-center align-center"
    style="min-height: 300px;"
  >
    <VProgressCircular indeterminate />
  </div>

  <section v-else>
    <!-- RINGKASAN -->
    <VRow class="mb-6">
      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-4">
            <VAvatar
              size="44"
              color="primary"
              variant="tonal"
              rounded
            >
              <VIcon
                icon="tabler-list-details"
                size="24"
              />
            </VAvatar>

            <div>
              <div class="text-h5 font-weight-bold">
                {{ summary.total }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                Total Keterangan Transaksi
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-4">
            <VAvatar
              size="44"
              color="success"
              variant="tonal"
              rounded
            >
              <VIcon
                icon="tabler-circle-check"
                size="24"
              />
            </VAvatar>

            <div>
              <div class="text-h5 font-weight-bold">
                {{ summary.active }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                Aktif
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-4">
            <VAvatar
              size="44"
              color="secondary"
              variant="tonal"
              rounded
            >
              <VIcon
                icon="tabler-circle-off"
                size="24"
              />
            </VAvatar>

            <div>
              <div class="text-h5 font-weight-bold">
                {{ summary.inactive }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                Nonaktif
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- TABEL -->
    <VCard>
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-list-details" />
          </VAvatar>
        </template>

        <VCardTitle>Master Keterangan Transaksi</VCardTitle>
        <VCardSubtitle>
          Dipakai pada form FPU dan Claim. Untuk FPU, ikut menentukan approval flow yang berlaku.
        </VCardSubtitle>
      </VCardItem>

      <VDivider />

      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="4"
          >
            <VTextField
              v-model="search"
              label="Cari"
              placeholder="Kode, nama, keterangan, dokumen wajib"
              density="compact"
              prepend-inner-icon="tabler-search"
              clearable
              hide-details
            />
          </VCol>

          <!--
            Satu master menampung dua daftar, jadi penyaring modul mendahului
            penyaring status: itu pemisah yang paling menentukan isi tabelnya.
          -->
          <VCol
            cols="12"
            md="2"
          >
            <VSelect
              v-model="selectedDocumentType"
              label="Modul"
              :items="[{ title: 'Semua', value: 'all' }, ...documentTypeItems]"
              item-title="title"
              item-value="value"
              density="compact"
              hide-details
            />
          </VCol>

          <VCol
            cols="12"
            md="2"
          >
            <VSelect
              v-model="selectedStatus"
              label="Status"
              :items="statusOptions"
              item-title="title"
              item-value="value"
              density="compact"
              hide-details
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
            class="d-flex align-center gap-2"
          >
            <VBtn
              color="secondary"
              variant="tonal"
              prepend-icon="tabler-refresh"
              class="text-none"
              :disabled="loading"
              @click="resetFilter"
            >
              Reset
            </VBtn>

            <VBtn
              v-if="canCreate"
              color="primary"
              prepend-icon="tabler-plus"
              class="text-none"
              @click="openCreate"
            >
              Tambah
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>

      <VDivider />

      <!--
        Dua kolom di sini berisi kalimat, bukan label pendek, jadi tabelnya
        tidak boleh dipaksa satu baris. Pembungkusnya tetap diberi gulir
        mendatar sebagai jaring pengaman untuk layar sempit.
      -->
      <div class="ftc-table-wrapper">
        <VTable class="ftc-table">
          <thead>
            <tr>
              <th scope="col">
                No
              </th>
              <th
                scope="col"
                class="text-center"
              >
                Modul
              </th>
              <th
                scope="col"
                class="ftc-name-col"
              >
                Nama
              </th>
              <th
                scope="col"
                class="text-center"
              >
                Dapat Diklaim?
              </th>
              <th
                scope="col"
                class="ftc-desc-col"
              >
                Batasan / Ketentuan
              </th>
              <th
                scope="col"
                class="ftc-desc-col"
              >
                Dokumen Pendukung Wajib
              </th>
              <th
                scope="col"
                class="text-center"
              >
                Urutan
              </th>
              <th
                scope="col"
                class="text-center"
              >
                Status
              </th>
              <th
                scope="col"
                class="text-center"
                style="inline-size: 8rem;"
              >
                Aksi
              </th>
            </tr>
          </thead>

          <tbody>
            <tr
              v-for="(row, index) in rows"
              :key="row.id"
            >
              <td class="text-medium-emphasis">
                {{ ((currentPage - 1) * rowPerPage) + index + 1 }}
              </td>

              <td class="text-center">
                <VChip
                  size="x-small"
                  variant="tonal"
                  :color="row.document_type === 'CLAIM' ? 'warning' : 'info'"
                >
                  {{ documentTypeLabel(row.document_type) }}
                </VChip>
              </td>

              <td class="ftc-name-col font-weight-medium">
                {{ row.name }}
              </td>

              <td class="text-center">
                <span
                  v-if="!row.claimable_label"
                  class="text-medium-emphasis"
                >—</span>

                <VChip
                  v-else
                  size="x-small"
                  variant="tonal"
                  :color="row.claimable_status === 2 ? 'error' : 'success'"
                >
                  {{ row.claimable_label }}
                </VChip>
              </td>

              <td class="ftc-desc-col text-medium-emphasis">
                {{ row.description || '-' }}
              </td>

              <td class="ftc-desc-col text-medium-emphasis">
                {{ row.required_documents || '-' }}
              </td>

              <td class="text-center text-medium-emphasis">
                {{ row.sort_order }}
              </td>

              <td class="text-center">
                <VChip
                  size="small"
                  variant="tonal"
                  :color="row.is_active ? 'success' : 'secondary'"
                >
                  {{ row.is_active ? 'Aktif' : 'Nonaktif' }}
                </VChip>
              </td>

              <td class="text-center">
                <VBtn
                  v-if="canUpdate"
                  icon
                  size="small"
                  variant="text"
                  color="primary"
                  @click="openEdit(row)"
                >
                  <VIcon icon="mdi-pencil-outline" />
                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    Ubah
                  </VTooltip>
                </VBtn>

                <VBtn
                  v-if="canUpdate"
                  icon
                  size="small"
                  variant="text"
                  :color="row.is_active ? 'warning' : 'success'"
                  @click="toggleStatus(row)"
                >
                  <VIcon :icon="row.is_active ? 'tabler-circle-off' : 'tabler-circle-check'" />
                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    {{ row.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                  </VTooltip>
                </VBtn>

                <VBtn
                  v-if="canDelete"
                  icon
                  size="small"
                  variant="text"
                  color="error"
                  :disabled="!row.is_deletable"
                  @click="removeRow(row)"
                >
                  <VIcon icon="tabler-trash" />
                  <VTooltip
                    activator="parent"
                    location="top"
                    max-width="280"
                  >
                    {{ usageHint(row) }}
                  </VTooltip>
                </VBtn>
              </td>
            </tr>

            <tr v-if="!loading && !rows.length">
              <td
                colspan="9"
                class="text-center py-8"
              >
                <div class="text-body-1 font-weight-medium">
                  Belum ada keterangan transaksi
                </div>
                <div class="text-caption text-medium-emphasis">
                  Tambahkan keterangan transaksi agar bisa dipilih pada form FPU.
                </div>
              </td>
            </tr>

            <tr v-if="loading">
              <td
                colspan="9"
                class="text-center py-8"
              >
                <VProgressCircular indeterminate />
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>

      <VDivider />

      <VCardText class="d-flex align-center flex-wrap justify-space-between gap-4 py-3">
        <div class="d-flex align-center gap-3">
          <span class="text-body-2">{{ paginationData }}</span>

          <VSelect
            v-model="rowPerPage"
            :items="[10, 25, 50, 100]"
            density="compact"
            variant="outlined"
            hide-details
            style="inline-size: 6rem;"
          />
        </div>

        <VPagination
          v-model="currentPage"
          :length="totalPage"
          :total-visible="5"
        />
      </VCardText>
    </VCard>

    <!-- DIALOG FORM -->
    <VDialog
      v-model="dialog"
      max-width="620"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between">
          <span class="text-h6 font-weight-bold">{{ dialogTitle }}</span>

          <VBtn
            icon
            variant="text"
            size="small"
            :disabled="isSaving"
            @click="closeDialog"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>

        <VDivider />

        <VCardText>
          <VRow>
            <!--
              Modul dipilih lebih dulu karena menentukan isian mana yang berlaku
              di bawahnya. Tidak dikunci saat menyunting: memindahkan satu
              keterangan ke modul lain adalah koreksi yang wajar.
            -->
            <VCol
              cols="12"
              md="4"
            >
              <VSelect
                v-model="form.document_type"
                label="Modul *"
                :items="documentTypeItems"
                item-title="title"
                item-value="value"
                density="comfortable"
                :disabled="isSaving"
                hint="Menentukan form mana yang menawarkan keterangan ini."
                persistent-hint
              />
            </VCol>

            <VCol
              cols="12"
              md="3"
            >
              <VTextField
                v-model="form.code"
                label="Kode *"
                placeholder="Contoh: PERDIN"
                density="comfortable"
                :disabled="isSaving"
                hint="Huruf, angka, dan garis bawah. Disimpan sebagai huruf besar."
                persistent-hint
                :error="isSubmitted && !form.code.trim()"
                :error-messages="isSubmitted && !form.code.trim() ? ['Kode wajib diisi'] : []"
              />
            </VCol>

            <VCol
              cols="12"
              md="5"
            >
              <VTextField
                v-model="form.name"
                label="Nama *"
                placeholder="Contoh: Perdin (Exclude Tiket & Hotel)"
                density="comfortable"
                :disabled="isSaving"
                :error="isSubmitted && !form.name.trim()"
                :error-messages="isSubmitted && !form.name.trim() ? ['Nama wajib diisi'] : []"
              />
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.description"
                :label="isClaimForm ? 'Batasan / Ketentuan' : 'Keterangan'"
                :placeholder="isClaimForm
                  ? 'Contoh: Terkait perjalanan dinas/operasional perusahaan dan didukung bukti'
                  : 'Penjelasan tambahan bila perlu'"
                rows="2"
                auto-grow
                :disabled="isSaving"
              />
            </VCol>

            <!--
              Dua isian berikut hanya berlaku pada Claim. Disembunyikan, bukan
              sekadar dinonaktifkan, supaya form FPU tidak menyisakan kolom
              yang tidak pernah bisa diisi.
            -->
            <VCol
              v-if="isClaimForm"
              cols="12"
              md="4"
            >
              <VSelect
                v-model="form.claimable_status"
                label="Dapat Diklaim?"
                :items="claimableItems"
                item-title="title"
                item-value="value"
                density="comfortable"
                clearable
                :disabled="isSaving"
                :hint="claimableHint"
                persistent-hint
              />
            </VCol>

            <VCol
              v-if="isClaimForm"
              cols="12"
              md="8"
            >
              <VTextarea
                v-model="form.required_documents"
                label="Dokumen Pendukung Wajib"
                placeholder="Contoh: Struk parkir / mutasi e-toll, tanggal, tujuan perjalanan"
                rows="2"
                auto-grow
                :disabled="isSaving"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <VTextField
                v-model.number="form.sort_order"
                label="Urutan Tampil"
                type="number"
                min="0"
                density="comfortable"
                :disabled="isSaving"
                hint="Angka lebih kecil tampil lebih dulu."
                persistent-hint
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
              class="d-flex align-center"
            >
              <VSwitch
                v-model="form.is_active"
                color="success"
                label="Aktif"
                hide-details
                :disabled="isSaving"
              />
            </VCol>

            <!--
              Penanda perjalanan dinas. Hanya untuk baris FPU: Claim tidak
              mengenal Perdin, dan menampilkannya di sana hanya akan
              mengundang isian yang lalu diabaikan server.
            -->
            <VCol
              v-if="!isClaimForm"
              cols="12"
            >
              <VSwitch
                v-model="form.requires_business_trip"
                color="primary"
                label="Wajib menautkan dokumen Perdin"
                hide-details
                :disabled="isSaving"
              />

              <div class="text-caption text-medium-emphasis mt-1">
                FPU dengan keterangan transaksi ini wajib menunjuk Perjalanan Dinas
                yang sudah disetujui milik pemohonnya sendiri.
              </div>
            </VCol>
          </VRow>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            :disabled="isSaving"
            @click="closeDialog"
          >
            Batal
          </VBtn>

          <VBtn
            color="primary"
            class="text-none"
            :loading="isSaving"
            @click="submitForm"
          >
            Simpan
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>
/*
|--------------------------------------------------------------------------
| Tabel
|--------------------------------------------------------------------------
| Kolomnya bertambah sejak satu master menampung FPU dan Claim, dan dua di
| antaranya berisi kalimat. Lebar kolom teks dibatasi supaya isinya membungkus
| ke bawah, bukan mendorong tabel melebar ke samping.
|--------------------------------------------------------------------------
*/
.ftc-table-wrapper {
  overflow-x: auto;
  max-inline-size: 100%;
}

/* Kolom pendek tetap satu baris; hanya kolom teks yang boleh membungkus. */
.ftc-table :deep(th),
.ftc-table :deep(td) {
  white-space: nowrap;
}

.ftc-desc-col {
  inline-size: 20rem;
  min-inline-size: 14rem;
  max-inline-size: 22rem;
}

.ftc-table :deep(.ftc-desc-col) {
  white-space: normal;
}

/* Nama transaksi juga berupa frasa panjang, jadi ikut dibungkus. */
.ftc-name-col {
  min-inline-size: 12rem;
  max-inline-size: 16rem;
}

.ftc-table :deep(.ftc-name-col) {
  white-space: normal;
}
</style>
