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
| Dashboard Purchase Requisition
|--------------------------------------------------------------------------
| Disusun untuk manajemen, bukan operator. Urutan bacanya sengaja dibuat
| menurun dari keputusan ke rincian:
|
|   1. Ringkasan  -- berapa banyak, berapa nilainya, seberapa lancar
|   2. Tindak lanjut -- mana yang tertahan dan mana yang belum jadi PO
|   3. Penumpukan approval -- prosesnya macet di tahap mana
|   4. Tren dan sebaran -- konteks jangka panjang dan asal permintaannya
|
| Bagian "tindak lanjut" diletakkan tinggi karena itulah yang bisa langsung
| dikerjakan; grafik ada di bawahnya sebagai konteks, bukan sebaliknya.
|--------------------------------------------------------------------------
*/

type PeriodType = 'day' | 'week' | 'month' | 'year' | 'range'

interface AmountPair {
  count: number
  amount: number
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
  approved_count: number
  amount: number
}

interface BottleneckRow {
  step_label: string
  step_order: number
  count: number
  amount: number
  average_waiting_days: number
  longest_waiting_days: number
}

interface StuckRow {
  id: number
  number: string
  date: string | null
  amount: number
  cabang: string
  department: string
  step_label: string
  waiting_since: string | null
  waiting_days: number
  is_overdue: boolean
}

interface AwaitingPoRow {
  id: number
  number: string
  date: string | null
  amount: number
  cabang: string
  department: string
  approved_at: string | null
  idle_days: number
  is_overdue: boolean
}

interface BreakdownRow {
  id: number | null
  name: string
  count: number
  amount: number
  waiting_count: number
  approved_count: number
}

interface TypeRow {
  name: string
  count: number
  amount: number
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

/**
 * Bentuk isi respons dashboard.
 *
 * Seluruh bagiannya diperlakukan opsional saat dibaca: kalau backend belum
 * mengirim salah satunya, halaman tetap tampil dengan nilai kosong alih-alih
 * gagal render.
 */
interface DashboardPayload {
  access: DashboardAccess | null

  filters: {
    aging_threshold_days?: number
  }

  summary: {
    total?: AmountPair
    official?: AmountPair
    waiting_approval?: AmountPair
    approved?: AmountPair
    not_followed_up?: AmountPair
    average_approval_days?: number | null
    approval_rate_percent?: number
  }

  statuses: StatusRow[]
  trend: { granularity?: string; points?: TrendPoint[] }
  approval_bottleneck: BottleneckRow[]
  attention: { stuck_approval?: StuckRow[]; awaiting_po?: AwaitingPoRow[] }

