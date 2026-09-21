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
| Dashboard Goods Receipt
|--------------------------------------------------------------------------
| Kerangka header, filter, dan aliran bacanya sengaja sama dengan dashboard
| PR dan PO supaya ketiganya terasa satu keluarga. Isinya berbeda karena
| pertanyaannya berbeda: bukan "apa yang diminta" atau "apa yang dibeli",
| melainkan APA YANG SUDAH SAMPAI -- dan apa yang belum.
|
| Urutan bacanya menurun dari keputusan ke rincian:
|
|   1. Ringkasan     -- berapa yang datang, berapa yang belum, berapa balik
|   2. Kinerja       -- seberapa penuh terpenuhi dan seberapa cepat
|   3. Tindak lanjut -- PO yang barangnya belum sampai dan draft menggantung
|   4. Tren & status -- konteks jangka panjang
|   5. Vendor        -- siapa yang mengirim, dan siapa yang bermasalah
|
| Warnanya bertumpu pada cyan supaya jelas berbeda dari PR (ungu) dan PO
| (hijau) tanpa harus membaca judulnya lebih dulu.
|--------------------------------------------------------------------------
*/

type PeriodType = 'day' | 'week' | 'month' | 'year' | 'range'

interface AmountPair {
  count: number
  amount: number
}

interface OutstandingSummary extends AmountPair {
  item_count: number
  fulfillment_percent: number
}

interface ReturnSummary extends AmountPair {
  qty: number
}

interface StatusRow {
  status: string
  count: number
  amount: number
}

interface TrendPoint {
  bucket: string
  label: string
  count: number
  posted_count: number
  amount: number
}

interface VendorRow {
  id: number | null
  name: string
  receipt_count: number
  posted_count: number
  amount: number
  qty: number
  average_lead_days: number | null
  return_count: number
  return_amount: number
  return_rate_percent: number
}

interface ReasonRow {
  name: string
  count: number
  qty: number
  amount: number
}

interface OutstandingPoRow {
  id: number
  number: string
  date: string | null
  vendor: string
  cabang: string
  department: string
  approved_at: string | null
  outstanding_amount: number
  received_percent: number
  idle_days: number
  is_overdue: boolean
}

interface DraftReceiptRow {
  id: number
  number: string
  date: string | null
  po_number: string
  vendor: string
  cabang: string
  department: string
  amount: number
  idle_days: number
  is_overdue: boolean
}

interface BreakdownRow {
  id: number | null
  name: string
  count: number
  amount: number
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
  access: DashboardAccess
  filters: { aging_threshold_days?: number }

  summary: {
    total: AmountPair
    posted: AmountPair
    draft: AmountPair
    partial: AmountPair
    outstanding: OutstandingSummary
    returns: ReturnSummary
    average_lead_days: number | null
    fulfillment_percent: number
    return_rate_percent: number
  }

  statuses: StatusRow[]
  trend: { granularity: string; points: TrendPoint[] }
  vendors: VendorRow[]
  return_reasons: ReasonRow[]

  attention: {
    threshold_days: number
    outstanding_po: OutstandingPoRow[]
    draft_receipts: DraftReceiptRow[]
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

const canView = computed(() => permissionStore.can('dashboard.gr.view'))

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
  { id: null as number | null, nama_cabang: t('dashboard.goodsReceipt.filters.allBranches') },
  ...cabangOptions.value,
])

const departmentItems = computed(() => [
  { id: null as number | null, nama: t('dashboard.goodsReceipt.filters.allDepartments') },
  ...departmentOptions.value,
])

const periodOptions = computed(() => [
  { title: t('dashboard.goodsReceipt.filters.periodOptions.day'), value: 'day' },
  { title: t('dashboard.goodsReceipt.filters.periodOptions.week'), value: 'week' },
  { title: t('dashboard.goodsReceipt.filters.periodOptions.month'), value: 'month' },
  { title: t('dashboard.goodsReceipt.filters.periodOptions.year'), value: 'year' },
  { title: t('dashboard.goodsReceipt.filters.periodOptions.range'), value: 'range' },
])

const yearOptions = Array.from({ length: 10 }, (_, index) => currentYear - index)

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/
const access = ref<DashboardAccess | null>(null)
const thresholdDays = ref(3)

const summary = ref({
  total: { count: 0, amount: 0 } as AmountPair,
  posted: { count: 0, amount: 0 } as AmountPair,
  draft: { count: 0, amount: 0 } as AmountPair,
  partial: { count: 0, amount: 0 } as AmountPair,
  outstanding: { count: 0, amount: 0, item_count: 0, fulfillment_percent: 0 } as OutstandingSummary,
  returns: { count: 0, amount: 0, qty: 0 } as ReturnSummary,
  average_lead_days: null as number | null,
  fulfillment_percent: 0,
  return_rate_percent: 0,
})

const statuses = ref<StatusRow[]>([])
const trendPoints = ref<TrendPoint[]>([])
const vendors = ref<VendorRow[]>([])
const returnReasons = ref<ReasonRow[]>([])
const outstandingPo = ref<OutstandingPoRow[]>([])
const draftReceipts = ref<DraftReceiptRow[]>([])
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
|--------------------------------------------------------------------------
| Turunan tampilan
|--------------------------------------------------------------------------
*/

