<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import SignaturePad from 'signature_pad'
import axios from '@axios'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
  showWarningToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { formatAuditDateTime, formatDate, formatNumberWithoutRp } from '@/utils/textFormatter'
import { buildPrintLoadingPage } from '@/utils/printLoadingPage'
import { usePolling } from '@core/composable/usePolling'
import { useDayNames } from '@core/composable/useDayNames'
import { useNavigationStore } from '@/stores/navigation'
import { usePermissionStore } from '@/stores/permission'

/*
|--------------------------------------------------------------------------
| Daftar Claim
|--------------------------------------------------------------------------
| Aksinya: lihat, ubah, hapus, ajukan, setujui, tolak, dan bayar. Pembayaran
| adalah tahap terakhir -- Claim tidak punya dokumen realisasi, karena bukti
| pengeluarannya sudah menempel sejak dokumen dibuat.
|
| Export Excel dan cetak PDF mengikuti daftar yang sedang tampil; cetakan
| baru tersedia setelah dokumennya disetujui.
|--------------------------------------------------------------------------
*/

interface ClaimRow {
  id: number
  public_id: string
  claim_number: string | null
  date: string | null
  subject: string | null
  branch: string | null
  department: string | null
  total_amount: number | string | null
  status: string | null

  can_submit?: boolean
  can_approve?: boolean
  approval_label?: string | null

  /*
  | Tanggal pembayaran yang dibekukan saat berkas diterima. Kosong berarti
  | dokumennya memang tidak menunggu pembayaran Finance.
  */
  scheduled_payment_date?: string | null
  payment_timing?: string | null

  /*
  | Dihitung server: hari ini termasuk hari pembayaran atau tidak. Kosong
  | pada dokumen lama, dan diperlakukan sebagai boleh.
  */
  can_pay_today?: boolean
}

interface ClaimAbilities {
  can_view: boolean
  view_scope: string
  can_create: boolean
  can_update: boolean
  can_submit: boolean
  can_delete: boolean
  can_cancel: boolean
  can_pay: boolean
}

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()

const permissionStore = usePermissionStore()
const navigationStore = useNavigationStore()

const isCheckingPermission = ref(true)

const loading = ref(false)
const loadError = ref(false)
const rows = ref<ClaimRow[]>([])

const searchQuery = ref('')
const selectedStatus = ref('')

/*
| Tanggal jadwal pembayaran yang sedang dilihat. Terpisah dari startDate/
| endDate, yang menyaring TANGGAL DOKUMEN -- dua hal berbeda yang mudah
| tertukar kalau disatukan.
*/
const scheduledPaymentDate = ref<string | null>(null)

const startDate = ref<string | null>(null)
const endDate = ref<string | null>(null)
const selectedPendingAction = ref('')
const onlyWaitingMyApproval = ref(false)

const rowPerPage = ref(10)
const currentPage = ref(1)
const totalData = ref(0)
const totalPage = ref(1)

const defaultAbilities = (): ClaimAbilities => ({
  can_view: false,
  view_scope: 'NONE',
  can_create: false,
  can_update: false,
  can_submit: false,
  can_delete: false,
  can_cancel: false,
  can_pay: false,
})

/*
| Hari kerja kasir dalam penomoran ISO, dari server. Namanya dirangkai di
| sini, bukan di server, supaya ikut berganti begitu pengguna menukar
| bahasa -- tanpa menunggu daftarnya diambil ulang.
*/
const paymentDays = ref<number[]>([])

const { dayList } = useDayNames()

/* "Senin dan Rabu". */
const paymentDaysText = computed<string>(() => dayList(paymentDays.value))

const abilities = ref<ClaimAbilities>(defaultAbilities())

/*
|--------------------------------------------------------------------------
| Filter cabang dan department
|--------------------------------------------------------------------------
| Hanya masuk akal bagi pembaca yang memang bisa melihat lintas cabang atau
| lintas department. Pada scope OWN_CABANG dan OWN_DATA keduanya sudah
| terkunci dari akun yang login, jadi filternya tidak ditampilkan sama sekali
| -- bukan ditampilkan dalam keadaan tidak berguna.
|--------------------------------------------------------------------------
*/
const selectedBranch = ref<string>('')
const selectedDepartment = ref<number | null>(null)

const branchOptions = ref<Array<{ id: number; nama_cabang: string }>>([])
const departmentOptions = ref<Array<{ id: number; nama: string }>>([])

const isLoadingFilterOptions = ref(false)

const canFilterBranch = computed<boolean>(
  () => ['ALL', 'OWN_DEPARTMENT'].includes(abilities.value.view_scope),
)

const canFilterDepartment = computed<boolean>(
  () => abilities.value.view_scope === 'ALL',
)

/*
 * Pilihan "semua" dijadikan item, bukan placeholder. Placeholder pada VSelect
 * bertumpuk dengan label-nya dan membuat kolom filter sulit dibaca.
 */
const branchItems = computed(() => [
  { title: t('claim.list.filters.allBranches'), value: '' },
  ...branchOptions.value.map(item => ({
    title: item.nama_cabang,
    value: String(item.id),
  })),
])

const departmentItems = computed(() => [
  { title: t('claim.list.filters.allDepartments'), value: null as number | null },
  ...departmentOptions.value.map(item => ({
    title: item.nama,
    value: Number(item.id),
  })),
])

const canView = computed(() => permissionStore.can('claim.view'))
const canCreate = computed(() => permissionStore.can('claim.create'))
const canUpdate = computed(() => permissionStore.can('claim.update'))
const canDelete = computed(() => permissionStore.can('claim.delete'))
const canReceive = computed(() => permissionStore.can('claim.receive'))
const canPay = computed(() => permissionStore.can('claim.pay'))
const canCancel = computed(() => permissionStore.can('claim.cancel'))
const canExport = computed(() => permissionStore.can('claim.export'))

/*
|--------------------------------------------------------------------------
| Dialog aksi
|--------------------------------------------------------------------------
*/
const approveDialog = ref(false)
const approveTarget = ref<ClaimRow | null>(null)
const approveNotes = ref('')
const approveLoading = ref(false)

const rejectDialog = ref(false)
const rejectTarget = ref<ClaimRow | null>(null)
const rejectNotes = ref('')
const rejectError = ref('')
const rejectLoading = ref(false)

const cancelDialog = ref(false)
const cancelTarget = ref<ClaimRow | null>(null)
const cancelNotes = ref('')
const cancelError = ref('')
const cancelLoading = ref(false)

const receiveLoading = ref(false)
const payLoading = ref(false)

/*
|--------------------------------------------------------------------------
| Tanda tangan
|--------------------------------------------------------------------------
| Submit dan approve sama-sama membubuhkan tanda tangan pemakainya, jadi
| akun yang belum punya diminta membuatnya lebih dulu -- aksi yang tertunda
| dilanjutkan sendiri setelah tanda tangannya tersimpan.
|--------------------------------------------------------------------------
*/
const signatureDialog = ref(false)
const signatureCanvasRef = ref<HTMLCanvasElement | null>(null)
const signaturePad = ref<SignaturePad | null>(null)
const signatureAgree = ref(false)
const signatureError = ref('')
const signatureLoading = ref(false)

const pendingAction = ref<'submit' | 'approve' | null>(null)
const pendingRow = ref<ClaimRow | null>(null)

/*
 * Satu baris approval hasil generator. Dibaca apa adanya dari backend --
 * halaman detail hanya menampilkannya, tidak menghitung ulang apa pun.
 */
interface DetailApproval {
  id: number
  step_order: number
  label: string | null
  approver_name: string | null
  approval_mode: string | null
  status: string | null
  approved_at: string | null
  rejected_at: string | null
  notes: string | null
}

const detailDialog = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const detail = ref<any | null>(null)

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
| Daftar status sudah memuat tahap yang belum dibangun supaya penyaringnya
| tidak perlu diubah lagi saat tahap itu menyusul.
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| Filter "butuh aksi"
|--------------------------------------------------------------------------
| Pembayaran adalah aksi berpermission, jadi pilihannya hanya ditawarkan
| kepada yang berwenang menjalankannya. Bila tidak dipegang, selectnya tidak
| ditampilkan sama sekali.
|--------------------------------------------------------------------------
*/
const pendingActionItems = computed(() => {
  const items = [
    { title: t('claim.list.filters.pendingAction.all'), value: '' },
  ]

  if (canPay.value) {
    items.push({
      title: t('claim.list.filters.pendingAction.notPaid'),
      value: 'NOT_PAID',
    })
  }

  if (canReceive.value) {
    items.splice(1, 0, {
      title: t('claim.list.filters.pendingAction.notReceived'),
      value: 'NOT_RECEIVED',
    })
  }

  return items
})

/** Hanya pilihan "semua" berarti tidak ada yang bisa disaring. */
const showPendingActionFilter = computed(() => pendingActionItems.value.length > 1)

const statusItems = computed(() => [
  { title: t('claim.status.all'), value: '' },
  { title: t('claim.status.draft'), value: 'DRAFT' },
  { title: t('claim.status.inProgress'), value: 'IN PROGRESS' },
  { title: t('claim.status.approved'), value: 'APPROVED' },
  { title: t('claim.status.rejected'), value: 'REJECTED' },
  { title: t('claim.status.cancelled'), value: 'CANCELLED' },
  { title: t('claim.status.received'), value: 'RECEIVED' },
  { title: t('claim.status.paid'), value: 'PAID' },
])

const formatStatus = (status: string | null | undefined): string => {
  const normalized = String(status || '').trim().toUpperCase()

  const map: Record<string, string> = {
    'DRAFT': t('claim.status.draft'),
    'IN PROGRESS': t('claim.status.inProgress'),
    'APPROVED': t('claim.status.approved'),
    'REJECTED': t('claim.status.rejected'),
    'CANCELLED': t('claim.status.cancelled'),
    'RECEIVED': t('claim.status.received'),
    'PAID': t('claim.status.paid'),
  }

  return map[normalized] || (status || '-')
}

const getStatusColor = (status: string | null | undefined): string => {
  const normalized = String(status || '').trim().toUpperCase()

  const map: Record<string, string> = {
    'DRAFT': 'secondary',
    'IN PROGRESS': 'warning',
    'APPROVED': 'success',
    'REJECTED': 'error',
    'CANCELLED': 'error',
    'RECEIVED': 'primary',
    'PAID': 'info',
  }

  return map[normalized] || 'secondary'
}

/*
|--------------------------------------------------------------------------
| Hari pembayaran
|--------------------------------------------------------------------------
| Kasir hanya bekerja pada hari tertentu. Membayar lebih awal maupun
| terlambat tetap boleh, tetapi harus jatuh pada salah satu hari itu.
|
| Jawabannya datang dari server; di sini hanya dibaca. Dokumen lama yang
| belum membawa kolom ini dianggap boleh -- memang tidak ada jadwal yang
| mengikatnya.
|--------------------------------------------------------------------------
*/
/*
| Saringan tanggal pembayaran hanya berguna bagi yang bisa membayar.
| Penjagaan sebenarnya ada di server -- ini semata soal tidak menyodorkan
| kotak yang tidak bisa ditindaklanjuti.
*/
const showScheduledPaymentFilter = computed<boolean>(() => canPay.value)