  breakdown: {
    by_cabang?: BreakdownRow[]
    by_department?: BreakdownRow[]
    by_type?: TypeRow[]
  }
}

const router = useRouter()
const { t } = useI18n()
const permissionStore = usePermissionStore()

const isCheckingPermission = ref(true)
const loading = ref(false)
const loadError = ref('')
const lastUpdated = ref<Date | null>(null)

const canView = computed(() => permissionStore.can('dashboard.pr.view'))

/*
|--------------------------------------------------------------------------
| Filter periode
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
 * Pilihan "Semua" dijadikan item, bukan placeholder. VSelect menaruh
 * placeholder pada posisi yang sama dengan label saat nilainya kosong,
 * sehingga keduanya saling menumpuk.
 */
const cabangItems = computed(() => [
  { id: null, nama_cabang: t('dashboard.purchaseRequest.filters.allBranches') },
  ...cabangOptions.value,
])

const departmentItems = computed(() => [
  { id: null, nama: t('dashboard.purchaseRequest.filters.allDepartments') },
  ...departmentOptions.value,
])

const periodOptions = computed(() => [
  { title: t('dashboard.purchaseRequest.filters.periodOptions.day'), value: 'day' },
  { title: t('dashboard.purchaseRequest.filters.periodOptions.week'), value: 'week' },
  { title: t('dashboard.purchaseRequest.filters.periodOptions.month'), value: 'month' },
  { title: t('dashboard.purchaseRequest.filters.periodOptions.year'), value: 'year' },
  { title: t('dashboard.purchaseRequest.filters.periodOptions.range'), value: 'range' },
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
  official: { count: 0, amount: 0 } as AmountPair,
  waiting_approval: { count: 0, amount: 0 } as AmountPair,
  approved: { count: 0, amount: 0 } as AmountPair,
  not_followed_up: { count: 0, amount: 0 } as AmountPair,
  average_approval_days: null as number | null,
  approval_rate_percent: 0,
})

const statuses = ref<StatusRow[]>([])
const trendPoints = ref<TrendPoint[]>([])
const bottleneck = ref<BottleneckRow[]>([])
const stuckApprovals = ref<StuckRow[]>([])
const awaitingPo = ref<AwaitingPoRow[]>([])
const byCabang = ref<BreakdownRow[]>([])
const byDepartment = ref<BreakdownRow[]>([])
const byType = ref<TypeRow[]>([])

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

const statusMeta: Record<string, { label: string; color: string }> = {
  'DRAFT': { label: 'Draft', color: 'secondary' },
  'IN PROGRESS': { label: 'Menunggu Approval', color: 'warning' },
  'APPROVED': { label: 'Disetujui', color: 'success' },
  'REJECTED': { label: 'Ditolak', color: 'error' },
  'CANCELLED': { label: 'Dibatalkan', color: 'error' },
}

const statusLabel = (status: string): string => statusMeta[status]?.label ?? status
const statusColor = (status: string): string => statusMeta[status]?.color ?? 'secondary'

const totalStatusCount = computed(() =>
  statuses.value.reduce((sum, row) => sum + row.count, 0))

const statusPercent = (row: StatusRow): number =>
  totalStatusCount.value === 0 ? 0 : Math.round((row.count / totalStatusCount.value) * 100)

/*
 * Periode tanpa satu pun PR. Dibedakan dari keadaan sedang memuat supaya
 * deretan angka nol tidak terbaca sebagai dashboard yang rusak.
 */
const isEmptyPeriod = computed(() =>
  !loading.value && !loadError.value && summary.value.total.count === 0)

/** Jumlah dokumen yang benar-benar menunggu tindakan seseorang. */
const totalNeedsAction = computed(() =>
  stuckApprovals.value.length + awaitingPo.value.length)

const overdueCount = computed(() =>
  stuckApprovals.value.filter(row => row.is_overdue).length
  + awaitingPo.value.filter(row => row.is_overdue).length)

/** Baris terberat pada penumpukan approval, dipakai untuk lebar bar. */
const bottleneckMax = computed(() =>
  bottleneck.value.reduce((max, row) => Math.max(max, row.count), 0) || 1)

const breakdownMax = (rows: BreakdownRow[]): number =>
  rows.reduce((max, row) => Math.max(max, row.amount), 0) || 1

/*
|--------------------------------------------------------------------------
| Grafik
|--------------------------------------------------------------------------
| Dua sumbu: batang untuk jumlah dokumen, garis untuk nilainya. Manajemen
| perlu melihat keduanya bersamaan -- banyak PR bernilai kecil dan sedikit PR
| bernilai besar menuntut tindakan yang berbeda.
|--------------------------------------------------------------------------
*/
const trendSeries = computed(() => [
  {
    name: t('dashboard.purchaseRequest.chart.seriesCount'),
    type: 'column',
    data: trendPoints.value.map(point => point.count),
  },
  {
    name: t('dashboard.purchaseRequest.chart.seriesApproved'),
    type: 'column',
    data: trendPoints.value.map(point => point.approved_count),
  },
  {
    name: t('dashboard.purchaseRequest.chart.seriesAmount'),
    type: 'line',
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
  },
  colors: ['#7367F0', '#28C76F', '#FF9F43'],
  stroke: { width: [0, 0, 3], curve: 'smooth' },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  dataLabels: { enabled: false },
  legend: { position: 'top', horizontalAlign: 'left', markers: { radius: 12 } },
  grid: { borderColor: 'rgba(var(--v-border-color), 0.12)', strokeDashArray: 4 },
  xaxis: {
    categories: trendPoints.value.map(point => point.label),
    axisBorder: { show: false },
    axisTicks: { show: false },
  },
  yaxis: [
    {
      seriesName: t('dashboard.purchaseRequest.chart.seriesCount'),
      title: { text: t('dashboard.purchaseRequest.chart.axisCount') },
      labels: { formatter: (value: number) => formatNumber(value) },
    },
    {
      seriesName: t('dashboard.purchaseRequest.chart.seriesCount'),
      show: false,
    },
    {
      opposite: true,
      seriesName: t('dashboard.purchaseRequest.chart.seriesAmount'),
      title: { text: t('dashboard.purchaseRequest.chart.axisAmount') },
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
  chart: { type: 'donut', fontFamily: 'inherit' },
  labels: statuses.value.map(row => statusLabel(row.status)),
  colors: ['#A8AAAE', '#FF9F43', '#28C76F', '#EA5455', '#EA5455'],
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
            label: t('dashboard.purchaseRequest.status.totalLabel'),
            formatter: () => formatNumber(totalStatusCount.value),
          },
        },
      },
    },
  },
  tooltip: { y: { formatter: (value: number) => formatNumber(value) } },
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
    official: data.summary?.official ?? kosong,
    waiting_approval: data.summary?.waiting_approval ?? kosong,
    approved: data.summary?.approved ?? kosong,
    not_followed_up: data.summary?.not_followed_up ?? kosong,
    average_approval_days: data.summary?.average_approval_days ?? null,
    approval_rate_percent: Number(data.summary?.approval_rate_percent ?? 0),
  }

  statuses.value = asArray<StatusRow>(data.statuses)
  trendPoints.value = asArray<TrendPoint>(data.trend?.points)
  bottleneck.value = asArray<BottleneckRow>(data.approval_bottleneck)
  stuckApprovals.value = asArray<StuckRow>(data.attention?.stuck_approval)
  awaitingPo.value = asArray<AwaitingPoRow>(data.attention?.awaiting_po)
  byCabang.value = asArray<BreakdownRow>(data.breakdown?.by_cabang)
  byDepartment.value = asArray<BreakdownRow>(data.breakdown?.by_department)
  byType.value = asArray<TypeRow>(data.breakdown?.by_type)
}

