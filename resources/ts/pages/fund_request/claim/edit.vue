<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import axios from '@axios'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showInfoToast,
  showLoadingAlert,
  showWarningToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import RevisionHistory from '@/components/fund_request/RevisionHistory.vue'
import type { RevisionEntry } from '@/components/fund_request/revision'
import { formatSanitizedNumberInput } from '@/utils/textFormatter'
import { usePermissionStore } from '@/stores/permission'
import { useDialogDatePicker } from '@core/composable/useDialogDatePicker'

interface ClaimItemForm {

  /** Null untuk baris baru; diisi supaya baris lama diperbarui, bukan diganti. */
  id: number | null

  date: string | null
  description: string
  amount: number

  /*
  | Keterangan transaksi barisnya, dibatasi himpunan di headernya.
  |
  | null saat himpunannya tepat satu: server mengisinya sendiri, dan isian
  | berpilihan tunggal tidak pantas diminta dari pengguna.
  */
  transaction_category_id: number | null

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
| Rentang tanggal baris rincian, mengikuti periode perjalanannya.
|
| Kosong selama Claim-nya tidak bertaut perdin -- pengeluaran biasa tidak
| terikat periode apa pun.
*/
const lineDateRange = ref<{ minDate?: string; maxDate?: string }>({})

const lineDateConfig = computed(() => dialogDateConfig(lineDateRange.value))

const businessTripList = ref<BusinessTripOption[]>([])
const isLoadingBusinessTrip = ref(false)

const transactionCategoryList = ref<TransactionCategoryOption[]>([])
const isLoadingTransactionCategory = ref(false)

const loadTransactionCategories = async (): Promise<void> => {
  isLoadingTransactionCategory.value = true

  try {
    const response = await axios.get(
      '/fund-request/transaction-categories/dropdown-select',
      {
        headers: { Accept: 'application/json' },

        /*
         * Satu master menampung daftar FPU dan Claim sekaligus, jadi
         * modulnya harus disebut supaya keduanya tidak tercampur.
         */
        params: { document_type: 'CLAIM' },
      },
    )

    const data = Array.isArray(response?.data?.data) ? response.data.data : []

    transactionCategoryList.value = data.map((item: any) => ({
      id: Number(item.id),
      title: String(item.title || item.name || '-'),
      requires_business_trip: Boolean(item.requires_business_trip),
    }))
  }
  catch (error: unknown) {
    console.error('[Cash Advance] TRANSACTION CATEGORY ERROR:', error)
    transactionCategoryList.value = []
  }
  finally {
    isLoadingTransactionCategory.value = false
  }
}

/** Keterangan pendek kenapa sebuah perdin belum bisa dipilih. */
const businessTripLockReason = (item: BusinessTripOption): string => {
  if (item.selectable)
    return ''

  if (!item.waiting_step)
    return t('claim.form.businessTripState.locked')

  return t('claim.form.businessTripState.waitingStep', {
    step: item.waiting_step.step_order,
    who: item.waiting_step.approver
      || item.waiting_step.label
      || t('claim.form.businessTripState.someone'),
  })
}

/*
| Tanggal di luar periode diperiksa terus-menerus, bukan sekali saat
| dipilih: keterangan transaksinya bisa diganti BELAKANGAN, sesudah
| rinciannya terisi, sehingga baris yang tadinya sah mendadak berada di
| luar periode.
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
    return [t('claim.form.validation.itemDateOutsideTrip')]

  if (isSubmitted.value && !item.date)
    return [t('claim.form.validation.itemDate')]

  return []
}

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

const claimNumber = ref('-')
const existingAttachments = ref<ExistingAttachment[]>([])
const deletedAttachmentIds = ref<number[]>([])

const canUpdate = computed(() => permissionStore.can('claim.update'))

const createEmptyItem = (): ClaimItemForm => ({
  id: null,
  date: null,
  description: '',
  amount: 0,
  attachments: [],

  /* Diisikan server bila himpunannya tunggal. */
  transaction_category_id: null,
  existing: [],
})

const form = reactive({
  date: '',
  branch: null as number | null,
  department_id: null as number | null,

  /*
   * Keterangan transaksi ikut menentukan approval flow, jadi wajib diisi.
   */
  /*
  | Keterangan transaksi PENENTU: yang pertama di antara himpunannya.
  |
  | Masih yang memilih jadwal pembayaran di server. Diisi otomatis dari
  | himpunannya -- bukan dipilih sendiri.
  */
  transaction_category_id: null as number | null,

  /*
  | Himpunan keterangan transaksi yang boleh dipakai baris rinciannya.
  | Claim tidak punya sifat pengajuan, jadi isinya boleh berapa pun.
  */
  transaction_category_ids: [] as number[],

  /* Diisi hanya bila keterangan transaksinya menuntut Perdin. */
  business_trip_id: null as number | null,

  // Rutin / Non Rutin, mengikuti tipe PR.

  subject: '',
  notes: '',
  items: [createEmptyItem()] as ClaimItemForm[],
})

/**
 * Baris rincian itu benar-benar berisi sesuatu.
 *
 * Formulir berangkat dengan satu baris kosong -- itulah yang nanti diisi
 * di modal. Tanpa pemeriksaan ini, baris itu ikut tergambar pada ringkasan
 * sebagai "- · Tanggal: - · Rp 0", tepat di bawah tulisan bahwa rinciannya
 * belum ada.
 *
 * Ketiganya diperiksa, bukan keterangannya saja: baris yang sudah
 * bernominal tetapi belum diberi keterangan tetap berisi sesuatu, dan
 * menyembunyikannya berarti menyembunyikan angka yang sudah diketik orang.
 */
const isItemFilled = (item: { description?: string; date?: string | null; amount?: unknown }): boolean =>
  Boolean(String(item.description ?? '').trim())
  || Boolean(String(item.date ?? '').trim())
  || Number(item.amount ?? 0) > 0

/** Ada rincian yang pantas ditampilkan dan pantas direset. */
const hasItemDetails = computed<boolean>(() => form.items.some(isItemFilled))

const selectedBusinessTrip = computed<BusinessTripOption | null>(() =>
  businessTripList.value.find(
    item => Number(item.id) === Number(form.business_trip_id),
  ) ?? null)

