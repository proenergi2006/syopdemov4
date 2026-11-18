<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import {
  showConfirmAlert,
  showErrorToast,
  showSuccessToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { usePermissionStore } from '@/stores/permission'
import PettyCashBalancePanel from '@/components/petty-cash/PettyCashBalancePanel.vue'
import { LOCALE_ANGKA } from '@/utils/numberFormatter'

/*
|--------------------------------------------------------------------------
| Petty Cash
|--------------------------------------------------------------------------
| Formulir pemegang kas kecil untuk membayar beberapa claim kecil sekaligus.
|
| Isinya bukan rincian yang diketik, melainkan claim yang dipilih dari daftar
| -- jadi formulirnya pada dasarnya satu tabel pilihan, bukan satu tabel
| isian.
|
| Tombol-tombolnya TIDAK diputuskan di layar ini. Setiap baris membawa objek
| `can` dari server, dan layar hanya menampilkan apa yang sudah diizinkan di
| sana. Layar yang memutuskan sendiri akan selalu menjawab berbeda dari server
| pada keadaan pinggir, dan yang terlihat pengguna adalah tombol yang ada
| tetapi menolak saat ditekan.
|--------------------------------------------------------------------------
*/

interface Kemampuan {
  update: boolean
  delete: boolean
  submit: boolean
  disburse: boolean
  cancel: boolean
  approve: boolean
}

interface PettyCashRow {
  public_id: string
  petty_cash_number: string
  date: string | null
  subject: string
  branch: string | null
  branch_id: number
  department: string | null
  total_amount: number
  claim_count: number
  status: string
  requester: string | null
  disbursed_at: string | null
  can: Kemampuan
}

interface EligibleClaim {
  id: number
  claim_number: string
  date: string | null
  subject: string
  department: string | null
  requester: string | null
  amount: number
}

interface ClaimDiFormulir extends EligibleClaim {
  claim_id: number
  claim_amount_now: number
  status: string
}

interface BarisApproval {
  step_order: number
  label: string | null
  approver_name: string | null
  approval_mode: string
  status: string
  approved_at: string | null
  rejected_at: string | null
  notes: string | null
}

const { t } = useI18n()
const permissionStore = usePermissionStore()

const ENDPOINT = '/fund-request/petty-cash'

const rows = ref<PettyCashRow[]>([])
const loading = ref(false)
const holderBranchIds = ref<number[]>([])

/*
 * Nama cabang diambil dari master, bukan dari baris daftar: formulir bisa
 * dibuka sebelum ada satu pun formulir di cabang itu, dan pilihan yang
 * berisi angka id bukan pilihan yang bisa dibaca siapa pun.
 */
const branchNames = ref<Record<number, string>>({})

const filter = reactive({
  search: '',
  status: null as string | null,
  branch: null as number | null,
  dateFrom: '' as string,
  dateTo: '' as string,
})

/*
| Cabang yang plafonnya sudah hidup, dikirim server bersama daftarnya.
|
| Dipakai menjelaskan kenapa tombol "Buat" tidak ada. Tanpa ini layarnya
| hanya bisa bilang "Anda bukan pemegang", yang benar tapi tidak menolong --
| yang dicari orang berikutnya selalu "lalu cabang mana yang sudah bisa".
*/
const ceilingBranches = ref<string[]>([])

/*
| Halaman dipisahkan dari saringan: mengubah saringan harus mengembalikan
| daftar ke halaman pertama, sedangkan berpindah halaman tidak boleh
| menyentuh saringannya.
*/
const currentPage = ref(1)
const rowPerPage = ref(25)
const totalData = ref(0)
const totalPage = ref(1)

const paginationData = computed(() => {
  const pertama = totalData.value === 0
    ? 0
    : ((currentPage.value - 1) * rowPerPage.value) + 1

  const terakhir = Math.min(currentPage.value * rowPerPage.value, totalData.value)

  return `${pertama}-${terakhir} of ${totalData.value}`
})

const canView = computed(() => permissionStore.can('petty_cash.view'))

const punyaIzinBuat = computed(() => permissionStore.can('petty_cash.create'))

const canCreate = computed(
  () => punyaIzinBuat.value && holderBranchIds.value.length > 0,
)

/*
| Dua sebab yang berbeda, dan jalan keluarnya pun berbeda: yang satu diurus
| di Role Permission, yang satu di master plafon. Digabung jadi satu pesan
| akan mengirim orang ke tempat yang salah.
*/
const sebabTidakBisaBuat = computed<'izin' | 'plafon' | null>(() => {
  if (canCreate.value)
    return null

  return punyaIzinBuat.value ? 'plafon' : 'izin'
})

const STATUS_LIST = ['DRAFT', 'IN PROGRESS', 'APPROVED', 'DISBURSED', 'REJECTED', 'CANCELLED']

const statusOptions = computed(() =>
  STATUS_LIST.map(s => ({ value: s, title: t(`pettyCash.status.${s}`) })),
)

const rupiah = (nilai: number | null | undefined): string =>
  new Intl.NumberFormat(LOCALE_ANGKA, { maximumFractionDigits: 0 }).format(Number(nilai ?? 0))

const tanggal = (nilai: string | null): string => {
  if (!nilai)
    return '-'

  return new Date(nilai).toLocaleDateString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

const warnaStatus = (status: string): string => {
  const peta: Record<string, string> = {
    'DRAFT': 'secondary',
    'IN PROGRESS': 'warning',
    'APPROVED': 'info',
    'DISBURSED': 'success',
    'REJECTED': 'error',
    'CANCELLED': 'secondary',
  }

  return peta[status] ?? 'secondary'
}

const labelStatus = (status: string): string => {
  const kunci = `pettyCash.status.${status}`
  const hasil = t(kunci)

  return hasil === kunci ? status : hasil
}

const fetchRows = async (): Promise<void> => {
  loading.value = true

  try {
    const response = await axios.get(ENDPOINT, {
      headers: { Accept: 'application/json' },
      params: {
        search: filter.search || undefined,
        status: filter.status || undefined,
        branch: filter.branch || undefined,
        date_from: filter.dateFrom || undefined,
        date_to: filter.dateTo || undefined,
        page: currentPage.value,
        per_page: rowPerPage.value,
      },
    })

    const halaman = response.data?.data ?? {}

    rows.value = halaman.data ?? []
    totalData.value = Number(halaman.total ?? 0)
    totalPage.value = Number(halaman.last_page ?? 1)

    holderBranchIds.value = response.data?.meta?.holder_branch_ids ?? []
    ceilingBranches.value = response.data?.meta?.ceiling_branches ?? []
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.list.loadFailed')),
    })
  }
  finally {
    loading.value = false
  }
}

