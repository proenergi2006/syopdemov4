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
import RevisionHistory from '@/components/fund_request/RevisionHistory.vue'

interface RealizationRow {

  /*
  | Jumlah lampiran. Dipakai satu hal saja: menentukan perlu tidaknya
  | layar bertanya "gabungkan lampiran ke dalam cetakan?".
  */
  attachment_count?: number

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
  revision_count?: number
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

  /*
  | Pembuka halaman ini adalah pemohon dokumen tersebut. Penentu tombol
  | "Kembalikan Sisa Dana": izinnya saja tidak cukup, sebab uang sisanya
  | hanya dipegang satu orang.
  */
  is_requester?: boolean

  /*
  | Boleh membatalkan dokumen INI. Dihitung server dengan penjaga yang sama
  | dengan endpoint-nya, karena izin batal punya cakupannya sendiri --
  | OWN_DATA, OWN_CABANG, dan seterusnya. Tanpa ini tombolnya muncul di
  | setiap dokumen yang boleh dilihat, termasuk milik orang lain.
  */
  can_cancel?: boolean
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
  can_confirm_return: boolean
  can_reject_return: boolean
  can_finalize: boolean
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
  can_confirm_return: false,
  can_reject_return: false,
  can_finalize: false,
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

/*
| Menarik kembali penerimaan berkas. Dipisah dari receive karena
| bobotnya lain: yang satu menambah dokumen ke antrean pembayaran,
| yang ini mencabut tanggal yang sudah dijanjikan ke pemohon.
*/
const canUnreceive = computed(() => permissionStore.can('cash_advance_realization.unreceive'))

/* Memeriksa lalu meneruskannya ke pembayaran kekurangan. */
const canVerify = computed(() => permissionStore.can('cash_advance_realization.verify'))

const canReturn = computed(() => permissionStore.can('cash_advance_realization.return'))

/*
| Mengonfirmasi uang sisa benar-benar masuk.
|
| REF YANG SAMA dengan canReceive, bukan computed kedua atas permission yang
| sama: dua pembacaan terpisah atas satu izin pada akhirnya akan berselisih.
| Namanya sendiri tetap ada karena yang dijawabnya pertanyaan lain -- meja
| yang menerima berkasnya pula yang menghitung uangnya masuk, dan kalau
| kelak langkah itu punya izinnya sendiri, hanya baris ini yang berubah.
*/
const canConfirmReturn = computed(() => permissionStore.can('cash_advance_realization.confirm_return'))

const canRejectReturn = computed(() => permissionStore.can('cash_advance_realization.reject_return'))

/*
| Menutup realisasi yang nilainya PAS. Tidak ada uang yang berpindah di
| cabang ini, jadi tidak ada izin pembayaran maupun pengembalian yang bisa
| dipakai -- yang tersisa justru pekerjaan yang paling mudah terlupakan.
*/
const canFinalize = computed(() => permissionStore.can('cash_advance_realization.finalize'))
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
const verifyLoading = ref(false)
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

| Rincian perjalanan dinas pada modal detail
|--------------------------------------------------------------------------
| Dikelompokkan menurut kategori, dan kategori yang diurus GA ikut masuk walau
| tanpa baris: kosongnya disengaja, dan itu perlu terbaca. Tanpa ini, dokumen
| yang seluruhnya diurus GA tampak seperti dokumen yang rinciannya belum
| diisi.
|
| Urutan barisnya dibiarkan apa adanya dari server -- itulah urutan yang sama
| dengan yang tercetak di kertas.
*/
/**
 * Membaca satu penanda "diurus GA" dari muatan detailnya.
 *
 * Dua bentuk diterima: { name, notes } yang dikirim sekarang, dan nama
 * saja yang dikirim sebelumnya. Jawaban yang terlanjur tersimpan di cache
 * peramban masih bisa datang dalam bentuk lamanya, dan String() atas
 * sebuah objek menghasilkan "[object Object]" -- terpampang sebagai nama
 * kategori, tanpa satu pun galat yang menunjukkan sebabnya.
 *
 * Dipisahkan dari computed-nya karena ia mengerjakan hal yang lain:
 * menafsirkan bentuk muatan, bukan mengelompokkan baris.
 */
const readArrangedMarker = (satu: unknown): { name: string; notes: string } => {
  if (typeof satu === 'string')
    return { name: satu.trim(), notes: '' }

  const obj = satu as { name?: unknown; notes?: unknown } | null

  return {
    name: String(obj?.name ?? '').trim(),
    notes: String(obj?.notes ?? ''),
  }
}

const detailBreakdown = computed<{
  name: string
  arranged: boolean

  /*
  | Catatan pemohon untuk GA. Hanya ada pada kategori yang diurus GA --
  | di kategori berisi, yang menjelaskan adalah barisnya sendiri.
  */
  notes: string
  rows: any[]
}[]>(() => {
  const barisRincian: any[] = detail.value?.items || []
  const kelompok: { name: string; arranged: boolean; notes: string; rows: any[] }[] = []

  for (const row of barisRincian) {
    const nama = String(row.expense_category_name || '').trim()

    if (!nama)
      continue

    const adaKelompok = kelompok.find(satu => satu.name === nama)

    if (adaKelompok)
      adaKelompok.rows.push(row)
    else
      kelompok.push({ name: nama, arranged: false, notes: '', rows: [row] })
  }

  for (const satu of (detail.value?.arranged_categories || [])) {
    const { name, notes } = readArrangedMarker(satu)

    if (!name)
      continue

    /*
    | Kategori yang sudah punya baris tidak diubah menjadi "diurus GA":
    | server sudah menolak dokumen yang mengatakan dua-duanya, jadi
    | keadaan ini hanya mungkin pada dokumen lama -- dan di sana barisnya
    | yang memegang angkanya, jadi barisnya yang menang.
    */
    const sudahAda = kelompok.some(satuKelompok => satuKelompok.name === name)

    if (sudahAda)
      continue

    kelompok.push({ name, arranged: true, notes, rows: [] })
  }

  return kelompok
})

/*
| Bentuknya dikenali dari isinya, bukan dari penanda terpisah: penanda yang
| mengatakan "ini perdin" sementara rinciannya tidak berkategori akan
| menggambar tabel kosong tanpa sebab yang kelihatan.
*/
const detailHasBreakdown = computed<boolean>(() => detailBreakdown.value.length > 0)

/*
| Himpunan keterangan transaksi FPU sumbernya.
|
| Jatuh ke keterangan tunggalnya bila himpunannya tidak dikirim -- itu
| keadaan dokumen yang dibuat sebelum himpunan ini ada, dan juga keadaan
| jawaban lama yang terlanjur tersimpan di cache peramban.
*/
const detailTransactionCategories = computed<string[]>(() => {
  const himpunan = detail.value?.transaction_categories

  if (Array.isArray(himpunan) && himpunan.length) {
    return himpunan
      .map((satu: any) => String(satu?.name ?? '').trim())
      .filter((nama: string) => nama !== '')
  }

  const tunggal = String(detail.value?.transaction_category ?? '').trim()

  return tunggal !== '' ? [tunggal] : []
})

/*
| Kolom keterangan transaksi per baris hanya berarti bila himpunannya lebih
| dari satu. Pada dokumen berketerangan tunggal ia mengulang apa yang sudah
| tertulis di kepala modalnya, sebanyak jumlah barisnya.
*/
const detailShowsLineCategory = computed<boolean>(() =>
  detailTransactionCategories.value.length > 1)

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

  /*
  | Antrean konfirmasi: sudah dinyatakan pemohon, menunggu Finance
  | menghitung uangnya masuk. Milik pemegang izin PENERIMAAN.
  */
  if (canConfirmReturn.value) {
    items.push({
      title: t('cashAdvanceRealization.list.filters.pendingAction.confirmReturn'),
      value: 'CONFIRM_RETURN',
    })
  }

  /*
  | Antrean approve: nilainya pas, berkasnya sedang diperiksa, menunggu
  | dinyatakan benar lalu ditutup.
  */
  if (canFinalize.value) {
    items.push({
      title: t('cashAdvanceRealization.list.filters.pendingAction.finalize'),
      value: 'FINALIZE',
    })
  }

