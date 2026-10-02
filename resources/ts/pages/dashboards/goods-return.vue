<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from '@axios'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { usePermissionStore } from '@/stores/permission'

/*
|--------------------------------------------------------------------------
| Dashboard Goods Return
|--------------------------------------------------------------------------
| Kerangka header, filter, dan aliran bacanya sengaja sama dengan dashboard
| PR, PO, dan Goods Receipt supaya keempatnya terasa satu keluarga. Isinya
| berbeda karena pertanyaannya berbeda: bukan "apa yang diminta", "apa yang
| dibeli", atau "apa yang sudah sampai", melainkan APA YANG TERNYATA TIDAK
| BISA DIPAKAI.
|
| Urutan bacanya menurun dari keputusan ke rincian:
|
|   1. Ringkasan     -- berapa yang balik, seberapa besar bagiannya, seberapa
|                       lama cacatnya baru ketahuan, berapa yang menggantung
|   2. Alasan        -- kenapa barangnya dikembalikan
|   3. Tren & status -- konteks jangka panjang
|   4. Vendor & item -- siapa dan apa yang bermasalah
|   5. Tindak lanjut -- draft yang menggantung hari ini
|   6. Sebaran       -- cabang dan department
|
| Warnanya bertumpu pada amber, mengikuti warna modulnya pada daftar
| dashboard, supaya jelas berbeda dari PR (ungu), PO (hijau), dan GR (cyan)
| tanpa harus membaca judulnya lebih dulu.
|
| Satu hal yang perlu diingat saat membaca angkanya: dokumen return hanya
| mencatat kuantitas, tidak menyimpan nilai. Setiap rupiah dihitung dari qty
| return dikali harga satuan pada baris PO-nya -- itu sebabnya qty selalu
| ditampilkan berdampingan dengan nilainya, bukan nilainya saja.
|--------------------------------------------------------------------------
*/

type PeriodType = 'day' | 'week' | 'month' | 'year' | 'range'

interface ValueTriple {
  count: number
  amount: number
  qty: number
}

interface StatusRow {
  status: string
  count: number
  amount: number
  qty: number
}

interface TrendPoint {
  date: string
  count: number
  amount: number
  qty: number
}

interface ReasonRow {
  name: string
  count: number
  qty: number
  amount: number
  share_percent: number
}

interface VendorRow {
  vendor_id: number | null
  name: string
  count: number
  qty: number
  amount: number
  received_amount: number
  return_rate_percent: number | null
}

interface ItemRow {
  name: string
  count: number
  document_count: number
  qty: number
  amount: number
}

interface DraftReturnRow {
  id: number
  number: string
  date: string | null
  cabang_name: string
  department_name: string
  vendor_name: string
  qty: number
  amount: number
  age_days: number
}

interface BreakdownRow {
  id: number | null
  name: string
  count: number
  amount: number
  qty: number
  posted_count: number
  draft_count: number
}

interface DashboardAccess {
  scope_view: string
  cabang_id: number | null
  cabang_name: string | null
  department_id: number | null
  department_name: string | null
  can_filter_cabang: boolean
  can_filter_department: boolean
  own_data_only: boolean
}

interface DashboardPayload {
  access: DashboardAccess | null

  filters: {
    aging_threshold_days: number
  }

  summary: {
    total: ValueTriple
    posted: ValueTriple
    draft: ValueTriple
    cancelled: ValueTriple
    received: ValueTriple
    return_rate_amount_percent: number
    return_rate_qty_percent: number
    average_detect_days: number | null
    item_count: number
  }

  statuses: StatusRow[]

  trend: {
    granularity: 'day' | 'week' | 'month'
    points: TrendPoint[]
  }

  reasons: ReasonRow[]
  vendors: VendorRow[]
  items: ItemRow[]

  attention: {
    threshold_days: number
    draft_returns: DraftReturnRow[]
  }

  breakdown: {
    by_cabang: BreakdownRow[]
    by_department: BreakdownRow[]
  }
}

const router = useRouter()
const { t } = useI18n()
const permissionStore = usePermissionStore()

const isCheckingPermission = ref(true)
const loading = ref(false)
const loadError = ref('')
const lastUpdated = ref<Date | null>(null)

const canView = computed(() => permissionStore.can('dashboard.goods-return.view'))

/*
|--------------------------------------------------------------------------
| Filter
|--------------------------------------------------------------------------
*/
const now = new Date()
const currentYear = now.getFullYear()

const pad = (value: number): string => String(value).padStart(2, '0')

const selectedPeriod = ref<PeriodType>('month')
const selectedDate = ref(`${currentYear}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`)
const selectedWeek = ref('')
const selectedMonth = ref(`${currentYear}-${pad(now.getMonth() + 1)}`)
const selectedYear = ref(currentYear)
const startDate = ref('')
const endDate = ref('')

const selectedCabang = ref<number | null>(null)
const selectedDepartment = ref<number | null>(null)

const cabangOptions = ref<Array<{ id: number; nama_cabang: string }>>([])
const departmentOptions = ref<Array<{ id: number; nama: string }>>([])

/*
 * Pilihan "semua" dijadikan item, bukan placeholder. Placeholder pada VSelect
 * bertumpuk dengan label-nya dan membuat kolom filter sulit dibaca.
 */
const cabangItems = computed(() => [
  { id: null as number | null, nama_cabang: t('dashboard.goodsReturn.filters.allBranches') },
  ...cabangOptions.value,
])

const departmentItems = computed(() => [
  { id: null as number | null, nama: t('dashboard.goodsReturn.filters.allDepartments') },
  ...departmentOptions.value,
])