/*
| Setiap perubahan saringan mengembalikan daftar ke halaman pertama. Tanpa
| ini, menyaring sewaktu berada di halaman 3 bisa menghasilkan daftar kosong
| yang terbaca seperti "tidak ada datanya", padahal datanya ada di halaman 1.
*/
const terapkanFilter = async (): Promise<void> => {
  currentPage.value = 1
  await fetchRows()
}

const resetFilters = async (): Promise<void> => {
  filter.search = ''
  filter.status = null
  filter.branch = null
  filter.dateFrom = ''
  filter.dateTo = ''

  await terapkanFilter()
}

const fetchBranchNames = async (): Promise<void> => {
  try {
    const response = await axios.get('/master/cabang/dropdown-select', {
      headers: { Accept: 'application/json' },
    })

    const peta: Record<number, string> = {}

    for (const x of (response.data?.data ?? []) as Record<string, unknown>[])
      peta[Number(x.id)] = String(x.nama_cabang ?? x.nama ?? x.title ?? x.id)

    branchNames.value = peta
  }
  catch {
    branchNames.value = {}
  }
}

/*
| Saringan cabang memakai seluruh cabang yang dikenal, bukan hanya cabang
| pegangan sendiri: orang yang melihat daftar lintas cabang (HO) perlu bisa
| mempersempitnya, dan yang hanya melihat satu cabang tidak dirugikan oleh
| pilihan yang hasilnya kosong.
*/
const branchFilterOptions = computed(() =>
  Object.entries(branchNames.value)
    .map(([id, nama]) => ({ value: Number(id), title: nama }))
    .sort((a, b) => a.title.localeCompare(b.title)),
)

const holderBranchOptions = computed(() =>
  holderBranchIds.value.map(id => ({
    value: id,
    title: branchNames.value[id] ?? String(id),
  })),
)

/*
|--------------------------------------------------------------------------
| Formulir
|--------------------------------------------------------------------------
*/
const dialog = ref(false)
const saving = ref(false)
const editingId = ref<string | null>(null)

const eligible = ref<EligibleClaim[]>([])
const eligibleLoading = ref(false)

const kasKecil = reactive({
  balance: 0,
  ceiling_amount: 0,
  claim_threshold: 0,
})

const form = reactive({
  branch: null as number | null,
  date: new Date().toISOString().slice(0, 10),
  subject: '',
  notes: '',
  claim_ids: [] as number[],
})

const totalDipilih = computed(() =>
  eligible.value
    .filter(c => form.claim_ids.includes(c.id))
    .reduce((jumlah, c) => jumlah + Number(c.amount), 0),
)