const statusMeta: Record<string, { key: string; color: string }> = {
  DRAFT: { key: 'draft', color: 'secondary' },
  POSTED: { key: 'posted', color: 'success' },
  CANCELLED: { key: 'cancelled', color: 'error' },
}

const statusLabel = (status: string): string =>
  statusMeta[status] ? t(`dashboard.goodsReceipt.status.${statusMeta[status].key}`) : status

const statusColor = (status: string): string => statusMeta[status]?.color ?? 'secondary'

const totalStatusCount = computed(() =>
  statuses.value.reduce((sum, row) => sum + row.count, 0))

const statusPercent = (row: StatusRow): number =>
  totalStatusCount.value === 0 ? 0 : Math.round((row.count / totalStatusCount.value) * 100)

/*
 * Periode tanpa satu pun penerimaan. Dibedakan dari keadaan sedang memuat
 * supaya deretan angka nol tidak terbaca sebagai dashboard yang rusak.
 */
const isEmptyPeriod = computed(() =>
  !loading.value && !loadError.value && summary.value.total.count === 0)

const totalNeedsAction = computed(() =>
  outstandingPo.value.length + draftReceipts.value.length)

const overdueCount = computed(() =>
  outstandingPo.value.filter(row => row.is_overdue).length
  + draftReceipts.value.filter(row => row.is_overdue).length)

/** Nilai terbesar pada satu sebaran, dipakai sebagai acuan lebar bar. */
const breakdownMax = (rows: BreakdownRow[]): number =>
  rows.reduce((max, row) => Math.max(max, row.amount), 0) || 1

const vendorMax = computed(() =>
  vendors.value.reduce((max, row) => Math.max(max, row.amount), 0) || 1)

const reasonMax = computed(() =>
  returnReasons.value.reduce((max, row) => Math.max(max, row.count), 0) || 1)

/**
 * Warna tingkat pengembalian. Ambangnya sengaja rendah: barang yang kembali
 * berarti barang yang gagal dipakai, jadi 5% pun sudah pantas diperhatikan.
 */
const returnRateColor = (percent: number): string => {
  if (percent >= 10)
    return 'error'

  if (percent >= 5)
    return 'warning'

  return 'success'
}

/*
|--------------------------------------------------------------------------
| Grafik
|--------------------------------------------------------------------------
| Animasinya dinyalakan bertahap supaya batang dan garisnya tumbuh, bukan
| muncul tiba-tiba. Pengguna yang mematikan animasi di sistemnya tetap
| terlindungi lewat aturan prefers-reduced-motion pada bagian style.
|--------------------------------------------------------------------------
*/
const chartAnimations = {
  enabled: true,
  easing: 'easeinout',
  speed: 900,
  animateGradually: { enabled: true, delay: 150 },
  dynamicAnimation: { enabled: true, speed: 450 },
}

const trendSeries = computed(() => [
  {
    name: t('dashboard.goodsReceipt.chart.seriesCount'),
    type: 'column',
    data: trendPoints.value.map(point => point.count),
  },
  {
    name: t('dashboard.goodsReceipt.chart.seriesPosted'),
    type: 'column',
    data: trendPoints.value.map(point => point.posted_count),
  },
  {
    name: t('dashboard.goodsReceipt.chart.seriesAmount'),
    type: 'area',
    data: trendPoints.value.map(point => point.amount),
  },
])

const trendOptions = computed(() => ({
  chart: {
    type: 'line',
    stacked: false,
    toolbar: { show: false },
    zoom: { enabled: false },
    parentHeightOffset: 0,
    fontFamily: 'inherit',
    animations: chartAnimations,
  },
  colors: ['#00CFE8', '#28C76F', '#7367F0'],
  stroke: { width: [0, 0, 3], curve: 'smooth' },
  plotOptions: { bar: { borderRadius: 5, columnWidth: '52%' } },
  dataLabels: { enabled: false },
  legend: { position: 'top', horizontalAlign: 'left', markers: { radius: 12 } },
  grid: { borderColor: 'rgba(var(--v-border-color), 0.12)', strokeDashArray: 4 },

  /* Gradasi hanya pada deret nilai; batangnya dibiarkan solid agar tetap tegas. */
  fill: {
    type: ['solid', 'solid', 'gradient'],
    gradient: {
      shadeIntensity: 1,
      opacityFrom: 0.4,
      opacityTo: 0.05,
      stops: [0, 90, 100],
    },
  },

  xaxis: {
    categories: trendPoints.value.map(point => point.label),
    axisBorder: { show: false },
    axisTicks: { show: false },
  },

  yaxis: [
    {
      seriesName: t('dashboard.goodsReceipt.chart.seriesCount'),
      title: { text: t('dashboard.goodsReceipt.chart.axisCount') },
      labels: { formatter: (value: number) => formatNumber(value) },
    },
    {
      seriesName: t('dashboard.goodsReceipt.chart.seriesCount'),
      show: false,
    },
    {
      opposite: true,
      seriesName: t('dashboard.goodsReceipt.chart.seriesAmount'),
      title: { text: t('dashboard.goodsReceipt.chart.axisAmount') },
      labels: { formatter: (value: number) => formatCompactCurrency(value) },
    },
  ],

  tooltip: {
    shared: true,
    intersect: false,
    y: {
      formatter: (value: number, { seriesIndex }: { seriesIndex: number }) =>
        seriesIndex === 2 ? formatCurrency(value) : formatNumber(value),
    },
  },
}))

