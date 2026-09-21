<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import SignaturePad from 'signature_pad'
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

interface RealizationRow {
  id: number
  public_id: string
  realization_number: string | null
  advance_number: string | null
  date: string | null
  branch: string | null
  department: string | null

  total_advance_amount: number | string | null
  total_realization_amount: number | string | null
  difference_amount: number | string | null

  /** NONE / RETURN / REIMBURSE */
  difference_type: string | null

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

interface RealizationAbilities {
  can_view: boolean
  view_scope: string
  can_create: boolean
  can_update: boolean
  can_submit: boolean
  can_delete: boolean
  can_cancel: boolean
  can_return: boolean
  can_reimburse: boolean
}

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()

const permissionStore = usePermissionStore()
const navigationStore = useNavigationStore()

const isCheckingPermission = ref(true)

const loading = ref(false)
const loadError = ref(false)
const rows = ref<RealizationRow[]>([])

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

const defaultAbilities = (): RealizationAbilities => ({
  can_view: false,
  view_scope: 'NONE',
  can_create: false,
  can_update: false,
  can_submit: false,
  can_delete: false,
  can_cancel: false,
  can_return: false,
  can_reimburse: false,
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

const abilities = ref<RealizationAbilities>(defaultAbilities())

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
  { title: t('cashAdvanceRealization.list.filters.allBranches'), value: '' },
  ...branchOptions.value.map(item => ({
    title: item.nama_cabang,
    value: String(item.id),
  })),
])

const departmentItems = computed(() => [
  { title: t('cashAdvanceRealization.list.filters.allDepartments'), value: null as number | null },
  ...departmentOptions.value.map(item => ({
    title: item.nama,
    value: Number(item.id),
  })),
])

const canView = computed(() => permissionStore.can('cash_advance_realization.view'))
const canCreate = computed(() => permissionStore.can('cash_advance_realization.create'))
const canExport = computed(() => permissionStore.can('cash_advance_realization.export'))
const canUpdate = computed(() => permissionStore.can('cash_advance_realization.update'))
const canDelete = computed(() => permissionStore.can('cash_advance_realization.delete'))
const canCancel = computed(() => permissionStore.can('cash_advance_realization.cancel'))
const canReceive = computed(() => permissionStore.can('cash_advance_realization.receive'))
const canReturn = computed(() => permissionStore.can('cash_advance_realization.return'))
const canReimburse = computed(() => permissionStore.can('cash_advance_realization.reimburse'))

/*
|--------------------------------------------------------------------------
| Dialog aksi
|--------------------------------------------------------------------------
*/
const approveDialog = ref(false)
const approveTarget = ref<RealizationRow | null>(null)
const approveNotes = ref('')
const approveLoading = ref(false)

const rejectDialog = ref(false)
const rejectTarget = ref<RealizationRow | null>(null)
const rejectNotes = ref('')
const rejectError = ref('')
const rejectLoading = ref(false)

const cancelDialog = ref(false)
const cancelTarget = ref<RealizationRow | null>(null)
const cancelNotes = ref('')
const cancelError = ref('')
const cancelLoading = ref(false)

const receiveLoading = ref(false)
const settleLoading = ref(false)

/** Ukuran berkas lampiran pada modal detail; nilainya datang dalam byte. */
const formatAttachmentSize = (size: number | string | null | undefined): string => {
  const bytes = Number(size || 0)

  if (!bytes || Number.isNaN(bytes))
    return '-'

  if (bytes < 1024)
    return `${bytes} B`

  if (bytes < 1024 * 1024)
    return `${(bytes / 1024).toFixed(1)} KB`

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

const detailDialog = ref(false)
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
const pendingRow = ref<RealizationRow | null>(null)

/*
|--------------------------------------------------------------------------
| Filter "butuh aksi"
|--------------------------------------------------------------------------
| Kedua penyelesaian selisih adalah aksi berpermission, jadi pilihannya hanya
| ditawarkan kepada yang berwenang menjalankannya. Bila tak satu pun dipegang,
| selectnya tidak ditampilkan sama sekali.
|--------------------------------------------------------------------------
*/
const pendingActionItems = computed(() => {
  const items = [
    { title: t('cashAdvanceRealization.list.filters.pendingAction.all'), value: '' },
  ]

  if (canReturn.value) {
    items.push({
      title: t('cashAdvanceRealization.list.filters.pendingAction.return'),
      value: 'RETURN',
    })
  }

  if (canReimburse.value) {
    items.push({
      title: t('cashAdvanceRealization.list.filters.pendingAction.reimburse'),
      value: 'REIMBURSE',
    })
  }

  if (canReceive.value) {
    items.splice(1, 0, {
      title: t('cashAdvanceRealization.list.filters.pendingAction.notReceived'),
      value: 'NOT_RECEIVED',
    })
  }

  return items
})

/** Hanya pilihan "semua" berarti tidak ada yang bisa disaring. */
const showPendingActionFilter = computed(() => pendingActionItems.value.length > 1)

const statusItems = computed(() => [
  { title: t('cashAdvanceRealization.status.all'), value: '' },
  { title: t('cashAdvanceRealization.status.draft'), value: 'DRAFT' },
  { title: t('cashAdvanceRealization.status.inProgress'), value: 'IN PROGRESS' },
  { title: t('cashAdvanceRealization.status.approved'), value: 'APPROVED' },
  { title: t('cashAdvanceRealization.status.rejected'), value: 'REJECTED' },
  { title: t('cashAdvanceRealization.status.cancelled'), value: 'CANCELLED' },
  { title: t('cashAdvanceRealization.status.received'), value: 'RECEIVED' },
  { title: t('cashAdvanceRealization.status.settled'), value: 'SETTLED' },
])

const formatStatus = (status: string | null | undefined): string => {
  const normalized = String(status || '').trim().toUpperCase()

  const map: Record<string, string> = {
    'DRAFT': t('cashAdvanceRealization.status.draft'),
    'IN PROGRESS': t('cashAdvanceRealization.status.inProgress'),
    'APPROVED': t('cashAdvanceRealization.status.approved'),
    'REJECTED': t('cashAdvanceRealization.status.rejected'),
    'CANCELLED': t('cashAdvanceRealization.status.cancelled'),
    'RECEIVED': t('cashAdvanceRealization.status.received'),
    'SETTLED': t('cashAdvanceRealization.status.settled'),
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
    'SETTLED': 'info',
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
const showScheduledPaymentFilter = computed<boolean>(() => canReimburse.value)

const canPayTodayRow = (row: RealizationRow): boolean => row.can_pay_today !== false

const isStatus = (row: RealizationRow, status: string): boolean =>
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
const bulkMode = ref<'RECEIVE' | 'RETURN' | 'REIMBURSE' | null>(null)
const bulkLoading = ref(false)

const fetchRealizations = async (): Promise<void> => {
  loading.value = true
  loadError.value = false

  try {
    const response = await axios.get('/fund-request/cash-advance-realization', {
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

    console.error('[Realisasi FPU] FETCH ERROR:', error)

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.list.toast.loadFailed')),
    })

    rows.value = []
    totalData.value = 0
    totalPage.value = 1
  }
  finally {
    loading.value = false
  }
}

usePolling(fetchRealizations, { interval: 30000 })

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
    console.error('[cashAdvanceRealization] FILTER OPTIONS ERROR:', error)

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

  await fetchRealizations()
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
      await submitRealization(pendingRow.value)

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
  router.push('/fund_request/cash_advance_realization/create')
}

const goToEdit = (publicId: string): void => {
  router.push(`/fund_request/cash_advance_realization/edit?id=${publicId}`)
}

/*
|--------------------------------------------------------------------------
| Export Excel
|--------------------------------------------------------------------------
| Mengirim filter yang sedang aktif supaya isi file sama dengan yang terlihat
| di layar. Tanpa filter, seluruh Realisasi yang boleh dilihat user ikut
| terekspor.
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
      t('cashAdvanceRealization.list.toast.exportLoading'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.get('/fund-request/cash-advance-realization/export-excel', {
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
      : 'Realisasi_FPU.xlsx'

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
      text: t('cashAdvanceRealization.list.toast.exportSuccess'),
    })
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: t('cashAdvanceRealization.list.toast.exportFailed'),
    })

    console.error('[Realisasi FPU] EXPORT ERROR:', error)
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

const canPrint = (row: RealizationRow): boolean =>
  isStatus(row, 'APPROVED') || isStatus(row, 'RECEIVED') || isStatus(row, 'SETTLED')

const printDocument = async (row: RealizationRow): Promise<void> => {
  if (!row.public_id || printLoadingId.value)
    return

  printLoadingId.value = row.public_id

  const loadingTitle = t('cashAdvanceRealization.list.print.loading')
  const loadingText = t('common.alert.pleaseWait')

  let printWindow: Window | null = null

  try {
    showLoadingAlert(loadingTitle, loadingText)

    printWindow = window.open('', '_blank')

    if (!printWindow)
      throw new Error(t('cashAdvanceRealization.list.print.popupBlocked'))

    printWindow.document.open()
    printWindow.document.write(buildPrintLoadingPage(loadingTitle, loadingText))
    printWindow.document.close()

    /*
     * Hanya meminta signed URL. Berkas PDF-nya sendiri diunduh browser saat
     * navigasi di bawah, jadi Axios tidak ikut menunggu render selesai.
     */
    const response = await axios.post(
      `/fund-request/cash-advance-realization/${encodeURIComponent(row.public_id)}/print-url`,
      null,
      { headers: { Accept: 'application/json' } },
    )

    const printUrl = response.data?.url

    if (response.data?.success === false || typeof printUrl !== 'string' || !printUrl.trim())
      throw new Error(response.data?.message || t('cashAdvanceRealization.list.print.urlFailed'))

    if (printWindow.closed)
      throw new Error(t('cashAdvanceRealization.list.print.windowClosed'))

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
        : getApiErrorMessage(error, t('cashAdvanceRealization.list.print.failed')),
    })
  }
  finally {
    printLoadingId.value = null
  }
}