const saldoSesudah = computed(() => kasKecil.balance - totalDipilih.value)

const melebihiSaldo = computed(() => totalDipilih.value > kasKecil.balance)

const formValid = computed(
  () =>
    !!form.branch
    && !!form.date
    && form.subject.trim().length > 0
    && form.claim_ids.length > 0
    && !melebihiSaldo.value,
)

const loadEligible = async (): Promise<void> => {
  if (!form.branch) {
    eligible.value = []

    return
  }

  eligibleLoading.value = true

  try {
    const response = await axios.get(`${ENDPOINT}/eligible-claims`, {
      headers: { Accept: 'application/json' },
      params: {
        branch: form.branch,
        petty_cash_id: editingId.value || undefined,
      },
    })

    eligible.value = response.data?.data ?? []

    const meta = response.data?.meta ?? {}

    kasKecil.balance = Number(meta.balance ?? 0)
    kasKecil.ceiling_amount = Number(meta.ceiling_amount ?? 0)
    kasKecil.claim_threshold = Number(meta.claim_threshold ?? 0)
  }
  catch (error: unknown) {
    eligible.value = []

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.form.noEligible')),
    })
  }
  finally {
    eligibleLoading.value = false
  }
}

watch(() => form.branch, () => {
  form.claim_ids = []
  loadEligible()
})

const openCreate = (): void => {
  editingId.value = null

  form.branch = holderBranchIds.value[0] ?? null
  form.date = new Date().toISOString().slice(0, 10)
  form.subject = ''
  form.notes = ''
  form.claim_ids = []

  dialog.value = true

  loadEligible()
}

const openEdit = async (row: PettyCashRow): Promise<void> => {
  editingId.value = row.public_id

  try {
    const response = await axios.get(`${ENDPOINT}/${row.public_id}`, {
      headers: { Accept: 'application/json' },
    })

    const d = response.data?.data ?? {}

    form.branch = Number(d.branch_id ?? row.branch_id)
    form.date = String(d.date ?? '').slice(0, 10)
    form.subject = String(d.subject ?? '')
    form.notes = String(d.notes ?? '')

    dialog.value = true

    await loadEligible()

    /* Isi formulir disetel SESUDAH daftarnya dimuat, supaya pilihannya ada. */
    form.claim_ids = (d.claims ?? []).map((c: ClaimDiFormulir) => Number(c.claim_id))
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.detail.loadFailed')),
    })
  }
}

const save = async (): Promise<void> => {
  if (!formValid.value)
    return

  saving.value = true

  try {
    const muatan = {
      date: form.date,
      subject: form.subject,
      notes: form.notes || null,
      claim_ids: form.claim_ids,
    }

    if (editingId.value)
      await axios.put(`${ENDPOINT}/${editingId.value}`, muatan)
    else
      await axios.post(ENDPOINT, { ...muatan, branch: form.branch })

    dialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('pettyCash.form.saved'),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.form.saveFailed')),
    })
  }
  finally {
    saving.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Detail
|--------------------------------------------------------------------------
*/
const detailDialog = ref(false)
const detailLoading = ref(false)
const detail = ref<Record<string, unknown> | null>(null)

const openDetail = async (row: PettyCashRow): Promise<void> => {
  detail.value = null
  detailDialog.value = true
  detailLoading.value = true

  try {
    const response = await axios.get(`${ENDPOINT}/${row.public_id}`, {
      headers: { Accept: 'application/json' },
    })

    detail.value = response.data?.data ?? null
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.detail.loadFailed')),
    })
  }
  finally {
    detailLoading.value = false
  }
}

const detailClaims = computed<ClaimDiFormulir[]>(
  () => (detail.value?.claims as ClaimDiFormulir[]) ?? [],
)

const detailApprovals = computed<BarisApproval[]>(
  () => (detail.value?.approvals as BarisApproval[]) ?? [],
)

/*
|--------------------------------------------------------------------------
| Aksi berkeputusan
|--------------------------------------------------------------------------
| Satu dialog untuk empat aksi yang bentuknya sama: satu catatan, satu
| tombol. Empat dialog terpisah hanya akan menjadi empat tempat yang harus
| diperbaiki bersama setiap kali salah satunya berubah.
|--------------------------------------------------------------------------
*/
const aksiDialog = ref(false)
const aksiLoading = ref(false)
const aksiCatatan = ref('')

const aksiAktif = ref<{
  row: PettyCashRow
  jenis: 'approve' | 'reject' | 'disburse' | 'cancel'
} | null>(null)

const aksiWajibCatatan = computed(
  () => aksiAktif.value?.jenis === 'reject' || aksiAktif.value?.jenis === 'cancel',
)