const statusSeries = computed(() => statuses.value.map(row => row.count))

const statusChartOptions = computed(() => ({
  chart: { type: 'donut', fontFamily: 'inherit', animations: chartAnimations },
  labels: statuses.value.map(row => statusLabel(row.status)),
  colors: ['#A8AAAE', '#28C76F', '#EA5455'],
  legend: { position: 'bottom', markers: { radius: 12 } },
  dataLabels: { enabled: false },
  stroke: { width: 0 },

  plotOptions: {
    pie: {
      donut: {
        size: '70%',
        labels: {
          show: true,
          total: {
            show: true,
            label: t('dashboard.goodsReceipt.status.totalLabel'),
            formatter: () => formatNumber(totalStatusCount.value),
          },
        },
      },
    },
  },

  tooltip: { y: { formatter: (value: number) => formatNumber(value) } },
}))

/*
 * Pengukur pemenuhan. Dipilih radial karena yang ingin dibaca adalah "sudah
 * seberapa penuh", bukan perbandingan antar periode.
 */
const fulfillmentSeries = computed(() => [summary.value.fulfillment_percent])

const fulfillmentOptions = computed(() => ({
  chart: { type: 'radialBar', fontFamily: 'inherit', animations: chartAnimations, sparkline: { enabled: true } },
  colors: ['#00CFE8'],
  labels: [t('dashboard.goodsReceipt.performance.fulfillmentLabel')],

  fill: {
    type: 'gradient',
    gradient: {
      shade: 'dark',
      type: 'horizontal',
      gradientToColors: ['#28C76F'],
      stops: [0, 100],
    },
  },

  stroke: { lineCap: 'round' },

  plotOptions: {
    radialBar: {
      hollow: { size: '62%' },
      track: { background: 'rgba(var(--v-theme-on-surface), 0.08)' },

      dataLabels: {
        name: {
          offsetY: 22,
          fontSize: '0.8rem',
          color: 'rgba(var(--v-theme-on-surface), 0.6)',
        },
        value: {
          offsetY: -14,
          fontSize: '1.6rem',
          fontWeight: 700,
          formatter: (value: number) => `${formatDecimal(value)}%`,
        },
      },
    },
  },
}))