const periodOptions = computed(() => [
  { title: t('dashboard.goodsReturn.filters.periodOptions.day'), value: 'day' },
  { title: t('dashboard.goodsReturn.filters.periodOptions.week'), value: 'week' },
  { title: t('dashboard.goodsReturn.filters.periodOptions.month'), value: 'month' },
  { title: t('dashboard.goodsReturn.filters.periodOptions.year'), value: 'year' },
  { title: t('dashboard.goodsReturn.filters.periodOptions.range'), value: 'range' },
])

const yearOptions = Array.from({ length: 10 }, (_, index) => currentYear - index)

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/
const access = ref<DashboardAccess | null>(null)
const thresholdDays = ref(3)
const trendGranularity = ref<'day' | 'week' | 'month'>('day')

const kosong = (): ValueTriple => ({ count: 0, amount: 0, qty: 0 })

const summary = ref({
  total: kosong(),
  posted: kosong(),
  draft: kosong(),
  cancelled: kosong(),
  received: kosong(),
  return_rate_amount_percent: 0,
  return_rate_qty_percent: 0,
  average_detect_days: null as number | null,
  item_count: 0,
})

const statuses = ref<StatusRow[]>([])
const trendPoints = ref<TrendPoint[]>([])
const reasons = ref<ReasonRow[]>([])
const vendors = ref<VendorRow[]>([])
const items = ref<ItemRow[]>([])
const draftReturns = ref<DraftReturnRow[]>([])
const byCabang = ref<BreakdownRow[]>([])
const byDepartment = ref<BreakdownRow[]>([])

/*
|--------------------------------------------------------------------------
| Format
|--------------------------------------------------------------------------
*/
const formatNumber = (value: number | null | undefined): string =>
  new Intl.NumberFormat('id-ID').format(Number(value ?? 0))

const formatDecimal = (value: number | null | undefined): string =>
  new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
  }).format(Number(value ?? 0))

const formatCurrency = (value: number | null | undefined): string =>
  new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Number(value ?? 0))

/** Nilai besar dipendekkan supaya kartu ringkasan tidak terpotong. */
const formatCompactCurrency = (value: number | null | undefined): string => {
  const amount = Number(value ?? 0)
  const abs = Math.abs(amount)

  const scale = (divisor: number, suffix: string): string => {
    const scaled = amount / divisor

    return `Rp ${new Intl.NumberFormat('id-ID', {
      minimumFractionDigits: 0,
      maximumFractionDigits: Math.abs(scaled) >= 100 ? 0 : 1,
    }).format(scaled)} ${suffix}`
  }

  if (abs >= 1_000_000_000_000)
    return scale(1_000_000_000_000, 'T')

  if (abs >= 1_000_000_000)
    return scale(1_000_000_000, 'M')

  if (abs >= 1_000_000)
    return scale(1_000_000, 'Jt')

  if (abs >= 1_000)
    return scale(1_000, 'Rb')

  return formatCurrency(amount)
}

const formatDate = (value: string | null | undefined): string => {
  if (!value)
    return '-'

  const parsed = new Date(value)

  return Number.isNaN(parsed.getTime())
    ? '-'
    : parsed.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

const formatTime = (value: Date | null): string =>
  value ? value.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : '-'

/*
| Label titik tren dirakit di sini, bukan di server: aplikasinya dua bahasa,
| dan label yang dirakit server akan terkunci pada satu bahasa saja.
*/
const trendLabel = (isoDate: string): string => {
  const parsed = new Date(isoDate)

  if (Number.isNaN(parsed.getTime()))
    return isoDate

  if (trendGranularity.value === 'month')
    return parsed.toLocaleDateString('id-ID', { month: 'short', year: '2-digit' })

  return parsed.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' })
}

/*
|--------------------------------------------------------------------------
| Turunan tampilan
|--------------------------------------------------------------------------
*/
/*
| Alasan yang tidak diisi datang sebagai "-" dari server. Tanda hubung
| tidak memberi tahu apa pun -- yang perlu dibereskan justru pengisiannya,
| dan itu yang dikatakan di sini.
*/
const reasonLabel = (name: string): string =>
  (name === '-' ? t('dashboard.goodsReturn.reason.unfilled') : name)

const statusMeta: Record<string, { key: string; color: string }> = {
  DRAFT: { key: 'draft', color: 'secondary' },
  POSTED: { key: 'posted', color: 'warning' },
  CANCELLED: { key: 'cancelled', color: 'error' },
}

const statusLabel = (status: string): string =>
  statusMeta[status] ? t(`dashboard.goodsReturn.status.${statusMeta[status].key}`) : status

const statusColor = (status: string): string => statusMeta[status]?.color ?? 'secondary'

/** Kosong sungguhan: tidak ada satu pun dokumen pada periode itu. */
const isEmptyPeriod = computed<boolean>(() => summary.value.total.count === 0)

const hasStatusData = computed<boolean>(() =>
  statuses.value.some(row => row.count > 0))

const hasTrendData = computed<boolean>(() =>
  trendPoints.value.some(point => point.count > 0))

/*
| Perbandingan diterima dan dikembalikan, dijadikan lebar batang. Yang
| dikembalikan hampir selalu jauh lebih kecil, jadi batangnya diberi lebar
| minimum supaya tetap terlihat -- kalau tidak, nilai kecil menjadi garis
| tipis yang tidak bisa dibaca.
*/
const returnedBarWidth = computed<number>(() => {
  const diterima = summary.value.received.amount
  const balik = summary.value.posted.amount

  if (diterima <= 0)
    return balik > 0 ? 100 : 0

  return Math.max(Math.min((balik / diterima) * 100, 100), balik > 0 ? 2 : 0)
})

const trendSeries = computed(() => [
  {
    name: t('dashboard.goodsReturn.chart.amount'),
    data: trendPoints.value.map(point => Number(point.amount ?? 0)),
  },
])

const trendOptions = computed(() => ({
  chart: {
    type: 'area',
    toolbar: { show: false },
    fontFamily: 'inherit',
    parentHeightOffset: 0,
  },
  colors: ['#f59e0b'],
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth', width: 2 },

  fill: {
    type: 'gradient',
    gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 95] },
  },

  grid: { borderColor: 'rgba(var(--v-border-color), 0.12)', strokeDashArray: 4 },
  xaxis: {
    categories: trendPoints.value.map(point => trendLabel(point.date)),
    labels: { rotate: -35, hideOverlappingLabels: true },
    axisTicks: { show: false },
    axisBorder: { show: false },
  },

  yaxis: {
    labels: { formatter: (value: number): string => formatCompactCurrency(value) },
  },

  tooltip: {
    y: { formatter: (value: number): string => formatCurrency(value) },
  },
}))

