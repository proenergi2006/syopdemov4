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
| Top Up Petty Cash
|--------------------------------------------------------------------------
| Formulirnya hanya menanyakan cabang, tanggal, dan keterangan. NOMINALNYA
| TIDAK DIISI PEMOHON -- ia dihitung sistem dari plafon dikurangi saldo, dan
| diperlihatkan sebelum tombol disimpan ditekan.
|
| Angka yang baru muncul sesudah menyimpan adalah angka yang tidak pernah
| disetujui siapa pun dengan sadar, jadi ia diperlihatkan lebih dulu.
|--------------------------------------------------------------------------
*/

interface Kemampuan {
  update: boolean
  delete: boolean
  submit: boolean
  cancel: boolean
  approve: boolean
}

interface TopupRow {
  public_id: string
  topup_number: string
  date: string | null
  subject: string | null
  branch: string | null
  branch_id: number
  ceiling_amount: number
  balance_before: number
  amount: number
  status: string
  requester: string | null
  final_approved_by: string | null
  can: Kemampuan
}

interface KeadaanKas {
  ceiling_amount: number
  balance: number
  amount: number | null
  warning_percentage: number
  warning_threshold: number
  below_warning: boolean
  running_topup: { public_id: string; topup_number: string; status: string } | null
}

const { t } = useI18n()
const permissionStore = usePermissionStore()

const ENDPOINT = '/fund-request/petty-cash-topup'

const rows = ref<TopupRow[]>([])
const loading = ref(false)
const holderBranchIds = ref<number[]>([])
const branchNames = ref<Record<number, string>>({})

const filter = reactive({
  search: '',
  status: null as string | null,
  branch: null as number | null,
  dateFrom: '' as string,
  dateTo: '' as string,
})

/* Cabang berplafon hidup; dipakai menjelaskan tombol yang tidak ada. */
const ceilingBranches = ref<string[]>([])

/*
| Halaman dipisahkan dari saringan: mengubah saringan mengembalikan daftar ke
| halaman pertama, berpindah halaman tidak menyentuh saringannya.
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

const canView = computed(() => permissionStore.can('petty_cash_topup.view'))

const punyaIzinBuat = computed(() => permissionStore.can('petty_cash_topup.create'))

const canCreate = computed(
  () => punyaIzinBuat.value && holderBranchIds.value.length > 0,
)

/*
| Dua sebab berbeda dengan jalan keluar yang berbeda: izinnya diurus di Role
| Permission, plafonnya di master plafon.
*/
const sebabTidakBisaBuat = computed<'izin' | 'plafon' | null>(() => {
  if (canCreate.value)
    return null

  return punyaIzinBuat.value ? 'plafon' : 'izin'
})

const STATUS_LIST = ['DRAFT', 'IN PROGRESS', 'APPROVED', 'REJECTED', 'CANCELLED']

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
    'APPROVED': 'success',
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
      text: getApiErrorMessage(error, t('pettyCash.topup.loadFailed')),
    })
  }
  finally {
    loading.value = false
  }
}

/*
| Menyaring sewaktu berada di halaman 3 bisa menghasilkan daftar kosong yang
| terbaca seperti "tidak ada datanya", padahal datanya ada di halaman 1.
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
const keadaanLoading = ref(false)
const keadaan = ref<KeadaanKas | null>(null)

const form = reactive({
  branch: null as number | null,
  date: new Date().toISOString().slice(0, 10),
  subject: '',
  notes: '',
})

const sudahPenuh = computed(
  () => !!keadaan.value && (keadaan.value.amount === null || keadaan.value.amount <= 0),
)

const adaYangBerjalan = computed(() => !!keadaan.value?.running_topup)

const formValid = computed(
  () => !!form.branch && !!form.date && !sudahPenuh.value && !adaYangBerjalan.value,
)

const loadKeadaan = async (): Promise<void> => {
  keadaan.value = null

  if (!form.branch)
    return

  keadaanLoading.value = true

  try {
    const response = await axios.get(`${ENDPOINT}/ceiling-state`, {
      headers: { Accept: 'application/json' },
      params: { branch: form.branch },
    })

    keadaan.value = response.data?.data ?? null
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.topup.loadFailed')),
    })
  }
  finally {
    keadaanLoading.value = false
  }
}

watch(() => form.branch, loadKeadaan)

const openCreate = (): void => {
  editingId.value = null

  form.branch = holderBranchIds.value[0] ?? null
  form.date = new Date().toISOString().slice(0, 10)
  form.subject = ''
  form.notes = ''

  dialog.value = true

  loadKeadaan()
}

const openEdit = (row: TopupRow): void => {
  editingId.value = row.public_id

  form.branch = row.branch_id
  form.date = String(row.date ?? '').slice(0, 10)
  form.subject = String(row.subject ?? '')
  form.notes = ''

  dialog.value = true

  loadKeadaan()
}

const save = async (): Promise<void> => {
  if (!editingId.value && !formValid.value)
    return

  saving.value = true

  try {
    const muatan = {
      date: form.date,
      subject: form.subject || null,
      notes: form.notes || null,
    }

    if (editingId.value)
      await axios.put(`${ENDPOINT}/${editingId.value}`, muatan)
    else
      await axios.post(ENDPOINT, { ...muatan, branch: form.branch })

    dialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('pettyCash.topup.form.saved'),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.topup.form.saveFailed')),
    })
  }
  finally {
    saving.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Aksi berkeputusan
|--------------------------------------------------------------------------
*/
const aksiDialog = ref(false)
const aksiLoading = ref(false)
const aksiCatatan = ref('')

