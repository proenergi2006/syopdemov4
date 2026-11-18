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
| Dashboard FPU (Form Pengajuan Uang)
|--------------------------------------------------------------------------
| Disusun untuk manajemen, bukan operator. Urutan bacanya menurun dari
| keputusan ke rincian:
|
|   1. Ringkasan        -- berapa diajukan, disetujui, dan sudah keluar
|   2. Dana berjalan    -- berapa uang perusahaan yang sedang di tangan orang
|   3. Tindak lanjut    -- mana yang tertahan, belum cair, dan menunggak
|   4. Penumpukan       -- prosesnya macet di tahap siapa
|   5. Tren dan sebaran -- konteks jangka panjang dan ke mana dananya mengalir
|
| Bagian tindak lanjut diletakkan tinggi karena itulah yang bisa langsung
| dikerjakan; grafik ada di bawahnya sebagai konteks, bukan sebaliknya.
|
| ANGKA PERIODE DAN ANGKA POSISI DIBEDAKAN DI LAYAR.
|
| Kartu yang menyatakan posisi saat ini -- dana berjalan dan ketiga daftar
| tindak lanjut -- diberi keterangan bahwa isinya lepas dari periode terpilih.
| Tanpa itu, orang akan mengira "dana berjalan 6 juta" berarti 6 juta cair
| bulan ini, padahal sebagian mungkin sudah keluar dua bulan lalu dan justru
| itulah masalahnya.
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
  disbursed_amount: number
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
  approver: string
  waiting_since: string | null
  waiting_days: number
  is_overdue: boolean
}

interface AwaitingDisbursementRow {
  id: number
  number: string
  date: string | null
  status: string
  amount: number
  cabang: string
  department: string
  approved_at: string | null
  scheduled_payment_date: string | null
  idle_days: number
  is_overdue: boolean
}

interface OverdueRealizationRow {
  id: number
  number: string
  amount: number
  cabang: string
  department: string
  requester: string
  disbursed_at: string | null
  age_days: number
  overdue_days: number
}

interface BreakdownRow {
  id: number | null
  name: string
  count: number
  amount: number
  waiting_count: number
  approved_count: number
  disbursed_amount: number
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
    disbursed?: AmountPair
    rejected?: AmountPair
    outstanding?: AmountPair
    approval_rate?: number
    average_approval_days?: number | null
    average_disbursement_days?: number | null
  }

  statuses: StatusRow[]
  trend: { granularity?: string; points?: TrendPoint[] }
  approval_bottleneck: BottleneckRow[]

  attention: {
    stuck_approval?: StuckRow[]
    awaiting_disbursement?: AwaitingDisbursementRow[]
    overdue_realization?: OverdueRealizationRow[]
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

const canView = computed(() => permissionStore.can('dashboard.cash-advance.view'))

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
  { id: null, nama_cabang: t('dashboard.cashAdvance.filters.allBranches') },
  ...cabangOptions.value,
])

const departmentItems = computed(() => [
  { id: null, nama: t('dashboard.cashAdvance.filters.allDepartments') },
  ...departmentOptions.value,
])

const periodOptions = computed(() => [
  { title: t('dashboard.cashAdvance.filters.periodOptions.day'), value: 'day' },
  { title: t('dashboard.cashAdvance.filters.periodOptions.week'), value: 'week' },
  { title: t('dashboard.cashAdvance.filters.periodOptions.month'), value: 'month' },
  { title: t('dashboard.cashAdvance.filters.periodOptions.year'), value: 'year' },
  { title: t('dashboard.cashAdvance.filters.periodOptions.range'), value: 'range' },
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
  disbursed: { ...kosong } as AmountPair,
  rejected: { ...kosong } as AmountPair,
  outstanding: { ...kosong } as AmountPair,
  approval_rate: 0,
  average_approval_days: null as number | null,
  average_disbursement_days: null as number | null,
})