  /*
  | Antrean pemeriksaan: yang berkasnya sudah diterima dan kekurangannya
  | perlu dibayarkan, menunggu diperiksa sebelum berpindah meja.
  */
  if (canVerify.value) {
    items.push({
      title: t('cashAdvanceRealization.list.filters.pendingAction.verify'),
      value: 'VERIFY',
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
  { title: t('cashAdvanceRealization.status.needRevision'), value: 'NEED REVISION' },
  { title: t('cashAdvanceRealization.status.cancelled'), value: 'CANCELLED' },
  { title: t('cashAdvanceRealization.status.received'), value: 'RECEIVED' },
  { title: t('cashAdvanceRealization.status.onChecking'), value: 'ON CHECKING' },
  { title: t('cashAdvanceRealization.status.verified'), value: 'VERIFIED' },
  { title: t('cashAdvanceRealization.status.returnDeclared'), value: 'RETURN DECLARED' },
  { title: t('cashAdvanceRealization.status.settled'), value: 'SETTLED' },
])

const formatStatus = (status: string | null | undefined): string => {
  const normalized = String(status || '').trim().toUpperCase()

  const map: Record<string, string> = {
    'DRAFT': t('cashAdvanceRealization.status.draft'),
    'IN PROGRESS': t('cashAdvanceRealization.status.inProgress'),
    'APPROVED': t('cashAdvanceRealization.status.approved'),
    'REJECTED': t('cashAdvanceRealization.status.rejected'),
    'NEED REVISION': t('cashAdvanceRealization.status.needRevision'),
    'CANCELLED': t('cashAdvanceRealization.status.cancelled'),
    'RECEIVED': t('cashAdvanceRealization.status.received'),
    'ON CHECKING': t('cashAdvanceRealization.status.onChecking'),
    'VERIFIED': t('cashAdvanceRealization.status.verified'),
    'RETURN DECLARED': t('cashAdvanceRealization.status.returnDeclared'),
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
    'NEED REVISION': 'warning',
    'CANCELLED': 'error',
    'RECEIVED': 'primary',

    /*
    | Berkasnya sedang diperiksa pada realisasi yang nilainya pas. Warnanya
    | dibedakan dari RECEIVED: dua antrean yang dikerjakan lewat tombol
    | berbeda tidak boleh terbaca sama dalam satu daftar.
    */
    'ON CHECKING': 'info',

    /*
    | Sudah diperiksa, menunggu dibayar -- warnanya dibedakan dari RECEIVED
    | supaya dua antrean yang dikerjakan orang berbeda tidak terbaca sama
    | dalam satu daftar.
    */
    'VERIFIED': 'warning',

    /*
    | Pemohon sudah menyatakan uangnya kembali, Finance belum
    | mengonfirmasi. Warnanya sendiri, bukan warna SETTLED: yang belum
    | dihitung siapa pun tidak boleh terbaca seperti yang sudah tutup.
    */
    'RETURN DECLARED': 'purple',

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
const bulkMode = ref<'RECEIVE' | 'REIMBURSE' | null>(null)
const bulkLoading = ref(false)

/*
|--------------------------------------------------------------------------
| Nomor urut permintaan daftar
|--------------------------------------------------------------------------
| Daftar ini disegarkan dari banyak arah: polling tiap 30 detik, kembali ke
| tab, berpindah halaman, berganti penyaring, dan setiap tindakan yang
| selesai. Permintaan-permintaan itu bisa berangkat bersamaan, dan yang
| berangkat lebih dulu tidak selalu tiba lebih dulu.
|
| Tanpa nomor urut, jawaban lama yang tiba belakangan menimpa jawaban baru
| yang sudah tampil. Itulah sebabnya status yang baru diubah lewat tindakan
| massal sempat terlihat benar lalu berubah kembali seolah-olah batal,
| padahal datanya sudah tersimpan dengan benar di server.
|
| Hanya jawaban dari permintaan terakhir yang boleh dipakai.
|--------------------------------------------------------------------------
*/
let nomorPermintaanDaftar = 0

const fetchRealizations = async (): Promise<void> => {
  const nomorPermintaan = ++nomorPermintaanDaftar

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

    /*
    | Jawaban basi: sudah ada permintaan yang lebih baru sesudah yang ini
    | berangkat, jadi hasilnya dibuang tanpa menyentuh apa pun di layar.
    */
    if (nomorPermintaan !== nomorPermintaanDaftar)
      return

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
    /* Kegagalan dari permintaan basi juga tidak boleh mengubah layar. */
    if (nomorPermintaan !== nomorPermintaanDaftar)
      return
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
    /*
    | Hanya permintaan terakhir yang berhak memadamkan penanda memuat.
    | Bila tidak, jawaban basi akan memadamkannya padahal permintaan yang
    | lebih baru masih berjalan.
    */
    if (nomorPermintaan === nomorPermintaanDaftar)
      loading.value = false
  }
}

usePolling(fetchRealizations, {
  interval: 30000,

  /*
  | Penyegaran otomatis ditahan pada dua keadaan, dan keduanya adalah saat
  | mengganti isi daftar paling merugikan:
  |
  | 1. Ada tindakan massal sedang berjalan -- penyegaran yang berangkat di
  |    tengahnya membaca data sebelum tindakan itu tersimpan.
  | 2. Ada baris yang tercentang -- daftar yang berganti isi akan menghapus
  |    pilihan pengguna di tengah jalan, tanpa sebab yang terlihat.
  |
  | Jadwalnya tidak berhenti; giliran yang ditahan hanya dilewati.
  */
  canPoll: () => !bulkLoading.value && selectedIds.value.length === 0,
})

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

/*
| VERIFIED ikut disebut. Justru di sanalah cetakannya paling dibutuhkan:
| yang membayarkan kekurangannya bukan orang yang memeriksa berkasnya, dan
| ia perlu melihat dokumen yang dibayarnya.
*/
const canPrint = (row: RealizationRow): boolean =>
  isStatus(row, 'APPROVED')
    || isStatus(row, 'RECEIVED')
    || isStatus(row, 'VERIFIED')
    || isStatus(row, 'SETTLED')

/*
|--------------------------------------------------------------------------
| Cetak, dengan atau tanpa lampiran
|--------------------------------------------------------------------------
| Lampiran gambar disatukan ke dalam PDF-nya, dua per halaman. Yang
| berformat PDF tidak bisa disisipkan -- dompdf menggambar halaman dari
| HTML, ia tidak bisa menempelkan halaman PDF yang sudah jadi. Namanya
| tetap didaftarkan di halaman terakhir supaya tidak hilang tanpa jejak.
|--------------------------------------------------------------------------
*/
const printAttachmentDialog = ref(false)
const printTarget = ref<RealizationRow | null>(null)
const printWithAttachments = ref(false)

/* Satukan dengan dokumen perjalanan dinasnya. */
const printWithTrip = ref(false)

/**
 * Apakah perdin dokumen ini boleh ikut dicetak.
 *
 * Perdin yang belum disetujui penuh belum berisi tanda tangan siapa pun,
 * dan hasil cetaknya bisa disalahpahami sebagai dokumen sah. Server
 * menolaknya juga -- yang di sini supaya penolakannya datang sebelum
 * orangnya menunggu, bukan sesudah.
 */
const tripCombinable = (row: RealizationRow | null): boolean =>
  Boolean(row && row.business_trip_status === 'APPROVED')

const runPrint = async (
  row: RealizationRow,
  withAttachments: boolean,
  withTrip = false,
): Promise<void> => {
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

      /*
      | Pilihannya ikut ditandatangani server bersama tautannya, jadi ia
      | dikirim di sini -- bukan ditempelkan ke URL hasilnya, yang akan
      | merusak tanda tangannya.
      */
      { with_attachments: withAttachments, with_business_trip: withTrip },
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

const printDocument = async (row: RealizationRow): Promise<void> => {
  if (!row.public_id || printLoadingId.value)
    return

  /*
  | Hanya ditanyakan kalau memang ada lampirannya. Pertanyaan yang
  | jawabannya cuma satu bukan pilihan, ia hambatan -- dan cetakan adalah
  | aksi yang paling sering diulang orang.
  */
  /*
  | Ditanyakan bila ADA yang bisa dipilih -- lampiran, perdinnya, atau
  | keduanya. Semula hanya lampiran yang membuka dialog ini, sehingga
  | dokumen tanpa lampiran tidak pernah bisa digabungkan dengan perdinnya.
  */
  if ((row.attachment_count ?? 0) > 0 || tripCombinable(row)) {
    printTarget.value = row
    printWithAttachments.value = false
    printWithTrip.value = false
    printAttachmentDialog.value = true

    return
  }

  await runPrint(row, false)
}

const confirmPrint = async (): Promise<void> => {
  const row = printTarget.value

  if (!row)
    return

  printAttachmentDialog.value = false

  await runPrint(row, printWithAttachments.value, printWithTrip.value)
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

/*
| Kembalikan dokumen ke pemohon untuk direvisi.
|
| Pilihan KETIGA di meja approver, di samping menyetujui dan menolak, dan
| bukan salah satu dari keduanya: dokumennya tidak ditolak -- ia dikembalikan
| untuk diperbaiki, lalu berjalan lagi dari tahap pertama.
|
| Alasannya wajib, dan di situ seluruh gunanya. Dokumen yang kembali tanpa
| sepatah kata pun akan diajukan ulang apa adanya, lalu berputar sekali lagi
| melewati seluruh rantai approval -- dan yang terbuang bukan hanya waktu
| pemohonnya.
*/
/*
| HANYA APPROVER YANG SEDANG MEMEGANG DOKUMENNYA.
|
| can_approve datang dari server, dihitung dari baris approval-nya, dan
| sudah menolak pemohon atas dokumennya sendiri. Satu penjaga itu saja --
| sama persis dengan tombol Setujui, karena yang berhak menyuruh
| memperbaiki adalah yang berhak menilai.
|
| Finance TIDAK termasuk, walau ia memegang izin penarikan penerimaan.
| Menarik penerimaan berkas dan menilai isi dokumen adalah dua wewenang
| berbeda; yang kedua milik rantai approval, dan Finance tidak berdiri di
| dalamnya. Jalan mundur Finance tetap ada lewat "Batalkan Terima", yang
| mengembalikan dokumennya ke APPROVED tanpa memaksa siapa pun mengulang
| approval.
|
| Yang sudah dicairkan, dibayarkan, atau diselesaikan tidak lewat sini;
| servernya menolak, dan di sini tombolnya pun tidak muncul.
*/
const canReturnRow = (row: RealizationRow): boolean =>
  Boolean(row.can_approve)

const returnDialog = ref(false)
const returnTarget = ref<RealizationRow | null>(null)
const returnReason = ref('')
const returnError = ref('')
const returnLoading = ref(false)

const openReturn = (row: RealizationRow): void => {
  returnTarget.value = row
  returnReason.value = ''
  returnError.value = ''
  returnDialog.value = true
}

const returnForRevision = async (): Promise<void> => {
  if (!returnTarget.value?.public_id || returnLoading.value)
    return

  /* Sama dengan penjaga di server: tiga huruf. */
  if (returnReason.value.trim().length < 3) {
    returnError.value = t('common.revision.reasonRequired')

    return
  }

  returnLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/cash-advance-realization/${encodeURIComponent(returnTarget.value.public_id)}/return-for-revision`,
      { reason: returnReason.value.trim() },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    returnDialog.value = false
    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('common.revision.successFallback'),
    })

    await fetchRealizations()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('common.revision.failedFallback')),
    })
  }
  finally {
    returnLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Finance menolak pernyataan pengembalian
|--------------------------------------------------------------------------
| Pintu keluar bila uangnya ternyata tidak pernah masuk -- salah rekening,
| bukti keliru, nominalnya kurang. Tanpa ini Finance hanya punya dua pilihan:
| mengakui yang tidak ia terima, atau membiarkan dokumennya menggantung.
|
| Alasannya WAJIB dan diteruskan utuh ke pemohon. Penolakan tanpa alasan
| memaksa pemohon menebak apa yang salah, lalu menyatakan ulang hal yang sama.
|--------------------------------------------------------------------------
*/
const rejectReturnDialog = ref(false)
const rejectReturnTarget = ref<RealizationRow | null>(null)
const rejectReturnReason = ref('')
const rejectReturnError = ref('')
const rejectReturnLoading = ref(false)

const openRejectReturn = (row: RealizationRow): void => {
  rejectReturnTarget.value = row
  rejectReturnReason.value = ''
  rejectReturnError.value = ''
  rejectReturnDialog.value = true
}

const submitRejectReturn = async (): Promise<void> => {
  if (!rejectReturnTarget.value?.public_id || rejectReturnLoading.value)
    return

  /* Sama dengan penjaga di server: tiga huruf. */
  if (rejectReturnReason.value.trim().length < 3) {
    rejectReturnError.value = t('common.revision.reasonRequired')

    return
  }

  rejectReturnLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.patch(
      `/fund-request/cash-advance-realization/${encodeURIComponent(rejectReturnTarget.value.public_id)}/reject-return-declaration`,
      { reason: rejectReturnReason.value.trim() },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    rejectReturnDialog.value = false
    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message
        || t('cashAdvanceRealization.list.rejectReturn.successFallback'),
    })

    await fetchRealizations()

    /* Angka di sidebar ikut berubah -- angka basi merusak kepercayaan pada semuanya. */
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(
        error,
        t('cashAdvanceRealization.list.rejectReturn.failedFallback'),
      ),
    })
  }
  finally {
    rejectReturnLoading.value = false
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

    /* Angka di sidebar ikut turun -- angka basi merusak kepercayaan pada semuanya. */
    await navigationStore.refreshBadges()
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

/**
 * Menunggu diperiksa sebelum kekurangannya dibayarkan.
 *
 * Hanya pada cabang kelebihan: yang nilainya pas sudah tertutup saat
 * diterima, dan yang lebih ditutup orang yang sama begitu uangnya kembali.
 * Keduanya tidak punya meja kedua untuk dituju.
 */
const needsVerification = (row: RealizationRow): boolean =>
  isStatus(row, 'RECEIVED')
    && String(row.difference_type || '').trim().toUpperCase() === 'REIMBURSE'

type DifferenceMode = 'RETURN' | 'CONFIRM_RETURN' | 'REIMBURSE' | 'FINALIZE'

const differenceActionState = (row: RealizationRow): {
  mode: DifferenceMode
  color: string
  icon: string
  label: string
} | null => {
  const type = String(row.difference_type || '').trim().toUpperCase()

  /*
  | Tiga meja, tiga syarat -- cerminan persis dari penjaga di server.
  |
  | Uang yang kembali dinyatakan PEMOHON setelah berkasnya diterima, lalu
  | dikonfirmasi Finance. Uang yang keluar menunggu pemeriksaan lebih dulu,
  | jadi ia baru ditawarkan setelah VERIFIED.
  |
  | Kalau layarnya tidak ikut, tombolnya muncul lalu ditolak -- dan
  | penolakan yang datang sesudah diklik lebih buruk daripada tombol yang
  | memang belum ada.
  */
  if (type === 'RETURN' && isStatus(row, 'RECEIVED')) {
    return {
      mode: 'RETURN',
      color: 'success',
      icon: 'tabler-arrow-back-up',
      label: t('cashAdvanceRealization.list.pending.return'),
    }
  }

  if (type === 'RETURN' && isStatus(row, 'RETURN DECLARED')) {
    return {
      mode: 'CONFIRM_RETURN',
      color: 'purple',
      icon: 'tabler-checks',
      label: t('cashAdvanceRealization.list.pending.confirmReturn'),
    }
  }

  /*
  | Nilainya PAS. Tidak ada uang yang berpindah, jadi yang ditawarkan bukan
  | penyelesaian selisih melainkan persetujuan atas angkanya.
  */
  if (type === 'NONE' && isStatus(row, 'ON CHECKING')) {
    return {
      mode: 'FINALIZE',
      color: 'info',
      icon: 'tabler-circle-check',
      label: t('cashAdvanceRealization.list.pending.finalize'),
    }
  }

  if (type === 'REIMBURSE' && isStatus(row, 'VERIFIED')) {
    return {
      mode: 'REIMBURSE',
      color: 'warning',
      icon: 'tabler-cash-banknote',
      label: t('cashAdvanceRealization.list.pending.reimburse'),
    }
  }

  return null
}

/*
| Menu aksinya baru ditawarkan bila user memang berwenang menjalankannya.
|
| Pengembalian menuntut DUA hal, bukan satu: izinnya, DAN dokumen itu
| memang miliknya. Uang sisanya dipegang satu orang saja, jadi izin tanpa
| kepemilikan akan menawarkan tombol untuk menyerahkan uang orang lain.
*/
const pendingDifferenceMode = (row: RealizationRow): DifferenceMode | null => {
  const state = differenceActionState(row)

  if (!state)
    return null

  if (state.mode === 'RETURN' && abilities.value.can_return && row.is_requester)
    return 'RETURN'

  if (state.mode === 'CONFIRM_RETURN' && abilities.value.can_confirm_return)
    return 'CONFIRM_RETURN'

  if (state.mode === 'FINALIZE' && abilities.value.can_finalize)
    return 'FINALIZE'

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
  canReceive.value || abilities.value.can_reimburse)

/**
 * Tindakan massal yang dibutuhkan satu baris, bila ada.
 *
 * Diturunkan dari penentu yang sudah dipakai menu aksi satuan, jadi keduanya
 * tidak mungkin berbeda pendapat tentang baris mana yang boleh diproses.
 */
const rowBulkAction = (row: RealizationRow): 'RECEIVE' | 'REIMBURSE' | null => {
  if (canReceive.value && needsReceipt(row))
    return 'RECEIVE'

  /* Hari ini bukan harinya berarti baris itu tidak bisa diproses sama sekali. */
  if (!canPayTodayRow(row))
    return null

  const mode = pendingDifferenceMode(row)

  /*
  | PENGEMBALIAN DANA SENGAJA TIDAK BISA MASSAL.
  |
  | Buktinya wajib, dan satu dokumen satu bukti. Pada tindakan massal
  | tidak ada tempat untuk menyatakan berkas mana milik dokumen mana --
  | yang diunggah akan menempel ke semuanya sekaligus, dan sesudahnya
  | tidak ada yang bisa membedakan lagi.
  |
  | Jadi barisnya memang tidak boleh dicentang; pengembalian dilakukan
  | lewat menu pada nomor dokumennya, satu per satu.
  */
  return mode === 'REIMBURSE' ? 'REIMBURSE' : null
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
/*
|--------------------------------------------------------------------------
| Bukti transfer pengembalian dana
|--------------------------------------------------------------------------
| Wajib, dan boleh lebih dari satu berkas. Yang menyerahkan uang adalah
| pihak yang juga membuat dokumennya -- tanpa bukti, tidak ada yang bisa
| memverifikasi bahwa uangnya benar-benar berpindah.
|--------------------------------------------------------------------------
*/
const MAX_FILE_SIZE = 3 * 1024 * 1024

const ALLOWED_FILE_TYPES = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png']

const proofDialog = ref(false)
const proofFiles = ref<File[]>([])
const proofError = ref('')
const proofInput = ref<HTMLInputElement | null>(null)
const proofResolver = ref<((berkas: File[] | null) => void) | null>(null)

/** Ukuran berkas, dibulatkan dua desimal dalam MB. */
const formatFileSize = (bytes: number): string =>
  `${(bytes / 1024 / 1024).toFixed(2)} MB`

/**
 * Meminta bukti transfer, lalu menyelesaikan janji dengan berkasnya.
 *
 * null berarti dibatalkan -- dan pembatalan harus bisa dibedakan dari
 * "belum melampirkan apa pun", karena yang pertama menghentikan seluruh
 * tindakan.
 */
const askTransferProof = (): Promise<File[] | null> => {
  proofFiles.value = []
  proofError.value = ''
  proofDialog.value = true

  return new Promise<File[] | null>(resolve => {
    proofResolver.value = resolve
  })
}

const closeProofDialog = (berkas: File[] | null): void => {
  proofDialog.value = false

  proofResolver.value?.(berkas)
  proofResolver.value = null
}

const triggerProofInput = (): void => {
  proofInput.value?.click()
}

/*
| Berkas diperiksa di sini juga, bukan hanya di server: yang ditolak
| sesudah perjalanan request baru diketahui setelah menunggu, dan yang
| menunggu tidak tahu berkas mana yang bermasalah.
*/
const handleProofSelected = (event: Event): void => {
  const input = event.target as HTMLInputElement

  const dipilih = Array.from(input.files || [])

  for (const berkas of dipilih) {
    if (!ALLOWED_FILE_TYPES.includes(berkas.type)) {
      proofError.value = t('cashAdvanceRealization.form.toast.invalidFileType', { name: berkas.name })

      continue
    }

    if (berkas.size > MAX_FILE_SIZE) {
      proofError.value = t('cashAdvanceRealization.form.toast.invalidFileSize', { name: berkas.name })

      continue
    }

    const kembar = proofFiles.value.some(
      ada => ada.name === berkas.name && ada.size === berkas.size,
    )

    if (!kembar) {
      proofFiles.value.push(berkas)
      proofError.value = ''
    }
  }

  /* Dikosongkan supaya berkas yang sama bisa dipilih lagi sesudah dihapus. */
  input.value = ''
}

const removeProofFile = (index: number): void => {
  proofFiles.value.splice(index, 1)
}

const confirmProofDialog = (): void => {
  if (!proofFiles.value.length) {
    proofError.value = t('cashAdvanceRealization.list.returnDifference.attachmentRequired')

    return
  }

  closeProofDialog([...proofFiles.value])
}

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

  /*
  | Pengembalian dana tidak ada di sini: buktinya wajib dan harus melekat
  | pada satu dokumen, sehingga tindakannya memang hanya satuan.
  */
  const endpoint = {
    RECEIVE: '/fund-request/cash-advance-realization/bulk-receive',
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

    /* Angka di sidebar ikut turun -- angka basi merusak kepercayaan pada semuanya. */
    await navigationStore.refreshBadges()
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
/*
|--------------------------------------------------------------------------
| Batal terima
|--------------------------------------------------------------------------
| Jalan mundur dari meja Finance. Dokumennya kembali ke status disetujui,
| dan dari sana bisa dibatalkan seperti biasa.
|
| Tanpa aksi ini, dokumen yang terlanjur diterima keliru tidak punya pintu
| keluar selain dicairkan -- uang keluar hanya karena tidak ada tombol untuk
| mengurungkannya.
|--------------------------------------------------------------------------
*/
const unreceiveDialog = ref(false)
const unreceiveLoading = ref(false)
const unreceiveError = ref('')
const unreceiveTarget = ref<RealizationRow | null>(null)
const unreceiveNotes = ref('')

const openUnreceive = (row: RealizationRow): void => {
  unreceiveTarget.value = row
  unreceiveNotes.value = ''
  unreceiveError.value = ''
  unreceiveDialog.value = true
}

const submitUnreceive = async (): Promise<void> => {
  if (!unreceiveTarget.value?.public_id || unreceiveLoading.value)
    return

  /*
  | Alasannya wajib. Tanggal pembayaran yang sudah dijanjikan ke pemohon
  | ikut batal karenanya, dan pencabutan tanpa penjelasan hanya melahirkan
  | pertanyaan yang tidak ada jawabannya.
  */
  if (!unreceiveNotes.value.trim()) {
    unreceiveError.value = t('cashAdvanceRealization.list.unreceive.notesRequired')

    return
  }

  unreceiveLoading.value = true

  try {
    const response = await axios.patch(
      `/fund-request/cash-advance-realization/${unreceiveTarget.value.public_id}/unreceive`,
      { notes: unreceiveNotes.value.trim() },
      { headers: { Accept: 'application/json' } },
    )

    unreceiveDialog.value = false
    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvanceRealization.list.unreceive.successFallback'),
    })

    await fetchRealizations()

    /* Angka di sidebar ikut turun -- angka basi merusak kepercayaan pada semuanya. */
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    /* Pesannya di dalam dialognya: alasannya masih terketik di sana. */
    unreceiveError.value = getApiErrorMessage(
      error,
      t('cashAdvanceRealization.list.unreceive.failedFallback'),
    )
  }
  finally {
    unreceiveLoading.value = false
  }
}

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

    /* Angka di sidebar ikut turun -- angka basi merusak kepercayaan pada semuanya. */
    await navigationStore.refreshBadges()
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
| Meneruskan ke pembayaran kekurangan
|--------------------------------------------------------------------------
| Hanya cabang kelebihan yang melewati pintu ini. Yang nilainya pas sudah
| tertutup saat diterima, dan yang kurang ditutup orang yang sama begitu
| uang sisanya kembali -- keduanya tidak punya meja kedua untuk dituju.
|
| Penegasan biasa, bukan modal tersendiri: satu klik, dan catatan
| pemeriksaannya opsional di dalamnya. Membangun formulir untuk satu isian
| opsional hanya menambah langkah tanpa menambah keterangan.
|--------------------------------------------------------------------------
*/
const openVerify = async (row: RealizationRow): Promise<void> => {
  if (!row?.public_id || verifyLoading.value)
    return

  const konfirmasi = await showConfirmAlert({
    title: t('cashAdvanceRealization.list.verify.confirmTitle'),
    text: t('cashAdvanceRealization.list.verify.confirmText', { nomor: row.realization_number || '-' }),
    confirmButtonText: t('cashAdvanceRealization.list.verify.confirmButton'),
    cancelButtonText: t('common.actions.cancel'),
    input: 'textarea',
    inputLabel: t('cashAdvanceRealization.list.verify.notesLabel'),
    inputAttributes: { maxlength: '500' },
  })

  if (!konfirmasi.isConfirmed)
    return

  verifyLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    /*
    | Isian kosong dikirim sebagai string kosong, bukan dihilangkan:
    | servernya menerima catatan opsional, dan membedakan "tidak diisi"
    | dari "tidak dikirim" tidak ada gunanya di sini.
    */
    const response = await axios.patch(
      `/fund-request/cash-advance-realization/${row.public_id}/verify`,
      { notes: String(konfirmasi.value ?? '').trim() },
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t('cashAdvanceRealization.list.verify.success'),
    })

    await fetchRealizations()

    /* Angka di sidebar ikut turun -- angka basi merusak kepercayaan pada semuanya. */
    await navigationStore.refreshBadges()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.list.verify.failed')),
    })
  }
  finally {
    verifyLoading.value = false
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
/*
| Spanduknya menjelaskan kenapa tombol bayarnya mati hari ini, jadi ia
| hanya berarti bagi orang yang punya tombol itu. Hanya yang berwenang menyelesaikan selisihnya yang melihatnya.
|
| Barisnya disaring menurut status, bukan hanya menurut can_pay_today.
| Jadwal pembayaran tetap menempel sesudah dokumennya dibayar, jadi tanpa
| saringan ini dokumen yang sudah lunas pun ikut menjawab "hari ini bukan
| hari pembayaran" -- dan spanduknya mengumumkan ada yang menunggu
| dicairkan padahal tidak ada satu pun.
*/
/*
| Spanduknya menjelaskan kenapa tombol bayarnya mati hari ini, jadi ia hanya
| berarti bagi yang memang punya tombol berjadwal.
|
| Hanya pembayaran kekurangan yang terikat hari kasir. Konfirmasi
| pengembalian dan approve tanpa selisih tidak -- memunculkan spanduk bagi
| mereka berarti mengumumkan penghalang yang tidak pernah menghalangi apa pun.
*/
const blockedByPaymentDay = computed<boolean>(() =>
  canReimburse.value
  && rows.value.some(row =>
    row.can_pay_today === false
    && differenceActionState(row)?.mode === 'REIMBURSE'))

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
/**
 * Penegasan biasa, tanpa kalender kasir.
 *
 * Bentuk kembaliannya sengaja sama persis dengan askPaymentConfirmation,
 * supaya pemanggilnya bisa memilih salah satu tanpa mengurus perbedaan.
 */
const askPlainConfirmation = async (
  judul: string,
  pesan: string,
  tombol: string,
): Promise<{ lanjut: boolean; catatan: string }> => {
  const konfirmasi = await showConfirmAlert({
    title: judul,
    text: pesan,
    confirmButtonText: tombol,
    cancelButtonText: t('common.actions.cancel'),
  })

  return { lanjut: konfirmasi.isConfirmed === true, catatan: '' }
}

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

/*
| Tiga meja, tiga alamat, tiga kalimat. Ditulis sebagai peta, bukan rangkaian
| if di dalam openSettle: yang bercabang di tengah fungsi panjang pada
| akhirnya akan kehilangan satu cabang tanpa ada yang menyadarinya.
*/
/*
| Sifat tiap mode, ditulis sebagai daftar dan bukan sebagai rangkaian
| perbandingan di dalam openSettle.
|
| Bukan sekadar soal kerapian: tiap mode baru yang ditambahkan ke
| rangkaian || menambah satu cabang pada fungsi yang sudah panjang, dan
| cabang yang terlewat di satu tempat tidak akan terlihat sampai ada
| yang menekan tombolnya.
*/

/** Wajib melampirkan bukti transfer sebelum apa pun ditanyakan. */
const MODE_WAJIB_BUKTI: DifferenceMode[] = ['RETURN']

/*
| Tidak terikat hari kerja kasir.
|
| Kalender itu mengatur uang yang KELUAR dari kas. Pernyataan pemohon
| menyusul transfer yang sudah terjadi, konfirmasi Finance mengakui uang yang
| MASUK, dan approve tanpa selisih tidak memindahkan sepeser pun.
|
| Yang tersisa di luar daftar ini hanya pembayaran kekurangan -- satu-satunya
| langkah di modul ini yang benar-benar mengeluarkan uang dari kas.
*/
const MODE_TANPA_KALENDER_KASIR: DifferenceMode[] = ['RETURN', 'CONFIRM_RETURN', 'FINALIZE']

/** Mengirim nominal; yang lain hanya menyetujui angka yang sudah ada. */
const MODE_KIRIM_NOMINAL: DifferenceMode[] = ['RETURN', 'REIMBURSE']

const SETTLE_ROUTES: Record<DifferenceMode, { ns: string; endpoint: string }> = {
  RETURN: {
    ns: 'cashAdvanceRealization.list.returnDifference',
    endpoint: 'return-difference',
  },
  CONFIRM_RETURN: {
    ns: 'cashAdvanceRealization.list.confirmReturn',
    endpoint: 'confirm-return-difference',
  },
  REIMBURSE: {
    ns: 'cashAdvanceRealization.list.reimburseDifference',
    endpoint: 'reimburse-difference',
  },
  FINALIZE: {
    ns: 'cashAdvanceRealization.list.finalize',
    endpoint: 'finalize-no-difference',
  },
}

const openSettle = async (
  row: RealizationRow,
  mode: DifferenceMode,
): Promise<void> => {
  if (!row?.public_id || settleLoading.value)
    return

  const { ns, endpoint } = SETTLE_ROUTES[mode]

  /*
  | Bukti transfer diminta LEBIH DULU, sebelum penegasan apa pun: yang
  | tidak memegang buktinya berhenti di sini, tanpa harus menjawab
  | pertanyaan lain. Hanya untuk pengembalian -- konfirmasi Finance dan
  | pembayaran kekurangan tidak mewajibkan berkas.
  */
  let bukti: File[] = []

  if (MODE_WAJIB_BUKTI.includes(mode)) {
    const dipilih = await askTransferProof()

    if (!dipilih)
      return

    bukti = dipilih
  }

  const judul = t(`${ns}.confirmTitle`)

  const pesan = t(`${ns}.confirmText`, {
    nomor: row.realization_number || '-',
    nominal: `Rp ${formatNumberWithoutRp(Math.abs(Number(row.difference_amount || 0)))}`,
  })

  const tombol = t(`${ns}.confirmButton`)

  /*
  | Jadwal kasir hanya mengikat meja Finance.
  |
  | Pemohon menyatakan transfer yang SUDAH ia lakukan; tanggalnya sudah lewat
  | sebelum layar ini dibuka, jadi menahannya dengan kalender kasir berarti
  | menolak kenyataan yang sudah terjadi. Servernya pun tidak memeriksanya di
  | langkah itu -- dan layar yang menahan lebih ketat daripada servernya
  | adalah larangan yang tidak tertulis di mana pun.
  */
  /*
  | Kalender kasir hanya mengikat langkah yang benar-benar memindahkan uang
  | lewat meja Finance. Pernyataan pemohon menyusul transfer yang sudah
  | terjadi, dan approve tanpa selisih tidak memindahkan sepeser pun.
  */
  const { lanjut, catatan } = MODE_TANPA_KALENDER_KASIR.includes(mode)
    ? await askPlainConfirmation(judul, pesan, tombol)
    : await askPaymentConfirmation(row, judul, pesan, tombol)

  if (!lanjut)
    return

  settleLoading.value = true

  try {
    showLoadingAlert(
      t('common.alert.processing'),
      t('common.alert.pleaseWait'),
    )

    /*
     * Multipart, dengan method spoofing: rutenya PATCH, sementara berkas
     * hanya terkirim lewat POST.
     *
     * settlement_amount tetap dikirim karena server mewajibkannya. Nilainya
     * diturunkan dari selisih dokumen -- pembayaran sebagian memang tidak
     * dapat dicatat, jadi tidak ada yang perlu diketik user.
     */
    const formData = new FormData()

    formData.append('_method', 'PATCH')

    /*
    | Nominalnya dikirim oleh yang MENYATAKAN, bukan yang mengonfirmasi.
    | Angka itu sudah terekam saat pernyataannya; mengirimkannya lagi di
    | langkah kedua berarti memberi kesempatan kedua untuk mengubahnya.
    */
    if (MODE_KIRIM_NOMINAL.includes(mode)) {
      formData.append(
        'settlement_amount',
        String(Math.round(Math.abs(Number(row.difference_amount || 0)) * 100) / 100),
      )
    }

    formData.append('notes', catatan ?? '')

    bukti.forEach(berkas => {
      formData.append('attachments[]', berkas)
    })

    const response = await axios.post(
      `/fund-request/cash-advance-realization/${row.public_id}/${endpoint}`,
      formData,
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    detailDialog.value = false

    showSuccessToast({
      title: t('common.alert.success'),
      text: response.data?.message || t(`${ns}.successFallback`),
    })

    await fetchRealizations()

    /* Angka di sidebar ikut turun -- angka basi merusak kepercayaan pada semuanya. */
    await navigationStore.refreshBadges()
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

/*
| Warna, ikon, dan sebutan tiap percobaan pengembalian.
|
| Dipisah dari templatnya supaya tiga keadaan itu punya SATU definisi: yang
| ditulis berulang di beberapa tempat pada akhirnya akan berbeda warna di
| daftar dan di riwayatnya.
*/
const normalkanStatusPercobaan = (status: string | null | undefined): string =>
  String(status || '').trim().toUpperCase()

const returnAttemptColor = (status: string | null | undefined): string => {
  const normalized = normalkanStatusPercobaan(status)

  if (normalized === 'CONFIRMED')
    return 'success'

  return normalized === 'REJECTED' ? 'error' : 'warning'
}

const returnAttemptIcon = (status: string | null | undefined): string => {
  const normalized = normalkanStatusPercobaan(status)

  if (normalized === 'CONFIRMED')
    return 'tabler-check'

  return normalized === 'REJECTED' ? 'tabler-x' : 'tabler-clock'
}

const returnAttemptLabel = (status: string | null | undefined): string => {
  const normalized = normalkanStatusPercobaan(status)

  if (normalized === 'CONFIRMED')
    return t('cashAdvanceRealization.detail.returnStatus.confirmed')

  return normalized === 'REJECTED'
    ? t('cashAdvanceRealization.detail.returnStatus.rejected')
    : t('cashAdvanceRealization.detail.returnStatus.pending')
}

/** Nominal bergaya Rp, sama dengan yang dipakai kartu ringkasan. */
const formatRupiah = (value: unknown): string =>
  `Rp ${formatNumberWithoutRp(Number(value || 0))}`

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

    /* Angka di sidebar ikut turun -- angka basi merusak kepercayaan pada semuanya. */
    await navigationStore.refreshBadges()
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

/*
| Boleh mengembalikan dari layar detail: approver yang sedang memegangnya,
| atau Finance pada dokumen yang berkasnya baru diterima.
*/
const detailCanReturn = computed<boolean>(() =>
  Boolean(detail.value?.can_approve)
  || (
    String(detail.value?.status || '').trim().toUpperCase() === 'RECEIVED'
    && canUnreceive.value
  ))

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

  /*
  | Yang dibayar lebih awal hanya terlihat pemegang izin pembayaran selisih.
  |
  | Dibayar sebelum jadwal tidak merugikan pemohonnya dan tidak bisa
  | ditindaklanjutinya; mengabarkannya hanya menambah yang harus dibaca.
  | Yang membayarkan tetap melihatnya -- ia perlu tahu apa yang
  | dikerjakannya sendiri.
  |
  | Seluruh baloknya disembunyikan, bukan baris keterangannya saja:
  | balok bertanggal tanpa keterangan terbaca seperti tampilan yang
  | separuh gagal dimuat.
  |
  | Hanya EARLY. Menunggu jadwal, lewat jadwal, tepat waktu, dan
  | terlambat tetap terlihat semua orang -- justru itu yang perlu
  | diketahui pemohonnya.
  */
  if (source.payment_timing === 'EARLY' && !(abilities.value.can_reimburse || abilities.value.can_confirm_return))
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
              'car-row-need-verify': needsVerification(row),
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
                    v-if="canReturnRow(row)"
                    href="javascript:void(0)"
                    :disabled="returnLoading"
                    @click="openReturn(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="mdi-undo-variant"
                        :size="20"
                        color="warning"
                        class="me-3"
                      />
                    </template>

                    <VListItemTitle class="text-warning">
                      {{ t('common.revision.menuReturn') }}
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
                    Meneruskan ke pembayaran kekurangan.
                    Hanya pada cabang kelebihan: dua cabang lain tidak
                    berpindah meja, jadi tidak ada yang perlu diteruskan.
                  -->
                  <VListItem
                    v-if="needsVerification(row) && canVerify"
                    href="javascript:void(0)"
                    @click="openVerify(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-checkup-list"
                        :size="20"
                        class="me-3 text-warning"
                      />
                    </template>

                    <VListItemTitle class="text-warning">
                      {{ t('cashAdvanceRealization.list.action.verify') }}
                    </VListItemTitle>
                  </VListItem>

                  <!--
                    Jalan mundur dari meja Finance. Muncul hanya pada dokumen
                    yang memang sudah diterima -- dan hanya bagi yang
                    berwenang menariknya kembali.
                  -->
                  <VListItem
                    v-if="(isStatus(row, 'RECEIVED') || isStatus(row, 'ON CHECKING')) && canUnreceive"
                    href="javascript:void(0)"
                    @click="openUnreceive(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-arrow-back-up"
                        :size="20"
                        class="me-3 text-warning"
                      />
                    </template>

                    <VListItemTitle class="text-warning">
                      {{ t('cashAdvanceRealization.list.unreceive.menu') }}
                    </VListItemTitle>
                  </VListItem>

                  <!--
                    Aksi penyelesaian selisih hanya muncul bila memang ada uang
                    yang harus berpindah, dan labelnya mengikuti arahnya.
                  -->

                  <!--
                    Milik PEMOHON, bukan Finance. Tidak terikat hari kasir:
                    transfernya sudah terjadi sebelum layar ini dibuka.
                  -->
                  <VListItem
                    v-if="pendingDifferenceMode(row) === 'RETURN'"
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

                  <!-- Milik Finance: menyatakan uangnya benar-benar masuk. -->
                  <VListItem
                    v-if="pendingDifferenceMode(row) === 'CONFIRM_RETURN'"
                    href="javascript:void(0)"
                    @click="openSettle(row, 'CONFIRM_RETURN')"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-checks"
                        :size="20"
                        class="me-3 text-primary"
                      />
                    </template>

                    <VListItemTitle class="text-primary">
                      {{ t('cashAdvanceRealization.list.menu.confirmReturn') }}
                    </VListItemTitle>
                  </VListItem>

                  <!--
                    Pasangan tombol di atas. Tanpa pintu ini Finance hanya
                    punya dua pilihan ketika uangnya tidak pernah masuk:
                    mengakuinya, atau membiarkannya menggantung.
                  -->
                  <VListItem
                    v-if="differenceActionState(row)?.mode === 'CONFIRM_RETURN' && canRejectReturn"
                    href="javascript:void(0)"
                    @click="openRejectReturn(row)"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-receipt-off"
                        :size="20"
                        class="me-3 text-error"
                      />
                    </template>

                    <VListItemTitle class="text-error">
                      {{ t('cashAdvanceRealization.list.menu.rejectReturn') }}
                    </VListItemTitle>
                  </VListItem>

                  <!--
                    Nilainya pas: tidak ada uang yang berpindah, yang
                    dilakukan hanya menyatakan angkanya memang sudah benar.
                    Sesudah ini dokumennya tidak bisa ditarik lagi.
                  -->
                  <VListItem
                    v-if="pendingDifferenceMode(row) === 'FINALIZE'"
                    href="javascript:void(0)"
                    @click="openSettle(row, 'FINALIZE')"
                  >
                    <template #prepend>
                      <VIcon
                        icon="tabler-circle-check"
                        :size="20"
                        class="me-3 text-info"
                      />
                    </template>

                    <VListItemTitle class="text-info">
                      {{ t('cashAdvanceRealization.list.menu.finalize') }}
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
                    v-if="(isStatus(row, 'DRAFT') || isStatus(row, 'NEED REVISION')) && canUpdate"
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
                    v-if="isStatus(row, 'APPROVED') && canCancel && row.can_cancel"
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
                  Lencana revisi, pada dokumen yang pernah dikembalikan.
                  Tetap tampil walau dokumennya sudah berjalan lagi: inilah
                  yang memberi tahu approver bahwa isinya sudah berubah.
                -->
                <VChip
                  v-if="(row.revision_count || 0) > 0"
                  size="x-small"
                  color="warning"
                  variant="outlined"
                >
                  {{ t('common.revision.badge', { n: row.revision_count }) }}
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

                <!--
                  Menunggu diperiksa. Tanpa penanda ini antrean meja pertama
                  satu-satunya yang tak bertanda, padahal ia justru yang
                  menahan langkah berikutnya.
                -->
                <VChip
                  v-else-if="needsVerification(row)"
                  size="x-small"
                  variant="flat"
                  color="warning"
                  prepend-icon="tabler-checkup-list"
                >
                  {{ t('cashAdvanceRealization.list.pending.verify') }}
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
        <div
          class="car-detail__header"
          :class="[`car-detail__header--${detailAccent}`]"
        >
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

                <!--
                  Seluruh keterangan transaksi FPU sumbernya, bukan yang pertama
                  saja. Menampilkan satu akan menyembunyikan keterangan lain yang
                  justru dijawab sebagian barisnya -- dan yang menyetujui membaca
                  dokumen yang isinya tidak seperti kepalanya.
                -->
                <VChip
                  v-for="nama in detailTransactionCategories"
                  :key="`detail-trxcat-${nama}`"
                  size="small"
                  variant="tonal"
                  color="primary"
                  prepend-icon="tabler-tag"
                >
                  {{ nama }}
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

              <div
                class="car-detail__money-tile"
                :class="[`car-detail__money-tile--${detailDifference.color}`]"
              >
                <div class="car-detail__label d-flex align-center gap-1">
                  <VIcon
                    :icon="detailDifference.icon"
                    size="14"
                  />
                  {{ t('cashAdvanceRealization.detail.difference') }}
                </div>

                <div
                  class="car-detail__money-value"
                  :class="[`text-${detailDifference.color}`]"
                >
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

            <!--

              Bentuk perjalanan dinas: dikelompokkan menurut kategori, tanpa
              tanggal. Kolom Tanggal diganti Qty dan Rincian Biaya -- pada
              bentuk ini tanggalnya selalu "-".
            -->
            <div
              v-if="detailHasBreakdown"
              class="car-detail__table"
            >
              <VTable density="compact">
                <thead>
                  <tr>
                    <th style="inline-size: 3rem;">
                      {{ t('cashAdvanceRealization.detail.itemNo') }}
                    </th>
                    <th>{{ t('cashAdvanceRealization.form.breakdown.tableItem') }}</th>
                    <th
                      class="text-center"
                      style="inline-size: 5rem;"
                    >
                      {{ t('cashAdvanceRealization.form.breakdown.tableQty') }}
                    </th>
                    <th
                      class="text-end"
                      style="inline-size: 9rem;"
                    >
                      {{ t('cashAdvanceRealization.form.breakdown.tableUnitPrice') }}
                    </th>
                    <th
                      class="text-end"
                      style="inline-size: 10rem;"
                    >
                      {{ t('cashAdvanceRealization.detail.itemRealization') }}
                    </th>
                    <th
                      class="text-end"
                      style="inline-size: 10rem;"
                    >
                      {{ t('cashAdvanceRealization.detail.itemAdvance') }}
                    </th>
                    <th style="inline-size: 13rem;">
                      {{ t('cashAdvanceRealization.detail.itemAttachment') }}
                    </th>
                  </tr>
                </thead>

                <tbody>
                  <template
                    v-for="kelompok in detailBreakdown"
                    :key="`detail-kategori-${kelompok.name}`"
                  >
                    <tr class="car-detail__cat-row">
                      <td colspan="7">
                        {{ kelompok.name }}

                        <VChip
                          v-if="kelompok.arranged"
                          size="x-small"
                          variant="tonal"
                          color="warning"
                          class="ms-2"
                        >
                          {{ t('cashAdvanceRealization.form.breakdown.arrangedByGa') }}
                        </VChip>
                      </td>
                    </tr>

                    <tr v-if="kelompok.arranged">
                      <td
                        colspan="7"
                        class="car-detail__meta"
                      >
                        {{ t('cashAdvanceRealization.form.breakdown.arrangedNotice') }}
                      </td>
                    </tr>

                    <!--
                      Catatan pemohon untuk GA. Baris tersendiri, dan hanya bila
                      ada: kategori yang diurus GA tidak punya baris rincian,
                      jadi tidak ada kolom keterangan yang bisa ditumpanginya.
                    -->
                    <tr v-if="kelompok.arranged && kelompok.notes.trim()">
                      <td
                        colspan="7"
                        class="car-detail__ga-note"
                      >
                        <span class="text-caption text-medium-emphasis">
                          {{ t('cashAdvanceRealization.form.breakdown.arrangedNotesLabel') }}
                        </span>

                        <div>{{ kelompok.notes }}</div>
                      </td>
                    </tr>

                    <tr
                      v-for="(item, index) in kelompok.rows"
                      :key="`detail-item-${item.id}`"
                    >
                      <td class="text-medium-emphasis">
                        {{ index + 1 }}
                      </td>

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
                        <!--
                          Keterangan barisnya. Pada baris yang dinolkan inilah alasan
                          pengeluarannya tidak jadi -- tanpa ini approver melihat nol
                          tanpa tahu sebabnya.
                        -->
                        <div
                          v-if="item.notes"
                          class="text-caption text-medium-emphasis mt-1"
                        >
                          {{ item.notes }}
                        </div>
                      </td>

                      <td class="text-center">
                        {{ item.qty ?? '-' }}
                      </td>

                      <td class="text-end">
                        Rp {{ formatNumberWithoutRp(Number(item.unit_price || 0)) }}
                      </td>

                      <td class="text-end font-weight-medium">
                        Rp {{ formatNumberWithoutRp(Number(item.realization_amount || 0)) }}
                      </td>

                      <!--
                        Baris di luar rencana tidak punya pembanding: itu
                        pengeluaran yang tidak direncanakan, bukan nol.
                      -->
                      <td class="text-end text-medium-emphasis">
                        {{ item.cash_advance_item_id
                          ? `Rp ${formatNumberWithoutRp(Number(item.advance_amount || 0))}`
                          : '—' }}
                      </td>

                      <td>
                        <!--
                          DUA KELOMPOK, BUKAN SATU DAFTAR.

                          Lampiran FPU adalah dasar pengajuan -- penawaran, perkiraan harga.
                          Lampiran realisasi adalah bukti uangnya benar-benar keluar --
                          kuitansi, struk. Disatukan, perkiraan harga akan terbaca sebagai
                          bukti pengeluaran, dan pemeriksa menyetujui sesuatu yang belum
                          pernah dibuktikan. Karena itu keduanya diberi nama masing-masing.
                        -->
                        <div
                          v-if="(item.source_attachments || []).length"
                          class="car-detail__file-group"
                        >
                          <div class="car-detail__file-group-title">
                            {{ t('cashAdvanceRealization.detail.itemSourceAttachment') }}
                          </div>

                          <a
                            v-for="berkas in item.source_attachments"
                            :key="`detail-item-${item.id}-fpu-${berkas.id}`"
                            class="car-detail__line-file car-detail__line-file--source"
                            :href="berkas.url || undefined"
                            target="_blank"
                            rel="noopener noreferrer"
                          >
                            <VIcon
                              :icon="getAttachmentIcon(berkas.mime_type)"
                              size="18"
                              color="secondary"
                            />

                            <span class="car-detail__line-file-name">
                              {{ berkas.original_filename || berkas.filename }}
                            </span>
                          </a>
                        </div>

                        <div
                          v-if="(item.attachments || []).length"
                          class="car-detail__file-group"
                        >
                          <div class="car-detail__file-group-title">
                            {{ t('cashAdvanceRealization.form.attachment.ownTitle') }}
                          </div>

                          <a
                            v-for="berkas in item.attachments"
                            :key="`detail-item-${item.id}-file-${berkas.id}`"
                            class="car-detail__line-file"
                            :href="berkas.url || undefined"
                            target="_blank"
                            rel="noopener noreferrer"
                          >
                            <VIcon
                              :icon="getAttachmentIcon(berkas.mime_type)"
                              size="18"
                              color="primary"
                            />

                            <span class="car-detail__line-file-name">
                              {{ berkas.original_filename || berkas.filename }}
                            </span>
                          </a>
                        </div>

                        <span
                          v-if="!(item.source_attachments || []).length
                            && !(item.attachments || []).length"
                          class="car-detail__meta"
                        >—</span>
                      </td>
                    </tr>
                  </template>
                </tbody>

                <tfoot>
                  <tr class="car-detail__table-total">
                    <td colspan="4">
                      {{ t('cashAdvanceRealization.detail.totalLabel') }}
                    </td>

                    <td class="text-end">
                      Rp {{ formatNumberWithoutRp(Number(detail.total_realization_amount || 0)) }}
                    </td>

                    <td class="text-end">
                      Rp {{ formatNumberWithoutRp(Number(detail.total_advance_amount || 0)) }}
                    </td>

                    <td />
                  </tr>
                </tfoot>
              </VTable>
            </div>

            <!--
              Bentuk biasa: satu tabel datar, bertanggal.

              Syaratnya disebut, bukan v-else. v-else berpasangan dengan apa
              pun yang kebetulan berdiri tepat di atasnya; sekali ada yang
              menyisipkan sesuatu di antaranya, pasangannya berpindah tanpa
              bersuara.
            -->
            <div
              v-if="!detailHasBreakdown"

              class="car-detail__table"
            >
              <VTable density="compact">
                <thead>
                  <tr>
                    <th style="inline-size: 3rem;">
                      {{ t('cashAdvanceRealization.detail.itemNo') }}
                    </th>
                    <th style="inline-size: 7.5rem;">
                      {{ t('cashAdvanceRealization.detail.itemDate') }}
                    </th>
                    <!--
                      Keterangan transaksi barisnya. Hanya bila himpunannya lebih
                      dari satu -- lihat detailShowsLineCategory.
                    -->
                    <th
                      v-if="detailShowsLineCategory"
                      style="inline-size: 10rem;"
                    >
                      {{ t('cashAdvanceRealization.detail.itemTransactionCategory') }}
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

                    <td
                      v-if="detailShowsLineCategory"
                      class="text-wrap"
                    >
                      <!--
                        Tanda hubung untuk baris lama yang tidak punya keterangan
                        sendiri. Ia memang ikut FPU sumbernya, dan menuliskan nama
                        itu di sini akan menyatakan pilihan yang tidak pernah
                        dibuat siapa pun.
                      -->
                      {{ item.transaction_category_name || '-' }}
                    </td>

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
                      <!--
                        Keterangan barisnya. Pada baris yang dinolkan inilah alasan
                        pengeluarannya tidak jadi -- tanpa ini approver melihat nol
                        tanpa tahu sebabnya.
                      -->
                      <div
                        v-if="item.notes"
                        class="text-caption text-medium-emphasis mt-1"
                      >
                        {{ item.notes }}
                      </div>
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
                      <!--
                        DUA KELOMPOK, BUKAN SATU DAFTAR.

                        Lampiran FPU adalah dasar pengajuan -- penawaran, perkiraan harga.
                        Lampiran realisasi adalah bukti uangnya benar-benar keluar --
                        kuitansi, struk. Disatukan, perkiraan harga akan terbaca sebagai
                        bukti pengeluaran, dan pemeriksa menyetujui sesuatu yang belum
                        pernah dibuktikan. Karena itu keduanya diberi nama masing-masing.
                      -->
                      <div
                        v-if="(item.source_attachments || []).length"
                        class="car-detail__file-group"
                      >
                        <div class="car-detail__file-group-title">
                          {{ t('cashAdvanceRealization.detail.itemSourceAttachment') }}
                        </div>

                        <a
                          v-for="berkas in item.source_attachments"
                          :key="`detail-item-${item.id}-fpu-${berkas.id}`"
                          class="car-detail__line-file car-detail__line-file--source"
                          :href="berkas.url || undefined"
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          <VIcon
                            :icon="getAttachmentIcon(berkas.mime_type)"
                            size="18"
                            color="secondary"
                          />

                          <span class="car-detail__line-file-name">
                            {{ berkas.original_filename || berkas.filename }}
                          </span>
                        </a>
                      </div>

                      <div
                        v-if="(item.attachments || []).length"
                        class="car-detail__file-group"
                      >
                        <div class="car-detail__file-group-title">
                          {{ t('cashAdvanceRealization.form.attachment.ownTitle') }}
                        </div>

                        <a
                          v-for="berkas in item.attachments"
                          :key="`detail-item-${item.id}-file-${berkas.id}`"
                          class="car-detail__line-file"
                          :href="berkas.url || undefined"
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          <VIcon
                            :icon="getAttachmentIcon(berkas.mime_type)"
                            size="18"
                            color="primary"
                          />

                          <span class="car-detail__line-file-name">
                            {{ berkas.original_filename || berkas.filename }}
                          </span>
                        </a>
                      </div>

                      <span
                        v-if="!(item.source_attachments || []).length
                          && !(item.attachments || []).length"
                        class="car-detail__meta"
                      >—</span>
                    </td>
                  </tr>
                </tbody>

                <tfoot>
                  <tr class="car-detail__table-total">
                    <!--
                      Dihitung, bukan dipatok: kolom keterangan transaksi hanya
                      muncul bila himpunannya lebih dari satu, dan angka tetap
                      akan menggeser seluruh sel pada baris ini -- tanpa ada yang
                      memberi tahu.
                    -->
                    <td :colspan="detailShowsLineCategory ? 4 : 3">
                      {{ t('cashAdvanceRealization.detail.totalLabel') }}
                    </td>

                    <td class="text-end car-detail__nowrap">
                      Rp {{ formatNumberWithoutRp(Number(detail.total_advance_amount || 0)) }}
                    </td>

                    <td class="text-end car-detail__nowrap">
                      Rp {{ formatNumberWithoutRp(Number(detail.total_realization_amount || 0)) }}
                    </td>

                    <!--
                      Yang tetap di atribut statis, yang berubah di :class.
                      Kelas statis yang dititipkan ke dalam ikatan dinamis
                      membuat pembacanya menelusuri ekspresi untuk menemukan
                      sesuatu yang sebenarnya tidak pernah berubah.
                    -->
                    <td
                      class="text-end car-detail__nowrap"
                      :class="`text-${detailDifference.color}`"
                    >
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

            <!--
              Serah-terima dari pemeriksa ke pembayar, pada cabang kekurangan.

              Langkah inilah yang memindahkan dokumen antar meja, dan selama
              ini justru ia yang tidak terlihat: pembaca detail hanya melihat
              "diterima" lalu "dibayar", seolah tidak ada pemeriksaan di
              antaranya.
            -->
            <VAlert
              v-if="detail.verified_at"
              type="warning"
              variant="tonal"
              density="compact"
              class="mt-5"
            >
              <div class="font-weight-medium">
                {{ t('cashAdvanceRealization.detail.verifiedTitle') }}
              </div>

              <div class="car-detail__meta mt-1">
                {{ t('cashAdvanceRealization.detail.verifiedBy', {
                  name: detail.verified_by_name || '-',
                  time: formatAuditDateTime(detail.verified_at),
                }) }}
              </div>

              <div
                v-if="detail.verification_notes"
                class="text-body-2 mt-1"
              >
                {{ detail.verification_notes }}
              </div>
            </VAlert>

            <!--
              Riwayat pengembalian sisa dana.

              Pengembalian bisa GAGAL, dan karena itu bisa berulang. Tanpa
              daftar ini pemohon yang ditolak hanya tahu dokumennya kembali,
              tidak tahu APA yang salah -- lalu menyatakan ulang hal yang sama.

              Percobaan yang ditolak sengaja tetap ditampilkan beserta
              buktinya: justru itu yang perlu dibaca berdampingan dengan
              alasan penolakannya.
            -->
            <template v-if="(detail.return_declarations || []).length">
              <div class="car-detail__section-title">
                <VIcon
                  icon="tabler-history"
                  size="18"
                />
                {{ t('cashAdvanceRealization.detail.returnHistoryTitle') }}
              </div>

              <VTimeline
                side="end"
                align="start"
                truncate-line="both"
                density="compact"
                class="car-return-history"
              >
                <VTimelineItem
                  v-for="percobaan in detail.return_declarations"
                  :key="`return-attempt-${percobaan.id}`"
                  :dot-color="returnAttemptColor(percobaan.status)"
                  :icon="returnAttemptIcon(percobaan.status)"
                  size="x-small"
                >
                  <div class="d-flex flex-wrap align-center gap-2 mb-1">
                    <span class="font-weight-medium">
                      {{ t('cashAdvanceRealization.detail.returnAttempt', { n: percobaan.attempt }) }}
                    </span>

                    <VChip
                      :color="returnAttemptColor(percobaan.status)"
                      size="x-small"
                      label
                    >
                      {{ returnAttemptLabel(percobaan.status) }}
                    </VChip>
                  </div>

                  <div class="text-body-2">
                    {{ t('cashAdvanceRealization.detail.returnDeclaredBy', {
                      name: percobaan.declared_by_name || '-',
                      amount: formatRupiah(percobaan.declared_amount),
                    }) }}
                  </div>

                  <div class="car-detail__meta">
                    {{ formatAuditDateTime(percobaan.declared_at) }}
                  </div>

                  <div
                    v-if="percobaan.declaration_notes"
                    class="text-body-2 mt-1"
                  >
                    {{ percobaan.declaration_notes }}
                  </div>

                  <!--
                    Alasan penolakan ditonjolkan, bukan diselipkan sebagai
                    catatan biasa: inilah satu-satunya kalimat yang memberi
                    tahu pemohon apa yang harus ia perbaiki.
                  -->
                  <VAlert
                    v-if="percobaan.status === 'REJECTED' && percobaan.review_notes"
                    type="error"
                    variant="tonal"
                    density="compact"
                    class="mt-2"
                  >
                    <div class="text-body-2">
                      {{ percobaan.review_notes }}
                    </div>

                    <div class="car-detail__meta mt-1">
                      {{ t('cashAdvanceRealization.detail.returnReviewedBy', {
                        name: percobaan.reviewed_by_name || '-',
                        time: formatAuditDateTime(percobaan.reviewed_at),
                      }) }}
                    </div>
                  </VAlert>

                  <div
                    v-else-if="percobaan.reviewed_at"
                    class="car-detail__meta mt-1"
                  >
                    {{ t('cashAdvanceRealization.detail.returnReviewedBy', {
                      name: percobaan.reviewed_by_name || '-',
                      time: formatAuditDateTime(percobaan.reviewed_at),
                    }) }}
                  </div>

                  <div
                    v-if="(percobaan.attachments || []).length"
                    class="car-detail__files mt-2"
                  >
                    <a
                      v-for="berkas in percobaan.attachments"
                      :key="`return-attempt-file-${berkas.id}`"
                      class="car-detail__file"
                      :href="berkas.url || undefined"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <VIcon
                        :icon="getAttachmentIcon(berkas.mime_type)"
                        size="22"
                        color="info"
                      />

                      <div class="car-detail__file-body">
                        <div class="car-detail__file-name">
                          {{ berkas.original_filename || berkas.filename }}
                        </div>

                        <div class="car-detail__meta">
                          {{ formatAttachmentSize(berkas.file_size) }}
                        </div>
                      </div>

                      <VIcon
                        icon="tabler-external-link"
                        size="16"
                        class="text-medium-emphasis"
                      />
                    </a>
                  </div>
                </VTimelineItem>
              </VTimeline>
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

            <!--
              Revisi: yang sedang berjalan, dan riwayatnya. Ditaruh paling
              bawah -- yang dibaca lebih dulu adalah dokumennya sendiri.
            -->
            <div class="mt-5">
              <RevisionHistory
                :revisions="detail?.revisions || []"
                :open-revision="detail?.revision_open || null"
              />
            </div>
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
            v-if="detailCanReturn"
            color="warning"
            variant="tonal"
            prepend-icon="mdi-undo-variant"
            class="text-none"
            :disabled="returnLoading"
            @click="openReturn(detail)"
          >
            {{ t('common.revision.menuReturn') }}
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
        <!--
          Judulnya bertanya dan menyebut nomornya sekaligus. Pernyataan
          seperti "Setujui Realisasi FPU" terbaca sebagai pemberitahuan
          bahwa dokumennya sedang disetujui, bukan sebagai pertanyaan yang
          menunggu jawaban -- dan nomornya adalah hal pertama yang perlu
          dipastikan sebelum menjawab.
        -->
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvanceRealization.list.approve.dialogTitle', {
            number: approveTarget?.realization_number || '-',
          }) }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('cashAdvanceRealization.list.approve.dialogSubtitle') }}
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
    <!--
      DIALOG KEMBALIKAN

      Judulnya bertanya dan menyebut nomornya sekaligus. Yang menekan tombol
      ini sedang memindahkan dokumen orang lain kembali ke mejanya, dan
      nomornya adalah hal pertama yang perlu dipastikan sebelum menjawab.

      Warnanya bukan merah. Mengembalikan bukan menolak: dokumennya masih
      hidup, dan yang diminta adalah perbaikan.
    -->
    <VDialog
      v-model="returnDialog"
      max-width="520"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('common.revision.dialogTitle', {
            number: returnTarget?.realization_number || '-',
          }) }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('common.revision.dialogSubtitle') }}
          </div>

          <VTextarea
            v-model="returnReason"
            :label="t('common.revision.reasonLabel')"
            :placeholder="t('common.revision.reasonPlaceholder')"
            rows="3"
            auto-grow
            :error="!!returnError"
            :error-messages="returnError ? [returnError] : []"
          />
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            :disabled="returnLoading"
            @click="returnDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="warning"
            class="text-none"
            :loading="returnLoading"
            @click="returnForRevision"
          >
            {{ t('common.revision.confirmButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      Menolak pernyataan pengembalian.

      Warnanya merah: ini menyatakan uangnya TIDAK diterima, dan dokumennya
      mundur ke meja pemohon. Berbeda dari "Kembalikan untuk revisi", yang
      meminta perbaikan isi dokumen, bukan uangnya.
    -->
    <VDialog
      v-model="rejectReturnDialog"
      max-width="520"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvanceRealization.list.rejectReturn.dialogTitle', {
            number: rejectReturnTarget?.realization_number || '-',
          }) }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('cashAdvanceRealization.list.rejectReturn.dialogSubtitle') }}
          </div>

          <VTextarea
            v-model="rejectReturnReason"
            :label="t('cashAdvanceRealization.list.rejectReturn.reasonLabel')"
            :placeholder="t('cashAdvanceRealization.list.rejectReturn.reasonPlaceholder')"
            rows="3"
            auto-grow
            :error="!!rejectReturnError"
            :error-messages="rejectReturnError ? [rejectReturnError] : []"
          />
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            :disabled="rejectReturnLoading"
            @click="rejectReturnDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="error"
            class="text-none"
            :loading="rejectReturnLoading"
            @click="submitRejectReturn"
          >
            {{ t('cashAdvanceRealization.list.rejectReturn.confirmButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="rejectDialog"
      max-width="520"
    >
      <VCard>
        <!--
          Judulnya bertanya dan menyebut nomornya sekaligus. Pernyataan
          seperti "Setujui Realisasi FPU" terbaca sebagai pemberitahuan
          bahwa dokumennya sedang disetujui, bukan sebagai pertanyaan yang
          menunggu jawaban -- dan nomornya adalah hal pertama yang perlu
          dipastikan sebelum menjawab.
        -->
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvanceRealization.list.reject.dialogTitle', {
            number: rejectTarget?.realization_number || '-',
          }) }}
        </VCardTitle>

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('cashAdvanceRealization.list.reject.dialogSubtitle') }}
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

    <!--
      BUKTI TRANSFER PENGEMBALIAN DANA

      Wajib, dan boleh lebih dari satu berkas. Dibuka sebelum penegasan
      apa pun: yang tidak memegang buktinya berhenti di sini.

      persistent: menutupnya harus lewat tombol, supaya tindakan tidak
      batal karena salah klik di luar kotaknya.
    -->
    <VDialog
      v-model="proofDialog"
      max-width="560"
      persistent
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvanceRealization.list.returnDifference.dialogTitle') }}
        </VCardTitle>

        <VDivider />

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('cashAdvanceRealization.list.returnDifference.dialogSubtitle') }}
          </div>

          <div class="d-flex align-center justify-space-between mb-2">
            <div class="text-body-1 font-weight-medium">
              {{ t('cashAdvanceRealization.list.returnDifference.attachmentTitle') }}
              <span class="text-error">*</span>
            </div>

            <VBtn
              size="small"
              variant="outlined"
              color="primary"
              class="text-none"
              prepend-icon="tabler-paperclip"
              @click="triggerProofInput"
            >
              {{ t('cashAdvanceRealization.list.returnDifference.attachmentAddButton') }}
            </VBtn>
          </div>

          <input
            ref="proofInput"
            type="file"
            multiple
            accept=".pdf,.jpg,.jpeg,.png"
            class="d-none"
            @change="handleProofSelected"
          >

          <div class="text-caption text-medium-emphasis mb-3">
            {{ t('cashAdvanceRealization.list.returnDifference.attachmentHint') }}
          </div>

          <!-- Berkas yang sudah dipilih, berikut ukurannya. -->
          <div
            v-if="proofFiles.length"
            class="d-flex flex-column gap-2 mb-2"
          >
            <div
              v-for="(berkas, index) in proofFiles"
              :key="`bukti-${index}-${berkas.name}`"
              class="car-proof-file"
            >
              <VIcon
                :icon="berkas.type === 'application/pdf' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                size="20"
                color="primary"
              />

              <div class="car-proof-file__body">
                <div class="car-proof-file__name">
                  {{ berkas.name }}
                </div>

                <div class="text-caption text-medium-emphasis">
                  {{ formatFileSize(berkas.size) }}
                </div>
              </div>

              <VBtn
                icon
                size="x-small"
                variant="text"
                color="error"
                @click="removeProofFile(index)"
              >
                <VIcon
                  icon="tabler-x"
                  size="16"
                />
              </VBtn>
            </div>
          </div>

          <VAlert
            v-if="proofError"
            type="error"
            variant="tonal"
            density="compact"
          >
            {{ proofError }}
          </VAlert>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            @click="closeProofDialog(null)"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="primary"
            class="text-none"
            :disabled="!proofFiles.length"
            @click="confirmProofDialog"
          >
            {{ t('common.actions.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      BATAL TERIMA

      Peringatannya menyebut akibatnya, bukan sekadar bertanya. Yang
      menekannya perlu tahu bahwa tanggal pembayaran yang sudah dijanjikan
      ikut batal -- itu bagian yang tidak terlihat dari namanya.
    -->
    <VDialog
      v-model="unreceiveDialog"
      max-width="520"
    >
      <VCard v-if="unreceiveTarget">
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvanceRealization.list.unreceive.title') }}
        </VCardTitle>

        <VDivider />

        <VCardText>
          <VAlert
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ t('cashAdvanceRealization.list.unreceive.warning') }}
          </VAlert>

          <VAlert
            v-if="unreceiveError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ unreceiveError }}
          </VAlert>

          <div class="text-body-2 text-medium-emphasis mb-3">
            {{ t('cashAdvanceRealization.list.unreceive.notesHint') }}
          </div>

          <VTextarea
            v-model="unreceiveNotes"
            :label="t('cashAdvanceRealization.list.unreceive.notes')"
            rows="3"
            density="comfortable"
            autofocus
          />
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            :disabled="unreceiveLoading"
            @click="unreceiveDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="warning"
            class="text-none"
            :loading="unreceiveLoading"
            :disabled="!unreceiveNotes.trim()"
            @click="submitUnreceive"
          >
            {{ t('cashAdvanceRealization.list.unreceive.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      CETAK: gabung lampiran atau tidak

      Muncul hanya pada dokumen yang punya lampiran. Keterangan soal PDF
      disebutkan di muka, bukan disembunyikan sampai cetakannya jadi --
      orang yang mengira vouchernya ikut tercetak akan berhenti memeriksa.
    -->
    <VDialog
      v-model="printAttachmentDialog"
      max-width="480"
    >
      <VCard v-if="printTarget">
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvanceRealization.list.print.attachmentTitle') }}
        </VCardTitle>

        <VDivider />

        <VCardText>
          <div class="text-body-2 mb-3">
            {{ t('cashAdvanceRealization.list.print.attachmentCount', {
              count: printTarget.attachment_count ?? 0,
            }) }}
          </div>

          <VCheckbox
            v-model="printWithAttachments"
            :label="t('cashAdvanceRealization.list.print.attachmentInclude')"
            density="comfortable"
            hide-details
          />

          <!--
            Dimatikan berikut alasannya, bukan disembunyikan: pilihan yang
            hilang tidak bisa ditanyai, dan yang membacanya akan mengira
            fiturnya belum ada.
          -->
          <VCheckbox
            v-model="printWithTrip"
            :label="t('cashAdvanceRealization.list.print.combineTripInclude')"
            :disabled="!tripCombinable(printTarget)"
            :hint="tripCombinable(printTarget)
              ? undefined
              : t('cashAdvanceRealization.list.print.combineTripUnavailable')"
            persistent-hint
            density="comfortable"
          />

          <VAlert
            v-if="printWithTrip"
            type="info"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ t('cashAdvanceRealization.list.print.combineTripNotice') }}
          </VAlert>

          <VAlert
            v-if="printWithAttachments"
            type="info"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ t('cashAdvanceRealization.list.print.attachmentPdfNotice') }}
          </VAlert>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            @click="printAttachmentDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="primary"
            class="text-none"
            prepend-icon="tabler-printer"
            @click="confirmPrint"
          >
            {{ t('cashAdvanceRealization.list.print.attachmentConfirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
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

/*
| Antrean pemeriksaan. Warnanya sama dengan pembayaran kekurangan -- kedua
| tahap itu satu urusan yang sama, hanya berbeda mejanya -- tetapi diberi
| garis tepi supaya yang belum diperiksa tetap terbedakan dari yang sudah.
*/
.car-row-need-verify {
  border-inline-start: 3px solid rgb(var(--v-theme-warning));
  background: rgba(var(--v-theme-warning), 0.04);
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

/*
| Catatan GA pada modal detail. Bergaris tepi supaya terbaca sebagai
| perkataan pemohon, bukan sebagai keterangan sistem seperti baris di
| atasnya.
*/
.car-detail__ga-note {
  border-inline-start: 2px solid rgba(var(--v-theme-warning), 0.5);
  font-size: 0.875rem;
  white-space: pre-wrap;
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

/* Baris judul kategori pada rincian perjalanan dinas. */
.car-detail__cat-row td {

  background: rgba(var(--v-theme-primary), 0.06);

  font-weight: 600;
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

/*
| Kolom uang tidak pernah terbungkus.
|
| "Rp 2.000.000" yang pecah menjadi dua baris terbaca sebagai dua angka
| sekejap, dan pada kolom uang kesalahan baca sekejap itu yang mahal.
|
| Dinamai __nowrap, bukan __money. Di sana nama __amount sudah
| terpakai kotak total di kepala modalnya, dan menyamakan penamaan di kedua
| modal lebih berharga daripada memakai nama yang kebetulan masih kosong di
| sini saja.
*/
.car-detail__table :deep(.car-detail__nowrap) {
  white-space: nowrap;
}

.car-detail__table :deep(tfoot .car-detail__table-total td) {
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgba(var(--v-theme-primary), 0.08);
  font-size: 0.9rem;
  font-weight: 700;
}

/* Bukti yang menempel pada satu baris rincian. */
/* Satu kelompok lampiran, berikut namanya. */
.car-detail__file-group {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}

.car-detail__file-group + .car-detail__file-group {
  margin-block-start: 0.5rem;
}

.car-detail__file-group-title {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.65rem;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

/*
  Lampiran FPU diredupkan dengan sengaja: ia rujukan, bukan bukti yang
  sedang dinilai. Yang dinilai pemeriksa adalah kelompok di bawahnya.
*/
.car-detail__line-file--source {
  opacity: 0.75;
}

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
/* Kartu berkas bukti transfer, sebentuk dengan kartu berkas di formulirnya. */
.car-proof-file {
  display: flex;
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  gap: 0.5rem;
  padding-block: 0.375rem;
  padding-inline: 0.5rem;
}

.car-proof-file__body {
  flex: 1;
  min-inline-size: 0;
}

/* Nama berkas yang panjang dipotong, bukan melebarkan dialognya. */
.car-proof-file__name {
  overflow: hidden;
  font-size: 0.85rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