const submitRealization = async (row: RealizationRow): Promise<void> => {
  if (!row?.public_id)
    return

  const confirm = await showConfirmAlert({
    title: t('cashAdvanceRealization.list.toast.submitConfirmTitle'),
    text: t('cashAdvanceRealization.list.toast.submitConfirmText', { nomor: row.realization_number }),
    confirmButtonText: t('cashAdvanceRealization.list.toast.submitConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed)
    return

  try {
    showLoadingAlert(
      t('cashAdvanceRealization.list.toast.submitLoading'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/cash-advance-realization/${row.public_id}/submit`,
      {},
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvanceRealization.list.toast.submitSuccessFallback'),
    })

    await fetchRealizations()
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.list.toast.submitFailedFallback')),
    })
  }
}

const openSubmit = async (row: RealizationRow): Promise<void> => {
  pendingAction.value = 'submit'
  pendingRow.value = row

  try {
    if (!await checkUserSignature()) {
      await openSignatureDialog()

      return
    }

    await submitRealization(row)
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, 'Gagal memeriksa tanda tangan digital.'),
    })
  }
}

const showApproveDialog = (row: RealizationRow): void => {
  approveTarget.value = row
  approveNotes.value = ''
  approveDialog.value = true
}

const openApprove = async (row: RealizationRow): Promise<void> => {
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

const approveRealization = async (): Promise<void> => {
  if (!approveTarget.value?.public_id || approveLoading.value)
    return

  approveLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/cash-advance-realization/${approveTarget.value.public_id}/approve`,
      { notes: approveNotes.value || null },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    approveDialog.value = false
    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvanceRealization.list.approve.successFallback'),
    })

    await fetchRealizations()
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.list.approve.failedFallback')),
    })
  }
  finally {
    approveLoading.value = false
  }
}

const openReject = (row: RealizationRow): void => {
  rejectTarget.value = row
  rejectNotes.value = ''
  rejectError.value = ''
  rejectDialog.value = true
}

const rejectRealization = async (): Promise<void> => {
  if (!rejectTarget.value?.public_id || rejectLoading.value)
    return

  if (!rejectNotes.value.trim()) {
    rejectError.value = t('cashAdvanceRealization.list.reject.notesRequired')

    return
  }

  rejectLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/cash-advance-realization/${rejectTarget.value.public_id}/reject`,
      { notes: rejectNotes.value },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    rejectDialog.value = false
    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvanceRealization.list.reject.successFallback'),
    })

    await fetchRealizations()
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.list.reject.failedFallback')),
    })
  }
  finally {
    rejectLoading.value = false
  }
}

const openCancel = (row: RealizationRow): void => {
  cancelTarget.value = row
  cancelNotes.value = ''
  cancelError.value = ''
  cancelDialog.value = true
}

const cancelRealization = async (): Promise<void> => {
  if (!cancelTarget.value?.public_id || cancelLoading.value)
    return

  if (!cancelNotes.value.trim()) {
    cancelError.value = t('cashAdvanceRealization.list.cancel.notesRequired')

    return
  }

  cancelLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/cash-advance-realization/${cancelTarget.value.public_id}/cancel`,
      { notes: cancelNotes.value },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    cancelDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvanceRealization.list.cancel.successFallback'),
    })

    await fetchRealizations()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.list.cancel.failedFallback')),
    })
  }
  finally {
    cancelLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Penyelesaian selisih
|--------------------------------------------------------------------------
| Dua peristiwa berbeda dengan bentuk formulir yang sama, jadi memakai satu
| dialog yang menyesuaikan diri:
|
| - RETURN    : pemohon mengembalikan sisa dana. Bukti WAJIB.
| - REIMBURSE : Finance membayar kekurangan. Bukti opsional.
|
| Realisasi yang nominalnya pas tidak punya aksi apa pun -- tidak ada uang
| yang berpindah, jadi tidak ada yang perlu dicatat.
|--------------------------------------------------------------------------
*/
/**
 * Selisih baris ini yang masih menunggu perpindahan uang.
 *
 * Sengaja tidak melihat permission: ini keterangan tentang keadaan dokumen,
 * bukan tentang apa yang boleh dilakukan pembacanya. Pemohon perlu tahu
 * uangnya ditunggu meskipun yang menekan tombolnya nanti orang lain.
 */
/*
|--------------------------------------------------------------------------
| Tahap penerimaan
|--------------------------------------------------------------------------
| Disetujui belum berarti uangnya diproses: berkasnya harus dinyatakan
| diterima lebih dulu. Dua meja yang berbeda, sama seperti FPU.
|--------------------------------------------------------------------------
*/
const needsReceipt = (row: RealizationRow): boolean =>
  isStatus(row, 'APPROVED')

const differenceActionState = (row: RealizationRow): {
  mode: 'RETURN' | 'REIMBURSE'
  color: string
  icon: string
  label: string
} | null => {
  if (!isStatus(row, 'RECEIVED'))
    return null

  const type = String(row.difference_type || '').trim().toUpperCase()

  if (type === 'RETURN') {
    return {
      mode: 'RETURN',
      color: 'success',
      icon: 'tabler-arrow-back-up',
      label: t('cashAdvanceRealization.list.pending.return'),
    }
  }

  if (type === 'REIMBURSE') {
    return {
      mode: 'REIMBURSE',
      color: 'warning',
      icon: 'tabler-cash-banknote',
      label: t('cashAdvanceRealization.list.pending.reimburse'),
    }
  }

  return null
}

/** Menu aksinya baru ditawarkan bila user memang berwenang menjalankannya. */
const pendingDifferenceMode = (row: RealizationRow): 'RETURN' | 'REIMBURSE' | null => {
  const state = differenceActionState(row)

  if (!state)
    return null

  if (state.mode === 'RETURN' && abilities.value.can_return)
    return 'RETURN'

  if (state.mode === 'REIMBURSE' && abilities.value.can_reimburse)
    return 'REIMBURSE'

  return null
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
const canBulkSelect = computed<boolean>(() =>
  canReceive.value || abilities.value.can_return || abilities.value.can_reimburse)

/**
 * Tindakan massal yang dibutuhkan satu baris, bila ada.
 *
 * Diturunkan dari penentu yang sudah dipakai menu aksi satuan, jadi keduanya
 * tidak mungkin berbeda pendapat tentang baris mana yang boleh diproses.
 */
const rowBulkAction = (row: RealizationRow): 'RECEIVE' | 'RETURN' | 'REIMBURSE' | null => {
  if (canReceive.value && needsReceipt(row))
    return 'RECEIVE'

  /* Hari ini bukan harinya berarti baris itu tidak bisa diproses sama sekali. */
  return canPayTodayRow(row) ? pendingDifferenceMode(row) : null
}

const isSelected = (row: RealizationRow): boolean =>
  selectedIds.value.includes(String(row.public_id))

/** Baris boleh dicentang bila tindakannya sama dengan yang sedang berjalan. */
const isSelectable = (row: RealizationRow): boolean => {
  const aksi = rowBulkAction(row)

  if (aksi === null)
    return false

  return bulkMode.value === null || bulkMode.value === aksi
}

const selectableRows = computed<RealizationRow[]>(() =>
  rows.value.filter(row => isSelectable(row)))

const allSelected = computed<boolean>(() =>
  selectableRows.value.length > 0
  && selectableRows.value.every(row => isSelected(row)))

const someSelected = computed<boolean>(() =>
  selectedIds.value.length > 0 && !allSelected.value)

const toggleRow = (row: RealizationRow): void => {
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
  `cashAdvanceRealization.list.bulk.${bulkMode.value ?? 'RECEIVE'}`)

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

  const endpoint = {
    RECEIVE: '/fund-request/cash-advance-realization/bulk-receive',
    RETURN: '/fund-request/cash-advance-realization/bulk-return-difference',
    REIMBURSE: '/fund-request/cash-advance-realization/bulk-reimburse-difference',
  }[bulkMode.value]

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
        title: t('cashAdvanceRealization.list.bulk.partialTitle'),
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

    await fetchRealizations()
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
| Tindakan ini hanya menandai bahwa dananya sudah berpindah. Tidak ada yang
| perlu diketik maupun diunggah, jadi cukup satu pertanyaan penegasan --
| dijawab ya, statusnya langsung diperbarui.
|
| Kolom catatan dan lampiran di database sengaja dibiarkan. Endpoint-nya pun
| masih menerima keduanya; layar ini saja yang tidak lagi mengirimnya.
|--------------------------------------------------------------------------
*/
/*
| Penegasan saja, tanpa formulir. Tidak ada yang perlu diketik maupun
| diunggah -- kolom catatan dan lampirannya tetap ada di database, layar ini
| saja yang tidak mengirimnya.
*/
const openReceive = async (row: RealizationRow): Promise<void> => {
  if (!row?.public_id || receiveLoading.value)
    return

  const konfirmasi = await showConfirmAlert({
    title: t('cashAdvanceRealization.list.receive.confirmTitle'),
    text: t('cashAdvanceRealization.list.receive.confirmText', { nomor: row.realization_number || '-' }),
    confirmButtonText: t('cashAdvanceRealization.list.receive.confirmButton'),
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
      `/fund-request/cash-advance-realization/${row.public_id}/receive`,
      {},
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvanceRealization.list.receive.successFallback'),
    })

    await fetchRealizations()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.list.receive.failedFallback')),
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

const paymentDeviation = (row: RealizationRow): 'EARLY' | 'LATE' | null => {
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
  row: RealizationRow,
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
    ns: 'cashAdvanceRealization',
    title: judul,
    message: pesan,
    confirmText: tombol,
    deviation: simpangan,
    scheduledDate: String(row.scheduled_payment_date ?? ''),
  })

  return { lanjut: hasil?.confirmed === true, catatan: hasil?.notes ?? '' }
}

const openSettle = async (
  row: RealizationRow,
  mode: 'RETURN' | 'REIMBURSE',
): Promise<void> => {
  if (!row?.public_id || settleLoading.value)
    return

  /*
  | Dua tindakan yang berbeda: pemohon mengembalikan sisa, atau perusahaan
  | membayar kekurangan. Endpoint dan teksnya mengikuti mode yang dipilih.
  */
  const ns = mode === 'RETURN'
    ? 'cashAdvanceRealization.list.returnDifference'
    : 'cashAdvanceRealization.list.reimburseDifference'

  const endpoint = mode === 'RETURN'
    ? 'return-difference'
    : 'reimburse-difference'

  const { lanjut, catatan } = await askPaymentConfirmation(
    row,
    t(`${ns}.confirmTitle`),
    t(`${ns}.confirmText`, {
      nomor: row.realization_number || '-',
      nominal: `Rp ${formatNumberWithoutRp(Math.abs(Number(row.difference_amount || 0)))}`,
    }),
    t(`${ns}.confirmButton`),
  )

  if (!lanjut)
    return

  settleLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    /*
     * PATCH biasa, bukan multipart: tanpa berkas, method spoofing tidak
     * diperlukan lagi.
     *
     * settlement_amount tetap dikirim karena server mewajibkannya. Nilainya
     * diturunkan dari selisih dokumen -- pembayaran sebagian memang tidak
     * dapat dicatat, jadi tidak ada yang perlu diketik user.
     */
    const response = await axios.patch(
      `/fund-request/cash-advance-realization/${row.public_id}/${endpoint}`,
      {
        settlement_amount: Math.round(Math.abs(Number(row.difference_amount || 0)) * 100) / 100,
        notes: catatan,
      },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t(`${ns}.successFallback`),
    })

    await fetchRealizations()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t(`${ns}.failedFallback`)),
    })
  }
  finally {
    settleLoading.value = false
  }
}

