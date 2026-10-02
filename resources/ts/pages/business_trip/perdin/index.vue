<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { usePermissionStore } from '@/stores/permission'
import { buildPrintLoadingPage } from '@/utils/printLoadingPage'

/*
|--------------------------------------------------------------------------
| Daftar Perjalanan Dinas
|--------------------------------------------------------------------------
| Susunannya mengikuti daftar FPU: kartu saringan di atas, kartu tabel di
| bawah, dan seluruh aksi bersembunyi di balik nomor dokumennya. Bentuk yang
| sama dipakai di semua modul dokumen supaya orang tidak perlu belajar dua
| kali.
|--------------------------------------------------------------------------
*/

interface ItineraryRow {
  sort_no: number
  date: string | null
  time_start: string | null
  time_end: string | null
  timezone: string
  time_text: string
  description: string
}

interface ApprovalRow {
  step_order: number
  label: string | null
  approver_name: string | null
  approver_type: string
  status: string
  approved_at: string | null
  rejected_at: string | null
  notes: string | null
}

interface ArrangementFile {
  id: number
  original_filename: string
  mime_type: string | null
  file_size: number | null
  url: string | null
}

interface ArrangementRow {
  id: number
  type: string
  vendor_name: string | null
  reference_no: string | null
  starts_at: string | null
  ends_at: string | null
  notes: string | null
  status: string
  cancelled_at: string | null
  cancellation_notes: string | null
  cancelled_by_name: string | null
  replaces_id: number | null
  created_by_name: string | null
  created_at: string | null
  files: ArrangementFile[]
}

interface TripRow {
  public_id: string
  trip_number: string
  date: string | null

  status: string
  is_editable: boolean
  can_approve: boolean
  is_on_time: boolean
  allows_parallel_cash_advance: boolean

  /* Tanggal berangkatnya sudah lewat -- draft begini tidak bisa diajukan. */
  is_departure_passed: boolean

  /* Titik awal rantai persetujuan pada rincian. */
  submitted_at: string | null

  rejection_notes: string | null
  cancellation_notes: string | null

  employee_name: string
  department_name: string | null
  position_name: string | null

  destination: string

  depart_date: string | null
  depart_time: string | null
  return_date: string | null
  return_time: string | null
  duration_days: number

  purpose: string
  notes: string | null

  itineraries: ItineraryRow[]
  approvals: ApprovalRow[]

  /*
  | Tidak ikut dikirim daftarnya -- terlalu mahal untuk dua puluh baris
  | sekaligus, dan tidak ada yang membacanya dari tabel. Diambil tersendiri
  | saat rinciannya dibuka, lalu ditempelkan ke baris ini.
  */
  arrangements?: ArrangementRow[]
}

interface Abilities {
  can_view: boolean
  view_scope: string
  can_create: boolean
  can_update: boolean
  can_delete: boolean
  can_submit: boolean
  can_cancel: boolean
  can_print: boolean
  can_export: boolean
  can_arrange: boolean
}

const { t, locale } = useI18n()
const router = useRouter()
const permissionStore = usePermissionStore()

const rows = ref<TripRow[]>([])
const loading = ref(false)
const loadError = ref(false)

const defaultAbilities = (): Abilities => ({
  can_view: false,
  view_scope: 'NONE',
  can_create: false,
  can_update: false,
  can_delete: false,
  can_submit: false,
  can_cancel: false,
  can_print: false,
  can_export: false,
  can_arrange: false,
})

const abilities = ref<Abilities>(defaultAbilities())

const canView = computed(() => permissionStore.can('business_trip.view'))

/*
|--------------------------------------------------------------------------
| Saringan dan halaman
|--------------------------------------------------------------------------
*/
const searchQuery = ref('')
const statusFilter = ref<string | null>(null)
const departFrom = ref('')
const departTo = ref('')
const onlyWaitingMyApproval = ref(false)

const currentPage = ref(1)
const rowPerPage = ref(10)
const totalData = ref(0)
const totalPage = ref(1)

const statusItems = computed(() => [
  { title: t('businessTrip.status.DRAFT'), value: 'DRAFT' },
  { title: t('businessTrip.status.IN PROGRESS'), value: 'IN PROGRESS' },
  { title: t('businessTrip.status.APPROVED'), value: 'APPROVED' },
  { title: t('businessTrip.status.REJECTED'), value: 'REJECTED' },
  { title: t('businessTrip.status.CANCELLED'), value: 'CANCELLED' },
])

/*
| Saringan yang sedang aktif, tanpa halaman.
|
| Dipakai daftar maupun export -- file yang isinya berbeda dengan yang
| sedang tampil membuat orang mengira salah satunya bohong.
*/
const buildFilterParams = (): Record<string, unknown> => ({
  search: searchQuery.value || undefined,
  status: statusFilter.value || undefined,
  depart_date_from: departFrom.value || undefined,
  depart_date_to: departTo.value || undefined,
})

const buildParams = (): Record<string, unknown> => ({
  page: currentPage.value,
  per_page: rowPerPage.value,
  ...buildFilterParams(),
})

const fetchTrips = async (): Promise<void> => {
  loading.value = true
  loadError.value = false

  try {
    const response = await axios.get('/business-trip/perdin', {
      params: buildParams(),
    })

    const payload = response.data

    rows.value = payload?.data ?? []
    abilities.value = { ...defaultAbilities(), ...(payload?.abilities || {}) }

    totalData.value = Number(payload?.meta?.total ?? rows.value.length ?? 0)
    totalPage.value = Number(payload?.meta?.last_page ?? 1)
  }
  catch (error: unknown) {
    loadError.value = true

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('businessTrip.list.loadFailed')),
    })
  }
  finally {
    loading.value = false
  }
}

/* Setiap saringan yang ikut dikirim harus ikut memicu pengambilan ulang. */
watch([currentPage, rowPerPage], fetchTrips)

