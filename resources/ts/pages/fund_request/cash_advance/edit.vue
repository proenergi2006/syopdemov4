<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showWarningToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { formatSanitizedNumberInput } from '@/utils/textFormatter'
import { usePermissionStore } from '@/stores/permission'
import { useDialogDatePicker } from '@core/composable/useDialogDatePicker'

interface CashAdvanceItemForm {

  /** Null untuk baris baru; diisi supaya baris lama diperbarui, bukan diganti. */
  id: number | null

  date: string | null
  description: string
  amount: number

  /*
   * Bukti melekat pada baris. attachments adalah berkas yang baru dipilih,
   * existing adalah yang sudah tersimpan -- keduanya dihitung bersama saat
   * memeriksa kewajiban minimal satu bukti per baris.
   */
  attachments: File[]
  existing: ExistingAttachment[]
}

interface ExistingAttachment {
  id: number
  original_filename: string | null
  filename: string
  mime_type: string | null
  file_size: number | null
  url: string | null
}

interface BranchOption {
  id: number
  value: number
  title: string
}

interface DepartmentOption {
  id: number
  label: string
}

const route = useRoute()
const router = useRouter()
const permissionStore = usePermissionStore()
const { t } = useI18n()

const publicId = ref<string>(String(route.query.id || ''))

const isCheckingPermission = ref(true)
const isLoading = ref(true)
const isSubmitted = ref(false)

/*
| Kalender tanggal pada baris rincian berada di dalam dialog layar penuh.
|
| Di sana flatpickr memposisikan kalendernya dengan koordinat dokumen,
| sementara dialog mengunci gulir halaman dengan menggeser <html> -- dan
| kalendernya mendarat menutupi field-nya sendiri. Pemosisi ini memakai
| koordinat layar, yang tidak ikut bergeser.
*/
const { dialogDateConfig } = useDialogDatePicker()

/*
| Batas tanggal baris rincian menyusul perdin yang dipilih -- diisi di
| bawah, setelah daftar perdinnya ada. Untuk FPU biasa tidak ada batas
| sama sekali: pengeluarannya tidak terikat periode apa pun.
*/
const lineDateRange = ref<{ minDate?: string; maxDate?: string }>({})

const lineDateConfig = computed(() => dialogDateConfig(lineDateRange.value))
const isSaving = ref(false)

const MAX_FILE_SIZE = 3 * 1024 * 1024
const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png']

const branchList = ref<BranchOption[]>([])
const departmentList = ref<DepartmentOption[]>([])
const departmentsByBranch = ref<Record<string, DepartmentOption[]>>({})

const isLoadingBranch = ref(false)
const isLoadingDepartment = ref(false)

const attachmentError = ref('')

const advanceNumber = ref('-')
const existingAttachments = ref<ExistingAttachment[]>([])
const deletedAttachmentIds = ref<number[]>([])

const canUpdate = computed(() => permissionStore.can('cash_advance.update'))

const createEmptyItem = (): CashAdvanceItemForm => ({
  id: null,
  date: null,
  description: '',
  amount: 0,
  attachments: [],
  existing: [],
})

const form = reactive({
  date: '',
  branch: null as number | null,
  department_id: null as number | null,

  /*
   * Keterangan transaksi ikut menentukan approval flow, jadi wajib diisi.
   */
  transaction_category_id: null as number | null,

  /* Dokumen Perdin yang mendasari FPU ini; hanya terisi bila diwajibkan. */
  business_trip_id: null as number | null,

  // Rutin / Non Rutin, mengikuti tipe PR.
  request_type: null as string | null,

  subject: '',
  notes: '',
  items: [createEmptyItem()] as CashAdvanceItemForm[],
})

const requestTypeList = ['Rutin', 'Non Rutin']

interface TransactionCategoryOption {
  id: number
  title: string
}

const transactionCategoryList = ref<TransactionCategoryOption[]>([])
const isLoadingTransactionCategory = ref(false)

/*
 * Nama kategori yang tersimpan pada dokumen. Dipakai hanya bila kategori itu
 * sudah di luar cakupan approval flow, supaya pilihannya tetap terbaca dan
 * tidak berubah jadi sekadar angka.
 */
const savedTransactionCategoryName = ref<string>('')

/*
 * Apakah cabang dan department ini punya approval flow FPU.
 *
 * null berarti belum diketahui -- cabang atau department-nya belum dipilih,
 * jadi belum ada yang bisa ditanyakan ke server.
 */
const hasApprovalFlow = ref<boolean | null>(null)

/*
|--------------------------------------------------------------------------
| Tautan ke Perjalanan Dinas
|--------------------------------------------------------------------------
| Keterangan transaksi tertentu -- perjalanan dinas -- mensyaratkan FPU
| menunjuk dokumen Perdin yang sudah disetujui. Yang mana saja, ditentukan
| master, bukan daftar yang ditanam di sini.
|--------------------------------------------------------------------------
*/
interface BusinessTripOption {
  id: number
  trip_number: string
  destination: string
  depart_date: string | null
  return_date: string | null

  /*
   * Perdin yang diajukan tepat waktu boleh dipakai walau persetujuannya
   * belum selesai. Keadaannya harus terbaca di daftar pilihan -- kalau
   * tidak, pemohon mengira dananya pasti cair, lalu bingung saat ditahan.
   */
  status: string
  is_on_time: boolean

  /*
   * Yang belum boleh dipakai tetap ditampilkan -- terkunci, dengan
   * keterangan sedang menunggu siapa. Daftar kosong tidak menjelaskan
   * apa pun: pemohon tidak tahu perdinnya belum dibuat, belum diajukan,
   * atau sudah diajukan dan masih menunggu.
   */
  selectable: boolean
  waiting_step: { step_order: number; label: string | null; approver: string | null } | null
}

const businessTripList = ref<BusinessTripOption[]>([])
const isLoadingBusinessTrip = ref(false)

/** Perdin yang sedang dipilih, bila memang ada. */
const selectedBusinessTrip = computed<BusinessTripOption | null>(() =>
  businessTripList.value.find(
    item => Number(item.id) === Number(form.business_trip_id),
  ) ?? null)

/** Keterangan pendek kenapa sebuah perdin belum bisa dipilih. */
const businessTripLockReason = (item: BusinessTripOption): string => {
  if (item.selectable)
    return ''

  if (!item.waiting_step)
    return t('cashAdvance.form.businessTripState.locked')

  return t('cashAdvance.form.businessTripState.waitingStep', {
    step: item.waiting_step.step_order,
    who: item.waiting_step.approver
      || item.waiting_step.label
      || t('cashAdvance.form.businessTripState.someone'),
  })
}

/**
 * Perdin yang dipilih masih menunggu persetujuan.
 *
 * Dipakai memberi tahu SEKARANG bahwa dananya belum bisa cair sampai
 * perjalanannya diizinkan -- bukan nanti, saat Finance menahannya dan
 * pemohon sudah menunggu.
 */
const selectedBusinessTripPending = computed<boolean>(() => {
  const dipilih = businessTripList.value.find(
    item => Number(item.id) === Number(form.business_trip_id),
  )

  return !!dipilih && dipilih.status !== 'APPROVED'
})

/** Keterangan transaksi yang sedang dipilih menuntut dokumen Perdin. */
const needsBusinessTrip = computed<boolean>(() => {
  const dipilih = transactionCategoryList.value.find(
    (item: any) => Number(item.id) === Number(form.transaction_category_id),
  )

  return Boolean((dipilih as any)?.requires_business_trip)
})