const statuses = ref<StatusRow[]>([])
const trendPoints = ref<TrendPoint[]>([])
const bottleneck = ref<BottleneckRow[]>([])
const stuckApprovals = ref<StuckRow[]>([])
const awaitingDisbursement = ref<AwaitingDisbursementRow[]>([])
const overdueRealization = ref<OverdueRealizationRow[]>([])
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
 * Hari selalu bulat: "3 hari", bukan "3,0 hari".
 *
 * Pecahan hari tidak pernah menjadi dasar tindakan siapa pun -- yang telat
 * tiga hari dan tiga setengah hari sama-sama ditagih hari ini.
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
  'NEED REVISION': { label: 'Butuh Revisi', color: 'warning' },
  'APPROVED': { label: 'Disetujui', color: 'info' },
  'RECEIVED': { label: 'Diterima', color: 'info' },
  'DISBURSED': { label: 'Dicairkan', color: 'success' },
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
|--------------------------------------------------------------------------
| Daftar tindak lanjut yang dibuka bertahap
|--------------------------------------------------------------------------
| Tiga baris dulu, lalu tiga lagi setiap kali diminta, sampai habis. Setelah
| habis, tombolnya berubah menjadi "Sembunyikan" dan mengembalikannya ke tiga
| baris awal.
|
| Alasannya bukan sekadar hemat tempat: ketiga daftar berdiri berdampingan,
| dan satu daftar yang panjang akan mendorong dua tetangganya jauh ke bawah
| sampai tidak terbaca bersamaan. Padahal justru perbandingan ketiganya yang
| dicari -- di mana macetnya, berapa yang menunggu cair, berapa yang
| menunggak.
|
| Dihitung ulang setiap kali data baru datang, supaya mengganti periode tidak
| meninggalkan daftar yang terlanjur terbuka panjang dari periode sebelumnya.
|--------------------------------------------------------------------------
*/
const LANGKAH_TAMPIL = 3

type DaftarPerhatian = 'stuck' | 'awaiting' | 'overdue'

const terlihat = ref<Record<DaftarPerhatian, number>>({
  stuck: LANGKAH_TAMPIL,
  awaiting: LANGKAH_TAMPIL,
  overdue: LANGKAH_TAMPIL,
})

const resetDaftarPerhatian = (): void => {
  terlihat.value = {
    stuck: LANGKAH_TAMPIL,
    awaiting: LANGKAH_TAMPIL,
    overdue: LANGKAH_TAMPIL,
  }
}

const shownStuck = computed(() => stuckApprovals.value.slice(0, terlihat.value.stuck))
const shownAwaiting = computed(() => awaitingDisbursement.value.slice(0, terlihat.value.awaiting))
const shownOverdue = computed(() => overdueRealization.value.slice(0, terlihat.value.overdue))

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

/*
 * Periode tanpa satu pun FPU. Dibedakan dari keadaan sedang memuat supaya
 * deretan angka nol tidak terbaca sebagai dashboard yang rusak.
 */
const isEmptyPeriod = computed(() =>
  !loading.value && !loadError.value && summary.value.submitted.count === 0)

/** Dokumen yang benar-benar menunggu tindakan seseorang. */
const totalNeedsAction = computed(() =>
  stuckApprovals.value.length
  + awaitingDisbursement.value.length
  + overdueRealization.value.length)

const overdueCount = computed(() =>
  stuckApprovals.value.filter(row => row.is_overdue).length
  + awaitingDisbursement.value.filter(row => row.is_overdue).length
  + overdueRealization.value.length)

/** Baris terberat pada penumpukan approval, dipakai untuk lebar bar. */
const bottleneckMax = computed(() =>
  bottleneck.value.reduce((max, row) => Math.max(max, row.count), 0) || 1)

const breakdownMax = (rows: Array<{ amount: number }>): number =>
  rows.reduce((max, row) => Math.max(max, row.amount), 0) || 1

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
    return t('dashboard.cashAdvance.scope.ownData')

  if (info.scope_view === 'OWN_CABANG') {
    return t('dashboard.cashAdvance.scope.ownBranch', {
      branch: info.locked_cabang_names.join(', ') || '-',
      department: info.locked_department_names.join(', ') || '-',
    })
  }

  if (info.scope_view === 'OWN_DEPARTMENT') {
    return t('dashboard.cashAdvance.scope.ownDepartment', {
      department: info.locked_department_names.join(', ') || '-',
    })
  }

  return ''
})