const aksiValid = computed(
  () => !aksiWajibCatatan.value || aksiCatatan.value.trim().length >= 3,
)

const aksiJudul = computed(() => {
  const peta: Record<string, string> = {
    approve: 'pettyCash.action.approveTitle',
    reject: 'pettyCash.action.rejectTitle',
    disburse: 'pettyCash.action.disburseTitle',
    cancel: 'pettyCash.action.cancelTitle',
  }

  return aksiAktif.value ? t(peta[aksiAktif.value.jenis]) : ''
})

const bukaAksi = async (
  row: PettyCashRow,
  jenis: 'approve' | 'reject' | 'disburse' | 'cancel',
): Promise<void> => {
  /*
   * Pencairan dan pembatalan dikonfirmasi lebih dulu, karena keduanya
   * mengubah uang atau melepas claim. Persetujuan dan penolakan sudah
   * punya dialog catatannya sendiri sebagai jeda.
   */
  if (jenis === 'disburse' || jenis === 'cancel') {
    const konfirmasi = await showConfirmAlert({
      title: t(`pettyCash.action.${jenis}Confirm.title`),
      text: t(`pettyCash.action.${jenis}Confirm.text`),
    })

    if (!konfirmasi?.isConfirmed)
      return
  }

  aksiAktif.value = { row, jenis }
  aksiCatatan.value = ''
  aksiDialog.value = true
}

const jalankanAksi = async (): Promise<void> => {
  if (!aksiAktif.value || !aksiValid.value)
    return

  aksiLoading.value = true

  try {
    const { row, jenis } = aksiAktif.value

    await axios.patch(`${ENDPOINT}/${row.public_id}/${jenis}`, {
      notes: aksiCatatan.value || null,
    })

    aksiDialog.value = false

    showSuccessToast({ title: t('common.alert.success') })

    await fetchRows()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.action.failed')),
    })
  }
  finally {
    aksiLoading.value = false
  }
}

const submit = async (row: PettyCashRow): Promise<void> => {
  const konfirmasi = await showConfirmAlert({
    title: t('pettyCash.action.submitConfirm.title'),
    text: t('pettyCash.action.submitConfirm.text'),
  })

  if (!konfirmasi?.isConfirmed)
    return

  try {
    await axios.patch(`${ENDPOINT}/${row.public_id}/submit`)

    showSuccessToast({ title: t('common.alert.success') })

    await fetchRows()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.action.failed')),
    })
  }
}

const remove = async (row: PettyCashRow): Promise<void> => {
  const konfirmasi = await showConfirmAlert({
    title: t('pettyCash.action.deleteConfirm.title'),
    text: t('pettyCash.action.deleteConfirm.text'),
  })

  if (!konfirmasi?.isConfirmed)
    return

  try {
    await axios.delete(`${ENDPOINT}/${row.public_id}`)

    showSuccessToast({ title: t('common.alert.success') })

    await fetchRows()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.action.failed')),
    })
  }
}

watch(currentPage, () => {
  fetchRows()
})

watch(rowPerPage, () => {
  currentPage.value = 1
  fetchRows()
})

onMounted(async () => {
  if (!canView.value)
    return

  await Promise.all([fetchRows(), fetchBranchNames()])
})
</script>