/*
| Tanggal baris rincian mengikuti periode perjalanannya.
|
| Biaya sebuah perjalanan tidak mungkin bertanggal di luar perjalanan itu.
| Dibatasi di kalendernya, bukan hanya ditolak setelah dikirim -- tanggal
| yang salah sebaiknya tidak pernah sempat dipilih.
*/
watch(
  [selectedBusinessTrip, needsBusinessTrip],
  () => {
    const trip = needsBusinessTrip.value ? selectedBusinessTrip.value : null

    lineDateRange.value = {
      minDate: trip?.depart_date || undefined,
      maxDate: trip?.return_date || undefined,
    }
  },
  { immediate: true },
)

/**
 * Perdin yang boleh dipakai: milik sendiri, sudah disetujui, dan belum
 * dipegang FPU lain yang masih berjalan.
 *
 * Daftarnya disusun server dengan aturan yang sama persis yang dipakai
 * memeriksa kiriman ini nanti -- kalau disusun dengan aturan sendiri, layar
 * akan menawarkan sesuatu yang lalu ditolak.
 */
const loadBusinessTrips = async (): Promise<void> => {
  if (!needsBusinessTrip.value) {
    businessTripList.value = []
    form.business_trip_id = null

    return
  }

  isLoadingBusinessTrip.value = true

  try {
    const response = await axios.get('/business-trip/perdin/eligible', {
      params: publicId.value ? { cash_advance_public_id: publicId.value } : {},
    })

    businessTripList.value = Array.isArray(response?.data?.data)
      ? response.data.data
      : []
  }
  catch (error: unknown) {
    businessTripList.value = []
  }
  finally {
    isLoadingBusinessTrip.value = false
  }
}

/* Daftarnya diminta ulang setiap keterangan transaksinya berganti. */
watch(() => form.transaction_category_id, () => {
  loadBusinessTrips()
})

/**
 * Menyelaraskan pilihan yang sedang aktif dengan daftar yang baru.
 *
 * Saat cabang atau department diganti, pilihan yang tidak lagi tersedia
 * dikosongkan. Saat memuat dokumen tersimpan, pilihan lamanya justru
 * dipertahankan -- ditambahkan ke daftar bila perlu, supaya tetap terbaca
 * dan bisa diganti sendiri oleh user.
 */
const syncSelectedTransactionCategory = (preserveSelected: boolean): void => {
  const selectedId = form.transaction_category_id

  if (!selectedId)
    return

  const tersedia = transactionCategoryList.value.some(
    item => Number(item.id) === Number(selectedId),
  )

  if (tersedia)
    return

  if (!preserveSelected) {
    form.transaction_category_id = null

    return
  }

  transactionCategoryList.value = [
    ...transactionCategoryList.value,
    {
      id: Number(selectedId),
      title: savedTransactionCategoryName.value || String(selectedId),
    },
  ]
}

/*
 * Keterangan transaksi yang boleh dipilih ditentukan oleh approval flow FPU
 * milik cabang dan department pemohon -- matriks yang sama yang menentukan
 * siapa approver-nya. Karena itu daftarnya diminta ulang setiap kali salah
 * satu dari keduanya berubah, bukan sekali saat halaman dibuka.
 *
 * preserveSelected dipakai saat memuat dokumen yang sudah tersimpan: pilihan
 * lamanya dipertahankan walau kini di luar cakupan flow, supaya isian yang
 * tidak pernah disentuh user tidak hilang diam-diam.
 */
const loadTransactionCategories = async (preserveSelected = false): Promise<void> => {
  if (!form.branch || !form.department_id) {
    transactionCategoryList.value = []
    hasApprovalFlow.value = null

    if (!preserveSelected)
      form.transaction_category_id = null

    return
  }

  isLoadingTransactionCategory.value = true

  try {
    const response = await axios.get(
      '/fund-request/transaction-categories/dropdown-select',
      {
        headers: { Accept: 'application/json' },

        /*
         * Satu master menampung daftar FPU dan Claim sekaligus, jadi
         * modulnya harus disebut supaya keduanya tidak tercampur.
         *
         * Cabang dan department dipakai server untuk menyaringnya menurut
         * approval flow yang berlaku.
         */
        params: {
          document_type: 'ADVANCE',
          branch_id: form.branch,
          department_id: form.department_id,
        },
      },
    )

    const data = Array.isArray(response?.data?.data) ? response.data.data : []

    transactionCategoryList.value = data.map((item: any) => ({
      id: Number(item.id),
      title: String(item.title || item.name || '-'),

      /* Menentukan perlu-tidaknya dokumen Perdin ditautkan. */
      requires_business_trip: Boolean(item.requires_business_trip),
    }))

    hasApprovalFlow.value = response?.data?.meta?.has_matching_flow ?? null

    syncSelectedTransactionCategory(preserveSelected)
  }
  catch (error: unknown) {
    console.error('[Cash Advance] TRANSACTION CATEGORY ERROR:', error)
    transactionCategoryList.value = []
    hasApprovalFlow.value = null
  }
  finally {
    isLoadingTransactionCategory.value = false
  }
}

const required = (value: unknown): boolean =>
  value !== '' && value !== null && value !== undefined

const formatMoney = (value: number | null | undefined): string => {
  if (!value)
    return ''

  return new Intl.NumberFormat('id-ID').format(Number(value))
}

const getExtension = (fileName: string): string =>
  fileName.split('.').pop()?.toLowerCase() || ''

const totalAmount = computed(() =>
  form.items.reduce((total, item) => total + Number(item.amount || 0), 0),
)

const updateDepartmentListByBranch = (
  branchId: number | null,
  preserveSelected = false,
): void => {
  if (!branchId) {
    departmentList.value = []

    if (!preserveSelected)
      form.department_id = null

    return
  }

  departmentList.value = departmentsByBranch.value[String(branchId)] || []

  const stillAvailable = departmentList.value.some(
    department => Number(department.id) === Number(form.department_id),
  )

  if (!preserveSelected || (form.department_id && !stillAvailable))
    form.department_id = null
}

const fetchAccessAssignments = async (showAlert = true): Promise<void> => {
  isLoadingBranch.value = true
  isLoadingDepartment.value = true

  try {
    const response = await axios.get('/account/access-assignments', {
      headers: { Accept: 'application/json' },
    })

    const payload = response.data?.data || {}

    branchList.value = Array.isArray(payload.branches)
      ? payload.branches.map((item: any) => ({
        id: Number(item.id),
        value: Number(item.id),
        title: item.title
            || (item.code ? `${item.code} - ${item.name}` : item.name || '-'),
      }))
      : []

    const rawDepartments = payload.departments_by_branch || {}
    const normalized: Record<string, DepartmentOption[]> = {}

    Object.entries(rawDepartments).forEach(([branchId, departments]) => {
      normalized[String(branchId)] = Array.isArray(departments)
        ? departments.map((item: any) => ({
          id: Number(item.id),
          label: item.title
              || (item.code ? `${item.code} - ${item.name}` : item.name || '-'),
        }))
        : []
    })

    departmentsByBranch.value = normalized

    updateDepartmentListByBranch(form.branch, true)
  }
  catch (error: unknown) {
    console.error('[Cash Advance] ACCESS ASSIGNMENT ERROR:', error)

    branchList.value = []
    departmentList.value = []
    departmentsByBranch.value = {}

    if (showAlert) {
      showErrorToast({
        title: t('common.alert.error'),
        text: getApiErrorMessage(error, t('cashAdvance.form.toast.loadAccessFailed')),
      })
    }
  }
  finally {
    isLoadingBranch.value = false
    isLoadingDepartment.value = false
  }
}