/*
|--------------------------------------------------------------------------
| Permintaan data
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
    cabang_id: selectedCabang.value || undefined,
    department_id: selectedDepartment.value || undefined,
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
  thresholdDays.value = Number(data.filters?.aging_threshold_days ?? 3)

  const kosong: AmountPair = { count: 0, amount: 0 }

  summary.value = {
    total: data.summary?.total ?? kosong,
    posted: data.summary?.posted ?? kosong,
    draft: data.summary?.draft ?? kosong,
    partial: data.summary?.partial ?? kosong,

    outstanding: data.summary?.outstanding
      ?? { count: 0, amount: 0, item_count: 0, fulfillment_percent: 0 },

    returns: data.summary?.returns ?? { count: 0, amount: 0, qty: 0 },

    average_lead_days: data.summary?.average_lead_days ?? null,
    fulfillment_percent: Number(data.summary?.fulfillment_percent ?? 0),
    return_rate_percent: Number(data.summary?.return_rate_percent ?? 0),
  }

  statuses.value = asArray<StatusRow>(data.statuses)
  trendPoints.value = asArray<TrendPoint>(data.trend?.points)
  vendors.value = asArray<VendorRow>(data.vendors)
  returnReasons.value = asArray<ReasonRow>(data.return_reasons)
  outstandingPo.value = asArray<OutstandingPoRow>(data.attention?.outstanding_po)
  draftReceipts.value = asArray<DraftReceiptRow>(data.attention?.draft_receipts)
  byCabang.value = asArray<BreakdownRow>(data.breakdown?.by_cabang)
  byDepartment.value = asArray<BreakdownRow>(data.breakdown?.by_department)
}

const fetchDashboard = async (): Promise<void> => {
  const params = buildParams()

  if (!params) {
    loadError.value = t('dashboard.goodsReceipt.errors.periodInvalid')

    return
  }

  loading.value = true
  loadError.value = ''

  try {
    const response = await axios.get('/dashboard/goods-receipt', {
      params,
      headers: { Accept: 'application/json' },
    })

    applyDashboardResponse(response.data?.data ?? {})

    lastUpdated.value = new Date()
  }
  catch (error: unknown) {
    loadError.value = getApiErrorMessage(
      error,
      t('dashboard.goodsReceipt.errors.dashboardFailed'),
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

const goToGoodsReceipt = (): void => {
  router.push('/purchaseSupplier/goods-receipt')
}

/** Kembali ke daftar modul dashboard, sama seperti dashboard PR dan PO. */
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

  <section v-else>
    <!-- KEPALA -->
    <VCard class="grdash__header mb-6">
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
            color="info"
            variant="tonal"
            rounded
            class="grdash__badge"
          >
            <VIcon
              icon="mdi-package-variant-closed-check"
              size="28"
            />
          </VAvatar>

          <div class="min-w-0">
            <div class="text-h5 font-weight-bold">
              {{ t('dashboard.goodsReceipt.header.title') }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('dashboard.goodsReceipt.header.description') }}
            </div>
          </div>
        </div>

        <div class="d-flex align-center gap-2 flex-wrap">
          <VChip
            v-if="access"
            size="small"
            variant="tonal"
            color="info"
            prepend-icon="mdi-shield-account-outline"
          >
            {{ access.scope_view }}
          </VChip>

          <VChip
            size="small"
            variant="tonal"
            prepend-icon="mdi-clock-outline"
          >
            {{ t('dashboard.goodsReceipt.header.lastUpdated') }} {{ formatTime(lastUpdated) }}
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
              {{ t('dashboard.goodsReceipt.header.refresh') }}
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
              :label="t('dashboard.goodsReceipt.filters.periodType')"
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
              :label="t('dashboard.goodsReceipt.filters.pickDate')"
              density="compact"
              hide-details
              @change="fetchDashboard"
            />

            <VTextField
              v-else-if="selectedPeriod === 'week'"
              v-model="selectedWeek"
              type="week"
              :label="t('dashboard.goodsReceipt.filters.pickWeek')"
              density="compact"
              hide-details
              @change="fetchDashboard"
            />

            <VTextField
              v-else-if="selectedPeriod === 'month'"
              v-model="selectedMonth"
              type="month"
              :label="t('dashboard.goodsReceipt.filters.pickMonth')"
              density="compact"
              hide-details
              @change="fetchDashboard"
            />

            <VSelect
              v-else-if="selectedPeriod === 'year'"
              v-model="selectedYear"
              :items="yearOptions"
              :label="t('dashboard.goodsReceipt.filters.pickYear')"
              density="compact"
              hide-details
              @update:model-value="fetchDashboard"
            />

            <VTextField
              v-else
              v-model="startDate"
              type="date"
              :label="t('dashboard.goodsReceipt.filters.startDate')"
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
              :label="t('dashboard.goodsReceipt.filters.endDate')"
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
              :label="t('dashboard.goodsReceipt.filters.branch')"
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
              :label="t('dashboard.goodsReceipt.filters.department')"
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
          {{ t('dashboard.goodsReceipt.filters.scopeNote') }}
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
      Periode kosong diberi keterangan, bukan dibiarkan sebagai deretan nol.
      Nol yang tidak dijelaskan mudah disalahartikan sebagai gagal memuat.
    -->
    <VAlert
      v-if="isEmptyPeriod"
      type="info"
      variant="tonal"
      class="mb-6"
    >
      <div class="d-flex align-center justify-space-between flex-wrap gap-3">
        <div>
          <div class="font-weight-medium">
            {{ t('dashboard.goodsReceipt.empty.title') }}
          </div>

          <div class="text-body-2">
            {{ t('dashboard.goodsReceipt.empty.description') }}
          </div>
        </div>

        <VBtn
          v-if="selectedPeriod !== 'year'"
          size="small"
          variant="tonal"
          color="info"
          class="text-none"
          prepend-icon="mdi-calendar-expand-horizontal"
          @click="showWholeYear"
        >
          {{ t('dashboard.goodsReceipt.empty.showYear') }}
        </VBtn>
      </div>
    </VAlert>

    <!-- RINGKASAN -->
    <VRow class="mb-2">
      <VCol
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="grdash__kpi grdash__kpi--1 h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="grdash__kpi-label">{{ t('dashboard.goodsReceipt.stats.received') }}</span>

              <VAvatar
                size="34"
                color="success"
                variant="tonal"
                rounded
              >
                <VIcon
                  icon="mdi-package-variant-closed-check"
                  size="19"
                />
              </VAvatar>
            </div>

            <div class="grdash__kpi-value text-success">
              {{ formatNumber(summary.posted.count) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ formatCompactCurrency(summary.posted.amount) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!--
        Kartu paling actionable pada dashboard ini: uang sudah terikat pada PO
        tetapi barangnya belum ada. Diberi warna info supaya menonjol tanpa
        terbaca sebagai kesalahan.
      -->
      <VCol
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="grdash__kpi grdash__kpi--2 h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="grdash__kpi-label">{{ t('dashboard.goodsReceipt.stats.outstanding') }}</span>

              <VAvatar
                size="34"
                color="info"
                variant="tonal"
                rounded
              >
                <VIcon
                  icon="mdi-truck-delivery-outline"
                  size="19"
                />
              </VAvatar>
            </div>

            <div class="grdash__kpi-value text-info">
              {{ formatCompactCurrency(summary.outstanding.amount) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('dashboard.goodsReceipt.stats.outstandingHint', {
                po: formatNumber(summary.outstanding.count),
                items: formatNumber(summary.outstanding.item_count),
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
        <VCard class="grdash__kpi grdash__kpi--3 h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="grdash__kpi-label">{{ t('dashboard.goodsReceipt.stats.draft') }}</span>

              <VAvatar
                size="34"
                color="warning"
                variant="tonal"
                rounded
              >
                <VIcon
                  icon="mdi-file-clock-outline"
                  size="19"
                />
              </VAvatar>
            </div>

            <div class="grdash__kpi-value text-warning">
              {{ formatNumber(summary.draft.count) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('dashboard.goodsReceipt.stats.draftHint') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="grdash__kpi grdash__kpi--4 h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="grdash__kpi-label">{{ t('dashboard.goodsReceipt.stats.returned') }}</span>

              <VAvatar
                size="34"
                color="error"
                variant="tonal"
                rounded
              >
                <VIcon
                  icon="mdi-package-variant-closed-minus"
                  size="19"
                />
              </VAvatar>
            </div>

            <div class="grdash__kpi-value text-error">
              {{ formatNumber(summary.returns.count) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ formatCompactCurrency(summary.returns.amount) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- KINERJA PENERIMAAN -->
    <VRow class="mb-2">
      <VCol
        cols="12"
        md="4"
      >
        <VCard class="h-100">
          <VCardText class="pa-5 text-center">
            <div class="text-caption text-medium-emphasis text-uppercase mb-2">
              {{ t('dashboard.goodsReceipt.performance.fulfillmentTitle') }}
            </div>

            <VueApexCharts
              type="radialBar"
              height="200"
              :options="fulfillmentOptions"
              :series="fulfillmentSeries"
            />

            <div class="text-caption text-medium-emphasis mt-2">
              {{ t('dashboard.goodsReceipt.performance.fulfillmentHint') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="4"
      >
        <VCard class="h-100">
          <VCardText class="d-flex align-center gap-4 pa-5 h-100">
            <VAvatar
              size="44"
              color="info"
              variant="tonal"
              rounded
            >
              <VIcon
                icon="mdi-truck-fast-outline"
                size="24"
              />
            </VAvatar>

            <div class="min-w-0">
              <div class="text-caption text-medium-emphasis text-uppercase">
                {{ t('dashboard.goodsReceipt.performance.leadTitle') }}
              </div>

              <div class="text-h5 font-weight-bold">
                <template v-if="summary.average_lead_days === null">
                  —
                </template>

                <template v-else>
                  {{ formatDecimal(summary.average_lead_days) }}
                  <span class="text-body-1 font-weight-regular">
                    {{ t('dashboard.goodsReceipt.performance.days') }}
                  </span>
                </template>
              </div>

              <div class="text-caption text-medium-emphasis">
                {{ t('dashboard.goodsReceipt.performance.leadHint') }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="4"
      >
        <VCard class="h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <div class="text-caption text-medium-emphasis text-uppercase">
                {{ t('dashboard.goodsReceipt.performance.returnTitle') }}
              </div>

              <div
                class="text-h6 font-weight-bold"
                :class="`text-${returnRateColor(summary.return_rate_percent)}`"
              >
                {{ formatDecimal(summary.return_rate_percent) }}%
              </div>
            </div>

            <VProgressLinear
              :model-value="Math.min(summary.return_rate_percent, 100)"
              :color="returnRateColor(summary.return_rate_percent)"
              height="10"
              rounded
            />

            <div class="text-caption text-medium-emphasis mt-2">
              {{ t('dashboard.goodsReceipt.performance.returnHint', {
                amount: formatCompactCurrency(summary.returns.amount),
                received: formatCompactCurrency(summary.posted.amount),
              }) }}
            </div>

            <VDivider class="my-3" />

            <div class="d-flex align-center justify-space-between text-caption">
              <span class="text-medium-emphasis">
                {{ t('dashboard.goodsReceipt.performance.partialLabel') }}
              </span>

              <VChip
                size="x-small"
                variant="tonal"
                :color="summary.partial.count > 0 ? 'warning' : 'success'"
              >
                {{ formatNumber(summary.partial.count) }}
              </VChip>
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
            :color="overdueCount > 0 ? 'error' : 'success'"
            variant="tonal"
            rounded
          >
            <VIcon :icon="overdueCount > 0 ? 'mdi-alert-decagram-outline' : 'mdi-check-all'" />
          </VAvatar>
        </template>

        <VCardTitle>{{ t('dashboard.goodsReceipt.attention.title') }}</VCardTitle>

        <VCardSubtitle>
          {{ t('dashboard.goodsReceipt.attention.subtitle', { days: thresholdDays }) }}
        </VCardSubtitle>

        <template #append>
          <VChip
            :color="overdueCount > 0 ? 'error' : 'success'"
            variant="tonal"
            size="small"
          >
            {{ t('dashboard.goodsReceipt.attention.badge', {
              overdue: formatNumber(overdueCount),
              total: formatNumber(totalNeedsAction),
            }) }}
          </VChip>
        </template>
      </VCardItem>

      <VDivider />

      <VCardText class="pa-5">
        <VRow>
          <!-- PO yang barangnya belum sampai -->
          <VCol
            cols="12"
            lg="6"
          >
            <div class="grdash__section-title">
              <VIcon
                icon="mdi-truck-alert-outline"
                size="18"
              />
              {{ t('dashboard.goodsReceipt.attention.outstandingTitle') }}

              <VChip
                size="x-small"
                variant="tonal"
                class="ms-1"
              >
                {{ formatNumber(outstandingPo.length) }}
              </VChip>
            </div>

            <VAlert
              v-if="!outstandingPo.length"
              type="success"
              variant="tonal"
              density="compact"
            >
              {{ t('dashboard.goodsReceipt.attention.outstandingEmpty') }}
            </VAlert>

            <div
              v-else
              class="d-flex flex-column gap-2"
            >
              <div
                v-for="row in outstandingPo"
                :key="`po-${row.id}`"
                class="grdash__item"
                :class="{ 'grdash__item--overdue': row.is_overdue }"
              >
                <div class="d-flex align-center justify-space-between gap-3 flex-wrap">
                  <div class="min-w-0">
                    <div class="font-weight-medium text-truncate">
                      {{ row.number }}
                    </div>

                    <div class="text-caption text-medium-emphasis text-truncate">
                      {{ row.vendor }} &middot; {{ row.cabang }}
                    </div>
                  </div>

                  <div class="text-end">
                    <div class="font-weight-medium text-info">
                      {{ formatCompactCurrency(row.outstanding_amount) }}
                    </div>

                    <div class="text-caption text-medium-emphasis">
                      {{ formatDate(row.date) }}
                    </div>
                  </div>
                </div>

                <!-- Sejauh mana pesanan itu sudah terpenuhi. -->
                <VProgressLinear
                  :model-value="row.received_percent"
                  color="success"
                  height="6"
                  rounded
                  class="mt-2"
                />

                <div class="d-flex align-center justify-space-between gap-2 mt-2 flex-wrap">
                  <span class="text-caption text-medium-emphasis">
                    {{ t('dashboard.goodsReceipt.attention.receivedPercent', {
                      percent: formatDecimal(row.received_percent),
                    }) }}
                  </span>

                  <VChip
                    size="x-small"
                    variant="flat"
                    :color="row.is_overdue ? 'error' : 'info'"
                  >
                    {{ t('dashboard.goodsReceipt.attention.idleDays', {
                      days: formatDecimal(row.idle_days),
                    }) }}
                  </VChip>
                </div>
              </div>
            </div>
          </VCol>

          <!-- Penerimaan yang masih draft -->
          <VCol
            cols="12"
            lg="6"
          >
            <div class="grdash__section-title">
              <VIcon
                icon="mdi-file-clock-outline"
                size="18"
              />
              {{ t('dashboard.goodsReceipt.attention.draftTitle') }}

              <VChip
                size="x-small"
                variant="tonal"
                class="ms-1"
              >
                {{ formatNumber(draftReceipts.length) }}
              </VChip>
            </div>

            <VAlert
              v-if="!draftReceipts.length"
              type="success"
              variant="tonal"
              density="compact"
            >
              {{ t('dashboard.goodsReceipt.attention.draftEmpty') }}
            </VAlert>

            <div
              v-else
              class="d-flex flex-column gap-2"
            >
              <div
                v-for="row in draftReceipts"
                :key="`draft-${row.id}`"
                class="grdash__item"
                :class="{ 'grdash__item--overdue': row.is_overdue }"
              >
                <div class="d-flex align-center justify-space-between gap-3 flex-wrap">
                  <div class="min-w-0">
                    <div class="font-weight-medium text-truncate">
                      {{ row.number }}
                    </div>

                    <div class="text-caption text-medium-emphasis text-truncate">
                      {{ row.po_number }} &middot; {{ row.vendor }}
                    </div>
                  </div>

                  <div class="text-end">
                    <div class="font-weight-medium">
                      {{ formatCompactCurrency(row.amount) }}
                    </div>

                    <div class="text-caption text-medium-emphasis">
                      {{ formatDate(row.date) }}
                    </div>
                  </div>
                </div>

                <div class="d-flex justify-end mt-2">
                  <VChip
                    size="x-small"
                    variant="flat"
                    :color="row.is_overdue ? 'error' : 'warning'"
                  >
                    {{ t('dashboard.goodsReceipt.attention.draftDays', {
                      days: formatDecimal(row.idle_days),
                    }) }}
                  </VChip>
                </div>
              </div>
            </div>
          </VCol>
        </VRow>

        <div class="d-flex justify-end mt-4">
          <VBtn
            variant="tonal"
            color="info"
            size="small"
            class="text-none"
            append-icon="mdi-arrow-right"
            @click="goToGoodsReceipt"
          >
            {{ t('dashboard.goodsReceipt.attention.openModule') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>

    <!-- TREN DAN STATUS -->
    <VRow class="mb-2">
      <VCol
        cols="12"
        lg="8"
      >
        <VCard class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('dashboard.goodsReceipt.chart.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('dashboard.goodsReceipt.chart.subtitle') }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText class="pa-4">
            <div
              v-if="!trendPoints.length"
              class="d-flex align-center justify-center text-medium-emphasis"
              style="min-height: 320px;"
            >
              {{ t('dashboard.goodsReceipt.chart.empty') }}
            </div>

            <VueApexCharts
              v-else
              type="line"
              height="340"
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
            <VCardTitle>{{ t('dashboard.goodsReceipt.status.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('dashboard.goodsReceipt.status.subtitle') }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText class="pa-4">
            <div
              v-if="!totalStatusCount"
              class="d-flex align-center justify-center text-medium-emphasis"
              style="min-height: 260px;"
            >
              {{ t('dashboard.goodsReceipt.status.empty') }}
            </div>

            <template v-else>
              <VueApexCharts
                type="donut"
                height="260"
                :options="statusChartOptions"
                :series="statusSeries"
              />

              <div class="d-flex flex-column gap-2 mt-3">
                <div
                  v-for="row in statuses"
                  :key="`status-${row.status}`"
                  class="d-flex align-center justify-space-between gap-2"
                >
                  <VChip
                    size="x-small"
                    variant="tonal"
                    :color="statusColor(row.status)"
                  >
                    {{ statusLabel(row.status) }}
                  </VChip>

                  <div class="text-caption text-medium-emphasis">
                    {{ formatNumber(row.count) }} &middot; {{ statusPercent(row) }}%
                  </div>
                </div>
              </div>
            </template>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!--
      KINERJA VENDOR
      Bagian yang paling khas Goods Receipt: yang dinilai bukan dokumennya,
      melainkan pihak yang mengirim.
    -->
    <VCard class="mb-6">
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="info"
            variant="tonal"
            rounded
          >
            <VIcon icon="mdi-store-check-outline" />
          </VAvatar>
        </template>

        <VCardTitle>{{ t('dashboard.goodsReceipt.vendor.title') }}</VCardTitle>
        <VCardSubtitle>{{ t('dashboard.goodsReceipt.vendor.subtitle') }}</VCardSubtitle>
      </VCardItem>

      <VDivider />

      <VCardText class="pa-5">
        <VAlert
          v-if="!vendors.length"
          type="info"
          variant="tonal"
          density="compact"
        >
          {{ t('dashboard.goodsReceipt.vendor.empty') }}
        </VAlert>

        <div
          v-else
          class="d-flex flex-column gap-4"
        >
          <div
            v-for="row in vendors"
            :key="`vendor-${row.id ?? row.name}`"
          >
            <div class="d-flex align-center justify-space-between gap-3 flex-wrap mb-1">
              <div class="d-flex align-center gap-2 min-w-0">
                <VAvatar
                  size="28"
                  color="info"
                  variant="tonal"
                >
                  <VIcon
                    icon="mdi-storefront-outline"
                    size="16"
                  />
                </VAvatar>

                <span class="font-weight-medium text-truncate">{{ row.name }}</span>
              </div>

              <div class="d-flex align-center gap-2 flex-wrap">
                <VChip
                  size="x-small"
                  variant="tonal"
                  prepend-icon="mdi-package-variant-closed"
                >
                  {{ t('dashboard.goodsReceipt.vendor.receiptCount', {
                    count: formatNumber(row.receipt_count),
                  }) }}
                </VChip>

                <VChip
                  v-if="row.average_lead_days !== null"
                  size="x-small"
                  variant="tonal"
                  color="info"
                  prepend-icon="mdi-truck-fast-outline"
                >
                  {{ t('dashboard.goodsReceipt.vendor.leadDays', {
                    days: formatDecimal(row.average_lead_days),
                  }) }}
                </VChip>

                <VChip
                  v-if="row.return_count > 0"
                  size="x-small"
                  variant="flat"
                  :color="returnRateColor(row.return_rate_percent)"
                  prepend-icon="mdi-keyboard-return"
                >
                  {{ formatDecimal(row.return_rate_percent) }}%
                </VChip>

                <span class="text-caption text-medium-emphasis">
                  {{ formatCompactCurrency(row.amount) }}
                </span>
              </div>
            </div>

            <VProgressLinear
              :model-value="(row.amount / vendorMax) * 100"
              color="info"
              height="8"
              rounded
            />
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- SEBARAN -->
    <VRow>
      <VCol
        cols="12"
        lg="6"
      >
        <VCard class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('dashboard.goodsReceipt.breakdown.branchTitle') }}</VCardTitle>
            <VCardSubtitle>{{ t('dashboard.goodsReceipt.breakdown.subtitle') }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText class="pa-5">
            <VAlert
              v-if="!byCabang.length"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('dashboard.goodsReceipt.breakdown.empty') }}
            </VAlert>

            <div
              v-else
              class="d-flex flex-column gap-3"
            >
              <div
                v-for="row in byCabang"
                :key="`cabang-${row.id ?? row.name}`"
              >
                <div class="d-flex align-center justify-space-between gap-2 mb-1">
                  <span class="font-weight-medium text-truncate">{{ row.name }}</span>

                  <span class="text-caption text-medium-emphasis">
                    {{ t('dashboard.goodsReceipt.breakdown.count', {
                      count: formatNumber(row.count),
                    }) }} &middot; {{ formatCompactCurrency(row.amount) }}
                  </span>
                </div>

                <VProgressLinear
                  :model-value="(row.amount / breakdownMax(byCabang)) * 100"
                  color="info"
                  height="8"
                  rounded
                />
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        lg="6"
      >
        <VCard class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('dashboard.goodsReceipt.breakdown.departmentTitle') }}</VCardTitle>
            <VCardSubtitle>{{ t('dashboard.goodsReceipt.breakdown.subtitle') }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText class="pa-5">
            <VAlert
              v-if="!byDepartment.length"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('dashboard.goodsReceipt.breakdown.empty') }}
            </VAlert>

            <div
              v-else
              class="d-flex flex-column gap-3"
            >
              <div
                v-for="row in byDepartment"
                :key="`dept-${row.id ?? row.name}`"
              >
                <div class="d-flex align-center justify-space-between gap-2 mb-1">
                  <span class="font-weight-medium text-truncate">{{ row.name }}</span>

                  <span class="text-caption text-medium-emphasis">
                    {{ t('dashboard.goodsReceipt.breakdown.count', {
                      count: formatNumber(row.count),
                    }) }} &middot; {{ formatCompactCurrency(row.amount) }}
                  </span>
                </div>

                <VProgressLinear
                  :model-value="(row.amount / breakdownMax(byDepartment)) * 100"
                  color="primary"
                  height="8"
                  rounded
                />

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('dashboard.goodsReceipt.breakdown.detail', {
                    posted: formatNumber(row.posted_count),
                    draft: formatNumber(row.draft_count),
                  }) }}
                </div>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!--
        Alasan pengembalian. Diletakkan paling bawah karena sifatnya diagnosis:
        dibaca setelah terlihat bahwa angka returnnya memang tinggi.
      -->
      <VCol cols="12">
        <VCard>
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="error"
                variant="tonal"
                rounded
              >
                <VIcon icon="mdi-clipboard-alert-outline" />
              </VAvatar>
            </template>

            <VCardTitle>{{ t('dashboard.goodsReceipt.reason.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('dashboard.goodsReceipt.reason.subtitle') }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText class="pa-5">
            <VAlert
              v-if="!returnReasons.length"
              type="success"
              variant="tonal"
              density="compact"
            >
              {{ t('dashboard.goodsReceipt.reason.empty') }}
            </VAlert>

            <VRow v-else>
              <VCol
                v-for="row in returnReasons"
                :key="`reason-${row.name}`"
                cols="12"
                sm="6"
                md="4"
              >
                <div class="grdash__item h-100">
                  <div class="text-caption text-medium-emphasis text-uppercase text-truncate">
                    {{ row.name }}
                  </div>

                  <div class="d-flex align-center justify-space-between gap-2 mt-1">
                    <div class="text-h6 font-weight-bold">
                      {{ t('dashboard.goodsReceipt.reason.count', {
                        count: formatNumber(row.count),
                      }) }}
                    </div>

                    <span class="text-body-2 text-medium-emphasis">
                      {{ formatCompactCurrency(row.amount) }}
                    </span>
                  </div>

                  <VProgressLinear
                    :model-value="(row.count / reasonMax) * 100"
                    color="error"
                    height="6"
                    rounded
                    class="mt-2"
                  />
                </div>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </section>
</template>

<style scoped>
/* Cyan dipilih supaya halaman ini langsung terbedakan dari PR dan PO. */
.grdash__header {
  background: linear-gradient(135deg, rgba(var(--v-theme-info), 0.14), transparent 62%);
}

/* Denyut halus pada lencana kepala, sekadar penanda halaman yang hidup. */
.grdash__badge {
  animation: grdash-pulse 3.2s ease-in-out infinite;
}

@keyframes grdash-pulse {
  0%,
  100% {
    box-shadow: 0 0 0 0 rgba(var(--v-theme-info), 0.32);
  }

  50% {
    box-shadow: 0 0 0 10px rgba(var(--v-theme-info), 0);
  }
}

.grdash__kpi-label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.grdash__kpi-value {
  font-size: 1.75rem;
  font-weight: 700;
  line-height: 1.2;
}

/*
 * Kartu ringkasan muncul bergiliran dari bawah, bukan serempak. Jeda antar
 * kartu dibuat pendek supaya terbaca sebagai satu gerakan, bukan antrean.
 */
.grdash__kpi {
  animation: grdash-rise 0.45s ease-out both;
  transition: box-shadow 0.25s ease, transform 0.25s ease;
}

.grdash__kpi--1 { animation-delay: 0.02s; }
.grdash__kpi--2 { animation-delay: 0.09s; }
.grdash__kpi--3 { animation-delay: 0.16s; }
.grdash__kpi--4 { animation-delay: 0.23s; }

.grdash__kpi:hover {
  box-shadow: 0 8px 22px rgba(var(--v-theme-on-surface), 0.10);
  transform: translateY(-3px);
}

@keyframes grdash-rise {
  from {
    opacity: 0;
    transform: translateY(14px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.grdash__section-title {
  display: flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.85);
  font-size: 0.9rem;
  font-weight: 700;
  gap: 0.5rem;
  margin-block-end: 0.75rem;
}

.grdash__section-title .v-icon {
  color: rgba(var(--v-theme-info), 0.85);
}

/* Kartu ringkas untuk satu dokumen atau satu kelompok angka. */
.grdash__item {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  padding-block: 0.75rem;
  padding-inline: 0.875rem;
  transition: border-color 0.2s ease, background 0.2s ease;
}

.grdash__item:hover {
  border-color: rgba(var(--v-theme-info), 0.45);
  background: rgba(var(--v-theme-info), 0.03);
}

/*
 * Melewati ambang hari. Ditandai lewat garis tepi kiri, bukan latar penuh,
 * supaya deretan panjang tidak berubah jadi blok merah yang sulit dibaca.
 */
.grdash__item--overdue {
  border-inline-start: 3px solid rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.04);
}

/* Pengguna yang meminta gerakan minimal tidak dipaksa melihat animasinya. */
@media (prefers-reduced-motion: reduce) {
  .grdash__badge,
  .grdash__kpi {
    animation: none;
  }

  .grdash__kpi:hover {
    transform: none;
  }
}
</style>
