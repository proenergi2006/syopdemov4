<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import axios from '@axios'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { usePermissionStore } from '@/stores/permission'
import { LOCALE_ANGKA } from '@/utils/numberFormatter'

/*
|--------------------------------------------------------------------------
| Dashboard Realisasi FPU
|--------------------------------------------------------------------------
| Disusun untuk manajemen. Susunannya mengikuti dashboard FPU supaya kedua
| layarnya terasa satu keluarga, tetapi yang ditonjolkan berbeda.
|
| FPU menjawab "berapa uang yang keluar". Layar ini menjawab "berapa yang
| kembali bentuk pertanggungjawabannya, dan berapa yang belum". Dua hal yang
| tidak punya padanan di dashboard FPU:
|
|   1. SELISIH -- uang yang harus dikembalikan pemohon atau dibayar
|      perusahaan, dan berapa yang masih menggantung.
|   2. YANG BELUM MASUK -- FPU sudah cair, pertanggungjawabannya belum ada.
|      Dokumennya belum ada, jadi tidak muncul di layar modul Realisasi mana
|      pun. Hanya di sini.
|
| Urutan bacanya menurun dari keputusan ke rincian, dan kartu yang menyatakan
| POSISI SAAT INI diberi keterangan bahwa isinya lepas dari periode terpilih.
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
  settled_count: number
  advance_amount: number
  realization_amount: number
}

interface BottleneckRow {
  step_label: string
  step_order: number
  count: number
  amount: number
  average_waiting_days: number
  longest_waiting_days: number
}

interface NotSubmittedRow {
  id: number
  number: string
  amount: number
  cabang: string
  department: string
  requester: string
  disbursed_at: string | null
  age_days: number
  overdue_days: number
  is_overdue: boolean
  in_progress: boolean
}

interface StuckRow {
  id: number
  number: string
  date: string | null
  amount: number
  cabang: string
  department: string
  step_label: string
  approver: string
  waiting_since: string | null
  waiting_days: number
  is_overdue: boolean
}

interface UnsettledRow {
  id: number
  number: string
  status: string
  difference_type: string
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
  settled_count: number
  difference_amount: number
}

interface CategoryRow {
  id: number | null
  name: string
  count: number
  amount: number
}

interface DashboardAccess {
  scope_view: string
  cabang_id: number | null
  department_id: number | null
  can_filter_cabang: boolean
  can_filter_department: boolean
  own_data_only: boolean
  locked_cabang_names: string[]
  locked_department_names: string[]
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
    realization_limit_days?: number
  }

  summary: {
    submitted?: AmountPair
    waiting?: AmountPair
    approved?: AmountPair
    settled?: AmountPair
    rejected?: AmountPair
    not_submitted?: AmountPair
    approval_rate?: number
    average_approval_days?: number | null
    average_settlement_days?: number | null
  }

  difference: {
    advance_amount?: number
    realization_amount?: number
    returned?: AmountPair
    reimbursed?: AmountPair
    exact?: AmountPair
    exact_rate?: number

    outstanding?: {
      count: number
      amount: number
      return_amount: number
      reimburse_amount: number
    }
  }

  statuses: StatusRow[]
  trend: { granularity?: string; points?: TrendPoint[] }
  approval_bottleneck: BottleneckRow[]

  attention: {
    not_submitted?: NotSubmittedRow[]
    stuck_approval?: StuckRow[]
    unsettled_difference?: UnsettledRow[]
  }

  breakdown: {
    by_cabang?: BreakdownRow[]
    by_department?: BreakdownRow[]
    by_category?: CategoryRow[]
  }
}

const router = useRouter()
const { t } = useI18n()
const permissionStore = usePermissionStore()

const isCheckingPermission = ref(true)
const loading = ref(false)
const loadError = ref('')
const lastUpdated = ref<Date | null>(null)

const canView = computed(() => permissionStore.can('dashboard.cash-advance-realization.view'))

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
  { id: null, nama_cabang: t('dashboard.cashAdvanceRealization.filters.allBranches') },
  ...cabangOptions.value,
])

const departmentItems = computed(() => [
  { id: null, nama: t('dashboard.cashAdvanceRealization.filters.allDepartments') },
  ...departmentOptions.value,
])

const periodOptions = computed(() => [
  { title: t('dashboard.cashAdvanceRealization.filters.periodOptions.day'), value: 'day' },
  { title: t('dashboard.cashAdvanceRealization.filters.periodOptions.week'), value: 'week' },
  { title: t('dashboard.cashAdvanceRealization.filters.periodOptions.month'), value: 'month' },
  { title: t('dashboard.cashAdvanceRealization.filters.periodOptions.year'), value: 'year' },
  { title: t('dashboard.cashAdvanceRealization.filters.periodOptions.range'), value: 'range' },
])

const yearOptions = Array.from({ length: 10 }, (_, index) => currentYear - index)

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/
const access = ref<DashboardAccess | null>(null)
const thresholdDays = ref(3)
const realizationLimitDays = ref(14)

const kosong: AmountPair = { count: 0, amount: 0 }

const summary = ref({
  submitted: { ...kosong } as AmountPair,
  waiting: { ...kosong } as AmountPair,
  approved: { ...kosong } as AmountPair,
  settled: { ...kosong } as AmountPair,
  rejected: { ...kosong } as AmountPair,
  not_submitted: { ...kosong } as AmountPair,
  approval_rate: 0,
  average_approval_days: null as number | null,
  average_settlement_days: null as number | null,
})

const difference = ref({
  advance_amount: 0,
  realization_amount: 0,
  returned: { ...kosong } as AmountPair,
  reimbursed: { ...kosong } as AmountPair,
  exact: { ...kosong } as AmountPair,
  exact_rate: 0,
  outstanding: { count: 0, amount: 0, return_amount: 0, reimburse_amount: 0 },
})

const statuses = ref<StatusRow[]>([])
const trendPoints = ref<TrendPoint[]>([])
const bottleneck = ref<BottleneckRow[]>([])
const notSubmitted = ref<NotSubmittedRow[]>([])
const stuckApprovals = ref<StuckRow[]>([])
const unsettledDifference = ref<UnsettledRow[]>([])
const byCabang = ref<BreakdownRow[]>([])
const byDepartment = ref<BreakdownRow[]>([])
const byCategory = ref<CategoryRow[]>([])

/*
|--------------------------------------------------------------------------
| Format
|--------------------------------------------------------------------------
*/
const formatNumber = (value: number | null | undefined): string =>
  new Intl.NumberFormat(LOCALE_ANGKA).format(Number(value ?? 0))

const formatDecimal = (value: number | null | undefined): string =>
  new Intl.NumberFormat(LOCALE_ANGKA, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
  }).format(Number(value ?? 0))