const loadCashAdvance = async (): Promise<void> => {
  isLoading.value = true

  try {
    const response = await axios.get(
      `/fund-request/cash-advance/${publicId.value}/edit`,
      { headers: { Accept: 'application/json' } },
    )

    const data = response.data?.data

    if (!data) {
      showErrorToast({
        title: t('common.alert.error'),
        text: t('cashAdvance.form.toast.loadFailed'),
      })

      await router.replace('/fund_request/cash_advance')

      return
    }

    advanceNumber.value = data.advance_number || '-'

    form.date = data.date || ''
    form.branch = data.branch !== null && data.branch !== undefined
      ? Number(data.branch)
      : null
    form.department_id = data.department_id ? Number(data.department_id) : null

    form.transaction_category_id = data.transaction_category_id
      ? Number(data.transaction_category_id)
      : null

    savedTransactionCategoryName.value = String(data.transaction_category || '')

    /*
     * Tautan perdin-nya ikut dimuat, kalau tidak menyimpan ulang dokumen
     * yang tidak disentuh justru akan memutus tautannya.
     */
    form.business_trip_id = data.business_trip_id
      ? Number(data.business_trip_id)
      : null

    form.request_type = data.request_type || null

    form.subject = data.subject || ''
    form.notes = data.notes || ''

    const mapAttachment = (item: any): ExistingAttachment => ({
      id: Number(item.id),
      original_filename: item.original_filename || null,
      filename: item.filename || '',
      mime_type: item.mime_type || null,
      file_size: item.file_size !== null && item.file_size !== undefined
        ? Number(item.file_size)
        : null,
      url: item.url || null,
    })

    form.items = Array.isArray(data.items) && data.items.length
      ? data.items.map((item: any) => ({
        id: item.id ? Number(item.id) : null,
        date: item.date || null,
        description: item.description || '',
        amount: Number(item.amount || 0),
        attachments: [] as File[],
        existing: Array.isArray(item.attachments)
          ? item.attachments.map(mapAttachment)
          : [],
      }))
      : [createEmptyItem()]

    /*
     * Lampiran tingkat dokumen hanya tersisa pada FPU lama yang dibuat sebelum
     * bukti dipindah ke tingkat baris. Tetap ditampilkan supaya tidak hilang.
     */
    existingAttachments.value = Array.isArray(data.attachments)
      ? data.attachments.map((item: any) => ({
        id: Number(item.id),
        original_filename: item.original_filename || null,
        filename: item.filename || '',
        mime_type: item.mime_type || null,
        file_size: item.file_size !== null && item.file_size !== undefined
          ? Number(item.file_size)
          : null,
        url: item.url || null,
      }))
      : []

    updateDepartmentListByBranch(form.branch, true)
  }
  catch (error: unknown) {
    console.error('[Cash Advance] LOAD ERROR:', error)

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvance.form.toast.loadFailed')),
    })

    await router.replace('/fund_request/cash_advance')
  }
  finally {
    isLoading.value = false
  }
}

const handleBranchChange = async (): Promise<void> => {
  updateDepartmentListByBranch(form.branch, false)

  await loadTransactionCategories()
}

/* Department ikut menentukan daftar keterangan transaksinya. */
const handleDepartmentChange = async (): Promise<void> => {
  await loadTransactionCategories()
}

/*
 * Rincian disunting di modal layar penuh, mengikuti pola Purchase Requisition.
 * Halaman utama hanya menampilkan ringkasannya sebagai teks.
 */
const itemDialog = ref(false)
const itemDialogSaved = ref(false)
const confirmCloseItemDialog = ref(false)
const tempItems = ref<CashAdvanceItemForm[]>([])

/*
 * Penghapusan bukti lama baru dicatat saat modal disimpan. Kalau langsung
 * dicatat, membatalkan modal akan meninggalkan id yang tetap terhapus di server
 * padahal berkasnya kembali tampil.
 */
const tempDeletedIds = ref<number[]>([])

/*
 * Salinan dangkal, bukan JSON clone: baris membawa objek File yang tidak
 * selamat melewati serialisasi JSON.
 */
const cloneItems = (items: CashAdvanceItemForm[]): CashAdvanceItemForm[] =>
  items.map(item => ({
    ...item,
    attachments: [...item.attachments],
    existing: [...item.existing],
  }))

/*
| Satu tanggal di luar periode perjalanan.
|
| Dipakai baris demi baris, dan dihitung ulang setiap kali periodenya
| berubah -- keterangan transaksinya bisa diganti BELAKANGAN, setelah
| rinciannya terisi, sehingga baris yang tadinya sah bisa mendadak
| berada di luar periode.
*/
const dateOutsideTrip = (value: unknown): boolean => {
  const { minDate, maxDate } = lineDateRange.value

  if (!minDate || !maxDate)
    return false

  const tanggal = String(value ?? '').trim()

  return tanggal !== '' && (tanggal < minDate || tanggal > maxDate)
}

/** Pesan pada kolom tanggal sebuah baris, bila memang ada. */
const itemDateErrors = (item: { date?: string | null }): string[] => {
  if (dateOutsideTrip(item.date))
    return [t('cashAdvance.form.validation.itemDateOutsideTrip')]

  if (isSubmitted.value && !item.date)
    return [t('cashAdvance.form.validation.itemDate')]

  return []
}

/** Nomor baris yang tanggalnya di luar periode perjalanan. */
const itemsOutsideTripRange = computed<number[]>(() =>
  form.items.reduce<number[]>((baris, item, index) => {
    if (dateOutsideTrip(item.date))
      baris.push(index + 1)

    return baris
  }, []))

/** Kalimatnya menyebut baris keberapa, perdin mana, dan periodenya. */
const outsideTripMessage = (rows: number[], key: string): string =>
  t(key, {
    rows: rows.join(', '),
    number: selectedBusinessTrip.value?.trip_number ?? '-',
    from: lineDateRange.value.minDate ?? '-',
    to: lineDateRange.value.maxDate ?? '-',
    action: t('cashAdvance.form.items.addButton'),
  })

/*
| Isian kepala dokumen yang masih kosong.
|
| Urutannya mengikuti urutan di layar, supaya yang membacanya bisa
| menelusuri formulirnya dari atas ke bawah.
*/
const missingHeaderFields = computed<string[]>(() => {
  const kurang: string[] = []

  if (!required(form.branch))
    kurang.push(t('cashAdvance.form.fields.branch'))

  if (!required(form.department_id))
    kurang.push(t('cashAdvance.form.fields.department'))

  if (!required(form.request_type))
    kurang.push(t('cashAdvance.form.fields.requestType'))

  if (!required(form.transaction_category_id))
    kurang.push(t('cashAdvance.form.fields.transactionCategory'))

  /* Dokumen perdin hanya diminta bila keterangan transaksinya menuntutnya. */
  if (needsBusinessTrip.value && !required(form.business_trip_id))
    kurang.push(t('cashAdvance.form.fields.businessTrip'))

  return kurang
})

const openItemFullscreen = (): void => {
  /*
  | Rincian baru bisa diisi setelah kepalanya lengkap.
  |
  | Cabang dan department memilih daftar keterangan transaksinya; keterangan
  | transaksi memilih perlu tidaknya dokumen perdin; dan perdin menentukan
  | rentang tanggal yang boleh dipakai baris rincian. Mengisi rincian lebih
  | dulu berarti mengisi sesuatu yang aturannya belum diketahui -- lalu
  | mengetahuinya saat menyimpan, setelah semuanya terlanjur diisi.
  |
  | Dijelaskan, bukan dimatikan: tombol mati tidak bisa ditanyai.
  */
  if (missingHeaderFields.value.length) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvance.form.toast.completeHeaderFirst', {
        fields: missingHeaderFields.value.join(', '),
      }),
    })

    return
  }

  tempItems.value = cloneItems(form.items)
  tempDeletedIds.value = [...deletedAttachmentIds.value]

  if (!tempItems.value.length)
    tempItems.value = [createEmptyItem()]

  itemDialogSaved.value = false
  itemDialog.value = true
}