const openDelete = async (row: RealizationRow): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: t('cashAdvanceRealization.list.toast.deleteConfirmTitle'),
    text: t('cashAdvanceRealization.list.toast.deleteConfirmText', { nomor: row.realization_number }),
    confirmButtonText: t('common.alert.deleteConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed)
    return

  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    const response = await axios.delete(
      `/fund-request/cash-advance-realization/${row.public_id}`,
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvanceRealization.list.toast.deleteSuccess'),
    })

    await fetchRealizations()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.list.toast.deleteFailed')),
    })
  }
}

const openDetail = async (publicId: string): Promise<void> => {
  detailDialog.value = true
  detailLoading.value = true
  detailError.value = ''
  detail.value = null

  try {
    const response = await axios.get(`/fund-request/cash-advance-realization/${publicId}`, {
      headers: { Accept: 'application/json' },
    })

    detail.value = response.data?.data ?? null

    if (!detail.value)
      detailError.value = t('cashAdvanceRealization.detail.loadFailed')
  }
  catch (error: unknown) {
    detailError.value = getApiErrorMessage(error, t('cashAdvanceRealization.detail.loadFailed'))
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

/**
 * Warna aksen kepala modal, mengikuti status dokumen.
 *
 * Dipakai sebagai kelas, bukan style inline, supaya gradasinya tetap mengikuti
 * palet tema saat mode gelap dinyalakan.
 */
const detailAccent = computed<string>(() => getStatusColor(detail.value?.status))

/** Label dan warna selisih dokumen yang sedang dibuka. */
const detailDifference = computed<{ label: string; color: string; icon: string }>(() => {
  const type = String(detail.value?.difference_type || '').trim().toUpperCase()

  if (type === 'RETURN') {
    return {
      label: t('cashAdvanceRealization.difference.return'),
      color: 'success',
      icon: 'tabler-arrow-back-up',
    }
  }

  if (type === 'REIMBURSE') {
    return {
      label: t('cashAdvanceRealization.difference.reimburse'),
      color: 'warning',
      icon: 'tabler-arrow-forward-up',
    }
  }

  return {
    label: t('cashAdvanceRealization.difference.none'),
    color: 'secondary',
    icon: 'tabler-equal',
  }
})

/**
 * Selisih yang masih menunggu perpindahan uang.
 *
 * Hanya berlaku selama dokumen berstatus approved; begitu selisihnya
 * diselesaikan statusnya menjadi settled dan penanda ini hilang dengan
 * sendirinya.
 */
const detailPendingDifference = computed<{ type: 'success' | 'warning'; message: string } | null>(() => {
  const source = detail.value

  if (!source || String(source.status || '').trim().toUpperCase() !== 'APPROVED')
    return null

  const type = String(source.difference_type || '').trim().toUpperCase()

  if (type === 'RETURN') {
    return {
      type: 'success',
      message: t('cashAdvanceRealization.detail.pendingReturn'),
    }
  }

  if (type === 'REIMBURSE') {
    return {
      type: 'warning',
      message: t('cashAdvanceRealization.detail.pendingReimburse'),
    }
  }

  return null
})

/** Ringkasan penutup dokumen: ditolak, dibatalkan, atau selisih diselesaikan. */
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
    ON_TRACK: { color: 'info', icon: 'tabler-calendar-event', label: t('cashAdvanceRealization.detail.paymentOnTrack') },
    OVERDUE: { color: 'error', icon: 'tabler-alert-triangle', label: t('cashAdvanceRealization.detail.paymentOverdue') },
    EARLY: { color: 'success', icon: 'tabler-rocket', label: t('cashAdvanceRealization.detail.paymentEarly') },
    ON_TIME: { color: 'success', icon: 'tabler-circle-check', label: t('cashAdvanceRealization.detail.paymentOnTime') },
    LATE: { color: 'warning', icon: 'tabler-clock-exclamation', label: t('cashAdvanceRealization.detail.paymentLate') },
  }

  const dipakai = tampilan[String(source.payment_timing || '')] ?? tampilan.ON_TRACK

  return {
    date: formatDate(source.scheduled_payment_date),
    ...dipakai,
  }
})