const canPayTodayRow = (row: ClaimRow): boolean => row.can_pay_today !== false

const isStatus = (row: ClaimRow, status: string): boolean =>
  String(row.status || '').trim().toUpperCase() === status

/** Draft saja yang boleh diubah maupun dihapus, sama seperti FPU. */
const canEditRow = (row: ClaimRow): boolean =>
  canUpdate.value && isStatus(row, 'DRAFT')

const canDeleteRow = (row: ClaimRow): boolean =>
  canDelete.value && isStatus(row, 'DRAFT')

/*
 * can_submit dan can_approve dihitung backend karena bergantung pada
 * kepemilikan dokumen dan baris approval yang sedang aktif -- keduanya
 * tidak bisa disimpulkan dari status saja.
 */
const canSubmitRow = (row: ClaimRow): boolean =>
  Boolean(row.can_submit)

const canApproveRow = (row: ClaimRow): boolean =>
  Boolean(row.can_approve)

/** Pembayaran hanya dari status approved, dan hanya oleh pemegang permission. */
/*
| Penampung pilihan ditaruh di sini karena fetchClaims mengosongkannya, dan
| fungsi itu ditulis jauh di atas fungsi-fungsi tindakan massalnya.
*/
const selectedIds = ref<string[]>([])
const bulkMode = ref<'RECEIVE' | 'PAY' | null>(null)
const bulkLoading = ref(false)

/*
|--------------------------------------------------------------------------
| Tahap penerimaan
|--------------------------------------------------------------------------
| Disetujui belum berarti uangnya diproses: berkasnya harus dinyatakan
| diterima lebih dulu. Dua meja yang berbeda, sama seperti FPU.
|--------------------------------------------------------------------------
*/
const needsReceipt = (row: ClaimRow): boolean =>
  isStatus(row, 'APPROVED')

const canPayRow = (row: ClaimRow): boolean =>
  canPay.value && isStatus(row, 'RECEIVED')

/* Layak dibayar DAN hari ini memang harinya. */
const canPayNowRow = (row: ClaimRow): boolean =>
  canPayRow(row) && canPayTodayRow(row)

/*
 * Pembatalan juga hanya dari approved: draft cukup dihapus, yang masih
 * berjalan bisa ditolak approver, dan yang sudah dibayarkan tidak bisa
 * ditarik kembali. Diperiksa ulang di backend -- pemeriksaan di sini semata
 * agar menu yang pasti ditolak tidak ditawarkan.
 */
const canCancelRow = (row: ClaimRow): boolean =>
  canCancel.value && (isStatus(row, 'APPROVED') || isStatus(row, 'RECEIVED'))

/*
 * Penanda tindakan Finance yang masih ditunggu. Approved saja belum berarti
 * selesai selama uang penggantinya belum diterima pemohon.
 *
 * Hanya ditampilkan kepada pemegang permission pembayaran -- bagi yang lain
 * penanda ini tidak berarti apa-apa karena mereka tidak bisa menindaklanjuti.
 */
const needsPayment = (row: ClaimRow): boolean =>
  canPay.value && isStatus(row, 'RECEIVED')

/** Cetakan baru sah setelah disetujui; setelah dibayarkan pun tetap boleh. */
const canPrintRow = (row: ClaimRow): boolean =>
  isStatus(row, 'APPROVED') || isStatus(row, 'RECEIVED') || isStatus(row, 'PAID')

const getApprovalStatusColor = (status: string | null | undefined): string => {
  const normalized = String(status || '').trim().toUpperCase()

  const map: Record<string, string> = {
    PENDING: 'secondary',
    WAITING: 'warning',
    APPROVED: 'success',
    REJECTED: 'error',
    CANCELLED: 'error',
    SKIPPED: 'info',
  }

  return map[normalized] || 'secondary'
}

const getApprovalStatusIcon = (status: string | null | undefined): string => {
  const normalized = String(status || '').trim().toUpperCase()

  const map: Record<string, string> = {
    PENDING: 'tabler-dots',
    WAITING: 'tabler-clock',
    APPROVED: 'tabler-check',
    REJECTED: 'tabler-x',
    CANCELLED: 'tabler-ban',
    SKIPPED: 'tabler-arrow-forward',
  }

  return map[normalized] || 'tabler-point'
}

/** Waktu keputusan approval, mana pun yang terisi. */
const getApprovalMoment = (approval: DetailApproval): string | null =>
  approval?.approved_at || approval?.rejected_at || null

/*
|--------------------------------------------------------------------------
| Riwayat approval per tahap
|--------------------------------------------------------------------------
| Backend mengirim satu baris per APPROVER, bukan per tahap. Satu tahap yang
| punya dua approver karena itu akan terbaca sebagai dua tahap bila
| ditampilkan apa adanya -- padahal keduanya berbagi urutan yang sama.
|
| Di sini barisnya dikelompokkan kembali menurut step_order, lalu status
| tahapnya disimpulkan dari status para approver-nya.
|--------------------------------------------------------------------------
*/
interface ApprovalStepGroup {
  step_order: number
  label: string | null
  status: string
  approval_mode: string
  approvers: DetailApproval[]
  visibleApprovers: DetailApproval[]
  moment: string | null
}

/**
 * Status satu tahap, disimpulkan dari para approver di dalamnya.
 *
 * Penolakan mengalahkan apa pun: satu penolakan sudah menghentikan dokumen.
 * Pada mode ANY satu persetujuan menuntaskan tahapnya, sedangkan pada mode
 * ALL tahap baru selesai bila seluruh approver menyetujui.
 */
const resolveStepStatus = (approvers: DetailApproval[], mode: string): string => {
  const statuses = approvers.map(row => String(row?.status || '').trim().toUpperCase())

  if (statuses.includes('REJECTED'))
    return 'REJECTED'

  if (statuses.includes('CANCELLED'))
    return 'CANCELLED'

  const approved = statuses.filter(status => status === 'APPROVED').length

  if (approved > 0 && (mode === 'ANY' || approved === statuses.length))
    return 'APPROVED'

  if (statuses.includes('WAITING'))
    return 'WAITING'

  return statuses[0] ?? 'PENDING'
}

const detailApprovalSteps = computed<ApprovalStepGroup[]>(() => {
  const daftarApproval = (detail.value?.approvals ?? []) as DetailApproval[]
  const perStep = new Map<number, DetailApproval[]>()

  daftarApproval.forEach(row => {
    const order = Number(row?.step_order ?? 0)

    perStep.set(order, [...(perStep.get(order) ?? []), row])
  })

  return [...perStep.entries()]
    .sort(([a], [b]) => a - b)
    .map(([order, approvers]) => {
      const mode = String(approvers[0]?.approval_mode || 'ANY').trim().toUpperCase()

      /* Waktu keputusan tahap: yang terakhir di antara para approver-nya. */
      const moment = approvers
        .map(row => getApprovalMoment(row))
        .filter((value): value is string => Boolean(value))
        .sort()
        .pop() ?? null

      /*
      | Approver yang dilewati disembunyikan. Kalau semuanya dilewati,
      | daftarnya dipakai apa adanya supaya tahapnya tidak tampil kosong.
      */
      const bertindak = approvers.filter(row => String(row?.status || '').trim().toUpperCase() !== 'SKIPPED')

      return {
        step_order: order,
        label: approvers.find(row => row?.label)?.label ?? null,
        status: resolveStepStatus(approvers, mode),
        approval_mode: mode,
        approvers,
        visibleApprovers: bertindak.length > 0 ? bertindak : approvers,
        moment,
      }
    })
})

/*
 * Approver yang sedang ditunggu persetujuannya.
 *
 * can_approve dihitung backend dari baris approval berstatus WAITING yang
 * cocok dengan user login, sehingga statusnya tidak perlu diperiksa ulang di
 * sini -- dokumen yang sudah selesai tidak punya baris WAITING sama sekali.
 */
/*
|--------------------------------------------------------------------------
| Jadwal pembayaran pada detail
|--------------------------------------------------------------------------
| Tanggalnya dibekukan saat berkas diterima dan sudah dikirimkan ke pemohon,
| jadi yang ditampilkan di sini adalah janji yang sama persis -- bukan hasil
| hitungan ulang yang bisa berbeda.
|--------------------------------------------------------------------------
*/
const detailPaymentSchedule = computed<{
  date: string
  color: 'info' | 'success' | 'warning' | 'error'
  icon: string
  label: string
} | null>(() => {
  const source = detail.value

  if (!source?.scheduled_payment_date)
    return null

  /* Belum dibayar dan tanggalnya sudah lewat adalah satu-satunya yang mendesak. */
  const tampilan: Record<string, { color: 'info' | 'success' | 'warning' | 'error'; icon: string; label: string }> = {
    ON_TRACK: { color: 'info', icon: 'tabler-calendar-event', label: t('claim.detail.paymentOnTrack') },
    OVERDUE: { color: 'error', icon: 'tabler-alert-triangle', label: t('claim.detail.paymentOverdue') },
    EARLY: { color: 'success', icon: 'tabler-rocket', label: t('claim.detail.paymentEarly') },
    ON_TIME: { color: 'success', icon: 'tabler-circle-check', label: t('claim.detail.paymentOnTime') },
    LATE: { color: 'warning', icon: 'tabler-clock-exclamation', label: t('claim.detail.paymentLate') },
  }

  const dipakai = tampilan[String(source.payment_timing || '')] ?? tampilan.ON_TRACK

  return {
    date: formatDate(source.scheduled_payment_date),
    ...dipakai,
  }
})

const detailCanApprove = computed<boolean>(() => Boolean(detail.value?.can_approve))

/** Ikon lampiran mengikuti jenis berkasnya. */
const getAttachmentIcon = (mimeType: string | null | undefined): string => {
  const normalized = String(mimeType || '').toLowerCase()

  if (normalized.includes('pdf'))
    return 'mdi-file-pdf-box'

  if (normalized.startsWith('image/'))
    return 'mdi-file-image-outline'

  return 'mdi-file-outline'
}

const detailAccent = computed<string>(() => getStatusColor(detail.value?.status))

const paginationData = computed(() => {
  const first = totalData.value === 0
    ? 0
    : ((currentPage.value - 1) * rowPerPage.value) + 1

  const last = Math.min(currentPage.value * rowPerPage.value, totalData.value)

  return `${first}-${last} of ${totalData.value}`
})

/*
|--------------------------------------------------------------------------
| Pengambilan data
|--------------------------------------------------------------------------
*/
/**
 * Penyaring yang dikirim ke server.
 *
 * Dipakai bersama oleh permintaan daftar dan permintaan export supaya berkas
 * yang diunduh selalu berisi tepat baris yang sedang terlihat di layar.
 */
