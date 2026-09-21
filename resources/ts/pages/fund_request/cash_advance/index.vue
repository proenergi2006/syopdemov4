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

interface CashAdvanceRow {
  id: number
  public_id: string
  advance_number: string | null
  date: string | null
  subject: string | null
  branch: string | null
  department: string | null
  total_amount: number | string | null
  status: string | null

  can_submit?: boolean
  can_approve?: boolean
  approval_label?: string | null

  /** FPU ini sudah punya dokumen realisasi. */
  has_realization?: boolean

  /** Realisasinya masih hidup -- belum ditolak maupun dibatalkan. */
  has_active_realization?: boolean

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

  /*
   * Perdin yang mendasari FPU ini. Kosong untuk FPU biasa.
   *
   * my_approval terisi bila akun yang membuka SUDAH menyetujui perjalanannya --
   * dipakai memberi konteks saat ia menyetujui FPU-nya, bukan menggantikannya.
   */
  business_trip?: {
    trip_number: string
    destination: string
    depart_date: string | null
    return_date: string | null
    status: string
    my_approval: { step_order: number; label: string | null; approved_at: string | null } | null
  } | null
}

interface CashAdvanceAbilities {
  can_view: boolean
  view_scope: string
  can_create: boolean
  can_update: boolean
  can_submit: boolean
  can_delete: boolean
  can_cancel: boolean
  can_receive: boolean
  can_disburse: boolean
  can_create_realization: boolean
}

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()

const permissionStore = usePermissionStore()
const navigationStore = useNavigationStore()

const isCheckingPermission = ref(true)

const loading = ref(false)
const loadError = ref(false)
const rows = ref<CashAdvanceRow[]>([])

const searchQuery = ref('')
const selectedStatus = ref('')
const selectedPendingAction = ref('')

/*
| Tanggal jadwal pembayaran yang sedang dilihat. Terpisah dari startDate/
| endDate, yang menyaring TANGGAL DOKUMEN -- dua hal berbeda yang mudah
| tertukar kalau disatukan.
*/
const scheduledPaymentDate = ref<string | null>(null)

const startDate = ref<string | null>(null)
const endDate = ref<string | null>(null)
const onlyWaitingMyApproval = ref(false)

const rowPerPage = ref(10)
const currentPage = ref(1)
const totalData = ref(0)
const totalPage = ref(1)

const defaultAbilities = (): CashAdvanceAbilities => ({
  can_view: false,
  view_scope: 'NONE',
  can_create: false,
  can_update: false,
  can_submit: false,
  can_delete: false,
  can_cancel: false,
  can_receive: false,
  can_disburse: false,
  can_create_realization: false,
})

/*
| Keadaan batas pengajuan milik pengguna ini, dari server. Dipakai memberi
| tahu SEBELUM ia mengisi formulir -- angkanya sama persis dengan yang
| dipakai penolakan di server.
*/
interface SubmissionLimit {
  outstanding: number
  max_outstanding: number | null
  max_days: number | null
  overdue: { number: string; days: number }[]
  blocked: boolean
  reasons: string[]
  limit_name: string | null
}

const submissionLimit = ref<SubmissionLimit | null>(null)

/*
| Hari kerja kasir dalam penomoran ISO, dari server. Namanya dirangkai di
| sini, bukan di server, supaya ikut berganti begitu pengguna menukar
| bahasa -- tanpa menunggu daftarnya diambil ulang.
*/
const paymentDays = ref<number[]>([])

const { dayList } = useDayNames()

/* "Senin dan Rabu". */
const paymentDaysText = computed<string>(() => dayList(paymentDays.value))

const abilities = ref<CashAdvanceAbilities>(defaultAbilities())

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
  { title: t('cashAdvance.list.filters.allBranches'), value: '' },
  ...branchOptions.value.map(item => ({
    title: item.nama_cabang,
    value: String(item.id),
  })),
])

const departmentItems = computed(() => [
  { title: t('cashAdvance.list.filters.allDepartments'), value: null as number | null },
  ...departmentOptions.value.map(item => ({
    title: item.nama,
    value: Number(item.id),
  })),
])

const canView = computed(() => permissionStore.can('cash_advance.view'))
const canCreate = computed(() => permissionStore.can('cash_advance.create'))
const canExport = computed(() => permissionStore.can('cash_advance.export'))
const canUpdate = computed(() => permissionStore.can('cash_advance.update'))
const canDelete = computed(() => permissionStore.can('cash_advance.delete'))
const canCancel = computed(() => permissionStore.can('cash_advance.cancel'))
const canReceive = computed(() => permissionStore.can('cash_advance.receive'))
const canDisburse = computed(() => permissionStore.can('cash_advance.disburse'))