watch([searchQuery, statusFilter, departFrom, departTo], () => {
  currentPage.value = 1
  fetchTrips()
})

const resetFilters = (): void => {
  searchQuery.value = ''
  statusFilter.value = null
  departFrom.value = ''
  departTo.value = ''
  onlyWaitingMyApproval.value = false
  currentPage.value = 1
}

/*
| Penyaringan "menunggu saya" dilakukan di layar, bukan di server: penandanya
| sudah ikut pada tiap baris, jadi menanyakannya lagi hanya menambah perjalanan
| bolak-balik tanpa menambah apa pun.
*/
const visibleRows = computed<TripRow[]>(() =>
  onlyWaitingMyApproval.value
    ? rows.value.filter(row => row.can_approve)
    : rows.value)

/*
|--------------------------------------------------------------------------
| Rincian
|--------------------------------------------------------------------------
*/
const detailDialog = ref(false)
const detailRow = ref<TripRow | null>(null)

/*
|--------------------------------------------------------------------------
| Tampilan
|--------------------------------------------------------------------------
*/
/* Warna status, sebagai peta -- bukan percabangan yang harus dibaca ulang. */
const STATUS_COLORS: Record<string, string> = {
  'APPROVED': 'success',
  'IN PROGRESS': 'warning',
  'REJECTED': 'error',
  'CANCELLED': 'secondary',
}

const statusColor = (status: string): string => STATUS_COLORS[status] ?? 'info'

const formatDate = (nilai?: string | null): string => {
  if (!nilai)
    return '-'

  const d = new Date(`${nilai}T00:00:00`)

  if (Number.isNaN(d.getTime()))
    return nilai

  return d.toLocaleDateString(undefined, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

/*
|--------------------------------------------------------------------------
| Pemesanan perjalanan
|--------------------------------------------------------------------------
| Diambil saat rinciannya dibuka, bukan ikut daftarnya. Satu halaman berisi
| dua puluh perdin, dan tiap pemesanan membawa berkas-berkasnya sendiri --
| memuat semuanya di muka berarti membayar mahal untuk data yang hampir
| selalu tidak jadi dilihat.
|--------------------------------------------------------------------------
*/
const arrangementLoading = ref(false)

/*
| Datang dari server, bukan dihitung di layar. Syaratnya bukan cuma
| permission: perdinnya harus sudah disetujui dan belum dibatalkan. Menebak
| ketiganya di sini berarti dua aturan untuk satu pertanyaan.
*/
const canArrangeCurrent = ref(false)

const tripPeriod = ref<{ depart_date: string | null; return_date: string | null }>({
  depart_date: null,
  return_date: null,
})

const fetchArrangements = async (row: TripRow): Promise<void> => {
  arrangementLoading.value = true
  canArrangeCurrent.value = false

  try {
    const response = await axios.get(
      `/business-trip/perdin/${encodeURIComponent(row.public_id)}/arrangements`,
    )

    const payload = response.data ?? {}

    /*
    | Ditempelkan ke baris di daftar, bukan disimpan terpisah. Kartu
    | rinciannya membaca trip.arrangements -- satu sumber, jadi layar tidak
    | bisa menampilkan daftar yang berbeda dari yang baru saja diambil.
    */
    row.arrangements = payload.data ?? []

    canArrangeCurrent.value = Boolean(payload.can_arrange)

    tripPeriod.value = payload.trip_period ?? { depart_date: null, return_date: null }
  }
  catch (error: unknown) {
    /*
    | Gagalnya tidak menutup rinciannya. Rundown dan persetujuannya sudah ada
    | di tangan dan tetap berguna; yang hilang hanya satu bagian, dan itu
    | dikabarkan seadanya.
    */
    row.arrangements = []

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('businessTrip.arrangement.manage.loadFailed')),
    })
  }
  finally {
    arrangementLoading.value = false
  }
}

const openDetail = async (row: TripRow): Promise<void> => {
  detailRow.value = row
  detailDialog.value = true

  /* Pemesanannya menyusul; rinciannya sudah bisa dibaca sementara menunggu. */
  await fetchArrangements(row)
}

/* "23 Sep 2026 - 25 Sep 2026", sebagai pegangan saat mengisi tanggal. */
const tripPeriodText = computed<string>(() => {
  const { depart_date: mulai, return_date: selesai } = tripPeriod.value

  if (!mulai)
    return ''

  return selesai && selesai !== mulai
    ? `${formatDate(mulai)} - ${formatDate(selesai)}`
    : formatDate(mulai)
})

/*
|--------------------------------------------------------------------------
| Mencatat pemesanan
|--------------------------------------------------------------------------
*/
const ARRANGEMENT_TYPES = ['PENGINAPAN', 'TIKET', 'TRANSPORT', 'LAINNYA']

const MAX_FILE_BYTES = 3 * 1024 * 1024

const ALLOWED_FILE_TYPES = ['application/pdf', 'image/jpeg', 'image/png']

const arrangeDialog = ref(false)
const arrangeSaving = ref(false)
const arrangeError = ref('')

const arrangeForm = ref({
  type: 'PENGINAPAN',
  vendor_name: '',
  reference_no: '',
  starts_at: '',
  ends_at: '',
  notes: '',
  replaces_id: null as number | null,
  files: [] as File[],
})

const arrangeTypeOptions = computed(() =>
  ARRANGEMENT_TYPES.map(kode => ({
    title: t(`businessTrip.arrangement.type.${kode}`),
    value: kode,
  })))

/*
| Yang boleh dipilih sebagai yang digantikan: pemesanan batal yang belum
| punya pengganti. Rantai yang bercabang tidak bisa dibaca lagi -- mana di
| antara dua penggantinya yang berlaku?
*/
const replaceableOptions = computed(() => {
  const semua = detailRow.value?.arrangements ?? []

  const sudahDigantikan = new Set(
    semua.map(baris => baris.replaces_id).filter(id => id !== null),
  )

  return semua
    .filter(baris => baris.status === 'DIBATALKAN' && !sudahDigantikan.has(baris.id))
    .map(baris => ({
      title: [
        t(`businessTrip.arrangement.type.${baris.type}`),
        baris.vendor_name,
        baris.reference_no,
      ].filter(Boolean).join(' · '),
      value: baris.id,
    }))
})

