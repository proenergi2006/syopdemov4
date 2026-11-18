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
| Dashboard Perjalanan Dinas
|--------------------------------------------------------------------------
| Susunannya mengikuti tiga dashboard pengajuan dana lainnya, tetapi yang
| diukur berbeda: PERDIN PUNYA TENGGAT YANG TIDAK BISA DIGESER.
|
| Uang bisa menunggu; tanggal berangkat tidak. Begitu tanggal itu lewat,
| approval yang baru keluar tidak ada gunanya, tiket yang baru dipesan
| percuma, dan orangnya sudah terlanjur berangkat tanpa bekal. Karena itu
| hampir setiap angka di sini diukur TERHADAP TANGGAL BERANGKAT, bukan
| terhadap umur dokumen.
|
| Satu lagi yang khas: YANG DIHITUNG ORANG, BUKAN HANYA DOKUMEN. Satu perdin
| bisa membawa rombongan, dan biaya maupun pemesanannya mengikuti jumlah
| orangnya -- jadi setiap angka disertai jumlah pelancongnya.
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
  traveler_count: number
}

interface BottleneckRow {
  step_label: string
  step_order: number
  count: number
  amount: number
  average_waiting_days: number
  longest_waiting_days: number
}

/** Bentuk baris yang sama untuk ketiga daftar butuh perhatian. */
interface TripRow {
  id: number
  number: string
  employee: string
  destination: string
  cabang: string
  department: string
  depart_date: string | null
  traveler_count: number
  days_to_depart: number | null
  is_urgent: boolean
  is_departed: boolean
  step_label?: string
  approver?: string
  waiting_days?: number
}

interface ArrangementTypeRow {
  type: string
  count: number
}

interface BreakdownRow {
  id: number | null
  name: string
  count: number
  amount: number
  waiting_count: number
  approved_count: number
}

interface DestinationRow {
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
    urgent_within_days?: number
  }

  summary: {
    submitted?: AmountPair
    waiting?: AmountPair
    approved?: AmountPair
    rejected?: AmountPair
    upcoming?: AmountPair
    approval_rate?: number
    average_approval_days?: number | null
    average_lead_days?: number | null
  }

  readiness: {
    without_arrangement?: AmountPair
    without_funding?: AmountPair
    arrangement_types?: ArrangementTypeRow[]
  }

  statuses: StatusRow[]
  trend: { granularity?: string; points?: TrendPoint[] }
  approval_bottleneck: BottleneckRow[]

  attention: {
    stuck_approval?: TripRow[]
    without_arrangement?: TripRow[]
    without_funding?: TripRow[]
  }

  breakdown: {
    by_cabang?: BreakdownRow[]
    by_department?: BreakdownRow[]
    by_destination?: DestinationRow[]
  }
}

const router = useRouter()
const { t } = useI18n()
const permissionStore = usePermissionStore()

const isCheckingPermission = ref(true)
const loading = ref(false)
const loadError = ref('')
const lastUpdated = ref<Date | null>(null)

const canView = computed(() => permissionStore.can('dashboard.business-trip.view'))

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
  { id: null, nama_cabang: t('dashboard.businessTrip.filters.allBranches') },
  ...cabangOptions.value,
])

const departmentItems = computed(() => [
  { id: null, nama: t('dashboard.businessTrip.filters.allDepartments') },
  ...departmentOptions.value,
])

const periodOptions = computed(() => [
  { title: t('dashboard.businessTrip.filters.periodOptions.day'), value: 'day' },
  { title: t('dashboard.businessTrip.filters.periodOptions.week'), value: 'week' },
  { title: t('dashboard.businessTrip.filters.periodOptions.month'), value: 'month' },
  { title: t('dashboard.businessTrip.filters.periodOptions.year'), value: 'year' },
  { title: t('dashboard.businessTrip.filters.periodOptions.range'), value: 'range' },
])

const yearOptions = Array.from({ length: 10 }, (_, index) => currentYear - index)

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/
const access = ref<DashboardAccess | null>(null)
const thresholdDays = ref(3)
const urgentWithinDays = ref(3)

const kosong: AmountPair = { count: 0, amount: 0 }

const summary = ref({
  submitted: { ...kosong } as AmountPair,
  waiting: { ...kosong } as AmountPair,
  approved: { ...kosong } as AmountPair,
  rejected: { ...kosong } as AmountPair,
  upcoming: { ...kosong } as AmountPair,
  approval_rate: 0,
  average_approval_days: null as number | null,
  average_lead_days: null as number | null,
})

const readiness = ref({
  without_arrangement: { ...kosong } as AmountPair,
  without_funding: { ...kosong } as AmountPair,
})