const statusSeries = computed(() => statuses.value.map(row => row.count))

const statusOptions = computed(() => ({
  chart: { type: 'donut', fontFamily: 'inherit' },
  labels: statuses.value.map(row => statusLabel(row.status)),
  colors: ['#94a3b8', '#f59e0b', '#ef4444'],
  legend: { position: 'bottom' },
  dataLabels: { enabled: true, formatter: (value: number): string => `${Math.round(value)}%` },
  stroke: { width: 0 },

  plotOptions: {
    pie: {
      donut: {
        size: '68%',
        labels: {
          show: true,
          total: {
            show: true,
            label: t('dashboard.goodsReturn.stats.documents'),
            formatter: (): string => formatNumber(summary.value.total.count),
          },
        },
      },
    },
  },
}))

const reasonSeries = computed(() => reasons.value.map(row => row.amount))

const reasonOptions = computed(() => ({
  chart: { type: 'donut', fontFamily: 'inherit' },
  labels: reasons.value.map(row => reasonLabel(row.name)),
  colors: ['#f59e0b', '#fb923c', '#f87171', '#a78bfa', '#60a5fa', '#34d399', '#94a3b8'],
  legend: { position: 'bottom' },
  stroke: { width: 0 },

  dataLabels: {
    enabled: true,
    formatter: (value: number): string => `${Math.round(value)}%`,
  },

  tooltip: {
    y: { formatter: (value: number): string => formatCurrency(value) },
  },

  plotOptions: {
    pie: { donut: { size: '62%' } },
  },
}))

/*
|--------------------------------------------------------------------------
| Pengambilan data
|--------------------------------------------------------------------------
*/
/**
 * Bagian parameter yang khas untuk setiap jenis periode.
 *
 * null berarti pilihannya belum lengkap -- pemanggil menahan permintaannya
 * dan menampilkan pesan, bukan mengirim filter setengah jadi ke server.
 */
const buildPeriodParams = (): Record<string, unknown> | null => {
  const perPeriode: Record<PeriodType, () => Record<string, unknown> | null> = {
    day: () => (selectedDate.value ? { date: selectedDate.value } : null),
    week: () => (selectedWeek.value ? { week: selectedWeek.value } : null),
    month: () => (selectedMonth.value ? { month: selectedMonth.value } : null),
    year: () => ({ year: selectedYear.value }),

    range: () => (startDate.value && endDate.value
      ? { start_date: startDate.value, end_date: endDate.value }
      : null),
  }

  return perPeriode[selectedPeriod.value]()
}

const buildParams = (): Record<string, unknown> | null => {
  const periodParams = buildPeriodParams()

  if (!periodParams)
    return null

  return {
    period: selectedPeriod.value,
    ...periodParams,
    cabang_id: selectedCabang.value ?? undefined,
    department_id: selectedDepartment.value ?? undefined,
  }
}

/*
 * Mengambil larik dari respons; bentuk tak terduga diperlakukan sebagai kosong.
 *
 * Ditulis sebagai function declaration, bukan arrow generic, karena parameter
 * tipe pada arrow function di dalam SFC terbaca sebagai tag oleh parser-nya.
 */
function asArray<T>(value: unknown): T[] {
  return Array.isArray(value) ? value as T[] : []
}

const applyDashboardResponse = (data: Partial<DashboardPayload>): void => {
  access.value = data.access ?? null
  thresholdDays.value = Number(data.attention?.threshold_days ?? 3)
  trendGranularity.value = data.trend?.granularity ?? 'day'

  summary.value = {
    total: data.summary?.total ?? kosong(),
    posted: data.summary?.posted ?? kosong(),
    draft: data.summary?.draft ?? kosong(),
    cancelled: data.summary?.cancelled ?? kosong(),
    received: data.summary?.received ?? kosong(),
    return_rate_amount_percent: Number(data.summary?.return_rate_amount_percent ?? 0),
    return_rate_qty_percent: Number(data.summary?.return_rate_qty_percent ?? 0),
    average_detect_days: data.summary?.average_detect_days ?? null,
    item_count: Number(data.summary?.item_count ?? 0),
  }

  statuses.value = asArray<StatusRow>(data.statuses)
  trendPoints.value = asArray<TrendPoint>(data.trend?.points)
  reasons.value = asArray<ReasonRow>(data.reasons)
  vendors.value = asArray<VendorRow>(data.vendors)
  items.value = asArray<ItemRow>(data.items)
  draftReturns.value = asArray<DraftReturnRow>(data.attention?.draft_returns)
  byCabang.value = asArray<BreakdownRow>(data.breakdown?.by_cabang)
  byDepartment.value = asArray<BreakdownRow>(data.breakdown?.by_department)
}