const buildFilterParams = (): Record<string, unknown> => ({
  search: searchQuery.value || undefined,
  start_date: startDate.value || undefined,
  end_date: endDate.value || undefined,
  scheduled_payment_date: scheduledPaymentDate.value || undefined,
  status: selectedStatus.value || undefined,
  pending_action: selectedPendingAction.value || undefined,
  branch: selectedBranch.value || undefined,
  department_id: selectedDepartment.value || undefined,
  waiting_my_approval: onlyWaitingMyApproval.value ? 1 : undefined,
})

const fetchClaims = async (): Promise<void> => {
  loading.value = true
  loadError.value = false

  try {
    const response = await axios.get('/fund-request/claim', {
      headers: { Accept: 'application/json' },
      params: {
        page: currentPage.value,
        per_page: rowPerPage.value,
        ...buildFilterParams(),
      },
    })

    const payload = response.data

    rows.value = Array.isArray(payload?.data) ? payload.data : []

    /* Lihat keterangan pada blok pilih banyak dokumen. */
    selectedIds.value = []
    bulkMode.value = null

    abilities.value = {
      ...defaultAbilities(),
      ...(payload?.abilities || {}),
    }

    paymentDays.value = Array.isArray(payload?.payment_days)
      ? payload.payment_days.map(Number)
      : []

    totalData.value = Number(payload?.meta?.total || 0)
    totalPage.value = Number(payload?.meta?.last_page || 1)
  }
  catch (error: unknown) {
    loadError.value = true
    rows.value = []
    totalData.value = 0
    totalPage.value = 1

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.list.toast.loadFailed')),
    })
  }
  finally {
    loading.value = false
  }
}

usePolling(fetchClaims, { interval: 30000 })

/**
 * Memuat isi kedua dropdown filter.
 *
 * Baru dipanggil setelah scope diketahui dari respons daftar, dan hanya bagi
 * pembaca yang memang boleh memilihnya -- pemakai OWN_CABANG dan OWN_DATA
 * tidak perlu membayar permintaan yang hasilnya tidak akan dipakai.
 */
const fetchFilterOptions = async (): Promise<void> => {
  if (!canFilterBranch.value || isLoadingFilterOptions.value)
    return

  isLoadingFilterOptions.value = true

  try {
    /*
     * Daftar departemen hanya diambil bila memang bisa dipilih; pemakai scope
     * OWN_DEPARTMENT tidak perlu membayar permintaan yang hasilnya tidak
     * akan dipakai.
     */
    const [cabang, department] = await Promise.all([
      axios.get('/master/cabang/dropdown-select', { headers: { Accept: 'application/json' } }),

      canFilterDepartment.value
        ? axios.get('/master/department/dropdown-select', { headers: { Accept: 'application/json' } })
        : Promise.resolve(null),
    ])

    const daftarCabang = cabang?.data?.data
    const daftarDepartment = department?.data?.data

    branchOptions.value = Array.isArray(daftarCabang) ? daftarCabang : []
    departmentOptions.value = Array.isArray(daftarDepartment) ? daftarDepartment : []
  }
  catch (error: unknown) {
    console.error('[claim] FILTER OPTIONS ERROR:', error)

    branchOptions.value = []
    departmentOptions.value = []
  }
  finally {
    isLoadingFilterOptions.value = false
  }
}

const resetFilters = async (): Promise<void> => {
  searchQuery.value = ''
  startDate.value = null
  endDate.value = null
  scheduledPaymentDate.value = null
  selectedStatus.value = ''
  selectedPendingAction.value = ''
  selectedBranch.value = ''
  selectedDepartment.value = null
  onlyWaitingMyApproval.value = false
  currentPage.value = 1

  await fetchClaims()
}

/*
|--------------------------------------------------------------------------
| Aksi
|--------------------------------------------------------------------------
*/
const goToCreate = async (): Promise<void> => {
  await router.push('/fund_request/claim/create')
}

const goToEdit = async (row: ClaimRow): Promise<void> => {
  await router.push(`/fund_request/claim/edit?id=${encodeURIComponent(row.public_id)}`)
}

/*
|--------------------------------------------------------------------------
| Tanda tangan digital
|--------------------------------------------------------------------------
*/
const checkUserSignature = async (): Promise<boolean> => {
  const response = await axios.get('/master/user/check-signature', {
    headers: { Accept: 'application/json' },
  })

  return response.data?.has_signature === true
}

const resizeSignatureCanvas = (): void => {
  const canvas = signatureCanvasRef.value

  if (!canvas)
    return

  const ratio = Math.max(window.devicePixelRatio || 1, 1)
  const rect = canvas.getBoundingClientRect()

  canvas.width = rect.width * ratio
  canvas.height = rect.height * ratio

  const context = canvas.getContext('2d')

  if (!context)
    return

  context.setTransform(ratio, 0, 0, ratio, 0, 0)

  signaturePad.value?.clear()
}

const initSignaturePad = (): void => {
  const canvas = signatureCanvasRef.value

  if (!canvas)
    return

  const rect = canvas.getBoundingClientRect()

  if (!rect.width || !rect.height) {
    setTimeout(initSignaturePad, 200)

    return
  }

  signaturePad.value?.off()

  signaturePad.value = new SignaturePad(canvas, {
    minWidth: 0.8,
    maxWidth: 2.4,
    throttle: 16,
    penColor: 'black',
    backgroundColor: 'rgba(255,255,255,0)',
  })

  resizeSignatureCanvas()
}

const openSignatureDialog = async (): Promise<void> => {
  signatureError.value = ''
  signatureAgree.value = false
  signatureDialog.value = true

  await nextTick()

  setTimeout(initSignaturePad, 300)
}