const formatCurrency = (value: number | null | undefined): string =>
  new Intl.NumberFormat(LOCALE_ANGKA, {
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

    return `Rp ${new Intl.NumberFormat(LOCALE_ANGKA, {
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

/*
 * Hari selalu bulat: "3 hari", bukan "3,0 hari". Pecahan hari tidak pernah
 * menjadi dasar tindakan siapa pun.
 *
 * Kecuali yang di bawah satu hari: dibulatkan menjadi "0 hari" ia terbaca
 * seolah tidak menunggu sama sekali, padahal menunggu. Ditulis "<1".
 */
const formatDays = (value: number | null | undefined): string => {
  const days = Number(value ?? 0)

  if (days > 0 && days < 1)
    return '<1'

  return new Intl.NumberFormat(LOCALE_ANGKA).format(Math.round(days))
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
  'NEED REVISION': { label: 'Butuh Revisi', color: 'warning' },
  'APPROVED': { label: 'Disetujui', color: 'info' },
  'RECEIVED': { label: 'Diterima', color: 'info' },
  'VERIFIED': { label: 'Terverifikasi', color: 'info' },
  'SETTLED': { label: 'Selesai', color: 'success' },
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
 * Periode tanpa satu pun realisasi. Dibedakan dari keadaan sedang memuat
 * supaya deretan angka nol tidak terbaca sebagai dashboard yang rusak.
 */
const isEmptyPeriod = computed(() =>
  !loading.value && !loadError.value && summary.value.submitted.count === 0)

/*
|--------------------------------------------------------------------------
| Daftar tindak lanjut yang dibuka bertahap
|--------------------------------------------------------------------------
| Tiga baris dulu, lalu tiga lagi setiap kali diminta, sampai habis. Setelah
| habis, tombolnya berubah menjadi "Sembunyikan" dan mengembalikannya ke tiga
| baris awal.
|
| Ketiga daftar berdiri berdampingan: satu daftar panjang akan mendorong dua
| tetangganya jauh ke bawah sampai tidak terbaca bersamaan, padahal justru
| perbandingan ketiganya yang dicari.
|--------------------------------------------------------------------------
*/
const LANGKAH_TAMPIL = 3

type DaftarPerhatian = 'notSubmitted' | 'stuck' | 'unsettled'

const terlihat = ref<Record<DaftarPerhatian, number>>({
  notSubmitted: LANGKAH_TAMPIL,
  stuck: LANGKAH_TAMPIL,
  unsettled: LANGKAH_TAMPIL,
})

const resetDaftarPerhatian = (): void => {
  terlihat.value = {
    notSubmitted: LANGKAH_TAMPIL,
    stuck: LANGKAH_TAMPIL,
    unsettled: LANGKAH_TAMPIL,
  }
}

const shownNotSubmitted = computed(() => notSubmitted.value.slice(0, terlihat.value.notSubmitted))
const shownStuck = computed(() => stuckApprovals.value.slice(0, terlihat.value.stuck))
const shownUnsettled = computed(() => unsettledDifference.value.slice(0, terlihat.value.unsettled))

const semuaTerlihat = (daftar: DaftarPerhatian, total: number): boolean =>
  terlihat.value[daftar] >= total

/** Sisa yang belum ditampilkan, untuk ditulis pada tombolnya. */
const sisaTersembunyi = (daftar: DaftarPerhatian, total: number): number =>
  Math.max(0, Math.min(LANGKAH_TAMPIL, total - terlihat.value[daftar]))

const toggleDaftar = (daftar: DaftarPerhatian, total: number): void => {
  terlihat.value[daftar] = semuaTerlihat(daftar, total)
    ? LANGKAH_TAMPIL
    : Math.min(terlihat.value[daftar] + LANGKAH_TAMPIL, total)
}

/** Dokumen yang benar-benar menunggu tindakan seseorang. */
const totalNeedsAction = computed(() =>
  notSubmitted.value.length + stuckApprovals.value.length + unsettledDifference.value.length)

const overdueCount = computed(() =>
  notSubmitted.value.filter(row => row.is_overdue).length
  + stuckApprovals.value.filter(row => row.is_overdue).length
  + unsettledDifference.value.filter(row => row.is_overdue).length)

/** Baris terberat pada penumpukan approval, dipakai untuk lebar bar. */
const bottleneckMax = computed(() =>
  bottleneck.value.reduce((max, row) => Math.max(max, row.count), 0) || 1)

const breakdownMax = (rows: Array<{ amount: number }>): number =>
  rows.reduce((max, row) => Math.max(max, row.amount), 0) || 1

/** Panjang bar realisasi terhadap uang mukanya. */
const realizationRatio = computed(() => {
  const muka = difference.value.advance_amount

  return muka > 0
    ? Math.min(100, (difference.value.realization_amount / muka) * 100)
    : 0
})

/*
 * Kalimat yang menyatakan wilayah data yang sedang dilihat.
 *
 * Perlu ditulis terang-terangan: orang yang melihat angka kecil berhak tahu
 * bahwa itu bukan angka perusahaan, melainkan angka wilayahnya sendiri.
 */
const scopeNotice = computed(() => {
  const info = access.value

  if (!info)
    return ''

  if (info.own_data_only)
    return t('dashboard.cashAdvanceRealization.scope.ownData')

  if (info.scope_view === 'OWN_CABANG') {
    return t('dashboard.cashAdvanceRealization.scope.ownBranch', {
      branch: info.locked_cabang_names.join(', ') || '-',
      department: info.locked_department_names.join(', ') || '-',
    })
  }

  if (info.scope_view === 'OWN_DEPARTMENT') {
    return t('dashboard.cashAdvanceRealization.scope.ownDepartment', {
      department: info.locked_department_names.join(', ') || '-',
    })
  }

  return ''
})

/*
|--------------------------------------------------------------------------
| Grafik
|--------------------------------------------------------------------------
| Uang muka dibanding realisasinya, berdampingan sebagai batang. Jarak antara
| keduanya ITULAH selisihnya -- terbaca langsung tanpa perlu menghitung.
|--------------------------------------------------------------------------
*/
const trendSeries = computed(() => [
  {
    name: t('dashboard.cashAdvanceRealization.chart.seriesAdvance'),
    type: 'column',
    data: trendPoints.value.map(point => point.advance_amount),
  },
  {
    name: t('dashboard.cashAdvanceRealization.chart.seriesRealization'),
    type: 'column',
    data: trendPoints.value.map(point => point.realization_amount),
  },
  {
    name: t('dashboard.cashAdvanceRealization.chart.seriesCount'),
    type: 'line',
    data: trendPoints.value.map(point => point.count),
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
  colors: ['#A8AAAE', '#00CFE8', '#7367F0'],
  stroke: { width: [0, 0, 3], curve: 'smooth' },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
  dataLabels: { enabled: false },
  legend: { position: 'top', horizontalAlign: 'left', markers: { radius: 12 } },

  /* Titik pada garis jumlah, supaya periode pendek tetap terbaca. */
  markers: { size: [0, 0, 4], strokeWidth: 0, hover: { sizeOffset: 2 } },

  grid: {
    borderColor: 'rgba(var(--v-border-color), 0.12)',
    strokeDashArray: 4,
    padding: { top: 0, right: 16, bottom: 0, left: 8 },
  },

  xaxis: {
    categories: trendPoints.value.map(point => point.label),
    axisBorder: { show: false },
    axisTicks: { show: false },
    tooltip: { enabled: false },
  },
  yaxis: [
    {
      seriesName: t('dashboard.cashAdvanceRealization.chart.seriesAdvance'),
      title: { text: t('dashboard.cashAdvanceRealization.chart.axisAmount') },
      min: 0,
      labels: {
        formatter: (value: number) => formatCompactCurrency(value),
        minWidth: 72,
      },
    },
    {
      seriesName: t('dashboard.cashAdvanceRealization.chart.seriesAdvance'),
      show: false,
    },
    {
      opposite: true,
      seriesName: t('dashboard.cashAdvanceRealization.chart.seriesCount'),

      /*
       * Jumlah dokumen selalu bilangan bulat. Tanpa ini ApexCharts menyisipkan
       * tangga 0,5 -- "1,5 realisasi" tidak pernah ada wujudnya.
       */
      min: 0,
      forceNiceScale: true,
      labels: {
        formatter: (value: number) => formatNumber(Math.round(value)),
        minWidth: 40,
      },
    },
  ],
  tooltip: {
    shared: true,
    intersect: false,
    y: {
      formatter: (value: number, { seriesIndex }: { seriesIndex: number }) =>
        seriesIndex === 2 ? formatNumber(value) : formatCurrency(value),
    },
  },
}))

const statusSeries = computed(() => statuses.value.map(row => row.count))

const statusChartOptions = computed(() => ({
  chart: { type: 'donut', fontFamily: 'inherit' },
  labels: statuses.value.map(row => statusLabel(row.status)),
  colors: statuses.value.map(row => ({
    'DRAFT': '#A8AAAE',
    'IN PROGRESS': '#FF9F43',
    'NEED REVISION': '#FF9F43',
    'APPROVED': '#00CFE8',
    'RECEIVED': '#00CFE8',
    'VERIFIED': '#00CFE8',
    'SETTLED': '#28C76F',
    'REJECTED': '#EA5455',
    'CANCELLED': '#EA5455',
  }[row.status] ?? '#A8AAAE')),

  /*
   * Legenda dimatikan: daftar status di sebelahnya menyebut hal yang sama,
   * lengkap dengan jumlah dan persentasenya.
   */
  legend: { show: false },
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
            label: t('dashboard.cashAdvanceRealization.status.totalLabel'),
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

/*
 * Dipecah tiga, bukan satu fungsi panjang.
 *
 * Setiap medan dibaca dengan nilai cadangannya sendiri, dan ditulis
 * berderet-deret ketiganya menjadi satu blok yang tidak lagi bisa dibaca
 * sekilas -- sementara yang dikerjakannya cuma menyalin.
 */
const terapkanRingkasan = (data: Partial<DashboardPayload>): void => {
  summary.value = {
    submitted: data.summary?.submitted ?? { ...kosong },
    waiting: data.summary?.waiting ?? { ...kosong },
    approved: data.summary?.approved ?? { ...kosong },
    settled: data.summary?.settled ?? { ...kosong },
    rejected: data.summary?.rejected ?? { ...kosong },
    not_submitted: data.summary?.not_submitted ?? { ...kosong },
    approval_rate: Number(data.summary?.approval_rate ?? 0),
    average_approval_days: data.summary?.average_approval_days ?? null,
    average_settlement_days: data.summary?.average_settlement_days ?? null,
  }
}

const terapkanSelisih = (data: Partial<DashboardPayload>): void => {
  difference.value = {
    advance_amount: Number(data.difference?.advance_amount ?? 0),
    realization_amount: Number(data.difference?.realization_amount ?? 0),
    returned: data.difference?.returned ?? { ...kosong },
    reimbursed: data.difference?.reimbursed ?? { ...kosong },
    exact: data.difference?.exact ?? { ...kosong },
    exact_rate: Number(data.difference?.exact_rate ?? 0),

    outstanding: data.difference?.outstanding
      ?? { count: 0, amount: 0, return_amount: 0, reimburse_amount: 0 },
  }
}

const terapkanDaftar = (data: Partial<DashboardPayload>): void => {
  statuses.value = asArray<StatusRow>(data.statuses)
  trendPoints.value = asArray<TrendPoint>(data.trend?.points)
  bottleneck.value = asArray<BottleneckRow>(data.approval_bottleneck)
  notSubmitted.value = asArray<NotSubmittedRow>(data.attention?.not_submitted)
  stuckApprovals.value = asArray<StuckRow>(data.attention?.stuck_approval)
  unsettledDifference.value = asArray<UnsettledRow>(data.attention?.unsettled_difference)
  byCabang.value = asArray<BreakdownRow>(data.breakdown?.by_cabang)
  byDepartment.value = asArray<BreakdownRow>(data.breakdown?.by_department)
  byCategory.value = asArray<CategoryRow>(data.breakdown?.by_category)
}

const applyDashboardResponse = (data: Partial<DashboardPayload>): void => {
  access.value = data.access ?? null
  thresholdDays.value = Number(data.filters?.aging_threshold_days ?? 3)
  realizationLimitDays.value = Number(data.filters?.realization_limit_days ?? 14)

  terapkanRingkasan(data)
  terapkanSelisih(data)
  terapkanDaftar(data)

  resetDaftarPerhatian()
}

const fetchDashboard = async (): Promise<void> => {
  const params = buildParams()

  if (!params) {
    loadError.value = t('dashboard.cashAdvanceRealization.errors.periodInvalid')

    return
  }

  loading.value = true
  loadError.value = ''

  try {
    const response = await axios.get('/dashboard/cash-advance-realization', {
      params,
      headers: { Accept: 'application/json' },
    })

    applyDashboardResponse(response.data?.data ?? {})

    lastUpdated.value = new Date()
  }
  catch (error: unknown) {
    loadError.value = getApiErrorMessage(
      error,
      t('dashboard.cashAdvanceRealization.errors.dashboardFailed'),
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

const goToRealization = (): void => {
  router.push('/fund_request/cash_advance_realization')
}

/** Kembali ke daftar modul dashboard, sama seperti dashboard FPU. */
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
    <VCard class="rlsdash__header mb-6">
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
          >
            <VIcon
              icon="mdi-receipt-text-check-outline"
              size="28"
            />
          </VAvatar>

          <div class="min-w-0">
            <div class="text-h5 font-weight-bold">
              {{ t('dashboard.cashAdvanceRealization.header.title') }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('dashboard.cashAdvanceRealization.header.description') }}
            </div>
          </div>
        </div>

        <div class="d-flex align-center gap-3">
          <div class="text-caption text-medium-emphasis text-end d-none d-sm-block">
            {{ t('dashboard.cashAdvanceRealization.header.lastUpdated') }}<br>
            <span class="font-weight-medium">{{ formatTime(lastUpdated) }}</span>
          </div>

          <VBtn
            variant="tonal"
            color="secondary"
            size="small"
            :loading="loading"
            @click="fetchDashboard"
          >
            <VIcon
              start
              icon="mdi-refresh"
            />
            {{ t('dashboard.cashAdvanceRealization.header.refresh') }}
          </VBtn>

          <VBtn
            color="info"
            size="small"
            @click="goToRealization"
          >
            <VIcon
              start
              icon="mdi-open-in-new"
            />
            {{ t('dashboard.cashAdvanceRealization.header.openModule') }}
          </VBtn>
        </div>
      </VCardText>

      <!-- Wilayah data yang sedang dilihat, bila memang dipersempit. -->
      <VCardText
        v-if="scopeNotice"
        class="pt-0 px-6 pb-5"
      >
        <VAlert
          density="compact"
          variant="tonal"
          color="info"
          icon="mdi-eye-outline"
        >
          {{ scopeNotice }}
        </VAlert>
      </VCardText>
    </VCard>

    <!-- FILTER -->
    <VCard class="mb-6">
      <VCardText class="pa-5">
        <VRow dense>
          <VCol
            cols="12"
            sm="6"
            md="3"
          >
            <VSelect
              v-model="selectedPeriod"
              :items="periodOptions"
              item-title="title"
              item-value="value"
              :label="t('dashboard.cashAdvanceRealization.filters.periodType')"
              density="compact"
              hide-details
            />
          </VCol>

          <VCol
            v-if="selectedPeriod === 'day'"
            cols="12"
            sm="6"
            md="3"
          >
            <VTextField
              v-model="selectedDate"
              type="date"
              :label="t('dashboard.cashAdvanceRealization.filters.pickDate')"
              density="compact"
              hide-details
            />
          </VCol>

          <VCol
            v-if="selectedPeriod === 'week'"
            cols="12"
            sm="6"
            md="3"
          >
            <VTextField
              v-model="selectedWeek"
              type="week"
              :label="t('dashboard.cashAdvanceRealization.filters.pickWeek')"
              density="compact"
              hide-details
            />
          </VCol>

          <VCol
            v-if="selectedPeriod === 'month'"
            cols="12"
            sm="6"
            md="3"
          >
            <VTextField
              v-model="selectedMonth"
              type="month"
              :label="t('dashboard.cashAdvanceRealization.filters.pickMonth')"
              density="compact"
              hide-details
            />
          </VCol>

          <VCol
            v-if="selectedPeriod === 'year'"
            cols="12"
            sm="6"
            md="3"
          >
            <VSelect
              v-model="selectedYear"
              :items="yearOptions"
              :label="t('dashboard.cashAdvanceRealization.filters.pickYear')"
              density="compact"
              hide-details
            />
          </VCol>

          <template v-if="selectedPeriod === 'range'">
            <VCol
              cols="12"
              sm="6"
              md="3"
            >
              <VTextField
                v-model="startDate"
                type="date"
                :label="t('dashboard.cashAdvanceRealization.filters.startDate')"
                density="compact"
                hide-details
              />
            </VCol>

            <VCol
              cols="12"
              sm="6"
              md="3"
            >
              <VTextField
                v-model="endDate"
                type="date"
                :label="t('dashboard.cashAdvanceRealization.filters.endDate')"
                density="compact"
                hide-details
              />
            </VCol>
          </template>

          <!--
            Saringan cabang dan department hanya digambar bila scope-nya
            memang mengizinkan. Menampilkannya dalam keadaan mati justru
            membingungkan: orang akan mengira datanya bisa dilebarkan.
          -->
          <VCol
            v-if="access?.can_filter_cabang"
            cols="12"
            sm="6"
            md="3"
          >
            <VSelect
              v-model="selectedCabang"
              :items="cabangItems"
              item-title="nama_cabang"
              item-value="id"
              :label="t('dashboard.cashAdvanceRealization.filters.branch')"
              density="compact"
              hide-details
            />
          </VCol>

          <VCol
            v-if="access?.can_filter_department"
            cols="12"
            sm="6"
            md="3"
          >
            <VSelect
              v-model="selectedDepartment"
              :items="departmentItems"
              item-title="nama"
              item-value="id"
              :label="t('dashboard.cashAdvanceRealization.filters.department')"
              density="compact"
              hide-details
            />
          </VCol>

          <VCol
            cols="12"
            sm="6"
            md="2"
            class="d-flex align-center"
          >
            <VBtn
              block
              color="primary"
              :loading="loading"
              @click="fetchDashboard"
            >
              <VIcon
                start
                icon="mdi-filter-check-outline"
              />
              {{ t('dashboard.cashAdvanceRealization.filters.apply') }}
            </VBtn>
          </VCol>
        </VRow>
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

    <!-- PERIODE KOSONG -->
    <VCard
      v-if="isEmptyPeriod"
      class="mb-6"
    >
      <VCardText class="text-center py-10">
        <VIcon
          icon="mdi-receipt-text-remove-outline"
          size="48"
          class="text-disabled mb-3"
        />

        <div class="text-h6 font-weight-bold">
          {{ t('dashboard.cashAdvanceRealization.empty.title') }}
        </div>

        <div class="text-body-2 text-medium-emphasis mt-1 mb-4">
          {{ t('dashboard.cashAdvanceRealization.empty.description') }}
        </div>

        <VBtn
          variant="tonal"
          color="primary"
          @click="showWholeYear"
        >
          {{ t('dashboard.cashAdvanceRealization.empty.showYear') }}
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
          <VCard>
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="rlsdash__kpi-label">{{ t('dashboard.cashAdvanceRealization.stats.submitted') }}</span>

                <VIcon
                  icon="mdi-receipt-text-outline"
                  color="primary"
                />
              </div>

              <div class="rlsdash__kpi-value">
                {{ formatNumber(summary.submitted.count) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ formatCompactCurrency(summary.submitted.amount) }}
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          sm="6"
          lg="3"
        >
          <VCard>
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="rlsdash__kpi-label">{{ t('dashboard.cashAdvanceRealization.stats.waiting') }}</span>

                <VIcon
                  icon="mdi-timer-sand"
                  color="warning"
                />
              </div>

              <div class="rlsdash__kpi-value">
                {{ formatNumber(summary.waiting.count) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ formatCompactCurrency(summary.waiting.amount) }}
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          sm="6"
          lg="3"
        >
          <VCard>
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="rlsdash__kpi-label">{{ t('dashboard.cashAdvanceRealization.stats.approved') }}</span>

                <VIcon
                  icon="mdi-check-decagram-outline"
                  color="info"
                />
              </div>

              <div class="rlsdash__kpi-value">
                {{ formatNumber(summary.approved.count) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ formatCompactCurrency(summary.approved.amount) }}
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          sm="6"
          lg="3"
        >
          <VCard>
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="rlsdash__kpi-label">{{ t('dashboard.cashAdvanceRealization.stats.settled') }}</span>

                <VIcon
                  icon="mdi-check-all"
                  color="success"
                />
              </div>

              <div class="rlsdash__kpi-value">
                {{ formatNumber(summary.settled.count) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ formatCompactCurrency(summary.settled.amount) }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- BELUM DIPERTANGGUNGJAWABKAN DAN KECEPATAN PROSES -->
      <VRow class="mb-2">
        <VCol
          cols="12"
          md="4"
        >
          <VCard class="rlsdash__outstanding h-100">
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="rlsdash__kpi-label">
                  {{ t('dashboard.cashAdvanceRealization.stats.notSubmitted') }}
                </span>

                <VIcon
                  icon="mdi-file-clock-outline"
                  color="warning"
                />
              </div>

              <div class="rlsdash__kpi-value text-warning">
                {{ formatCompactCurrency(summary.not_submitted.amount) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('dashboard.cashAdvanceRealization.stats.notSubmittedCount', {
                  count: formatNumber(summary.not_submitted.count),
                }) }}
              </div>

              <VDivider class="my-3" />

              <div class="text-caption text-medium-emphasis">
                <VIcon
                  icon="mdi-information-outline"
                  size="14"
                  class="me-1"
                />
                {{ t('dashboard.cashAdvanceRealization.stats.notSubmittedHint') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          md="8"
        >
          <VCard class="h-100">
            <VCardText class="pa-5">
              <div class="rlsdash__section-title">
                <VIcon icon="mdi-speedometer" />
                {{ t('dashboard.cashAdvanceRealization.performance.title') }}
              </div>

              <VRow dense>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="rlsdash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.cashAdvanceRealization.performance.approvalRate') }}
                    </div>

                    <div class="text-h5 font-weight-bold mt-1">
                      {{ formatDecimal(summary.approval_rate) }}%
                    </div>

                    <VProgressLinear
                      :model-value="summary.approval_rate"
                      color="success"
                      height="6"
                      rounded
                      class="mt-2"
                    />

                    <div class="text-caption text-medium-emphasis mt-2">
                      {{ t('dashboard.cashAdvanceRealization.performance.approvalRateHint') }}
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="rlsdash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.cashAdvanceRealization.performance.approvalDays') }}
                    </div>

                    <div class="text-h5 font-weight-bold mt-1">
                      {{ formatDays(summary.average_approval_days) }}
                      <span class="text-body-2 font-weight-regular text-medium-emphasis">
                        {{ t('dashboard.cashAdvanceRealization.performance.days') }}
                      </span>
                    </div>

                    <div class="text-caption text-medium-emphasis mt-2">
                      {{ t('dashboard.cashAdvanceRealization.performance.approvalDaysHint') }}
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="rlsdash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.cashAdvanceRealization.performance.settlementDays') }}
                    </div>

                    <div class="text-h5 font-weight-bold mt-1">
                      {{ formatDays(summary.average_settlement_days) }}
                      <span class="text-body-2 font-weight-regular text-medium-emphasis">
                        {{ t('dashboard.cashAdvanceRealization.performance.days') }}
                      </span>
                    </div>

                    <div class="text-caption text-medium-emphasis mt-2">
                      {{ t('dashboard.cashAdvanceRealization.performance.settlementDaysHint') }}
                    </div>
                  </div>
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- SELISIH -->
      <VRow class="mb-2">
        <VCol
          cols="12"
          md="4"
        >
          <VCard class="rlsdash__unsettled h-100">
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="rlsdash__kpi-label">
                  {{ t('dashboard.cashAdvanceRealization.difference.outstandingTitle') }}
                </span>

                <VIcon
                  icon="mdi-swap-horizontal-circle-outline"
                  color="error"
                />
              </div>

              <div class="rlsdash__kpi-value text-error">
                {{ formatCompactCurrency(difference.outstanding.amount) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('dashboard.cashAdvanceRealization.difference.outstandingCount', {
                  count: formatNumber(difference.outstanding.count),
                }) }}
              </div>

              <VDivider class="my-3" />

              <div class="d-flex align-center justify-space-between py-1">
                <span class="text-caption">
                  <VIcon
                    icon="mdi-arrow-down-left"
                    size="14"
                    class="me-1"
                  />
                  {{ t('dashboard.cashAdvanceRealization.difference.toReturn') }}
                </span>

                <span class="text-body-2 font-weight-medium">
                  {{ formatCompactCurrency(difference.outstanding.return_amount) }}
                </span>
              </div>

              <div class="d-flex align-center justify-space-between py-1">
                <span class="text-caption">
                  <VIcon
                    icon="mdi-arrow-up-right"
                    size="14"
                    class="me-1"
                  />
                  {{ t('dashboard.cashAdvanceRealization.difference.toReimburse') }}
                </span>

                <span class="text-body-2 font-weight-medium">
                  {{ formatCompactCurrency(difference.outstanding.reimburse_amount) }}
                </span>
              </div>

              <div class="text-caption text-medium-emphasis mt-2">
                <VIcon
                  icon="mdi-information-outline"
                  size="14"
                  class="me-1"
                />
                {{ t('dashboard.cashAdvanceRealization.difference.outstandingHint') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          md="8"
        >
          <VCard class="h-100">
            <VCardText class="pa-5">
              <div class="rlsdash__section-title">
                <VIcon icon="mdi-scale-balance" />
                {{ t('dashboard.cashAdvanceRealization.difference.title') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mb-4">
                {{ t('dashboard.cashAdvanceRealization.difference.subtitle') }}
              </div>

              <!--
                Uang muka sebagai patokan, realisasi sebagai bar di dalamnya.
                Jarak sisa itulah selisihnya, terbaca tanpa perlu menghitung.
              -->
              <div class="d-flex align-center justify-space-between mb-1">
                <span class="text-caption text-medium-emphasis">
                  {{ t('dashboard.cashAdvanceRealization.difference.advance') }}
                </span>

                <span class="text-body-2 font-weight-medium">
                  {{ formatCurrency(difference.advance_amount) }}
                </span>
              </div>

              <VProgressLinear
                :model-value="100"
                color="secondary"
                height="10"
                rounded
                class="mb-3"
              />

              <div class="d-flex align-center justify-space-between mb-1">
                <span class="text-caption text-medium-emphasis">
                  {{ t('dashboard.cashAdvanceRealization.difference.realization') }}
                </span>

                <span class="text-body-2 font-weight-medium">
                  {{ formatCurrency(difference.realization_amount) }}
                </span>
              </div>

              <VProgressLinear
                :model-value="realizationRatio"
                color="info"
                height="10"
                rounded
                class="mb-4"
              />

              <VRow dense>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="rlsdash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.cashAdvanceRealization.difference.returned') }}
                    </div>

                    <div class="text-h6 font-weight-bold mt-1">
                      {{ formatCompactCurrency(difference.returned.amount) }}
                    </div>

                    <div class="text-caption text-medium-emphasis mt-1">
                      {{ t('dashboard.cashAdvanceRealization.difference.docCount', {
                        count: formatNumber(difference.returned.count),
                      }) }}
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="rlsdash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.cashAdvanceRealization.difference.reimbursed') }}
                    </div>

                    <div class="text-h6 font-weight-bold mt-1">
                      {{ formatCompactCurrency(difference.reimbursed.amount) }}
                    </div>

                    <div class="text-caption text-medium-emphasis mt-1">
                      {{ t('dashboard.cashAdvanceRealization.difference.docCount', {
                        count: formatNumber(difference.reimbursed.count),
                      }) }}
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="rlsdash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.cashAdvanceRealization.difference.exact') }}
                    </div>

                    <div class="text-h6 font-weight-bold mt-1">
                      {{ formatDecimal(difference.exact_rate) }}%
                    </div>

                    <div class="text-caption text-medium-emphasis mt-1">
                      {{ t('dashboard.cashAdvanceRealization.difference.exactHint', {
                        count: formatNumber(difference.exact.count),
                      }) }}
                    </div>
                  </div>
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- BUTUH TINDAK LANJUT -->
      <VCard class="mb-6">
        <VCardText class="pa-5">
          <div class="d-flex flex-wrap align-center justify-space-between gap-2 mb-1">
            <div class="rlsdash__section-title mb-0">
              <VIcon icon="mdi-alert-decagram-outline" />
              {{ t('dashboard.cashAdvanceRealization.attention.title') }}
            </div>

            <div class="d-flex align-center gap-2">
              <VChip
                size="small"
                variant="tonal"
                color="primary"
              >
                {{ t('dashboard.cashAdvanceRealization.attention.badge', { count: totalNeedsAction }) }}
              </VChip>

              <VChip
                v-if="overdueCount > 0"
                size="small"
                variant="tonal"
                color="error"
              >
                {{ t('dashboard.cashAdvanceRealization.attention.overdueBadge', { count: overdueCount }) }}
              </VChip>
            </div>
          </div>

          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('dashboard.cashAdvanceRealization.attention.subtitle') }}
          </div>

          <VRow>
            <!-- Belum dipertanggungjawabkan -->
            <VCol
              cols="12"
              lg="4"
            >
              <div class="rlsdash__section-title">
                <VIcon icon="mdi-file-clock-outline" />
                {{ t('dashboard.cashAdvanceRealization.attention.notSubmittedTitle') }}
              </div>

              <div
                v-if="!notSubmitted.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvanceRealization.attention.notSubmittedEmpty') }}
              </div>

              <template v-else>
                <div
                  v-for="row in shownNotSubmitted"
                  :key="`notsub-${row.id}`"
                  class="rlsdash__item mb-2"
                  :class="{ 'rlsdash__item--overdue': row.is_overdue }"
                >
                  <div class="d-flex align-start justify-space-between gap-2">
                    <div class="min-w-0">
                      <div class="font-weight-medium text-truncate">
                        {{ row.number }}
                      </div>

                      <div class="text-caption text-medium-emphasis text-truncate">
                        {{ row.requester }} &middot; {{ row.cabang }}
                      </div>
                    </div>

                    <div class="text-end flex-shrink-0">
                      <div class="font-weight-medium">
                        {{ formatCompactCurrency(row.amount) }}
                      </div>
                    </div>
                  </div>

                  <VDivider class="my-2" />

                  <div class="d-flex flex-wrap align-center justify-space-between gap-1">
                    <!--
                      Menyebut siapa yang harus dikejar.

                      Keduanya sama-sama belum dipertanggungjawabkan, tetapi
                      yang realisasinya sudah dikirim ada di meja penyetuju --
                      menagih pemohonnya hanya membuang waktu kedua pihak.
                    -->
                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="row.in_progress ? 'info' : 'secondary'"
                    >
                      {{ row.in_progress
                        ? t('dashboard.cashAdvanceRealization.attention.inProgress')
                        : t('dashboard.cashAdvanceRealization.attention.notCreated') }}
                    </VChip>

                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="row.is_overdue ? 'error' : 'secondary'"
                    >
                      {{ row.is_overdue
                        ? t('dashboard.cashAdvanceRealization.attention.overdueDays', { days: row.overdue_days })
                        : t('dashboard.cashAdvanceRealization.attention.ageDays', { days: row.age_days }) }}
                    </VChip>
                  </div>
                </div>

                <VBtn
                  v-if="notSubmitted.length > 3"
                  variant="text"
                  size="small"
                  color="primary"
                  block
                  @click="toggleDaftar('notSubmitted', notSubmitted.length)"
                >
                  <VIcon
                    start
                    :icon="semuaTerlihat('notSubmitted', notSubmitted.length) ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                  />

                  {{ semuaTerlihat('notSubmitted', notSubmitted.length)
                    ? t('dashboard.cashAdvanceRealization.attention.hide')
                    : t('dashboard.cashAdvanceRealization.attention.showMore', {
                      count: sisaTersembunyi('notSubmitted', notSubmitted.length),
                    }) }}
                </VBtn>
              </template>
            </VCol>

            <!-- Tertahan di approval -->
            <VCol
              cols="12"
              lg="4"
            >
              <div class="rlsdash__section-title">
                <VIcon icon="mdi-timer-sand-complete" />
                {{ t('dashboard.cashAdvanceRealization.attention.stuckTitle') }}
              </div>

              <div
                v-if="!stuckApprovals.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvanceRealization.attention.stuckEmpty') }}
              </div>

              <template v-else>
                <div
                  v-for="row in shownStuck"
                  :key="`stuck-${row.id}`"
                  class="rlsdash__item mb-2"
                  :class="{ 'rlsdash__item--overdue': row.is_overdue }"
                >
                  <div class="d-flex align-start justify-space-between gap-2">
                    <div class="min-w-0">
                      <div class="font-weight-medium text-truncate">
                        {{ row.number }}
                      </div>

                      <div class="text-caption text-medium-emphasis">
                        {{ row.cabang }} &middot; {{ row.department }}
                      </div>
                    </div>

                    <div class="text-end flex-shrink-0">
                      <div class="font-weight-medium">
                        {{ formatCompactCurrency(row.amount) }}
                      </div>
                    </div>
                  </div>

                  <VDivider class="my-2" />

                  <div class="d-flex flex-wrap align-center justify-space-between gap-1">
                    <div class="text-caption">
                      <VIcon
                        icon="mdi-account-clock-outline"
                        size="14"
                        class="me-1"
                      />
                      {{ row.step_label }} &mdash; {{ row.approver }}
                    </div>

                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="row.is_overdue ? 'error' : 'secondary'"
                    >
                      {{ t('dashboard.cashAdvanceRealization.attention.waitingDays', {
                        days: formatDays(row.waiting_days),
                      }) }}
                    </VChip>
                  </div>
                </div>

                <VBtn
                  v-if="stuckApprovals.length > 3"
                  variant="text"
                  size="small"
                  color="primary"
                  block
                  @click="toggleDaftar('stuck', stuckApprovals.length)"
                >
                  <VIcon
                    start
                    :icon="semuaTerlihat('stuck', stuckApprovals.length) ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                  />

                  {{ semuaTerlihat('stuck', stuckApprovals.length)
                    ? t('dashboard.cashAdvanceRealization.attention.hide')
                    : t('dashboard.cashAdvanceRealization.attention.showMore', {
                      count: sisaTersembunyi('stuck', stuckApprovals.length),
                    }) }}
                </VBtn>
              </template>
            </VCol>

            <!-- Selisih belum tuntas -->
            <VCol
              cols="12"
              lg="4"
            >
              <div class="rlsdash__section-title">
                <VIcon icon="mdi-swap-horizontal-circle-outline" />
                {{ t('dashboard.cashAdvanceRealization.attention.unsettledTitle') }}
              </div>

              <div
                v-if="!unsettledDifference.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvanceRealization.attention.unsettledEmpty') }}
              </div>

              <template v-else>
                <div
                  v-for="row in shownUnsettled"
                  :key="`unsettled-${row.id}`"
                  class="rlsdash__item mb-2"
                  :class="{ 'rlsdash__item--overdue': row.is_overdue }"
                >
                  <div class="d-flex align-start justify-space-between gap-2">
                    <div class="min-w-0">
                      <div class="font-weight-medium text-truncate">
                        {{ row.number }}
                      </div>

                      <div class="text-caption text-medium-emphasis">
                        {{ row.cabang }} &middot; {{ row.department }}
                      </div>
                    </div>

                    <div class="text-end flex-shrink-0">
                      <div class="font-weight-medium">
                        {{ formatCompactCurrency(row.amount) }}
                      </div>

                      <VChip
                        size="x-small"
                        variant="tonal"
                        :color="row.difference_type === 'RETURN' ? 'success' : 'warning'"
                        class="mt-1"
                      >
                        {{ row.difference_type === 'RETURN'
                          ? t('dashboard.cashAdvanceRealization.difference.toReturn')
                          : t('dashboard.cashAdvanceRealization.difference.toReimburse') }}
                      </VChip>
                    </div>
                  </div>

                  <VDivider class="my-2" />

                  <div class="d-flex flex-wrap align-center justify-space-between gap-1">
                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="statusColor(row.status)"
                    >
                      {{ statusLabel(row.status) }}
                    </VChip>

                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="row.is_overdue ? 'error' : 'secondary'"
                    >
                      {{ t('dashboard.cashAdvanceRealization.attention.idleDays', {
                        days: formatDays(row.idle_days),
                      }) }}
                    </VChip>
                  </div>
                </div>

                <VBtn
                  v-if="unsettledDifference.length > 3"
                  variant="text"
                  size="small"
                  color="primary"
                  block
                  @click="toggleDaftar('unsettled', unsettledDifference.length)"
                >
                  <VIcon
                    start
                    :icon="semuaTerlihat('unsettled', unsettledDifference.length) ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                  />

                  {{ semuaTerlihat('unsettled', unsettledDifference.length)
                    ? t('dashboard.cashAdvanceRealization.attention.hide')
                    : t('dashboard.cashAdvanceRealization.attention.showMore', {
                      count: sisaTersembunyi('unsettled', unsettledDifference.length),
                    }) }}
                </VBtn>
              </template>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- PENUMPUKAN APPROVAL -->
      <VCard class="mb-6">
        <VCardText class="pa-5">
          <div class="rlsdash__section-title">
            <VIcon icon="mdi-stairs-up" />
            {{ t('dashboard.cashAdvanceRealization.bottleneck.title') }}
          </div>

          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('dashboard.cashAdvanceRealization.bottleneck.subtitle') }}
          </div>

          <div
            v-if="!bottleneck.length"
            class="text-body-2 text-medium-emphasis py-4"
          >
            {{ t('dashboard.cashAdvanceRealization.bottleneck.empty') }}
          </div>

          <div
            v-for="row in bottleneck"
            v-else
            :key="`bottleneck-${row.step_label}`"
            class="mb-4"
          >
            <div class="d-flex flex-wrap align-center justify-space-between gap-2 mb-1">
              <div class="font-weight-medium">
                <VChip
                  size="x-small"
                  variant="tonal"
                  color="secondary"
                  class="me-2"
                >
                  {{ t('dashboard.cashAdvanceRealization.bottleneck.step', { order: row.step_order }) }}
                </VChip>

                {{ row.step_label }}
              </div>

              <div class="text-body-2">
                <span class="font-weight-bold">{{ formatNumber(row.count) }}</span>
                <span class="text-medium-emphasis"> &middot; {{ formatCompactCurrency(row.amount) }}</span>
              </div>
            </div>

            <VProgressLinear
              :model-value="(row.count / bottleneckMax) * 100"
              color="warning"
              height="8"
              rounded
            />

            <div class="text-caption text-medium-emphasis mt-1">
              {{ t('dashboard.cashAdvanceRealization.bottleneck.average', {
                days: formatDays(row.average_waiting_days),
              }) }}
              &middot;
              {{ t('dashboard.cashAdvanceRealization.bottleneck.longest', {
                days: formatDays(row.longest_waiting_days),
              }) }}
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- TREN -- selebar halaman, sama seperti dashboard FPU. -->
      <VRow class="mb-2">
        <VCol cols="12">
          <VCard>
            <VCardText class="pa-5">
              <div class="rlsdash__section-title">
                <VIcon icon="mdi-chart-timeline-variant" />
                {{ t('dashboard.cashAdvanceRealization.chart.title') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mb-2">
                {{ t('dashboard.cashAdvanceRealization.chart.subtitle') }}
              </div>

              <div
                v-if="!trendPoints.length"
                class="text-body-2 text-medium-emphasis py-10 text-center"
              >
                {{ t('dashboard.cashAdvanceRealization.chart.empty') }}
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
      </VRow>

      <!-- SEBARAN -->
      <VRow>
        <VCol
          cols="12"
          md="6"
        >
          <VCard class="h-100">
            <VCardText class="pa-5">
              <div class="rlsdash__section-title">
                <VIcon icon="mdi-chart-donut" />
                {{ t('dashboard.cashAdvanceRealization.status.title') }}
              </div>

              <div
                v-if="!statuses.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvanceRealization.status.empty') }}
              </div>

              <VRow
                v-else
                dense
                class="align-center"
              >
                <VCol
                  cols="12"
                  sm="5"
                >
                  <VueApexCharts
                    type="donut"
                    height="200"
                    :options="statusChartOptions"
                    :series="statusSeries"
                  />
                </VCol>

                <VCol
                  cols="12"
                  sm="7"
                >
                  <div
                    v-for="row in statuses"
                    :key="`status-${row.status}`"
                    class="d-flex align-center justify-space-between py-1"
                  >
                    <div class="d-flex align-center gap-2 min-w-0">
                      <VChip
                        size="x-small"
                        variant="tonal"
                        :color="statusColor(row.status)"
                      >
                        {{ statusLabel(row.status) }}
                      </VChip>
                    </div>

                    <div class="text-body-2 text-end flex-shrink-0">
                      <span class="font-weight-bold">{{ formatNumber(row.count) }}</span>
                      <span class="text-medium-emphasis"> ({{ statusPercent(row) }}%)</span>
                    </div>
                  </div>
                </VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="12"
          md="6"
        >
          <VCard class="h-100">
            <VCardText class="pa-5">
              <div class="rlsdash__section-title">
                <VIcon icon="mdi-office-building-outline" />
                {{ t('dashboard.cashAdvanceRealization.breakdown.branchTitle') }}
              </div>

              <div
                v-if="!byCabang.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvanceRealization.breakdown.empty') }}
              </div>

              <div
                v-for="row in byCabang"
                v-else
                :key="`cabang-${row.id ?? row.name}`"
                class="mb-3"
              >
                <div class="d-flex align-center justify-space-between gap-2 mb-1">
                  <span class="text-body-2 font-weight-medium text-truncate">{{ row.name }}</span>
                  <span class="text-body-2 flex-shrink-0">{{ formatCompactCurrency(row.amount) }}</span>
                </div>

                <VProgressLinear
                  :model-value="(row.amount / breakdownMax(byCabang)) * 100"
                  color="primary"
                  height="6"
                  rounded
                />

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('dashboard.cashAdvanceRealization.breakdown.detail', {
                    count: formatNumber(row.count),
                    settled: formatNumber(row.settled_count),
                    difference: formatCompactCurrency(row.difference_amount),
                  }) }}
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
              <div class="rlsdash__section-title">
                <VIcon icon="mdi-account-group-outline" />
                {{ t('dashboard.cashAdvanceRealization.breakdown.departmentTitle') }}
              </div>

              <div
                v-if="!byDepartment.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvanceRealization.breakdown.empty') }}
              </div>

              <div
                v-for="row in byDepartment"
                v-else
                :key="`dept-${row.id ?? row.name}`"
                class="mb-3"
              >
                <div class="d-flex align-center justify-space-between gap-2 mb-1">
                  <span class="text-body-2 font-weight-medium text-truncate">{{ row.name }}</span>
                  <span class="text-body-2 flex-shrink-0">{{ formatCompactCurrency(row.amount) }}</span>
                </div>

                <VProgressLinear
                  :model-value="(row.amount / breakdownMax(byDepartment)) * 100"
                  color="info"
                  height="6"
                  rounded
                />

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('dashboard.cashAdvanceRealization.breakdown.detail', {
                    count: formatNumber(row.count),
                    settled: formatNumber(row.settled_count),
                    difference: formatCompactCurrency(row.difference_amount),
                  }) }}
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
              <div class="rlsdash__section-title">
                <VIcon icon="mdi-tag-multiple-outline" />
                {{ t('dashboard.cashAdvanceRealization.breakdown.categoryTitle') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mb-3">
                {{ t('dashboard.cashAdvanceRealization.breakdown.categorySubtitle') }}
              </div>

              <div
                v-if="!byCategory.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvanceRealization.breakdown.empty') }}
              </div>

              <div
                v-for="row in byCategory"
                v-else
                :key="`kat-${row.id ?? row.name}`"
                class="mb-3"
              >
                <div class="d-flex align-center justify-space-between gap-2 mb-1">
                  <span class="text-body-2 font-weight-medium text-truncate">{{ row.name }}</span>
                  <span class="text-body-2 flex-shrink-0">{{ formatCompactCurrency(row.amount) }}</span>
                </div>

                <VProgressLinear
                  :model-value="(row.amount / breakdownMax(byCategory)) * 100"
                  color="success"
                  height="6"
                  rounded
                />

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('dashboard.cashAdvanceRealization.breakdown.categoryDetail', {
                    count: formatNumber(row.count),
                  }) }}
                </div>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </section>
</template>

<style scoped>
.rlsdash__header {
  background: linear-gradient(135deg, rgba(var(--v-theme-info), 0.10), transparent 65%);
}

.rlsdash__kpi-label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.rlsdash__kpi-value {
  font-size: 1.75rem;
  font-weight: 700;
  line-height: 1.2;
}

/*
 * Dua kartu posisi diberi tepi sendiri.
 *
 * Keduanya menyatakan POSISI SAAT INI, bukan angka periode, dan keduanya
 * mewakili uang yang belum selesai urusannya. Dibiarkan seragam dengan kartu
 * di sekitarnya, keduanya terbaca sebagai angka periode biasa.
 */
.rlsdash__outstanding {
  border-inline-start: 3px solid rgb(var(--v-theme-warning));
}

.rlsdash__unsettled {
  border-inline-start: 3px solid rgb(var(--v-theme-error));
}

.rlsdash__section-title {
  display: flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.85);
  font-size: 0.9rem;
  font-weight: 700;
  gap: 0.5rem;
  margin-block-end: 0.75rem;
}

.rlsdash__section-title .v-icon {
  color: rgba(var(--v-theme-primary), 0.8);
}

/* Kartu ringkas untuk satu dokumen atau satu kelompok angka. */
.rlsdash__item {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  padding-block: 0.75rem;
  padding-inline: 0.875rem;
}

/*
 * Melewati ambang hari. Ditandai lewat garis tepi kiri, bukan latar penuh,
 * supaya deretan panjang tidak berubah jadi blok merah yang sulit dibaca.
 */
.rlsdash__item--overdue {
  border-inline-start: 3px solid rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.04);
}
</style>