const closeItemDialog = (): void => {
  if (itemDialogSaved.value) {
    tempItems.value = []
    itemDialog.value = false

    return
  }

  confirmCloseItemDialog.value = true
}

const discardItemDialog = (): void => {
  confirmCloseItemDialog.value = false
  tempItems.value = []
  tempDeletedIds.value = []
  itemDialog.value = false
}

const saveItemsFromDialog = (): void => {
  /*
   * Tanggal wajib pada setiap baris. Dipisah dari pemeriksaan deskripsi
   * dan nominal supaya pesannya menunjuk langsung ke kolom yang kosong.
   */
  const withoutDate = tempItems.value.findIndex(item => !String(item.date ?? '').trim())

  if (withoutDate !== -1) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvance.form.toast.itemDateRequired', { number: withoutDate + 1 }),
    })

    return
  }

  /*
   * Tanggal yang di luar periode perjalanan ditahan di sini juga, bukan
   * hanya dibatasi kalendernya: baris bisa sudah terisi sebelum perdinnya
   * dipilih, dan kalendernya tidak pernah melihat tanggal yang sudah ada.
   */
  const outsideRow = tempItems.value.findIndex(item => dateOutsideTrip(item.date))

  if (outsideRow !== -1) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: outsideTripMessage(
        [outsideRow + 1],
        'cashAdvance.form.toast.itemDateOutsideTrip',
      ),
    })

    return
  }

  const invalidIndex = tempItems.value.findIndex(
    item => !item.description.trim() || Number(item.amount || 0) <= 0,
  )

  if (invalidIndex !== -1) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvance.form.toast.completeItemRow', { number: invalidIndex + 1 }),
    })

    return
  }

  const withoutAttachment = tempItems.value.findIndex(
    item => item.attachments.length + item.existing.length < 1,
  )

  if (withoutAttachment !== -1) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvance.form.toast.itemAttachmentRequired', {
        number: withoutAttachment + 1,
      }),
    })

    return
  }

  form.items = cloneItems(tempItems.value)
  deletedAttachmentIds.value = [...tempDeletedIds.value]
  itemDialogSaved.value = true
  itemDialog.value = false
}

const addItemRow = (): void => {
  tempItems.value.push(createEmptyItem())
}

const removeItemRow = (index: number): void => {
  if (tempItems.value.length <= 1)
    return

  tempItems.value.splice(index, 1)
}

const resetItems = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: t('cashAdvance.form.items.resetConfirmTitle'),
    text: t('cashAdvance.form.items.resetConfirmText'),
    confirmButtonText: t('common.actions.confirm'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed)
    return

  form.items = []
  tempItems.value = []
}

const handleAmountInput = (event: Event, index: number): void => {
  const target = event.target as HTMLInputElement

  const result = formatSanitizedNumberInput(target.value, formatMoney, {
    maxLength: 15,
    emptyAsZero: true,
  })

  if (!tempItems.value[index])
    return

  tempItems.value[index].amount = result.numeric ?? 0

  target.value = result.formatted
}

/*
 * Satu input berkas tersembunyi per baris. Dikumpulkan dalam array supaya
 * tombol pada baris ke-n membuka pemilih berkas milik baris itu saja.
 */
const lineFileRefs = ref<Array<HTMLInputElement | null>>([])

const setLineFileRef = (el: any, index: number): void => {
  lineFileRefs.value[index] = el as HTMLInputElement | null
}

const triggerLineFileInput = (index: number): void => {
  lineFileRefs.value[index]?.click()
}

const handleLineFileUpload = (event: Event, index: number): void => {
  const input = event.target as HTMLInputElement
  const row = tempItems.value[index]

  if (!input.files || !row)
    return

  attachmentError.value = ''

  const invalidMessages: string[] = []

  for (const file of Array.from(input.files)) {
    const extension = getExtension(file.name)
    const validMime = ALLOWED_TYPES.includes(file.type)
    const validExtension = ALLOWED_EXTENSIONS.includes(extension)

    if (!validMime && !validExtension) {
      invalidMessages.push(
        t('cashAdvance.form.toast.invalidFileType', { name: file.name }),
      )

      continue
    }

    if (file.size > MAX_FILE_SIZE) {
      invalidMessages.push(
        t('cashAdvance.form.toast.invalidFileSize', { name: file.name }),
      )

      continue
    }

    const exists = row.attachments.some(
      existing => existing.name === file.name && existing.size === file.size,
    )

    if (!exists)
      row.attachments.push(file)
  }

  if (invalidMessages.length) {
    attachmentError.value = invalidMessages.join(' ')

    showWarningToast({
      title: t('cashAdvance.form.toast.invalidFileTitle'),
      text: attachmentError.value,
    })
  }

  input.value = ''
}

const removeLineAttachment = (index: number, fileIndex: number): void => {
  tempItems.value[index]?.attachments.splice(fileIndex, 1)
}

/**
 * Menandai bukti lama sebuah baris untuk dihapus.
 *
 * Sama seperti lampiran dokumen: hanya ditandai di sini, penghapusan sebenarnya
 * terjadi di server saat perubahan disimpan.
 */
const removeLineExistingAttachment = (index: number, attachmentId: number): void => {
  const row = tempItems.value[index]

  if (!row)
    return

  tempDeletedIds.value.push(attachmentId)

  row.existing = row.existing.filter(item => item.id !== attachmentId)
}

/** Baris tanpa bukti sama sekali, baik yang lama maupun yang baru dipilih. */
const lineMissingAttachment = (index: number): boolean => {
  const row = tempItems.value[index]

  if (!row)
    return true

  return row.attachments.length + row.existing.length < 1
}

/*
 * File lama tidak langsung dihapus dari server -- hanya ditandai, lalu benar
 * benar dihapus ketika perubahan disimpan. Dengan begitu membatalkan edit
 * tidak menghilangkan lampiran.
 */
const removeExistingAttachment = (attachment: ExistingAttachment): void => {
  deletedAttachmentIds.value.push(attachment.id)

  existingAttachments.value = existingAttachments.value.filter(
    item => item.id !== attachment.id,
  )
}

const formatFileSize = (bytes: number | null): string => {
  if (!bytes)
    return '-'

  return `${(bytes / 1024 / 1024).toFixed(2)} MB`
}

const getFileType = (mimeType: string | null): string =>
  mimeType === 'application/pdf' ? 'PDF' : 'IMAGE'

const validateForm = (): boolean => {
  if (
    !required(form.date)
    || !required(form.branch)
    || !required(form.department_id)
    || !required(form.transaction_category_id)
    || !required(form.request_type)
    || !form.subject.trim()

    /* Wajib hanya bila keterangan transaksinya menuntutnya. */
    || (needsBusinessTrip.value && !required(form.business_trip_id))
  ) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvance.form.toast.completeRequiredData'),
    })

    return false
  }

  if (!form.items.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvance.form.toast.minOneItem'),
    })

    return false
  }

  /*
   * Tanggal wajib pada setiap baris. Dipisah dari pemeriksaan deskripsi
   * dan nominal supaya pesannya menunjuk langsung ke kolom yang kosong.
   */
  const withoutDate = form.items.findIndex(item => !String(item.date ?? '').trim())

  if (withoutDate !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvance.form.toast.itemDateRequired', { number: withoutDate + 1 }),
    })

    return false
  }

  /*
   * Baris yang tanggalnya di luar periode perjalanan. Ditahan di sini
   * supaya tidak perlu menunggu penolakan server -- server tetap
   * memeriksanya, tetapi pesannya baru datang setelah semuanya dikirim.
   */
  if (itemsOutsideTripRange.value.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: outsideTripMessage(
        itemsOutsideTripRange.value,
        'cashAdvance.form.toast.itemDateOutsideTrip',
      ),
    })

    return false
  }

  const invalidIndex = form.items.findIndex(
    item => !item.description.trim() || Number(item.amount || 0) <= 0,
  )

  if (invalidIndex !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvance.form.toast.completeItemRow', { number: invalidIndex + 1 }),
    })

    return false
  }

  /*
   * Setiap baris wajib berbukti, dihitung dari gabungan bukti lama yang masih
   * ada dan berkas yang baru dipilih. Diperiksa di sini juga, bukan hanya di
   * backend, supaya user langsung tahu baris mana yang kurang.
   */
  const withoutAttachment = form.items.findIndex(
    item => item.attachments.length + item.existing.length < 1,
  )

  if (withoutAttachment !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvance.form.toast.itemAttachmentRequired', {
        number: withoutAttachment + 1,
      }),
    })

    return false
  }

  return true
}