const openArrange = (): void => {
  arrangeForm.value = {
    type: 'PENGINAPAN',
    vendor_name: '',
    reference_no: '',
    starts_at: '',
    ends_at: '',
    notes: '',
    replaces_id: null,
    files: [],
  }

  arrangeError.value = ''
  arrangeDialog.value = true
}

/*
| Diperiksa di layar SELAIN di server, bukan sebagai gantinya. Tiga berkas
| tiga megabita yang ditolak di ujung berarti tiga unggahan yang terbuang
| dan tidak ada yang menjelaskan kenapa sampai semuanya selesai terkirim.
*/
const validateArrangement = (): boolean => {
  arrangeError.value = ''

  if (!arrangeForm.value.type) {
    arrangeError.value = t('businessTrip.arrangement.form.typeRequired')

    return false
  }

  if (!arrangeForm.value.files.length) {
    arrangeError.value = t('businessTrip.arrangement.form.filesRequired')

    return false
  }

  for (const berkas of arrangeForm.value.files) {
    if (berkas.size > MAX_FILE_BYTES) {
      arrangeError.value = t('businessTrip.arrangement.form.fileTooBig', { name: berkas.name })

      return false
    }

    if (!ALLOWED_FILE_TYPES.includes(berkas.type)) {
      arrangeError.value = t('businessTrip.arrangement.form.fileWrongType', { name: berkas.name })

      return false
    }
  }

  const { starts_at: mulai, ends_at: selesai } = arrangeForm.value

  if (mulai && selesai && selesai < mulai) {
    arrangeError.value = t('businessTrip.arrangement.form.endBeforeStart')

    return false
  }

  return true
}

const submitArrangement = async (): Promise<void> => {
  if (!detailRow.value || !validateArrangement())
    return

  arrangeSaving.value = true

  try {
    const data = new FormData()

    data.append('type', arrangeForm.value.type)

    /* Yang kosong tidak dikirim sama sekali -- string kosong bukan jawaban. */
    const opsional: Array<['vendor_name' | 'reference_no' | 'starts_at' | 'ends_at' | 'notes', string]> = [
      ['vendor_name', arrangeForm.value.vendor_name],
      ['reference_no', arrangeForm.value.reference_no],
      ['starts_at', arrangeForm.value.starts_at],
      ['ends_at', arrangeForm.value.ends_at],
      ['notes', arrangeForm.value.notes],
    ]

    for (const [kunci, nilai] of opsional) {
      if (nilai && nilai.trim())
        data.append(kunci, nilai.trim())
    }

    if (arrangeForm.value.replaces_id)
      data.append('replaces_id', String(arrangeForm.value.replaces_id))

    for (const berkas of arrangeForm.value.files)
      data.append('files[]', berkas)

    await axios.post(
      `/business-trip/perdin/${encodeURIComponent(detailRow.value.public_id)}/arrangements`,
      data,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    )

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('businessTrip.arrangement.form.saved'),
    })

    arrangeDialog.value = false

    await fetchArrangements(detailRow.value)
  }
  catch (error: unknown) {
    /*
    | Pesannya ditaruh DI DALAM dialognya, bukan sebagai toast. Formulirnya
    | masih terbuka dan masih berisi; pesan yang lewat di sudut layar akan
    | hilang sebelum yang mengisi selesai membaca apa yang harus diperbaiki.
    */
    arrangeError.value = getApiErrorMessage(
      error,
      t('businessTrip.arrangement.form.saveFailed'),
    )
  }
  finally {
    arrangeSaving.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Membatalkan pemesanan
|--------------------------------------------------------------------------
| Tanpa tombol hapus di mana pun, dan itu disengaja. Yang sudah dikabarkan
| ke yang berangkat tidak boleh lenyap tanpa jejak.
*/
const arrangeCancelDialog = ref(false)
const arrangeCancelRow = ref<ArrangementRow | null>(null)
const arrangeCancelNotes = ref('')
const arrangeCancelLoading = ref(false)

const openArrangeCancel = (baris: ArrangementRow): void => {
  arrangeCancelRow.value = baris
  arrangeCancelNotes.value = ''
  arrangeCancelDialog.value = true
}

const confirmArrangeCancel = async (): Promise<void> => {
  if (!detailRow.value || !arrangeCancelRow.value || !arrangeCancelNotes.value.trim())
    return

  arrangeCancelLoading.value = true

  try {
    await axios.patch(
      `/business-trip/perdin/${encodeURIComponent(detailRow.value.public_id)}`
      + `/arrangements/${arrangeCancelRow.value.id}/cancel`,
      { notes: arrangeCancelNotes.value.trim() },
    )

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('businessTrip.arrangement.cancel.done'),
    })

    arrangeCancelDialog.value = false

    await fetchArrangements(detailRow.value)
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('businessTrip.arrangement.cancel.failed')),
    })
  }
  finally {
    arrangeCancelLoading.value = false
  }
}

/** "05 Okt 2026 06:00 → 09 Okt 2026 22:00", dirakit dari empat kolom. */
const periodText = (row: TripRow): string => {
  const sisi = (tanggal?: string | null, jam?: string | null): string =>
    [formatDate(tanggal), jam].filter(Boolean).join(' ')

  return `${sisi(row.depart_date, row.depart_time)} → ${sisi(row.return_date, row.return_time)}`
}

/*
|--------------------------------------------------------------------------
| Export Excel
|--------------------------------------------------------------------------
| Isinya mengikuti daftar yang sedang tampil, termasuk saringannya, dan
| membawa rundown tiap perjalanan -- yang di layar hanya terlihat setelah
| rinciannya dibuka satu per satu.
|--------------------------------------------------------------------------
*/
const isExporting = ref(false)