const fetchDashboard = async (): Promise<void> => {
  const params = buildParams()

  if (!params) {
    loadError.value = t('dashboard.goodsReturn.errors.periodInvalid')

    return
  }

  loading.value = true
  loadError.value = ''

  try {
    const response = await axios.get('/dashboard/goods-return', {
      params,
      headers: { Accept: 'application/json' },
    })

    applyDashboardResponse(response.data?.data ?? {})

    lastUpdated.value = new Date()
  }
  catch (error: unknown) {
    loadError.value = getApiErrorMessage(
      error,
      t('dashboard.goodsReturn.errors.dashboardFailed'),
    )
  }
  finally {
    loading.value = false
  }
}

/** Melebarkan periode ke satu tahun penuh, pintasan dari penanda kosong. */
const showWholeYear = async (): Promise<void> => {
  selectedPeriod.value = 'year'
  selectedYear.value = currentYear

  await fetchDashboard()
}

const fetchFilterOptions = async (): Promise<void> => {
  try {
    const [cabang, department] = await Promise.all([
      axios.get('/master/cabang/dropdown-select', { headers: { Accept: 'application/json' } }),
      axios.get('/master/department/dropdown-select', { headers: { Accept: 'application/json' } }),
    ])

    cabangOptions.value = Array.isArray(cabang.data?.data) ? cabang.data.data : []
    departmentOptions.value = Array.isArray(department.data?.data) ? department.data.data : []
  }
  catch {
    cabangOptions.value = []
    departmentOptions.value = []
  }
}

const goToGoodsReturn = (): void => {
  router.push('/non_stock/goods_return')
}

