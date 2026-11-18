<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import axios from '@axios'
import { showErrorToast } from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { usePermissionStore } from '@/stores/permission'
import { formatUang } from '@/utils/numberFormatter'

/*
|--------------------------------------------------------------------------
| Daftar Bank Disbursement Voucher
|--------------------------------------------------------------------------
| Susunan layarnya sengaja dibuat sama dengan modul pengajuan dana lain --
| kartu penyaring di atas, kartu tabel di bawahnya, jumlah baris dan halaman
| di kakinya. Orang yang sudah terbiasa dengan FPU dan Claim tidak perlu
| belajar apa pun lagi di sini.
|--------------------------------------------------------------------------
*/

interface BdvRow {
  public_id: string
  bdv_number: string | null
  date: string | null
  document_type: string
  document_type_label: string
  source_number: string | null
  branch: string | null
  department: string | null
  total_amount: number
  status: string
  requester: string | null
  created_at: string | null
}

interface PilihanDokumen {
  code: string
  label: string
}

interface PilihanUmum {
  value: string | number
  title: string
}

const { t } = useI18n()
const router = useRouter()
const permissionStore = usePermissionStore()

const ENDPOINT = '/cash-bank/bdv'

const rows = ref<BdvRow[]>([])
const loading = ref(false)

const documentTypes = ref<PilihanDokumen[]>([])
const statuses = ref<string[]>([])
const branches = ref<PilihanUmum[]>([])
const departments = ref<PilihanUmum[]>([])

const filter = reactive({
  search: '',
  status: null as string | null,
  documentType: null as string | null,
  branch: null as string | null,
  departmentId: null as number | null,

  /* null, bukan string kosong: itu yang dikirim pemilih tanggal saat dibersihkan. */
  dateFrom: null as string | null,
  dateTo: null as string | null,
})

const currentPage = ref(1)
const rowPerPage = ref(25)
const totalData = ref(0)
const totalPage = ref(1)

const canView = computed(() => permissionStore.can('bdv.view'))
const canCreate = computed(() => permissionStore.can('bdv.create'))

const adaPenyaringAktif = computed(() =>
  Boolean(
    filter.search
    || filter.status
    || filter.documentType
    || filter.branch
    || filter.departmentId
    || filter.dateFrom
    || filter.dateTo,
  ))

const paginationData = computed(() => {
  if (totalData.value === 0)
    return t('bdv.list.showing', { from: 0, to: 0, total: 0 })

  const dari = (currentPage.value - 1) * rowPerPage.value + 1
  const sampai = Math.min(currentPage.value * rowPerPage.value, totalData.value)

  return t('bdv.list.showing', { from: dari, to: sampai, total: totalData.value })
})

/*
|--------------------------------------------------------------------------
| Tampilan nilai
|--------------------------------------------------------------------------
*/
const rupiah = (nilai: number | null | undefined): string => formatUang(nilai ?? 0)