const arrangementTypes = ref<ArrangementTypeRow[]>([])
const statuses = ref<StatusRow[]>([])
const trendPoints = ref<TrendPoint[]>([])
const bottleneck = ref<BottleneckRow[]>([])
const stuckApprovals = ref<TripRow[]>([])
const withoutArrangement = ref<TripRow[]>([])
const withoutFunding = ref<TripRow[]>([])
const byCabang = ref<BreakdownRow[]>([])
const byDepartment = ref<BreakdownRow[]>([])
const byDestination = ref<DestinationRow[]>([])

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
 * Jarak ke tanggal berangkat, dibacakan sebagai kalimat.
 *
 * Angka mentahnya bertanda: negatif berarti sudah lewat. Ditulis apa adanya
 * ("-8 hari lagi") ia justru membingungkan, jadi tandanya diterjemahkan
 * menjadi kata.
 */
const departLabel = (row: TripRow): string => {
  if (row.days_to_depart === null)
    return t('dashboard.businessTrip.attention.noDepartDate')

  if (row.days_to_depart < 0) {
    return t('dashboard.businessTrip.attention.departedAgo', {
      days: Math.abs(row.days_to_depart),
    })
  }

  if (row.days_to_depart === 0)
    return t('dashboard.businessTrip.attention.departsToday')

  return t('dashboard.businessTrip.attention.departsIn', { days: row.days_to_depart })
}

const departColor = (row: TripRow): string => {
  if (row.is_departed)
    return 'error'

  return row.is_urgent ? 'warning' : 'secondary'
}

/*
|--------------------------------------------------------------------------
| Turunan tampilan
|--------------------------------------------------------------------------
*/

const statusMeta: Record<string, { label: string; color: string }> = {
  'DRAFT': { label: 'Draft', color: 'secondary' },
  'IN PROGRESS': { label: 'Menunggu Approval', color: 'warning' },
  'NEED REVISION': { label: 'Butuh Revisi', color: 'warning' },
  'APPROVED': { label: 'Disetujui', color: 'success' },
  'REJECTED': { label: 'Ditolak', color: 'error' },
  'CANCELLED': { label: 'Dibatalkan', color: 'error' },
}

const statusLabel = (status: string): string => statusMeta[status]?.label ?? status
const statusColor = (status: string): string => statusMeta[status]?.color ?? 'secondary'

/** Jenis pemesanan ditulis dalam huruf besar di basis data. */
const arrangementLabel = (type: string): string => ({
  TIKET: 'Tiket',
  PENGINAPAN: 'Penginapan',
  TRANSPORT: 'Transport',
  LAINNYA: 'Lainnya',
}[type] ?? type)

const totalStatusCount = computed(() =>
  statuses.value.reduce((sum, row) => sum + row.count, 0))

const statusPercent = (row: StatusRow): number =>
  totalStatusCount.value === 0 ? 0 : Math.round((row.count / totalStatusCount.value) * 100)

/*
 * Periode tanpa satu pun perdin. Dibedakan dari keadaan sedang memuat supaya
 * deretan angka nol tidak terbaca sebagai dashboard yang rusak.
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
|--------------------------------------------------------------------------
*/
const LANGKAH_TAMPIL = 3

type DaftarPerhatian = 'stuck' | 'arrangement' | 'funding'

const terlihat = ref<Record<DaftarPerhatian, number>>({
  stuck: LANGKAH_TAMPIL,
  arrangement: LANGKAH_TAMPIL,
  funding: LANGKAH_TAMPIL,
})

const resetDaftarPerhatian = (): void => {
  terlihat.value = {
    stuck: LANGKAH_TAMPIL,
    arrangement: LANGKAH_TAMPIL,
    funding: LANGKAH_TAMPIL,
  }
}

const shownStuck = computed(() => stuckApprovals.value.slice(0, terlihat.value.stuck))
const shownArrangement = computed(() => withoutArrangement.value.slice(0, terlihat.value.arrangement))
const shownFunding = computed(() => withoutFunding.value.slice(0, terlihat.value.funding))

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

const totalNeedsAction = computed(() =>
  stuckApprovals.value.length + withoutArrangement.value.length + withoutFunding.value.length)

/** Yang sudah mepet atau terlanjur lewat tanggal berangkatnya. */
const urgentCount = computed(() =>
  stuckApprovals.value.filter(row => row.is_urgent).length
  + withoutArrangement.value.filter(row => row.is_urgent).length
  + withoutFunding.value.filter(row => row.is_urgent).length)

/** Baris terberat pada penumpukan approval, dipakai untuk lebar bar. */
const bottleneckMax = computed(() =>
  bottleneck.value.reduce((max, row) => Math.max(max, row.count), 0) || 1)

const breakdownMax = (rows: Array<{ amount: number }>): number =>
  rows.reduce((max, row) => Math.max(max, row.amount), 0) || 1

const totalArrangement = computed(() =>
  arrangementTypes.value.reduce((sum, row) => sum + row.count, 0))

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
    return t('dashboard.businessTrip.scope.ownData')

  if (info.scope_view === 'OWN_CABANG') {
    return t('dashboard.businessTrip.scope.ownBranch', {
      branch: info.locked_cabang_names.join(', ') || '-',
      department: info.locked_department_names.join(', ') || '-',
    })
  }

  if (info.scope_view === 'OWN_DEPARTMENT') {
    return t('dashboard.businessTrip.scope.ownDepartment', {
      department: info.locked_department_names.join(', ') || '-',
    })
  }

  return ''
})