/*
|--------------------------------------------------------------------------
| Dialog aksi
|--------------------------------------------------------------------------
*/
/** "18 Sep 2026, 10:12" -- untuk menyebut kapan perdinnya disetujui. */
const formatDateTimeShort = (nilai?: string | null): string => {
  if (!nilai)
    return '-'

  const d = new Date(nilai)

  if (Number.isNaN(d.getTime()))
    return '-'

  return d.toLocaleString(undefined, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const approveDialog = ref(false)
const approveTarget = ref<CashAdvanceRow | null>(null)
const approveNotes = ref('')
const approveLoading = ref(false)

const rejectDialog = ref(false)
const rejectTarget = ref<CashAdvanceRow | null>(null)
const rejectNotes = ref('')
const rejectError = ref('')
const rejectLoading = ref(false)

const cancelDialog = ref(false)
const cancelTarget = ref<CashAdvanceRow | null>(null)
const cancelNotes = ref('')
const cancelError = ref('')
const cancelLoading = ref(false)

/*
| Penerimaan dokumen: tahap antara persetujuan dan pencairan. Bentuknya
| sengaja sama persis dengan pencairan supaya PIC tidak menghadapi dua tata
| cara berbeda untuk dua tahap yang berurutan.
*/
const receiveLoading = ref(false)
const disburseLoading = ref(false)

const detailDialog = ref(false)

/*
| Rincian perdin, bertumpuk di atas detail FPU.
|
| Sengaja tidak pindah halaman: penyetuju yang ingin memastikan satu hal
| pada perjalanannya tidak perlu meninggalkan dokumen yang sedang ia nilai,
| lalu mencarinya lagi dari awal.
*/
const businessTripDialog = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const detail = ref<any | null>(null)

/*
|--------------------------------------------------------------------------
| Tanda tangan digital
|--------------------------------------------------------------------------
| Submit dan approve keduanya membutuhkan tanda tangan. Bila user belum
| punya, dialog ini muncul lebih dulu lalu aksi tertunda dilanjutkan.
|--------------------------------------------------------------------------
*/
const signatureDialog = ref(false)
const signatureCanvasRef = ref<HTMLCanvasElement | null>(null)
const signaturePad = ref<SignaturePad | null>(null)
const signatureAgree = ref(false)
const signatureError = ref('')
const signatureLoading = ref(false)

const pendingAction = ref<'submit' | 'approve' | null>(null)
const pendingRow = ref<CashAdvanceRow | null>(null)

/*
|--------------------------------------------------------------------------
| Filter "butuh aksi"
|--------------------------------------------------------------------------
| Pilihannya mengikuti kewenangan pembacanya:
|
| - Belum direalisasi terbuka untuk semua, karena FPU boleh dibuat siapa pun
|   dan pertanggungjawabannya melekat pada pemohonnya sendiri.
| - Belum dicairkan hanya untuk pemegang permission pencairan, karena hanya
|   mereka yang bisa menindaklanjutinya.
|
| Selectnya sendiri disembunyikan bila tidak ada pilihan yang tersisa.
|--------------------------------------------------------------------------
*/
const pendingActionItems = computed(() => {
  const items = [
    { title: t('cashAdvance.list.filters.pendingAction.all'), value: '' },
    { title: t('cashAdvance.list.filters.pendingAction.notRealized'), value: 'NOT_REALIZED' },
  ]

  if (canDisburse.value) {
    items.splice(1, 0, {
      title: t('cashAdvance.list.filters.pendingAction.notDisbursed'),
      value: 'NOT_DISBURSED',
    })
  }

  if (canReceive.value) {
    items.splice(1, 0, {
      title: t('cashAdvance.list.filters.pendingAction.notReceived'),
      value: 'NOT_RECEIVED',
    })
  }

  return items
})

const statusItems = computed(() => [
  { title: t('cashAdvance.status.all'), value: '' },
  { title: t('cashAdvance.status.draft'), value: 'DRAFT' },
  { title: t('cashAdvance.status.inProgress'), value: 'IN PROGRESS' },
  { title: t('cashAdvance.status.approved'), value: 'APPROVED' },
  { title: t('cashAdvance.status.rejected'), value: 'REJECTED' },
  { title: t('cashAdvance.status.cancelled'), value: 'CANCELLED' },
  { title: t('cashAdvance.status.received'), value: 'RECEIVED' },
  { title: t('cashAdvance.status.disbursed'), value: 'DISBURSED' },
])

const formatStatus = (status: string | null | undefined): string => {
  const normalized = String(status || '').trim().toUpperCase()

  const map: Record<string, string> = {
    'DRAFT': t('cashAdvance.status.draft'),
    'IN PROGRESS': t('cashAdvance.status.inProgress'),
    'APPROVED': t('cashAdvance.status.approved'),
    'REJECTED': t('cashAdvance.status.rejected'),
    'CANCELLED': t('cashAdvance.status.cancelled'),
    'RECEIVED': t('cashAdvance.status.received'),
    'DISBURSED': t('cashAdvance.status.disbursed'),
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
    'DISBURSED': 'info',
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
const showScheduledPaymentFilter = computed<boolean>(() => canDisburse.value)

/** Sudah menyentuh batas: pengajuan baru akan ditolak server. */
const submissionBlocked = computed<boolean>(() => submissionLimit.value?.blocked === true)

/** Daftar dokumen yang terlambat direalisasi, dirangkai jadi satu kalimat. */
const overdueText = computed<string>(() =>
  (submissionLimit.value?.overdue ?? [])
    .map(d => `${d.number} (${d.days} hari)`)
    .join(', '))

const canPayTodayRow = (row: CashAdvanceRow): boolean => row.can_pay_today !== false

const isStatus = (row: CashAdvanceRow, status: string): boolean =>
  String(row.status || '').trim().toUpperCase() === status

const paginationData = computed(() => {
  const first = totalData.value === 0
    ? 0
    : (currentPage.value - 1) * rowPerPage.value + 1

  const last = Math.min(currentPage.value * rowPerPage.value, totalData.value)

  return `${first}-${last} of ${totalData.value}`
})

/*
|--------------------------------------------------------------------------
| Ambil data
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

/*
| Penampung pilihan ditaruh di sini karena pemuat daftar mengosongkannya.
*/
const selectedIds = ref<string[]>([])
const bulkMode = ref<'RECEIVE' | 'DISBURSE' | null>(null)
const bulkLoading = ref(false)

const fetchCashAdvances = async (): Promise<void> => {
  loading.value = true
  loadError.value = false

  try {
    const response = await axios.get('/fund-request/cash-advance', {
      headers: { Accept: 'application/json' },
      params: {
        page: currentPage.value,
        per_page: rowPerPage.value,
        ...buildFilterParams(),
      },
    })

    const payload = response.data

    rows.value = Array.isArray(payload?.data) ? payload.data : []

    /*
    | Setelah berpindah halaman atau berganti penyaring, baris yang tadi
    | tercentang belum tentu masih terlihat -- memproses dokumen yang tidak
    | terlihat adalah hal terakhir yang diinginkan siapa pun.
    */
    selectedIds.value = []
    bulkMode.value = null

    abilities.value = {
      ...defaultAbilities(),
      ...(payload?.abilities || {}),
    }

    paymentDays.value = Array.isArray(payload?.payment_days)
      ? payload.payment_days.map(Number)
      : []
    submissionLimit.value = payload?.submission_limit ?? null

    totalData.value = Number(payload?.meta?.total ?? rows.value.length ?? 0)
    totalPage.value = Number(payload?.meta?.last_page ?? 1)
    currentPage.value = Number(payload?.meta?.current_page ?? 1)
  }
  catch (error: unknown) {
    const status = (error as any)?.response?.status

    /*
     * 401 tidak dimunculkan sebagai toast karena user sudah dialihkan ke
     * halaman login oleh interceptor.
     */
    if (status === 401) {
      rows.value = []
      totalData.value = 0
      totalPage.value = 1

      return
    }

    loadError.value = true

    console.error('[Cash Advance] FETCH ERROR:', error)

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvance.list.toast.loadFailed')),
    })

    rows.value = []
    totalData.value = 0
    totalPage.value = 1
  }
  finally {
    loading.value = false
  }
}

usePolling(fetchCashAdvances, { interval: 30000 })

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
    console.error('[cashAdvance] FILTER OPTIONS ERROR:', error)

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

  await fetchCashAdvances()
}

/*
|--------------------------------------------------------------------------
| Tanda tangan
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

const saveSignatureAndContinue = async (): Promise<void> => {
  if (!signaturePad.value || signaturePad.value.isEmpty()) {
    signatureError.value = 'Tanda tangan wajib diisi.'

    return
  }

  if (!signatureAgree.value) {
    signatureError.value = 'Anda wajib menyetujui penggunaan tanda tangan digital.'

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
      await submitCashAdvance(pendingRow.value)

    if (pendingAction.value === 'approve' && pendingRow.value)
      showApproveDialog(pendingRow.value)
  }
  catch (error: unknown) {
    signatureError.value = getApiErrorMessage(
      error,
      'Gagal menyimpan tanda tangan digital.',
    )
  }
  finally {
    signatureLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Aksi dokumen
|--------------------------------------------------------------------------
*/
const goToCreate = (): void => {
  router.push('/fund_request/cash_advance/create')
}

const goToEdit = (publicId: string): void => {
  router.push(`/fund_request/cash_advance/edit?id=${publicId}`)
}

/*
|--------------------------------------------------------------------------
| Export Excel
|--------------------------------------------------------------------------
| Mengirim filter yang sedang aktif supaya isi file sama dengan yang terlihat
| di layar. Tanpa filter, seluruh FPU yang boleh dilihat user ikut terekspor.
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
      t('cashAdvance.list.toast.exportLoading'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.get('/fund-request/cash-advance/export-excel', {
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
      : 'FPU.xlsx'

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
      text: t('cashAdvance.list.toast.exportSuccess'),
    })
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: t('cashAdvance.list.toast.exportFailed'),
    })

    console.error('[FPU] EXPORT ERROR:', error)
  }
  finally {
    isExporting.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Cetak PDF
|--------------------------------------------------------------------------
| Mengikuti pola cetak PR dan PO: tab dibuka lebih dulu, sebelum permintaan
| async, supaya tidak diblokir popup blocker.
|
| Tab itu langsung diisi halaman loading, bukan dibiarkan kosong. Halaman itu
| tetap terlihat selama browser menunggu server merender PDF, karena navigasi
| baru mengganti isi tab setelah responsnya datang.
|
| Cetakan hanya berbahasa Indonesia, tanpa dialog pilihan bahasa.
|--------------------------------------------------------------------------
*/
const printLoadingId = ref<string | null>(null)

const canPrint = (row: CashAdvanceRow): boolean =>
  isStatus(row, 'APPROVED')
  || isStatus(row, 'RECEIVED')
  || isStatus(row, 'DISBURSED')

const printDocument = async (row: CashAdvanceRow): Promise<void> => {
  if (!row.public_id || printLoadingId.value)
    return

  printLoadingId.value = row.public_id

  const loadingTitle = t('cashAdvance.list.print.loading')
  const loadingText = t('common.alert.pleaseWait')

  let printWindow: Window | null = null

  try {
    showLoadingAlert(loadingTitle, loadingText)

    printWindow = window.open('', '_blank')

    if (!printWindow)
      throw new Error(t('cashAdvance.list.print.popupBlocked'))

    printWindow.document.open()
    printWindow.document.write(buildPrintLoadingPage(loadingTitle, loadingText))
    printWindow.document.close()

    /*
     * Hanya meminta signed URL. Berkas PDF-nya sendiri diunduh browser saat
     * navigasi di bawah, jadi Axios tidak ikut menunggu render selesai.
     */
    const response = await axios.post(
      `/fund-request/cash-advance/${encodeURIComponent(row.public_id)}/print-url`,
      null,
      { headers: { Accept: 'application/json' } },
    )

    const printUrl = response.data?.url

    if (response.data?.success === false || typeof printUrl !== 'string' || !printUrl.trim())
      throw new Error(response.data?.message || t('cashAdvance.list.print.urlFailed'))

    if (printWindow.closed)
      throw new Error(t('cashAdvance.list.print.windowClosed'))

    closeAlert()

    printWindow.location.replace(new URL(printUrl, window.location.origin).toString())
  }
  catch (error: unknown) {
    closeAlert()

    if (printWindow && !printWindow.closed)
      printWindow.close()

    showErrorToast({
      title: t('common.alert.error'),
      text: error instanceof Error
        ? error.message
        : getApiErrorMessage(error, t('cashAdvance.list.print.failed')),
    })
  }
  finally {
    printLoadingId.value = null
  }
}

/*
|--------------------------------------------------------------------------
| Pintasan ke Realisasi
|--------------------------------------------------------------------------
| Hanya untuk FPU yang sudah dibayarkan dan belum punya realisasi. Ketiga
| syaratnya diperiksa lagi di backend saat menyimpan -- pemeriksaan di sini
| semata agar menu yang mustahil dipakai tidak ditawarkan.
|--------------------------------------------------------------------------
*/
const canCreateRealizationFor = (row: CashAdvanceRow): boolean => {
  return abilities.value.can_create_realization
    && isStatus(row, 'DISBURSED')
    && !row.has_realization
}

/**
 * FPU yang dananya sudah keluar tetapi belum dipertanggungjawabkan.
 *
 * Penanda, bukan status: dokumennya sah-sah saja berstatus DISBURSED, hanya
 * saja masih ada kewajiban yang menggantung. Sengaja tidak melihat permission
 * -- ini keterangan tentang keadaan dokumen, bukan tentang apa yang boleh
 * dilakukan pembacanya.
 */
const needsRealization = (row: CashAdvanceRow): boolean =>
  isStatus(row, 'DISBURSED') && !row.has_realization

/*
|--------------------------------------------------------------------------
| Penanda pencairan
|--------------------------------------------------------------------------
| Approved adalah keadaan menunggu Finance: approvalnya tuntas tetapi dananya
| belum keluar. Ditampilkan sebagai penanda tersendiri, bukan sekadar status,
| supaya baris yang menunggu tindakan langsung terlihat di antara baris lain
| yang sudah selesai urusannya.
|--------------------------------------------------------------------------
*/
const needsDisbursement = (row: CashAdvanceRow): boolean =>
  isStatus(row, 'RECEIVED')

/*
|--------------------------------------------------------------------------
| Penanda penerimaan
|--------------------------------------------------------------------------
| Approved kini berarti menunggu PIC menerima dokumennya, bukan lagi menunggu
| pencairan. Dipisah dari penanda pencairan supaya kedua antrean itu terbaca
| sebagai dua meja yang berbeda.
|--------------------------------------------------------------------------
*/
const needsReceipt = (row: CashAdvanceRow): boolean =>
  isStatus(row, 'APPROVED')

/*
|--------------------------------------------------------------------------
| Kelayakan pembatalan FPU
|--------------------------------------------------------------------------
| Hanya status approved: draft cukup dihapus, in progress masih bisa ditolak
| approver, dan yang sudah dibayarkan diselesaikan lewat Realisasi.
|
| Realisasi yang masih hidup juga menahan pembatalan. Ketiganya diperiksa
| ulang di backend -- pemeriksaan di sini semata agar menu yang pasti ditolak
| tidak ditawarkan.
|--------------------------------------------------------------------------
*/
const canCancelFor = (row: CashAdvanceRow): boolean => {
  return canCancel.value
    && (isStatus(row, 'APPROVED') || isStatus(row, 'RECEIVED'))
    && !row.has_active_realization
}

const goToCreateRealization = (row: CashAdvanceRow): void => {
  router.push({
    path: '/fund_request/cash_advance_realization/create',

    /*
    | Id numerik, bukan public_id.
    |
    | public_id adalah hasil enkripsi dengan IV acak, jadi nilainya berbeda
    | setiap kali dibuat walau menunjuk dokumen yang sama. Form realisasi
    | mencocokkan query ini dengan daftar FPU yang dimuatnya sendiri, dan
    | pencocokan itu hanya bisa diandalkan memakai id yang stabil.
    */
    query: { cash_advance_id: String(row.id) },
  })
}

const submitCashAdvance = async (row: CashAdvanceRow): Promise<void> => {
  if (!row?.public_id)
    return

  const confirm = await showConfirmAlert({
    title: t('cashAdvance.list.toast.submitConfirmTitle'),
    text: t('cashAdvance.list.toast.submitConfirmText', { nomor: row.advance_number }),
    confirmButtonText: t('cashAdvance.list.toast.submitConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed)
    return

  try {
    showLoadingAlert(
      t('cashAdvance.list.toast.submitLoading'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/cash-advance/${row.public_id}/submit`,
      {},
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvance.list.toast.submitSuccessFallback'),
    })

    await fetchCashAdvances()
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvance.list.toast.submitFailedFallback')),
    })
  }
}

const openSubmit = async (row: CashAdvanceRow): Promise<void> => {
  pendingAction.value = 'submit'
  pendingRow.value = row

  try {
    if (!await checkUserSignature()) {
      await openSignatureDialog()

      return
    }

    await submitCashAdvance(row)
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, 'Gagal memeriksa tanda tangan digital.'),
    })
  }
}

const showApproveDialog = (row: CashAdvanceRow): void => {
  approveTarget.value = row
  approveNotes.value = ''
  approveDialog.value = true
}

const openApprove = async (row: CashAdvanceRow): Promise<void> => {
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
      text: getApiErrorMessage(error, 'Gagal memeriksa tanda tangan digital.'),
    })
  }
}

const approveCashAdvance = async (): Promise<void> => {
  if (!approveTarget.value?.public_id || approveLoading.value)
    return

  approveLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/cash-advance/${approveTarget.value.public_id}/approve`,
      { notes: approveNotes.value || null },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    approveDialog.value = false
    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvance.list.approve.successFallback'),
    })

    await fetchCashAdvances()
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvance.list.approve.failedFallback')),
    })
  }
  finally {
    approveLoading.value = false
  }
}

const openReject = (row: CashAdvanceRow): void => {
  rejectTarget.value = row
  rejectNotes.value = ''
  rejectError.value = ''
  rejectDialog.value = true
}

const rejectCashAdvance = async (): Promise<void> => {
  if (!rejectTarget.value?.public_id || rejectLoading.value)
    return

  if (!rejectNotes.value.trim()) {
    rejectError.value = t('cashAdvance.list.reject.notesRequired')

    return
  }

  rejectLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/cash-advance/${rejectTarget.value.public_id}/reject`,
      { notes: rejectNotes.value },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    rejectDialog.value = false
    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvance.list.reject.successFallback'),
    })

    await fetchCashAdvances()
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvance.list.reject.failedFallback')),
    })
  }
  finally {
    rejectLoading.value = false
  }
}

const openCancel = (row: CashAdvanceRow): void => {
  cancelTarget.value = row
  cancelNotes.value = ''
  cancelError.value = ''
  cancelDialog.value = true
}

const cancelCashAdvance = async (): Promise<void> => {
  if (!cancelTarget.value?.public_id || cancelLoading.value)
    return

  if (!cancelNotes.value.trim()) {
    cancelError.value = t('cashAdvance.list.cancel.notesRequired')

    return
  }

  cancelLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/cash-advance/${cancelTarget.value.public_id}/cancel`,
      { notes: cancelNotes.value },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    cancelDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvance.list.cancel.successFallback'),
    })

    await fetchCashAdvances()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvance.list.cancel.failedFallback')),
    })
  }
  finally {
    cancelLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Pilih banyak dokumen
|--------------------------------------------------------------------------
| Halaman ini punya dua tindakan berbeda pada daftar yang sama, sedangkan
| tombolnya hanya satu. Karena itu pilihannya dikunci pada satu tindakan:
| baris pertama yang dicentang menentukannya, dan baris yang membutuhkan
| tindakan lain dinonaktifkan selama pilihan itu berjalan.
|
| Satu tombol yang mengerjakan dua hal berbeda pada baris berbeda adalah cara
| paling mudah membuat orang salah menekan.
|--------------------------------------------------------------------------
*/
/** Kolom centang tidak perlu ada bila tidak ada satu pun yang bisa diproses. */
const canBulkSelect = computed<boolean>(() => canReceive.value || canDisburse.value)

/** Tindakan massal yang dibutuhkan satu baris, bila ada. */
const rowBulkAction = (row: CashAdvanceRow): 'RECEIVE' | 'DISBURSE' | null => {
  if (canReceive.value && isStatus(row, 'APPROVED'))
    return 'RECEIVE'

  /* Hari ini bukan harinya berarti baris itu tidak bisa diproses sama sekali. */
  if (canDisburse.value && isStatus(row, 'RECEIVED') && canPayTodayRow(row))
    return 'DISBURSE'

  return null
}

const isSelected = (row: CashAdvanceRow): boolean =>
  selectedIds.value.includes(String(row.public_id))

/** Baris boleh dicentang bila tindakannya sama dengan yang sedang berjalan. */
const isSelectable = (row: CashAdvanceRow): boolean => {
  const aksi = rowBulkAction(row)

  if (aksi === null)
    return false

  return bulkMode.value === null || bulkMode.value === aksi
}

const selectableRows = computed<CashAdvanceRow[]>(() =>
  rows.value.filter(row => isSelectable(row)))

const allSelected = computed<boolean>(() =>
  selectableRows.value.length > 0
  && selectableRows.value.every(row => isSelected(row)))

const someSelected = computed<boolean>(() =>
  selectedIds.value.length > 0 && !allSelected.value)

const toggleRow = (row: CashAdvanceRow): void => {
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
  bulkMode.value === 'RECEIVE'
    ? 'cashAdvance.list.bulk.RECEIVE'
    : 'cashAdvance.list.bulk.DISBURSE')

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

  const endpoint = bulkMode.value === 'RECEIVE'
    ? '/fund-request/cash-advance/bulk-receive'
    : '/fund-request/cash-advance/bulk-disburse'

  bulkLoading.value = true

  try {
    showLoadingAlert(
      t(`${bulkTextKey.value}.loadingTitle`, { count: selectedIds.value.length }),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.post(
      endpoint,
      { public_ids: selectedIds.value },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    const gagal = response.data?.data?.failed ?? []

    /*
    | Hasil sebagian dilaporkan sebagai peringatan, dengan dokumen yang gagal
    | disebut satu per satu -- supaya jelas mana yang masih perlu ditangani.
    */
    if (gagal.length > 0) {
      showWarningToast({
        title: t('cashAdvance.list.bulk.partialTitle'),
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

    await fetchCashAdvances()
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
|--------------------------------------------------------------------------
| Penegasan, bukan formulir
|--------------------------------------------------------------------------
| Seragam dengan pencairan: penerimaan hanya menandai bahwa berkasnya sudah
| di tangan, jadi cukup satu pertanyaan penegasan.
|
| Kolom catatan dan lampiran di database tetap ada, dan endpoint-nya masih
| menerima keduanya -- layar ini saja yang tidak lagi mengirimnya.
|--------------------------------------------------------------------------
*/
const openReceive = async (row: CashAdvanceRow): Promise<void> => {
  if (!row?.public_id || receiveLoading.value)
    return

  const konfirmasi = await showConfirmAlert({
    title: t('cashAdvance.list.receive.confirmTitle'),
    text: t('cashAdvance.list.receive.confirmText', { nomor: row.advance_number || '-' }),
    confirmButtonText: t('cashAdvance.list.receive.confirmButton'),
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

    /* PATCH biasa: tanpa berkas, method spoofing tidak diperlukan lagi. */
    const response = await axios.patch(
      `/fund-request/cash-advance/${row.public_id}/receive`,
      {},
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvance.list.receive.successFallback'),
    })

    await fetchCashAdvances()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvance.list.receive.failedFallback')),
    })
  }
  finally {
    receiveLoading.value = false
  }
}

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

const paymentDeviation = (row: CashAdvanceRow): 'EARLY' | 'LATE' | null => {
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
  row: CashAdvanceRow,
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
    ns: 'cashAdvance',
    title: judul,
    message: pesan,
    confirmText: tombol,
    deviation: simpangan,
    scheduledDate: String(row.scheduled_payment_date ?? ''),
  })

  return { lanjut: hasil?.confirmed === true, catatan: hasil?.notes ?? '' }
}

const openDisburse = async (row: CashAdvanceRow): Promise<void> => {
  if (!row?.public_id || disburseLoading.value)
    return

  const { lanjut, catatan } = await askPaymentConfirmation(
    row,
    t('cashAdvance.list.disburse.confirmTitle'),
    t('cashAdvance.list.disburse.confirmText', { nomor: row.advance_number || '-' }),
    t('cashAdvance.list.disburse.confirmButton'),
  )

  if (!lanjut)
    return

  disburseLoading.value = true

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
      `/fund-request/cash-advance/${row.public_id}/disburse`,
      { notes: catatan },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvance.list.disburse.successFallback'),
    })

    await fetchCashAdvances()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvance.list.disburse.failedFallback')),
    })
  }
  finally {
    disburseLoading.value = false
  }
}