const fetchDashboard = async (): Promise<void> => {
  const params = buildParams()

  if (!params) {
    loadError.value = t('dashboard.purchaseRequest.errors.periodInvalid')

    return
  }

  loading.value = true
  loadError.value = ''

  try {
    const response = await axios.get('/dashboard/purchase-request', {
      params,
      headers: { Accept: 'application/json' },
    })

    applyDashboardResponse(response.data?.data ?? {})

    lastUpdated.value = new Date()
  }
  catch (error: unknown) {
    loadError.value = getApiErrorMessage(
      error,
      t('dashboard.purchaseRequest.errors.dashboardFailed'),
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

const goToPurchaseRequest = (): void => {
  router.push('/non_stock/purchase_request')
}

/** Kembali ke daftar modul dashboard, sama seperti dashboard PO. */
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
    <VCard class="reqdash__header mb-6">
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
            color="primary"
            variant="tonal"
            rounded
          >
            <VIcon
              icon="mdi-file-document-edit-outline"
              size="28"
            />
          </VAvatar>

          <div class="min-w-0">
            <div class="text-h5 font-weight-bold">
              {{ t('dashboard.purchaseRequest.header.title') }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('dashboard.purchaseRequest.header.description') }}
            </div>
          </div>
        </div>

        <div class="d-flex align-center gap-2 flex-wrap">
          <VChip
            v-if="access"
            size="small"
            variant="tonal"
            color="primary"
            prepend-icon="mdi-shield-account-outline"
          >
            {{ access.scope_view }}
          </VChip>

          <VChip
            size="small"
            variant="tonal"
            prepend-icon="mdi-clock-outline"
          >
            {{ t('dashboard.purchaseRequest.header.lastUpdated') }} {{ formatTime(lastUpdated) }}
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
              {{ t('dashboard.purchaseRequest.header.refresh') }}
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
              :label="t('dashboard.purchaseRequest.filters.periodType')"
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
              :label="t('dashboard.purchaseRequest.filters.pickDate')"
              density="compact"
              hide-details
              @change="fetchDashboard"
            />

            <VTextField
              v-else-if="selectedPeriod === 'week'"
              v-model="selectedWeek"
              type="week"
              :label="t('dashboard.purchaseRequest.filters.pickWeek')"
              density="compact"
              hide-details
              @change="fetchDashboard"
            />

            <VTextField
              v-else-if="selectedPeriod === 'month'"
              v-model="selectedMonth"
              type="month"
              :label="t('dashboard.purchaseRequest.filters.pickMonth')"
              density="compact"
              hide-details
              @change="fetchDashboard"
            />

            <VSelect
              v-else-if="selectedPeriod === 'year'"
              v-model="selectedYear"
              :items="yearOptions"
              :label="t('dashboard.purchaseRequest.filters.pickYear')"
              density="compact"
              hide-details
              @update:model-value="fetchDashboard"
            />

            <VTextField
              v-else
              v-model="startDate"
              type="date"
              :label="t('dashboard.purchaseRequest.filters.startDate')"
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
              :label="t('dashboard.purchaseRequest.filters.endDate')"
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
              :label="t('dashboard.purchaseRequest.filters.branch')"
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
              :label="t('dashboard.purchaseRequest.filters.department')"
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
          {{ t('dashboard.purchaseRequest.filters.scopeNote') }}
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
      Nol yang tidak dijelaskan mudah disalahartikan sebagai kegagalan memuat.
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
            {{ t('dashboard.purchaseRequest.empty.title') }}
          </div>

          <div class="text-body-2">
            {{ t('dashboard.purchaseRequest.empty.description') }}
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
          {{ t('dashboard.purchaseRequest.empty.showYear') }}
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
        <VCard class="reqdash__kpi h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="reqdash__kpi-label">{{ t('dashboard.purchaseRequest.stats.total') }}</span>

              <VAvatar
                size="34"
                color="primary"
                variant="tonal"
                rounded
              >
                <VIcon
                  icon="mdi-file-document-multiple-outline"
                  size="19"
                />
              </VAvatar>
            </div>

            <div class="reqdash__kpi-value">
              {{ formatNumber(summary.total.count) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ formatCompactCurrency(summary.total.amount) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="reqdash__kpi h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="reqdash__kpi-label">{{ t('dashboard.purchaseRequest.stats.waiting') }}</span>

              <VAvatar
                size="34"
                color="warning"
                variant="tonal"
                rounded
              >
                <VIcon
                  icon="mdi-progress-clock"
                  size="19"
                />
              </VAvatar>
            </div>

            <div class="reqdash__kpi-value text-warning">
              {{ formatNumber(summary.waiting_approval.count) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ formatCompactCurrency(summary.waiting_approval.amount) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="reqdash__kpi h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="reqdash__kpi-label">{{ t('dashboard.purchaseRequest.stats.approved') }}</span>

              <VAvatar
                size="34"
                color="success"
                variant="tonal"
                rounded
              >
                <VIcon
                  icon="mdi-check-decagram-outline"
                  size="19"
                />
              </VAvatar>
            </div>

            <div class="reqdash__kpi-value text-success">
              {{ formatNumber(summary.approved.count) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ formatCompactCurrency(summary.approved.amount) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!--
        Kartu paling actionable: sudah disetujui tetapi belum dibelanjakan.
        Diberi warna info supaya menonjol tanpa terbaca sebagai kesalahan.
      -->
      <VCol
        cols="12"
        sm="6"
        lg="3"
      >
        <VCard class="reqdash__kpi h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="reqdash__kpi-label">{{ t('dashboard.purchaseRequest.stats.notFollowedUp') }}</span>

              <VAvatar
                size="34"
                color="info"
                variant="tonal"
                rounded
              >
                <VIcon
                  icon="mdi-cart-arrow-right"
                  size="19"
                />
              </VAvatar>
            </div>

            <div class="reqdash__kpi-value text-info">
              {{ formatNumber(summary.not_followed_up.count) }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ formatCompactCurrency(summary.not_followed_up.amount) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- INDIKATOR PROSES -->
    <VRow class="mb-2">
      <VCol
        cols="12"
        md="6"
      >
        <VCard class="h-100">
          <VCardText class="d-flex align-center gap-4 pa-5">
            <VAvatar
              size="44"
              color="primary"
              variant="tonal"
              rounded
            >
              <VIcon
                icon="mdi-timer-sand-complete"
                size="24"
              />
            </VAvatar>

            <div class="min-w-0">
              <div class="text-caption text-medium-emphasis text-uppercase">
                {{ t('dashboard.purchaseRequest.stats.averageApproval') }}
              </div>

              <div class="text-h5 font-weight-bold">
                <template v-if="summary.average_approval_days === null">
                  —
                </template>

                <template v-else>
                  {{ formatDecimal(summary.average_approval_days) }}
                  <span class="text-body-1 font-weight-regular">{{ t('dashboard.purchaseRequest.stats.days') }}</span>
                </template>
              </div>

              <div class="text-caption text-medium-emphasis">
                {{ t('dashboard.purchaseRequest.stats.averageApprovalHint') }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="6"
      >
        <VCard class="h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-2">
              <div class="text-caption text-medium-emphasis text-uppercase">
                {{ t('dashboard.purchaseRequest.stats.approvalRate') }}
              </div>

              <div class="text-h6 font-weight-bold">
                {{ formatDecimal(summary.approval_rate_percent) }}%
              </div>
            </div>

            <VProgressLinear
              :model-value="summary.approval_rate_percent"
              color="success"
              height="10"
              rounded
            />

            <div class="text-caption text-medium-emphasis mt-2">
              {{ t('dashboard.purchaseRequest.stats.approvalRateHint', {
                approved: formatNumber(summary.approved.count),
                official: formatNumber(summary.official.count),
              }) }}
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

        <VCardTitle>{{ t('dashboard.purchaseRequest.attention.title') }}</VCardTitle>

        <VCardSubtitle>
          {{ t('dashboard.purchaseRequest.attention.subtitle', { days: thresholdDays }) }}
        </VCardSubtitle>

        <template #append>
          <VChip
            :color="overdueCount > 0 ? 'error' : 'success'"
            variant="tonal"
            size="small"
          >
            {{ t('dashboard.purchaseRequest.attention.badge', {
              overdue: formatNumber(overdueCount),
              total: formatNumber(totalNeedsAction),
            }) }}
          </VChip>
        </template>
      </VCardItem>

      <VDivider />

      <VCardText class="pa-5">
        <VRow>
          <!-- Tertahan di approval -->
          <VCol
            cols="12"
            lg="6"
          >
            <div class="reqdash__section-title">
              <VIcon
                icon="mdi-account-clock-outline"
                size="18"
              />
              {{ t('dashboard.purchaseRequest.attention.stuckTitle') }}

              <VChip
                size="x-small"
                variant="tonal"
                class="ms-1"
              >
                {{ formatNumber(stuckApprovals.length) }}
              </VChip>
            </div>

            <VAlert
              v-if="!stuckApprovals.length"
              type="success"
              variant="tonal"
              density="compact"
            >
              {{ t('dashboard.purchaseRequest.attention.stuckEmpty') }}
            </VAlert>

            <div
              v-else
              class="d-flex flex-column gap-2"
            >
              <div
                v-for="row in stuckApprovals"
                :key="`stuck-${row.id}`"
                class="reqdash__item"
                :class="{ 'reqdash__item--overdue': row.is_overdue }"
              >
                <div class="d-flex align-center justify-space-between gap-3 flex-wrap">
                  <div class="min-w-0">
                    <div class="font-weight-medium text-truncate">
                      {{ row.number }}
                    </div>

                    <div class="text-caption text-medium-emphasis">
                      {{ row.cabang }} &middot; {{ row.department }}
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

                <div class="d-flex align-center justify-space-between gap-2 mt-2 flex-wrap">
                  <VChip
                    size="x-small"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-account-check-outline"
                  >
                    {{ row.step_label }}
                  </VChip>

                  <VChip
                    size="x-small"
                    variant="flat"
                    :color="row.is_overdue ? 'error' : 'warning'"
                  >
                    {{ t('dashboard.purchaseRequest.attention.waitingDays', {
                      days: formatDecimal(row.waiting_days),
                    }) }}
                  </VChip>
                </div>
              </div>
            </div>
          </VCol>

          <!-- Menunggu dibuatkan PO -->
          <VCol
            cols="12"
            lg="6"
          >
            <div class="reqdash__section-title">
              <VIcon
                icon="mdi-cart-arrow-right"
                size="18"
              />
              {{ t('dashboard.purchaseRequest.attention.awaitingPoTitle') }}

              <VChip
                size="x-small"
                variant="tonal"
                class="ms-1"
              >
                {{ formatNumber(awaitingPo.length) }}
              </VChip>
            </div>

            <VAlert
              v-if="!awaitingPo.length"
              type="success"
              variant="tonal"
              density="compact"
            >
              {{ t('dashboard.purchaseRequest.attention.awaitingPoEmpty') }}
            </VAlert>

            <div
              v-else
              class="d-flex flex-column gap-2"
            >
              <div
                v-for="row in awaitingPo"
                :key="`po-${row.id}`"
                class="reqdash__item"
                :class="{ 'reqdash__item--overdue': row.is_overdue }"
              >
                <div class="d-flex align-center justify-space-between gap-3 flex-wrap">
                  <div class="min-w-0">
                    <div class="font-weight-medium text-truncate">
                      {{ row.number }}
                    </div>

                    <div class="text-caption text-medium-emphasis">
                      {{ row.cabang }} &middot; {{ row.department }}
                    </div>
                  </div>

                  <div class="text-end">
                    <div class="font-weight-medium">
                      {{ formatCompactCurrency(row.amount) }}
                    </div>

                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.purchaseRequest.attention.approvedOn') }}
                      {{ formatDate(row.approved_at) }}
                    </div>
                  </div>
                </div>

                <div class="d-flex justify-end mt-2">
                  <VChip
                    size="x-small"
                    variant="flat"
                    :color="row.is_overdue ? 'error' : 'info'"
                  >
                    {{ t('dashboard.purchaseRequest.attention.idleDays', {
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
            color="primary"
            size="small"
            class="text-none"
            append-icon="mdi-arrow-right"
            @click="goToPurchaseRequest"
          >
            {{ t('dashboard.purchaseRequest.attention.openModule') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>

    <!-- PENUMPUKAN APPROVAL -->
    <VCard class="mb-6">
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="warning"
            variant="tonal"
            rounded
          >
            <VIcon icon="mdi-transit-connection-variant" />
          </VAvatar>
        </template>

        <VCardTitle>{{ t('dashboard.purchaseRequest.bottleneck.title') }}</VCardTitle>
        <VCardSubtitle>{{ t('dashboard.purchaseRequest.bottleneck.subtitle') }}</VCardSubtitle>
      </VCardItem>

      <VDivider />

      <VCardText class="pa-5">
        <VAlert
          v-if="!bottleneck.length"
          type="success"
          variant="tonal"
          density="compact"
        >
          {{ t('dashboard.purchaseRequest.bottleneck.empty') }}
        </VAlert>

        <div
          v-else
          class="d-flex flex-column gap-4"
        >
          <div
            v-for="row in bottleneck"
            :key="`step-${row.step_label}`"
          >
            <div class="d-flex align-center justify-space-between gap-3 flex-wrap mb-1">
              <div class="d-flex align-center gap-2 min-w-0">
                <VChip
                  size="x-small"
                  variant="tonal"
                  color="primary"
                >
                  {{ t('dashboard.purchaseRequest.bottleneck.step', { order: row.step_order }) }}
                </VChip>

                <span class="font-weight-medium text-truncate">{{ row.step_label }}</span>
              </div>

              <div class="d-flex align-center gap-3 text-caption text-medium-emphasis">
                <span>{{ formatCompactCurrency(row.amount) }}</span>

                <span>
                  {{ t('dashboard.purchaseRequest.bottleneck.average', {
                    days: formatDecimal(row.average_waiting_days),
                  }) }}
                </span>

                <VChip
                  size="x-small"
                  variant="flat"
                  :color="row.longest_waiting_days >= thresholdDays ? 'error' : 'warning'"
                >
                  {{ formatNumber(row.count) }}
                </VChip>
              </div>
            </div>

            <VProgressLinear
              :model-value="(row.count / bottleneckMax) * 100"
              :color="row.longest_waiting_days >= thresholdDays ? 'error' : 'warning'"
              height="8"
              rounded
            />

            <div class="text-caption text-medium-emphasis mt-1">
              {{ t('dashboard.purchaseRequest.bottleneck.longest', {
                days: formatDecimal(row.longest_waiting_days),
              }) }}
            </div>
          </div>
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
            <VCardTitle>{{ t('dashboard.purchaseRequest.chart.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('dashboard.purchaseRequest.chart.subtitle') }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText class="pa-4">
            <div
              v-if="!trendPoints.length"
              class="d-flex align-center justify-center text-medium-emphasis"
              style="min-height: 320px;"
            >
              {{ t('dashboard.purchaseRequest.chart.empty') }}
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
            <VCardTitle>{{ t('dashboard.purchaseRequest.status.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('dashboard.purchaseRequest.status.subtitle') }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText class="pa-4">
            <div
              v-if="!totalStatusCount"
              class="d-flex align-center justify-center text-medium-emphasis"
              style="min-height: 260px;"
            >
              {{ t('dashboard.purchaseRequest.status.empty') }}
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

    <!-- SEBARAN -->
    <VRow>
      <VCol
        cols="12"
        lg="6"
      >
        <VCard class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('dashboard.purchaseRequest.breakdown.branchTitle') }}</VCardTitle>
            <VCardSubtitle>{{ t('dashboard.purchaseRequest.breakdown.subtitle') }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText class="pa-5">
            <VAlert
              v-if="!byCabang.length"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('dashboard.purchaseRequest.breakdown.empty') }}
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
                    {{ formatNumber(row.count) }} PR &middot; {{ formatCompactCurrency(row.amount) }}
                  </span>
                </div>

                <VProgressLinear
                  :model-value="(row.amount / breakdownMax(byCabang)) * 100"
                  color="primary"
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
            <VCardTitle>{{ t('dashboard.purchaseRequest.breakdown.departmentTitle') }}</VCardTitle>
            <VCardSubtitle>{{ t('dashboard.purchaseRequest.breakdown.subtitle') }}</VCardSubtitle>
          </VCardItem>

          <VDivider />

          <VCardText class="pa-5">
            <VAlert
              v-if="!byDepartment.length"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('dashboard.purchaseRequest.breakdown.empty') }}
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
                    {{ formatNumber(row.count) }} PR &middot; {{ formatCompactCurrency(row.amount) }}
                  </span>
                </div>

                <VProgressLinear
                  :model-value="(row.amount / breakdownMax(byDepartment)) * 100"
                  color="info"
                  height="8"
                  rounded
                />

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('dashboard.purchaseRequest.breakdown.detail', {
                    waiting: formatNumber(row.waiting_count),
                    approved: formatNumber(row.approved_count),
                  }) }}
                </div>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardItem>
            <VCardTitle>{{ t('dashboard.purchaseRequest.breakdown.typeTitle') }}</VCardTitle>
          </VCardItem>

          <VDivider />

          <VCardText class="pa-5">
            <VAlert
              v-if="!byType.length"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('dashboard.purchaseRequest.breakdown.empty') }}
            </VAlert>

            <VRow v-else>
              <VCol
                v-for="row in byType"
                :key="`type-${row.name}`"
                cols="12"
                sm="6"
                md="4"
              >
                <div class="reqdash__item">
                  <div class="text-caption text-medium-emphasis text-uppercase">
                    {{ row.name }}
                  </div>

                  <div class="text-h6 font-weight-bold mt-1">
                    {{ formatNumber(row.count) }} PR
                  </div>

                  <div class="text-body-2 text-medium-emphasis">
                    {{ formatCompactCurrency(row.amount) }}
                  </div>
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
.reqdash__header {
  background: linear-gradient(135deg, rgba(var(--v-theme-primary), 0.10), transparent 65%);
}

.reqdash__kpi-label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.reqdash__kpi-value {
  font-size: 1.75rem;
  font-weight: 700;
  line-height: 1.2;
}

.reqdash__section-title {
  display: flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.85);
  font-size: 0.9rem;
  font-weight: 700;
  gap: 0.5rem;
  margin-block-end: 0.75rem;
}

.reqdash__section-title .v-icon {
  color: rgba(var(--v-theme-primary), 0.8);
}

/* Kartu ringkas untuk satu dokumen atau satu kelompok angka. */
.reqdash__item {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  padding-block: 0.75rem;
  padding-inline: 0.875rem;
}

/*
 * Melewati ambang hari. Ditandai lewat garis tepi kiri, bukan latar penuh,
 * supaya deretan panjang tidak berubah jadi blok merah yang sulit dibaca.
 */
.reqdash__item--overdue {
  border-inline-start: 3px solid rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.04);
}
</style>