/** Keterangan transaksi yang sedang dipilih menuntut dokumen Perdin. */
const needsBusinessTrip = computed<boolean>(() => {
  const dipilih = transactionCategoryList.value.find(
    item => Number(item.id) === Number(form.transaction_category_id),
  )

  return Boolean(dipilih?.requires_business_trip)
})

interface TransactionCategoryOption {
  id: number
  title: string

  /*
  | Penanda dari master: keterangan transaksi ini menuntut dokumen Perdin.
  | Dibaca dari penandanya, bukan dari namanya -- nama bisa disunting orang.
  */
  requires_business_trip?: boolean
}

interface BusinessTripOption {
  id: number
  trip_number: string
  destination: string
  depart_date: string | null
  return_date: string | null
  status: string
  selectable: boolean
  waiting_step?: { step_order: number; label: string | null; approver: string | null } | null
}

/**
 * Perdin yang boleh dipakai: milik sendiri, sudah disetujui, dan belum
 * dipegang FPU atau Claim lain yang masih berjalan.
 *
 * Daftarnya disusun server dengan aturan yang sama persis yang dipakai
 * memeriksa kiriman ini nanti. Kalau disusun dengan aturan sendiri, layar
 * akan menawarkan sesuatu yang lalu ditolak.
 */
const loadBusinessTrips = async (): Promise<void> => {
  /*
  | Daftar keterangan transaksinya belum tiba: belum ada yang bisa
  | dikatakan tentang perlu tidaknya perdin, karena jawabannya justru
  | ada di daftar itu.
  |
  | Berhenti di sini, bukan meneruskan ke cabang di bawah: cabang itu
  | membuang tautan perdin yang tersimpan, dan membuangnya atas dasar
  | tebakan 'tidak perlu' yang kebetulan selalu benar selama daftarnya
  | kosong. Diam lebih benar daripada menebak.
  */
  if (!transactionCategoryList.value.length)
    return

  if (!needsBusinessTrip.value) {
    businessTripList.value = []
    form.business_trip_id = null

    return
  }

  isLoadingBusinessTrip.value = true

  try {
  /*
  | Claim ini sendiri dikecualikan dari penyaring "sudah terpakai" --
  | kalau tidak, membuka Claim lama akan kehilangan tautan perdinnya.
  */
    const response = await axios.get('/business-trip/perdin/eligible', {
      params: { claim_public_id: publicId.value },
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
/*
| Dua pemicu, dan yang kedua yang menentukan di halaman sunting.
|
| Perlu tidaknya perdin baru bisa dijawab setelah daftar keterangan
| transaksinya tiba. Tanpa memuat ulang di saat itu, halaman yang
| memuat dokumennya lebih dulu akan berhenti dengan pemilih perdin
| kosong selamanya -- dan urutan pemuatan bukan sesuatu yang pantas
| dijadikan sandaran diam-diam.
*/
/*
|--------------------------------------------------------------------------
| Himpunan keterangan transaksi
|--------------------------------------------------------------------------
| Headernya menyatakan himpunan; tiap baris rincian memilih salah satunya,
| dengan ketentuan tiap anggotanya punya minimal satu baris.
|--------------------------------------------------------------------------
*/
const sortByMaster = (ids: number[]): number[] => {
  const urutan = transactionCategoryList.value.map(satu => Number(satu.id))

  return [...new Set(ids.map(Number))]
    .filter(id => id > 0)
    .sort((a, b) => {
      const ia = urutan.indexOf(a)
      const ib = urutan.indexOf(b)

      /* Yang tidak ada di daftar ditaruh di belakang, bukan di depan. */
      return (ia < 0 ? Number.MAX_SAFE_INTEGER : ia) - (ib < 0 ? Number.MAX_SAFE_INTEGER : ib)
    })
}

const setCategorySet = (ids: number[]): void => {
  form.transaction_category_ids = sortByMaster(ids)

  /* Penentunya selalu anggota himpunannya. */
  form.transaction_category_id = form.transaction_category_ids[0] ?? null
}

/**
 * Himpunan keterangan transaksi dokumen yang baru dimuat.
 *
 * Jatuh ke penentunya untuk Claim yang dibuat sebelum himpunan ini ada.
 * Servernya sudah melakukan hal yang sama lewat idsFor(); diulang di sini
 * supaya layar tidak bergantung pada muatan yang bisa saja datang dari
 * jawaban lama yang tersimpan di cache peramban.
 */
const readCategorySet = (
  data: { transaction_category_ids?: unknown },
  penentu: number | null,
): number[] => {
  const himpunan = data?.transaction_category_ids

  if (Array.isArray(himpunan) && himpunan.length)
    return himpunan.map((id: unknown) => Number(id))

  return penentu ? [penentu] : []
}

/**
 * Keterangan transaksi sebuah baris tersimpan.
 *
 * null dipertahankan sebagai null -- itu baris lama yang memang ikut
 * headernya, bukan nol.
 */
const readLineCategory = (nilai: unknown): number | null =>
  (nilai === null || nilai === undefined ? null : Number(nilai))

/** Baris rincian perlu menyebut keterangan transaksinya sendiri. */
const lineNeedsCategory = computed<boolean>(() =>
  form.transaction_category_ids.length > 1)

/**
 * Kolom keterangan transaksi digambar pada rinciannya.
 *
 * Berbeda dari lineNeedsCategory, yang menjawab "perlukah pengguna
 * memilih". Pada keterangan tunggal tidak ada yang perlu dipilih --
 * tetapi tetap perlu TERLIHAT, supaya rincian yang sedang diisi
 * menyebutkan untuk apa ia dibuat tanpa menggulung ke atas.
 */
/**
 * Keterangan transaksi satu-satunya, bila himpunannya memang tunggal.
 *
 * null saat himpunannya panjang: di sana memilih adalah pekerjaan
 * pengguna, dan tebakan yang salah tidak akan diperiksa siapa pun lagi.
 */
const keteranganTunggal = computed<number | null>(() =>
  form.transaction_category_ids.length === 1
    ? form.transaction_category_ids[0]
    : null)

const lineShowsCategory = computed<boolean>(() =>
  form.transaction_category_ids.length > 0)

/*
| Pilihan yang ditawarkan di baris: HANYA anggota himpunan headernya.
|
| Menawarkan seluruh master di baris akan membuat pilihannya ditolak
| server, sesudah diklik.
*/
const selectedCategoryOptions = computed<{ id: number; title: string }[]>(() =>
  form.transaction_category_ids
    .map(id => transactionCategoryList.value.find(satu => Number(satu.id) === Number(id)))
    .filter((satu): satu is { id: number; title: string } => Boolean(satu))
    .map(satu => ({ id: Number(satu.id), title: String(satu.title) })))

/**
 * Nama keterangan transaksi sebuah baris, dicari di himpunan headernya.
 */
const lineCategoryName = (categoryId: number | null): string => {
  const ketemu = selectedCategoryOptions.value.find(
    satu => Number(satu.id) === Number(categoryId),
  )

  return ketemu ? ketemu.title : ''
}

watch([() => form.transaction_category_id, transactionCategoryList], () => {
  loadBusinessTrips()
})

/*
| Tanggal baris rincian mengikuti periode perjalanannya.
|
| Biaya sebuah perjalanan tidak mungkin bertanggal di luar perjalanan itu.
| Dibatasi di kalendernya, bukan hanya ditolak sesudah dikirim -- tanggal
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
        text: getApiErrorMessage(error, t('claim.form.toast.loadAccessFailed')),
      })
    }
  }
  finally {
    isLoadingBranch.value = false
    isLoadingDepartment.value = false
  }
}

const loadClaim = async (): Promise<void> => {
  isLoading.value = true

  try {
    const response = await axios.get(
      `/fund-request/claim/${publicId.value}/edit`,
      { headers: { Accept: 'application/json' } },
    )

    const data = response.data?.data

    if (!data) {
      showErrorToast({
        title: t('common.alert.error'),
        text: t('claim.form.toast.loadFailed'),
      })

      await router.replace('/fund_request/claim')

      return
    }

    claimNumber.value = data.claim_number || '-'

    revisiTerbuka.value = data.revision_open || null

    form.date = data.date || ''
    form.branch = data.branch !== null && data.branch !== undefined
      ? Number(data.branch)
      : null
    form.department_id = data.department_id ? Number(data.department_id) : null

    form.transaction_category_id = data.transaction_category_id
      ? Number(data.transaction_category_id)
      : null

    /*
    | Himpunan keterangan transaksinya ikut dipulihkan.
    |
    | Tanpa ini, membuka Claim lalu menyimpannya kembali akan menyusutkan
    | himpunannya menjadi penentunya saja -- dan baris rincian yang memakai
    | keterangan lain mendadak berada di luar himpunan headernya sendiri.
    |
    | Jatuh ke penentunya untuk Claim yang dibuat sebelum himpunan ini ada.
    | Servernya sudah melakukan hal yang sama lewat idsFor(); diulang di sini
    | supaya layar tidak bergantung pada muatan yang bisa saja datang dari
    | jawaban lama yang tersimpan di cache peramban.
    */
    form.transaction_category_ids = readCategorySet(data, form.transaction_category_id)

    /*
     * Tautan perdinnya ikut dimuat, kalau tidak menyimpan ulang dokumen yang
     * tidak disentuh justru akan memutus tautannya.
     */
    form.business_trip_id = data.business_trip_id
      ? Number(data.business_trip_id)
      : null

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

        /*
        | Keterangan transaksi barisnya ikut dipulihkan. Tanpa ini, menyunting
        | lalu menyimpan kembali akan mengosongkan pilihan tiap barisnya -- dan
        | pada himpunan berisi lebih dari satu, server menolaknya dengan menyebut
        | nomor baris yang pengisinya tidak pernah sentuh.
        */
        transaction_category_id: readLineCategory(item.transaction_category_id),

        attachments: [] as File[],
        existing: Array.isArray(item.attachments)
          ? item.attachments.map(mapAttachment)
          : [],
      }))
      : [createEmptyItem()]

    /*
     * Lampiran tingkat dokumen hanya tersisa pada Claim lama yang dibuat sebelum
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
      text: getApiErrorMessage(error, t('claim.form.toast.loadFailed')),
    })

    await router.replace('/fund_request/claim')
  }
  finally {
    isLoading.value = false
  }
}

const handleBranchChange = (): void => {
  updateDepartmentListByBranch(form.branch, false)
}

/*
 * Rincian disunting di modal layar penuh, mengikuti pola Purchase Requisition.
 * Halaman utama hanya menampilkan ringkasannya sebagai teks.
 */
const itemDialog = ref(false)
const itemDialogSaved = ref(false)
const confirmCloseItemDialog = ref(false)
const tempItems = ref<ClaimItemForm[]>([])

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
const cloneItems = (items: ClaimItemForm[]): ClaimItemForm[] =>
  items.map(item => ({
    ...item,
    attachments: [...item.attachments],
    existing: [...item.existing],
  }))

const openItemFullscreen = (): void => {
  tempItems.value = cloneItems(form.items)
  tempDeletedIds.value = [...deletedAttachmentIds.value]

  /*
  | Satu baris disediakan untuk tiap keterangan transaksi yang belum punya
  | baris.
  |
  | Ketentuannya memang tiap keterangan transaksi wajib punya minimal satu
  | baris; menyediakannya di sini membuat ketentuan itu terpenuhi dengan
  | sendirinya, bukan menjadi penolakan yang baru muncul saat menyimpan.
  */
  if (lineNeedsCategory.value) {
    form.transaction_category_ids.forEach(id => {
      const sudahAda = tempItems.value.some(
        item => Number(item.transaction_category_id) === Number(id),
      )

      if (!sudahAda) {
        tempItems.value.push({
          ...createEmptyItem(),
          transaction_category_id: id,
        })
      }
    })
  }
  else if (!tempItems.value.length) {
    tempItems.value = [{
      ...createEmptyItem(),
      transaction_category_id: keteranganTunggal.value,
    }]
  }

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

/**
 * Keterangan transaksi yang dipilih tetapi belum punya satu baris pun.
 *
 * Barisnya memang disediakan saat modalnya dibuka, tetapi yang disediakan
 * bisa dihapus. Tanpa pemeriksaan ini headernya menyatakan menagih tiga
 * hal sementara isinya hanya memuat dua -- dan yang membaca cetakannya
 * tidak punya cara tahu mana yang hilang, apakah memang tidak ditagih
 * atau lupa diisi.
 *
 * Kosong bila himpunannya tunggal: di sana tidak ada yang perlu dibagi.
 */
const categoriesWithoutItem = (daftar: ClaimItemForm[]): string[] => {
  if (!lineNeedsCategory.value)
    return []

  return form.transaction_category_ids
    .filter(id => !daftar.some(
      item => Number(item.transaction_category_id) === Number(id),
    ))
    .map(id => lineCategoryName(id))
    .filter(nama => nama !== '')
}

/**
 * Apa yang salah dengan keterangan transaksi pada rinciannya, bila ada.
 *
 * Dipakai saat formulirnya disimpan -- bukan hanya saat modalnya ditutup.
 * Headernya bisa diganti SESUDAH rinciannya disimpan, dan modalnya belum
 * tentu dibuka lagi; penjaga yang hanya berdiri di satu pintu tidak
 * menjaga apa pun bagi orang yang tidak lewat situ.
 *
 * Mengembalikan kalimatnya, bukan sekadar benar-salah: yang menanyakannya
 * tidak perlu tahu keluhan mana yang keluar.
 */
const breakdownCategoryIssue = (): string | null => {
  if (!form.items.length)
    return t('claim.form.toast.minOneItem')

  if (lineNeedsCategory.value) {
    const tanpaKategori = form.items.findIndex(
      item => !Number(item.transaction_category_id),
    )

    if (tanpaKategori !== -1) {
      return t('claim.form.toast.itemTransactionCategoryRequired', {
        number: tanpaKategori + 1,
      })
    }
  }

  const tanpaBaris = categoriesWithoutItem(form.items)

  return tanpaBaris.length
    ? t('claim.form.toast.categoryWithoutItem', {
      categories: tanpaBaris.join(', '),
    })
    : null
}

/*
| Baris disesuaikan setiap himpunannya berganti.
|
| Menggantikan aturan lama yang mengosongkan SELURUH rincian begitu
| keterangan transaksinya berganti. Aturan itu lahir ketika keterangan
| transaksi hanya bisa satu; sejak himpunannya bisa panjang, mencabut satu
| anggota berarti membuang baris yang tidak ada hubungannya dengan anggota
| itu.
|
| Dua arah, dan keduanya perlu:
|
| - Himpunannya menyusut menjadi satu: baris yang belum memilih diisikan
|   yang satu itu. Kolomnya hilang dari layar, jadi tidak ada lagi tempat
|   bagi pengguna untuk memperbaikinya sendiri.
|
| - Anggota dicabut dari himpunannya: baris yang memakainya dibuang --
|   BUKAN dilabeli ulang. Baris yang menagih taksi tidak boleh berubah
|   menjadi menagih makan tanpa satu pun galat: angkanya benar,
|   keterangannya bohong, dan tersimpan begitu.
*/
watch(() => form.transaction_category_ids, himpunan => {
  const tunggal = himpunan.length === 1 ? himpunan[0] : null

  const sesuaikan = (daftar: ClaimItemForm[]): ClaimItemForm[] =>
    daftar.filter(baris => {
      const dipakai = Number(baris.transaction_category_id)

      if (!dipakai) {
        baris.transaction_category_id = tunggal

        return true
      }

      return himpunan.includes(dipakai)
    })

  /* Keduanya: yang sedang disunting di modal, dan yang akan terkirim. */
  tempItems.value = sesuaikan(tempItems.value)

  const sebelum = form.items.length

  form.items = sesuaikan(form.items)

  /*
  | Dikabarkan, bukan dibuang diam-diam.
  |
  | Baris yang sudah diketik orang -- berikut bukti yang sudah diunggah --
  | tidak pantas lenyap tanpa sepatah kata, walau lenyapnya memang benar.
  */
  const hilang = sebelum - form.items.length

  if (hilang > 0) {
    showInfoToast({
      title: t('claim.form.categorySet.linesDroppedTitle'),
      text: t('claim.form.categorySet.linesDroppedText', { count: hilang }),
    })
  }
}, { deep: true })

const saveItemsFromDialog = (): void => {
  /*
  | Keterangan transaksi barisnya, bila headernya memilih lebih dari satu.
  |
  | Ditahan di sini, bukan hanya di server: modalnya ditutup lalu
  | halamannya tampak siap dikirim, dan penolakan yang baru datang di
  | tombol simpan memaksa orangnya membuka modalnya lagi untuk mencari
  | baris mana.
  */
  if (lineNeedsCategory.value) {
    const withoutCategory = tempItems.value.findIndex(
      item => !Number(item.transaction_category_id),
    )

    if (withoutCategory !== -1) {
      isSubmitted.value = true

      showWarningToast({
        title: t('common.alert.warning'),
        text: t('claim.form.toast.itemTransactionCategoryRequired', {
          number: withoutCategory + 1,
        }),
      })

      return
    }
  }

  /*
  | Tiap keterangan transaksi yang dipilih harus punya minimal satu baris.
  |
  | Barisnya memang disediakan saat modalnya dibuka, tetapi yang disediakan
  | bisa dihapus. Jalan keluarnya disebutkan dua-duanya: tambah barisnya,
  | atau cabut keterangan transaksinya di bagian atas formulir.
  */
  const tanpaBaris = categoriesWithoutItem(tempItems.value)

  if (tanpaBaris.length) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('claim.form.toast.categoryWithoutItem', {
        categories: tanpaBaris.join(', '),
      }),
    })

    return
  }

  /*
   * Tanggal wajib pada setiap baris. Dipisah dari pemeriksaan deskripsi
   * dan nominal supaya pesannya menunjuk langsung ke kolom yang kosong.
   */
  const withoutDate = tempItems.value.findIndex(item => !String(item.date ?? '').trim())

  if (withoutDate !== -1) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('claim.form.toast.itemDateRequired', { number: withoutDate + 1 }),
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
      text: t('claim.form.toast.completeItemRow', { number: invalidIndex + 1 }),
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
      text: t('claim.form.toast.itemAttachmentRequired', {
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
  tempItems.value.push({
    ...createEmptyItem(),
    transaction_category_id: keteranganTunggal.value,
  })
}

const removeItemRow = (index: number): void => {
  if (tempItems.value.length <= 1)
    return

  tempItems.value.splice(index, 1)
}

const resetItems = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: t('claim.form.items.resetConfirmTitle'),
    text: t('claim.form.items.resetConfirmText'),
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
        t('claim.form.toast.invalidFileType', { name: file.name }),
      )

      continue
    }

    if (file.size > MAX_FILE_SIZE) {
      invalidMessages.push(
        t('claim.form.toast.invalidFileSize', { name: file.name }),
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
      title: t('claim.form.toast.invalidFileTitle'),
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
    || (needsBusinessTrip.value && !required(form.business_trip_id))
    || !form.subject.trim()
  ) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('claim.form.toast.completeRequiredData'),
    })

    return false
  }

  /*
  | Keterangan transaksi tiap baris, diperiksa lagi di sini.
  |
  | Penjaganya sudah ada di pintu modal, tetapi headernya bisa diganti
  | SESUDAH rinciannya disimpan -- dan modalnya belum tentu dibuka lagi.
  | Keluhan "minimal satu baris" ikut keluar dari sini, supaya urutan
  | pemeriksaannya hanya ditulis di satu tempat.
  */
  const keluhanKategori = breakdownCategoryIssue()

  if (keluhanKategori) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: keluhanKategori,
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
      text: t('claim.form.toast.itemDateRequired', { number: withoutDate + 1 }),
    })

    return false
  }

  const invalidIndex = form.items.findIndex(
    item => !item.description.trim() || Number(item.amount || 0) <= 0,
  )

  if (invalidIndex !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('claim.form.toast.completeItemRow', { number: invalidIndex + 1 }),
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
      text: t('claim.form.toast.itemAttachmentRequired', {
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
  | Himpunannya, berikut penentunya di atas. Keduanya dikirim: penentunya
  | masih memilih jadwal pembayaran, dan server tetap mewajibkannya.
  */
  form.transaction_category_ids.forEach(id => {
    formData.append('transaction_category_ids[]', String(id))
  })

  /*
  | Hanya dikirim bila keterangan transaksinya memang menuntutnya. Server
  | mengabaikannya pada kategori lain, tetapi mengirimnya tetap keliru --
  | ia menyatakan tautan yang tidak ada.
  */
  if (needsBusinessTrip.value && form.business_trip_id)
    formData.append('business_trip_id', String(form.business_trip_id))
  formData.append('notes', form.notes || '')

  formData.append(
    'items',
    JSON.stringify(
      form.items.map(item => ({
        id: item.id,
        date: item.date || null,
        description: item.description,
        amount: Number(item.amount || 0),

        /* Keterangan transaksi barisnya. */
        transaction_category_id: item.transaction_category_id,
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

const updateClaim = async (event?: Event): Promise<void> => {
  event?.preventDefault()
  event?.stopPropagation()

  if (isSaving.value)
    return

  isSubmitted.value = true

  if (!validateForm())
    return

  const confirm = await showConfirmAlert({
    title: t('claim.form.toast.updateConfirmTitle'),
    text: t('claim.form.toast.updateConfirmText'),
    confirmButtonText: t('common.actions.confirm'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed)
    return

  isSaving.value = true

  try {
    showLoadingAlert(
      t('claim.form.toast.savingData'),
      t('common.alert.pleaseWait'),
    )

    await axios.post(
      `/fund-request/claim/${publicId.value}`,
      buildFormData(),
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    await router.replace({
      path: '/fund_request/claim',
      query: { success: 'updated' },
    })
  }
  catch (error: unknown) {
    closeAlert()

    console.error('[Cash Advance] UPDATE ERROR:', error)

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('claim.form.toast.saveFailed')),
    })
  }
  finally {
    isSaving.value = false
  }
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: t('claim.form.toast.cancelConfirmTitle'),
    text: t('claim.form.toast.cancelConfirmText'),
    confirmButtonText: t('common.actions.confirm'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (confirm.isConfirmed)
    await router.replace('/fund_request/claim')
}

const goBack = async (): Promise<void> => {
  await router.replace('/fund_request/claim')
}

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canUpdate.value) {
    await router.replace('/forbidden')

    return
  }

  if (!publicId.value) {
    await router.replace('/fund_request/claim')

    return
  }

  isCheckingPermission.value = false

  await Promise.all([
    fetchAccessAssignments(false),
    loadTransactionCategories(),
  ])
  await loadClaim()
})

/*
| Revisi yang sedang berjalan, bila dokumen ini dikembalikan.
|
| Ditampilkan di atas formnya. Alasan yang hanya bisa dibaca di
| layar lain sama saja dengan tidak ada: pemohon akan menutup
| formnya, mencari alasannya, lalu membukanya lagi.
*/
const revisiTerbuka = ref<RevisionEntry | null>(null)
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
            {{ t('claim.form.loadingTitle') }}
          </div>

          <div class="text-body-2 text-medium-emphasis">
            {{ t('common.alert.pleaseWait') }}
          </div>
        </div>
      </div>
    </VCardText>
  </VCard>

  <section v-else>
    <!--
      Alasan pengembaliannya, paling atas.

      Hanya bagian "sedang berjalan" dari komponen riwayat: di form
      edit yang dibutuhkan bukan riwayat, melainkan satu hal yang
      harus diperbaiki sekarang.
    -->
    <RevisionHistory
      v-if="revisiTerbuka"
      :open-revision="revisiTerbuka"
    />
    <VRow>
      <VCol cols="12">
        <VCard>
          <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-3">
            <div>
              <div class="text-h6 font-weight-bold">
                {{ t('claim.form.editTitle') }} — {{ claimNumber }}
              </div>

              <div class="text-body-2 text-medium-emphasis">
                {{ t('claim.form.editSubtitle') }}
              </div>
            </div>

            <VBtn
              prepend-icon="mdi-arrow-left"
              variant="text"
              color="secondary"
              class="text-none"
              @click="goBack"
            >
              {{ t('claim.form.backButton') }}
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
                  :label="t('claim.form.fields.date')"
                  :placeholder="t('claim.form.placeholders.date')"
                  :config="{ dateFormat: 'Y-m-d', position: 'below' }"
                  :error="isSubmitted && !form.date"
                  :error-messages="isSubmitted && !form.date ? [t('claim.form.validation.date')] : []"
                />
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <VAutocomplete
                  v-model="form.branch"
                  :label="t('claim.form.fields.branch')"
                  :items="branchList"
                  item-title="title"
                  item-value="value"
                  density="comfortable"
                  :loading="isLoadingBranch"
                  :clearable="branchList.length > 1"
                  :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                  :error="isSubmitted && !form.branch"
                  :error-messages="isSubmitted && !form.branch ? [t('claim.form.validation.branch')] : []"
                  :no-data-text="t('claim.form.noData.branch')"
                  :placeholder="t('claim.form.placeholders.branch')"
                  @update:model-value="handleBranchChange"
                />
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <VAutocomplete
                  v-model="form.department_id"
                  :label="t('claim.form.fields.department')"
                  :items="departmentList"
                  item-title="label"
                  item-value="id"
                  density="comfortable"
                  :loading="isLoadingDepartment"
                  :disabled="!form.branch"
                  :clearable="departmentList.length > 1"
                  :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                  :error="isSubmitted && !form.department_id"
                  :error-messages="isSubmitted && !form.department_id ? [t('claim.form.validation.department')] : []"
                  :no-data-text="t('claim.form.noData.department')"
                  :placeholder="t('claim.form.placeholders.department')"
                />
              </VCol>

              <VCol cols="12">
                <!--
                  Keterangan transaksi ikut menentukan approval flow nanti,
                  sama seperti pada FPU.
                -->
                <VAutocomplete
                  :model-value="form.transaction_category_ids"
                  :label="t('claim.form.fields.transactionCategory')"
                  :items="transactionCategoryList"
                  item-title="title"
                  item-value="id"
                  density="comfortable"
                  :loading="isLoadingTransactionCategory"
                  clearable

                  multiple
                  chips
                  closable-chips

                  :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                  :error="isSubmitted && !form.transaction_category_ids.length"
                  :error-messages="isSubmitted && !form.transaction_category_ids.length ? [t('claim.form.validation.transactionCategory')] : []"
                  :hint="t('claim.form.categorySet.hint')"
                  persistent-hint
                  :no-data-text="t('claim.form.noData.transactionCategory')"
                  :placeholder="t('claim.form.placeholders.transactionCategory')"
                  @update:model-value="nilai => setCategorySet((nilai as number[]) ?? [])"
                />

                <!--
                  PEMILIH PERDIN

                  Muncul hanya bila keterangan transaksinya menuntut dokumen
                  Perdin. Daftarnya sudah disaring server: milik pemohon,
                  sudah disetujui, dan belum dipegang FPU atau Claim lain
                  yang masih berjalan.
                -->
                <VAutocomplete
                  v-if="needsBusinessTrip"
                  v-model="form.business_trip_id"
                  :label="t('claim.form.fields.businessTrip')"
                  :items="businessTripList"
                  item-value="id"
                  density="comfortable"
                  class="mt-4"
                  :loading="isLoadingBusinessTrip"
                  clearable
                  :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300 }"
                  :error="isSubmitted && !form.business_trip_id"
                  :error-messages="isSubmitted && !form.business_trip_id
                    ? [t('claim.form.validation.businessTrip')]
                    : []"
                  :no-data-text="t('claim.form.noData.businessTrip')"
                  :placeholder="t('claim.form.placeholders.businessTrip')"
                  persistent-placeholder
                  :hint="t('claim.form.hints.businessTrip')"
                  persistent-hint
                >
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
                            ? t('claim.form.businessTripState.approved')
                            : t('claim.form.businessTripState.pending') }}
                        </VChip>
                      </template>
                    </VListItem>
                  </template>

                  <template #selection="{ item }">
                    <span>{{ item.raw.trip_number }} &mdash; {{ item.raw.destination }}</span>
                  </template>
                </VAutocomplete>

                <!--
                  Periode perjalanannya, sebagai pegangan saat mengisi rincian.
                  Tanggal di luar periode tidak bisa dipilih di kalendernya.
                -->
                <VAlert
                  v-if="needsBusinessTrip && selectedBusinessTrip"
                  type="info"
                  variant="tonal"
                  density="compact"
                  class="mt-2"
                >
                  {{ t('claim.form.hints.businessTripPeriod', {
                    from: selectedBusinessTrip.depart_date,
                    to: selectedBusinessTrip.return_date,
                  }) }}
                </VAlert>
              </VCol>

              <VCol cols="12">
                <VTextarea
                  v-model="form.subject"
                  :label="t('claim.form.fields.subject')"
                  :placeholder="t('claim.form.placeholders.subject')"
                  rows="2"
                  auto-grow
                  :error="isSubmitted && !form.subject.trim()"
                  :error-messages="isSubmitted && !form.subject.trim() ? [t('claim.form.validation.subject')] : []"
                />
              </VCol>
            </VRow>

            <!-- RINCIAN PENGAJUAN -->
            <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-4 mb-3">
              <div>
                <div class="text-subtitle-1 font-weight-bold">
                  {{ t('claim.form.items.sectionTitle') }}
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
                  {{ t('claim.form.items.addButton') }}

                  <VTooltip
                    activator="parent"
                    location="top"
                    :offset="4"
                  >
                    {{ t('claim.form.items.addButtonTooltip') }}
                  </VTooltip>
                </VBtn>

                <!-- Tidak ada yang bisa direset selama rinciannya kosong. -->
                <VBtn
                  type="button"
                  color="error"
                  variant="outlined"
                  size="small"
                  class="text-none"
                  :disabled="!hasItemDetails"
                  @click="resetItems"
                >
                  {{ t('claim.form.items.resetButton') }}
                </VBtn>
              </div>
            </div>

            <!--
              Ringkasan bacl-saja. Penyuntingan dilakukan di modal layar penuh,
              mengikuti pola Purchase Requisition.
            -->
            <VCard
              flat
              class="cl-summary-card"
            >
              <VCardText>
                <VAlert
                  v-if="!hasItemDetails"
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  {{ t('claim.form.items.emptyAlert', { action: t('claim.form.items.addButton') }) }}
                </VAlert>

                <!--
                  Syaratnya disebut, bukan v-else.

                  v-else berpasangan dengan apa pun yang kebetulan berdiri
                  tepat di atasnya; sekali ada yang menyisipkan peringatan di
                  antaranya, pasangannya berpindah tanpa bersuara.
                -->
                <div
                  v-if="hasItemDetails"
                  class="d-flex flex-column gap-3"
                >
                  <div
                    v-for="(item, index) in form.items"
                    :key="`summary-item-${index}`"
                    class="cl-summary-row"
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
                          {{ t('claim.form.items.tableDate') }}:
                          <strong>{{ item.date || '-' }}</strong>
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
                          {{ t('claim.form.items.tableAmount') }}
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
              <div class="cl-total-box">
                <span>{{ t('claim.form.items.total') }}</span>
                <strong>Rp {{ formatMoney(totalAmount) || '0' }}</strong>
              </div>
            </div>

            <!--
              LAMPIRAN TINGKAT DOKUMEN (warisan)

              Hanya muncul pada Claim yang dibuat sebelum bukti dipindah ke
              tingkat baris. Tidak ada lagi jalur yang menambah berkas ke sini;
              yang tersisa hanya bisa dibuka atau dihapus.
            -->
            <div
              v-if="existingAttachments.length"
              class="mt-6"
            >
              <div class="text-subtitle-1 font-weight-bold mb-2">
                {{ t('claim.form.attachment.existingTitle') }}
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
                      {{ t('claim.form.attachment.deleteButton') }}
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
              :label="t('claim.form.fields.notes')"
              :placeholder="t('claim.form.placeholders.notes')"
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
                {{ t('claim.form.buttons.cancel') }}
              </VBtn>

              <VBtn
                type="button"
                color="primary"
                class="text-none"
                :loading="isSaving"
                @click.prevent.stop="updateClaim($event)"
              >
                {{ t('claim.form.buttons.update') }}
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
            {{ t('claim.form.itemDialog.title') }}
          </VToolbarTitle>

          <VSpacer />

          <VBtn
            variant="flat"
            class="me-3 text-none"
            prepend-icon="tabler-plus"
            @click="addItemRow"
          >
            {{ t('claim.form.itemDialog.addRowButton') }}
          </VBtn>

          <VBtn
            variant="flat"
            class="me-3 text-none"
            @click="saveItemsFromDialog"
          >
            {{ t('claim.form.itemDialog.saveButton') }}
          </VBtn>
        </VToolbar>

        <VCardText class="pa-4">
          <div class="cl-table-wrapper">
            <VTable
              class="cl-item-table"
              :class="{ 'cl-item-table--trxcat': lineShowsCategory }"
            >
              <thead>
                <tr>
                  <th class="cl-col-no">
                    {{ t('claim.form.items.tableNo') }}
                  </th>
                  <th class="cl-col-date">
                    {{ t('claim.form.items.tableDate') }}
                    <span class="text-error">*</span>
                  </th>
                  <!--
                    Keterangan transaksi barisnya. Muncul hanya bila headernya
                    memilih lebih dari satu -- kolom berpilihan tunggal hanya
                    menambah lebar tanpa menambah keterangan.
                  -->
                  <th
                    v-if="lineShowsCategory"
                    class="cl-col-trxcat"
                  >
                    {{ t('claim.form.items.tableTransactionCategory') }}
                    <span class="text-error">*</span>
                  </th>

                  <th class="cl-col-desc">
                    {{ t('claim.form.items.tableDescription') }}
                    <span class="text-error">*</span>
                  </th>
                  <th class="cl-col-amount">
                    {{ t('claim.form.items.tableAmount') }}
                    <span class="text-error">*</span>
                  </th>
                  <th class="cl-col-attachment">
                    {{ t('claim.form.items.tableAttachment') }}
                    <span class="text-error">*</span>
                  </th>
                  <th class="cl-col-action text-center">
                    {{ t('claim.form.items.tableActions') }}
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

                  <td v-if="lineShowsCategory">
                    <!--
                      Nama keterangan transaksi di Claim panjang-panjang, jadi
                      yang terpilih DIPOTONG di selnya (lihat .cl-item-table di
                      bawah) -- bukan dibungkus, yang membuatnya menimpa kolom
                      sebelahnya. Yang terpotong tetap terbaca dua cara: daftar
                      pilihannya dilebarkan sendiri, dan namanya utuh di title.
                    -->
                    <VSelect
                      v-model="item.transaction_category_id"
                      :items="selectedCategoryOptions"
                      item-title="title"
                      item-value="id"
                      density="compact"
                      variant="outlined"
                      hide-details="auto"
                      :menu-props="{ location: 'bottom', offset: 8, maxHeight: 300, minWidth: 320 }"
                      :title="lineCategoryName(item.transaction_category_id)"
                      :error="isSubmitted && !item.transaction_category_id"
                      :error-messages="isSubmitted && !item.transaction_category_id
                        ? [t('claim.form.validation.itemTransactionCategory')]
                        : []"
                    />
                  </td>

                  <td>
                    <VTextField
                      v-model="item.description"
                      density="compact"
                      variant="outlined"
                      hide-details="auto"
                      :error="isSubmitted && !item.description.trim()"
                      :error-messages="isSubmitted && !item.description.trim() ? [t('claim.form.validation.itemDescription')] : []"
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
                      :error-messages="isSubmitted && Number(item.amount || 0) <= 0 ? [t('claim.form.validation.itemAmount')] : []"
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
                        {{ t('claim.form.attachment.addButton') }}
                      </VBtn>

                      <div
                        v-if="isSubmitted && lineMissingAttachment(index)"
                        class="text-error text-caption"
                      >
                        {{ t('claim.form.validation.itemAttachment') }}
                      </div>

                      <!-- Bukti yang sudah tersimpan -->
                      <div
                        v-for="attachment in item.existing"
                        :key="`item-${index}-existing-${attachment.id}`"
                        class="cl-line-file"
                      >
                        <VIcon
                          :icon="getFileType(attachment.mime_type) === 'PDF' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                          size="18"
                          color="primary"
                        />

                        <div class="cl-line-file__body">
                          <a
                            v-if="attachment.url"
                            :href="attachment.url"
                            target="_blank"
                            rel="noopener"
                            class="cl-line-file__name d-block text-primary"
                          >
                            {{ attachment.original_filename || attachment.filename }}
                          </a>

                          <div
                            v-else
                            class="cl-line-file__name"
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
                        class="cl-line-file"
                      >
                        <VIcon
                          :icon="file.type === 'application/pdf' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                          size="18"
                          color="success"
                        />

                        <div class="cl-line-file__body">
                          <div class="cl-line-file__name">
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
          {{ t('claim.form.itemDialog.discardTitle') }}
        </VCardTitle>

        <VCardText>
          {{ t('claim.form.itemDialog.discardText') }}
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
            {{ t('claim.form.itemDialog.discardButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>
/* Ringkasan rincian pada halaman utama. */
.cl-summary-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
}

.cl-summary-row {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  padding-block: 0.75rem;
  padding-inline: 0.875rem;
}

/*
| TIDAK ber-overflow sendiri.
|
| VTable sudah membawa penggulirnya pada .v-table__wrapper. Menambah satu
| lagi di sini memunculkan dua batang penggulir bertumpuk -- dan yang atas
| menggulir yang bawah, bukan isinya.
|
| min-inline-size: 0 tetap perlu: tanpa itu, pembungkus yang menjadi anak
| flex atau grid tidak boleh menyusut di bawah lebar isinya, dan penggulir
| di dalamnya tidak pernah mendapat ruang untuk bekerja.
*/
.cl-table-wrapper {
  min-inline-size: 0;
}

/*
| Lebar minimumnya pada TABELNYA, bukan pada akar VTable.
|
| Dipasang di akarnya, akar itu yang melebar dan pembungkus di luarnya ikut
| menggulir -- sementara tabel di dalamnya masih bisa melebar lagi karena
| jumlah lebar minimum kolomnya tidak terikat angka ini. Dua wadah
| menggulir sekaligus, dan dua batang penggulir muncul bertumpuk.
|
| Di tabelnya, akarnya tetap selebar induknya dan hanya .v-table__wrapper
| milik Vuetify yang menggulir. Satu batang, dan kepalanya ikut bergeser
| bersama isinya -- ia memang satu tabel.
*/
.cl-item-table :deep(table) {
  min-inline-size: 960px;
}

/* Satu kolom lagi, satu kolom lebih lebar -- bukan angka yang sama. */
.cl-item-table--trxcat :deep(table) {
  min-inline-size: 1140px;
}

/*
| Teks pilihan keterangan transaksi dipotong, tidak dibungkus.
|
| Nama di master Claim panjang-panjang. Dibiarkan membungkus, satu sel
| tumbuh tiga baris dan isinya melampaui tinggi selnya sendiri --
| menimpa kolom sebelahnya, persis seperti yang terlihat di layar.
|
| min-inline-size: 0 pada wadahnya bukan hiasan: anak flex tidak boleh
| menyusut di bawah lebar isinya tanpa itu, dan ellipsis-nya tidak
| pernah dapat giliran.
*/
.cl-item-table :deep(.v-select .v-field__input) {
  flex-wrap: nowrap;
  min-inline-size: 0;
}

.cl-item-table :deep(.v-select .v-select__selection) {
  overflow: hidden;
  min-inline-size: 0;
}

.cl-item-table :deep(.v-select .v-select__selection-text) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/*
| Pada layar sempit, lebar yang dihemat diambil dari jarak sel --
| bukan dari kolomnya. Kolom yang menyusut membuat isiannya tidak
| terbaca; jarak yang menyusut hanya membuatnya rapat.
*/
@media (max-width: 600px) {
  .cl-item-table.v-table :deep(.v-table__wrapper) > table > tbody > tr > td,
  .cl-item-table.v-table :deep(.v-table__wrapper) > table > thead > tr > th {
    padding-inline: 0.5rem;
  }
}

/*
| Kolom lampiran: cukup untuk tombolnya dan kartu berkasnya, tidak
| lebih. Nama berkas yang panjang sudah dipotong di kartunya.
*/
.cl-col-attachment {
  inline-size: 13rem;
  min-inline-size: 13rem;
}

/* Kartu berkas yang menempel pada satu baris rincian. */
.cl-line-file {
  display: flex;
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  gap: 0.5rem;
  padding-block: 0.25rem;
  padding-inline: 0.5rem;
}

.cl-line-file__body {
  flex: 1;
  min-inline-size: 0;
}

.cl-line-file__name {
  overflow: hidden;
  font-size: 0.8rem;
  text-decoration: none;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cl-col-no {
  inline-size: 3rem;
  min-inline-size: 3rem;
}

/*
| Deskripsi satu-satunya kolom yang boleh melar mengisi sisa ruang.
| Batas bawahnya tetap perlu: tanpa itu ia pula yang pertama diambil
| ketika ruangnya kurang.
*/
.cl-col-desc {
  min-inline-size: 14rem;
}

/*
| Kolom tanggal butuh ruang untuk "2026-08-30" beserta padding field dan
| tombol clear-nya. Pada 12rem, tata letak tabel otomatis masih menyusutkan
| kolom ini demi kolom deskripsi sehingga tanggalnya terpotong -- karena itu
| lebarnya dikunci lewat min-inline-size, bukan sekadar inline-size.
*/
/*
| Keterangan transaksi per baris.
|
| min-inline-size, bukan inline-size: tabel auto-layout memperlakukan
| inline-size sebagai saran dan tetap memeras kolomnya saat ruangnya kurang.
*/
.cl-col-trxcat {
  inline-size: 12rem;
  min-inline-size: 12rem;
}

.cl-col-date {
  inline-size: 12rem;
  min-inline-size: 12rem;
}

.cl-col-amount {
  inline-size: 11rem;
  min-inline-size: 11rem;
}

.cl-col-action {
  inline-size: 4.5rem;
  min-inline-size: 4.5rem;
}

.cl-total-box {
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