const openDelete = async (row: CashAdvanceRow): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: t('cashAdvance.list.toast.deleteConfirmTitle'),
    text: t('cashAdvance.list.toast.deleteConfirmText', { nomor: row.advance_number }),
    confirmButtonText: t('common.alert.deleteConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed)
    return

  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    const response = await axios.delete(
      `/fund-request/cash-advance/${row.public_id}`,
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvance.list.toast.deleteSuccess'),
    })

    await fetchCashAdvances()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvance.list.toast.deleteFailed')),
    })
  }
}

const openDetail = async (publicId: string): Promise<void> => {
  detailDialog.value = true
  detailLoading.value = true
  detailError.value = ''
  detail.value = null

  try {
    const response = await axios.get(`/fund-request/cash-advance/${publicId}`, {
      headers: { Accept: 'application/json' },
    })

    detail.value = response.data?.data ?? null

    if (!detail.value)
      detailError.value = t('cashAdvance.detail.loadFailed')
  }
  catch (error: unknown) {
    detailError.value = getApiErrorMessage(error, t('cashAdvance.detail.loadFailed'))
  }
  finally {
    detailLoading.value = false
  }
}

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

/*
|--------------------------------------------------------------------------
| Tampilan detail
|--------------------------------------------------------------------------
*/

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
const getApprovalMoment = (approval: any): string | null =>
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
  approvers: any[]
  visibleApprovers: any[]
  moment: string | null
}