const buildFormData = (): FormData => {
  const formData = new FormData()

  /*
   * FormData tidak bisa dikirim lewat PUT, jadi dipakai method spoofing --
   * mengikuti pola form Purchase Requisition.
   */
  formData.append('_method', 'PUT')

  formData.append('date', String(form.date || ''))
  formData.append('branch', String(form.branch || ''))
  formData.append('department_id', String(form.department_id || ''))
  formData.append('subject', form.subject)
  formData.append('transaction_category_id', String(form.transaction_category_id || ''))

  /*
   * Dikirim hanya bila memang diperlukan. Server tetap mengabaikannya
   * untuk keterangan transaksi yang tidak menuntutnya.
   */
  if (needsBusinessTrip.value && form.business_trip_id)
    formData.append('business_trip_id', String(form.business_trip_id))
  formData.append('request_type', String(form.request_type || ''))
  formData.append('notes', form.notes || '')

  formData.append(
    'items',
    JSON.stringify(
      form.items.map(item => ({
        id: item.id,
        date: item.date || null,
        description: item.description,
        amount: Number(item.amount || 0),
      })),
    ),
  )

  if (deletedAttachmentIds.value.length) {
    formData.append(
      'deleted_attachment_ids',
      JSON.stringify(deletedAttachmentIds.value),
    )
  }

  /*
   * Berkas baru dikirim berkelompok menurut nomor urut baris. Bukti lama tidak
   * ikut dikirim ulang -- yang sudah ada di server tetap di sana kecuali
   * id-nya masuk deleted_attachment_ids.
   */
  form.items.forEach((item, index) => {
    item.attachments.forEach(file => {
      formData.append(`line_attachments[${index}][]`, file)
    })
  })

  return formData
}

const updateCashAdvance = async (event?: Event): Promise<void> => {
  event?.preventDefault()
  event?.stopPropagation()

  if (isSaving.value)
    return

  isSubmitted.value = true

  if (!validateForm())
    return

  const confirm = await showConfirmAlert({
    title: t('cashAdvance.form.toast.updateConfirmTitle'),
    text: t('cashAdvance.form.toast.updateConfirmText'),
    confirmButtonText: t('common.actions.confirm'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed)
    return

  isSaving.value = true

  try {
    showLoadingAlert(
      t('cashAdvance.form.toast.savingData'),
      t('common.alert.pleaseWait'),
    )

    await axios.post(
      `/fund-request/cash-advance/${publicId.value}`,
      buildFormData(),
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    await router.replace({
      path: '/fund_request/cash_advance',
      query: { success: 'updated' },
    })
  }
  catch (error: unknown) {
    closeAlert()

    console.error('[Cash Advance] UPDATE ERROR:', error)

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvance.form.toast.saveFailed')),
    })
  }
  finally {
    isSaving.value = false
  }
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: t('cashAdvance.form.toast.cancelConfirmTitle'),
    text: t('cashAdvance.form.toast.cancelConfirmText'),
    confirmButtonText: t('common.actions.confirm'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (confirm.isConfirmed)
    await router.replace('/fund_request/cash_advance')
}

const goBack = async (): Promise<void> => {
  await router.replace('/fund_request/cash_advance')
}

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canUpdate.value) {
    await router.replace('/forbidden')

    return
  }

  if (!publicId.value) {
    await router.replace('/fund_request/cash_advance')

    return
  }

  isCheckingPermission.value = false

  await fetchAccessAssignments(false)
  await loadCashAdvance()

  /*
   * Menyusul dokumennya, karena daftar kategori bergantung pada cabang dan
   * department yang baru saja dimuat. preserveSelected menjaga pilihan lama
   * tetap ada walau flow-nya sudah berubah sejak dokumen dibuat.
   */
  await loadTransactionCategories(true)
})
</script>