const detailClosingNote = computed<{
  type: 'error' | 'warning' | 'info'
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
      title: t('cashAdvanceRealization.detail.rejectionNotes'),
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
      title: t('cashAdvanceRealization.detail.cancellationNotes'),
      meta: [
        source.cancelled_by_name,
        formatAuditDateTime(source.cancelled_at),
      ].filter(Boolean).join(' — '),
      notes: source.cancellation_notes || null,
    }
  }

  /*
  | Penerimaan mendahului penyelesaian, jadi diperiksa lebih dulu -- tetapi
  | hanya ditampilkan selama selisihnya belum beres. Setelah diselesaikan,
  | jejak penyelesaianlah yang lebih menjelaskan keadaan dokumennya.
  */
  if (source.received_at && !source.settled_at) {
    return {
      type: 'info',
      title: t('cashAdvanceRealization.detail.receivedAt'),
      meta: [
        source.received_by_name,
        formatAuditDateTime(source.received_at),
      ].filter(Boolean).join(' — '),
      notes: source.receipt_notes || null,
    }
  }

  if (source.settled_at) {
    return {
      type: 'info',
      title: t('cashAdvanceRealization.detail.settledAt'),
      meta: [
        source.settled_by_name,
        formatAuditDateTime(source.settled_at),
      ].filter(Boolean).join(' — '),
      notes: source.settlement_notes || null,
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
  await fetchRealizations()
})