const tanggal = (nilai: string | null): string => {
  if (!nilai)
    return '-'

  const d = new Date(nilai)

  if (Number.isNaN(d.getTime()))
    return '-'

  return d.toLocaleDateString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

const warnaStatus = (status: string): string => {
  const peta: Record<string, string> = {
    'DRAFT': 'secondary',
    'IN PROGRESS': 'warning',
    'NEED REVISION': 'warning',
    'APPROVED': 'success',
    'REJECTED': 'error',
    'CANCELLED': 'secondary',
  }

  return peta[status] ?? 'secondary'
}

/* Status yang belum punya terjemahan ditampilkan apa adanya, bukan sebagai kunci. */
const labelStatus = (status: string): string => {
  const kunci = `bdv.status.${status}`
  const hasil = t(kunci)

  return hasil === kunci ? status : hasil
}

const statusOptions = computed(() =>
  statuses.value.map(s => ({ value: s, title: labelStatus(s) })))

const documentTypeOptions = computed(() =>
  documentTypes.value.map(d => ({ value: d.code, title: d.label })))

/*
|--------------------------------------------------------------------------
| Memuat daftar
|--------------------------------------------------------------------------
| Memakai nomor urut permintaan dengan alasan yang sama seperti di daftar FPU
| dan Claim: penyaring yang diubah berturut-turut melahirkan beberapa
| permintaan sekaligus, dan yang berangkat lebih dulu tidak selalu tiba lebih
| dulu. Hanya jawaban dari permintaan terakhir yang boleh dipakai.
|--------------------------------------------------------------------------
*/
let nomorPermintaanDaftar = 0

/*
| Penyaring yang kosong dikirim sebagai undefined, bukan string kosong: axios
| membuang undefined dari query string, sehingga server menerima permintaan
| yang bersih tanpa perlu menyaring nilai kosong sendiri.
*/
const buildFilterParams = (): Record<string, unknown> => ({
  search: filter.search || undefined,
  status: filter.status || undefined,
  document_type: filter.documentType || undefined,
  branch: filter.branch || undefined,
  department_id: filter.departmentId || undefined,
  date_from: filter.dateFrom || undefined,
  date_to: filter.dateTo || undefined,
})

const fetchRows = async (): Promise<void> => {
  if (!canView.value)
    return

  const nomorPermintaan = ++nomorPermintaanDaftar

  loading.value = true

  try {
    const { data } = await axios.get(ENDPOINT, {
      headers: { Accept: 'application/json' },
      params: {
        page: currentPage.value,
        per_page: rowPerPage.value,
        ...buildFilterParams(),
      },
    })

    if (nomorPermintaan !== nomorPermintaanDaftar)
      return

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalData.value = Number(data?.meta?.total ?? 0)
    totalPage.value = Number(data?.meta?.last_page ?? 1)
  }
  catch (error: unknown) {
    if (nomorPermintaan !== nomorPermintaanDaftar)
      return

    rows.value = []
    totalData.value = 0
    totalPage.value = 1

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.list.loadFailed')),
    })
  }
  finally {
    if (nomorPermintaan === nomorPermintaanDaftar)
      loading.value = false
  }
}

const fetchOptions = async (): Promise<void> => {
  if (!canView.value)
    return

  try {
    const { data } = await axios.get(`${ENDPOINT}/options`, {
      headers: { Accept: 'application/json' },
    })

    documentTypes.value = data?.data?.document_types ?? []
    statuses.value = data?.data?.statuses ?? []
    branches.value = data?.data?.branches ?? []
    departments.value = data?.data?.departments ?? []
  }
  catch {
    /*
     * Penyaring yang gagal dimuat tidak menghalangi daftarnya: dropdown-nya
     * kosong, tetapi datanya tetap terbaca.
     */
    documentTypes.value = []
    statuses.value = []
    branches.value = []
    departments.value = []
  }
}

const terapkanFilter = async (): Promise<void> => {
  currentPage.value = 1

  await fetchRows()
}

const resetFilters = async (): Promise<void> => {
  filter.search = ''
  filter.status = null
  filter.documentType = null
  filter.branch = null
  filter.departmentId = null
  filter.dateFrom = null
  filter.dateTo = null

  await terapkanFilter()
}

/*
| Rentang tanggal terbalik dikoreksi saat itu juga, supaya permintaan tidak
| pernah berangkat dalam keadaan yang mustahil menghasilkan apa pun.
*/
watch(() => filter.dateTo, nilai => {
  if (!nilai || !filter.dateFrom)
    return

  if (new Date(nilai) < new Date(filter.dateFrom)) {
    filter.dateTo = null

    showErrorToast({
      title: t('common.alert.error'),
      text: t('bdv.list.dateRangeInvalid'),
    })
  }
})

watch([currentPage, rowPerPage], () => {
  fetchRows()
})

const bukaFormulirBuat = (): void => {
  router.push('/cash_bank/bdv/create')
}

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canView.value)
    return

  await Promise.all([fetchOptions(), fetchRows()])
})
</script>