const exportExcel = async (): Promise<void> => {
  if (isExporting.value)
    return

  isExporting.value = true

  try {
    showLoadingAlert(
      t('businessTrip.list.exportLoading'),
      t('common.alert.pleaseWait'),
    )

    const response = await axios.get('/business-trip/perdin/export-excel', {
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
      : 'Perjalanan_Dinas.xlsx'

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
      text: t('businessTrip.list.exportSuccess'),
    })
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: t('businessTrip.list.exportFailed'),
    })

    console.error('[Perdin] EXPORT ERROR:', error)
  }
  finally {
    isExporting.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Aksi
|--------------------------------------------------------------------------
*/
const goToCreate = (): void => {
  router.push('/business_trip/perdin/create')
}

const goToEdit = (row: TripRow): void => {
  router.push(`/business_trip/perdin/edit?id=${encodeURIComponent(row.public_id)}`)
}

/** Satu pembungkus untuk semua aksi: konfirmasi, loading, muat ulang. */
const runAction = async (
  row: TripRow,
  aksi: 'submit' | 'approve' | 'delete',
  body: Record<string, unknown> = {},
): Promise<void> => {
  const konfirmasi = await showConfirmAlert({
    title: t(`businessTrip.action.${aksi}.title`),
    text: t(`businessTrip.action.${aksi}.text`, { number: row.trip_number }),
    confirmButtonText: t(`businessTrip.action.${aksi}.confirm`),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!konfirmasi.isConfirmed)
    return

  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    const id = encodeURIComponent(row.public_id)

    if (aksi === 'delete')
      await axios.delete(`/business-trip/perdin/${id}`)
    else
      await axios.patch(`/business-trip/perdin/${id}/${aksi}`, body)

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: t(`businessTrip.action.${aksi}.success`),
    })

    detailDialog.value = false

    await fetchTrips()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t(`businessTrip.action.${aksi}.failed`)),
    })
  }
}

/*
| Penolakan wajib beralasan: pemohon harus tahu apa yang perlu diperbaiki,
| kalau tidak ia hanya bisa menebak lalu mengajukan hal yang sama lagi.
*/
const rejectDialog = ref(false)
const rejectRow = ref<TripRow | null>(null)
const rejectNotes = ref('')
const rejectLoading = ref(false)

const openReject = (row: TripRow): void => {
  rejectRow.value = row
  rejectNotes.value = ''
  rejectDialog.value = true
}

const confirmReject = async (): Promise<void> => {
  if (!rejectRow.value || !rejectNotes.value.trim())
    return

  rejectLoading.value = true

  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    await axios.patch(
      `/business-trip/perdin/${encodeURIComponent(rejectRow.value.public_id)}/reject`,
      { notes: rejectNotes.value.trim() },
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('businessTrip.action.reject.success'),
    })

    rejectDialog.value = false
    detailDialog.value = false

    await fetchTrips()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('businessTrip.action.reject.failed')),
    })
  }
  finally {
    rejectLoading.value = false
  }
}

/*
| Pembatalan wajib beralasan, sama seperti penolakan.
|
| Bedanya pembacanya: alasan penolakan dibaca pemohonnya, alasan pembatalan
| dibaca penyetuju yang dokumennya mendadak hilang dari daftar tugasnya.
| Keduanya sama-sama berhak tahu kenapa.
*/
const cancelDialog = ref(false)
const cancelRow = ref<TripRow | null>(null)
const cancelNotes = ref('')
const cancelLoading = ref(false)

const openCancel = (row: TripRow): void => {
  cancelRow.value = row
  cancelNotes.value = ''
  cancelDialog.value = true
}

const confirmCancel = async (): Promise<void> => {
  if (!cancelRow.value || !cancelNotes.value.trim())
    return

  cancelLoading.value = true

  try {
    showLoadingAlert(t('common.alert.processing'), t('common.alert.pleaseWait'))

    await axios.patch(
      `/business-trip/perdin/${encodeURIComponent(cancelRow.value.public_id)}/cancel`,
      { notes: cancelNotes.value.trim() },
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: t('businessTrip.action.cancel.success'),
    })

    cancelDialog.value = false
    detailDialog.value = false

    await fetchTrips()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('businessTrip.action.cancel.failed')),
    })
  }
  finally {
    cancelLoading.value = false
  }
}

/*
| Aksi mana yang pantas muncul untuk sebuah baris. Dihitung di satu tempat
| supaya menu dan tombol tidak pernah berbeda pendapat.
*/
/*
| Tombol di kaki dialog bekerja pada dokumen yang sedang dibuka. Dibungkus
| supaya keadaan kosongnya ditangani di satu tempat -- v-if pada template
| tidak ikut mempersempit tipe di dalam penangan klik.
*/
const approveDetail = (): void => {
  if (detailRow.value)
    runAction(detailRow.value, 'approve')
}

const rejectDetail = (): void => {
  if (detailRow.value)
    openReject(detailRow.value)
}

/*
|--------------------------------------------------------------------------
| Cetak
|--------------------------------------------------------------------------
| Jendelanya dibuka LEBIH DULU, diisi halaman tunggu, baru tautannya
| diminta. Urutan sebaliknya membuat browser menganggap jendela itu dibuka
| tanpa perintah pengguna, lalu memblokirnya.
|--------------------------------------------------------------------------
*/
const printLoadingId = ref<string | null>(null)

/** Cetakan hanya untuk dokumen yang sudah sah, dan hanya bagi pemegangnya. */
const canPrintRow = (row: TripRow): boolean =>
  abilities.value.can_print && row.status === 'APPROVED'