watch(rowPerPage, async () => {
  currentPage.value = 1
  await fetchRealizations()
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
    await fetchRealizations()
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
      text: t('cashAdvanceRealization.list.filters.endDate'),
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

  await fetchRealizations()

  /* Scope baru diketahui dari respons daftar, jadi pilihannya menyusul. */
  await fetchFilterOptions()

  window.addEventListener('resize', resizeSignatureCanvas)

  const success = route.query.success

  if (success) {
    await router.replace({ path: '/fund_request/cash_advance_realization', query: {} })

    setTimeout(() => {
      if (success === 'created') {
        showSuccessToast({
          title: t('common.alert.success'),
          text: t('cashAdvanceRealization.list.toast.createdSuccess'),
        })
      }

      if (success === 'updated') {
        showSuccessToast({
          title: t('common.alert.success'),
          text: t('cashAdvanceRealization.list.toast.updatedSuccess'),
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
                {{ t('cashAdvanceRealization.list.filtersTitle') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mt-1">
                {{ t('cashAdvanceRealization.list.filtersSubtitle') }}
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
            {{ t('cashAdvanceRealization.list.filters.resetButton') }}
          </VBtn>
        </div>

        <VRow>
          <VCol
            cols="12"
            md="4"
          >
            <VTextField
              v-model="searchQuery"
              :label="t('cashAdvanceRealization.list.filters.searchLabel')"
              :placeholder="t('cashAdvanceRealization.list.filters.searchPlaceholder')"
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
              :label="t('cashAdvanceRealization.list.filters.scheduledPaymentDate')"
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
              :label="t('cashAdvanceRealization.list.filters.startDate')"
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
              :label="t('cashAdvanceRealization.list.filters.endDate')"
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
              :label="t('cashAdvanceRealization.list.filters.statusLabel')"
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
              :label="t('cashAdvanceRealization.list.filters.branchLabel')"
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
              :label="t('cashAdvanceRealization.list.filters.departmentLabel')"
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
            Kedua penyelesaian selisih adalah aksi berpermission, jadi filternya
            hanya ada bagi yang berwenang menjalankannya.
          -->
          <VCol
            v-if="showPendingActionFilter"
            cols="12"
            md="4"
          >
            <VSelect
              v-model="selectedPendingAction"
              :label="t('cashAdvanceRealization.list.filters.pendingActionLabel')"
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
                  {{ t('cashAdvanceRealization.list.onlyMyApproval') }}
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
      class="car-bulk-bar mb-4"
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
            {{ t('cashAdvanceRealization.list.bulk.clearButton') }}
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
      {{ t('cashAdvanceRealization.list.paymentDay.blockedNotice', { days: paymentDaysText }) }}
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
          {{ t('cashAdvanceRealization.list.createButton') }}
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
          {{ t('cashAdvanceRealization.list.exportButton') }}
        </VBtn>

        <VSpacer />

        <VChip
          v-if="loading"
          size="small"
          variant="tonal"
        >
          {{ t('cashAdvanceRealization.list.toast.loadingText') }}
        </VChip>

        <VBtn
          v-else-if="loadError"
          size="small"
          color="error"
          variant="tonal"
          prepend-icon="tabler-refresh"
          class="text-none"
          @click="fetchRealizations"
        >
          {{ t('cashAdvanceRealization.list.toast.reloadData') }}
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
              {{ t('cashAdvanceRealization.list.table.no') }}
            </th>
            <th scope="col">
              {{ t('cashAdvanceRealization.list.table.realizationNumber') }}
            </th>
            <th scope="col">
              {{ t('cashAdvanceRealization.list.table.advanceNumber') }}
            </th>
            <th scope="col">
              {{ t('cashAdvanceRealization.list.table.date') }}
            </th>
            <th scope="col">
              {{ t('cashAdvanceRealization.list.table.branch') }}
            </th>
            <th scope="col">
              {{ t('cashAdvanceRealization.list.table.department') }}
            </th>
            <th
              scope="col"
              class="text-end"
            >
              {{ t('cashAdvanceRealization.list.table.totalAdvance') }}
            </th>
            <th
              scope="col"
              class="text-end"
            >
              {{ t('cashAdvanceRealization.list.table.totalRealization') }}
            </th>
            <th
              scope="col"
              class="text-end"
            >
              {{ t('cashAdvanceRealization.list.table.difference') }}
            </th>
            <th scope="col">
              {{ t('cashAdvanceRealization.list.table.status') }}
            </th>
          </tr>
        </thead>

        <tbody>
          <tr
            v-for="(row, index) in rows"
            :key="row.id"
            :class="{
              'ca-row-need-approval': row.can_approve,
              'car-row-need-receipt': needsReceipt(row),
              'car-row-need-return': differenceActionState(row)?.mode === 'RETURN',
              'car-row-need-reimburse': differenceActionState(row)?.mode === 'REIMBURSE',
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
                      <span>{{ row.realization_number || '-' }}</span>

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

                      {{ t('cashAdvanceRealization.list.menu.waitingMyApprovalBadge') }}
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
                      {{ t('cashAdvanceRealization.list.menu.viewDetail') }}
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
                      {{ t('cashAdvanceRealization.list.menu.print') }}
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
                      {{ t('cashAdvanceRealization.list.menu.approve') }}
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
                      {{ t('cashAdvanceRealization.list.menu.reject') }}
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
                      {{ t('cashAdvanceRealization.list.menu.submit') }}
                    </VListItemTitle>
                  </VListItem>

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
                      {{ t('cashAdvanceRealization.list.menu.receive') }}
                    </VListItemTitle>
                  </VListItem>

                  <!--
                    Aksi penyelesaian selisih hanya muncul bila memang ada uang
                    yang harus berpindah, dan labelnya mengikuti arahnya.
                  -->
                  <VListItem
                    v-if="pendingDifferenceMode(row) === 'RETURN'"
                    :disabled="!canPayTodayRow(row)"
                    href="javascript:void(0)"
                    @click="openSettle(row, 'RETURN')"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-arrow-back-up"
                        :size="20"
                        class="me-3 text-success"
                      />
                    </template>

                    <VListItemTitle class="text-success">
                      {{ t('cashAdvanceRealization.list.menu.returnDifference') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="pendingDifferenceMode(row) === 'REIMBURSE'"
                    :disabled="!canPayTodayRow(row)"
                    href="javascript:void(0)"
                    @click="openSettle(row, 'REIMBURSE')"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-cash-banknote"
                        :size="20"
                        class="me-3 text-warning"
                      />
                    </template>

                    <VListItemTitle class="text-warning">
                      {{ t('cashAdvanceRealization.list.menu.reimburseDifference') }}
                    </VListItemTitle>
                  </VListItem>

                  <VListItem
                    v-if="(isStatus(row, 'APPROVED') || isStatus(row, 'RECEIVED')) && canCancel"
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
                      {{ t('cashAdvanceRealization.list.menu.cancel') }}
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
                      {{ t('cashAdvanceRealization.list.menu.edit') }}
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
                      {{ t('cashAdvanceRealization.list.menu.delete') }}
                    </VListItemTitle>
                  </VListItem>
                </VList>
              </VMenu>
            </td>

            <td class="text-medium-emphasis">
              {{ row.advance_number || '-' }}
            </td>

            <td>{{ formatDate(row.date) || '-' }}</td>
            <td>{{ row.branch || '-' }}</td>
            <td>{{ row.department || '-' }}</td>

            <td class="text-end text-medium-emphasis">
              Rp {{ formatNumberWithoutRp(Number(row.total_advance_amount || 0)) }}
            </td>

            <td class="text-end">
              Rp {{ formatNumberWithoutRp(Number(row.total_realization_amount || 0)) }}
            </td>

            <td class="text-end">
              <div class="d-flex flex-column align-end gap-1">
                <span class="font-weight-medium">
                  Rp {{ formatNumberWithoutRp(Math.abs(Number(row.difference_amount || 0))) }}
                </span>

                <!--
                  Penanda arah selisih; tidak muncul bila realisasi pas dengan
                  nilai yang dicairkan.
                -->
                <VChip
                  v-if="row.difference_type && row.difference_type !== 'NONE'"
                  size="x-small"
                  variant="tonal"
                  :color="row.difference_type === 'RETURN' ? 'success' : 'warning'"
                >
                  {{ row.difference_type === 'RETURN'
                    ? t('cashAdvanceRealization.difference.return')
                    : t('cashAdvanceRealization.difference.reimburse') }}
                </VChip>
              </div>
            </td>

            <td>
              <div class="d-flex flex-column align-start gap-1">
                <VChip
                  size="small"
                  :color="getStatusColor(row.status)"
                  variant="tonal"
                >
                  {{ formatStatus(row.status) }}
                </VChip>

                <!--
                  Penanda tindakan yang masih ditunggu. Approved saja belum
                  berarti selesai selama uangnya belum berpindah.
                -->
                <VChip
                  v-if="differenceActionState(row)"
                  size="x-small"
                  variant="flat"
                  :color="differenceActionState(row)!.color"
                  :prepend-icon="differenceActionState(row)!.icon"
                >
                  {{ differenceActionState(row)!.label }}
                </VChip>
              </div>
            </td>
          </tr>

          <tr v-if="!loading && !rows.length">
            <td
              :colspan="canBulkSelect ? 11 : 10"
              class="text-center py-8"
            >
              <div class="text-body-1 font-weight-medium">
                {{ t('cashAdvanceRealization.list.emptyTitle') }}
              </div>

              <div class="text-caption text-medium-emphasis">
                {{ t('cashAdvanceRealization.list.emptySubtitle') }}
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
      <VCard class="car-detail">
        <!--
          Kepala modal: identitas dokumen dan FPU induknya dijadikan satu blok,
          karena realisasi selalu dibaca berpasangan dengan pengajuannya.
        -->
        <div :class="['car-detail__header', `car-detail__header--${detailAccent}`]">
          <div class="d-flex align-center justify-space-between gap-3">
            <div class="car-detail__eyebrow">
              <VIcon
                icon="tabler-file-invoice"
                size="16"
              />
              {{ t('cashAdvanceRealization.detail.title') }}
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
            <div>
              <div class="car-detail__number">
                {{ detail?.realization_number || '—' }}
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
              v-if="detail?.advance_number"
              class="car-detail__source"
            >
              <div class="car-detail__source-label">
                <VIcon
                  icon="tabler-link"
                  size="13"
                />
                {{ t('cashAdvanceRealization.detail.advanceNumber') }}
              </div>

              <div class="car-detail__source-value">
                {{ detail.advance_number }}
              </div>
            </div>
          </div>
        </div>

        <VDivider />

        <VCardText class="car-detail__body">
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
            <!--
              Tiga angka inti dokumen. Selisih diberi warna sesuai jenisnya
              supaya arah uangnya -- kembali atau dibayarkan -- langsung terbaca.
            -->
            <div class="car-detail__money">
              <div class="car-detail__money-tile">
                <div class="car-detail__label">
                  {{ t('cashAdvanceRealization.detail.totalAdvance') }}
                </div>

                <div class="car-detail__money-value">
                  Rp {{ formatNumberWithoutRp(Number(detail.total_advance_amount || 0)) }}
                </div>
              </div>

              <VIcon
                icon="tabler-arrow-right"
                size="18"
                class="car-detail__money-arrow"
              />

              <div class="car-detail__money-tile">
                <div class="car-detail__label">
                  {{ t('cashAdvanceRealization.detail.totalRealization') }}
                </div>

                <div class="car-detail__money-value">
                  Rp {{ formatNumberWithoutRp(Number(detail.total_realization_amount || 0)) }}
                </div>
              </div>

              <div :class="['car-detail__money-tile', `car-detail__money-tile--${detailDifference.color}`]">
                <div class="car-detail__label d-flex align-center gap-1">
                  <VIcon
                    :icon="detailDifference.icon"
                    size="14"
                  />
                  {{ t('cashAdvanceRealization.detail.difference') }}
                </div>

                <div :class="['car-detail__money-value', `text-${detailDifference.color}`]">
                  Rp {{ formatNumberWithoutRp(Math.abs(Number(detail.difference_amount || 0))) }}
                </div>

                <div class="car-detail__meta">
                  {{ detailDifference.label }}
                </div>
              </div>
            </div>

            <!--
              Penanda selisih yang belum diselesaikan. Tanpa ini, dokumen yang
              sudah approved tapi uangnya belum berpindah terlihat seperti sudah
              beres padahal masih menyisakan kewajiban.
            -->
            <VAlert
              v-if="detailPendingDifference"
              :type="detailPendingDifference.type"
              variant="tonal"
              density="compact"
              class="mt-3"
            >
              {{ detailPendingDifference.message }}
            </VAlert>

            <!-- Ringkasan -->
            <div class="car-detail__grid mt-4">
              <div class="car-detail__field">
                <VIcon
                  icon="tabler-calendar-event"
                  size="18"
                />

                <div class="car-detail__field-body">
                  <div class="car-detail__label">
                    {{ t('cashAdvanceRealization.detail.date') }}
                  </div>

                  <div class="car-detail__value">
                    {{ formatDate(detail.date) || '-' }}
                  </div>
                </div>
              </div>

              <div class="car-detail__field">
                <VIcon
                  icon="tabler-building-store"
                  size="18"
                />

                <div class="car-detail__field-body">
                  <div class="car-detail__label">
                    {{ t('cashAdvanceRealization.detail.branch') }}
                  </div>

                  <div class="car-detail__value">
                    {{ detail.branch || '-' }}
                  </div>
                </div>
              </div>

              <div class="car-detail__field">
                <VIcon
                  icon="tabler-users-group"
                  size="18"
                />

                <div class="car-detail__field-body">
                  <div class="car-detail__label">
                    {{ t('cashAdvanceRealization.detail.department') }}
                  </div>

                  <div class="car-detail__value">
                    {{ detail.department_name || detail.department || '-' }}
                  </div>
                </div>
              </div>

              <div class="car-detail__field">
                <VIcon
                  icon="tabler-user-plus"
                  size="18"
                />

                <div class="car-detail__field-body">
                  <div class="car-detail__label">
                    {{ t('cashAdvanceRealization.detail.createdBy') }}
                  </div>

                  <div class="car-detail__value">
                    {{ detail.created_by_name || '-' }}
                  </div>

                  <div
                    v-if="detail.created_at"
                    class="car-detail__meta"
                  >
                    {{ formatAuditDateTime(detail.created_at) }}
                  </div>
                </div>
              </div>

              <div class="car-detail__field">
                <VIcon
                  icon="tabler-send"
                  size="18"
                />

                <div class="car-detail__field-body">
                  <div class="car-detail__label">
                    {{ t('cashAdvanceRealization.detail.submittedBy') }}
                  </div>

                  <div class="car-detail__value">
                    {{ detail.submitted_by_name || '-' }}
                  </div>

                  <div
                    v-if="detail.submitted_at"
                    class="car-detail__meta"
                  >
                    {{ formatAuditDateTime(detail.submitted_at) }}
                  </div>
                </div>
              </div>

              <div
                v-if="detail.final_approved_at"
                class="car-detail__field"
              >
                <VIcon
                  icon="tabler-circle-check"
                  size="18"
                />

                <div class="car-detail__field-body">
                  <div class="car-detail__label">
                    {{ t('cashAdvanceRealization.detail.finalApprovedBy') }}
                  </div>

                  <div class="car-detail__value">
                    {{ detail.final_approved_by_name || '-' }}
                  </div>

                  <div class="car-detail__meta">
                    {{ formatAuditDateTime(detail.final_approved_at) }}
                  </div>
                </div>
              </div>
            </div>

            <!-- Perihal dan catatan -->
            <div class="car-detail__note mt-4">
              <div class="car-detail__label">
                {{ t('cashAdvanceRealization.detail.subject') }}
              </div>

              <div class="car-detail__note-text">
                {{ detail.subject || '-' }}
              </div>

              <template v-if="detail.notes">
                <div class="car-detail__label mt-3">
                  {{ t('cashAdvanceRealization.detail.notes') }}
                </div>

                <div class="car-detail__note-text">
                  {{ detail.notes }}
                </div>
              </template>
            </div>

            <!-- Rincian -->
            <div class="car-detail__section-title">
              <VIcon
                icon="tabler-list-details"
                size="18"
              />
              {{ t('cashAdvanceRealization.detail.itemsTitle') }}

              <VChip
                size="x-small"
                variant="tonal"
                class="ms-1"
              >
                {{ (detail.items || []).length }}
              </VChip>
            </div>

            <div class="car-detail__table">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th style="inline-size: 3rem;">
                      {{ t('cashAdvanceRealization.detail.itemNo') }}
                    </th>
                    <th style="inline-size: 7.5rem;">
                      {{ t('cashAdvanceRealization.detail.itemDate') }}
                    </th>
                    <th>{{ t('cashAdvanceRealization.detail.itemDescription') }}</th>
                    <th
                      class="text-end"
                      style="inline-size: 10rem;"
                    >
                      {{ t('cashAdvanceRealization.detail.itemAdvance') }}
                    </th>
                    <th
                      class="text-end"
                      style="inline-size: 10rem;"
                    >
                      {{ t('cashAdvanceRealization.detail.itemRealization') }}
                    </th>
                    <th
                      class="text-end"
                      style="inline-size: 9rem;"
                    >
                      {{ t('cashAdvanceRealization.detail.itemDifference') }}
                    </th>
                    <th style="inline-size: 15rem;">
                      {{ t('cashAdvanceRealization.detail.itemAttachment') }}
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

                      <VChip
                        v-if="!item.cash_advance_item_id"
                        size="x-small"
                        variant="tonal"
                        color="warning"
                        class="ms-1"
                      >
                        {{ t('cashAdvanceRealization.form.items.extra') }}
                      </VChip>
                    </td>

                    <td class="text-end text-medium-emphasis">
                      {{ item.cash_advance_item_id
                        ? `Rp ${formatNumberWithoutRp(Number(item.advance_amount || 0))}`
                        : '—' }}
                    </td>

                    <td class="text-end font-weight-medium">
                      Rp {{ formatNumberWithoutRp(Number(item.realization_amount || 0)) }}
                    </td>

                    <td
                      class="text-end"
                      :class="{
                        'text-success': Number(item.difference_amount || 0) > 0,
                        'text-warning': Number(item.difference_amount || 0) < 0,
                      }"
                    >
                      {{ item.cash_advance_item_id
                        ? `Rp ${formatNumberWithoutRp(Math.abs(Number(item.difference_amount || 0)))}`
                        : '—' }}
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
                          class="car-detail__line-file"
                          :href="attachment.url || undefined"
                          target="_blank"
                          rel="noopener"
                        >
                          <VIcon
                            :icon="getAttachmentIcon(attachment.mime_type)"
                            size="18"
                            color="primary"
                          />

                          <span class="car-detail__line-file-name">
                            {{ attachment.original_filename || attachment.filename }}
                          </span>
                        </a>
                      </div>

                      <span
                        v-else
                        class="car-detail__meta"
                      >—</span>
                    </td>
                  </tr>
                </tbody>

                <tfoot>
                  <tr class="car-detail__table-total">
                    <td colspan="3">
                      {{ t('cashAdvanceRealization.detail.totalLabel') }}
                    </td>

                    <td class="text-end">
                      Rp {{ formatNumberWithoutRp(Number(detail.total_advance_amount || 0)) }}
                    </td>

                    <td class="text-end">
                      Rp {{ formatNumberWithoutRp(Number(detail.total_realization_amount || 0)) }}
                    </td>

                    <td :class="['text-end', `text-${detailDifference.color}`]">
                      Rp {{ formatNumberWithoutRp(Math.abs(Number(detail.difference_amount || 0))) }}
                    </td>

                    <td />
                  </tr>
                </tfoot>
              </VTable>
            </div>

            <!-- Riwayat approval sebagai alur, bukan tabel -->
            <div class="car-detail__section-title">
              <VIcon
                icon="tabler-route"
                size="18"
              />
              {{ t('cashAdvanceRealization.detail.approvalsTitle') }}
            </div>

            <VAlert
              v-if="!(detail.approvals || []).length"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('cashAdvanceRealization.detail.approvalsEmpty') }}
            </VAlert>

            <VTimeline
              v-else
              side="end"
              align="start"
              density="compact"
              truncate-line="both"
              class="car-detail__timeline"
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
                    {{ t('cashAdvanceRealization.detail.approvalStep') }} {{ step.step_order }}
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
                    class="car-detail__meta d-inline-flex align-center gap-1"
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
                  class="car-detail__meta mt-1"
                >
                  {{ step.approval_mode === 'ALL'
                    ? t('cashAdvanceRealization.detail.approvalModeAll', { count: step.approvers.length })
                    : t('cashAdvanceRealization.detail.approvalModeAny', { count: step.approvers.length }) }}
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
                      class="car-detail__meta d-inline-flex align-center gap-1"
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
                    class="car-detail__quote mt-2"
                  >
                    {{ approval.notes }}
                  </div>
                </template>
              </VTimelineItem>
            </VTimeline>

            <!--
              Bukti pengeluaran tingkat dokumen. Sejak bukti dipindah ke tiap
              baris rincian, bagian ini tidak pernah terisi lagi, jadi blok ini
              disembunyikan bila tidak ada isinya.
            -->
            <template v-if="(detail.attachments || []).length">
              <div class="car-detail__section-title">
                <VIcon
                  icon="tabler-paperclip"
                  size="18"
                />
                {{ t('cashAdvanceRealization.detail.attachmentsTitle') }}
              </div>

              <div class="car-detail__files">
                <a
                  v-for="attachment in detail.attachments"
                  :key="`detail-attachment-${attachment.id}`"
                  class="car-detail__file"
                  :href="attachment.url || undefined"
                  target="_blank"
                  rel="noopener"
                >
                  <VIcon
                    :icon="getAttachmentIcon(attachment.mime_type)"
                    size="26"
                    color="primary"
                  />

                  <div class="car-detail__file-body">
                    <div class="car-detail__file-name">
                      {{ attachment.original_filename || attachment.filename }}
                    </div>

                    <div class="car-detail__meta">
                      {{ formatAttachmentSize(attachment.file_size) }}
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
              Bukti penyelesaian dari Finance. Blok ini hanya muncul bila memang
              ada berkasnya -- pengembalian tunai boleh tanpa bukti, jadi bagian
              kosong tidak perlu ditampilkan sebagai kekurangan.
            -->
            <template v-if="(detail.settlement_attachments || []).length">
              <div class="car-detail__section-title">
                <VIcon
                  icon="tabler-receipt"
                  size="18"
                />
                {{ t('cashAdvanceRealization.detail.settlementAttachmentsTitle') }}
              </div>

              <div class="car-detail__files">
                <a
                  v-for="attachment in detail.settlement_attachments"
                  :key="`detail-settlement-attachment-${attachment.id}`"
                  class="car-detail__file"
                  :href="attachment.url || undefined"
                  target="_blank"
                  rel="noopener"
                >
                  <VIcon
                    :icon="getAttachmentIcon(attachment.mime_type)"
                    size="26"
                    color="info"
                  />

                  <div class="car-detail__file-body">
                    <div class="car-detail__file-name">
                      {{ attachment.original_filename || attachment.filename }}
                    </div>

                    <div class="car-detail__meta">
                      {{ formatAttachmentSize(attachment.file_size) }}
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
                  {{ t('cashAdvanceRealization.detail.scheduledPaymentDate') }}: {{ detailPaymentSchedule.date }}
                </span>
              </div>

              <div class="text-body-2 mt-1">
                {{ detailPaymentSchedule.label }}
              </div>
            </VAlert>

            <!-- Penutup dokumen: ditolak, dibatalkan, atau selisih selesai -->
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
                class="car-detail__note-text mt-2"
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
            {{ t('cashAdvanceRealization.detail.approveButton') }}
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
            {{ t('cashAdvanceRealization.detail.rejectButton') }}
          </VBtn>

          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            @click="detailDialog = false"
          >
            {{ t('cashAdvanceRealization.detail.closeButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- DIALOG APPROVE -->
    <VDialog
      v-model="approveDialog"
      max-width="520"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvanceRealization.list.approve.dialogTitle') }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('cashAdvanceRealization.list.approve.dialogSubtitle') }}
          </div>

          <div class="font-weight-medium mb-4">
            {{ approveTarget?.realization_number || '-' }}
          </div>

          <VTextarea
            v-model="approveNotes"
            :label="t('cashAdvanceRealization.list.approve.notesLabel')"
            :placeholder="t('cashAdvanceRealization.list.approve.notesPlaceholder')"
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
            @click="approveRealization"
          >
            {{ t('cashAdvanceRealization.list.approve.confirmButton') }}
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
          {{ t('cashAdvanceRealization.list.reject.dialogTitle') }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('cashAdvanceRealization.list.reject.dialogSubtitle') }}
          </div>

          <div class="font-weight-medium mb-4">
            {{ rejectTarget?.realization_number || '-' }}
          </div>

          <VTextarea
            v-model="rejectNotes"
            :label="t('cashAdvanceRealization.list.reject.notesLabel')"
            :placeholder="t('cashAdvanceRealization.list.reject.notesPlaceholder')"
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
            @click="rejectRealization"
          >
            {{ t('cashAdvanceRealization.list.reject.confirmButton') }}
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
          {{ t('cashAdvanceRealization.list.cancel.dialogTitle') }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('cashAdvanceRealization.list.cancel.dialogSubtitle') }}
          </div>

          <div class="font-weight-medium mb-4">
            {{ cancelTarget?.realization_number || '-' }}
          </div>

          <VTextarea
            v-model="cancelNotes"
            :label="t('cashAdvanceRealization.list.cancel.notesLabel')"
            :placeholder="t('cashAdvanceRealization.list.cancel.notesPlaceholder')"
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
            @click="cancelRealization"
          >
            {{ t('cashAdvanceRealization.list.cancel.confirmButton') }}
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

.ca-subject-col {
  max-inline-size: 20rem;
  white-space: normal;
}

.ca-signature-wrapper {
  border: 1px dashed rgba(var(--v-border-color), 0.5);
  border-radius: 8px;
  block-size: 180px;
}

.ca-signature-canvas {
  block-size: 100%;
  inline-size: 100%;
  touch-action: none;
}

/*
| Baris yang selisihnya belum berpindah. Warnanya sengaja tipis: menandai,
| bukan mengalahkan sorotan baris yang menunggu approval user.
*/
/* Bilah tindakan massal: menonjol tanpa berteriak. */
.car-bulk-bar {
  border-inline-start: 4px solid rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.04);
}

/* Menunggu diterima: sudah disetujui, berkasnya belum dinyatakan sampai. */
.car-row-need-receipt {
  border-inline-start: 3px solid rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.03);
}

.car-row-need-return {
  background: rgba(var(--v-theme-success), 0.06);
}

.car-row-need-reimburse {
  background: rgba(var(--v-theme-warning), 0.06);
}

/* Nominal selisih pada dialog penyelesaian. */
.car-settle__amount {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: 8px;
  font-size: 0.95rem;
  gap: 1.5rem;
  padding-block: 0.5rem;
  padding-inline: 0.875rem;
}

.car-settle__amount--success {
  background: rgba(var(--v-theme-success), 0.1);
  color: rgb(var(--v-theme-success));
}

.car-settle__amount--warning {
  background: rgba(var(--v-theme-warning), 0.1);
  color: rgb(var(--v-theme-warning));
}

/*
|--------------------------------------------------------------------------
| Modal detail
|--------------------------------------------------------------------------
| Sejalan dengan modal detail FPU. Semua warna memakai token tema, bukan nilai
| tetap, supaya mode gelap ikut menyesuaikan tanpa aturan tambahan.
|--------------------------------------------------------------------------
*/

.car-detail__header {
  padding-block: 1.25rem;
  padding-inline: 1.5rem;
}

.car-detail__header--success {
  background: linear-gradient(135deg, rgba(var(--v-theme-success), 0.14), transparent 70%);
}

.car-detail__header--warning {
  background: linear-gradient(135deg, rgba(var(--v-theme-warning), 0.14), transparent 70%);
}

.car-detail__header--error {
  background: linear-gradient(135deg, rgba(var(--v-theme-error), 0.14), transparent 70%);
}

.car-detail__header--info {
  background: linear-gradient(135deg, rgba(var(--v-theme-info), 0.14), transparent 70%);
}

.car-detail__header--secondary {
  background: linear-gradient(135deg, rgba(var(--v-theme-secondary), 0.12), transparent 70%);
}

.car-detail__eyebrow {
  display: inline-flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.75rem;
  font-weight: 600;
  gap: 0.375rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.car-detail__number {
  font-size: 1.375rem;
  font-weight: 700;
  line-height: 1.25;
}

/* Kartu FPU induk pada kepala modal. */
.car-detail__source {
  border: 1px dashed rgba(var(--v-theme-primary), 0.4);
  border-radius: 10px;
  background: rgba(var(--v-theme-surface), 0.7);
  padding-block: 0.5rem;
  padding-inline: 0.875rem;
}

.car-detail__source-label {
  display: inline-flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.7rem;
  gap: 0.25rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.car-detail__source-value {
  color: rgb(var(--v-theme-primary));
  font-size: 0.95rem;
  font-weight: 700;
  white-space: nowrap;
}

.car-detail__body {
  padding-block: 1.25rem 1.5rem;
}

/* Tiga angka inti: pengajuan -> realisasi -> selisih. */
.car-detail__money {
  display: flex;
  align-items: stretch;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.car-detail__money-tile {
  flex: 1 1 12rem;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  padding-block: 0.75rem;
  padding-inline: 1rem;
}

.car-detail__money-tile--success {
  border-color: rgba(var(--v-theme-success), 0.4);
  background: rgba(var(--v-theme-success), 0.06);
}

.car-detail__money-tile--warning {
  border-color: rgba(var(--v-theme-warning), 0.4);
  background: rgba(var(--v-theme-warning), 0.06);
}

.car-detail__money-tile--secondary {
  background: rgba(var(--v-theme-on-surface), 0.03);
}

.car-detail__money-value {
  font-size: 1.05rem;
  font-weight: 700;
  line-height: 1.4;
  white-space: nowrap;
}

.car-detail__money-arrow {
  align-self: center;
  color: rgba(var(--v-theme-on-surface), 0.35);
}

.car-detail__grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(13.5rem, 1fr));
}

.car-detail__field {
  display: flex;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  gap: 0.625rem;
  padding-block: 0.625rem;
  padding-inline: 0.75rem;
}

.car-detail__field .v-icon {
  color: rgba(var(--v-theme-primary), 0.75);
  margin-block-start: 0.125rem;
}

.car-detail__field-body {
  min-inline-size: 0;
}

.car-detail__label {
  color: rgba(var(--v-theme-on-surface), 0.6);
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.car-detail__value {
  font-size: 0.9rem;
  font-weight: 500;
  overflow-wrap: anywhere;
}

.car-detail__meta {
  color: rgba(var(--v-theme-on-surface), 0.55);
  font-size: 0.75rem;
}

.car-detail__note {
  border-radius: 10px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  padding-block: 0.75rem;
  padding-inline: 1rem;
}

.car-detail__note-text {
  font-size: 0.9rem;
  white-space: pre-line;
  overflow-wrap: anywhere;
}

.car-detail__section-title {
  display: flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 0.85);
  font-size: 0.95rem;
  font-weight: 700;
  gap: 0.5rem;
  margin-block: 1.5rem 0.75rem;
}

.car-detail__section-title .v-icon {
  color: rgba(var(--v-theme-primary), 0.8);
}

.car-detail__table {
  overflow-x: auto;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
}

.car-detail__table :deep(thead th) {
  background: rgba(var(--v-theme-on-surface), 0.04);
  font-size: 0.7rem !important;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.car-detail__table :deep(tbody tr:nth-child(even)) {
  background: rgba(var(--v-theme-on-surface), 0.02);
}

.car-detail__table :deep(tfoot .car-detail__table-total td) {
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgba(var(--v-theme-primary), 0.08);
  font-size: 0.9rem;
  font-weight: 700;
}

/* Bukti yang menempel pada satu baris rincian. */
.car-detail__line-file {
  display: flex;
  align-items: center;
  color: inherit;
  gap: 0.375rem;
  min-inline-size: 0;
  text-decoration: none;
}

.car-detail__line-file:hover .car-detail__line-file-name {
  text-decoration: underline;
}

.car-detail__line-file-name {
  overflow: hidden;
  font-size: 0.8rem;
  max-inline-size: 10rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.car-detail__timeline {
  justify-content: start;
  padding-block-start: 0.25rem;
}

.car-detail__quote {
  border-inline-start: 3px solid rgba(var(--v-theme-primary), 0.35);
  background: rgba(var(--v-theme-on-surface), 0.04);
  font-size: 0.85rem;
  padding-block: 0.375rem;
  padding-inline: 0.625rem;
  white-space: pre-line;
}

.car-detail__files {
  display: grid;
  gap: 0.625rem;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
}

.car-detail__file {
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

.car-detail__file:hover {
  border-color: rgba(var(--v-theme-primary), 0.5);
  background: rgba(var(--v-theme-primary), 0.06);
}

.car-detail__file-body {
  flex: 1;
  min-inline-size: 0;
}

.car-detail__file-name {
  overflow: hidden;
  font-size: 0.85rem;
  font-weight: 500;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