<template>
  <div>
    <VCard v-if="!canView">
      <VCardText class="text-center py-12">
        <VIcon
          icon="tabler-lock"
          size="48"
          class="text-disabled mb-3"
        />

        <div class="text-body-1">
          {{ t('pettyCash.noAccess') }}
        </div>
      </VCardText>
    </VCard>

    <template v-else>
      <!--
        Panel saldo di atas daftar: inilah yang memperlihatkan peringatan
        saldo menipis kepada pemegang kas kecil tanpa bisa terlewat.
      -->
      <PettyCashBalancePanel class="mb-6" />

      <!-- FILTER -->
      <VCard class="mb-6">
        <VCardText class="pa-5">
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-5">
            <div class="d-flex align-center gap-3">
              <VAvatar
                size="44"
                color="primary"
                variant="tonal"
              >
                <VIcon
                  icon="tabler-filter"
                  size="24"
                />
              </VAvatar>

              <div>
                <div class="text-h5 font-weight-bold">
                  {{ t('pettyCash.list.filtersTitle') }}
                </div>

                <div class="text-body-2 text-medium-emphasis mt-1">
                  {{ t('pettyCash.list.filtersSubtitle') }}
                </div>
              </div>
            </div>

            <VBtn
              color="secondary"
              variant="tonal"
              prepend-icon="tabler-refresh"
              class="text-none"
              :disabled="loading"
              @click="resetFilters"
            >
              {{ t('pettyCash.list.resetButton') }}
            </VBtn>
          </div>

          <VRow>
            <VCol
              cols="12"
              md="4"
            >
              <VTextField
                v-model="filter.search"
                density="compact"
                clearable
                hide-details
                prepend-inner-icon="tabler-search"
                :label="t('pettyCash.list.search')"
                @keyup.enter="terapkanFilter"
                @click:clear="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <VSelect
                v-model="filter.status"
                density="compact"
                clearable
                hide-details
                prepend-inner-icon="tabler-flag"
                :items="statusOptions"
                :label="t('pettyCash.list.status')"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <VAutocomplete
                v-model="filter.branch"
                density="compact"
                clearable
                hide-details
                prepend-inner-icon="tabler-building"
                :items="branchFilterOptions"
                :label="t('pettyCash.list.branch')"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <VTextField
                v-model="filter.dateFrom"
                type="date"
                density="compact"
                hide-details
                :label="t('pettyCash.list.dateFrom')"
                @change="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <VTextField
                v-model="filter.dateTo"
                type="date"
                density="compact"
                hide-details
                :label="t('pettyCash.list.dateTo')"
                @change="terapkanFilter"
              />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!--
        KENAPA TOMBOL BUAT TIDAK ADA

        Tombol yang hilang tanpa sebab terbaca seperti fitur yang rusak.
        Sebabnya dua dan jalan keluarnya berbeda -- yang satu diurus di Role
        Permission, yang satu di master plafon -- jadi keduanya disebut
        terpisah, lengkap dengan cabang mana yang sudah siap.
      -->
      <VAlert
        v-if="sebabTidakBisaBuat"
        type="info"
        variant="tonal"
        class="mb-6"
        icon="tabler-info-circle"
      >
        <div class="font-weight-medium mb-1">
          {{ t('pettyCash.list.cannotCreate.title') }}
        </div>

        <div class="text-body-2">
          {{
            sebabTidakBisaBuat === 'izin'
              ? t('pettyCash.list.cannotCreate.noPermission')
              : t('pettyCash.list.cannotCreate.noCeiling')
          }}
        </div>

        <div
          v-if="sebabTidakBisaBuat === 'plafon'"
          class="text-body-2 mt-1"
        >
          {{
            ceilingBranches.length
              ? t('pettyCash.list.cannotCreate.ceilingList', { branches: ceilingBranches.join(', ') })
              : t('pettyCash.list.cannotCreate.noCeilingAtAll')
          }}
        </div>
      </VAlert>

      <!-- TABEL -->
      <VCard>
        <VCardText class="d-flex flex-wrap gap-4 align-center">
          <VBtn
            v-if="canCreate"
            color="primary"
            prepend-icon="tabler-plus"
            class="text-none"
            @click="openCreate"
          >
            {{ t('pettyCash.list.addButton') }}
          </VBtn>

          <VSpacer />
        </VCardText>

        <VDivider />

        <VProgressLinear
          v-if="loading"
          indeterminate
          color="primary"
        />

        <VTable class="text-no-wrap">
          <thead>
            <tr>
              <th>{{ t('pettyCash.list.columns.number') }}</th>
              <th>{{ t('pettyCash.list.columns.date') }}</th>
              <th>{{ t('pettyCash.list.columns.subject') }}</th>
              <th>{{ t('pettyCash.list.columns.branch') }}</th>
              <th class="text-center">
                {{ t('pettyCash.list.columns.claims') }}
              </th>
              <th class="text-end">
                {{ t('pettyCash.list.columns.total') }}
              </th>
              <th>{{ t('pettyCash.list.columns.status') }}</th>
              <th>{{ t('pettyCash.list.columns.requester') }}</th>
              <th class="text-end">
                {{ t('pettyCash.list.columns.actions') }}
              </th>
            </tr>
          </thead>

          <tbody>
            <tr
              v-for="row in rows"
              :key="row.public_id"
            >
              <td class="text-no-wrap font-weight-medium">
                {{ row.petty_cash_number }}
              </td>

              <td class="text-no-wrap">
                {{ tanggal(row.date) }}
              </td>

              <td class="pc-subject">
                {{ row.subject }}
              </td>

              <td>{{ row.branch ?? '-' }}</td>

              <td class="text-center">
                {{ row.claim_count }}
              </td>

              <td class="text-end font-weight-medium">
                {{ rupiah(row.total_amount) }}
              </td>

              <td>
                <VChip
                  size="small"
                  variant="tonal"
                  :color="warnaStatus(row.status)"
                >
                  {{ labelStatus(row.status) }}
                </VChip>
              </td>

              <td>{{ row.requester ?? '-' }}</td>

              <td class="text-end text-no-wrap">
                <VBtn
                  icon
                  variant="text"
                  size="small"
                  :title="t('pettyCash.action.detail')"
                  @click="openDetail(row)"
                >
                  <VIcon icon="tabler-eye" />
                </VBtn>

                <VBtn
                  v-if="row.can.update"
                  icon
                  variant="text"
                  size="small"
                  :title="t('pettyCash.action.edit')"
                  @click="openEdit(row)"
                >
                  <VIcon icon="tabler-pencil" />
                </VBtn>

                <VBtn
                  v-if="row.can.submit"
                  icon
                  variant="text"
                  size="small"
                  color="primary"
                  :title="t('pettyCash.action.submit')"
                  @click="submit(row)"
                >
                  <VIcon icon="tabler-send" />
                </VBtn>

                <VBtn
                  v-if="row.can.approve"
                  icon
                  variant="text"
                  size="small"
                  color="success"
                  :title="t('pettyCash.action.approve')"
                  @click="bukaAksi(row, 'approve')"
                >
                  <VIcon icon="tabler-check" />
                </VBtn>

                <VBtn
                  v-if="row.can.approve"
                  icon
                  variant="text"
                  size="small"
                  color="error"
                  :title="t('pettyCash.action.reject')"
                  @click="bukaAksi(row, 'reject')"
                >
                  <VIcon icon="tabler-x" />
                </VBtn>

                <VBtn
                  v-if="row.can.disburse"
                  icon
                  variant="text"
                  size="small"
                  color="success"
                  :title="t('pettyCash.action.disburse')"
                  @click="bukaAksi(row, 'disburse')"
                >
                  <VIcon icon="tabler-cash" />
                </VBtn>

                <VBtn
                  v-if="row.can.cancel"
                  icon
                  variant="text"
                  size="small"
                  color="warning"
                  :title="t('pettyCash.action.cancel')"
                  @click="bukaAksi(row, 'cancel')"
                >
                  <VIcon icon="tabler-ban" />
                </VBtn>

                <VBtn
                  v-if="row.can.delete"
                  icon
                  variant="text"
                  size="small"
                  color="error"
                  :title="t('pettyCash.action.delete')"
                  @click="remove(row)"
                >
                  <VIcon icon="tabler-trash" />
                </VBtn>
              </td>
            </tr>

            <tr v-if="!loading && rows.length === 0">
              <td
                colspan="9"
                class="text-center py-8"
              >
                <div class="text-body-1 font-weight-medium">
                  {{ t('pettyCash.list.emptyTitle') }}
                </div>

                <div class="text-caption text-medium-emphasis">
                  {{ t('pettyCash.list.emptySubtitle') }}
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>

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

      <!-- FORMULIR -->
      <VDialog
        v-model="dialog"
        max-width="1040"
        scrollable
      >
        <VCard>
          <VCardItem>
            <VCardTitle>
              {{ editingId ? t('pettyCash.form.editTitle') : t('pettyCash.form.createTitle') }}
            </VCardTitle>
          </VCardItem>

          <VDivider />

          <VCardText>
            <VRow>
              <VCol
                cols="12"
                md="4"
              >
                <VSelect
                  v-model="form.branch"
                  :items="holderBranchOptions"
                  :label="t('pettyCash.form.branch')"
                  :disabled="!!editingId"
                />
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <VTextField
                  v-model="form.date"
                  type="date"
                  :label="t('pettyCash.form.date')"
                />
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <VTextField
                  v-model="form.subject"
                  :label="t('pettyCash.form.subject')"
                />
              </VCol>

              <VCol cols="12">
                <VTextarea
                  v-model="form.notes"
                  rows="2"
                  :label="t('pettyCash.form.notes')"
                />
              </VCol>
            </VRow>

            <VDivider class="my-4" />

            <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-2">
              <div>
                <div class="text-body-1 font-weight-medium">
                  {{ t('pettyCash.form.claims') }}
                </div>

                <div class="text-caption text-medium-emphasis">
                  {{ t('pettyCash.form.claimsHint') }}
                </div>
              </div>

              <!--
                Tiga angka berdampingan: saldo sekarang, total pilihan, dan
                saldo sesudah dicairkan. Yang ketiga itu yang sebenarnya
                ingin diketahui -- dua yang pertama hanya bahan hitungnya.
              -->
              <div class="d-flex gap-4 text-end">
                <div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('pettyCash.warningPanel.balance') }}
                  </div>

                  <div class="text-body-1 font-weight-medium">
                    {{ rupiah(kasKecil.balance) }}
                  </div>
                </div>

                <div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('pettyCash.form.totalSelected') }}
                  </div>

                  <div class="text-body-1 font-weight-medium">
                    {{ rupiah(totalDipilih) }}
                  </div>
                </div>

                <div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('pettyCash.form.balanceAfter') }}
                  </div>

                  <div
                    class="text-body-1 font-weight-medium"
                    :class="melebihiSaldo ? 'text-error' : ''"
                  >
                    {{ rupiah(saldoSesudah) }}
                  </div>
                </div>
              </div>
            </div>

            <VAlert
              v-if="melebihiSaldo"
              type="error"
              variant="tonal"
              density="compact"
              class="mb-3"
            >
              {{ t('pettyCash.form.overBalance') }}
            </VAlert>

            <VProgressLinear
              v-if="eligibleLoading"
              indeterminate
              color="primary"
              class="mb-3"
            />

            <div
              v-if="!eligibleLoading && eligible.length === 0"
              class="text-center py-8 text-medium-emphasis"
            >
              {{ t('pettyCash.form.noEligible') }}
            </div>

            <VTable
              v-else
              density="compact"
            >
              <thead>
                <tr>
                  <th style="width: 48px" />
                  <th>{{ t('pettyCash.form.claimColumns.number') }}</th>
                  <th>{{ t('pettyCash.form.claimColumns.date') }}</th>
                  <th>{{ t('pettyCash.form.claimColumns.subject') }}</th>
                  <th>{{ t('pettyCash.form.claimColumns.requester') }}</th>
                  <th>{{ t('pettyCash.form.claimColumns.department') }}</th>
                  <th class="text-end">
                    {{ t('pettyCash.form.claimColumns.amount') }}
                  </th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="c in eligible"
                  :key="c.id"
                >
                  <td>
                    <VCheckbox
                      v-model="form.claim_ids"
                      :value="c.id"
                      density="compact"
                      hide-details
                    />
                  </td>

                  <td class="text-no-wrap">
                    {{ c.claim_number }}
                  </td>

                  <td class="text-no-wrap">
                    {{ tanggal(c.date) }}
                  </td>

                  <td class="pc-subject">
                    {{ c.subject }}
                  </td>

                  <td>{{ c.requester ?? '-' }}</td>

                  <td>{{ c.department ?? '-' }}</td>

                  <td class="text-end">
                    {{ rupiah(c.amount) }}
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>

          <VDivider />

          <VCardActions>
            <div class="text-body-2 text-medium-emphasis ps-2">
              {{ t('pettyCash.form.selected', { count: form.claim_ids.length }) }}
            </div>

            <VSpacer />

            <VBtn
              variant="text"
              class="text-none"
              @click="dialog = false"
            >
              {{ t('pettyCash.action.close') }}
            </VBtn>

            <VBtn
              color="primary"
              class="text-none"
              :loading="saving"
              :disabled="!formValid"
              @click="save"
            >
              {{ t('pettyCash.action.save') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <!-- DETAIL -->
      <VDialog
        v-model="detailDialog"
        max-width="980"
        scrollable
      >
        <VCard>
          <VCardItem>
            <VCardTitle>{{ t('pettyCash.detail.title') }}</VCardTitle>

            <VCardSubtitle v-if="detail">
              {{ detail.petty_cash_number }} — {{ detail.branch }}
            </VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText>
            <VProgressLinear
              v-if="detailLoading"
              indeterminate
              color="primary"
              class="mb-4"
            />

            <template v-if="detail">
              <VRow class="mb-2">
                <VCol
                  cols="12"
                  md="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    {{ t('pettyCash.list.columns.total') }}
                  </div>

                  <div class="text-h6">
                    {{ rupiah(detail.total_amount as number) }}
                  </div>
                </VCol>

                <VCol
                  v-if="detail.ceiling"
                  cols="12"
                  md="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    {{ t('pettyCash.warningPanel.balance') }}
                  </div>

                  <div class="text-h6">
                    {{ rupiah((detail.ceiling as Record<string, number>).balance) }}
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    {{ t('pettyCash.list.columns.status') }}
                  </div>

                  <VChip
                    size="small"
                    variant="tonal"
                    :color="warnaStatus(detail.status as string)"
                  >
                    {{ labelStatus(detail.status as string) }}
                  </VChip>
                </VCol>
              </VRow>

              <VDivider class="my-3" />

              <div class="text-body-1 font-weight-medium mb-2">
                {{ t('pettyCash.detail.claims') }}
              </div>

              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('pettyCash.form.claimColumns.number') }}</th>
                    <th>{{ t('pettyCash.form.claimColumns.requester') }}</th>
                    <th class="text-end">
                      {{ t('pettyCash.detail.amountFrozen') }}
                    </th>
                    <th class="text-end">
                      {{ t('pettyCash.detail.amountNow') }}
                    </th>
                    <th>{{ t('pettyCash.list.columns.status') }}</th>
                  </tr>
                </thead>

                <tbody>
                  <tr
                    v-for="c in detailClaims"
                    :key="c.claim_id"
                  >
                    <td class="text-no-wrap">
                      {{ c.claim_number }}
                    </td>

                    <td>{{ c.requester ?? '-' }}</td>

                    <td class="text-end">
                      {{ rupiah(c.amount) }}
                    </td>

                    <!--
                      Dua angka berdampingan, dan yang berbeda diberi warna.
                      Keduanya harus sama; kalau tidak, itu tanda ada yang
                      perlu diperiksa sebelum uang keluar.
                    -->
                    <td
                      class="text-end"
                      :class="Number(c.amount) !== Number(c.claim_amount_now) ? 'text-error font-weight-medium' : ''"
                    >
                      {{ rupiah(c.claim_amount_now) }}
                    </td>

                    <td>
                      <VChip
                        size="small"
                        variant="tonal"
                      >
                        {{ c.status }}
                      </VChip>
                    </td>
                  </tr>
                </tbody>
              </VTable>

              <VAlert
                v-if="detailClaims.some(c => Number(c.amount) !== Number(c.claim_amount_now))"
                type="warning"
                variant="tonal"
                density="compact"
                class="mt-3"
              >
                {{ t('pettyCash.detail.amountMismatch') }}
              </VAlert>

              <VDivider class="my-4" />

              <div class="text-body-1 font-weight-medium mb-2">
                {{ t('pettyCash.detail.approvals') }}
              </div>

              <VTable density="compact">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>{{ t('pettyCash.list.columns.status') }}</th>
                    <th>{{ t('pettyCash.form.claimColumns.requester') }}</th>
                    <th>{{ t('pettyCash.action.notes') }}</th>
                  </tr>
                </thead>

                <tbody>
                  <tr
                    v-for="(a, i) in detailApprovals"
                    :key="i"
                  >
                    <td>{{ a.step_order }}. {{ a.label ?? '-' }}</td>

                    <td>
                      <VChip
                        size="small"
                        variant="tonal"
                        :color="a.status === 'APPROVED' ? 'success' : a.status === 'REJECTED' ? 'error' : 'secondary'"
                      >
                        {{ a.status }}
                      </VChip>
                    </td>

                    <td>{{ a.approver_name ?? '-' }}</td>

                    <td class="text-body-2">
                      {{ a.notes ?? '-' }}
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>
          </VCardText>

          <VDivider />

          <VCardActions>
            <VSpacer />

            <VBtn
              variant="text"
              class="text-none"
              @click="detailDialog = false"
            >
              {{ t('pettyCash.action.close') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>

      <!-- AKSI BERKEPUTUSAN -->
      <VDialog
        v-model="aksiDialog"
        max-width="520"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>{{ aksiJudul }}</VCardTitle>

            <VCardSubtitle v-if="aksiAktif">
              {{ aksiAktif.row.petty_cash_number }} — {{ rupiah(aksiAktif.row.total_amount) }}
            </VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText>
            <VTextarea
              v-model="aksiCatatan"
              rows="3"
              :label="aksiWajibCatatan ? t('pettyCash.action.notesRequired') : t('pettyCash.action.notes')"
            />
          </VCardText>

          <VDivider />

          <VCardActions>
            <VSpacer />

            <VBtn
              variant="text"
              class="text-none"
              @click="aksiDialog = false"
            >
              {{ t('pettyCash.action.close') }}
            </VBtn>

            <VBtn
              color="primary"
              class="text-none"
              :loading="aksiLoading"
              :disabled="!aksiValid"
              @click="jalankanAksi"
            >
              {{ t('pettyCash.action.confirm') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>
    </template>
  </div>
</template>

<style scoped>
/*
 * Perihal bisa panjang. Dibiarkan turun ke bawah, bukan mendorong kolom lain
 * keluar layar -- pelajaran dari nama item Goods Receipt.
 */
.pc-subject {
  inline-size: 100%;
  min-inline-size: 180px;
  max-inline-size: 320px;
  overflow-wrap: anywhere;
  white-space: normal;
}

/* Subtitle halaman boleh membungkus, bukan dipotong elipsis. */
.pc-header :deep(.v-card-subtitle) {
  overflow: visible;
  line-height: 1.5;
  text-overflow: clip;
  white-space: normal;
}

.pc-header__text {
  flex: 1 1 320px;
  min-inline-size: 0;
  max-inline-size: 760px;
}
</style>