/*
|--------------------------------------------------------------------------
| Grafik
|--------------------------------------------------------------------------
| Dikelompokkan menurut TANGGAL BERANGKAT, bukan tanggal pengajuan: yang
| ingin dilihat adalah kapan orang-orangnya sedang di luar kantor.
|--------------------------------------------------------------------------
*/
const trendSeries = computed(() => [
  {
    name: t('dashboard.businessTrip.chart.seriesCount'),
    type: 'column',
    data: trendPoints.value.map(point => point.count),
  },
  {
    name: t('dashboard.businessTrip.chart.seriesApproved'),
    type: 'column',
    data: trendPoints.value.map(point => point.approved_count),
  },
  {
    name: t('dashboard.businessTrip.chart.seriesTravelers'),
    type: 'line',
    data: trendPoints.value.map(point => point.traveler_count),
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
  plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
  dataLabels: { enabled: false },
  legend: { position: 'top', horizontalAlign: 'left', markers: { radius: 12 } },

  /* Titik pada garis, supaya periode pendek tetap terbaca. */
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

  /*
   * Kedua sumbu menghitung benda yang bisa dihitung -- perjalanan dan orang
   * -- jadi keduanya dipaksa bilangan bulat. "1,5 perjalanan" tidak pernah
   * ada wujudnya.
   */
  yaxis: [
    {
      seriesName: t('dashboard.businessTrip.chart.seriesCount'),
      title: { text: t('dashboard.businessTrip.chart.axisCount') },
      min: 0,
      forceNiceScale: true,
      labels: { formatter: (value: number) => formatNumber(Math.round(value)) },
    },
    {
      seriesName: t('dashboard.businessTrip.chart.seriesCount'),
      show: false,
    },
    {
      opposite: true,
      seriesName: t('dashboard.businessTrip.chart.seriesTravelers'),
      title: { text: t('dashboard.businessTrip.chart.axisTravelers') },
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
    y: { formatter: (value: number) => formatNumber(value) },
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
    'APPROVED': '#28C76F',
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
            label: t('dashboard.businessTrip.status.totalLabel'),
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
 * Dipecah per bagian, bukan satu fungsi panjang. Setiap medan dibaca dengan
 * nilai cadangannya sendiri, dan ditulis berderet semuanya menjadi satu blok
 * yang tidak lagi bisa dibaca sekilas.
 */
const terapkanRingkasan = (data: Partial<DashboardPayload>): void => {
  summary.value = {
    submitted: data.summary?.submitted ?? { ...kosong },
    waiting: data.summary?.waiting ?? { ...kosong },
    approved: data.summary?.approved ?? { ...kosong },
    rejected: data.summary?.rejected ?? { ...kosong },
    upcoming: data.summary?.upcoming ?? { ...kosong },
    approval_rate: Number(data.summary?.approval_rate ?? 0),
    average_approval_days: data.summary?.average_approval_days ?? null,
    average_lead_days: data.summary?.average_lead_days ?? null,
  }

  readiness.value = {
    without_arrangement: data.readiness?.without_arrangement ?? { ...kosong },
    without_funding: data.readiness?.without_funding ?? { ...kosong },
  }

  arrangementTypes.value = asArray<ArrangementTypeRow>(data.readiness?.arrangement_types)
}

const terapkanDaftar = (data: Partial<DashboardPayload>): void => {
  statuses.value = asArray<StatusRow>(data.statuses)
  trendPoints.value = asArray<TrendPoint>(data.trend?.points)
  bottleneck.value = asArray<BottleneckRow>(data.approval_bottleneck)
  stuckApprovals.value = asArray<TripRow>(data.attention?.stuck_approval)
  withoutArrangement.value = asArray<TripRow>(data.attention?.without_arrangement)
  withoutFunding.value = asArray<TripRow>(data.attention?.without_funding)
  byCabang.value = asArray<BreakdownRow>(data.breakdown?.by_cabang)
  byDepartment.value = asArray<BreakdownRow>(data.breakdown?.by_department)
  byDestination.value = asArray<DestinationRow>(data.breakdown?.by_destination)
}

const applyDashboardResponse = (data: Partial<DashboardPayload>): void => {
  access.value = data.access ?? null
  thresholdDays.value = Number(data.filters?.aging_threshold_days ?? 3)
  urgentWithinDays.value = Number(data.filters?.urgent_within_days ?? 3)

  terapkanRingkasan(data)
  terapkanDaftar(data)

  resetDaftarPerhatian()
}

const fetchDashboard = async (): Promise<void> => {
  const params = buildParams()

  if (!params) {
    loadError.value = t('dashboard.businessTrip.errors.periodInvalid')

    return
  }

  loading.value = true
  loadError.value = ''

  try {
    const response = await axios.get('/dashboard/business-trip', {
      params,
      headers: { Accept: 'application/json' },
    })

    applyDashboardResponse(response.data?.data ?? {})

    lastUpdated.value = new Date()
  }
  catch (error: unknown) {
    loadError.value = getApiErrorMessage(
      error,
      t('dashboard.businessTrip.errors.dashboardFailed'),
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

const goToBusinessTrip = (): void => {
  router.push('/business_trip/perdin')
}

/** Kembali ke daftar modul dashboard, sama seperti dashboard lainnya. */
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
    <VCard class="btdash__header mb-6">
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
              icon="mdi-airplane-takeoff"
              size="28"
            />
          </VAvatar>

          <div class="min-w-0">
            <div class="text-h5 font-weight-bold">
              {{ t('dashboard.businessTrip.header.title') }}
            </div>

            <div class="text-body-2 text-medium-emphasis mt-1">
              {{ t('dashboard.businessTrip.header.description') }}
            </div>
          </div>
        </div>

        <div class="d-flex align-center gap-3">
          <div class="text-caption text-medium-emphasis text-end d-none d-sm-block">
            {{ t('dashboard.businessTrip.header.lastUpdated') }}<br>
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
            {{ t('dashboard.businessTrip.header.refresh') }}
          </VBtn>

          <VBtn
            color="primary"
            size="small"
            @click="goToBusinessTrip"
          >
            <VIcon
              start
              icon="mdi-open-in-new"
            />
            {{ t('dashboard.businessTrip.header.openModule') }}
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
              :label="t('dashboard.businessTrip.filters.periodType')"
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
              :label="t('dashboard.businessTrip.filters.pickDate')"
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
              :label="t('dashboard.businessTrip.filters.pickWeek')"
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
              :label="t('dashboard.businessTrip.filters.pickMonth')"
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
              :label="t('dashboard.businessTrip.filters.pickYear')"
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
                :label="t('dashboard.businessTrip.filters.startDate')"
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
                :label="t('dashboard.businessTrip.filters.endDate')"
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
              :label="t('dashboard.businessTrip.filters.branch')"
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
              :label="t('dashboard.businessTrip.filters.department')"
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
              {{ t('dashboard.businessTrip.filters.apply') }}
            </VBtn>
          </VCol>
        </VRow>

        <!--
          Periode di sini disaring menurut TANGGAL BERANGKAT, bukan tanggal
          pengajuan. Perbedaannya besar dan tidak terduga, jadi dikatakan
          terang-terangan alih-alih dibiarkan ditebak.
        -->
        <div class="text-caption text-medium-emphasis mt-3">
          <VIcon
            icon="mdi-information-outline"
            size="14"
            class="me-1"
          />
          {{ t('dashboard.businessTrip.filters.periodHint') }}
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

    <!-- PERIODE KOSONG -->
    <VCard
      v-if="isEmptyPeriod"
      class="mb-6"
    >
      <VCardText class="text-center py-10">
        <VIcon
          icon="mdi-airplane-off"
          size="48"
          class="text-disabled mb-3"
        />

        <div class="text-h6 font-weight-bold">
          {{ t('dashboard.businessTrip.empty.title') }}
        </div>

        <div class="text-body-2 text-medium-emphasis mt-1 mb-4">
          {{ t('dashboard.businessTrip.empty.description') }}
        </div>

        <VBtn
          variant="tonal"
          color="primary"
          @click="showWholeYear"
        >
          {{ t('dashboard.businessTrip.empty.showYear') }}
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
                <span class="btdash__kpi-label">{{ t('dashboard.businessTrip.stats.submitted') }}</span>

                <VIcon
                  icon="mdi-map-marker-path"
                  color="primary"
                />
              </div>

              <div class="btdash__kpi-value">
                {{ formatNumber(summary.submitted.count) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('dashboard.businessTrip.stats.travelers', {
                  count: formatNumber(summary.submitted.amount),
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
          <VCard>
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="btdash__kpi-label">{{ t('dashboard.businessTrip.stats.waiting') }}</span>

                <VIcon
                  icon="mdi-timer-sand"
                  color="warning"
                />
              </div>

              <div class="btdash__kpi-value">
                {{ formatNumber(summary.waiting.count) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('dashboard.businessTrip.stats.travelers', {
                  count: formatNumber(summary.waiting.amount),
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
          <VCard>
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="btdash__kpi-label">{{ t('dashboard.businessTrip.stats.approved') }}</span>

                <VIcon
                  icon="mdi-check-decagram-outline"
                  color="success"
                />
              </div>

              <div class="btdash__kpi-value">
                {{ formatNumber(summary.approved.count) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('dashboard.businessTrip.stats.travelers', {
                  count: formatNumber(summary.approved.amount),
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
          <VCard class="btdash__upcoming">
            <VCardText class="pa-5">
              <div class="d-flex align-center justify-space-between mb-2">
                <span class="btdash__kpi-label">{{ t('dashboard.businessTrip.stats.upcoming') }}</span>

                <VIcon
                  icon="mdi-airplane-clock"
                  color="info"
                />
              </div>

              <div class="btdash__kpi-value text-info">
                {{ formatNumber(summary.upcoming.count) }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('dashboard.businessTrip.stats.travelers', {
                  count: formatNumber(summary.upcoming.amount),
                }) }}
              </div>

              <div class="text-caption text-medium-emphasis mt-2">
                {{ t('dashboard.businessTrip.stats.upcomingHint') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- KESIAPAN BERANGKAT DAN KECEPATAN PROSES -->
      <VRow class="mb-2">
        <VCol
          cols="12"
          md="4"
        >
          <VCard class="btdash__readiness h-100">
            <VCardText class="pa-5">
              <div class="btdash__section-title">
                <VIcon icon="mdi-clipboard-check-outline" />
                {{ t('dashboard.businessTrip.readiness.title') }}
              </div>

              <div class="text-caption text-medium-emphasis mb-3">
                {{ t('dashboard.businessTrip.readiness.subtitle') }}
              </div>

              <div class="btdash__item mb-2">
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">
                    {{ t('dashboard.businessTrip.readiness.withoutArrangement') }}
                  </span>

                  <VChip
                    size="x-small"
                    variant="tonal"
                    :color="readiness.without_arrangement.count > 0 ? 'warning' : 'secondary'"
                  >
                    {{ formatNumber(readiness.without_arrangement.count) }}
                  </VChip>
                </div>

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('dashboard.businessTrip.readiness.withoutArrangementHint', {
                    count: formatNumber(readiness.without_arrangement.amount),
                  }) }}
                </div>
              </div>

              <div class="btdash__item mb-3">
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">
                    {{ t('dashboard.businessTrip.readiness.withoutFunding') }}
                  </span>

                  <VChip
                    size="x-small"
                    variant="tonal"
                    :color="readiness.without_funding.count > 0 ? 'error' : 'secondary'"
                  >
                    {{ formatNumber(readiness.without_funding.count) }}
                  </VChip>
                </div>

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('dashboard.businessTrip.readiness.withoutFundingHint', {
                    count: formatNumber(readiness.without_funding.amount),
                  }) }}
                </div>
              </div>

              <VDivider class="mb-3" />

              <div class="text-caption text-medium-emphasis mb-2">
                {{ t('dashboard.businessTrip.readiness.arrangementTypes') }}
              </div>

              <div
                v-if="!arrangementTypes.length"
                class="text-caption text-medium-emphasis"
              >
                {{ t('dashboard.businessTrip.readiness.arrangementEmpty') }}
              </div>

              <div
                v-for="row in arrangementTypes"
                v-else
                :key="`arr-${row.type}`"
                class="d-flex align-center justify-space-between py-1"
              >
                <span class="text-body-2">{{ arrangementLabel(row.type) }}</span>

                <div class="d-flex align-center gap-2 flex-shrink-0">
                  <VProgressLinear
                    :model-value="(row.count / (totalArrangement || 1)) * 100"
                    color="info"
                    height="6"
                    rounded
                    style="inline-size: 70px;"
                  />

                  <span class="text-body-2 font-weight-medium">{{ formatNumber(row.count) }}</span>
                </div>
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
              <div class="btdash__section-title">
                <VIcon icon="mdi-speedometer" />
                {{ t('dashboard.businessTrip.performance.title') }}
              </div>

              <VRow dense>
                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="btdash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.businessTrip.performance.approvalRate') }}
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
                      {{ t('dashboard.businessTrip.performance.approvalRateHint') }}
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="btdash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.businessTrip.performance.approvalDays') }}
                    </div>

                    <div class="text-h5 font-weight-bold mt-1">
                      {{ formatDays(summary.average_approval_days) }}
                      <span class="text-body-2 font-weight-regular text-medium-emphasis">
                        {{ t('dashboard.businessTrip.performance.days') }}
                      </span>
                    </div>

                    <div class="text-caption text-medium-emphasis mt-2">
                      {{ t('dashboard.businessTrip.performance.approvalDaysHint') }}
                    </div>
                  </div>
                </VCol>

                <VCol
                  cols="12"
                  sm="4"
                >
                  <div class="btdash__item h-100">
                    <div class="text-caption text-medium-emphasis">
                      {{ t('dashboard.businessTrip.performance.leadDays') }}
                    </div>

                    <!--
                      Angka negatif berarti approval sering baru keluar
                      SESUDAH orangnya berangkat. Itu kabar yang jauh lebih
                      buruk daripada sekadar "lama", jadi warnanya ikut
                      berubah alih-alih dibiarkan terbaca seperti angka biasa.
                    -->
                    <div
                      class="text-h5 font-weight-bold mt-1"
                      :class="{ 'text-error': Number(summary.average_lead_days ?? 0) < 0 }"
                    >
                      {{ formatDays(summary.average_lead_days) }}
                      <span class="text-body-2 font-weight-regular text-medium-emphasis">
                        {{ t('dashboard.businessTrip.performance.days') }}
                      </span>
                    </div>

                    <div class="text-caption text-medium-emphasis mt-2">
                      {{ Number(summary.average_lead_days ?? 0) < 0
                        ? t('dashboard.businessTrip.performance.leadDaysNegative')
                        : t('dashboard.businessTrip.performance.leadDaysHint') }}
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
            <div class="btdash__section-title mb-0">
              <VIcon icon="mdi-alert-decagram-outline" />
              {{ t('dashboard.businessTrip.attention.title') }}
            </div>

            <div class="d-flex align-center gap-2">
              <VChip
                size="small"
                variant="tonal"
                color="primary"
              >
                {{ t('dashboard.businessTrip.attention.badge', { count: totalNeedsAction }) }}
              </VChip>

              <VChip
                v-if="urgentCount > 0"
                size="small"
                variant="tonal"
                color="error"
              >
                {{ t('dashboard.businessTrip.attention.urgentBadge', { count: urgentCount }) }}
              </VChip>
            </div>
          </div>

          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('dashboard.businessTrip.attention.subtitle', { days: urgentWithinDays }) }}
          </div>

          <VRow>
            <!-- Tertahan di approval -->
            <VCol
              cols="12"
              lg="4"
            >
              <div class="btdash__section-title">
                <VIcon icon="mdi-timer-sand-complete" />
                {{ t('dashboard.businessTrip.attention.stuckTitle') }}
              </div>

              <div
                v-if="!stuckApprovals.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.businessTrip.attention.stuckEmpty') }}
              </div>

              <template v-else>
                <div
                  v-for="row in shownStuck"
                  :key="`stuck-${row.id}`"
                  class="btdash__item mb-2"
                  :class="{ 'btdash__item--urgent': row.is_urgent }"
                >
                  <div class="d-flex align-start justify-space-between gap-2">
                    <div class="min-w-0">
                      <div class="font-weight-medium text-truncate">
                        {{ row.number }}
                      </div>

                      <div class="text-caption text-medium-emphasis text-truncate">
                        {{ row.employee }} &middot; {{ row.destination }}
                      </div>
                    </div>

                    <VChip
                      size="x-small"
                      variant="tonal"
                      color="secondary"
                      class="flex-shrink-0"
                    >
                      {{ t('dashboard.businessTrip.attention.people', { count: row.traveler_count }) }}
                    </VChip>
                  </div>

                  <VDivider class="my-2" />

                  <div class="d-flex flex-wrap align-center justify-space-between gap-1">
                    <div class="text-caption text-truncate">
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
                      :color="departColor(row)"
                    >
                      {{ departLabel(row) }}
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
                    ? t('dashboard.businessTrip.attention.hide')
                    : t('dashboard.businessTrip.attention.showMore', {
                      count: sisaTersembunyi('stuck', stuckApprovals.length),
                    }) }}
                </VBtn>
              </template>
            </VCol>

            <!-- Belum ada pemesanan -->
            <VCol
              cols="12"
              lg="4"
            >
              <div class="btdash__section-title">
                <VIcon icon="mdi-ticket-confirmation-outline" />
                {{ t('dashboard.businessTrip.attention.arrangementTitle') }}
              </div>

              <div
                v-if="!withoutArrangement.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.businessTrip.attention.arrangementEmpty') }}
              </div>

              <template v-else>
                <div
                  v-for="row in shownArrangement"
                  :key="`arr-${row.id}`"
                  class="btdash__item mb-2"
                  :class="{ 'btdash__item--urgent': row.is_urgent }"
                >
                  <div class="d-flex align-start justify-space-between gap-2">
                    <div class="min-w-0">
                      <div class="font-weight-medium text-truncate">
                        {{ row.number }}
                      </div>

                      <div class="text-caption text-medium-emphasis text-truncate">
                        {{ row.employee }} &middot; {{ row.destination }}
                      </div>
                    </div>

                    <VChip
                      size="x-small"
                      variant="tonal"
                      color="secondary"
                      class="flex-shrink-0"
                    >
                      {{ t('dashboard.businessTrip.attention.people', { count: row.traveler_count }) }}
                    </VChip>
                  </div>

                  <VDivider class="my-2" />

                  <div class="d-flex flex-wrap align-center justify-space-between gap-1">
                    <div class="text-caption">
                      <VIcon
                        icon="mdi-calendar-start"
                        size="14"
                        class="me-1"
                      />
                      {{ formatDate(row.depart_date) }}
                    </div>

                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="departColor(row)"
                    >
                      {{ departLabel(row) }}
                    </VChip>
                  </div>
                </div>

                <VBtn
                  v-if="withoutArrangement.length > 3"
                  variant="text"
                  size="small"
                  color="primary"
                  block
                  @click="toggleDaftar('arrangement', withoutArrangement.length)"
                >
                  <VIcon
                    start
                    :icon="semuaTerlihat('arrangement', withoutArrangement.length) ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                  />

                  {{ semuaTerlihat('arrangement', withoutArrangement.length)
                    ? t('dashboard.businessTrip.attention.hide')
                    : t('dashboard.businessTrip.attention.showMore', {
                      count: sisaTersembunyi('arrangement', withoutArrangement.length),
                    }) }}
                </VBtn>
              </template>
            </VCol>

            <!-- Belum ada bekal -->
            <VCol
              cols="12"
              lg="4"
            >
              <div class="btdash__section-title">
                <VIcon icon="mdi-wallet-outline" />
                {{ t('dashboard.businessTrip.attention.fundingTitle') }}
              </div>

              <div
                v-if="!withoutFunding.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.businessTrip.attention.fundingEmpty') }}
              </div>

              <template v-else>
                <div
                  v-for="row in shownFunding"
                  :key="`fund-${row.id}`"
                  class="btdash__item mb-2"
                  :class="{ 'btdash__item--urgent': row.is_urgent }"
                >
                  <div class="d-flex align-start justify-space-between gap-2">
                    <div class="min-w-0">
                      <div class="font-weight-medium text-truncate">
                        {{ row.number }}
                      </div>

                      <div class="text-caption text-medium-emphasis text-truncate">
                        {{ row.employee }} &middot; {{ row.destination }}
                      </div>
                    </div>

                    <VChip
                      size="x-small"
                      variant="tonal"
                      color="secondary"
                      class="flex-shrink-0"
                    >
                      {{ t('dashboard.businessTrip.attention.people', { count: row.traveler_count }) }}
                    </VChip>
                  </div>

                  <VDivider class="my-2" />

                  <div class="d-flex flex-wrap align-center justify-space-between gap-1">
                    <div class="text-caption">
                      <VIcon
                        icon="mdi-calendar-start"
                        size="14"
                        class="me-1"
                      />
                      {{ formatDate(row.depart_date) }}
                    </div>

                    <VChip
                      size="x-small"
                      variant="tonal"
                      :color="departColor(row)"
                    >
                      {{ departLabel(row) }}
                    </VChip>
                  </div>
                </div>

                <VBtn
                  v-if="withoutFunding.length > 3"
                  variant="text"
                  size="small"
                  color="primary"
                  block
                  @click="toggleDaftar('funding', withoutFunding.length)"
                >
                  <VIcon
                    start
                    :icon="semuaTerlihat('funding', withoutFunding.length) ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                  />

                  {{ semuaTerlihat('funding', withoutFunding.length)
                    ? t('dashboard.businessTrip.attention.hide')
                    : t('dashboard.businessTrip.attention.showMore', {
                      count: sisaTersembunyi('funding', withoutFunding.length),
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
          <div class="btdash__section-title">
            <VIcon icon="mdi-stairs-up" />
            {{ t('dashboard.businessTrip.bottleneck.title') }}
          </div>

          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('dashboard.businessTrip.bottleneck.subtitle') }}
          </div>

          <div
            v-if="!bottleneck.length"
            class="text-body-2 text-medium-emphasis py-4"
          >
            {{ t('dashboard.businessTrip.bottleneck.empty') }}
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
                  {{ t('dashboard.businessTrip.bottleneck.step', { order: row.step_order }) }}
                </VChip>

                {{ row.step_label }}
              </div>

              <div class="text-body-2">
                <span class="font-weight-bold">{{ formatNumber(row.count) }}</span>
                <span class="text-medium-emphasis">
                  &middot; {{ t('dashboard.businessTrip.attention.people', { count: formatNumber(row.amount) }) }}
                </span>
              </div>
            </div>

            <VProgressLinear
              :model-value="(row.count / bottleneckMax) * 100"
              color="warning"
              height="8"
              rounded
            />

            <div class="text-caption text-medium-emphasis mt-1">
              {{ t('dashboard.businessTrip.bottleneck.average', { days: formatDays(row.average_waiting_days) }) }}
              &middot;
              {{ t('dashboard.businessTrip.bottleneck.longest', { days: formatDays(row.longest_waiting_days) }) }}
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- TREN -- selebar halaman, sama seperti dashboard lainnya. -->
      <VRow class="mb-2">
        <VCol cols="12">
          <VCard>
            <VCardText class="pa-5">
              <div class="btdash__section-title">
                <VIcon icon="mdi-chart-timeline-variant" />
                {{ t('dashboard.businessTrip.chart.title') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mb-2">
                {{ t('dashboard.businessTrip.chart.subtitle') }}
              </div>

              <div
                v-if="!trendPoints.length"
                class="text-body-2 text-medium-emphasis py-10 text-center"
              >
                {{ t('dashboard.businessTrip.chart.empty') }}
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
              <div class="btdash__section-title">
                <VIcon icon="mdi-chart-donut" />
                {{ t('dashboard.businessTrip.status.title') }}
              </div>

              <div
                v-if="!statuses.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.businessTrip.status.empty') }}
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
              <div class="btdash__section-title">
                <VIcon icon="mdi-map-marker-outline" />
                {{ t('dashboard.businessTrip.breakdown.destinationTitle') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mb-3">
                {{ t('dashboard.businessTrip.breakdown.destinationSubtitle') }}
              </div>

              <div
                v-if="!byDestination.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.businessTrip.breakdown.empty') }}
              </div>

              <div
                v-for="row in byDestination"
                v-else
                :key="`dest-${row.name}`"
                class="mb-3"
              >
                <div class="d-flex align-center justify-space-between gap-2 mb-1">
                  <span class="text-body-2 font-weight-medium text-truncate">{{ row.name }}</span>

                  <span class="text-body-2 flex-shrink-0">
                    {{ t('dashboard.businessTrip.breakdown.tripCount', { count: formatNumber(row.count) }) }}
                  </span>
                </div>

                <VProgressLinear
                  :model-value="(row.amount / breakdownMax(byDestination)) * 100"
                  color="primary"
                  height="6"
                  rounded
                />

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('dashboard.businessTrip.attention.people', { count: formatNumber(row.amount) }) }}
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
              <div class="btdash__section-title">
                <VIcon icon="mdi-office-building-outline" />
                {{ t('dashboard.businessTrip.breakdown.branchTitle') }}
              </div>

              <div
                v-if="!byCabang.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.businessTrip.breakdown.empty') }}
              </div>

              <div
                v-for="row in byCabang"
                v-else
                :key="`cabang-${row.id ?? row.name}`"
                class="mb-3"
              >
                <div class="d-flex align-center justify-space-between gap-2 mb-1">
                  <span class="text-body-2 font-weight-medium text-truncate">{{ row.name }}</span>

                  <span class="text-body-2 flex-shrink-0">
                    {{ t('dashboard.businessTrip.breakdown.tripCount', { count: formatNumber(row.count) }) }}
                  </span>
                </div>

                <VProgressLinear
                  :model-value="(row.amount / breakdownMax(byCabang)) * 100"
                  color="info"
                  height="6"
                  rounded
                />

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('dashboard.businessTrip.breakdown.detail', {
                    people: formatNumber(row.amount),
                    waiting: formatNumber(row.waiting_count),
                    approved: formatNumber(row.approved_count),
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
              <div class="btdash__section-title">
                <VIcon icon="mdi-account-group-outline" />
                {{ t('dashboard.businessTrip.breakdown.departmentTitle') }}
              </div>

              <div
                v-if="!byDepartment.length"
                class="text-body-2 text-medium-emphasis py-4"
              >
                {{ t('dashboard.businessTrip.breakdown.empty') }}
              </div>

              <div
                v-for="row in byDepartment"
                v-else
                :key="`dept-${row.id ?? row.name}`"
                class="mb-3"
              >
                <div class="d-flex align-center justify-space-between gap-2 mb-1">
                  <span class="text-body-2 font-weight-medium text-truncate">{{ row.name }}</span>

                  <span class="text-body-2 flex-shrink-0">
                    {{ t('dashboard.businessTrip.breakdown.tripCount', { count: formatNumber(row.count) }) }}
                  </span>
                </div>

                <VProgressLinear
                  :model-value="(row.amount / breakdownMax(byDepartment)) * 100"
                  color="success"
                  height="6"
                  rounded
                />

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('dashboard.businessTrip.breakdown.detail', {
                    people: formatNumber(row.amount),
                    waiting: formatNumber(row.waiting_count),
                    approved: formatNumber(row.approved_count),
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
.btdash__header {
  background: linear-gradient(135deg, rgba(var(--v-theme-primary), 0.10), transparent 65%);
}

.btdash__kpi-label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.btdash__kpi-value {
  font-size: 1.75rem;
  font-weight: 700;
  line-height: 1.2;
}

/*
 * Dua kartu posisi diberi tepi sendiri.
 *
 * Keduanya menyatakan POSISI SAAT INI, bukan angka periode, dan keduanya soal
 * perjalanan yang belum berangkat. Dibiarkan seragam dengan kartu di
 * sekitarnya, keduanya terbaca sebagai angka periode biasa.
 */
.btdash__upcoming {
  border-inline-start: 3px solid rgb(var(--v-theme-info));
}

.btdash__readiness {
  border-inline-start: 3px solid rgb(var(--v-theme-warning));
}

.btdash__section-title {
  display: flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.85);
  font-size: 0.9rem;
  font-weight: 700;
  gap: 0.5rem;
  margin-block-end: 0.75rem;
}

.btdash__section-title .v-icon {
  color: rgba(var(--v-theme-primary), 0.8);
}

/* Kartu ringkas untuk satu perjalanan atau satu kelompok angka. */
.btdash__item {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  padding-block: 0.75rem;
  padding-inline: 0.875rem;
}

/*
 * Tanggal berangkatnya sudah mepet atau terlewat. Ditandai lewat garis tepi
 * kiri, bukan latar penuh, supaya deretan panjang tidak berubah jadi blok
 * merah yang sulit dibaca.
 */
.btdash__item--urgent {
  border-inline-start: 3px solid rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.04);
}
</style>