const aksiAktif = ref<{
  row: TopupRow
  jenis: 'approve' | 'reject' | 'cancel'
} | null>(null)

const aksiWajibCatatan = computed(
  () => aksiAktif.value?.jenis === 'reject' || aksiAktif.value?.jenis === 'cancel',
)

const aksiValid = computed(
  () => !aksiWajibCatatan.value || aksiCatatan.value.trim().length >= 3,
)

const aksiJudul = computed(() => {
  const peta: Record<string, string> = {
    approve: 'pettyCash.topup.action.approveTitle',
    reject: 'pettyCash.topup.action.rejectTitle',
    cancel: 'pettyCash.topup.action.cancelTitle',
  }

  return aksiAktif.value ? t(peta[aksiAktif.value.jenis]) : ''
})

const bukaAksi = async (
  row: TopupRow,
  jenis: 'approve' | 'reject' | 'cancel',
): Promise<void> => {
  /*
   * Persetujuan dikonfirmasi lebih dulu karena persetujuan TERAKHIR langsung
   * menambah saldo -- dan yang menekan tombolnya belum tentu tahu bahwa
   * dirinya tingkat terakhir.
   */
  if (jenis === 'approve' || jenis === 'cancel') {
    const kunci = jenis === 'approve' ? 'approveConfirm' : 'cancelConfirm'

    const konfirmasi = await showConfirmAlert({
      title: t(`pettyCash.topup.action.${kunci}.title`),
      text: t(`pettyCash.topup.action.${kunci}.text`),
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

    const response = await axios.patch(`${ENDPOINT}/${row.public_id}/${jenis}`, {
      notes: aksiCatatan.value || null,
    })

    aksiDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: String(response.data?.message ?? ''),
    })

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

const submit = async (row: TopupRow): Promise<void> => {
  try {
    const response = await axios.patch(`${ENDPOINT}/${row.public_id}/submit`)

    showSuccessToast({
      title: t('common.alert.success'),
      text: String(response.data?.message ?? ''),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('pettyCash.action.failed')),
    })
  }
}

const remove = async (row: TopupRow): Promise<void> => {
  const konfirmasi = await showConfirmAlert({
    title: t('pettyCash.action.deleteConfirm.title'),
    text: t('pettyCash.topup.action.cancelConfirm.text'),
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
                  {{ t('pettyCash.topup.filtersTitle') }}
                </div>

                <div class="text-body-2 text-medium-emphasis mt-1">
                  {{ t('pettyCash.topup.filtersSubtitle') }}
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
              {{ t('pettyCash.topup.resetButton') }}
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

      <!-- KENAPA TOMBOL AJUKAN TIDAK ADA: lihat keterangan di halaman Petty Cash. -->
      <VAlert
        v-if="sebabTidakBisaBuat"
        type="info"
        variant="tonal"
        class="mb-6"
        icon="tabler-info-circle"
      >
        <div class="font-weight-medium mb-1">
          {{ t('pettyCash.topup.cannotCreate.title') }}
        </div>

        <div class="text-body-2">
          {{
            sebabTidakBisaBuat === 'izin'
              ? t('pettyCash.topup.cannotCreate.noPermission')
              : t('pettyCash.topup.cannotCreate.noCeiling')
          }}
        </div>

        <div
          v-if="sebabTidakBisaBuat === 'plafon'"
          class="text-body-2 mt-1"
        >
          {{
            ceilingBranches.length
              ? t('pettyCash.topup.cannotCreate.ceilingList', { branches: ceilingBranches.join(', ') })
              : t('pettyCash.topup.cannotCreate.noCeilingAtAll')
          }}
        </div>
      </VAlert>

      <!-- TABEL -->
      <VCard>
        <VCardText class="d-flex flex-wrap gap-4 align-center">
          <VBtn
            v-if="canCreate"
            color="primary"
            prepend-icon="tabler-cash-banknote"
            class="text-none"
            @click="openCreate"
          >
            {{ t('pettyCash.topup.addButton') }}
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
              <th>{{ t('pettyCash.topup.columns.number') }}</th>
              <th>{{ t('pettyCash.topup.columns.date') }}</th>
              <th>{{ t('pettyCash.topup.columns.branch') }}</th>
              <th class="text-end">
                {{ t('pettyCash.topup.columns.ceiling') }}
              </th>
              <th class="text-end">
                {{ t('pettyCash.topup.columns.balanceBefore') }}
              </th>
              <th class="text-end">
                {{ t('pettyCash.topup.columns.amount') }}
              </th>
              <th>{{ t('pettyCash.topup.columns.status') }}</th>
              <th>{{ t('pettyCash.topup.columns.requester') }}</th>
              <th class="text-end">
                {{ t('pettyCash.topup.columns.actions') }}
              </th>
            </tr>
          </thead>

          <tbody>
            <tr
              v-for="row in rows"
              :key="row.public_id"
            >
              <td class="text-no-wrap font-weight-medium">
                {{ row.topup_number }}
              </td>

              <td class="text-no-wrap">
                {{ tanggal(row.date) }}
              </td>

              <td>{{ row.branch ?? '-' }}</td>

              <td class="text-end">
                {{ rupiah(row.ceiling_amount) }}
              </td>

              <td class="text-end">
                {{ rupiah(row.balance_before) }}
              </td>

              <td class="text-end font-weight-medium">
                {{ rupiah(row.amount) }}
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
                  {{ t('pettyCash.topup.emptyTitle') }}
                </div>

                <div class="text-caption text-medium-emphasis">
                  {{ t('pettyCash.topup.emptySubtitle') }}
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
        max-width="620"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>
              {{ editingId ? t('pettyCash.topup.form.editTitle') : t('pettyCash.topup.form.createTitle') }}
            </VCardTitle>
          </VCardItem>

          <VDivider />

          <VCardText>
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <VSelect
                  v-model="form.branch"
                  :items="holderBranchOptions"
                  :label="t('pettyCash.topup.form.branch')"
                  :disabled="!!editingId"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VTextField
                  v-model="form.date"
                  type="date"
                  :label="t('pettyCash.topup.form.date')"
                />
              </VCol>

              <VCol cols="12">
                <VTextField
                  v-model="form.subject"
                  :label="t('pettyCash.topup.form.subject')"
                />
              </VCol>

              <VCol cols="12">
                <VTextarea
                  v-model="form.notes"
                  rows="2"
                  :label="t('pettyCash.topup.form.notes')"
                />
              </VCol>
            </VRow>

            <VProgressLinear
              v-if="keadaanLoading"
              indeterminate
              color="primary"
              class="mb-3"
            />

            <!--
              Nominalnya diperlihatkan SEBELUM tombol simpan ditekan, bersama
              dua angka pembentuknya. Angka yang baru muncul sesudah menyimpan
              adalah angka yang tidak pernah dilihat dengan sadar.
            -->
            <template v-if="keadaan">
              <VDivider class="my-3" />

              <VRow>
                <VCol
                  cols="6"
                  md="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    {{ t('pettyCash.topup.form.ceiling') }}
                  </div>

                  <div class="text-body-1">
                    {{ rupiah(keadaan.ceiling_amount) }}
                  </div>
                </VCol>

                <VCol
                  cols="6"
                  md="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    {{ t('pettyCash.topup.form.balance') }}
                  </div>

                  <div
                    class="text-body-1"
                    :class="keadaan.below_warning ? 'text-error font-weight-medium' : ''"
                  >
                    {{ rupiah(keadaan.balance) }}
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  md="4"
                >
                  <div class="text-caption text-medium-emphasis">
                    {{ t('pettyCash.topup.form.computed') }}
                  </div>

                  <div class="text-h6 text-primary">
                    {{ rupiah(keadaan.amount ?? 0) }}
                  </div>
                </VCol>
              </VRow>

              <div class="text-caption text-medium-emphasis mt-1">
                {{ t('pettyCash.topup.form.computedHint') }}
              </div>

              <VAlert
                v-if="adaYangBerjalan && keadaan.running_topup"
                type="warning"
                variant="tonal"
                density="compact"
                class="mt-3"
              >
                {{ t('pettyCash.topup.form.running', {
                  number: keadaan.running_topup.topup_number,
                  status: keadaan.running_topup.status,
                }) }}
              </VAlert>

              <VAlert
                v-else-if="sudahPenuh"
                type="info"
                variant="tonal"
                density="compact"
                class="mt-3"
              >
                {{ t('pettyCash.topup.form.full') }}
              </VAlert>
            </template>
          </VCardText>

          <VDivider />

          <VCardActions>
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
              :disabled="!editingId && !formValid"
              @click="save"
            >
              {{ t('pettyCash.action.save') }}
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
              {{ aksiAktif.row.topup_number }} — {{ rupiah(aksiAktif.row.amount) }}
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