/**
 * Status satu tahap, disimpulkan dari para approver di dalamnya.
 *
 * Penolakan mengalahkan apa pun: satu penolakan sudah menghentikan dokumen.
 * Pada mode ANY satu persetujuan menuntaskan tahapnya, sedangkan pada mode
 * ALL tahap baru selesai bila seluruh approver menyetujui.
 */
const resolveStepStatus = (approvers: any[], mode: string): string => {
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
  const daftarApproval = (detail.value?.approvals ?? []) as any[]
  const perStep = new Map<number, any[]>()

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

const formatFileSize = (size: number | string | null | undefined): string => {
  const bytes = Number(size || 0)

  if (!bytes || Number.isNaN(bytes))
    return '-'

  if (bytes < 1024)
    return `${bytes} B`

  if (bytes < 1024 * 1024)
    return `${(bytes / 1024).toFixed(1)} KB`

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

/**
 * Warna aksen kepala modal, mengikuti status dokumen.
 *
 * Dipakai sebagai kelas, bukan style inline, supaya gradasinya tetap mengikuti
 * palet tema saat mode gelap dinyalakan.
 */
const detailAccent = computed<string>(() => getStatusColor(detail.value?.status))

/**
 * Dokumen yang dibuka masih menunggu realisasi.
 *
 * Sama seperti penanda di daftar: dananya sudah keluar, dokumen realisasinya
 * belum ada.
 */
const detailNeedsRealization = computed<boolean>(() => {
  const source = detail.value

  if (!source)
    return false

  return String(source.status || '').trim().toUpperCase() === 'DISBURSED'
    && !source.has_realization
})

/**
 * Ringkasan penutup dokumen: ditolak, dibatalkan, diterima, atau dibayarkan.
 *
 * primary dipakai untuk penerimaan -- warnanya sama dengan penanda pada
 * daftar, sehingga satu keadaan tetap terbaca satu warna di mana pun.
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
    ON_TRACK: { color: 'info', icon: 'tabler-calendar-event', label: t('cashAdvance.detail.paymentOnTrack') },
    OVERDUE: { color: 'error', icon: 'tabler-alert-triangle', label: t('cashAdvance.detail.paymentOverdue') },
    EARLY: { color: 'success', icon: 'tabler-rocket', label: t('cashAdvance.detail.paymentEarly') },
    ON_TIME: { color: 'success', icon: 'tabler-circle-check', label: t('cashAdvance.detail.paymentOnTime') },
    LATE: { color: 'warning', icon: 'tabler-clock-exclamation', label: t('cashAdvance.detail.paymentLate') },
  }

  const dipakai = tampilan[String(source.payment_timing || '')] ?? tampilan.ON_TRACK

  return {
    date: formatDate(source.scheduled_payment_date),
    ...dipakai,
  }
})

const detailClosingNote = computed<{
  type: 'error' | 'warning' | 'info'
  icon: string
  title: string
  meta: string
  notes: string | null
} | null>(() => {
  const source = detail.value

  if (!source)
    return null

  if (source.rejection_notes || source.rejected_at) {
    return {
      type: 'error',
      icon: 'tabler-circle-x',
      title: t('cashAdvance.detail.rejectionNotes'),
      meta: [
        source.rejected_by_name,
        formatAuditDateTime(source.rejected_at),
      ].filter(Boolean).join(' — '),
      notes: source.rejection_notes || null,
    }
  }

  if (source.cancellation_notes || source.cancelled_at) {
    return {
      type: 'warning',
      icon: 'tabler-ban',
      title: t('cashAdvance.detail.cancellationNotes'),
      meta: [
        source.cancelled_by_name,
        formatAuditDateTime(source.cancelled_at),
      ].filter(Boolean).join(' — '),
      notes: source.cancellation_notes || null,
    }
  }

  /*
  | Penerimaan mendahului pencairan, jadi diperiksa lebih dulu -- tetapi hanya
  | ditampilkan selama dananya belum keluar. Setelah dicairkan, jejak
  | pencairanlah yang lebih menjelaskan keadaan dokumennya.
  */
  if (source.received_at && !source.disbursed_at) {
    return {
      type: 'info',
      icon: 'tabler-inbox',
      title: t('cashAdvance.detail.receivedAt'),
      meta: [
        source.received_by_name,
        formatAuditDateTime(source.received_at),
      ].filter(Boolean).join(' — '),
      notes: source.receipt_notes || null,
    }
  }

  if (source.disbursed_at) {
    return {
      type: 'info',
      icon: 'tabler-cash',
      title: t('cashAdvance.detail.disbursedAt'),
      meta: [
        source.disbursed_by_name,
        formatAuditDateTime(source.disbursed_at),
      ].filter(Boolean).join(' — '),
      notes: source.disbursement_notes || null,
    }
  }

  return null
})

/*
|--------------------------------------------------------------------------
| Watcher
|--------------------------------------------------------------------------
*/
watch(currentPage, async () => {
  await fetchCashAdvances()
})

watch(rowPerPage, async () => {
  currentPage.value = 1
  await fetchCashAdvances()
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
    await fetchCashAdvances()
  },
)

/*
 * Rentang tanggal terbalik dikoreksi langsung supaya query tidak pernah
 * dikirim dalam keadaan mustahil.
 */