/** Kembali ke daftar modul dashboard, sama seperti tiga dashboard lainnya. */
const backToDashboard = (): void => {
  router.push('/dashboards/crm')
}

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canView.value) {
    await router.replace('/forbidden')

    return
  }

  isCheckingPermission.value = false

  await Promise.all([fetchFilterOptions(), fetchDashboard()])
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

  <section
    v-else
    class="grtdash"
  >
    <!-- KEPALA -->
    <VCard class="grtdash__header mb-6">
      <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 pa-6">
        <div class="d-flex align-center gap-4 min-w-0">
          <VBtn
            icon
            variant="tonal"
            color="secondary"
            size="small"
            @click="backToDashboard"
          >
            <VIcon icon="mdi-arrow-left" />
          </VBtn>

          <VAvatar
            size="52"
            color="warning"
            variant="tonal"
            rounded
          >
            <VIcon
              icon="mdi-package-variant-closed-minus"
              size="28"
            />
          </VAvatar>

          <div class="min-w-0">
            <div class="text-h5 font-weight-bold">
              {{ t('dashboard.goodsReturn.header.title') }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('dashboard.goodsReturn.header.description') }}
            </div>
          </div>
        </div>

        <div class="d-flex align-center gap-2 flex-wrap">
          <VChip
            v-if="access"
            size="small"
            variant="tonal"
            color="warning"
            prepend-icon="mdi-shield-account-outline"
          >
            {{ access.scope_view }}
          </VChip>

          <VChip
            size="small"
            variant="tonal"
            prepend-icon="mdi-clock-outline"
          >
            {{ t('dashboard.goodsReturn.header.lastUpdated') }} {{ formatTime(lastUpdated) }}
          </VChip>

          <VBtn
            icon
            variant="tonal"
            size="small"
            :loading="loading"
            @click="fetchDashboard"
          >
            <VIcon icon="mdi-refresh" />

            <VTooltip
              activator="parent"
              location="bottom"
            >
              {{ t('dashboard.goodsReturn.header.refresh') }}
            </VTooltip>
          </VBtn>

          <VBtn
            icon
            variant="tonal"
            size="small"
            @click="goToGoodsReturn"
          >
            <VIcon icon="mdi-open-in-new" />

            <VTooltip
              activator="parent"
              location="bottom"
            >
              {{ t('dashboard.goodsReturn.header.openModule') }}
            </VTooltip>
          </VBtn>
        </div>
      </VCardText>
    </VCard>

    <!-- FILTER -->
    <VCard class="mb-6">
      <VCardText class="pa-5">
        <VRow>
          <VCol
            cols="12"
            md="3"
          >
            <VSelect
              v-model="selectedPeriod"
              :items="periodOptions"
              item-title="title"
              item-value="value"
              :label="t('dashboard.goodsReturn.filters.periodType')"
              prepend-inner-icon="mdi-calendar-filter-outline"
              density="compact"
              hide-details
              @update:model-value="fetchDashboard"
            />
          </VCol>

          <VCol
            cols="12"
            md="3"
          >
            <VTextField
              v-if="selectedPeriod === 'day'"
              v-model="selectedDate"
              type="date"
              :label="t('dashboard.goodsReturn.filters.pickDate')"
              density="compact"
              hide-details
              @change="fetchDashboard"
            />

            <VTextField
              v-else-if="selectedPeriod === 'week'"
              v-model="selectedWeek"
              type="week"
              :label="t('dashboard.goodsReturn.filters.pickWeek')"
              density="compact"
              hide-details
              @change="fetchDashboard"
            />

            <VTextField
              v-else-if="selectedPeriod === 'month'"
              v-model="selectedMonth"
              type="month"
              :label="t('dashboard.goodsReturn.filters.pickMonth')"
              density="compact"
              hide-details
              @change="fetchDashboard"
            />

            <VSelect
              v-else-if="selectedPeriod === 'year'"
              v-model="selectedYear"
              :items="yearOptions"
              :label="t('dashboard.goodsReturn.filters.pickYear')"
              density="compact"
              hide-details
              @update:model-value="fetchDashboard"
            />

            <VTextField
              v-else
              v-model="startDate"
              type="date"
              :label="t('dashboard.goodsReturn.filters.startDate')"
              density="compact"
              hide-details
              @change="fetchDashboard"
            />
          </VCol>

          <VCol
            v-if="selectedPeriod === 'range'"
            cols="12"
            md="3"
          >
            <VTextField
              v-model="endDate"
              type="date"
              :label="t('dashboard.goodsReturn.filters.endDate')"
              density="compact"
              hide-details
              @change="fetchDashboard"
            />
          </VCol>

          <VCol
            v-if="access?.can_filter_cabang"
            cols="12"
            md="3"
          >
            <VSelect
              v-model="selectedCabang"
              :items="cabangItems"
              item-title="nama_cabang"
              item-value="id"
              :label="t('dashboard.goodsReturn.filters.branch')"
              density="compact"
              hide-details
              @update:model-value="fetchDashboard"
            />
          </VCol>

          <VCol
            v-if="access?.can_filter_department"
            cols="12"
            md="3"
          >
            <VSelect
              v-model="selectedDepartment"
              :items="departmentItems"
              item-title="nama"
              item-value="id"
              :label="t('dashboard.goodsReturn.filters.department')"
              density="compact"
              hide-details
              @update:model-value="fetchDashboard"
            />
          </VCol>
        </VRow>

        <div
          v-if="access && (!access.can_filter_cabang || !access.can_filter_department)"
          class="text-caption text-medium-emphasis mt-3"
        >
          <VIcon
            icon="mdi-information-outline"
            size="14"
            class="me-1"
          />
          {{ t('dashboard.goodsReturn.filters.scopeNote') }}
        </div>
      </VCardText>
    </VCard>

    <VAlert
      v-if="loadError"
      type="error"
      variant="tonal"
      class="mb-6"
    >
      {{ loadError }}
    </VAlert>

    <!--
      Periode tanpa satu pun dokumen. Disebut apa adanya dan diberi jalan
      keluar, bukan dibiarkan sebagai deretan angka nol yang membuat orang
      mengira dashboardnya rusak.
    -->
    <VCard
      v-if="!loading && isEmptyPeriod"
      class="mb-6"
    >
      <VCardText class="text-center pa-10">
        <VAvatar
          size="64"
          color="warning"
          variant="tonal"
          class="mb-4"
        >
          <VIcon
            icon="mdi-package-variant-closed-check"
            size="34"
          />
        </VAvatar>

        <div class="text-h6 font-weight-bold">
          {{ t('dashboard.goodsReturn.empty.title') }}
        </div>

        <div class="text-body-2 text-medium-emphasis mt-1 mb-4">
          {{ t('dashboard.goodsReturn.empty.description') }}
        </div>

        <VBtn
          color="warning"
          variant="tonal"
          class="text-none"
          prepend-icon="mdi-calendar-expand-horizontal"
          @click="showWholeYear"
        >
          {{ t('dashboard.goodsReturn.empty.showYear') }}
        </VBtn>
      </VCardText>
    </VCard>

    <template v-else>
      <!-- RINGKASAN -->
      <VRow class="mb-2">
        <VCol
          cols="12"
          sm="6"
          lg="3"
        >
          <VCard class="h-100">
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-body-2 text-medium-emphasis">
                  {{ t('dashboard.goodsReturn.stats.returnedTitle') }}
                </span>

                <VAvatar
                  size="34"
                  color="warning"
                  variant="tonal"
                  rounded
                >
                  <VIcon icon="mdi-package-variant-closed-minus" />
                </VAvatar>
              </div>

              <div class="text-h5 font-weight-bold">
                {{ formatCompactCurrency(summary.posted.amount) }}
              </div>

              <div class="text-caption text-medium-emphasis mt-1">
                {{ t('dashboard.goodsReturn.stats.returnedSubtitle', {
                  count: formatNumber(summary.posted.count),
                  qty: formatDecimal(summary.posted.qty),
                }) }}
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <!--
          Angka yang paling berarti di halaman ini. Sepuluh return sebulan
          berarti lain pada seratus penerimaan dan pada lima penerimaan.
        -->
        <VCol
          cols="12"
          sm="6"
          lg="3"
        >
          <VCard class="h-100">
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-body-2 text-medium-emphasis">
                  {{ t('dashboard.goodsReturn.stats.rateTitle') }}
                </span>

                <VAvatar
                  size="34"
                  color="error"
                  variant="tonal"
                  rounded
                >
                  <VIcon icon="mdi-percent-outline" />
                </VAvatar>
              </div>

              <div class="text-h5 font-weight-bold">
                {{ formatDecimal(summary.return_rate_amount_percent) }}%
              </div>

              <div class="text-caption text-medium-emphasis mt-1">
                {{ t('dashboard.goodsReturn.stats.rateSubtitle') }}
              </div>

              <div class="text-caption text-medium-emphasis">
                {{ t('dashboard.goodsReturn.stats.rateQty', {
                  percent: formatDecimal(summary.return_rate_qty_percent),
                }) }}
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          sm="6"
          lg="3"
        >
          <VCard class="h-100">
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-body-2 text-medium-emphasis">
                  {{ t('dashboard.goodsReturn.stats.detectTitle') }}
                </span>

                <VAvatar
                  size="34"
                  color="info"
                  variant="tonal"
                  rounded
                >
                  <VIcon icon="mdi-timer-sand" />
                </VAvatar>
              </div>

              <div class="text-h5 font-weight-bold">
                <template v-if="summary.average_detect_days !== null">
                  {{ formatDecimal(summary.average_detect_days) }}
                  <span class="text-body-1">{{ t('dashboard.goodsReturn.stats.days') }}</span>
                </template>

                <span
                  v-else
                  class="text-body-1 text-medium-emphasis"
                >
                  {{ t('dashboard.goodsReturn.stats.detectEmpty') }}
                </span>
              </div>

              <div class="text-caption text-medium-emphasis mt-1">
                {{ t('dashboard.goodsReturn.stats.detectSubtitle') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          sm="6"
          lg="3"
        >
          <VCard class="h-100">
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="text-body-2 text-medium-emphasis">
                  {{ t('dashboard.goodsReturn.stats.draftTitle') }}
                </span>

                <VAvatar
                  size="34"
                  color="secondary"
                  variant="tonal"
                  rounded
                >
                  <VIcon icon="mdi-file-clock-outline" />
                </VAvatar>
              </div>

              <div class="text-h5 font-weight-bold">
                {{ formatNumber(summary.draft.count) }}
                <span class="text-body-1">{{ t('dashboard.goodsReturn.stats.documents') }}</span>
              </div>

              <div class="text-caption text-medium-emphasis mt-1">
                {{ t('dashboard.goodsReturn.stats.draftSubtitle') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- DITERIMA DAN DIKEMBALIKAN -->
      <VRow class="mb-2">
        <VCol
          cols="12"
          lg="5"
        >
          <VCard class="h-100">
            <VCardItem>
              <VCardTitle>{{ t('dashboard.goodsReturn.comparison.title') }}</VCardTitle>
              <VCardSubtitle>{{ t('dashboard.goodsReturn.comparison.subtitle') }}</VCardSubtitle>
            </VCardItem>

            <VCardText class="pa-5 pt-0">
              <div class="d-flex align-center justify-space-between mb-1">
                <span class="text-body-2">{{ t('dashboard.goodsReturn.comparison.received') }}</span>
                <span class="font-weight-bold">{{ formatCompactCurrency(summary.received.amount) }}</span>
              </div>

              <div class="grtdash__bar mb-4">
                <div class="grtdash__bar-fill grtdash__bar-fill--received" />
              </div>

              <div class="d-flex align-center justify-space-between mb-1">
                <span class="text-body-2">{{ t('dashboard.goodsReturn.comparison.returned') }}</span>
                <span class="font-weight-bold text-warning">{{ formatCompactCurrency(summary.posted.amount) }}</span>
              </div>

              <div class="grtdash__bar">
                <div
                  class="grtdash__bar-fill grtdash__bar-fill--returned"
                  :style="{ inlineSize: `${returnedBarWidth}%` }"
                />
              </div>

              <div class="text-caption text-medium-emphasis mt-4">
                <VIcon
                  icon="mdi-information-outline"
                  size="14"
                  class="me-1"
                />
                {{ t('dashboard.goodsReturn.comparison.note') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <!-- ALASAN PENGEMBALIAN -->
        <VCol
          cols="12"
          lg="7"
        >
          <VCard class="h-100">
            <VCardItem>
              <VCardTitle>{{ t('dashboard.goodsReturn.reason.title') }}</VCardTitle>
              <VCardSubtitle>{{ t('dashboard.goodsReturn.reason.subtitle') }}</VCardSubtitle>
            </VCardItem>

            <VCardText class="pa-4">
              <div
                v-if="!reasons.length"
                class="text-body-2 text-medium-emphasis text-center py-8"
              >
                {{ t('dashboard.goodsReturn.reason.empty') }}
              </div>

              <VRow v-else>
                <VCol
                  cols="12"
                  md="5"
                >
                  <VueApexCharts
                    type="donut"
                    height="240"
                    :options="reasonOptions"
                    :series="reasonSeries"
                  />
                </VCol>

                <VCol
                  cols="12"
                  md="7"
                >
                  <div class="grtdash__scroll">
                    <VTable density="compact">
                      <thead>
                        <tr>
                          <th>{{ t('dashboard.goodsReturn.reason.columnReason') }}</th>
                          <th class="text-end">
                            {{ t('dashboard.goodsReturn.reason.columnQty') }}
                          </th>
                          <th class="text-end">
                            {{ t('dashboard.goodsReturn.reason.columnAmount') }}
                          </th>
                          <th class="text-end">
                            {{ t('dashboard.goodsReturn.reason.columnShare') }}
                          </th>
                        </tr>
                      </thead>

                      <tbody>
                        <tr
                          v-for="row in reasons"
                          :key="`reason-${row.name}`"
                        >
                          <td>{{ reasonLabel(row.name) }}</td>
                          <td class="text-end">
                            {{ formatDecimal(row.qty) }}
                          </td>
                          <td class="text-end">
                            {{ formatCurrency(row.amount) }}
                          </td>
                          <td class="text-end font-weight-medium">
                            {{ formatDecimal(row.share_percent) }}%
                          </td>
                        </tr>
                      </tbody>
                    </VTable>
                  </div>
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- TREN DAN STATUS -->
      <VRow class="mb-2">
        <VCol
          cols="12"
          lg="8"
        >
          <VCard class="h-100">
            <VCardItem>
              <VCardTitle>{{ t('dashboard.goodsReturn.chart.title') }}</VCardTitle>
              <VCardSubtitle>{{ t('dashboard.goodsReturn.chart.subtitle') }}</VCardSubtitle>
            </VCardItem>

            <VCardText class="pa-4">
              <div
                v-if="!hasTrendData"
                class="text-body-2 text-medium-emphasis text-center py-10"
              >
                {{ t('dashboard.goodsReturn.chart.empty') }}
              </div>

              <VueApexCharts
                v-else
                type="area"
                height="280"
                :options="trendOptions"
                :series="trendSeries"
              />
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          lg="4"
        >
          <VCard class="h-100">
            <VCardItem>
              <VCardTitle>{{ t('dashboard.goodsReturn.status.title') }}</VCardTitle>
              <VCardSubtitle>{{ t('dashboard.goodsReturn.status.subtitle') }}</VCardSubtitle>
            </VCardItem>

            <VCardText class="pa-4">
              <div
                v-if="!hasStatusData"
                class="text-body-2 text-medium-emphasis text-center py-10"
              >
                {{ t('dashboard.goodsReturn.status.empty') }}
              </div>

              <template v-else>
                <VueApexCharts
                  type="donut"
                  height="240"
                  :options="statusOptions"
                  :series="statusSeries"
                />

                <div class="d-flex flex-column gap-1 mt-3">
                  <div
                    v-for="row in statuses"
                    :key="`status-${row.status}`"
                    class="d-flex align-center justify-space-between text-body-2"
                  >
                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="statusColor(row.status)"
                    >
                      {{ statusLabel(row.status) }}
                    </VChip>

                    <span>
                      {{ formatNumber(row.count) }} · {{ formatCompactCurrency(row.amount) }}
                    </span>
                  </div>
                </div>

                <!--
                  Disebut di sini supaya angka "dibatalkan" tidak salah dibaca
                  sebagai barang yang tetap kembali ke vendor.
                -->
                <div class="text-caption text-medium-emphasis mt-3">
                  {{ t('dashboard.goodsReturn.status.cancelledNote') }}
                </div>
              </template>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- VENDOR DAN BARANG -->
      <VRow class="mb-2">
        <VCol
          cols="12"
          lg="7"
        >
          <VCard class="h-100">
            <VCardItem>
              <VCardTitle>{{ t('dashboard.goodsReturn.vendor.title') }}</VCardTitle>
              <VCardSubtitle>{{ t('dashboard.goodsReturn.vendor.subtitle') }}</VCardSubtitle>
            </VCardItem>

            <VCardText class="pa-0">
              <div
                v-if="!vendors.length"
                class="text-body-2 text-medium-emphasis text-center py-10"
              >
                {{ t('dashboard.goodsReturn.vendor.empty') }}
              </div>

              <div
                v-else
                class="grtdash__scroll"
              >
                <VTable density="compact">
                  <thead>
                    <tr>
                      <th>{{ t('dashboard.goodsReturn.vendor.columnVendor') }}</th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.vendor.columnCount') }}
                      </th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.vendor.columnQty') }}
                      </th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.vendor.columnAmount') }}
                      </th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.vendor.columnReceived') }}
                      </th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.vendor.columnRate') }}
                      </th>
                    </tr>
                  </thead>

                  <tbody>
                    <tr
                      v-for="row in vendors"
                      :key="`vendor-${row.vendor_id ?? row.name}`"
                    >
                      <td class="font-weight-medium">
                        {{ row.name }}
                      </td>
                      <td class="text-end">
                        {{ formatNumber(row.count) }}
                      </td>
                      <td class="text-end">
                        {{ formatDecimal(row.qty) }}
                      </td>
                      <td class="text-end">
                        {{ formatCurrency(row.amount) }}
                      </td>
                      <td class="text-end text-medium-emphasis">
                        {{ formatCompactCurrency(row.received_amount) }}
                      </td>
                      <td class="text-end">
                        <!--
                          Rasio hanya berarti bila vendornya memang mengirim
                          pada periode itu. Bila tidak, dikatakan apa adanya
                          daripada menampilkan angka yang menyesatkan.
                        -->
                        <VChip
                          v-if="row.return_rate_percent !== null"
                          size="x-small"
                          variant="tonal"
                          :color="row.return_rate_percent >= 10 ? 'error' : 'warning'"
                        >
                          {{ formatDecimal(row.return_rate_percent) }}%
                        </VChip>

                        <span
                          v-else
                          class="text-caption text-disabled"
                        >
                          {{ t('dashboard.goodsReturn.vendor.noReceipt') }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          lg="5"
        >
          <VCard class="h-100">
            <VCardItem>
              <VCardTitle>{{ t('dashboard.goodsReturn.item.title') }}</VCardTitle>
              <VCardSubtitle>{{ t('dashboard.goodsReturn.item.subtitle') }}</VCardSubtitle>
            </VCardItem>

            <VCardText class="pa-0">
              <div
                v-if="!items.length"
                class="text-body-2 text-medium-emphasis text-center py-10"
              >
                {{ t('dashboard.goodsReturn.item.empty') }}
              </div>

              <div
                v-else
                class="grtdash__scroll"
              >
                <VTable density="compact">
                  <thead>
                    <tr>
                      <th>{{ t('dashboard.goodsReturn.item.columnItem') }}</th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.item.columnDocuments') }}
                      </th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.item.columnQty') }}
                      </th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.item.columnAmount') }}
                      </th>
                    </tr>
                  </thead>

                  <tbody>
                    <tr
                      v-for="row in items"
                      :key="`item-${row.name}`"
                    >
                      <td class="font-weight-medium">
                        {{ row.name }}
                      </td>
                      <td class="text-end">
                        {{ formatNumber(row.document_count) }}
                      </td>
                      <td class="text-end">
                        {{ formatDecimal(row.qty) }}
                      </td>
                      <td class="text-end">
                        {{ formatCurrency(row.amount) }}
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- BUTUH TINDAK LANJUT -->
      <VCard class="mb-6">
        <VCardItem>
          <template #prepend>
            <VAvatar
              size="38"
              color="warning"
              variant="tonal"
              rounded
            >
              <VIcon icon="mdi-alert-outline" />
            </VAvatar>
          </template>

          <VCardTitle>{{ t('dashboard.goodsReturn.attention.title') }}</VCardTitle>
          <VCardSubtitle>{{ t('dashboard.goodsReturn.attention.subtitle') }}</VCardSubtitle>
        </VCardItem>

        <VCardText class="pa-0">
          <div
            v-if="!draftReturns.length"
            class="text-body-2 text-medium-emphasis text-center py-8"
          >
            {{ t('dashboard.goodsReturn.attention.empty') }}
          </div>

          <div
            v-else
            class="grtdash__scroll"
          >
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('dashboard.goodsReturn.attention.columnNumber') }}</th>
                  <th>{{ t('dashboard.goodsReturn.attention.columnVendor') }}</th>
                  <th>{{ t('dashboard.goodsReturn.attention.columnPlace') }}</th>
                  <th class="text-end">
                    {{ t('dashboard.goodsReturn.attention.columnAmount') }}
                  </th>
                  <th class="text-end">
                    {{ t('dashboard.goodsReturn.attention.columnAge') }}
                  </th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="row in draftReturns"
                  :key="`draft-${row.id}`"
                >
                  <td>
                    <div class="font-weight-medium">
                      {{ row.number }}
                    </div>

                    <div class="text-caption text-medium-emphasis">
                      {{ formatDate(row.date) }}
                    </div>
                  </td>

                  <td>{{ row.vendor_name }}</td>

                  <td class="text-caption">
                    {{ row.cabang_name }} · {{ row.department_name }}
                  </td>

                  <td class="text-end">
                    {{ formatCurrency(row.amount) }}
                  </td>

                  <td class="text-end">
                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="row.age_days >= thresholdDays ? 'error' : 'secondary'"
                    >
                      {{ formatNumber(row.age_days) }} {{ t('dashboard.goodsReturn.attention.days') }}
                    </VChip>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>

          <div class="text-caption text-medium-emphasis pa-4 pt-2">
            <VIcon
              icon="mdi-information-outline"
              size="14"
              class="me-1"
            />
            {{ t('dashboard.goodsReturn.attention.note') }}
          </div>
        </VCardText>
      </VCard>

      <!-- SEBARAN -->
      <VRow>
        <VCol
          v-for="blok in [
            { key: 'branch', title: t('dashboard.goodsReturn.breakdown.branchTitle'), rows: byCabang },
            { key: 'department', title: t('dashboard.goodsReturn.breakdown.departmentTitle'), rows: byDepartment },
          ]"
          :key="`breakdown-${blok.key}`"
          cols="12"
          lg="6"
        >
          <VCard class="h-100">
            <VCardItem>
              <VCardTitle>{{ blok.title }}</VCardTitle>
              <VCardSubtitle>{{ t('dashboard.goodsReturn.breakdown.subtitle') }}</VCardSubtitle>
            </VCardItem>

            <VCardText class="pa-0">
              <div
                v-if="!blok.rows.length"
                class="text-body-2 text-medium-emphasis text-center py-8"
              >
                {{ t('dashboard.goodsReturn.breakdown.empty') }}
              </div>

              <div
                v-else
                class="grtdash__scroll"
              >
                <VTable density="compact">
                  <thead>
                    <tr>
                      <th>{{ t('dashboard.goodsReturn.breakdown.columnName') }}</th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.breakdown.columnCount') }}
                      </th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.breakdown.columnPosted') }}
                      </th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.breakdown.columnDraft') }}
                      </th>
                      <th class="text-end">
                        {{ t('dashboard.goodsReturn.breakdown.columnAmount') }}
                      </th>
                    </tr>
                  </thead>

                  <tbody>
                    <tr
                      v-for="row in blok.rows"
                      :key="`${blok.key}-${row.id ?? row.name}`"
                    >
                      <td class="font-weight-medium">
                        {{ row.name }}
                      </td>
                      <td class="text-end">
                        {{ formatNumber(row.count) }}
                      </td>
                      <td class="text-end">
                        {{ formatNumber(row.posted_count) }}
                      </td>
                      <td class="text-end">
                        {{ formatNumber(row.draft_count) }}
                      </td>
                      <td class="text-end">
                        {{ formatCurrency(row.amount) }}
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </section>
</template>

<style scoped>
/*
| Keterangan kartu boleh turun ke baris berikutnya.
|
| Bawaan Vuetify memotongnya dengan elipsis, dan kalimat yang terpotong
| bukan kalimat -- yang membacanya tahu ada keterangan, tetapi tidak tahu
| apa isinya.
*/
.grtdash :deep(.v-card-subtitle) {
  overflow: visible;
  line-height: 1.45;
  text-overflow: unset;
  white-space: normal;
}

.grtdash__header {
  border-inline-start: 4px solid rgb(var(--v-theme-warning));
}

/* Batang perbandingan diterima dan dikembalikan. */
.grtdash__bar {
  overflow: hidden;
  block-size: 10px;
  border-radius: 999px;
  background: rgba(var(--v-theme-on-surface), 0.08);
}

.grtdash__bar-fill {
  block-size: 100%;
  border-radius: 999px;
}

.grtdash__bar-fill--received {
  background: rgb(var(--v-theme-info));
  inline-size: 100%;
}

.grtdash__bar-fill--returned {
  background: rgb(var(--v-theme-warning));
}

/* Tabelnya lebar; yang boleh menggeser hanya tabelnya, bukan halamannya. */
.grtdash__scroll {
  overflow-x: auto;
}
</style>