/*
|--------------------------------------------------------------------------
| Grafik
|--------------------------------------------------------------------------
| Batang untuk jumlah dokumen, garis untuk nilai yang dicairkan. Manajemen
| perlu melihat keduanya bersamaan: banyak FPU kecil dan sedikit FPU besar
| menuntut tindakan yang berbeda.
|--------------------------------------------------------------------------
*/

/*
 * Ada tidaknya pencairan pada periode ini.
 *
 * Menentukan apakah sumbu nilai di kanan perlu digambar sama sekali. Tanpa
 * satu pun pencairan, ApexCharts tetap mengarang tangga "Rp 0" sampai "Rp 5"
 * -- angka yang tidak mewakili apa pun, memakan ruang, dan membuat orang
 * mengira ada lima juta yang cair.
 */
const hasDisbursed = computed(() =>
  trendPoints.value.some(point => Number(point.disbursed_amount) > 0))

const trendSeries = computed(() => [
  {
    name: t('dashboard.cashAdvance.chart.seriesCount'),
    type: 'column',
    data: trendPoints.value.map(point => point.count),
  },
  {
    name: t('dashboard.cashAdvance.chart.seriesApproved'),
    type: 'column',
    data: trendPoints.value.map(point => point.approved_count),
  },
  {
    name: t('dashboard.cashAdvance.chart.seriesDisbursed'),
    type: 'line',
    data: trendPoints.value.map(point => point.disbursed_amount),
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
  colors: ['#7367F0', '#00CFE8', '#28C76F'],
  stroke: { width: [0, 0, 3], curve: 'smooth' },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
  dataLabels: { enabled: false },
  legend: { position: 'top', horizontalAlign: 'left', markers: { radius: 12 } },

  /* Titik pada garis nilai, supaya periode pendek tetap terbaca. */
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
      seriesName: t('dashboard.cashAdvance.chart.seriesCount'),
      title: { text: t('dashboard.cashAdvance.chart.axisCount') },

      /*
       * Jumlah dokumen selalu bilangan bulat. Tanpa ini ApexCharts menyisipkan
       * tangga 0,5 -- "1,5 FPU" tidak pernah ada wujudnya.
       */
      min: 0,
      forceNiceScale: true,
      labels: { formatter: (value: number) => formatNumber(Math.round(value)) },
    },
    {
      seriesName: t('dashboard.cashAdvance.chart.seriesCount'),
      show: false,
    },
    {
      opposite: true,
      seriesName: t('dashboard.cashAdvance.chart.seriesDisbursed'),

      /*
       * DISEMBUNYIKAN SAAT TIDAK ADA PENCAIRAN SAMA SEKALI.
       *
       * ApexCharts tetap mengarang tangga "Rp 0" sampai "Rp 5" untuk deret
       * yang seluruhnya nol -- angka yang tidak mewakili apa pun, memakan
       * ruang, dan bisa terbaca seolah ada lima juta yang cair.
       */
      show: hasDisbursed.value,
      min: 0,

      /*
       * Tanpa judul sumbu, sengaja. Judul sumbu kanan digambar memutar di
       * posisi paling luar dan ia yang pertama terpotong. Keterangannya sudah
       * ada di legenda ("Nilai Dicairkan") dan tiap angkanya berawalan "Rp".
       */
      labels: {
        formatter: (value: number) => formatCompactCurrency(value),
        minWidth: 72,
      },
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
  colors: statuses.value.map(row => ({
    'DRAFT': '#A8AAAE',
    'IN PROGRESS': '#FF9F43',
    'NEED REVISION': '#FF9F43',
    'APPROVED': '#00CFE8',
    'RECEIVED': '#00CFE8',
    'DISBURSED': '#28C76F',
    'REJECTED': '#EA5455',
    'CANCELLED': '#EA5455',
  }[row.status] ?? '#A8AAAE')),

  /*
   * Legenda dimatikan: daftar status di bawah donatnya menyebut hal yang
   * sama, lengkap dengan jumlah dan persentasenya. Dua-duanya hidup hanya
   * memakan tinggi kartu tanpa menambah satu pun keterangan baru.
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
            label: t('dashboard.cashAdvance.status.totalLabel'),
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
  realizationLimitDays.value = Number(data.filters?.realization_limit_days ?? 14)

  summary.value = {
    submitted: data.summary?.submitted ?? { ...kosong },
    waiting: data.summary?.waiting ?? { ...kosong },
    approved: data.summary?.approved ?? { ...kosong },
    disbursed: data.summary?.disbursed ?? { ...kosong },
    rejected: data.summary?.rejected ?? { ...kosong },
    outstanding: data.summary?.outstanding ?? { ...kosong },
    approval_rate: Number(data.summary?.approval_rate ?? 0),
    average_approval_days: data.summary?.average_approval_days ?? null,
    average_disbursement_days: data.summary?.average_disbursement_days ?? null,
  }

  statuses.value = asArray<StatusRow>(data.statuses)
  trendPoints.value = asArray<TrendPoint>(data.trend?.points)
  bottleneck.value = asArray<BottleneckRow>(data.approval_bottleneck)
  stuckApprovals.value = asArray<StuckRow>(data.attention?.stuck_approval)
  awaitingDisbursement.value = asArray<AwaitingDisbursementRow>(data.attention?.awaiting_disbursement)
  overdueRealization.value = asArray<OverdueRealizationRow>(data.attention?.overdue_realization)
  byCabang.value = asArray<BreakdownRow>(data.breakdown?.by_cabang)
  byDepartment.value = asArray<BreakdownRow>(data.breakdown?.by_department)
  byCategory.value = asArray<CategoryRow>(data.breakdown?.by_category)

  resetDaftarPerhatian()
}

const fetchDashboard = async (): Promise<void> => {
  const params = buildParams()

  if (!params) {
    loadError.value = t('dashboard.cashAdvance.errors.periodInvalid')

    return
  }

  loading.value = true
  loadError.value = ''

  try {
    const response = await axios.get('/dashboard/cash-advance', {
      params,
      headers: { Accept: 'application/json' },
    })

    applyDashboardResponse(response.data?.data ?? {})

    lastUpdated.value = new Date()
  }
  catch (error: unknown) {
    loadError.value = getApiErrorMessage(
      error,
      t('dashboard.cashAdvance.errors.dashboardFailed'),
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

const goToCashAdvance = (): void => {
  router.push('/fund_request/cash_advance')
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
    <VCard class="fpudash__header mb-6">
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
            color="success"
            variant="tonal"
            rounded
          >
            <VIcon
              icon="mdi-cash-fast"
              size="28"
            />
          </VAvatar>

          <div class="min-w-0">
            <div class="text-h5 font-weight-bold">
              {{ t('dashboard.cashAdvance.header.title') }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('dashboard.cashAdvance.header.description') }}
            </div>
          </div>
        </div>

        <div class="d-flex align-center gap-3">
          <div class="text-caption text-medium-emphasis text-end d-none d-sm-block">
            {{ t('dashboard.cashAdvance.header.lastUpdated') }}<br>
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
            {{ t('dashboard.cashAdvance.header.refresh') }}
          </VBtn>

          <VBtn
            color="success"
            size="small"
            @click="goToCashAdvance"
          >
            <VIcon
              start
              icon="mdi-open-in-new"
            />
            {{ t('dashboard.cashAdvance.header.openModule') }}
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
              :label="t('dashboard.cashAdvance.filters.periodType')"
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
              :label="t('dashboard.cashAdvance.filters.pickDate')"
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
              :label="t('dashboard.cashAdvance.filters.pickWeek')"
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
              :label="t('dashboard.cashAdvance.filters.pickMonth')"
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
              :label="t('dashboard.cashAdvance.filters.pickYear')"
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
                :label="t('dashboard.cashAdvance.filters.startDate')"
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
                :label="t('dashboard.cashAdvance.filters.endDate')"
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
              :label="t('dashboard.cashAdvance.filters.branch')"
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
              :label="t('dashboard.cashAdvance.filters.department')"
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
              {{ t('dashboard.cashAdvance.filters.apply') }}
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
          icon="mdi-cash-remove"
          size="48"
          class="text-disabled mb-3"
        />

        <div class="text-h6 font-weight-bold">
          {{ t('dashboard.cashAdvance.empty.title') }}
        </div>

        <div class="text-body-2 text-medium-emphasis mt-1 mb-4">
          {{ t('dashboard.cashAdvance.empty.description') }}
        </div>

        <VBtn
          variant="tonal"
          color="primary"
          @click="showWholeYear"
        >
          {{ t('dashboard.cashAdvance.empty.showYear') }}
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
                <span class="fpudash__kpi-label">{{ t('dashboard.cashAdvance.stats.submitted') }}</span>

                <VIcon
                  icon="mdi-file-document-multiple-outline"
                  color="primary"
                />
              </div>

              <div class="fpudash__kpi-value">
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
                <span class="fpudash__kpi-label">{{ t('dashboard.cashAdvance.stats.waiting') }}</span>

                <VIcon
                  icon="mdi-timer-sand"
                  color="warning"
                />
              </div>

              <div class="fpudash__kpi-value">
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
                <span class="fpudash__kpi-label">{{ t('dashboard.cashAdvance.stats.approved') }}</span>

                <VIcon
                  icon="mdi-check-decagram-outline"
                  color="info"
                />
              </div>

              <div class="fpudash__kpi-value">
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
                <span class="fpudash__kpi-label">{{ t('dashboard.cashAdvance.stats.disbursed') }}</span>

                <VIcon
                  icon="mdi-cash-check"
                  color="success"
                />
              </div>

              <div class="fpudash__kpi-value">
                {{ formatNumber(summary.disbursed.count) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ formatCompactCurrency(summary.disbursed.amount) }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- DANA BERJALAN DAN INDIKATOR PROSES -->
      <VRow class="mb-2">
        <VCol
          cols="12"
          md="4"
        >
          <VCard class="fpudash__outstanding h-100">
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="fpudash__kpi-label">{{ t('dashboard.cashAdvance.stats.outstanding') }}</span>

                <VIcon
                  icon="mdi-wallet-outline"
                  color="warning"
                />
              </div>

              <div class="fpudash__kpi-value text-warning">
                {{ formatCompactCurrency(summary.outstanding.amount) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('dashboard.cashAdvance.stats.outstandingCount', { count: formatNumber(summary.outstanding.count) }) }}
              </div>

              <VDivider class="my-3" />

              <div class="text-caption text-medium-emphasis">
                <VIcon
                  icon="mdi-information-outline"
                  size="14"
                  class="me-1"
                />
                {{ t('dashboard.cashAdvance.stats.outstandingHint') }}
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
              <div class="fpudash__section-title">
                <VIcon icon="mdi-speedometer" />
                {{ t('dashboard.cashAdvance.performance.title') }}
              </div>

              <VRow dense>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="fpudash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.cashAdvance.performance.approvalRate') }}
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
                      {{ t('dashboard.cashAdvance.performance.approvalRateHint') }}
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="fpudash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.cashAdvance.performance.approvalDays') }}
                    </div>

                    <div class="text-h5 font-weight-bold mt-1">
                      {{ formatDays(summary.average_approval_days) }}
                      <span class="text-body-2 font-weight-regular text-medium-emphasis">
                        {{ t('dashboard.cashAdvance.performance.days') }}
                      </span>
                    </div>

                    <div class="text-caption text-medium-emphasis mt-2">
                      {{ t('dashboard.cashAdvance.performance.approvalDaysHint') }}
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="fpudash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.cashAdvance.performance.disbursementDays') }}
                    </div>

                    <div class="text-h5 font-weight-bold mt-1">
                      {{ formatDays(summary.average_disbursement_days) }}
                      <span class="text-body-2 font-weight-regular text-medium-emphasis">
                        {{ t('dashboard.cashAdvance.performance.days') }}
                      </span>
                    </div>

                    <div class="text-caption text-medium-emphasis mt-2">
                      {{ t('dashboard.cashAdvance.performance.disbursementDaysHint') }}
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
            <div class="fpudash__section-title mb-0">
              <VIcon icon="mdi-alert-decagram-outline" />
              {{ t('dashboard.cashAdvance.attention.title') }}
            </div>

            <div class="d-flex align-center gap-2">
              <VChip
                size="small"
                variant="tonal"
                color="primary"
              >
                {{ t('dashboard.cashAdvance.attention.badge', { count: totalNeedsAction }) }}
              </VChip>

              <VChip
                v-if="overdueCount > 0"
                size="small"
                variant="tonal"
                color="error"
              >
                {{ t('dashboard.cashAdvance.attention.overdueBadge', { count: overdueCount }) }}
              </VChip>
            </div>
          </div>

          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('dashboard.cashAdvance.attention.subtitle') }}
          </div>

          <VRow>
            <!-- Tertahan di approval -->
            <VCol
              cols="12"
              lg="4"
            >
              <div class="fpudash__section-title">
                <VIcon icon="mdi-timer-sand-complete" />
                {{ t('dashboard.cashAdvance.attention.stuckTitle') }}
              </div>

              <div
                v-if="!stuckApprovals.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvance.attention.stuckEmpty') }}
              </div>

              <div
                v-for="row in shownStuck"
                v-else
                :key="`stuck-${row.id}`"
                class="fpudash__item mb-2"
                :class="{ 'fpudash__item--overdue': row.is_overdue }"
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
                    {{ t('dashboard.cashAdvance.attention.waitingDays', { days: formatDays(row.waiting_days) }) }}
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
                  ? t('dashboard.cashAdvance.attention.hide')
                  : t('dashboard.cashAdvance.attention.showMore', { count: sisaTersembunyi('stuck', stuckApprovals.length) }) }}
              </VBtn>
            </VCol>

            <!-- Disetujui tetapi belum cair -->
            <VCol
              cols="12"
              lg="4"
            >
              <div class="fpudash__section-title">
                <VIcon icon="mdi-cash-clock" />
                {{ t('dashboard.cashAdvance.attention.awaitingTitle') }}
              </div>

              <div
                v-if="!awaitingDisbursement.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvance.attention.awaitingEmpty') }}
              </div>

              <div
                v-for="row in shownAwaiting"
                v-else
                :key="`await-${row.id}`"
                class="fpudash__item mb-2"
                :class="{ 'fpudash__item--overdue': row.is_overdue }"
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
                      :color="statusColor(row.status)"
                      class="mt-1"
                    >
                      {{ statusLabel(row.status) }}
                    </VChip>
                  </div>
                </div>

                <VDivider class="my-2" />

                <div class="d-flex flex-wrap align-center justify-space-between gap-1">
                  <div class="text-caption">
                    <VIcon
                      icon="mdi-calendar-check-outline"
                      size="14"
                      class="me-1"
                    />
                    {{ t('dashboard.cashAdvance.attention.approvedOn', { date: formatDate(row.approved_at) }) }}
                  </div>

                  <VChip
                    size="x-small"
                    variant="tonal"
                    :color="row.is_overdue ? 'error' : 'secondary'"
                  >
                    {{ t('dashboard.cashAdvance.attention.idleDays', { days: formatDays(row.idle_days) }) }}
                  </VChip>
                </div>
              </div>

              <VBtn
                v-if="awaitingDisbursement.length > 3"
                variant="text"
                size="small"
                color="primary"
                block
                @click="toggleDaftar('awaiting', awaitingDisbursement.length)"
              >
                <VIcon
                  start
                  :icon="semuaTerlihat('awaiting', awaitingDisbursement.length) ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                />

                {{ semuaTerlihat('awaiting', awaitingDisbursement.length)
                  ? t('dashboard.cashAdvance.attention.hide')
                  : t('dashboard.cashAdvance.attention.showMore', { count: sisaTersembunyi('awaiting', awaitingDisbursement.length) }) }}
              </VBtn>
            </VCol>

            <!-- Lewat batas realisasi -->
            <VCol
              cols="12"
              lg="4"
            >
              <div class="fpudash__section-title">
                <VIcon icon="mdi-receipt-text-clock-outline" />
                {{ t('dashboard.cashAdvance.attention.overdueTitle') }}
              </div>

              <div
                v-if="!overdueRealization.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvance.attention.overdueEmpty', { days: realizationLimitDays }) }}
              </div>

              <div
                v-for="row in shownOverdue"
                v-else
                :key="`overdue-${row.id}`"
                class="fpudash__item fpudash__item--overdue mb-2"
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
                  <div class="text-caption">
                    <VIcon
                      icon="mdi-cash-fast"
                      size="14"
                      class="me-1"
                    />
                    {{ t('dashboard.cashAdvance.attention.disbursedOn', { date: formatDate(row.disbursed_at) }) }}
                  </div>

                  <VChip
                    size="x-small"
                    variant="tonal"
                    color="error"
                  >
                    {{ t('dashboard.cashAdvance.attention.overdueDays', { days: row.overdue_days }) }}
                  </VChip>
                </div>
              </div>

              <VBtn
                v-if="overdueRealization.length > 3"
                variant="text"
                size="small"
                color="primary"
                block
                @click="toggleDaftar('overdue', overdueRealization.length)"
              >
                <VIcon
                  start
                  :icon="semuaTerlihat('overdue', overdueRealization.length) ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                />

                {{ semuaTerlihat('overdue', overdueRealization.length)
                  ? t('dashboard.cashAdvance.attention.hide')
                  : t('dashboard.cashAdvance.attention.showMore', { count: sisaTersembunyi('overdue', overdueRealization.length) }) }}
              </VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- PENUMPUKAN APPROVAL -->
      <VCard class="mb-6">
        <VCardText class="pa-5">
          <div class="fpudash__section-title">
            <VIcon icon="mdi-stairs-up" />
            {{ t('dashboard.cashAdvance.bottleneck.title') }}
          </div>

          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('dashboard.cashAdvance.bottleneck.subtitle') }}
          </div>

          <div
            v-if="!bottleneck.length"
            class="text-body-2 text-medium-emphasis py-4"
          >
            {{ t('dashboard.cashAdvance.bottleneck.empty') }}
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
                  {{ t('dashboard.cashAdvance.bottleneck.step', { order: row.step_order }) }}
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
              {{ t('dashboard.cashAdvance.bottleneck.average', { days: formatDays(row.average_waiting_days) }) }}
              &middot;
              {{ t('dashboard.cashAdvance.bottleneck.longest', { days: formatDays(row.longest_waiting_days) }) }}
            </div>
          </div>
        </VCardText>
      </VCard>

      <!--
        TREN -- SELEBAR HALAMAN, SENDIRIAN.

        Grafik ini memakai dua sumbu: jumlah di kiri, nilai rupiah di kanan.
        Dijejalkan ke dua pertiga lebar, sumbu kanannya tidak pernah kebagian
        ruang dan angkanya terpotong. Yang dikorbankan justru nilai besar --
        angka terpanjang, dan yang paling perlu terbaca utuh.

        Sebaran status pindah ke baris sebaran di bawah, tempat ia memang
        lebih cocok: ia sama-sama menjawab "bagaimana komposisinya", seperti
        sebaran cabang, department, dan keterangan transaksi.
      -->
      <VRow class="mb-2">
        <VCol cols="12">
          <VCard>
            <VCardText class="pa-5">
              <div class="fpudash__section-title">
                <VIcon icon="mdi-chart-timeline-variant" />
                {{ t('dashboard.cashAdvance.chart.title') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mb-2">
                {{ t('dashboard.cashAdvance.chart.subtitle') }}
              </div>

              <div
                v-if="!trendPoints.length"
                class="text-body-2 text-medium-emphasis py-10 text-center"
              >
                {{ t('dashboard.cashAdvance.chart.empty') }}
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
              <div class="fpudash__section-title">
                <VIcon icon="mdi-chart-donut" />
                {{ t('dashboard.cashAdvance.status.title') }}
              </div>

              <div
                v-if="!statuses.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvance.status.empty') }}
              </div>

              <!--
                Donat di kiri, daftarnya di kanan.

                Diameter donat dibatasi tingginya, bukan lebarnya. Ditumpuk ke
                bawah di dalam kartu selebar ini, ia menyisakan ruang kosong
                lebar di kiri dan kanannya sementara daftarnya terdorong jauh
                ke bawah. Berdampingan, keduanya terbaca sekaligus.
              -->
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
                  <!--
                    Daftar ini sekaligus menggantikan legenda bawaan grafik --
                    legendanya dimatikan. Keduanya menyebut hal yang sama, dan
                    yang di sini lebih lengkap: ada jumlah dan persentasenya.
                  -->
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
              <div class="fpudash__section-title">
                <VIcon icon="mdi-office-building-outline" />
                {{ t('dashboard.cashAdvance.breakdown.branchTitle') }}
              </div>

              <div
                v-if="!byCabang.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvance.breakdown.empty') }}
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
                  {{ t('dashboard.cashAdvance.breakdown.detail', {
                    count: formatNumber(row.count),
                    waiting: formatNumber(row.waiting_count),
                    disbursed: formatCompactCurrency(row.disbursed_amount),
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
              <div class="fpudash__section-title">
                <VIcon icon="mdi-account-group-outline" />
                {{ t('dashboard.cashAdvance.breakdown.departmentTitle') }}
              </div>

              <div
                v-if="!byDepartment.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvance.breakdown.empty') }}
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
                  {{ t('dashboard.cashAdvance.breakdown.detail', {
                    count: formatNumber(row.count),
                    waiting: formatNumber(row.waiting_count),
                    disbursed: formatCompactCurrency(row.disbursed_amount),
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
              <div class="fpudash__section-title">
                <VIcon icon="mdi-tag-multiple-outline" />
                {{ t('dashboard.cashAdvance.breakdown.categoryTitle') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mb-3">
                {{ t('dashboard.cashAdvance.breakdown.categorySubtitle') }}
              </div>

              <div
                v-if="!byCategory.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.cashAdvance.breakdown.empty') }}
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
                  {{ t('dashboard.cashAdvance.breakdown.categoryDetail', { count: formatNumber(row.count) }) }}
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
.fpudash__header {
  background: linear-gradient(135deg, rgba(var(--v-theme-success), 0.10), transparent 65%);
}

.fpudash__kpi-label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.fpudash__kpi-value {
  font-size: 1.75rem;
  font-weight: 700;
  line-height: 1.2;
}

/*
 * Dana berjalan diberi tepi sendiri.
 *
 * Ia satu-satunya angka pada deretan atas yang menyatakan POSISI, bukan
 * periode -- dan satu-satunya yang mewakili uang perusahaan yang sedang
 * berada di luar kas. Dibiarkan seragam dengan kartu lain, ia terbaca sebagai
 * angka periode biasa.
 */
.fpudash__outstanding {
  border-inline-start: 3px solid rgb(var(--v-theme-warning));
}

.fpudash__section-title {
  display: flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.85);
  font-size: 0.9rem;
  font-weight: 700;
  gap: 0.5rem;
  margin-block-end: 0.75rem;
}

.fpudash__section-title .v-icon {
  color: rgba(var(--v-theme-primary), 0.8);
}

/* Kartu ringkas untuk satu dokumen atau satu kelompok angka. */
.fpudash__item {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  padding-block: 0.75rem;
  padding-inline: 0.875rem;
}

/*
 * Melewati ambang hari. Ditandai lewat garis tepi kiri, bukan latar penuh,
 * supaya deretan panjang tidak berubah jadi blok merah yang sulit dibaca.
 */
.fpudash__item--overdue {
  border-inline-start: 3px solid rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.04);
}
</style>