/*
|--------------------------------------------------------------------------
| Ajukan
|--------------------------------------------------------------------------
*/
const submitClaim = async (row: ClaimRow): Promise<void> => {
  if (!row?.public_id)
    return

  const confirm = await showConfirmAlert({
    title: t('claim.list.toast.submitConfirmTitle'),
    text: t('claim.list.toast.submitConfirmText', { nomor: row.claim_number }),
    confirmButtonText: t('claim.list.toast.submitConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed)
    return

  try {
    showLoadingAlert(
      t('claim.list.toast.submitLoading'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/claim/${row.public_id}/submit`,
      {},
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('claim.list.toast.submitSuccessFallback'),
    })

    await fetchClaims()
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.list.toast.submitFailedFallback')),
    })
  }
}

const openSubmit = async (row: ClaimRow): Promise<void> => {
  pendingAction.value = 'submit'
  pendingRow.value = row

  try {
    if (!await checkUserSignature()) {
      await openSignatureDialog()

      return
    }

    await submitClaim(row)
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.list.toast.signatureCheckFailed')),
    })
  }
}

/*
|--------------------------------------------------------------------------
| Setujui
|--------------------------------------------------------------------------
*/
const showApproveDialog = (row: ClaimRow): void => {
  approveTarget.value = row
  approveNotes.value = ''
  approveDialog.value = true
}

const openApprove = async (row: ClaimRow): Promise<void> => {
  pendingAction.value = 'approve'
  pendingRow.value = row

  try {
    if (!await checkUserSignature()) {
      await openSignatureDialog()

      return
    }

    showApproveDialog(row)
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.list.toast.signatureCheckFailed')),
    })
  }
}

const approveClaim = async (): Promise<void> => {
  if (!approveTarget.value?.public_id || approveLoading.value)
    return

  approveLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/claim/${approveTarget.value.public_id}/approve`,
      { notes: approveNotes.value || null },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    approveDialog.value = false
    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('claim.list.approve.successFallback'),
    })

    await fetchClaims()
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.list.approve.failedFallback')),
    })
  }
  finally {
    approveLoading.value = false
  }
}

const saveSignatureAndContinue = async (): Promise<void> => {
  if (!signaturePad.value || signaturePad.value.isEmpty()) {
    signatureError.value = t('claim.list.signature.required')

    return
  }

  if (!signatureAgree.value) {
    signatureError.value = t('claim.list.signature.agreementRequired')

    return
  }

  try {
    signatureLoading.value = true
    signatureError.value = ''

    await axios.post(
      '/master/user/store-signature',
      { signature: signaturePad.value.toDataURL('image/png') },
      {
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      },
    )

    signatureDialog.value = false

    await nextTick()

    if (pendingAction.value === 'submit' && pendingRow.value)
      await submitClaim(pendingRow.value)

    if (pendingAction.value === 'approve' && pendingRow.value)
      showApproveDialog(pendingRow.value)
  }
  catch (error: unknown) {
    signatureError.value = getApiErrorMessage(
      error,
      t('claim.list.signature.saveFailed'),
    )
  }
  finally {
    signatureLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Tolak
|--------------------------------------------------------------------------
*/
const openReject = (row: ClaimRow): void => {
  rejectTarget.value = row
  rejectNotes.value = ''
  rejectError.value = ''
  rejectDialog.value = true
}

const rejectClaim = async (): Promise<void> => {
  if (!rejectTarget.value?.public_id || rejectLoading.value)
    return

  if (!rejectNotes.value.trim()) {
    rejectError.value = t('claim.list.reject.notesRequired')

    return
  }

  rejectLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/claim/${rejectTarget.value.public_id}/reject`,
      { notes: rejectNotes.value },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    rejectDialog.value = false
    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('claim.list.reject.successFallback'),
    })

    await fetchClaims()
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.list.reject.failedFallback')),
    })
  }
  finally {
    rejectLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Bayar
|--------------------------------------------------------------------------
| Tahap terakhir. Tidak ada realisasi setelahnya -- bukti pengeluarannya
| sudah menempel pada dokumen sejak dibuat.
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| Penegasan, bukan formulir
|--------------------------------------------------------------------------
| Tindakan ini hanya menandai bahwa dananya sudah berpindah. Tidak ada yang
| perlu diketik maupun diunggah, jadi cukup satu pertanyaan penegasan --
| dijawab ya, statusnya langsung diperbarui.
|
| Kolom catatan dan lampiran di database sengaja dibiarkan. Endpoint-nya pun
| masih menerima keduanya; layar ini saja yang tidak lagi mengirimnya.
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| Pilih banyak dokumen
|--------------------------------------------------------------------------
| Finance lazim membayar sekumpulan claim sekaligus, bukan satu per satu.
| Baris yang boleh dipilih hanya yang memang menunggu pembayaran -- baris lain
| tidak diberi kotak centang sama sekali, supaya tidak ada yang mengira
| bisa ikut diproses.
|
| Pilihan dikosongkan setiap kali daftarnya dimuat ulang: setelah berpindah
| halaman atau berganti penyaring, baris yang tadi tercentang belum tentu
| masih ada di layar, dan memproses dokumen yang tidak terlihat adalah hal
| terakhir yang diinginkan siapa pun.
|--------------------------------------------------------------------------
*/
/*
| Halaman ini kini punya dua tindakan pada daftar yang sama, sedangkan
| tombolnya hanya satu. Karena itu pilihannya dikunci pada satu tindakan:
| baris pertama yang dicentang menentukannya, dan baris yang membutuhkan
| tindakan lain dinonaktifkan selama pilihan itu berjalan.
|
| Satu tombol yang mengerjakan dua hal berbeda pada baris berbeda adalah cara
| paling mudah membuat orang salah menekan.
*/
/** Kolom centang tidak perlu ada bila tidak ada satu pun yang bisa diproses. */
const canBulkSelect = computed<boolean>(() => canReceive.value || canPay.value)

/** Tindakan massal yang dibutuhkan satu baris, bila ada. */
const rowBulkAction = (row: ClaimRow): 'RECEIVE' | 'PAY' | null => {
  if (canReceive.value && needsReceipt(row))
    return 'RECEIVE'

  if (canPayNowRow(row))
    return 'PAY'

  return null
}

const isSelected = (row: ClaimRow): boolean =>
  selectedIds.value.includes(String(row.public_id))

/** Baris boleh dicentang bila tindakannya sama dengan yang sedang berjalan. */
const isSelectable = (row: ClaimRow): boolean => {
  const aksi = rowBulkAction(row)

  if (aksi === null)
    return false

  return bulkMode.value === null || bulkMode.value === aksi
}

const selectableRows = computed<ClaimRow[]>(() =>
  rows.value.filter(row => isSelectable(row)))

const allSelected = computed<boolean>(() =>
  selectableRows.value.length > 0
  && selectableRows.value.every(row => isSelected(row)))

const someSelected = computed<boolean>(() =>
  selectedIds.value.length > 0 && !allSelected.value)

const toggleRow = (row: ClaimRow): void => {
  const id = String(row.public_id)

  if (isSelected(row)) {
    selectedIds.value = selectedIds.value.filter(item => item !== id)

    /* Pilihan yang habis melepas kuncian tindakannya. */
    if (selectedIds.value.length === 0)
      bulkMode.value = null

    return
  }

  if (!isSelectable(row))
    return

  bulkMode.value = rowBulkAction(row)
  selectedIds.value = [...selectedIds.value, id]
}

const toggleAll = (): void => {
  if (allSelected.value) {
    selectedIds.value = []
    bulkMode.value = null

    return
  }

  /*
  | Tanpa tindakan yang sedang berjalan, yang dipakai adalah tindakan baris
  | pertama yang bisa diproses -- bukan mencampur keduanya.
  */
  if (bulkMode.value === null) {
    const pertama = rows.value.find(row => rowBulkAction(row) !== null)

    if (!pertama)
      return

    bulkMode.value = rowBulkAction(pertama)
  }

  selectedIds.value = rows.value
    .filter(row => rowBulkAction(row) === bulkMode.value)
    .map(row => String(row.public_id))
}

const clearSelection = (): void => {
  selectedIds.value = []
  bulkMode.value = null
}

/** Kunci teks yang dipakai bilah aksi, mengikuti tindakan yang berjalan. */
const bulkTextKey = computed<string>(() =>
  `claim.list.bulk.${bulkMode.value ?? 'RECEIVE'}`)

const runBulkAction = async (): Promise<void> => {
  if (selectedIds.value.length === 0 || bulkMode.value === null || bulkLoading.value)
    return

  const konfirmasi = await showConfirmAlert({
    title: t(`${bulkTextKey.value}.confirmTitle`),
    text: t(`${bulkTextKey.value}.confirmText`, { count: selectedIds.value.length }),
    confirmButtonText: t(`${bulkTextKey.value}.confirmButton`),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!konfirmasi.isConfirmed)
    return

  bulkLoading.value = true

  try {
    showLoadingAlert(
      t(`${bulkTextKey.value}.loadingTitle`, { count: selectedIds.value.length }),
      t('common.alert.pleaseWait'),
    )

    const endpoint = bulkMode.value === 'RECEIVE'
      ? '/fund-request/claim/bulk-receive'
      : '/fund-request/claim/bulk-pay'

    const response = await axios.post(
      endpoint,
      { public_ids: selectedIds.value },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    const gagal = response.data?.data?.failed ?? []

    /*
    | Hasil sebagian dilaporkan sebagai peringatan, bukan keberhasilan biasa.
    | Dokumen yang gagal disebut satu per satu supaya jelas mana yang masih
    | perlu ditangani.
    */
    if (gagal.length > 0) {
      showWarningToast({
        title: t(`${bulkTextKey.value}.partialTitle`),
        text: [
          response.data?.message,
          ...gagal.map((item: { number: string; reason: string }) =>
            `${item.number}: ${item.reason}`),
        ].filter(Boolean).join(' '),
      })
    }
    else {
      showSuccessToast({
        title: t('common.alert.success'),
        text: response.data?.message || t(`${bulkTextKey.value}.successFallback`),
      })
    }

    clearSelection()

    await fetchClaims()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t(`${bulkTextKey.value}.failedFallback`)),
    })
  }
  finally {
    bulkLoading.value = false
  }
}

/*
| Penegasan saja, tanpa formulir. Tidak ada yang perlu diketik maupun
| diunggah -- kolom catatan dan lampirannya tetap ada di database, layar ini
| saja yang tidak mengirimnya.
*/
const openReceive = async (row: ClaimRow): Promise<void> => {
  if (!row?.public_id || receiveLoading.value)
    return

  const konfirmasi = await showConfirmAlert({
    title: t('claim.list.receive.confirmTitle'),
    text: t('claim.list.receive.confirmText', { nomor: row.claim_number || '-' }),
    confirmButtonText: t('claim.list.receive.confirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!konfirmasi.isConfirmed)
    return

  receiveLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/claim/${row.public_id}/receive`,
      {},
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('claim.list.receive.successFallback'),
    })

    await fetchClaims()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.list.receive.failedFallback')),
    })
  }
  finally {
    receiveLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Pembayaran di luar jadwal
|--------------------------------------------------------------------------
| Jadwalnya penanda, bukan pengunci. Finance tetap boleh membayar lebih awal
| maupun terlambat -- hanya saja saat itu terjadi, disediakan isian catatan
| singkat. Tetap opsional: dikosongkan pun pembayarannya jalan.
|--------------------------------------------------------------------------
*/
/** Ada baris yang menunggu dibayar tetapi hari ini bukan harinya. */
const blockedByPaymentDay = computed<boolean>(() =>
  rows.value.some(row => row.can_pay_today === false))

const paymentDeviation = (row: ClaimRow): 'EARLY' | 'LATE' | null => {
  if (!row.scheduled_payment_date)
    return null

  const jadwal = new Date(String(row.scheduled_payment_date))

  jadwal.setHours(0, 0, 0, 0)

  const hariIni = new Date()

  hariIni.setHours(0, 0, 0, 0)

  if (hariIni.getTime() < jadwal.getTime())
    return 'EARLY'

  return hariIni.getTime() > jadwal.getTime() ? 'LATE' : null
}

/** Dialog penegasan untuk pembayaran di luar jadwal. */
const offScheduleDialog = ref<{ open: (isi: Record<string, unknown>) => Promise<{ confirmed: boolean; notes: string }> } | null>(null)

/**
 * Menanyakan kesediaan melanjutkan pembayaran, lalu mengembalikan catatannya.
 *
 * Pembayaran yang TEPAT jadwal cukup lewat penegasan biasa. Yang menyimpang
 * lewat dialog tersendiri, karena di situ ada keadaan yang perlu ditonjolkan
 * dan satu isian catatan.
 */
const askPaymentConfirmation = async (
  row: ClaimRow,
  judul: string,
  pesan: string,
  tombol: string,
): Promise<{ lanjut: boolean; catatan: string }> => {
  const simpangan = paymentDeviation(row)

  if (!simpangan) {
    const konfirmasi = await showConfirmAlert({
      title: judul,
      text: pesan,
      confirmButtonText: tombol,
      cancelButtonText: t('common.actions.cancel'),
    })

    return { lanjut: konfirmasi.isConfirmed === true, catatan: '' }
  }

  const hasil = await offScheduleDialog.value?.open({
    ns: 'claim',
    title: judul,
    message: pesan,
    confirmText: tombol,
    deviation: simpangan,
    scheduledDate: String(row.scheduled_payment_date ?? ''),
  })

  return { lanjut: hasil?.confirmed === true, catatan: hasil?.notes ?? '' }
}

const openPay = async (row: ClaimRow): Promise<void> => {
  if (!row?.public_id || payLoading.value)
    return

  const { lanjut, catatan } = await askPaymentConfirmation(
    row,
    t('claim.list.pay.confirmTitle'),
    t('claim.list.pay.confirmText', { nomor: row.claim_number || '-' }),
    t('claim.list.pay.confirmButton'),
  )

  if (!lanjut)
    return

  payLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    /*
     * PATCH biasa, bukan multipart: tanpa berkas, tidak perlu lagi method
     * spoofing seperti sebelumnya.
     */
    const response = await axios.patch(
      `/fund-request/claim/${row.public_id}/pay`,
      { notes: catatan },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('claim.list.pay.successFallback'),
    })

    await fetchClaims()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.list.pay.failedFallback')),
    })
  }
  finally {
    payLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Batalkan
|--------------------------------------------------------------------------
*/
const openCancel = (row: ClaimRow): void => {
  cancelTarget.value = row
  cancelNotes.value = ''
  cancelError.value = ''
  cancelDialog.value = true
}

const cancelClaim = async (): Promise<void> => {
  if (!cancelTarget.value?.public_id || cancelLoading.value)
    return

  if (!cancelNotes.value.trim()) {
    cancelError.value = t('claim.list.cancel.notesRequired')

    return
  }

  cancelLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/claim/${cancelTarget.value.public_id}/cancel`,
      { notes: cancelNotes.value },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    cancelDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('claim.list.cancel.successFallback'),
    })

    await fetchClaims()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.list.cancel.failedFallback')),
    })
  }
  finally {
    cancelLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Export Excel
|--------------------------------------------------------------------------
| Mengirim filter yang sedang aktif supaya isi file sama dengan yang terlihat
| di layar. Tanpa filter, seluruh Claim yang boleh dilihat user ikut terekspor.
|
| responseType 'blob' wajib -- tanpa itu Axios memperlakukan biner xlsx
| sebagai teks dan berkas hasil unduhan akan rusak.
|--------------------------------------------------------------------------
*/
const isExporting = ref(false)

const exportExcel = async (): Promise<void> => {
  if (isExporting.value)
    return

  isExporting.value = true

  try {
    showLoadingAlert(
      t('claim.list.toast.exportLoading'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.get('/fund-request/claim/export-excel', {
      params: {
        lang: locale.value === 'en' ? 'en' : 'id',
        ...buildFilterParams(),
      },
      responseType: 'blob',
    })

    /*
     * Nama berkas diambil dari header Content-Disposition supaya mengikuti
     * penamaan backend, termasuk timestamp-nya.
     */
    const disposition = String(response.headers?.['content-disposition'] ?? '')
    const matched = disposition.match(/filename="?([^";]+)"?/i)

    const fileName = matched?.[1]
      ? decodeURIComponent(matched[1].trim())
      : 'Claim.xlsx'

    const blobUrl = window.URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')

    link.href = blobUrl
    link.setAttribute('download', fileName)
    document.body.appendChild(link)
    link.click()
    link.remove()

    window.URL.revokeObjectURL(blobUrl)

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('claim.list.toast.exportSuccess'),
    })
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: t('claim.list.toast.exportFailed'),
    })
  }
  finally {
    isExporting.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Cetak PDF
|--------------------------------------------------------------------------
| Mengikuti pola cetak FPU: tab dibuka lebih dulu, sebelum permintaan async,
| supaya tidak diblokir popup blocker.
|
| Tab itu langsung diisi halaman loading, bukan dibiarkan kosong. Halaman itu
| tetap terlihat selama browser menunggu server merender PDF, karena navigasi
| baru mengganti isi tab setelah responsnya datang.
|--------------------------------------------------------------------------
*/
const printLoadingId = ref<string | null>(null)

const printDocument = async (row: ClaimRow): Promise<void> => {
  if (!row.public_id || printLoadingId.value)
    return

  printLoadingId.value = row.public_id

  const loadingTitle = t('claim.list.print.loading')
  const loadingText = t('common.alert.pleaseWait')

  let printWindow: Window | null = null

  try {
    showLoadingAlert(loadingTitle, loadingText)

    printWindow = window.open('', '_blank')

    if (!printWindow)
      throw new Error(t('claim.list.print.popupBlocked'))

    printWindow.document.open()
    printWindow.document.write(buildPrintLoadingPage(loadingTitle, loadingText))
    printWindow.document.close()

    /*
     * Hanya meminta signed URL. Berkas PDF-nya sendiri diunduh browser saat
     * navigasi di bawah, jadi Axios tidak ikut menunggu render selesai.
     */
    const response = await axios.post(
      `/fund-request/claim/${encodeURIComponent(row.public_id)}/print-url`,
      null,
      { headers: { Accept: 'application/json' } },
    )

    const printUrl = response.data?.url

    if (response.data?.success === false || typeof printUrl !== 'string' || !printUrl.trim())
      throw new Error(response.data?.message || t('claim.list.print.urlFailed'))

    if (printWindow.closed)
      throw new Error(t('claim.list.print.windowClosed'))

    closeAlert()

    printWindow.location.replace(new URL(printUrl, window.location.origin).toString())
  }
  catch (error: unknown) {
    closeAlert()

    if (printWindow && !printWindow.closed)
      printWindow.close()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.list.print.failed')),
    })
  }
  finally {
    printLoadingId.value = null
  }
}

const openDelete = async (row: ClaimRow): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: t('claim.list.toast.deleteConfirmTitle'),
    text: t('claim.list.toast.deleteConfirmText', { nomor: row.claim_number }),
    confirmButtonText: t('common.alert.deleteConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed)
    return

  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    const response = await axios.delete(
      `/fund-request/claim/${row.public_id}`,
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('claim.list.toast.deleteSuccess'),
    })

    await fetchClaims()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.list.toast.deleteFailed')),
    })
  }
}

const openDetail = async (publicId: string): Promise<void> => {
  detailDialog.value = true
  detailLoading.value = true
  detailError.value = ''
  detail.value = null

  try {
    const response = await axios.get(`/fund-request/claim/${publicId}`, {
      headers: { Accept: 'application/json' },
    })

    detail.value = response.data?.data ?? null

    if (!detail.value)
      detailError.value = t('claim.detail.loadFailed')
  }
  catch (error: unknown) {
    detailError.value = getApiErrorMessage(error, t('claim.detail.loadFailed'))
  }
  finally {
    detailLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Pemantau
|--------------------------------------------------------------------------
*/
watch(currentPage, async () => {
  await fetchClaims()
})

watch(rowPerPage, async () => {
  currentPage.value = 1
  await fetchClaims()
})

watch(
  [
    searchQuery,
    selectedStatus,
    selectedPendingAction,
    startDate,
    endDate,
    scheduledPaymentDate,
    onlyWaitingMyApproval,
    selectedBranch,
    selectedDepartment,
  ],
  async () => {
    currentPage.value = 1
    await fetchClaims()
  },
)

/*
 * Rentang tanggal terbalik dikoreksi langsung supaya query tidak pernah
 * dikirim dalam keadaan mustahil.
 */
watch(endDate, newValue => {
  if (newValue && startDate.value && newValue < startDate.value)
    startDate.value = newValue
})

watch(startDate, newValue => {
  if (newValue && endDate.value && newValue > endDate.value)
    endDate.value = newValue
})

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canView.value) {
    await router.replace('/forbidden')

    return
  }

  isCheckingPermission.value = false

  await fetchClaims()

  /* Scope baru diketahui dari respons daftar, jadi pilihannya menyusul. */
  await fetchFilterOptions()

  window.addEventListener('resize', resizeSignatureCanvas)

  const success = route.query.success

  if (success) {
    await router.replace({ path: '/fund_request/claim', query: {} })

    setTimeout(() => {
      if (success === 'created') {
        showSuccessToast({
          title: t('common.alert.success'),
          text: t('claim.list.toast.createdSuccess'),
        })
      }

      if (success === 'updated') {
        showSuccessToast({
          title: t('common.alert.success'),
          text: t('claim.list.toast.updatedSuccess'),
        })
      }
    }, 300)
  }
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
                {{ t('claim.list.filtersTitle') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('claim.list.filtersSubtitle') }}
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
            {{ t('claim.list.filters.resetButton') }}
          </VBtn>
        </div>

        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <VTextField
              v-model="searchQuery"
              :label="t('claim.list.filters.searchLabel')"
              :placeholder="t('claim.list.filters.searchPlaceholder')"
              density="compact"
              prepend-inner-icon="tabler-search"
              clearable
              hide-details
            />
          </VCol>

          <!--
            Saringan tanggal PEMBAYARAN -- berbeda dari saringan tanggal
            dokumen di sebelahnya. Diberi ikon dan warna supaya keduanya tidak
            tertukar.
          -->
          <VCol
            v-if="showScheduledPaymentFilter"
            cols="12"
            md="3"
          >
            <AppDateTimePicker
              v-model="scheduledPaymentDate"
              :label="t('claim.list.filters.scheduledPaymentDate')"
              density="compact"
              clearable
              prepend-inner-icon="tabler-calendar-dollar"
              base-color="primary"
              color="primary"
              :config="{ dateFormat: 'Y-m-d', position: 'below' }"
            />
          </VCol>

          <VCol
            cols="12"
            md="3"
          >
            <AppDateTimePicker
              v-model="startDate"
              :label="t('claim.list.filters.startDate')"
              density="compact"
              clearable
              :config="{ dateFormat: 'Y-m-d', position: 'below' }"
            />
          </VCol>

          <VCol
            cols="12"
            md="3"
          >
            <AppDateTimePicker
              v-model="endDate"
              :label="t('claim.list.filters.endDate')"
              density="compact"
              clearable
              :config="{ dateFormat: 'Y-m-d', position: 'below' }"
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <VSelect
              v-model="selectedStatus"
              :label="t('claim.list.filters.statusLabel')"
              :items="statusItems"
              item-title="title"
              item-value="value"
              density="compact"
              prepend-inner-icon="tabler-progress-check"
              hide-details
            />
          </VCol>

          <!--
            Muncul hanya bila pembacanya memang bisa melihat lintas cabang.
            Pemakai scope OWN_CABANG tidak melihatnya karena cabangnya sudah
            terkunci dari akun yang login.
          -->
          <VCol
            v-if="canFilterBranch"
            cols="12"
            md="4"
          >
            <VAutocomplete
              v-model="selectedBranch"
              :label="t('claim.list.filters.branchLabel')"
              :items="branchItems"
              item-title="title"
              item-value="value"
              density="compact"
              prepend-inner-icon="tabler-building-store"
              :loading="isLoadingFilterOptions"
              :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
              hide-details
            />
          </VCol>

          <VCol
            v-if="canFilterDepartment"
            cols="12"
            md="4"
          >
            <VAutocomplete
              v-model="selectedDepartment"
              :label="t('claim.list.filters.departmentLabel')"
              :items="departmentItems"
              item-title="title"
              item-value="value"
              density="compact"
              prepend-inner-icon="tabler-users-group"
              :loading="isLoadingFilterOptions"
              :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
              hide-details
            />
          </VCol>

          <!--
            Pembayaran adalah aksi berpermission, jadi filternya hanya ada bagi
            yang berwenang menjalankannya.
          -->
          <VCol
            v-if="showPendingActionFilter"
            cols="12"
            md="4"
          >
            <VSelect
              v-model="selectedPendingAction"
              :label="t('claim.list.filters.pendingActionLabel')"
              :items="pendingActionItems"
              item-title="title"
              item-value="value"
              density="compact"
              prepend-inner-icon="tabler-alarm"
              hide-details
            />
          </VCol>

          <VCol
            cols="12"
            :md="showPendingActionFilter ? 4 : 8"
          >
            <div class="cl-approval-filter">
              <div class="d-flex align-center gap-3 min-w-0">
                <VAvatar
                  size="34"
                  :color="onlyWaitingMyApproval ? 'warning' : 'secondary'"
                  variant="tonal"
                >
                  <VIcon
                    icon="tabler-user-check"
                    size="19"
                  />
                </VAvatar>

                <div class="text-caption text-medium-emphasis">
                  {{ t('claim.list.onlyMyApproval') }}
                </div>
              </div>

              <VSwitch
                v-model="onlyWaitingMyApproval"
                color="warning"
                inset
                hide-details
              />
            </div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- BILAH TINDAKAN MASSAL -->
    <VCard
      v-if="selectedIds.length"
      class="cl-bulk-bar mb-4"
    >
      <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-3">
        <div class="d-flex align-center gap-3 min-w-0">
          <VAvatar
            size="36"
            color="success"
            variant="tonal"
            rounded
          >
            <VIcon
              icon="tabler-checkbox"
              size="20"
            />
          </VAvatar>

          <div class="font-weight-medium">
            {{ t(`${bulkTextKey}.selectedLabel`, { count: selectedIds.length }) }}
          </div>
        </div>

        <div class="d-flex align-center gap-2 flex-wrap">
          <VBtn
            variant="tonal"
            color="secondary"
            size="small"
            class="text-none"
            :disabled="bulkLoading"
            @click="clearSelection"
          >
            {{ t('claim.list.bulk.clearButton') }}
          </VBtn>

          <VBtn
            color="success"
            size="small"
            class="text-none"
            :prepend-icon="bulkMode === 'RECEIVE' ? 'tabler-inbox' : 'tabler-cash-banknote'"
            :loading="bulkLoading"
            @click="runBulkAction"
          >
            {{ t(`${bulkTextKey}.actionButton`) }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>

    <!-- Kenapa tombol bayarnya mati hari ini -->
    <VAlert
      v-if="blockedByPaymentDay"
      type="info"
      variant="tonal"
      density="comfortable"
      class="mb-4"
      icon="tabler-calendar-off"
    >
      {{ t('claim.list.paymentDay.blockedNotice', { days: paymentDaysText }) }}
    </VAlert>

    <!-- TABEL -->
    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <VBtn
          v-if="canCreate"
          color="primary"
          prepend-icon="tabler-plus"
          class="text-none"
          @click="goToCreate"
        >
          {{ t('claim.list.createButton') }}
        </VBtn>

        <!--
          Tombol mengikuti permission export tersendiri. Isi file selalu sama
          dengan daftar yang sedang tampil, termasuk filternya.
        -->
        <VBtn
          v-if="canExport"
          color="success"
          variant="tonal"
          prepend-icon="tabler-file-spreadsheet"
          class="text-none"
          :loading="isExporting"
          :disabled="isExporting"
          @click="exportExcel"
        >
          {{ t('claim.list.exportButton') }}
        </VBtn>

        <VSpacer />

        <VBtn
          v-if="loadError"
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-refresh"
          class="text-none"
          @click="fetchClaims"
        >
          {{ t('claim.list.toast.reloadData') }}
        </VBtn>
      </VCardText>

      <VDivider />

      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th
              v-if="canBulkSelect"
              scope="col"
              style="inline-size: 3rem;"
            >
              <VCheckbox
                :model-value="allSelected"
                :indeterminate="someSelected"
                :disabled="!selectableRows.length"
                hide-details
                density="compact"
                @update:model-value="toggleAll"
              />
            </th>

            <th scope="col">
              {{ t('claim.list.table.no') }}
            </th>
            <th scope="col">
              {{ t('claim.list.table.claimNumber') }}
            </th>
            <th scope="col">
              {{ t('claim.list.table.date') }}
            </th>
            <th scope="col">
              {{ t('claim.list.table.branch') }}
            </th>
            <th scope="col">
              {{ t('claim.list.table.department') }}
            </th>
            <th
              scope="col"
              class="cl-subject-col"
            >
              {{ t('claim.list.table.subject') }}
            </th>
            <th
              scope="col"
              class="text-end"
            >
              {{ t('claim.list.table.total') }}
            </th>
            <th scope="col">
              {{ t('claim.list.table.status') }}
            </th>
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="(row, index) in rows"
            :key="row.id"
            :class="{
              'cl-row-need-approval': canApproveRow(row),
              'cl-row-need-receipt': needsReceipt(row),
              'cl-row-need-payment': needsPayment(row),
            }"
          >
            <td v-if="canBulkSelect">
              <VCheckbox
                v-if="rowBulkAction(row) !== null"
                :model-value="isSelected(row)"
                :disabled="!isSelectable(row) && !isSelected(row)"
                hide-details
                density="compact"
                @update:model-value="toggleRow(row)"
              />
            </td>

            <td class="text-medium-emphasis">
              {{ ((currentPage - 1) * rowPerPage) + Number(index) + 1 }}
            </td>

            <td>
              <VMenu location="bottom start">
                <template #activator="{ props }">
                  <div
                    v-bind="props"
                    class="cl-number-action d-inline-flex flex-column gap-1"
                  >
                    <div class="d-flex align-center gap-1 font-weight-medium text-primary">
                      <span>{{ row.claim_number || '-' }}</span>

                      <VIcon
                        icon="tabler-chevron-down"
                        size="16"
                      />
                    </div>

                    <!-- Penanda tindakan yang menunggu user ini sendiri. -->
                    <VChip
                      v-if="canApproveRow(row)"
                      size="x-small"
                      color="warning"
                      variant="tonal"
                    >
                      <VIcon
                        icon="tabler-alert-circle"
                        size="14"
                        start
                      />

                      {{ t('claim.list.menu.waitingMyApprovalBadge') }}
                    </VChip>

                    <!--
                      Penanda tindakan Finance yang masih ditunggu. Approved saja
                      belum berarti selesai selama uangnya belum diganti.
                    -->
                    <VChip
                      v-if="needsPayment(row)"
                      size="x-small"
                      color="info"
                      variant="tonal"
                    >
                      <VIcon
                        icon="tabler-cash-banknote"
                        size="14"
                        start
                      />

                      {{ t('claim.list.menu.notPaidBadge') }}
                    </VChip>
                  </div>
                </template>

                <VList>
                  <VListItem
                    href="javascript:void(0)"
                    @click="openDetail(row.public_id)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-eye"
                        :size="20"
                        class="me-3"
                      />
                    </template>

                    <VListItemTitle>
                      {{ t('claim.list.menu.viewDetail') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="canPrintRow(row)"
                    href="javascript:void(0)"
                    :disabled="printLoadingId === row.public_id"
                    @click="printDocument(row)"
                  >
                    <template #prepend>
                      <VProgressCircular
                        v-if="printLoadingId === row.public_id"
                        indeterminate
                        size="18"
                        width="2"
                        class="me-3"
                      />

                      <VIcon
                        v-else
                        icon="tabler-printer"
                        :size="20"
                        class="me-3"
                      />
                    </template>

                    <VListItemTitle>
                      {{ t('claim.list.menu.print') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="canEditRow(row)"
                    href="javascript:void(0)"
                    @click="goToEdit(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-pencil"
                        :size="20"
                        class="me-3"
                      />
                    </template>

                    <VListItemTitle>
                      {{ t('claim.list.menu.edit') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="canSubmitRow(row)"
                    href="javascript:void(0)"
                    @click="openSubmit(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-send"
                        :size="20"
                        class="me-3"
                      />
                    </template>

                    <VListItemTitle>
                      {{ t('claim.list.menu.submit') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="canApproveRow(row)"
                    href="javascript:void(0)"
                    :disabled="approveLoading"
                    @click="openApprove(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-circle-check"
                        :size="20"
                        class="me-3"
                        color="success"
                      />
                    </template>

                    <VListItemTitle class="text-success">
                      {{ t('claim.list.menu.approve') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="canApproveRow(row)"
                    href="javascript:void(0)"
                    :disabled="rejectLoading"
                    @click="openReject(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-circle-x"
                        :size="20"
                        class="me-3"
                        color="error"
                      />
                    </template>

                    <VListItemTitle class="text-error">
                      {{ t('claim.list.menu.reject') }}
                    </VListItemTitle>
                  </VListItem>

                  <!--
                    Tahap terakhir: Finance mengganti uang yang sudah lebih
                    dulu dikeluarkan pemohon.
                  -->
                  <!-- Menerima berkasnya: tahap sebelum uangnya diproses. -->
                  <VListItem
                    v-if="isStatus(row, 'APPROVED') && canReceive"
                    href="javascript:void(0)"
                    @click="openReceive(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-inbox"
                        :size="20"
                        class="me-3 text-primary"
                      />
                    </template>

                    <VListItemTitle class="text-primary">
                      {{ t('claim.list.menu.receive') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="canPayRow(row)"
                    :disabled="payLoading || !canPayTodayRow(row)"
                    href="javascript:void(0)"
                    @click="openPay(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-cash-banknote"
                        :size="20"
                        class="me-3"
                        color="info"
                      />
                    </template>

                    <VListItemTitle class="text-info">
                      {{ t('claim.list.menu.pay') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="canCancelRow(row)"
                    href="javascript:void(0)"
                    :disabled="cancelLoading"
                    @click="openCancel(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-ban"
                        :size="20"
                        class="me-3"
                        color="error"
                      />
                    </template>

                    <VListItemTitle class="text-error">
                      {{ t('claim.list.menu.cancel') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="canDeleteRow(row)"
                    href="javascript:void(0)"
                    @click="openDelete(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-trash"
                        :size="20"
                        class="me-3"
                        color="error"
                      />
                    </template>

                    <VListItemTitle class="text-error">
                      {{ t('claim.list.menu.delete') }}
                    </VListItemTitle>
                  </VListItem>
                </VList>
              </VMenu>
            </td>

            <td>{{ formatDate(row.date) || '-' }}</td>
            <td>{{ row.branch || '-' }}</td>
            <td>{{ row.department || '-' }}</td>

            <td class="cl-subject-col text-wrap">
              {{ row.subject || '-' }}
            </td>

            <td class="text-end">
              Rp {{ formatNumberWithoutRp(Number(row.total_amount || 0)) }}
            </td>

            <td>
              <VChip
                size="small"
                :color="getStatusColor(row.status)"
                variant="tonal"
              >
                {{ formatStatus(row.status) }}
              </VChip>
            </td>
          </tr>

          <tr v-if="!loading && !rows.length">
            <td
              :colspan="canBulkSelect ? 9 : 8"
              class="text-center py-8"
            >
              <div class="text-body-1 font-weight-medium">
                {{ t('claim.list.emptyTitle') }}
              </div>

              <div class="text-caption text-medium-emphasis">
                {{ t('claim.list.emptySubtitle') }}
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

    <!-- DIALOG DETAIL -->
    <VDialog
      v-model="detailDialog"
      max-width="980"
      scrollable
    >
      <VCard class="cl-detail">
        <!--
          Kepala modal: identitas dokumen dan nominalnya dijadikan satu blok
          supaya dua hal yang paling sering dicari langsung terbaca.
        -->
        <div :class="['cl-detail__header', `cl-detail__header--${detailAccent}`]">
          <div class="d-flex align-center justify-space-between gap-3">
            <div class="cl-detail__eyebrow">
              <VIcon
                icon="tabler-receipt-2"
                size="16"
              />
              {{ t('claim.detail.title') }}
            </div>

            <VBtn
              icon
              variant="text"
              size="small"
              @click="detailDialog = false"
            >
              <VIcon icon="tabler-x" />
            </VBtn>
          </div>

          <div class="d-flex align-end justify-space-between flex-wrap gap-4 mt-2">
            <div class="cl-detail__identity">
              <div class="cl-detail__number">
                {{ detail?.claim_number || '—' }}
              </div>

              <div class="d-flex align-center flex-wrap gap-2 mt-2">
                <VChip
                  size="small"
                  variant="flat"
                  :color="getStatusColor(detail?.status)"
                >
                  {{ formatStatus(detail?.status) }}
                </VChip>

                <VChip
                  v-if="detail?.transaction_category"
                  size="small"
                  variant="tonal"
                  color="primary"
                  prepend-icon="tabler-tag"
                >
                  {{ detail.transaction_category }}
                </VChip>
              </div>
            </div>

            <div
              v-if="detail"
              class="cl-detail__amount"
            >
              <div class="cl-detail__amount-label">
                {{ t('claim.detail.total') }}
              </div>

              <div class="cl-detail__amount-value">
                Rp {{ formatNumberWithoutRp(Number(detail.total_amount || 0)) }}
              </div>
            </div>
          </div>
        </div>

        <VDivider />

        <VCardText class="cl-detail__body">
          <div
            v-if="detailLoading"
            class="d-flex flex-column align-center justify-center py-12 gap-3"
          >
            <VProgressCircular
              indeterminate
              color="primary"
            />

            <span class="text-body-2 text-medium-emphasis">
              {{ t('common.alert.pleaseWait') }}
            </span>
          </div>

          <VAlert
            v-else-if="detailError"
            type="error"
            variant="tonal"
          >
            {{ detailError }}
          </VAlert>

          <template v-else-if="detail">
            <!-- Ringkasan -->
            <div class="cl-detail__grid">
              <div class="cl-detail__field">
                <VIcon
                  icon="tabler-calendar-event"
                  size="18"
                />

                <div class="cl-detail__field-body">
                  <div class="cl-detail__label">
                    {{ t('claim.detail.date') }}
                  </div>

                  <div class="cl-detail__value">
                    {{ formatDate(detail.date) || '-' }}
                  </div>
                </div>
              </div>

              <div class="cl-detail__field">
                <VIcon
                  icon="tabler-building-store"
                  size="18"
                />

                <div class="cl-detail__field-body">
                  <div class="cl-detail__label">
                    {{ t('claim.detail.branch') }}
                  </div>

                  <div class="cl-detail__value">
                    {{ detail.branch || '-' }}
                  </div>
                </div>
              </div>

              <div class="cl-detail__field">
                <VIcon
                  icon="tabler-users-group"
                  size="18"
                />

                <div class="cl-detail__field-body">
                  <div class="cl-detail__label">
                    {{ t('claim.detail.department') }}
                  </div>

                  <div class="cl-detail__value">
                    {{ detail.department_name || detail.department || '-' }}
                  </div>
                </div>
              </div>

              <div class="cl-detail__field">
                <VIcon
                  icon="tabler-user-plus"
                  size="18"
                />

                <div class="cl-detail__field-body">
                  <div class="cl-detail__label">
                    {{ t('claim.detail.createdBy') }}
                  </div>

                  <div class="cl-detail__value">
                    {{ detail.created_by_name || '-' }}
                  </div>

                  <div
                    v-if="detail.created_at"
                    class="cl-detail__meta"
                  >
                    {{ formatAuditDateTime(detail.created_at) }}
                  </div>
                </div>
              </div>

              <div class="cl-detail__field">
                <VIcon
                  icon="tabler-send"
                  size="18"
                />

                <div class="cl-detail__field-body">
                  <div class="cl-detail__label">
                    {{ t('claim.detail.submittedBy') }}
                  </div>

                  <div class="cl-detail__value">
                    {{ detail.submitted_by_name || '-' }}
                  </div>

                  <div
                    v-if="detail.submitted_at"
                    class="cl-detail__meta"
                  >
                    {{ formatAuditDateTime(detail.submitted_at) }}
                  </div>
                </div>
              </div>

              <!--
                Persetujuan akhir baru ada setelah tahap terakhir dilewati,
                jadi kartunya menyusul, bukan tampil kosong sejak awal.
              -->
              <div
                v-if="detail.final_approved_at"
                class="cl-detail__field"
              >
                <VIcon
                  icon="tabler-circle-check"
                  size="18"
                />

                <div class="cl-detail__field-body">
                  <div class="cl-detail__label">
                    {{ t('claim.detail.finalApprovedBy') }}
                  </div>

                  <div class="cl-detail__value">
                    {{ detail.final_approved_by_name || '-' }}
                  </div>

                  <div class="cl-detail__meta">
                    {{ formatAuditDateTime(detail.final_approved_at) }}
                  </div>
                </div>
              </div>
            </div>

            <!-- Perihal dan catatan -->
            <div class="cl-detail__note mt-4">
              <div class="cl-detail__label">
                {{ t('claim.detail.subject') }}
              </div>

              <div class="cl-detail__note-text">
                {{ detail.subject || '-' }}
              </div>

              <template v-if="detail.notes">
                <div class="cl-detail__label mt-3">
                  {{ t('claim.detail.notes') }}
                </div>

                <div class="cl-detail__note-text">
                  {{ detail.notes }}
                </div>
              </template>
            </div>

            <!-- Rincian -->
            <div class="cl-detail__section-title">
              <VIcon
                icon="tabler-list-details"
                size="18"
              />
              {{ t('claim.detail.itemsTitle') }}

              <VChip
                size="x-small"
                variant="tonal"
                class="ms-1"
              >
                {{ (detail.items || []).length }}
              </VChip>
            </div>

            <div class="cl-detail__table">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th style="inline-size: 3rem;">
                      {{ t('claim.detail.itemNo') }}
                    </th>
                    <th style="inline-size: 8rem;">
                      {{ t('claim.detail.itemDate') }}
                    </th>
                    <th>{{ t('claim.detail.itemDescription') }}</th>
                    <th
                      class="text-end"
                      style="inline-size: 11rem;"
                    >
                      {{ t('claim.detail.itemAmount') }}
                    </th>
                    <th style="inline-size: 16rem;">
                      {{ t('claim.detail.itemAttachment') }}
                    </th>
                  </tr>
                </thead>

                <tbody>
                  <tr
                    v-for="(item, index) in (detail.items || [])"
                    :key="`detail-item-${item.id}`"
                  >
                    <td class="text-medium-emphasis">
                      {{ index + 1 }}
                    </td>
                    <td>{{ formatDate(item.date) || '-' }}</td>
                    <td class="text-wrap">
                      {{ item.description }}
                    </td>
                    <td class="text-end font-weight-medium">
                      Rp {{ formatNumberWithoutRp(Number(item.amount || 0)) }}
                    </td>

                    <!-- Bukti milik baris ini -->
                    <td>
                      <div
                        v-if="(item.attachments || []).length"
                        class="d-flex flex-column gap-1"
                      >
                        <a
                          v-for="attachment in item.attachments"
                          :key="`detail-item-${item.id}-file-${attachment.id}`"
                          class="cl-detail__line-file"
                          :href="attachment.url || undefined"
                          target="_blank"
                          rel="noopener"
                        >
                          <VIcon
                            :icon="getAttachmentIcon(attachment.mime_type)"
                            size="18"
                            color="primary"
                          />

                          <span class="cl-detail__line-file-name">
                            {{ attachment.original_filename || attachment.filename }}
                          </span>
                        </a>
                      </div>

                      <span
                        v-else
                        class="cl-detail__meta"
                      >—</span>
                    </td>
                  </tr>
                </tbody>

                <tfoot>
                  <tr class="cl-detail__table-total">
                    <td colspan="3">
                      {{ t('claim.detail.total') }}
                    </td>

                    <td class="text-end">
                      Rp {{ formatNumberWithoutRp(Number(detail.total_amount || 0)) }}
                    </td>

                    <td />
                  </tr>
                </tfoot>
              </VTable>
            </div>

            <!--
              Riwayat approval selalu ditampilkan, termasuk pada draft yang
              belum disubmit. Bagian yang hilang membuat pembacanya menduga
              dokumennya tidak punya jalur approval sama sekali; keterangan
              kosong menjawabnya langsung.
            -->
            <div class="cl-detail__section-title">
              <VIcon
                icon="tabler-route"
                size="18"
              />
              {{ t('claim.detail.approvalsTitle') }}
            </div>

            <VAlert
              v-if="!(detail.approvals || []).length"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('claim.detail.approvalsEmpty') }}
            </VAlert>

            <VTimeline
              v-else
              side="end"
              align="start"
              density="compact"
              truncate-line="both"
              class="cl-detail__timeline"
            >
              <VTimelineItem
                v-for="step in detailApprovalSteps"
                :key="`detail-step-${step.step_order}`"
                :dot-color="getApprovalStatusColor(step.status)"
                :icon="getApprovalStatusIcon(step.status)"
                size="x-small"
                fill-dot
              >
                <div class="d-flex align-center flex-wrap gap-2">
                  <span class="font-weight-medium">
                    {{ t('claim.detail.approvalStep') }} {{ step.step_order }}
                  </span>

                  <span
                    v-if="step.label"
                    class="text-medium-emphasis text-body-2"
                  >
                    {{ step.label }}
                  </span>

                  <VChip
                    size="x-small"
                    variant="tonal"
                    :color="getApprovalStatusColor(step.status)"
                  >
                    {{ step.status }}
                  </VChip>

                  <span
                    v-if="step.moment"
                    class="cl-detail__meta d-inline-flex align-center gap-1"
                  >
                    <VIcon
                      icon="tabler-clock"
                      size="14"
                    />
                    {{ formatAuditDateTime(step.moment) }}
                  </span>
                </div>

                <!--
                  Keterangan hanya muncul bila approver-nya lebih dari satu.
                  Pada tahap berapprover tunggal, aturannya tidak menambah
                  informasi apa pun.
                -->
                <div
                  v-if="step.approvers.length > 1"
                  class="cl-detail__meta mt-1"
                >
                  {{ step.approval_mode === 'ALL'
                    ? t('claim.detail.approvalModeAll', { count: step.approvers.length })
                    : t('claim.detail.approvalModeAny', { count: step.approvers.length }) }}
                </div>

                <div class="d-flex flex-column gap-1 mt-1">
                  <div
                    v-for="approval in step.visibleApprovers"
                    :key="`detail-approval-${approval.id}`"
                    class="d-flex align-center flex-wrap gap-2"
                  >
                    <span class="d-inline-flex align-center gap-1 text-body-2">
                      <VIcon
                        icon="tabler-user"
                        size="14"
                      />
                      {{ approval.approver_name || '-' }}
                    </span>

                    <!--
                      Status per approver hanya ditampilkan bila berbeda dari
                      status tahapnya -- misalnya approver yang dilewati
                      setelah rekannya lebih dulu menyetujui.
                    -->
                    <VChip
                      v-if="approval.status !== step.status"
                      size="x-small"
                      variant="tonal"
                      :color="getApprovalStatusColor(approval.status)"
                    >
                      {{ approval.status }}
                    </VChip>

                    <span
                      v-if="getApprovalMoment(approval) && step.visibleApprovers.length > 1"
                      class="cl-detail__meta d-inline-flex align-center gap-1"
                    >
                      <VIcon
                        icon="tabler-clock"
                        size="14"
                      />
                      {{ formatAuditDateTime(getApprovalMoment(approval)) }}
                    </span>
                  </div>
                </div>

                <template
                  v-for="approval in step.visibleApprovers"
                  :key="`detail-note-${approval.id}`"
                >
                  <div
                    v-if="approval.notes"
                    class="cl-detail__quote mt-2"
                  >
                    {{ approval.notes }}
                  </div>
                </template>
              </VTimelineItem>
            </VTimeline>

            <!-- Jadwal pembayaran: janji yang sudah dikirimkan ke pemohon -->
            <VAlert
              v-if="detailPaymentSchedule"
              :type="detailPaymentSchedule.color"
              variant="tonal"
              class="mt-5"
            >
              <div class="d-flex align-center gap-2">
                <VIcon
                  :icon="detailPaymentSchedule.icon"
                  size="18"
                />

                <span class="font-weight-medium">
                  {{ t('claim.detail.scheduledPaymentDate') }}: {{ detailPaymentSchedule.date }}
                </span>
              </div>

              <div class="text-body-2 mt-1">
                {{ detailPaymentSchedule.label }}
              </div>
            </VAlert>
          </template>
        </VCardText>

        <VDivider />

        <VCardActions class="px-5 py-3">
          <VSpacer />

          <VBtn
            v-if="detailCanApprove"
            color="success"
            variant="tonal"
            prepend-icon="tabler-circle-check"
            class="text-none"
            :disabled="approveLoading"
            @click="openApprove(detail)"
          >
            {{ t('claim.detail.approveButton') }}
          </VBtn>

          <VBtn
            v-if="detailCanApprove"
            color="error"
            variant="tonal"
            prepend-icon="tabler-circle-x"
            class="text-none"
            :disabled="rejectLoading"
            @click="openReject(detail)"
          >
            {{ t('claim.detail.rejectButton') }}
          </VBtn>

          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            @click="detailDialog = false"
          >
            {{ t('claim.detail.closeButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- DIALOG SETUJUI -->
    <VDialog
      v-model="approveDialog"
      max-width="520"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('claim.list.approve.dialogTitle') }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('claim.list.approve.dialogSubtitle') }}
          </div>

          <div class="font-weight-medium mb-4">
            {{ approveTarget?.claim_number || '-' }}
          </div>

          <VTextarea
            v-model="approveNotes"
            :label="t('claim.list.approve.notesLabel')"
            :placeholder="t('claim.list.approve.notesPlaceholder')"
            rows="3"
            auto-grow
          />
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            :disabled="approveLoading"
            @click="approveDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="success"
            class="text-none"
            :loading="approveLoading"
            @click="approveClaim"
          >
            {{ t('claim.list.approve.confirmButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- DIALOG TOLAK -->
    <VDialog
      v-model="rejectDialog"
      max-width="520"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('claim.list.reject.dialogTitle') }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('claim.list.reject.dialogSubtitle') }}
          </div>

          <div class="font-weight-medium mb-4">
            {{ rejectTarget?.claim_number || '-' }}
          </div>

          <VTextarea
            v-model="rejectNotes"
            :label="t('claim.list.reject.notesLabel')"
            :placeholder="t('claim.list.reject.notesPlaceholder')"
            rows="3"
            auto-grow
            :error="!!rejectError"
            :error-messages="rejectError ? [rejectError] : []"
          />
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            :disabled="rejectLoading"
            @click="rejectDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="error"
            class="text-none"
            :loading="rejectLoading"
            @click="rejectClaim"
          >
            {{ t('claim.list.reject.confirmButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- DIALOG BATALKAN -->
    <VDialog
      v-model="cancelDialog"
      max-width="520"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('claim.list.cancel.dialogTitle') }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('claim.list.cancel.dialogSubtitle') }}
          </div>

          <div class="font-weight-medium mb-4">
            {{ cancelTarget?.claim_number || '-' }}
          </div>

          <VTextarea
            v-model="cancelNotes"
            :label="t('claim.list.cancel.notesLabel')"
            :placeholder="t('claim.list.cancel.notesPlaceholder')"
            rows="3"
            auto-grow
            :error="!!cancelError"
            :error-messages="cancelError ? [cancelError] : []"
          />
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            :disabled="cancelLoading"
            @click="cancelDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="error"
            class="text-none"
            :loading="cancelLoading"
            @click="cancelClaim"
          >
            {{ t('claim.list.cancel.confirmButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- DIALOG TANDA TANGAN -->
    <VDialog
      v-model="signatureDialog"
      max-width="560"
      persistent
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('claim.list.signature.dialogTitle') }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('claim.list.signature.dialogSubtitle') }}
          </div>

          <div class="cl-signature-wrapper">
            <canvas
              ref="signatureCanvasRef"
              class="cl-signature-canvas"
            />
          </div>

          <VBtn
            size="small"
            variant="text"
            color="secondary"
            class="text-none mt-2"
            @click="signaturePad?.clear()"
          >
            {{ t('claim.list.signature.clearButton') }}
          </VBtn>

          <VCheckbox
            v-model="signatureAgree"
            class="mt-2"
            :label="t('claim.list.signature.agreementLabel')"
            hide-details
          />

          <VAlert
            v-if="signatureError"
            type="error"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ signatureError }}
          </VAlert>
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            :disabled="signatureLoading"
            @click="signatureDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="primary"
            class="text-none"
            :loading="signatureLoading"
            @click="saveSignatureAndContinue"
          >
            {{ t('claim.list.signature.saveButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
    <!-- Penegasan pembayaran di luar jadwal -->
    <OffSchedulePaymentDialog ref="offScheduleDialog" />
  </section>
</template>

<style scoped>
.cl-number-action {
  cursor: pointer;
}

/* Baris yang menunggu approval user ini sendiri. */
.cl-row-need-approval {
  background: rgba(var(--v-theme-warning), 0.06);
}

/* Sudah disetujui, tinggal menunggu penggantian dari Finance. */

/* Bilah tindakan massal: menonjol tanpa berteriak. */
.cl-bulk-bar {
  border-inline-start: 4px solid rgb(var(--v-theme-success));
  background: rgba(var(--v-theme-success), 0.04);
}

/* Menunggu diterima: sudah disetujui, berkasnya belum dinyatakan sampai. */
.cl-row-need-receipt {
  border-inline-start: 3px solid rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.03);
}

.cl-row-need-payment {
  background: rgba(var(--v-theme-info), 0.06);
}

/*
 * Baris yang menunggu approval user ini didahulukan warnanya: itu tindakan
 * miliknya sendiri, sedangkan menunggu pembayaran belum tentu.
 */
.cl-row-need-approval.cl-row-need-payment {
  background: rgba(var(--v-theme-warning), 0.06);
}

.cl-approval-filter {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  gap: 1rem;
  block-size: 100%;
  padding-block: 0.25rem;
  padding-inline: 1rem;
}

.cl-signature-wrapper {
  border: 1px dashed rgba(var(--v-border-color), 0.5);
  border-radius: 8px;
  block-size: 180px;
}

.cl-signature-canvas {
  block-size: 100%;
  inline-size: 100%;
  touch-action: none;
}

.cl-subject-col {
  max-inline-size: 20rem;
  white-space: normal;
}

/*
|--------------------------------------------------------------------------
| Modal detail
|--------------------------------------------------------------------------
| Semua warna memakai token tema, bukan nilai tetap, supaya mode gelap ikut
| menyesuaikan tanpa aturan tambahan.
|--------------------------------------------------------------------------
*/

.cl-detail__header {
  padding-block: 1.25rem;
  padding-inline: 1.5rem;
}

.cl-detail__header--success {
  background: linear-gradient(135deg, rgba(var(--v-theme-success), 0.14), transparent 70%);
}

.cl-detail__header--warning {
  background: linear-gradient(135deg, rgba(var(--v-theme-warning), 0.14), transparent 70%);
}

.cl-detail__header--error {
  background: linear-gradient(135deg, rgba(var(--v-theme-error), 0.14), transparent 70%);
}

.cl-detail__header--info {
  background: linear-gradient(135deg, rgba(var(--v-theme-info), 0.14), transparent 70%);
}

.cl-detail__header--secondary {
  background: linear-gradient(135deg, rgba(var(--v-theme-secondary), 0.12), transparent 70%);
}

.cl-detail__eyebrow {
  display: inline-flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.75rem;
  font-weight: 600;
  gap: 0.375rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.cl-detail__number {
  font-size: 1.375rem;
  font-weight: 700;
  line-height: 1.25;
}

.cl-detail__amount {
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 2px 8px rgba(var(--v-theme-on-surface), 0.08);
  padding-block: 0.5rem;
  padding-inline: 1rem;
  text-align: end;
}

.cl-detail__amount-label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.7rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.cl-detail__amount-value {
  color: rgb(var(--v-theme-primary));
  font-size: 1.25rem;
  font-weight: 700;
  line-height: 1.3;
  white-space: nowrap;
}

.cl-detail__body {
  padding-block: 1.25rem 1.5rem;
}

.cl-detail__grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(13.5rem, 1fr));
}

.cl-detail__field {
  display: flex;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  gap: 0.625rem;
  padding-block: 0.625rem;
  padding-inline: 0.75rem;
}

.cl-detail__field .v-icon {
  color: rgba(var(--v-theme-primary), 0.75);
  margin-block-start: 0.125rem;
}

.cl-detail__field-body {
  min-inline-size: 0;
}

.cl-detail__label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.cl-detail__value {
  font-size: 0.9rem;
  font-weight: 500;
  overflow-wrap: anywhere;
}

.cl-detail__meta {
  color: rgba(var(--v-theme-on-surface), 0.55);
  font-size: 0.75rem;
}

.cl-detail__note {
  border-radius: 10px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  padding-block: 0.75rem;
  padding-inline: 1rem;
}

.cl-detail__note-text {
  font-size: 0.9rem;
  white-space: pre-line;
  overflow-wrap: anywhere;
}

.cl-detail__section-title {
  display: flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.85);
  font-size: 0.95rem;
  font-weight: 700;
  gap: 0.5rem;
  margin-block: 1.5rem 0.75rem;
}

.cl-detail__section-title .v-icon {
  color: rgba(var(--v-theme-primary), 0.8);
}

.cl-detail__table {
  overflow-x: auto;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
}

/* Bukti yang menempel pada satu baris rincian. */
.cl-detail__line-file {
  display: flex;
  align-items: center;
  color: inherit;
  gap: 0.375rem;
  min-inline-size: 0;
  text-decoration: none;
}

.cl-detail__line-file:hover .cl-detail__line-file-name {
  text-decoration: underline;
}

.cl-detail__line-file-name {
  overflow: hidden;
  font-size: 0.8rem;
  max-inline-size: 9rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cl-detail__table :deep(thead th) {
  background: rgba(var(--v-theme-on-surface), 0.04);
  font-size: 0.7rem !important;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.cl-detail__table :deep(tbody tr:nth-child(even)) {
  background: rgba(var(--v-theme-on-surface), 0.02);
}

.cl-detail__timeline {
  justify-content: start;
  padding-block-start: 0.25rem;
}

/* Catatan approver, dibedakan dari teks biasa lewat garis tepi. */
.cl-detail__quote {
  border-inline-start: 3px solid rgba(var(--v-theme-primary), 0.35);
  background: rgba(var(--v-theme-on-surface), 0.04);
  font-size: 0.85rem;
  padding-block: 0.375rem;
  padding-inline: 0.625rem;
  white-space: pre-line;
}

.cl-detail__table :deep(tfoot .cl-detail__table-total td) {
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgba(var(--v-theme-primary), 0.08);
  font-size: 0.95rem;
  font-weight: 700;
}
</style>