const printDocument = async (row: TripRow): Promise<void> => {
  if (!row.public_id || printLoadingId.value)
    return

  printLoadingId.value = row.public_id

  const loadingTitle = t('businessTrip.print.loading')
  const loadingText = t('common.alert.pleaseWait')

  let printWindow: Window | null = null

  try {
    showLoadingAlert(loadingTitle, loadingText)

    printWindow = window.open('', '_blank')

    if (!printWindow)
      throw new Error(t('businessTrip.print.popupBlocked'))

    printWindow.document.open()
    printWindow.document.write(buildPrintLoadingPage(loadingTitle, loadingText))
    printWindow.document.close()

    /*
     * Hanya meminta tautannya. Berkas PDF-nya diunduh browser saat
     * navigasi di bawah, jadi permintaan ini tidak ikut menunggu
     * cetakannya selesai dirakit.
     */
    const response = await axios.get(
      `/business-trip/perdin/${encodeURIComponent(row.public_id)}/print-url`,
      { headers: { Accept: 'application/json' } },
    )

    const printUrl = response.data?.url

    if (response.data?.success === false || typeof printUrl !== 'string' || !printUrl.trim())
      throw new Error(response.data?.message || t('businessTrip.print.failed'))

    if (printWindow.closed)
      throw new Error(t('businessTrip.print.windowClosed'))

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
        : getApiErrorMessage(error, t('businessTrip.print.failed')),
    })
  }
  finally {
    printLoadingId.value = null
  }
}

const printDetail = (): void => {
  if (detailRow.value)
    printDocument(detailRow.value)
}

const canSubmitRow = (row: TripRow): boolean =>
  abilities.value.can_submit && ['DRAFT', 'REJECTED'].includes(row.status)

const canEditRow = (row: TripRow): boolean =>
  abilities.value.can_update && row.is_editable

const canDeleteRow = (row: TripRow): boolean =>
  abilities.value.can_delete && row.status === 'DRAFT'

/*
| Batalkan hanya untuk yang SUDAH DIAJUKAN.
|
| Draft belum pernah dilihat siapa pun -- tidak ada yang perlu diberi tahu,
| dan tidak ada jejak yang perlu ditinggalkan. Untuk draft yang tersedia
| Hapus, dan keduanya sengaja tidak pernah muncul bersamaan: dua tombol yang
| terasa serupa pada satu baris hanya membuat orang menebak mana yang benar.
*/
const canCancelRow = (row: TripRow): boolean =>
  abilities.value.can_cancel && row.status === 'IN PROGRESS'

onMounted(() => {
  if (canView.value)
    fetchTrips()
})
</script>