<template>
  <!--
    Bentuk loading disamakan dengan halaman edit PR dan PO: kartu berlabel,
    bukan spinner telanjang, supaya user tahu apa yang sedang dimuat.
  -->
  <VCard
    v-if="isCheckingPermission || isLoading"
    class="mb-6 rounded-lg"
    elevation="2"
  >
    <VCardText class="pa-6">
      <div class="d-flex align-center">
        <VProgressCircular
          indeterminate
          color="primary"
          size="28"
          width="3"
          class="me-4"
        />

        <div>
          <div class="text-h6 font-weight-medium">
            {{ t('cashAdvance.form.loadingTitle') }}
          </div>

          <div class="text-body-2 text-medium-emphasis">
            {{ t('common.alert.pleaseWait') }}
          </div>
        </div>
      </div>
    </VCardText>
  </VCard>

  <section v-else>
    <VRow>
      <VCol cols="12">
        <VCard>
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-3">
            <div>
              <div class="text-h6 font-weight-bold">
                {{ t('cashAdvance.form.editTitle') }} — {{ advanceNumber }}
              </div>

              <div class="text-body-2 text-medium-emphasis">
                {{ t('cashAdvance.form.editSubtitle') }}
              </div>
            </div>

            <VBtn
              prepend-icon="mdi-arrow-left"
              variant="text"
              color="secondary"
              class="text-none"
              @click="goBack"
            >
              {{ t('cashAdvance.form.backButton') }}
            </VBtn>
          </VCardTitle>

          <VDivider />

          <VCardText>
            <VRow>
              <VCol
                cols="12"
                md="4"
              >
                <AppDateTimePicker
                  v-model="form.date"
                  :label="t('cashAdvance.form.fields.date')"
                  :placeholder="t('cashAdvance.form.placeholders.date')"
                  :config="{ dateFormat: 'Y-m-d', position: 'below' }"
                  :error="isSubmitted && !form.date"
                  :error-messages="isSubmitted && !form.date ? [t('cashAdvance.form.validation.date')] : []"
                />
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <VAutocomplete
                  v-model="form.branch"
                  :label="t('cashAdvance.form.fields.branch')"
                  :items="branchList"
                  item-title="title"
                  item-value="value"
                  density="comfortable"
                  :loading="isLoadingBranch"
                  :clearable="branchList.length > 1"
                  :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                  :error="isSubmitted && !form.branch"
                  :error-messages="isSubmitted && !form.branch ? [t('cashAdvance.form.validation.branch')] : []"
                  :no-data-text="t('cashAdvance.form.noData.branch')"
                  :placeholder="t('cashAdvance.form.placeholders.branch')"
                  @update:model-value="handleBranchChange"
                />
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <VAutocomplete
                  v-model="form.department_id"
                  :label="t('cashAdvance.form.fields.department')"
                  :items="departmentList"
                  item-title="label"
                  item-value="id"
                  density="comfortable"
                  :loading="isLoadingDepartment"
                  :disabled="!form.branch"
                  :clearable="departmentList.length > 1"
                  :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                  :error="isSubmitted && !form.department_id"
                  :error-messages="isSubmitted && !form.department_id ? [t('cashAdvance.form.validation.department')] : []"
                  :no-data-text="t('cashAdvance.form.noData.department')"
                  :placeholder="t('cashAdvance.form.placeholders.department')"
                  @update:model-value="handleDepartmentChange"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <VAutocomplete
                  v-model="form.request_type"
                  :label="t('cashAdvance.form.fields.requestType')"
                  :items="requestTypeList"
                  density="comfortable"
                  clearable
                  :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                  :error="isSubmitted && !form.request_type"
                  :error-messages="isSubmitted && !form.request_type ? [t('cashAdvance.form.validation.requestType')] : []"
                  :placeholder="t('cashAdvance.form.placeholders.requestType')"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <!--
                  Keterangan transaksi ikut menentukan approval flow, sesuai
                  matriks yang membedakan Perdin dari Telpon/Listrik/Pantry.
                -->
                <VAutocomplete
                  v-model="form.transaction_category_id"
                  :label="t('cashAdvance.form.fields.transactionCategory')"
                  :items="transactionCategoryList"
                  item-title="title"
                  item-value="id"
                  density="comfortable"
                  :loading="isLoadingTransactionCategory"
                  clearable
                  :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                  :error="isSubmitted && !form.transaction_category_id"
                  :error-messages="isSubmitted && !form.transaction_category_id ? [t('cashAdvance.form.validation.transactionCategory')] : []"
                  :disabled="!form.branch || !form.department_id"
                  :no-data-text="t('cashAdvance.form.noData.transactionCategory')"
                  :placeholder="t('cashAdvance.form.placeholders.transactionCategory')"
                />

                <!--
                  Muncul hanya bila keterangan transaksinya menuntut dokumen
                  Perdin. Daftarnya sudah disaring server: milik pemohon,
                  sudah disetujui, dan belum dipegang FPU lain yang berjalan.
                -->
                <VAutocomplete
                  v-if="needsBusinessTrip"
                  v-model="form.business_trip_id"
                  :label="t('cashAdvance.form.fields.businessTrip')"
                  :items="businessTripList"
                  item-value="id"
                  density="comfortable"
                  class="mt-4"
                  :loading="isLoadingBusinessTrip"
                  clearable
                  :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                  :error="isSubmitted && !form.business_trip_id"
                  :error-messages="isSubmitted && !form.business_trip_id
                    ? [t('cashAdvance.form.validation.businessTrip')]
                    : []"
                  :no-data-text="t('cashAdvance.form.noData.businessTrip')"
                  :placeholder="t('cashAdvance.form.placeholders.businessTrip')"
                  persistent-placeholder
                  :hint="selectedBusinessTripPending
                    ? t('cashAdvance.form.hints.businessTripPending')
                    : t('cashAdvance.form.hints.businessTrip')"
                  persistent-hint
                >
                  <!-- Nomor saja tidak cukup untuk mengenali perjalanan mana. -->
                  <!--
                    Yang belum boleh dipakai tetap ditampilkan, tetapi tidak
                    bisa diklik -- dan sebabnya dikatakan di barisnya sendiri,
                    bukan disembunyikan.
                  -->
                  <template #item="{ props: itemProps, item }">
                    <VListItem
                      v-bind="itemProps"
                      :disabled="!item.raw.selectable"
                      :title="item.raw.trip_number"
                      :subtitle="item.raw.selectable
                        ? `${item.raw.destination} · ${item.raw.depart_date} → ${item.raw.return_date}`
                        : businessTripLockReason(item.raw)"
                    >
                      <template #prepend>
                        <VIcon
                          :icon="item.raw.selectable ? 'tabler-plane' : 'tabler-lock'"
                          :color="item.raw.selectable ? undefined : 'warning'"
                          size="18"
                          class="me-2"
                        />
                      </template>

                      <template #append>
                        <VChip
                          size="x-small"
                          variant="tonal"
                          :color="item.raw.status === 'APPROVED' ? 'success' : 'warning'"
                        >
                          {{ item.raw.status === 'APPROVED'
                            ? t('cashAdvance.form.businessTripState.approved')
                            : t('cashAdvance.form.businessTripState.pending') }}
                        </VChip>
                      </template>
                    </VListItem>
                  </template>

                  <template #selection="{ item }">
                    <span>{{ item.raw.trip_number }} &mdash; {{ item.raw.destination }}</span>
                  </template>
                </VAutocomplete>

                <!--
                  Daftar kosong punya dua sebab yang berbeda jauh: cabang dan
                  department belum dipilih, atau keduanya sudah dipilih tetapi
                  memang belum didaftarkan pada approval flow FPU. Yang kedua
                  tidak bisa diselesaikan sendiri oleh user, jadi harus
                  dikatakan terang-terangan.
                -->
                <VAlert
                  v-if="!form.branch || !form.department_id"
                  type="info"
                  variant="tonal"
                  density="compact"
                  class="mt-2"
                >
                  {{ t('cashAdvance.form.flowNotice.selectContext') }}
                </VAlert>

                <VAlert
                  v-else-if="hasApprovalFlow === false"
                  type="warning"
                  variant="tonal"
                  density="compact"
                  class="mt-2"
                >
                  {{ t('cashAdvance.form.flowNotice.noFlow') }}
                </VAlert>
              </VCol>

              <VCol cols="12">
                <VTextarea
                  v-model="form.subject"
                  :label="t('cashAdvance.form.fields.subject')"
                  :placeholder="t('cashAdvance.form.placeholders.subject')"
                  rows="2"
                  auto-grow
                  :error="isSubmitted && !form.subject.trim()"
                  :error-messages="isSubmitted && !form.subject.trim() ? [t('cashAdvance.form.validation.subject')] : []"
                />
              </VCol>
            </VRow>

            <!-- RINCIAN PENGAJUAN -->
            <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-4 mb-3">
              <div>
                <div class="text-subtitle-1 font-weight-bold">
                  {{ t('cashAdvance.form.items.sectionTitle') }}
                </div>

                <!--
                  Disebutkan sebelum tombolnya ditekan, bukan hanya sesudah:
                  yang membaca bisa langsung melengkapi kepala dokumennya.
                -->
                <div
                  v-if="missingHeaderFields.length"
                  class="text-caption text-warning mt-1"
                >
                  {{ t('cashAdvance.form.items.headerFirstHint', { fields: missingHeaderFields.join(', ') }) }}
                </div>
              </div>

              <div class="d-flex align-center flex-wrap gap-2">
                <VBtn
                  type="button"
                  color="primary"
                  variant="tonal"
                  size="small"
                  prepend-icon="tabler-list-details"
                  class="text-none"
                  @click="openItemFullscreen"
                >
                  {{ t('cashAdvance.form.items.addButton') }}

                  <VTooltip
                    activator="parent"
                    location="top"
                    :offset="4"
                  >
                    {{ t('cashAdvance.form.items.addButtonTooltip') }}
                  </VTooltip>
                </VBtn>

                <VBtn
                  type="button"
                  color="error"
                  variant="outlined"
                  size="small"
                  class="text-none"
                  @click="resetItems"
                >
                  {{ t('cashAdvance.form.items.resetButton') }}
                </VBtn>
              </div>
            </div>

            <!--
              Ringkasan baca-saja. Penyuntingan dilakukan di modal layar penuh,
              mengikuti pola Purchase Requisition.
            -->
            <VCard
              flat
              class="ca-summary-card"
            >
              <VCardText>
                <VAlert
                  v-if="!form.items.length || form.items.every(item => !item.description)"
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  {{ t('cashAdvance.form.items.emptyAlert', { action: t('cashAdvance.form.items.addButton') }) }}
                </VAlert>

                <!--
                  Muncul begitu perdinnya dipilih, tanpa menunggu disimpan.
                  Barisnya sengaja tidak dihapus sendiri: yang mengetiknya
                  yang tahu tanggal mana yang benar.
                -->
                <VAlert
                  v-if="itemsOutsideTripRange.length"
                  type="warning"
                  variant="tonal"
                  density="compact"
                  class="mb-3"
                >
                  {{ outsideTripMessage(itemsOutsideTripRange, 'cashAdvance.form.items.outsideTripAlert') }}
                </VAlert>

                <div
                  v-else
                  class="d-flex flex-column gap-3"
                >
                  <div
                    v-for="(item, index) in form.items"
                    :key="`summary-item-${index}`"
                    class="ca-summary-row"
                  >
                    <div class="d-flex align-start gap-3">
                      <VAvatar
                        size="30"
                        color="primary"
                        variant="tonal"
                      >
                        {{ index + 1 }}
                      </VAvatar>

                      <div class="flex-grow-1 min-w-0">
                        <div class="font-weight-bold">
                          {{ item.description || '-' }}
                        </div>

                        <div class="text-caption text-medium-emphasis mt-1">
                          {{ t('cashAdvance.form.items.tableDate') }}:
                          <strong :class="{ 'text-error': dateOutsideTrip(item.date) }">
                            {{ item.date || '-' }}
                          </strong>
                        </div>

                        <div class="d-flex align-center flex-wrap gap-2 mt-2">
                          <VChip
                            v-for="attachment in item.existing"
                            :key="`summary-item-${index}-existing-${attachment.id}`"
                            size="x-small"
                            variant="tonal"
                            color="primary"
                            prepend-icon="tabler-paperclip"
                          >
                            {{ attachment.original_filename || attachment.filename }}
                          </VChip>

                          <VChip
                            v-for="(file, fileIndex) in item.attachments"
                            :key="`summary-item-${index}-file-${fileIndex}`"
                            size="x-small"
                            variant="tonal"
                            color="success"
                            prepend-icon="tabler-paperclip"
                          >
                            {{ file.name }}
                          </VChip>
                        </div>
                      </div>

                      <div class="text-end">
                        <div class="text-caption text-medium-emphasis">
                          {{ t('cashAdvance.form.items.tableAmount') }}
                        </div>

                        <div class="font-weight-bold">
                          Rp {{ formatMoney(item.amount) || '0' }}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </VCardText>
            </VCard>

            <div class="d-flex justify-end mt-4">
              <div class="ca-total-box">
                <span>{{ t('cashAdvance.form.items.total') }}</span>
                <strong>Rp {{ formatMoney(totalAmount) || '0' }}</strong>
              </div>
            </div>

            <!--
              LAMPIRAN TINGKAT DOKUMEN (warisan)

              Hanya muncul pada FPU yang dibuat sebelum bukti dipindah ke
              tingkat baris. Tidak ada lagi jalur yang menambah berkas ke sini;
              yang tersisa hanya bisa dibuka atau dihapus.
            -->
            <div
              v-if="existingAttachments.length"
              class="mt-6"
            >
              <div class="text-subtitle-1 font-weight-bold mb-2">
                {{ t('cashAdvance.form.attachment.existingTitle') }}
              </div>

              <VDivider class="mb-4" />

              <VList
                density="comfortable"
                border
                rounded
              >
                <VListItem
                  v-for="attachment in existingAttachments"
                  :key="`existing-${attachment.id}`"
                >
                  <template #prepend>
                    <VIcon
                      :icon="getFileType(attachment.mime_type) === 'PDF' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                    />
                  </template>

                  <VListItemTitle class="text-body-2">
                    <a
                      v-if="attachment.url"
                      :href="attachment.url"
                      target="_blank"
                      rel="noopener"
                      class="text-primary"
                    >
                      {{ attachment.original_filename || attachment.filename }}
                    </a>

                    <span v-else>
                      {{ attachment.original_filename || attachment.filename }}
                    </span>
                  </VListItemTitle>

                  <VListItemSubtitle>
                    {{ getFileType(attachment.mime_type) }} • {{ formatFileSize(attachment.file_size) }}
                  </VListItemSubtitle>

                  <template #append>
                    <VBtn
                      type="button"
                      color="error"
                      variant="text"
                      size="small"
                      class="text-none"
                      @click="removeExistingAttachment(attachment)"
                    >
                      {{ t('cashAdvance.form.attachment.deleteButton') }}
                    </VBtn>
                  </template>
                </VListItem>
              </VList>
            </div>

            <!--
              Lampiran baru tidak lagi berdiri di tingkat dokumen -- setiap
              baris rincian membawa buktinya sendiri di kolom Lampiran.
            -->
            <VAlert
              v-if="attachmentError"
              type="warning"
              variant="tonal"
              class="mt-4"
            >
              {{ attachmentError }}
            </VAlert>

            <VTextarea
              v-model="form.notes"
              class="mt-6"
              :label="t('cashAdvance.form.fields.notes')"
              :placeholder="t('cashAdvance.form.placeholders.notes')"
              rows="3"
              auto-grow
            />

            <VDivider class="mt-6 mb-4" />

            <div class="d-flex justify-end gap-3">
              <VBtn
                type="button"
                color="secondary"
                variant="outlined"
                class="text-none"
                @click.prevent.stop="confirmCancel"
              >
                {{ t('cashAdvance.form.buttons.cancel') }}
              </VBtn>

              <VBtn
                type="button"
                color="primary"
                class="text-none"
                :loading="isSaving"
                @click.prevent.stop="updateCashAdvance($event)"
              >
                {{ t('cashAdvance.form.buttons.update') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!--
      MODAL RINCIAN (layar penuh)
      Mengikuti pola Purchase Requisition.
    -->
    <!--
      Kalender flatpickr dipasang ke <body>, di luar DOM dialog. Tiga bawaan
      VDialog karenanya harus dimatikan:

      - persistent      : tanpa ini, klik pada tanggal terbaca sebagai klik di
      luar dialog dan modalnya ikut tertutup.
      - retain-focus    : jebakan fokus menarik fokus kembali ke dialog dan
      menutup kalender sebelum tanggalnya sempat dipilih.
      - no-click-animation
      : klik kalender tetap terhitung klik-luar, dan pada
      dialog persistent itu memicu animasi denyut.

      Penutupan modal tetap tersedia lewat tombol X, yang melewati konfirmasi
      buang perubahan.
    -->
    <VDialog
      v-model="itemDialog"
      fullscreen
      scrollable
      persistent
      no-click-animation
      :retain-focus="false"
    >
      <VCard>
        <VToolbar color="primary">
          <VBtn
            icon
            variant="text"
            color="white"
            @click="closeItemDialog"
          >
            <VIcon icon="tabler-x" />
          </VBtn>

          <VToolbarTitle>
            {{ t('cashAdvance.form.itemDialog.title') }}
          </VToolbarTitle>

          <VSpacer />

          <VBtn
            variant="flat"
            class="me-3 text-none"
            prepend-icon="tabler-plus"
            @click="addItemRow"
          >
            {{ t('cashAdvance.form.itemDialog.addRowButton') }}
          </VBtn>

          <VBtn
            variant="flat"
            class="me-3 text-none"
            @click="saveItemsFromDialog"
          >
            {{ t('cashAdvance.form.itemDialog.saveButton') }}
          </VBtn>
        </VToolbar>

        <VCardText class="pa-4">
          <div class="ca-table-wrapper">
            <VTable class="ca-item-table">
              <thead>
                <tr>
                  <th class="ca-col-no">
                    {{ t('cashAdvance.form.items.tableNo') }}
                  </th>
                  <th class="ca-col-date">
                    {{ t('cashAdvance.form.items.tableDate') }}
                    <span class="text-error">*</span>
                  </th>
                  <th class="ca-col-desc">
                    {{ t('cashAdvance.form.items.tableDescription') }}
                  </th>
                  <th class="ca-col-amount">
                    {{ t('cashAdvance.form.items.tableAmount') }}
                  </th>
                  <th class="ca-col-attachment">
                    {{ t('cashAdvance.form.items.tableAttachment') }}
                    <span class="text-error">*</span>
                  </th>
                  <th class="ca-col-action text-center">
                    {{ t('cashAdvance.form.items.tableActions') }}
                  </th>
                </tr>
              </thead>

              <tbody>
                <tr
                  v-for="(item, index) in tempItems"
                  :key="`item-${index}`"
                >
                  <td class="text-medium-emphasis">
                    {{ index + 1 }}
                  </td>

                  <td>
                    <AppDateTimePicker
                      v-model="item.date"
                      density="compact"
                      hide-details="auto"
                      :config="lineDateConfig"
                      :error="(isSubmitted && !item.date) || dateOutsideTrip(item.date)"
                      :error-messages="itemDateErrors(item)"
                    />
                  </td>

                  <td>
                    <VTextField
                      v-model="item.description"
                      density="compact"
                      variant="outlined"
                      hide-details="auto"
                      :error="isSubmitted && !item.description.trim()"
                      :error-messages="isSubmitted && !item.description.trim() ? [t('cashAdvance.form.validation.itemDescription')] : []"
                    />
                  </td>

                  <td>
                    <VTextField
                      :model-value="formatMoney(item.amount)"
                      density="compact"
                      variant="outlined"
                      hide-details="auto"
                      prefix="Rp"
                      inputmode="numeric"
                      :error="isSubmitted && Number(item.amount || 0) <= 0"
                      :error-messages="isSubmitted && Number(item.amount || 0) <= 0 ? [t('cashAdvance.form.validation.itemAmount')] : []"
                      @input="handleAmountInput($event, index)"
                    />
                  </td>

                  <!--
                    Bukti melekat pada baris ini. Berkas lama dan yang baru
                    dipilih ditampilkan berurutan di sel yang sama.
                  -->
                  <td>
                    <div class="d-flex flex-column gap-2">
                      <input
                        :ref="el => setLineFileRef(el, index)"
                        type="file"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png"
                        class="d-none"
                        @change="handleLineFileUpload($event, index)"
                      >

                      <VBtn
                        type="button"
                        size="small"
                        variant="outlined"
                        class="text-none align-self-start"
                        :color="isSubmitted && lineMissingAttachment(index) ? 'error' : 'primary'"
                        prepend-icon="tabler-paperclip"
                        @click="triggerLineFileInput(index)"
                      >
                        {{ t('cashAdvance.form.attachment.addButton') }}
                      </VBtn>

                      <div
                        v-if="isSubmitted && lineMissingAttachment(index)"
                        class="text-error text-caption"
                      >
                        {{ t('cashAdvance.form.validation.itemAttachment') }}
                      </div>

                      <!-- Bukti yang sudah tersimpan -->
                      <div
                        v-for="attachment in item.existing"
                        :key="`item-${index}-existing-${attachment.id}`"
                        class="ca-line-file"
                      >
                        <VIcon
                          :icon="getFileType(attachment.mime_type) === 'PDF' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                          size="18"
                          color="primary"
                        />

                        <div class="ca-line-file__body">
                          <a
                            v-if="attachment.url"
                            :href="attachment.url"
                            target="_blank"
                            rel="noopener"
                            class="ca-line-file__name d-block text-primary"
                          >
                            {{ attachment.original_filename || attachment.filename }}
                          </a>

                          <div
                            v-else
                            class="ca-line-file__name"
                          >
                            {{ attachment.original_filename || attachment.filename }}
                          </div>

                          <div class="text-caption text-medium-emphasis">
                            {{ formatFileSize(attachment.file_size) }}
                          </div>
                        </div>

                        <VBtn
                          icon
                          size="x-small"
                          variant="text"
                          color="error"
                          @click="removeLineExistingAttachment(index, attachment.id)"
                        >
                          <VIcon
                            icon="tabler-x"
                            size="16"
                          />
                        </VBtn>
                      </div>

                      <!-- Berkas yang baru dipilih -->
                      <div
                        v-for="(file, fileIndex) in item.attachments"
                        :key="`item-${index}-file-${fileIndex}`"
                        class="ca-line-file"
                      >
                        <VIcon
                          :icon="file.type === 'application/pdf' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                          size="18"
                          color="success"
                        />

                        <div class="ca-line-file__body">
                          <div class="ca-line-file__name">
                            {{ file.name }}
                          </div>

                          <div class="text-caption text-medium-emphasis">
                            {{ formatFileSize(file.size) }}
                          </div>
                        </div>

                        <VBtn
                          icon
                          size="x-small"
                          variant="text"
                          color="error"
                          @click="removeLineAttachment(index, fileIndex)"
                        >
                          <VIcon
                            icon="tabler-x"
                            size="16"
                          />
                        </VBtn>
                      </div>
                    </div>
                  </td>

                  <td class="text-center">
                    <VBtn
                      icon
                      size="small"
                      variant="text"
                      color="error"
                      :disabled="tempItems.length <= 1"
                      @click="removeItemRow(index)"
                    >
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCardText>
      </VCard>
    </VDialog>

    <VDialog
      v-model="confirmCloseItemDialog"
      max-width="460"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvance.form.itemDialog.discardTitle') }}
        </VCardTitle>

        <VCardText>
          {{ t('cashAdvance.form.itemDialog.discardText') }}
        </VCardText>

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            @click="confirmCloseItemDialog = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="error"
            class="text-none"
            @click="discardItemDialog"
          >
            {{ t('cashAdvance.form.itemDialog.discardButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>
/* Ringkasan rincian pada halaman utama. */
.ca-summary-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
}

.ca-summary-row {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  padding-block: 0.75rem;
  padding-inline: 0.875rem;
}

.ca-table-wrapper {
  overflow-x: auto;
}

.ca-item-table {
  min-inline-size: 1060px;
}

.ca-col-attachment {
  inline-size: 18rem;
  min-inline-size: 18rem;
}

/* Kartu berkas yang menempel pada satu baris rincian. */
.ca-line-file {
  display: flex;
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  gap: 0.5rem;
  padding-block: 0.25rem;
  padding-inline: 0.5rem;
}

.ca-line-file__body {
  flex: 1;
  min-inline-size: 0;
}

.ca-line-file__name {
  overflow: hidden;
  font-size: 0.8rem;
  text-decoration: none;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ca-col-no {
  inline-size: 4rem;
}

/*
| Kolom tanggal butuh ruang untuk "2026-08-30" beserta padding field dan
| tombol clear-nya. Pada 12rem, tata letak tabel otomatis masih menyusutkan
| kolom ini demi kolom deskripsi sehingga tanggalnya terpotong -- karena itu
| lebarnya dikunci lewat min-inline-size, bukan sekadar inline-size.
*/
.ca-col-date {
  inline-size: 14rem;
  min-inline-size: 14rem;
}

.ca-col-amount {
  inline-size: 14rem;
}

.ca-col-action {
  inline-size: 6rem;
}

.ca-total-box {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: 8px;
  background: rgba(var(--v-theme-primary), 0.08);
  gap: 2rem;
  min-inline-size: 18rem;
  padding-block: 0.75rem;
  padding-inline: 1rem;
}
</style>