watch(endDate, newValue => {
  if (!newValue || !startDate.value)
    return

  if (new Date(newValue) < new Date(startDate.value)) {
    endDate.value = null

    showErrorToast({
      title: t('common.alert.error'),
      text: t('cashAdvance.list.filters.endDate'),
    })
  }
})

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canView.value) {
    await router.replace('/forbidden')

    return
  }

  isCheckingPermission.value = false

  await fetchCashAdvances()

  /* Scope baru diketahui dari respons daftar, jadi pilihannya menyusul. */
  await fetchFilterOptions()

  window.addEventListener('resize', resizeSignatureCanvas)

  const success = route.query.success

  if (success) {
    await router.replace({ path: '/fund_request/cash_advance', query: {} })

    setTimeout(() => {
      if (success === 'created') {
        showSuccessToast({
          title: t('common.alert.success'),
          text: t('cashAdvance.list.toast.createdSuccess'),
        })
      }

      if (success === 'updated') {
        showSuccessToast({
          title: t('common.alert.success'),
          text: t('cashAdvance.list.toast.updatedSuccess'),
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
                {{ t('cashAdvance.list.filtersTitle') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('cashAdvance.list.filtersSubtitle') }}
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
            {{ t('cashAdvance.list.filters.resetButton') }}
          </VBtn>
        </div>

        <VRow>
          <VCol
            cols="12"
            md="4"
          >
            <VTextField
              v-model="searchQuery"
              :label="t('cashAdvance.list.filters.searchLabel')"
              :placeholder="t('cashAdvance.list.filters.searchPlaceholder')"
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
            md="4"
          >
            <AppDateTimePicker
              v-model="scheduledPaymentDate"
              :label="t('cashAdvance.list.filters.scheduledPaymentDate')"
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
            md="4"
          >
            <AppDateTimePicker
              v-model="startDate"
              :label="t('cashAdvance.list.filters.startDate')"
              density="compact"
              clearable
              :config="{ dateFormat: 'Y-m-d', position: 'below' }"
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <AppDateTimePicker
              v-model="endDate"
              :label="t('cashAdvance.list.filters.endDate')"
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
              :label="t('cashAdvance.list.filters.statusLabel')"
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
              :label="t('cashAdvance.list.filters.branchLabel')"
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
              :label="t('cashAdvance.list.filters.departmentLabel')"
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
            Pilihan di dalamnya menyesuaikan kewenangan pembaca, jadi lebarnya
            dibuat sama dengan filter status di sebelahnya.
          -->
          <VCol
            cols="12"
            md="4"
          >
            <VSelect
              v-model="selectedPendingAction"
              :label="t('cashAdvance.list.filters.pendingActionLabel')"
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
            md="4"
          >
            <div class="ca-approval-filter">
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
                  {{ t('cashAdvance.list.onlyMyApproval') }}
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
      class="ca-bulk-bar mb-4"
    >
      <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-3">
        <div class="d-flex align-center gap-3 min-w-0">
          <VAvatar
            size="36"
            color="primary"
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
            {{ t('cashAdvance.list.bulk.clearButton') }}
          </VBtn>

          <VBtn
            color="primary"
            size="small"
            class="text-none"
            prepend-icon="tabler-checks"
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
      {{ t('cashAdvance.list.paymentDay.blockedNotice', { days: paymentDaysText }) }}
    </VAlert>

    <!--
      Batas pengajuan: diberitahukan sebelum, bukan setelah ditolak.

      Hanya muncul kalau memang ada yang sedang berjalan. "Anda punya 0 dari
      3 FPU yang masih berjalan" tidak memberi tahu apa pun -- ia hanya
      menempati ruang di atas daftar, setiap kali halaman dibuka.

      Tidak ada yang hilang karena disembunyikan: dokumen yang telat
      realisasi dihitung dari yang sedang berjalan juga, dan batasnya baru
      penuh kalau jumlahnya mencapai maksimum -- keduanya mustahil saat
      jumlahnya nol.
    -->
    <VAlert
      v-if="submissionLimit && submissionLimit.max_outstanding && submissionLimit.outstanding > 0"
      :type="submissionBlocked ? 'warning' : 'info'"
      variant="tonal"
      density="comfortable"
      class="mb-4"
      :icon="submissionBlocked ? 'tabler-hand-stop' : 'tabler-info-circle'"
    >
      <div class="font-weight-medium">
        {{ t('cashAdvance.list.submissionLimit.counter', {
          count: submissionLimit.outstanding,
          max: submissionLimit.max_outstanding,
        }) }}
      </div>

      <div
        v-if="overdueText"
        class="text-body-2 mt-1"
      >
        {{ t('cashAdvance.list.submissionLimit.overdue', {
          days: submissionLimit.max_days,
          documents: overdueText,
        }) }}
      </div>

      <div
        v-else-if="submissionBlocked"
        class="text-body-2 mt-1"
      >
        {{ t('cashAdvance.list.submissionLimit.blocked') }}
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
          :disabled="submissionBlocked"
          @click="goToCreate"
        >
          {{ t('cashAdvance.list.createButton') }}
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
          {{ t('cashAdvance.list.exportButton') }}
        </VBtn>

        <VSpacer />

        <VChip
          v-if="loading"
          size="small"
          variant="tonal"
        >
          {{ t('cashAdvance.list.toast.loadingText') }}
        </VChip>

        <VBtn
          v-else-if="loadError"
          size="small"
          color="error"
          variant="tonal"
          prepend-icon="tabler-refresh"
          class="text-none"
          @click="fetchCashAdvances"
        >
          {{ t('cashAdvance.list.toast.reloadData') }}
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
              {{ t('cashAdvance.list.table.no') }}
            </th>
            <th scope="col">
              {{ t('cashAdvance.list.table.advanceNumber') }}
            </th>
            <th scope="col">
              {{ t('cashAdvance.list.table.date') }}
            </th>
            <th scope="col">
              {{ t('cashAdvance.list.table.branch') }}
            </th>
            <th scope="col">
              {{ t('cashAdvance.list.table.department') }}
            </th>
            <th
              scope="col"
              class="ca-subject-col"
            >
              {{ t('cashAdvance.list.table.subject') }}
            </th>
            <th
              scope="col"
              class="text-end"
            >
              {{ t('cashAdvance.list.table.total') }}
            </th>
            <th scope="col">
              {{ t('cashAdvance.list.table.status') }}
            </th>
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="(row, index) in rows"
            :key="row.id"
            :class="{
              'ca-row-need-approval': row.can_approve,
              'ca-row-need-realization': needsRealization(row),
              'ca-row-need-receipt': needsReceipt(row),
              'ca-row-need-disbursement': needsDisbursement(row),
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
                    class="ca-number-action d-inline-flex flex-column gap-1"
                  >
                    <div class="d-flex align-center gap-1 font-weight-medium text-primary">
                      <span>{{ row.advance_number || '-' }}</span>

                      <VIcon
                        icon="tabler-chevron-down"
                        size="16"
                      />
                    </div>

                    <VChip
                      v-if="row.can_approve"
                      size="x-small"
                      color="warning"
                      variant="tonal"
                    >
                      <VIcon
                        icon="tabler-alert-circle"
                        size="14"
                        start
                      />

                      {{ t('cashAdvance.list.menu.waitingMyApprovalBadge') }}
                    </VChip>

                    <!--
                      Menunggu PIC menerima dokumennya. Approved kini berarti
                      berkasnya siap diambil, belum berarti dananya diproses.
                    -->
                    <VChip
                      v-if="needsReceipt(row)"
                      size="x-small"
                      color="primary"
                      variant="tonal"
                    >
                      <VIcon
                        icon="tabler-inbox"
                        size="14"
                        start
                      />

                      {{ t('cashAdvance.list.menu.notReceivedBadge') }}
                    </VChip>

                    <!--
                      Penanda tindakan Finance yang masih ditunggu. Sudah
                      diterima, dananya belum keluar.
                    -->
                    <VChip
                      v-if="needsDisbursement(row)"
                      size="x-small"
                      color="info"
                      variant="tonal"
                    >
                      <VIcon
                        icon="tabler-cash-banknote"
                        size="14"
                        start
                      />

                      {{ t('cashAdvance.list.menu.notDisbursedBadge') }}
                    </VChip>

                    <!--
                      Penanda pertanggungjawaban, bukan status. Dananya sudah
                      keluar tetapi belum ada dokumen realisasinya.
                    -->
                    <VChip
                      v-if="needsRealization(row)"
                      size="x-small"
                      color="success"
                      variant="tonal"
                    >
                      <VIcon
                        icon="tabler-file-dollar"
                        size="14"
                        start
                      />

                      {{ t('cashAdvance.list.menu.notRealizedBadge') }}
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
                      {{ t('cashAdvance.list.menu.viewDetail') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="canPrint(row)"
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
                      {{ t('cashAdvance.list.menu.print') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="row.can_approve"
                    href="javascript:void(0)"
                    :disabled="approveLoading"
                    @click="openApprove(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-circle-check"
                        :size="20"
                        class="me-3 text-success"
                      />
                    </template>

                    <VListItemTitle class="text-success">
                      {{ t('cashAdvance.list.menu.approve') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="row.can_approve"
                    href="javascript:void(0)"
                    :disabled="rejectLoading"
                    @click="openReject(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="mdi-close-circle-outline"
                        :size="20"
                        color="error"
                        class="me-3"
                      />
                    </template>

                    <VListItemTitle class="text-error">
                      {{ t('cashAdvance.list.menu.reject') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="row.can_submit"
                    href="javascript:void(0)"
                    @click="openSubmit(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="mdi-send-outline"
                        :size="20"
                        class="me-3"
                      />
                    </template>

                    <VListItemTitle>
                      {{ t('cashAdvance.list.menu.submit') }}
                    </VListItemTitle>
                  </VListItem>

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
                      {{ t('cashAdvance.list.menu.receive') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="isStatus(row, 'RECEIVED') && canDisburse"
                    :disabled="!canPayTodayRow(row)"
                    href="javascript:void(0)"
                    @click="openDisburse(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-cash-banknote"
                        :size="20"
                        class="me-3 text-info"
                      />
                    </template>

                    <VListItemTitle class="text-info">
                      {{ t('cashAdvance.list.menu.disburse') }}
                    </VListItemTitle>
                  </VListItem>

                  <!--
                    Pintasan ke form Realisasi dengan FPU ini sudah terpilih,
                    jadi user tidak perlu mencarinya lagi di sana.
                  -->
                  <VListItem
                    v-if="canCreateRealizationFor(row)"
                    href="javascript:void(0)"
                    @click="goToCreateRealization(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-receipt-2"
                        :size="20"
                        class="me-3 text-primary"
                      />
                    </template>

                    <VListItemTitle class="text-primary">
                      {{ t('cashAdvance.list.menu.createRealization') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="canCancelFor(row)"
                    href="javascript:void(0)"
                    @click="openCancel(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-ban"
                        :size="20"
                        color="error"
                        class="me-3"
                      />
                    </template>

                    <VListItemTitle class="text-error">
                      {{ t('cashAdvance.list.menu.cancel') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="isStatus(row, 'DRAFT') && canUpdate"
                    href="javascript:void(0)"
                    @click="goToEdit(row.public_id)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="mdi-pencil-outline"
                        :size="20"
                        class="me-3"
                      />
                    </template>

                    <VListItemTitle>
                      {{ t('cashAdvance.list.menu.edit') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="isStatus(row, 'DRAFT') && canDelete"
                    href="javascript:void(0)"
                    @click="openDelete(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-trash"
                        :size="20"
                        class="me-3 text-error"
                      />
                    </template>

                    <VListItemTitle class="text-error">
                      {{ t('cashAdvance.list.menu.delete') }}
                    </VListItemTitle>
                  </VListItem>
                </VList>
              </VMenu>
            </td>

            <td>{{ formatDate(row.date) || '-' }}</td>
            <td>{{ row.branch || '-' }}</td>
            <td>{{ row.department || '-' }}</td>

            <td class="ca-subject-col text-wrap">
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
                {{ t('cashAdvance.list.emptyTitle') }}
              </div>

              <div class="text-caption text-medium-emphasis">
                {{ t('cashAdvance.list.emptySubtitle') }}
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
      <VCard class="ca-detail">
        <!--
          Kepala modal: identitas dokumen dan nominalnya dijadikan satu blok
          supaya dua hal yang paling sering dicari langsung terbaca.
        -->
        <div
          class="ca-detail__header"
          :class="[`ca-detail__header--${detailAccent}`]"
        >
          <div class="d-flex align-center justify-space-between gap-3">
            <div class="ca-detail__eyebrow">
              <VIcon
                icon="tabler-file-description"
                size="16"
              />
              {{ t('cashAdvance.detail.title') }}
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
            <div class="ca-detail__identity">
              <div class="ca-detail__number">
                {{ detail?.advance_number || '—' }}
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

                <VChip
                  v-if="detail?.request_type"
                  size="small"
                  variant="tonal"
                  color="secondary"
                >
                  {{ detail.request_type }}
                </VChip>
              </div>
            </div>

            <div
              v-if="detail"
              class="ca-detail__amount"
            >
              <div class="ca-detail__amount-label">
                {{ t('cashAdvance.detail.total') }}
              </div>

              <div class="ca-detail__amount-value">
                Rp {{ formatNumberWithoutRp(Number(detail.total_amount || 0)) }}
              </div>
            </div>
          </div>
        </div>

        <VDivider />

        <VCardText class="ca-detail__body">
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
            <div class="ca-detail__grid">
              <div class="ca-detail__field">
                <VIcon
                  icon="tabler-calendar-event"
                  size="18"
                />

                <div class="ca-detail__field-body">
                  <div class="ca-detail__label">
                    {{ t('cashAdvance.detail.date') }}
                  </div>

                  <div class="ca-detail__value">
                    {{ formatDate(detail.date) || '-' }}
                  </div>
                </div>
              </div>

              <div class="ca-detail__field">
                <VIcon
                  icon="tabler-building-store"
                  size="18"
                />

                <div class="ca-detail__field-body">
                  <div class="ca-detail__label">
                    {{ t('cashAdvance.detail.branch') }}
                  </div>

                  <div class="ca-detail__value">
                    {{ detail.branch || '-' }}
                  </div>
                </div>
              </div>

              <div class="ca-detail__field">
                <VIcon
                  icon="tabler-users-group"
                  size="18"
                />

                <div class="ca-detail__field-body">
                  <div class="ca-detail__label">
                    {{ t('cashAdvance.detail.department') }}
                  </div>

                  <div class="ca-detail__value">
                    {{ detail.department_name || detail.department || '-' }}
                  </div>
                </div>
              </div>

              <div class="ca-detail__field">
                <VIcon
                  icon="tabler-user-plus"
                  size="18"
                />

                <div class="ca-detail__field-body">
                  <div class="ca-detail__label">
                    {{ t('cashAdvance.detail.createdBy') }}
                  </div>

                  <div class="ca-detail__value">
                    {{ detail.created_by_name || '-' }}
                  </div>

                  <div
                    v-if="detail.created_at"
                    class="ca-detail__meta"
                  >
                    {{ formatAuditDateTime(detail.created_at) }}
                  </div>
                </div>
              </div>

              <div class="ca-detail__field">
                <VIcon
                  icon="tabler-send"
                  size="18"
                />

                <div class="ca-detail__field-body">
                  <div class="ca-detail__label">
                    {{ t('cashAdvance.detail.submittedBy') }}
                  </div>

                  <div class="ca-detail__value">
                    {{ detail.submitted_by_name || '-' }}
                  </div>

                  <div
                    v-if="detail.submitted_at"
                    class="ca-detail__meta"
                  >
                    {{ formatAuditDateTime(detail.submitted_at) }}
                  </div>
                </div>
              </div>

              <div
                v-if="detail.final_approved_at"
                class="ca-detail__field"
              >
                <VIcon
                  icon="tabler-circle-check"
                  size="18"
                />

                <div class="ca-detail__field-body">
                  <div class="ca-detail__label">
                    {{ t('cashAdvance.detail.finalApprovedBy') }}
                  </div>

                  <div class="ca-detail__value">
                    {{ detail.final_approved_by_name || '-' }}
                  </div>

                  <div class="ca-detail__meta">
                    {{ formatAuditDateTime(detail.final_approved_at) }}
                  </div>
                </div>
              </div>
            </div>

            <!--
              Penanda pertanggungjawaban yang masih menggantung. Dananya sudah
              keluar tetapi belum ada dokumen realisasinya.
            -->
            <VAlert
              v-if="detailNeedsRealization"
              type="success"
              variant="tonal"
              density="compact"
              class="mt-4"
            >
              {{ t('cashAdvance.detail.notRealizedNotice') }}
            </VAlert>

            <!-- Perihal dan catatan -->
            <div class="ca-detail__note mt-4">
              <div class="ca-detail__label">
                {{ t('cashAdvance.detail.subject') }}
              </div>

              <div class="ca-detail__note-text">
                {{ detail.subject || '-' }}
              </div>

              <template v-if="detail.notes">
                <div class="ca-detail__label mt-3">
                  {{ t('cashAdvance.detail.notes') }}
                </div>

                <div class="ca-detail__note-text">
                  {{ detail.notes }}
                </div>
              </template>
            </div>

            <!--
              PERJALANAN DINAS

              Konteks ini dulu hanya ada di kotak konfirmasi Approve. Padahal
              yang dibaca sebelum memutuskan adalah detail ini -- kotak
              konfirmasi datang belakangan, setelah keputusannya praktis
              sudah diambil.

              Yang ditampilkan tetap KONTEKS, bukan pengganti persetujuan:
              formulir perdin tidak memuat nominal sama sekali, jadi
              menyetujui perjalanannya tidak pernah berarti menyetujui
              jumlah uangnya.
            -->
            <template v-if="detail.business_trip">
              <div class="ca-detail__section-title">
                <VIcon
                  icon="tabler-plane"
                  size="18"
                />
                {{ t('cashAdvance.detail.businessTripTitle') }}
              </div>

              <div class="ca-detail__note">
                <div class="d-flex align-center flex-wrap gap-2">
                  <span class="font-weight-bold">
                    {{ detail.business_trip.trip_number }}
                  </span>

                  <VChip
                    size="x-small"
                    variant="flat"
                    :color="getStatusColor(detail.business_trip.status)"
                  >
                    {{ formatStatus(detail.business_trip.status) }}
                  </VChip>

                  <VSpacer />

                  <!-- Rinciannya dibuka di tempat, bukan dengan pindah halaman. -->
                  <VBtn
                    size="small"
                    variant="text"
                    color="primary"
                    prepend-icon="tabler-eye"
                    class="text-none"
                    @click="businessTripDialog = true"
                  >
                    {{ t('cashAdvance.detail.businessTripView') }}
                  </VBtn>
                </div>

                <div class="ca-detail__note-text mt-1">
                  {{ detail.business_trip.destination || '-' }}
                </div>

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ detail.business_trip.depart_date }}
                  <template v-if="detail.business_trip.depart_time">
                    {{ detail.business_trip.depart_time }}
                  </template>

                  &rarr;

                  {{ detail.business_trip.return_date }}
                  <template v-if="detail.business_trip.return_time">
                    {{ detail.business_trip.return_time }}
                  </template>
                </div>

                <div
                  v-if="detail.business_trip.purpose"
                  class="text-caption text-medium-emphasis mt-1"
                >
                  {{ detail.business_trip.purpose }}
                </div>
              </div>

              <!--
                Yang membedakan panel ini dari sekadar keterangan: ia tahu
                apakah PEMBACANYA SENDIRI sudah menyetujui perjalanannya.
              -->
              <VAlert
                :type="detail.business_trip.my_approval ? 'success' : 'info'"
                variant="tonal"
                density="comfortable"
                class="mt-3"
                :icon="detail.business_trip.my_approval ? 'tabler-check' : 'tabler-plane'"
              >
                {{ detail.business_trip.my_approval
                  ? t('cashAdvance.detail.businessTripApprovedByMe', {
                    step: detail.business_trip.my_approval.step_order,
                    label: detail.business_trip.my_approval.label || '-',
                    when: formatDateTimeShort(detail.business_trip.my_approval.approved_at),
                  })
                  : t('cashAdvance.detail.businessTripContext') }}
              </VAlert>

              <!--
                Perdin yang belum disetujui tidak menghalangi FPU-nya diajukan
                (aturan H-7), tetapi menahan pencairannya. Disebut di sini
                supaya penyetuju tahu keadaannya sebelum memutuskan, bukan
                menemukannya saat pencairan ditahan.
              -->
              <VAlert
                v-if="detail.business_trip.disbursement_block"
                type="warning"
                variant="tonal"
                density="compact"
                class="mt-2"
              >
                {{ detail.business_trip.disbursement_block }}
              </VAlert>
            </template>

            <!-- Rincian -->
            <div class="ca-detail__section-title">
              <VIcon
                icon="tabler-list-details"
                size="18"
              />
              {{ t('cashAdvance.detail.itemsTitle') }}

              <VChip
                size="x-small"
                variant="tonal"
                class="ms-1"
              >
                {{ (detail.items || []).length }}
              </VChip>
            </div>

            <div class="ca-detail__table">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th style="inline-size: 3rem;">
                      {{ t('cashAdvance.detail.itemNo') }}
                    </th>
                    <th style="inline-size: 8rem;">
                      {{ t('cashAdvance.detail.itemDate') }}
                    </th>
                    <th>{{ t('cashAdvance.detail.itemDescription') }}</th>
                    <th
                      class="text-end"
                      style="inline-size: 11rem;"
                    >
                      {{ t('cashAdvance.detail.itemAmount') }}
                    </th>
                    <th style="inline-size: 16rem;">
                      {{ t('cashAdvance.detail.itemAttachment') }}
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
                          class="ca-detail__line-file"
                          :href="attachment.url || undefined"
                          target="_blank"
                          rel="noopener"
                        >
                          <VIcon
                            :icon="getAttachmentIcon(attachment.mime_type)"
                            size="18"
                            color="primary"
                          />

                          <span class="ca-detail__line-file-name">
                            {{ attachment.original_filename || attachment.filename }}
                          </span>
                        </a>
                      </div>

                      <span
                        v-else
                        class="ca-detail__meta"
                      >—</span>
                    </td>
                  </tr>
                </tbody>

                <tfoot>
                  <tr class="ca-detail__table-total">
                    <td colspan="3">
                      {{ t('cashAdvance.detail.total') }}
                    </td>

                    <td class="text-end">
                      Rp {{ formatNumberWithoutRp(Number(detail.total_amount || 0)) }}
                    </td>

                    <td />
                  </tr>
                </tfoot>
              </VTable>
            </div>

            <!-- Riwayat approval sebagai alur, bukan tabel -->
            <div class="ca-detail__section-title">
              <VIcon
                icon="tabler-route"
                size="18"
              />
              {{ t('cashAdvance.detail.approvalsTitle') }}
            </div>

            <VAlert
              v-if="!(detail.approvals || []).length"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('cashAdvance.detail.approvalsEmpty') }}
            </VAlert>

            <VTimeline
              v-else
              side="end"
              align="start"
              density="compact"
              truncate-line="both"
              class="ca-detail__timeline"
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
                    {{ t('cashAdvance.detail.approvalStep') }} {{ step.step_order }}
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
                    class="ca-detail__meta d-inline-flex align-center gap-1"
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
                  class="ca-detail__meta mt-1"
                >
                  {{ step.approval_mode === 'ALL'
                    ? t('cashAdvance.detail.approvalModeAll', { count: step.approvers.length })
                    : t('cashAdvance.detail.approvalModeAny', { count: step.approvers.length }) }}
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
                      class="ca-detail__meta d-inline-flex align-center gap-1"
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
                    class="ca-detail__quote mt-2"
                  >
                    {{ approval.notes }}
                  </div>
                </template>
              </VTimelineItem>
            </VTimeline>

            <!--
              Lampiran tingkat dokumen. Sejak lampiran dipindah ke tiap baris
              rincian, bagian ini hanya terisi pada FPU lama yang dibuat sebelum
              perubahan itu, jadi blok ini disembunyikan bila tidak ada isinya.
            -->
            <template v-if="(detail.attachments || []).length">
              <div class="ca-detail__section-title">
                <VIcon
                  icon="tabler-paperclip"
                  size="18"
                />
                {{ t('cashAdvance.detail.attachmentsTitle') }}
              </div>

              <div class="ca-detail__files">
                <a
                  v-for="attachment in detail.attachments"
                  :key="`detail-attachment-${attachment.id}`"
                  class="ca-detail__file"
                  :href="attachment.url || undefined"
                  target="_blank"
                  rel="noopener"
                >
                  <VIcon
                    :icon="getAttachmentIcon(attachment.mime_type)"
                    size="26"
                    color="primary"
                  />

                  <div class="ca-detail__file-body">
                    <div class="ca-detail__file-name">
                      {{ attachment.original_filename || attachment.filename }}
                    </div>

                    <div class="ca-detail__meta">
                      {{ formatFileSize(attachment.file_size) }}
                    </div>
                  </div>

                  <VIcon
                    icon="tabler-external-link"
                    size="16"
                    class="text-medium-emphasis"
                  />
                </a>
              </div>
            </template>

            <!--
              Bukti transfer dari Finance. Blok ini hanya muncul bila memang ada
              berkasnya -- pencairan tunai boleh tanpa bukti, jadi bagian kosong
              tidak perlu ditampilkan sebagai kekurangan.
            -->
            <template v-if="(detail.receipt_attachments || []).length">
              <div class="ca-detail__section-title">
                <VIcon
                  icon="tabler-inbox"
                  size="18"
                />
                {{ t('cashAdvance.detail.receiptAttachmentsTitle') }}
              </div>

              <div class="ca-detail__files">
                <a
                  v-for="attachment in detail.receipt_attachments"
                  :key="`detail-receipt-attachment-${attachment.id}`"
                  class="ca-detail__file"
                  :href="attachment.url || undefined"
                  target="_blank"
                  rel="noopener"
                >
                  <VIcon
                    :icon="getAttachmentIcon(attachment.mime_type)"
                    size="26"
                    color="primary"
                  />

                  <div class="ca-detail__file-body">
                    <div class="ca-detail__file-name">
                      {{ attachment.original_filename || attachment.filename }}
                    </div>

                    <div class="ca-detail__meta">
                      {{ formatFileSize(attachment.file_size) }}
                    </div>
                  </div>

                  <VIcon
                    icon="tabler-external-link"
                    size="16"
                    class="text-medium-emphasis"
                  />
                </a>
              </div>
            </template>

            <template v-if="(detail.disbursement_attachments || []).length">
              <div class="ca-detail__section-title">
                <VIcon
                  icon="tabler-receipt"
                  size="18"
                />
                {{ t('cashAdvance.detail.disbursementAttachmentsTitle') }}
              </div>

              <div class="ca-detail__files">
                <a
                  v-for="attachment in detail.disbursement_attachments"
                  :key="`detail-disbursement-attachment-${attachment.id}`"
                  class="ca-detail__file"
                  :href="attachment.url || undefined"
                  target="_blank"
                  rel="noopener"
                >
                  <VIcon
                    :icon="getAttachmentIcon(attachment.mime_type)"
                    size="26"
                    color="info"
                  />

                  <div class="ca-detail__file-body">
                    <div class="ca-detail__file-name">
                      {{ attachment.original_filename || attachment.filename }}
                    </div>

                    <div class="ca-detail__meta">
                      {{ formatFileSize(attachment.file_size) }}
                    </div>
                  </div>

                  <VIcon
                    icon="tabler-external-link"
                    size="16"
                    class="text-medium-emphasis"
                  />
                </a>
              </div>
            </template>

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
                  {{ t('cashAdvance.detail.scheduledPaymentDate') }}: {{ detailPaymentSchedule.date }}
                </span>
              </div>

              <div class="text-body-2 mt-1">
                {{ detailPaymentSchedule.label }}
              </div>
            </VAlert>

            <!-- Penutup dokumen: ditolak, dibatalkan, diterima, atau dibayarkan -->
            <VAlert
              v-if="detailClosingNote"
              :type="detailClosingNote.type"
              variant="tonal"
              class="mt-5"
            >
              <div class="font-weight-medium">
                {{ detailClosingNote.title }}
              </div>

              <div
                v-if="detailClosingNote.meta"
                class="text-body-2 mt-1"
              >
                {{ detailClosingNote.meta }}
              </div>

              <div
                v-if="detailClosingNote.notes"
                class="ca-detail__note-text mt-2"
              >
                {{ detailClosingNote.notes }}
              </div>
            </VAlert>
          </template>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end">
          <VBtn
            v-if="detailCanApprove"
            color="success"
            variant="tonal"
            prepend-icon="tabler-circle-check"
            class="text-none"
            :disabled="approveLoading"
            @click="openApprove(detail)"
          >
            {{ t('cashAdvance.detail.approveButton') }}
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
            {{ t('cashAdvance.detail.rejectButton') }}
          </VBtn>

          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            @click="detailDialog = false"
          >
            {{ t('cashAdvance.detail.closeButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      RINCIAN PERJALANAN DINAS

      Kartunya komponen yang sama persis dengan halaman daftar perdin --
      bukan salinan, supaya keduanya tidak bisa berbeda. Slot aksinya sengaja
      tidak diisi: dari sini perdin tidak disetujui, hanya dibaca.
    -->
    <VDialog
      v-model="businessTripDialog"
      max-width="1080"
      scrollable
    >
      <BusinessTripDetailCard
        v-if="detail?.business_trip"
        :trip="detail.business_trip"
        @close="businessTripDialog = false"
      />
    </VDialog>

    <!-- DIALOG APPROVE -->
    <VDialog
      v-model="approveDialog"
      max-width="520"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvance.list.approve.dialogTitle') }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('cashAdvance.list.approve.dialogSubtitle') }}
          </div>

          <div class="font-weight-medium mb-4">
            {{ approveTarget?.advance_number || '-' }}
          </div>

          <!--
            Konteks perdin.

            Penyetuju yang sama sering muncul di rantai Perdin dan rantai FPU.
            Yang ditampilkan di sini BUKAN pengganti persetujuannya: perdin
            tidak memuat nominal sama sekali, jadi menyetujui perjalanan tidak
            pernah berarti menyetujui jumlah uangnya. Ini hanya menghemat
            langkah mencari-cari sebelum memutuskan.
          -->
          <VAlert
            v-if="approveTarget?.business_trip"
            :type="approveTarget.business_trip.my_approval ? 'success' : 'info'"
            variant="tonal"
            density="comfortable"
            class="mb-4"
            :icon="approveTarget.business_trip.my_approval ? 'tabler-check' : 'tabler-plane'"
          >
            <div class="font-weight-medium">
              {{ approveTarget.business_trip.trip_number }}
              &middot; {{ approveTarget.business_trip.destination }}
            </div>

            <div class="text-caption mt-1">
              {{ approveTarget.business_trip.depart_date }}
              &rarr; {{ approveTarget.business_trip.return_date }}
            </div>

            <div class="text-body-2 mt-2">
              {{ approveTarget.business_trip.my_approval
                ? t('cashAdvance.list.approve.tripAlreadyApproved', {
                  when: formatDateTimeShort(approveTarget.business_trip.my_approval.approved_at),
                })
                : t('cashAdvance.list.approve.tripContext') }}
            </div>
          </VAlert>

          <VTextarea
            v-model="approveNotes"
            :label="t('cashAdvance.list.approve.notesLabel')"
            :placeholder="t('cashAdvance.list.approve.notesPlaceholder')"
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
            @click="approveCashAdvance"
          >
            {{ t('cashAdvance.list.approve.confirmButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- DIALOG REJECT -->
    <VDialog
      v-model="rejectDialog"
      max-width="520"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvance.list.reject.dialogTitle') }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('cashAdvance.list.reject.dialogSubtitle') }}
          </div>

          <div class="font-weight-medium mb-4">
            {{ rejectTarget?.advance_number || '-' }}
          </div>

          <VTextarea
            v-model="rejectNotes"
            :label="t('cashAdvance.list.reject.notesLabel')"
            :placeholder="t('cashAdvance.list.reject.notesPlaceholder')"
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
            @click="rejectCashAdvance"
          >
            {{ t('cashAdvance.list.reject.confirmButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- DIALOG CANCEL -->
    <VDialog
      v-model="cancelDialog"
      max-width="520"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvance.list.cancel.dialogTitle') }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('cashAdvance.list.cancel.dialogSubtitle') }}
          </div>

          <div class="font-weight-medium mb-4">
            {{ cancelTarget?.advance_number || '-' }}
          </div>

          <VTextarea
            v-model="cancelNotes"
            :label="t('cashAdvance.list.cancel.notesLabel')"
            :placeholder="t('cashAdvance.list.cancel.notesPlaceholder')"
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
            @click="cancelCashAdvance"
          >
            {{ t('cashAdvance.list.cancel.confirmButton') }}
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
          Tanda Tangan Digital
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            Anda belum memiliki tanda tangan digital. Bubuhkan tanda tangan
            Anda di bawah ini untuk melanjutkan.
          </div>

          <div class="ca-signature-wrapper">
            <canvas
              ref="signatureCanvasRef"
              class="ca-signature-canvas"
            />
          </div>

          <VBtn
            size="small"
            variant="text"
            color="secondary"
            class="text-none mt-2"
            @click="signaturePad?.clear()"
          >
            Bersihkan
          </VBtn>

          <VCheckbox
            v-model="signatureAgree"
            class="mt-2"
            label="Saya menyetujui penggunaan tanda tangan digital ini."
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
            Simpan & Lanjutkan
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
    <!-- Penegasan pembayaran di luar jadwal -->
    <OffSchedulePaymentDialog ref="offScheduleDialog" />
  </section>
</template>

<style scoped>
.ca-approval-filter {
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

.ca-number-action {
  cursor: pointer;
}

.ca-row-need-approval {
  background: rgba(var(--v-theme-warning), 0.06);
}

/*
| FPU yang dananya sudah keluar tetapi belum dipertanggungjawabkan. Ditulis
| setelah aturan approval supaya baris yang menunggu approval user tetap
| menang -- itu tindakan yang lebih mendesak baginya.
*/
.ca-row-need-realization {
  background: rgba(var(--v-theme-success), 0.06);
}

/* Bilah tindakan massal: menonjol tanpa berteriak. */
.ca-bulk-bar {
  border-inline-start: 4px solid rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.04);
}

.ca-row-need-receipt {
  background: rgba(var(--v-theme-primary), 0.06);
}

.ca-row-need-disbursement {
  background: rgba(var(--v-theme-info), 0.06);
}

/*
 * Baris yang menunggu approval user ini didahulukan warnanya: itu tindakan
 * miliknya sendiri, sedangkan menunggu pencairan atau realisasi belum tentu.
 */
.ca-row-need-approval.ca-row-need-realization,
.ca-row-need-approval.ca-row-need-receipt,
.ca-row-need-approval.ca-row-need-disbursement {
  background: rgba(var(--v-theme-warning), 0.06);
}

.ca-subject-col {
  max-inline-size: 20rem;
  white-space: normal;
}

.ca-signature-wrapper {
  border: 1px dashed rgba(var(--v-border-color), 0.5);
  border-radius: 8px;
  block-size: 180px;
}

/*
|--------------------------------------------------------------------------
| Modal detail
|--------------------------------------------------------------------------
| Semua warna memakai token tema, bukan nilai tetap, supaya mode gelap ikut
| menyesuaikan tanpa aturan tambahan.
|--------------------------------------------------------------------------
*/

.ca-detail__header {
  padding-block: 1.25rem;
  padding-inline: 1.5rem;
}

.ca-detail__header--success {
  background: linear-gradient(135deg, rgba(var(--v-theme-success), 0.14), transparent 70%);
}

.ca-detail__header--warning {
  background: linear-gradient(135deg, rgba(var(--v-theme-warning), 0.14), transparent 70%);
}

.ca-detail__header--error {
  background: linear-gradient(135deg, rgba(var(--v-theme-error), 0.14), transparent 70%);
}

.ca-detail__header--info {
  background: linear-gradient(135deg, rgba(var(--v-theme-info), 0.14), transparent 70%);
}

.ca-detail__header--secondary {
  background: linear-gradient(135deg, rgba(var(--v-theme-secondary), 0.12), transparent 70%);
}

.ca-detail__eyebrow {
  display: inline-flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.75rem;
  font-weight: 600;
  gap: 0.375rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.ca-detail__number {
  font-size: 1.375rem;
  font-weight: 700;
  line-height: 1.25;
}

.ca-detail__amount {
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 2px 8px rgba(var(--v-theme-on-surface), 0.08);
  padding-block: 0.5rem;
  padding-inline: 1rem;
  text-align: end;
}

.ca-detail__amount-label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.7rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.ca-detail__amount-value {
  color: rgb(var(--v-theme-primary));
  font-size: 1.25rem;
  font-weight: 700;
  line-height: 1.3;
  white-space: nowrap;
}

.ca-detail__body {
  padding-block: 1.25rem 1.5rem;
}

.ca-detail__grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(13.5rem, 1fr));
}

.ca-detail__field {
  display: flex;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  gap: 0.625rem;
  padding-block: 0.625rem;
  padding-inline: 0.75rem;
}

.ca-detail__field .v-icon {
  color: rgba(var(--v-theme-primary), 0.75);
  margin-block-start: 0.125rem;
}

.ca-detail__field-body {
  min-inline-size: 0;
}

.ca-detail__label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.ca-detail__value {
  font-size: 0.9rem;
  font-weight: 500;
  overflow-wrap: anywhere;
}

.ca-detail__meta {
  color: rgba(var(--v-theme-on-surface), 0.55);
  font-size: 0.75rem;
}

.ca-detail__note {
  border-radius: 10px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  padding-block: 0.75rem;
  padding-inline: 1rem;
}

.ca-detail__note-text {
  font-size: 0.9rem;
  white-space: pre-line;
  overflow-wrap: anywhere;
}

.ca-detail__section-title {
  display: flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.85);
  font-size: 0.95rem;
  font-weight: 700;
  gap: 0.5rem;
  margin-block: 1.5rem 0.75rem;
}

.ca-detail__section-title .v-icon {
  color: rgba(var(--v-theme-primary), 0.8);
}

.ca-detail__table {
  overflow-x: auto;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
}

/* Bukti yang menempel pada satu baris rincian. */
.ca-detail__line-file {
  display: flex;
  align-items: center;
  color: inherit;
  gap: 0.375rem;
  min-inline-size: 0;
  text-decoration: none;
}

.ca-detail__line-file:hover .ca-detail__line-file-name {
  text-decoration: underline;
}

.ca-detail__line-file-name {
  overflow: hidden;
  font-size: 0.8rem;
  max-inline-size: 9rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ca-detail__table :deep(thead th) {
  background: rgba(var(--v-theme-on-surface), 0.04);
  font-size: 0.7rem !important;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.ca-detail__table :deep(tbody tr:nth-child(even)) {
  background: rgba(var(--v-theme-on-surface), 0.02);
}

.ca-detail__table :deep(tfoot .ca-detail__table-total td) {
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgba(var(--v-theme-primary), 0.08);
  font-size: 0.95rem;
  font-weight: 700;
}

.ca-detail__timeline {
  justify-content: start;
  padding-block-start: 0.25rem;
}

.ca-detail__quote {
  border-inline-start: 3px solid rgba(var(--v-theme-primary), 0.35);
  background: rgba(var(--v-theme-on-surface), 0.04);
  font-size: 0.85rem;
  padding-block: 0.375rem;
  padding-inline: 0.625rem;
  white-space: pre-line;
}

.ca-detail__files {
  display: grid;
  gap: 0.625rem;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
}

.ca-detail__file {
  display: flex;
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  color: inherit;
  gap: 0.75rem;
  padding-block: 0.625rem;
  padding-inline: 0.875rem;
  text-decoration: none;
  transition: border-color 0.2s ease, background 0.2s ease;
}

.ca-detail__file:hover {
  border-color: rgba(var(--v-theme-primary), 0.5);
  background: rgba(var(--v-theme-primary), 0.06);
}

.ca-detail__file-body {
  flex: 1;
  min-inline-size: 0;
}

.ca-detail__file-name {
  overflow: hidden;
  font-size: 0.85rem;
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ca-signature-canvas {
  block-size: 100%;
  inline-size: 100%;
  touch-action: none;
}
</style>