<template>
  <section>
    <VAlert
      v-if="!canView"
      type="warning"
      variant="tonal"
      class="mb-4"
    >
      {{ t('businessTrip.list.noPermission') }}
    </VAlert>

    <template v-else>
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
                  {{ t('businessTrip.list.title') }}
                </div>

                <div class="text-body-2 text-medium-emphasis mt-1">
                  {{ t('businessTrip.list.subtitle') }}
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
              {{ t('common.actions.reset') }}
            </VBtn>
          </div>

          <VRow>
            <VCol
              cols="12"
              md="4"
            >
              <VTextField
                v-model="searchQuery"
                :label="t('businessTrip.list.searchLabel')"
                :placeholder="t('businessTrip.list.searchPlaceholder')"
                persistent-placeholder
                density="compact"
                prepend-inner-icon="tabler-search"
                clearable
                hide-details
              />
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <VSelect
                v-model="statusFilter"
                :label="t('businessTrip.columns.status')"
                :items="statusItems"
                item-title="title"
                item-value="value"
                density="compact"
                prepend-inner-icon="tabler-flag"
                clearable
                hide-details
              />
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <AppDateTimePicker
                v-model="departFrom"
                :label="t('businessTrip.list.departFrom')"
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
                v-model="departTo"
                :label="t('businessTrip.list.departTo')"
                density="compact"
                clearable
                :config="{ dateFormat: 'Y-m-d', position: 'below' }"
              />
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <div class="bt-approval-filter">
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
                    {{ t('businessTrip.list.onlyMyApproval') }}
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

      <!-- TABEL -->
      <VCard>
        <VCardText class="d-flex flex-wrap gap-4 align-center">
          <VBtn
            v-if="abilities.can_create"
            color="primary"
            prepend-icon="tabler-plus"
            class="text-none"
            @click="goToCreate"
          >
            {{ t('businessTrip.list.createButton') }}
          </VBtn>

          <!--
            Tombol mengikuti permission export tersendiri. Isi file selalu
            sama dengan daftar yang sedang tampil, termasuk saringannya.
          -->
          <VBtn
            v-if="abilities.can_export"
            color="success"
            variant="tonal"
            prepend-icon="tabler-file-spreadsheet"
            class="text-none"
            :loading="isExporting"
            :disabled="isExporting"
            @click="exportExcel"
          >
            {{ t('businessTrip.list.exportButton') }}
          </VBtn>

          <VSpacer />

          <VChip
            v-if="loading"
            size="small"
            variant="tonal"
          >
            {{ t('common.alert.processing') }}
          </VChip>

          <VBtn
            v-else-if="loadError"
            size="small"
            color="error"
            variant="tonal"
            prepend-icon="tabler-refresh"
            class="text-none"
            @click="fetchTrips"
          >
            {{ t('common.actions.refresh') }}
          </VBtn>
        </VCardText>

        <VDivider />

        <div class="bt-table-scroll">
          <VTable class="text-no-wrap">
            <thead>
              <tr>
                <th scope="col">
                  {{ t('businessTrip.columns.no') }}
                </th>
                <th scope="col">
                  {{ t('businessTrip.columns.number') }}
                </th>
                <th scope="col">
                  {{ t('businessTrip.columns.employee') }}
                </th>
                <th scope="col">
                  {{ t('businessTrip.columns.destination') }}
                </th>
                <th scope="col">
                  {{ t('businessTrip.columns.period') }}
                </th>
                <th scope="col">
                  {{ t('businessTrip.columns.duration') }}
                </th>
                <th scope="col">
                  {{ t('businessTrip.columns.status') }}
                </th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="(row, index) in visibleRows"
                :key="row.public_id"
                :class="{ 'bt-row-need-approval': row.can_approve }"
              >
                <td class="text-medium-emphasis">
                  {{ ((currentPage - 1) * rowPerPage) + Number(index) + 1 }}
                </td>

                <td>
                  <VMenu location="bottom start">
                    <template #activator="{ props }">
                      <div
                        v-bind="props"
                        class="bt-number-action d-inline-flex flex-column gap-1"
                      >
                        <div class="d-flex align-center gap-1 font-weight-medium text-primary">
                          <span>{{ row.trip_number || '-' }}</span>

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

                          {{ t('businessTrip.list.waitingMyApprovalBadge') }}
                        </VChip>

                        <!--
                          Menjelaskan kenapa FPU-nya sudah boleh dibuat atau masih
                          terkunci. Hanya muncul selagi persetujuannya berjalan --
                          setelah disetujui, keduanya sama saja.
                        -->
                        <VChip
                          v-if="row.status === 'IN PROGRESS'"
                          size="x-small"
                          :color="row.allows_parallel_cash_advance ? 'success' : 'secondary'"
                          variant="tonal"
                        >
                          <VIcon
                            :icon="row.allows_parallel_cash_advance ? 'tabler-lock-open' : 'tabler-lock'"
                            size="14"
                            start
                          />

                          {{ row.allows_parallel_cash_advance
                            ? t('businessTrip.list.fpuUnlockedBadge')
                            : t('businessTrip.list.fpuLockedBadge') }}
                        </VChip>

                        <!--
                          Tanggal berangkatnya sudah lewat sementara dokumennya
                          masih menggantung. Disebut di sini supaya tidak perlu
                          menekan Ajukan dulu untuk mengetahuinya.

                          Hanya pada status yang memang masih bisa diajukan --
                          pada perdin yang sudah berjalan, tanggal yang lewat
                          memang wajar.
                        -->
                        <VChip
                          v-if="row.is_departure_passed
                            && ['DRAFT', 'REJECTED'].includes(row.status)"
                          size="x-small"
                          color="error"
                          variant="tonal"
                        >
                          <VIcon
                            icon="tabler-calendar-x"
                            size="14"
                            start
                          />

                          {{ t('businessTrip.list.departurePassedBadge') }}

                          <VTooltip
                            activator="parent"
                            location="top"
                            max-width="320"
                          >
                            {{ t('businessTrip.list.departurePassedHint') }}
                          </VTooltip>
                        </VChip>
                      </div>
                    </template>

                    <VList density="compact">
                      <VListItem @click="openDetail(row)">
                        <template #prepend>
                          <VIcon
                            icon="tabler-eye"
                            size="18"
                          />
                        </template>

                        <VListItemTitle>{{ t('businessTrip.menu.detail') }}</VListItemTitle>
                      </VListItem>

                      <!--
                        Cetak baru muncul setelah dokumennya sah. Formulir yang
                        belum disetujui, begitu tercetak, tidak bisa dibedakan
                        dari yang sudah.
                      -->
                      <VListItem
                        v-if="canPrintRow(row)"
                        :disabled="printLoadingId === row.public_id"
                        @click="printDocument(row)"
                      >
                        <template #prepend>
                          <VIcon
                            icon="tabler-printer"
                            size="18"
                          />
                        </template>

                        <VListItemTitle>{{ t('businessTrip.menu.print') }}</VListItemTitle>
                      </VListItem>

                      <VListItem
                        v-if="canEditRow(row)"
                        @click="goToEdit(row)"
                      >
                        <template #prepend>
                          <VIcon
                            icon="tabler-pencil"
                            size="18"
                          />
                        </template>

                        <VListItemTitle>{{ t('businessTrip.menu.edit') }}</VListItemTitle>
                      </VListItem>

                      <VListItem
                        v-if="canSubmitRow(row)"
                        @click="runAction(row, 'submit')"
                      >
                        <template #prepend>
                          <VIcon
                            icon="tabler-send"
                            size="18"
                          />
                        </template>

                        <VListItemTitle>{{ t('businessTrip.menu.submit') }}</VListItemTitle>
                      </VListItem>

                      <VDivider v-if="row.can_approve" />

                      <VListItem
                        v-if="row.can_approve"
                        @click="runAction(row, 'approve')"
                      >
                        <template #prepend>
                          <VIcon
                            icon="tabler-check"
                            size="18"
                            color="success"
                          />
                        </template>

                        <VListItemTitle>{{ t('businessTrip.menu.approve') }}</VListItemTitle>
                      </VListItem>

                      <VListItem
                        v-if="row.can_approve"
                        @click="openReject(row)"
                      >
                        <template #prepend>
                          <VIcon
                            icon="tabler-x"
                            size="18"
                            color="error"
                          />
                        </template>

                        <VListItemTitle>{{ t('businessTrip.menu.reject') }}</VListItemTitle>
                      </VListItem>

                      <VDivider v-if="canCancelRow(row) || canDeleteRow(row)" />

                      <VListItem
                        v-if="canCancelRow(row)"
                        @click="openCancel(row)"
                      >
                        <template #prepend>
                          <VIcon
                            icon="tabler-ban"
                            size="18"
                          />
                        </template>

                        <VListItemTitle>{{ t('businessTrip.menu.cancel') }}</VListItemTitle>
                      </VListItem>

                      <VListItem
                        v-if="canDeleteRow(row)"
                        @click="runAction(row, 'delete')"
                      >
                        <template #prepend>
                          <VIcon
                            icon="tabler-trash"
                            size="18"
                            color="error"
                          />
                        </template>

                        <VListItemTitle>{{ t('businessTrip.menu.delete') }}</VListItemTitle>
                      </VListItem>
                    </VList>
                  </VMenu>
                </td>

                <td>
                  <div class="font-weight-medium">
                    {{ row.employee_name }}
                  </div>

                  <div class="text-caption text-medium-emphasis">
                    {{ [row.department_name, row.position_name].filter(Boolean).join(' · ') || '-' }}
                  </div>
                </td>

                <td>{{ row.destination }}</td>

                <td>{{ periodText(row) }}</td>

                <td>{{ t('businessTrip.days', { count: row.duration_days }) }}</td>

                <td>
                  <VChip
                    size="small"
                    variant="tonal"
                    :color="statusColor(row.status)"
                  >
                    {{ t(`businessTrip.status.${row.status}`) }}
                  </VChip>
                </td>
              </tr>

              <tr v-if="!loading && !visibleRows.length">
                <td
                  colspan="7"
                  class="text-center text-medium-emphasis py-6"
                >
                  {{ t('businessTrip.list.empty') }}
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>

        <VDivider />

        <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
          <div class="text-body-2 text-medium-emphasis">
            {{ t('businessTrip.list.total', { count: totalData }) }}
          </div>

          <VPagination
            v-model="currentPage"
            :length="totalPage"
            :total-visible="5"
          />
        </div>
      </VCard>
    </template>

    <!--
      RINCIAN

      Tiga hal yang dicari pembacanya diberi bentuknya masing-masing:
      identitas perjalanan di kepala, rencana hari demi hari di tengah, dan
      jalannya persetujuan di bawah.
    -->
    <VDialog
      v-model="detailDialog"
      max-width="1080"
      scrollable
    >
      <!--
        Kartunya satu komponen bersama dengan detail FPU. Yang berbeda di
        sini hanya tombol aksinya, dan itu yang dititipkan lewat slot.
      -->
      <BusinessTripDetailCard
        v-if="detailRow"
        :trip="detailRow"
        @close="detailDialog = false"
      >
        <!--
          Tombol Catat, di kepala bagian pemesanannya sendiri. Kartunya tidak
          tahu siapa yang boleh mencatat, dan memang tidak perlu tahu -- ia
          dipakai juga di detail FPU, tempat tombol ini tidak pernah muncul.
        -->
        <template #arrangement-actions>
          <VBtn
            v-if="canArrangeCurrent"
            size="small"
            color="primary"
            variant="tonal"
            prepend-icon="tabler-plus"
            class="text-none"
            :loading="arrangementLoading"
            @click="openArrange"
          >
            {{ t('businessTrip.arrangement.manage.add') }}
          </VBtn>
        </template>

        <!-- Dan Batalkan, satu per baris yang masih berlaku -->
        <template #arrangement-row="{ arrangement }">
          <VBtn
            v-if="canArrangeCurrent && arrangement.status !== 'DIBATALKAN'"
            size="x-small"
            color="error"
            variant="text"
            class="text-none"
            @click="openArrangeCancel(arrangement)"
          >
            {{ t('businessTrip.arrangement.cancel.action') }}
          </VBtn>
        </template>

        <template #actions>
          <VBtn
            v-if="canPrintRow(detailRow)"
            color="primary"
            variant="tonal"
            prepend-icon="tabler-printer"
            class="text-none"
            :loading="printLoadingId === detailRow.public_id"
            @click="printDetail"
          >
            {{ t('businessTrip.menu.print') }}
          </VBtn>

          <VBtn
            v-if="detailRow.can_approve"
            color="error"
            variant="tonal"
            class="text-none"
            @click="rejectDetail"
          >
            {{ t('businessTrip.menu.reject') }}
          </VBtn>

          <VBtn
            v-if="detailRow.can_approve"
            color="success"
            class="text-none"
            @click="approveDetail"
          >
            {{ t('businessTrip.menu.approve') }}
          </VBtn>
        </template>
      </BusinessTripDetailCard>
    </VDialog>
    <!--
      CATAT PEMESANAN

      Satu jenis per pemesanan, supaya tiap pemesanan bisa dibatalkan
      sendiri-sendiri: hotel batal bukan berarti tiketnya ikut batal.
    -->
    <VDialog
      v-model="arrangeDialog"
      max-width="640"
      scrollable
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('businessTrip.arrangement.form.title') }}
        </VCardTitle>

        <VDivider />

        <VCardText>
          <!--
            Jadwal perjalanannya sebagai pegangan, bukan sebagai batas.
            Tanggal di luarnya tidak diblokir -- menginap semalam sebelum
            penerbangan pagi itu lumrah, begitu pula pulang sehari sesudahnya.
          -->
          <VAlert
            v-if="tripPeriodText"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            <div class="font-weight-medium">
              {{ t('businessTrip.arrangement.manage.period', { period: tripPeriodText }) }}
            </div>

            <div class="text-caption">
              {{ t('businessTrip.arrangement.manage.periodHint') }}
            </div>
          </VAlert>

          <VAlert
            v-if="arrangeError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ arrangeError }}
          </VAlert>

          <VRow>
            <VCol cols="12">
              <VSelect
                v-model="arrangeForm.type"
                :items="arrangeTypeOptions"
                :label="t('businessTrip.arrangement.form.type')"
                :hint="t('businessTrip.arrangement.form.typeHint')"
                persistent-hint
                density="comfortable"
              />
            </VCol>

            <VCol
              cols="12"
              md="7"
            >
              <VTextField
                v-model="arrangeForm.vendor_name"
                :label="`${t('businessTrip.arrangement.form.vendor')} (${t('businessTrip.arrangement.form.optional')})`"
                :placeholder="t('businessTrip.arrangement.form.vendorHint')"
                density="comfortable"
              />
            </VCol>

            <VCol
              cols="12"
              md="5"
            >
              <VTextField
                v-model="arrangeForm.reference_no"
                :label="`${t('businessTrip.arrangement.form.reference')} (${t('businessTrip.arrangement.form.optional')})`"
                density="comfortable"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <AppDateTimePicker
                v-model="arrangeForm.starts_at"
                :label="`${t('businessTrip.arrangement.form.starts')} (${t('businessTrip.arrangement.form.optional')})`"
                density="comfortable"
                clearable
                :config="{ dateFormat: 'Y-m-d', position: 'below' }"
              />
            </VCol>

            <VCol
              cols="12"
              md="6"
            >
              <AppDateTimePicker
                v-model="arrangeForm.ends_at"
                :label="`${t('businessTrip.arrangement.form.ends')} (${t('businessTrip.arrangement.form.optional')})`"
                density="comfortable"
                clearable
                :config="{ dateFormat: 'Y-m-d', position: 'below' }"
              />
            </VCol>

            <!-- Muncul hanya bila memang ada yang bisa digantikan -->
            <VCol
              v-if="replaceableOptions.length"
              cols="12"
            >
              <VSelect
                v-model="arrangeForm.replaces_id"
                :items="replaceableOptions"
                :label="t('businessTrip.arrangement.form.replaces')"
                :hint="t('businessTrip.arrangement.form.replacesHint')"
                :placeholder="t('businessTrip.arrangement.form.replacesNone')"
                persistent-hint
                clearable
                density="comfortable"
              />
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="arrangeForm.notes"
                :label="`${t('businessTrip.arrangement.form.notes')} (${t('businessTrip.arrangement.form.optional')})`"
                rows="2"
                density="comfortable"
              />
            </VCol>

            <VCol cols="12">
              <VFileInput
                v-model="arrangeForm.files"
                :label="t('businessTrip.arrangement.form.files')"
                :hint="t('businessTrip.arrangement.form.filesHint')"
                persistent-hint
                multiple
                chips
                accept=".pdf,.jpg,.jpeg,.png"
                prepend-icon=""
                prepend-inner-icon="tabler-paperclip"
                density="comfortable"
              />
            </VCol>
          </VRow>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            :disabled="arrangeSaving"
            @click="arrangeDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="primary"
            class="text-none"
            :loading="arrangeSaving"
            @click="submitArrangement"
          >
            {{ t('businessTrip.arrangement.form.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      BATALKAN PEMESANAN

      Yang dibatalkan tidak hilang, dan itu dikatakan terus terang di sini --
      supaya yang menekannya tidak mengira ia sedang menghapus sesuatu.
    -->
    <VDialog
      v-model="arrangeCancelDialog"
      max-width="520"
    >
      <VCard v-if="arrangeCancelRow">
        <VCardTitle class="text-h6 font-weight-bold">
          {{
            t('businessTrip.arrangement.cancel.title', {
              type: t(`businessTrip.arrangement.type.${arrangeCancelRow.type}`),
            })
          }}
        </VCardTitle>

        <VDivider />

        <VCardText>
          <VAlert
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ t('businessTrip.arrangement.cancel.warning') }}
          </VAlert>

          <div class="text-body-2 text-medium-emphasis mb-3">
            {{ t('businessTrip.arrangement.cancel.notesHint') }}
          </div>

          <VTextarea
            v-model="arrangeCancelNotes"
            :label="t('businessTrip.arrangement.cancel.notes')"
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
            :disabled="arrangeCancelLoading"
            @click="arrangeCancelDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="error"
            class="text-none"
            :loading="arrangeCancelLoading"
            :disabled="!arrangeCancelNotes.trim()"
            @click="confirmArrangeCancel"
          >
            {{ t('businessTrip.arrangement.cancel.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- TOLAK: wajib beralasan -->
    <VDialog
      v-model="rejectDialog"
      max-width="520"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('businessTrip.action.reject.title') }}
        </VCardTitle>

        <VDivider />

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-3">
            {{ t('businessTrip.action.reject.notesHint') }}
          </div>

          <VTextarea
            v-model="rejectNotes"
            :label="t('businessTrip.detail.notes')"
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
            :disabled="rejectLoading"
            @click="rejectDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="error"
            class="text-none"
            :loading="rejectLoading"
            :disabled="!rejectNotes.trim()"
            @click="confirmReject"
          >
            {{ t('businessTrip.action.reject.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      BATALKAN

      Bentuknya sama persis dengan dialog Tolak, karena pertanyaannya sama:
      satu kalimat wajib, dan tombolnya mati sampai kalimat itu diisi.
    -->
    <VDialog
      v-model="cancelDialog"
      max-width="520"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('businessTrip.action.cancel.title') }}
        </VCardTitle>

        <VDivider />

        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-3">
            {{ t('businessTrip.action.cancel.notesHint') }}
          </div>

          <VTextarea
            v-model="cancelNotes"
            :label="t('businessTrip.action.cancel.notesLabel')"
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
            :disabled="cancelLoading"
            @click="cancelDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="error"
            class="text-none"
            :loading="cancelLoading"
            :disabled="!cancelNotes.trim()"
            @click="confirmCancel"
          >
            {{ t('businessTrip.action.cancel.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>
/* Tabelnya lebar; yang boleh menggeser hanya tabelnya, bukan seluruh halaman. */

.bt-table-scroll {
  overflow-x: auto;
}

.bt-number-action {
  cursor: pointer;
}

/*
 * Baris yang menunggu tanda tangan si pembuka.
 *
 * Latarnya yang menandai, bukan hanya garis tepinya -- garis setebal tiga
 * piksel di ujung kiri tabel lebar mudah terlewat, apalagi setelah tabelnya
 * digeser ke samping. Warnanya sama dengan PR, PO, FPU, Realisasi, dan Claim,
 * supaya satu warna berarti satu hal di seluruh aplikasi.
 */
.bt-row-need-approval {
  border-inline-start: 3px solid rgb(var(--v-theme-warning));
  background: rgba(var(--v-theme-warning), 0.06);
}

.bt-row-need-approval:hover {
  background: rgba(var(--v-theme-warning), 0.09);
}

.bt-approval-filter {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  block-size: 100%;
  padding-block: 4px;
  padding-inline: 12px;
}
</style>