<template>
  <div>
    <VCard v-if="permissionStore.isLoaded && !canView">
      <VCardText class="text-center py-12">
        <VIcon
          icon="tabler-lock"
          size="48"
          class="text-disabled mb-3"
        />

        <div class="text-body-1">
          {{ t('bdv.noAccess') }}
        </div>
      </VCardText>
    </VCard>

    <template v-else>
      <!-- ================= PENYARING ================= -->
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
                  icon="tabler-building-bank"
                  size="24"
                />
              </VAvatar>

              <div>
                <div class="text-h5 font-weight-bold">
                  {{ t('bdv.list.filtersTitle') }}
                </div>

                <div class="text-body-2 text-medium-emphasis mt-1">
                  {{ t('bdv.list.filtersSubtitle') }}
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
              {{ t('bdv.list.resetButton') }}
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
                :label="t('bdv.list.search')"
                @keyup.enter="terapkanFilter"
                @click:clear="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <VSelect
                v-model="filter.documentType"
                density="compact"
                clearable
                hide-details
                prepend-inner-icon="tabler-file-text"
                :items="documentTypeOptions"
                :label="t('bdv.list.documentType')"
                @update:model-value="terapkanFilter"
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
                :label="t('bdv.list.status')"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="3"
            >
              <VAutocomplete
                v-model="filter.branch"
                density="compact"
                clearable
                hide-details
                prepend-inner-icon="tabler-building"
                :items="branches"
                :label="t('bdv.list.branch')"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="3"
            >
              <VAutocomplete
                v-model="filter.departmentId"
                density="compact"
                clearable
                hide-details
                prepend-inner-icon="tabler-users-group"
                :items="departments"
                :label="t('bdv.list.department')"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <!--
              Memakai AppDateTimePicker, bukan input tanggal bawaan peramban.
              Yang bawaan mengikuti setelan wilayah komputer pemakainya,
              sehingga di sebagian mesin tertulis mm/dd/yyyy -- urutan yang
              berbeda dari seluruh tanggal lain di aplikasi ini, dan sumber
              salah baca yang tidak disadari.
            -->
            <VCol
              cols="12"
              md="3"
            >
              <AppDateTimePicker
                v-model="filter.dateFrom"
                density="compact"
                clearable
                :label="t('bdv.list.dateFrom')"
                :config="{ dateFormat: 'Y-m-d', position: 'below' }"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="3"
            >
              <AppDateTimePicker
                v-model="filter.dateTo"
                density="compact"
                clearable
                :label="t('bdv.list.dateTo')"
                :config="{ dateFormat: 'Y-m-d', position: 'below' }"
                @update:model-value="terapkanFilter"
              />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- ================= TABEL ================= -->
      <VCard>
        <VCardText class="d-flex flex-wrap gap-4 align-center">
          <VBtn
            v-if="canCreate"
            color="primary"
            prepend-icon="tabler-plus"
            class="text-none"
            @click="bukaFormulirBuat"
          >
            {{ t('bdv.list.addButton') }}
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
              <th>{{ t('bdv.list.columns.number') }}</th>
              <th>{{ t('bdv.list.columns.date') }}</th>
              <th>{{ t('bdv.list.columns.documentType') }}</th>
              <th>{{ t('bdv.list.columns.sourceNumber') }}</th>
              <th>{{ t('bdv.list.columns.branch') }}</th>
              <th>{{ t('bdv.list.columns.department') }}</th>
              <th class="text-end">
                {{ t('bdv.list.columns.amount') }}
              </th>
              <th>{{ t('bdv.list.columns.status') }}</th>
              <th>{{ t('bdv.list.columns.requester') }}</th>
            </tr>
          </thead>

          <tbody>
            <tr v-if="!loading && rows.length === 0">
              <td
                colspan="9"
                class="text-center py-10 text-medium-emphasis"
              >
                {{ adaPenyaringAktif ? t('bdv.list.emptyFiltered') : t('bdv.list.empty') }}
              </td>
            </tr>

            <tr
              v-for="row in rows"
              :key="row.public_id"
            >
              <td class="font-weight-medium">
                <span v-if="row.bdv_number">{{ row.bdv_number }}</span>

                <span
                  v-else
                  class="text-disabled font-weight-regular"
                >
                  {{ t('bdv.list.notNumbered') }}
                </span>
              </td>

              <td>{{ tanggal(row.date) }}</td>

              <td>
                <VChip
                  size="small"
                  variant="tonal"
                  color="info"
                >
                  {{ row.document_type }}
                </VChip>
              </td>

              <td>{{ row.source_number ?? '-' }}</td>

              <td>{{ row.branch ?? '-' }}</td>

              <td>{{ row.department ?? '-' }}</td>

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
            </tr>
          </tbody>
        </VTable>

        <VDivider />

        <VCardText class="d-flex flex-wrap justify-space-between align-center gap-4">
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
    </template>
  </div>
</template>
