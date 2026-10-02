<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
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

/*
|--------------------------------------------------------------------------
| Ubah Realisasi FPU
|--------------------------------------------------------------------------
| Hanya berlaku pada dokumen draft. FPU induknya terkunci: memindahkannya
| akan membuat nomor, cabang, dan department dokumen ini tidak lagi cocok
| dengan isinya. Bila salah pilih, hapus draft-nya lalu buat ulang.
|--------------------------------------------------------------------------
*/

interface RealizationItemForm {
  cash_advance_item_id: number | null

  /*
   * Rincian berkategori, hanya pada realisasi perjalanan dinas. Null di
   * tempat lain -- di sana rinciannya memang tidak dikelompokkan dan
   * tidak berkuantitas.
   */
  expense_category_id: number | null
  qty: number | null
  unit_price: number | null
  date: string | null
  description: string
  advance_amount: number
  realization_amount: number
  notes: string

  /** Null untuk baris baru; diisi supaya baris lama diperbarui, bukan diganti. */
  id: number | null

  /*
   * Bukti melekat pada baris. attachments adalah berkas yang baru dipilih,
   * existing adalah yang sudah tersimpan.
   */
  attachments: File[]
  existing: ExistingAttachment[]
}

/**
 * Ringkasan FPU induk. Pada halaman ubah, datanya datang dari dokumen
 * realisasi itu sendiri -- bukan dari daftar FPU yang siap direalisasi,
 * karena FPU tersebut sudah tidak muncul di sana.
 */
interface CashAdvanceSummary {
  advance_number: string
  subject: string
  branch: string
  department: string
  transaction_category: string | null
  total_amount: number
}

interface ExistingAttachment {
  id: number
  original_filename: string | null
  filename: string
  mime_type: string | null
  file_size: number | null
  url: string | null
}

const route = useRoute()
const router = useRouter()
const permissionStore = usePermissionStore()
const { t } = useI18n()

const ENDPOINT = '/fund-request/cash-advance-realization'
const LIST_PATH = '/fund_request/cash_advance_realization'

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

const lineDateConfig = dialogDateConfig()
const isSaving = ref(false)

const realizationNumber = ref('-')
const existingAttachments = ref<ExistingAttachment[]>([])
const deletedAttachmentIds = ref<number[]>([])

const MAX_FILE_SIZE = 3 * 1024 * 1024
const ALLOWED_TYPES = ['application/pdf', 'image/jpeg', 'image/png']
const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png']

const attachmentError = ref('')

const sourceCashAdvance = ref<CashAdvanceSummary | null>(null)

const canUpdate = computed(() => permissionStore.can('cash_advance_realization.update'))

const form = reactive({
  date: '',
  notes: '',
  items: [] as RealizationItemForm[],
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

/*
| Asal perdin dan penanda GA, keduanya datang dari FPU-nya.
|
| Realisasi tidak pernah memutuskan sendiri bentuknya: ia mengikuti FPU
| yang direalisasikan. Kalau FPU-nya perdin, rinciannya berkelompok.
*/
const businessTripId = ref<number | null>(null)

/* Kategori yang ditandai diurus GA -- tidak ditagihkan, tanpa baris. */
const arrangedCategories = ref<number[]>([])

const needsBusinessTrip = computed<boolean>(() => businessTripId.value !== null)

/*
 * Dinamai sama dengan halaman buat supaya seluruh template di bawahnya tidak
 * perlu berbeda; di sini isinya selalu FPU induk yang sudah terkunci.
 */
const selectedCashAdvance = computed(() => sourceCashAdvance.value)

const formatMoney = (value: number | null | undefined): string => {
  if (!value)
    return ''

  return new Intl.NumberFormat('id-ID').format(Number(value))
}

/**
 * Nominal bertanda, dipakai kolom selisih yang bisa negatif.
 */
const formatSigned = (value: number): string => {
  const rounded = Math.round(Number(value || 0))

  if (rounded === 0)
    return '0'

  const prefix = rounded > 0 ? '+' : '-'

  return prefix + new Intl.NumberFormat('id-ID').format(Math.abs(rounded))
}

const getExtension = (fileName: string): string =>
  fileName.split('.').pop()?.toLowerCase() || ''

/*
|--------------------------------------------------------------------------
| Total dan selisih
|--------------------------------------------------------------------------
| Total dicairkan diambil dari nilai FPU, bukan penjumlahan baris -- baris
| di luar rencana tidak menambah uang yang sudah diterima pemohon.
|--------------------------------------------------------------------------
*/
const totalAdvance = computed(() =>
  Number(selectedCashAdvance.value?.total_amount || 0),
)

const totalRealization = computed(() =>
  form.items.reduce((total, item) => total + Number(item.realization_amount || 0), 0),
)

const differenceAmount = computed(() =>
  Math.round((totalAdvance.value - totalRealization.value) * 100) / 100,
)

const differenceLabel = computed(() => {
  if (Math.abs(differenceAmount.value) < 0.005)
    return t('cashAdvanceRealization.difference.none')

  return differenceAmount.value > 0
    ? t('cashAdvanceRealization.difference.return')
    : t('cashAdvanceRealization.difference.reimburse')
})

const differenceColor = computed(() => {
  if (Math.abs(differenceAmount.value) < 0.005)
    return 'secondary'

  return differenceAmount.value > 0 ? 'success' : 'warning'
})

/*
|--------------------------------------------------------------------------
| Muat dokumen
|--------------------------------------------------------------------------
*/
const loadRealization = async (): Promise<void> => {
  isLoading.value = true

  try {
    const response = await axios.get(`${ENDPOINT}/${publicId.value}/edit`, {
      headers: { Accept: 'application/json' },
    })

    const data = response.data?.data

    if (!data) {
      showErrorToast({
        title: t('common.alert.error'),
        text: t('cashAdvanceRealization.form.toast.loadFailed'),
      })

      await router.replace(LIST_PATH)

      return
    }

    realizationNumber.value = data.realization_number || '-'

    form.date = data.date || ''
    form.notes = data.notes || ''

    sourceCashAdvance.value = {
      advance_number: String(data.advance_number || '-'),
      subject: String(data.subject || '-'),
      branch: String(data.branch || '-'),
      department: String(data.department || '-'),
      transaction_category: data.transaction_category || null,
      total_amount: Number(data.total_advance_amount || 0),
    }

    /*
    | Bentuknya ditetapkan sebelum barisnya dibaca: pengelompokan baris
    | bergantung padanya, dan menetapkannya belakangan berarti sekejap
    | menggambar rincian perdin dengan bentuk yang bukan miliknya.
    */
    businessTripId.value = data.business_trip_id !== null && data.business_trip_id !== undefined
      ? Number(data.business_trip_id)
      : null

    /*
    | Kategori yang ditandai diurus GA. Tanpa memulihkannya, menyimpan
    | kembali realisasi lama akan mencabut penandanya -- dan kategori yang
    | sengaja dikosongkan berubah menjadi kategori yang lupa diisi.
    */
    arrangedCategories.value = Array.isArray(data.arranged_categories)
      ? data.arranged_categories.map((id: unknown) => Number(id))
      : []

    form.items = Array.isArray(data.items)
      ? data.items.map((item: any): RealizationItemForm => ({
        cash_advance_item_id: item.cash_advance_item_id
          ? Number(item.cash_advance_item_id)
          : null,
        date: item.date || null,
        description: String(item.description || ''),
        advance_amount: Number(item.advance_amount || 0),
        realization_amount: Number(item.realization_amount || 0),
        notes: item.notes || '',
        id: item.id ? Number(item.id) : null,

        /* Null di luar perdin: di sana rinciannya memang tidak berkelompok. */
        expense_category_id: item.expense_category_id !== null && item.expense_category_id !== undefined
          ? Number(item.expense_category_id)
          : null,
        qty: item.qty !== null && item.qty !== undefined ? Number(item.qty) : null,
        unit_price: item.unit_price !== null && item.unit_price !== undefined
          ? Number(item.unit_price)
          : null,
        attachments: [],
        existing: Array.isArray(item.attachments)
          ? item.attachments.map((file: any): ExistingAttachment => ({
            id: Number(file.id),
            original_filename: file.original_filename || null,
            filename: file.filename || '',
            mime_type: file.mime_type || null,
            file_size: file.file_size !== null && file.file_size !== undefined
              ? Number(file.file_size)
              : null,
            url: file.url || null,
          }))
          : [],
      }))
      : []

    existingAttachments.value = Array.isArray(data.attachments)
      ? data.attachments.map((item: any): ExistingAttachment => ({
        id: Number(item.id),
        original_filename: item.original_filename || null,
        filename: String(item.filename || ''),
        mime_type: item.mime_type || null,
        file_size: item.file_size !== null && item.file_size !== undefined
          ? Number(item.file_size)
          : null,
        url: item.url || null,
      }))
      : []
  }
  catch (error: unknown) {
    console.error('[Realisasi FPU] LOAD ERROR:', error)

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.form.toast.loadFailed')),
    })

    await router.replace(LIST_PATH)
  }
  finally {
    isLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Baris realisasi
|--------------------------------------------------------------------------
*/
/*
 * Rincian disunting di modal layar penuh, mengikuti pola Purchase Requisition.
 * Halaman utama hanya menampilkan ringkasannya sebagai teks.
 */
const itemDialog = ref(false)
const itemDialogSaved = ref(false)
const confirmCloseItemDialog = ref(false)
const tempItems = ref<RealizationItemForm[]>([])

/*
 * Penghapusan bukti lama baru dicatat saat modal disimpan, supaya membatalkan
 * modal tidak meninggalkan id yang tetap terhapus di server.
 */
const tempDeletedIds = ref<number[]>([])

/*
 * Salinan dangkal, bukan JSON clone: baris membawa objek File yang tidak
 * selamat melewati serialisasi JSON.
 */
const cloneItems = (items: RealizationItemForm[]): RealizationItemForm[] =>
  items.map(item => ({
    ...item,
    attachments: [...item.attachments],
    existing: [...item.existing],
  }))

/*
|--------------------------------------------------------------------------
| Rincian berkategori (perjalanan dinas)
|--------------------------------------------------------------------------
*/
interface ExpenseCategoryOption {
  id: number
  name: string
  sort_order: number
}

const expenseCategoryList = ref<ExpenseCategoryOption[]>([])
const isLoadingExpenseCategory = ref(false)

const loadExpenseCategories = async (): Promise<void> => {
  if (!needsBusinessTrip.value) {
    expenseCategoryList.value = []

    return
  }

  isLoadingExpenseCategory.value = true

  try {
    const response = await axios.get(
      '/fund-request/business-trip-expense-categories/dropdown-select',
      { headers: { Accept: 'application/json' } },
    )

    expenseCategoryList.value = Array.isArray(response?.data?.data)
      ? response.data.data
      : []
  }
  catch (error: unknown) {
    expenseCategoryList.value = []
  }
  finally {
    isLoadingExpenseCategory.value = false
  }
}

watch(needsBusinessTrip, () => {
  loadExpenseCategories()

  /*
  | FPU sumbernya berganti ke bukan-perdin: penanda GA dan pengelompokan
  | barisnya kehilangan artinya. Dibersihkan di sini, bukan dibiarkan
  | terbawa diam-diam ke dokumen yang tidak mengenalnya.
  */
  if (!needsBusinessTrip.value) {
    arrangedCategories.value = []

    for (const item of form.items) {
      item.expense_category_id = null
      item.qty = null
      item.unit_price = null
    }
  }
}, { immediate: true })

/**
 * Nominal realisasi sebuah baris perdin: selalu Qty x Rincian Biaya.
 *
 * Tidak pernah diketik. Server pun menghitungnya sendiri dan mengabaikan
 * angka yang dikirim layar -- yang di sini hanya menampilkan hasil yang
 * sama supaya yang mengisi tahu apa yang akan tersimpan.
 */
const lineTotal = (item: RealizationItemForm): number =>
  Math.round((Number(item.qty) || 0) * (Number(item.unit_price) || 0) * 100) / 100

/* Baris yang sedang disunting, dikelompokkan menurut kategorinya. */
const tempItemsByCategory = computed<{
  category: ExpenseCategoryOption
  rows: { item: RealizationItemForm; index: number }[]
  total: number
  arranged: boolean
}[]>(() =>
  expenseCategoryList.value.map(category => {
    const rows = tempItems.value
      .map((item, index) => ({ item, index }))
      .filter(baris => Number(baris.item.expense_category_id) === Number(category.id))

    return {
      category,
      rows,
      total: rows.reduce((jumlah, baris) => jumlah + lineTotal(baris.item), 0),
      arranged: arrangedCategories.value.includes(Number(category.id)),
    }
  }))

/*
| Rincian yang SUDAH tersimpan, dikelompokkan untuk ringkasan di halaman.
|
| Berbeda dari tempItemsByCategory yang membaca baris di dalam modal.
| Keduanya perlu: yang satu menggambar apa yang sedang disunting, yang ini
| menggambar apa yang akan terkirim.
*/
const formItemsByCategory = computed<{
  category: ExpenseCategoryOption
  rows: RealizationItemForm[]
  total: number
  arranged: boolean
}[]>(() =>
  expenseCategoryList.value
    .map(category => {
      const rows = form.items.filter(
        item => Number(item.expense_category_id) === Number(category.id),
      )

      return {
        category,
        rows,
        total: rows.reduce((jumlah, item) => jumlah + Number(item.realization_amount || 0), 0),
        arranged: arrangedCategories.value.includes(Number(category.id)),
      }
    })

    /*
    | Kategori yang kosong DAN tidak diurus GA tidak ditampilkan: ia tidak
    | mengatakan apa-apa, hanya menambah tinggi halaman.
    */
    .filter(kelompok => kelompok.rows.length > 0 || kelompok.arranged))

/* Ada sesuatu untuk ditampilkan: baris, atau kategori yang diurus GA. */
const hasBreakdownSummary = computed<boolean>(() =>
  formItemsByCategory.value.length > 0)

const tempItemsTotal = computed<number>(() =>
  tempItems.value.reduce((jumlah, item) => jumlah + lineTotal(item), 0))

/**
 * Menandai atau membatalkan "diurus GA" pada sebuah kategori.
 *
 * Menandainya membuang baris kategori itu. Keduanya pernyataan yang saling
 * meniadakan -- server menolak dokumen yang mengatakan dua-duanya -- dan
 * membuangnya di sini lebih jujur daripada menyimpannya diam-diam lalu
 * ditolak saat menyimpan.
 */
const toggleArranged = (categoryId: number): void => {
  const id = Number(categoryId)

  if (arrangedCategories.value.includes(id)) {
    arrangedCategories.value = arrangedCategories.value.filter(satu => satu !== id)

    return
  }

  arrangedCategories.value = [...arrangedCategories.value, id]

  tempItems.value = tempItems.value.filter(
    item => Number(item.expense_category_id) !== id,
  )
}

/**
 * Menambah satu baris kosong pada sebuah kategori.
 *
 * Barisnya tidak berasal dari FPU -- realisasi boleh memuat pengeluaran
 * yang tidak direncanakan, dan itulah sebabnya ia tidak sekadar menyalin.
 */
const addItemRowTo = (categoryId: number): void => {
  tempItems.value.push({
    id: null,
    cash_advance_item_id: null,
    date: null,
    description: '',
    advance_amount: 0,
    realization_amount: 0,
    notes: '',
    attachments: [],
    existing: [],
    expense_category_id: Number(categoryId),
    qty: 1,
    unit_price: 0,
  })
}

/**
 * Memeriksa rincian perdin, dan mengembalikan true bila sudah layak.
 *
 * Aturannya berbeda dari rincian biasa: tanpa tanggal, tetapi menuntut
 * kategori, Qty, dan Rincian Biaya. Totalnya tidak diperiksa -- ia selalu
 * hasil hitungan, bukan isian.
 */
const validateBreakdownRows = (): boolean => {
  /*
  | Tidak ada baris DAN tidak ada kategori yang diurus GA: realisasinya
  | tidak melaporkan apa pun dan tidak menjelaskan kenapa.
  |
  | Yang seluruhnya diurus GA justru sah: pemohon memang tidak
  | mengeluarkan apa pun karena GA yang memesan semuanya.
  */
  if (!tempItems.value.length && !arrangedCategories.value.length) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.breakdown.toastNoRows'),
    })

    return false
  }

  for (const [index, item] of tempItems.value.entries()) {
    const nomor = index + 1

    if (!Number(item.expense_category_id)) {
      showWarningToast({
        title: t('common.alert.warning'),
        text: t('cashAdvanceRealization.form.breakdown.toastCategory', { number: nomor }),
      })

      return false
    }

    if (!item.description.trim()) {
      showWarningToast({
        title: t('common.alert.warning'),
        text: t('cashAdvanceRealization.form.validation.itemDescription'),
      })

      return false
    }

    if (!(Number(item.qty) > 0)) {
      showWarningToast({
        title: t('common.alert.warning'),
        text: t('cashAdvanceRealization.form.breakdown.toastQty', { number: nomor }),
      })

      return false
    }

    if (!(Number(item.unit_price) > 0)) {
      showWarningToast({
        title: t('common.alert.warning'),
        text: t('cashAdvanceRealization.form.breakdown.toastUnitPrice', { number: nomor }),
      })

      return false
    }
  }

  return true
}

/**
 * Rincian Biaya: berformat di kotaknya, angka polos di modelnya.
 *
 * Yang tersimpan harus tetap angka. Server menolak teks berformat --
 * "24.200" mendua artinya, dan menebaknya berarti menebak nominal.
 */
const handleUnitPriceInput = (event: Event, index: number): void => {
  const target = event.target as HTMLInputElement

  const result = formatSanitizedNumberInput(target.value, formatMoney, {
    maxLength: 15,
    emptyAsZero: true,
  })

  if (!tempItems.value[index])
    return

  tempItems.value[index].unit_price = result.numeric ?? 0

  target.value = result.formatted
}

const openItemFullscreen = (): void => {
  tempItems.value = cloneItems(form.items)

  /*
  | Baris tanpa kategori dibuang sebelum modalnya terbuka.
  |
  | Ia tidak tergambar di kelompok mana pun -- tidak ada kategori yang
  | memuatnya -- sementara penjagaannya tetap melihatnya dan menolak
  | menyimpan karena baris yang tidak bisa dilihat siapa pun.
  */
  if (needsBusinessTrip.value) {
    tempItems.value = tempItems.value.filter(
      item => Number(item.expense_category_id) > 0,
    )
  }
  tempDeletedIds.value = [...deletedAttachmentIds.value]
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
  | Rincian perdin punya aturannya sendiri, dan berhenti di sini --
  | pemeriksaan di bawahnya menuntut tanggal yang pada bentuk ini memang
  | tidak ada.
  */
  if (needsBusinessTrip.value) {
    isSubmitted.value = true

    if (!validateBreakdownRows())
      return

    /*
    | Hanya baris berkategori yang disimpan; sisanya tidak pernah ada.
    |
    | Nominalnya diisi di sini, memakai hitungan yang sama persis dengan
    | server. Bukan supaya server memakainya -- ia tetap menghitung sendiri
    | dan mengabaikan kiriman -- melainkan supaya ringkasan dan totalnya di
    | layar menunjukkan angka yang akan tersimpan.
    */
    const barisBerkategori = tempItems.value
      .filter(item => Number(item.expense_category_id) > 0)
      .map(item => ({ ...item, realization_amount: lineTotal(item) }))

    form.items = cloneItems(barisBerkategori)
    deletedAttachmentIds.value = [...tempDeletedIds.value]
    itemDialogSaved.value = true
    itemDialog.value = false

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
      text: t('cashAdvanceRealization.form.toast.itemDateRequired', { number: withoutDate + 1 }),
    })

    return
  }

  const invalidIndex = tempItems.value.findIndex(
    item => !item.description.trim() || Number(item.realization_amount) < 0,
  )

  if (invalidIndex !== -1) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.completeItemRow', {
        number: invalidIndex + 1,
      }),
    })

    return
  }

  const withoutAttachment = tempItems.value.findIndex(item => item.attachments.length + item.existing.length < 1)

  if (withoutAttachment !== -1) {
    isSubmitted.value = true

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.itemAttachmentRequired', {
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

const addExtraItem = (): void => {
  tempItems.value.push({
    id: null,
    cash_advance_item_id: null,
    date: null,
    description: '',
    advance_amount: 0,
    realization_amount: 0,
    notes: '',
    attachments: [],
    existing: [],

    /* Diisi hanya pada bentuk perdin, lewat tombol milik kategorinya. */
    expense_category_id: null,
    qty: null,
    unit_price: null,
  })
}

/**
 * Baris yang berasal dari FPU tidak boleh dihapus -- perbandingan per baris
 * akan bolong. Isi 0 bila memang tidak terpakai.
 */
const removeItem = (index: number): void => {
  if (tempItems.value[index]?.cash_advance_item_id !== null)
    return

  tempItems.value.splice(index, 1)
}

const handleAmountInput = (event: Event, index: number): void => {
  const target = event.target as HTMLInputElement

  const result = formatSanitizedNumberInput(target.value, formatMoney, {
    maxLength: 15,
    emptyAsZero: true,
  })

  if (!tempItems.value[index])
    return

  tempItems.value[index].realization_amount = result.numeric ?? 0

  target.value = result.formatted
}

const itemDifference = (item: RealizationItemForm): number =>
  Math.round((Number(item.advance_amount || 0) - Number(item.realization_amount || 0)) * 100) / 100

/*
|--------------------------------------------------------------------------
| Lampiran
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| Lampiran per baris
|--------------------------------------------------------------------------
| Satu input berkas tersembunyi per baris. Dikumpulkan dalam array supaya
| tombol pada baris ke-n membuka pemilih berkas milik baris itu saja.
|--------------------------------------------------------------------------
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
        t('cashAdvanceRealization.form.toast.invalidFileType', { name: file.name }),
      )

      continue
    }

    if (file.size > MAX_FILE_SIZE) {
      invalidMessages.push(
        t('cashAdvanceRealization.form.toast.invalidFileSize', { name: file.name }),
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
      title: t('cashAdvanceRealization.form.toast.invalidFileTitle'),
      text: attachmentError.value,
    })
  }

  input.value = ''
}

const removeLineAttachment = (index: number, fileIndex: number): void => {
  tempItems.value[index]?.attachments.splice(fileIndex, 1)
}

/**
 * Menandai bukti lama sebuah baris untuk dihapus. Penghapusan sebenarnya
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
 * Bukti lama tidak langsung dihapus dari server -- hanya ditandai, lalu benar
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

/*
|--------------------------------------------------------------------------
| Simpan
|--------------------------------------------------------------------------
*/
const validateForm = (): boolean => {
  if (!form.date) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.completeRequiredData'),
    })

    return false
  }

  /*
  | Dokumen perdin yang seluruh kategorinya diurus GA sah tanpa satu
  | baris pun: pemohon memang tidak mengeluarkan apa-apa karena GA yang
  | memesan semuanya. Itu keadaan yang sudah dijelaskan, bukan formulir
  | yang belum selesai -- dan aturan yang sama sudah berlaku di modalnya.
  */
  if (!form.items.length && !(needsBusinessTrip.value && arrangedCategories.value.length)) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.minOneItem'),
    })

    return false
  }

  /*
   * Tanggal wajib pada setiap baris. Dipisah dari pemeriksaan deskripsi
   * dan nominal supaya pesannya menunjuk langsung ke kolom yang kosong.
   */
  /*
  | Bentuk perdin memang tidak bertanggal: kolomnya tidak ada di modal,
  | jadi menuntutnya di sini berarti menolak dokumen karena kolom yang
  | tidak bisa diisi siapa pun.
  */
  const withoutDate = needsBusinessTrip.value
    ? -1
    : form.items.findIndex(item => !String(item.date ?? '').trim())

  if (withoutDate !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.itemDateRequired', { number: withoutDate + 1 }),
    })

    return false
  }

  const invalidIndex = form.items.findIndex(
    item => !item.description.trim() || Number(item.realization_amount) < 0,
  )

  if (invalidIndex !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.completeItemRow', {
        number: invalidIndex + 1,
      }),
    })

    return false
  }

  /*
   * Setiap baris wajib berbukti, dihitung dari gabungan bukti lama yang masih
   * ada dan berkas yang baru dipilih.
   */
  const withoutAttachment = form.items.findIndex(
    item => item.attachments.length + item.existing.length < 1,
  )

  if (withoutAttachment !== -1) {
    showWarningToast({
      title: t('common.alert.warning'),
      text: t('cashAdvanceRealization.form.toast.itemAttachmentRequired', {
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
   * mengikuti pola form PR dan FPU.
   */
  formData.append('_method', 'PUT')

  formData.append('date', String(form.date || ''))
  formData.append('notes', form.notes || '')

  formData.append(
    'items',
    JSON.stringify(
      form.items.map(item => ({
        id: item.id,
        cash_advance_item_id: item.cash_advance_item_id,
        date: item.date || null,
        description: item.description,
        realization_amount: Number(item.realization_amount || 0),
        notes: item.notes || null,

        /* Null di luar perdin; server pun hanya membacanya di sana. */
        expense_category_id: item.expense_category_id,
        qty: item.qty,
        unit_price: item.unit_price,
      })),
    ),
  )

  /*
  | Kategori yang diurus GA dikirim terpisah dari barisnya, karena justru
  | ketiadaan barislah yang dijelaskannya. Dikirim juga ketika kosong --
  | tanpa itu, mencabut penanda terakhir tidak akan sampai ke server.
  */
  if (needsBusinessTrip.value) {
    arrangedCategories.value.forEach(id => {
      formData.append('arranged_categories[]', String(id))
    })
  }

  if (deletedAttachmentIds.value.length) {
    formData.append(
      'deleted_attachment_ids',
      JSON.stringify(deletedAttachmentIds.value),
    )
  }

  /*
   * Berkas baru dikirim berkelompok menurut nomor urut baris. Bukti lama tidak
   * dikirim ulang -- yang sudah di server tetap di sana kecuali id-nya masuk
   * deleted_attachment_ids.
   */
  form.items.forEach((item, index) => {
    item.attachments.forEach(file => {
      formData.append(`line_attachments[${index}][]`, file)
    })
  })

  return formData
}

const save = async (event?: Event): Promise<void> => {
  event?.preventDefault()
  event?.stopPropagation()

  if (isSaving.value)
    return

  isSubmitted.value = true

  if (!validateForm())
    return

  const confirm = await showConfirmAlert({
    title: t('cashAdvanceRealization.form.toast.updateConfirmTitle'),
    text: t('cashAdvanceRealization.form.toast.updateConfirmText'),
    confirmButtonText: t('common.actions.confirm'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!confirm.isConfirmed)
    return

  isSaving.value = true

  try {
    showLoadingAlert(
      t('cashAdvanceRealization.form.toast.savingData'),
      t('common.alert.pleaseWait'),
    )

    /*
     * Content-Type sengaja tidak diset manual: browser harus menyusun sendiri
     * boundary multipart, kalau ditimpa maka lampiran gagal terkirim.
     */
    await axios.post(`${ENDPOINT}/${publicId.value}`, buildFormData(), {
      headers: { Accept: 'application/json' },
    })

    closeAlert()

    await router.replace({ path: LIST_PATH, query: { success: 'updated' } })
  }
  catch (error: unknown) {
    closeAlert()

    console.error('[Realisasi FPU] SAVE ERROR:', error)

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('cashAdvanceRealization.form.toast.saveFailed')),
    })
  }
  finally {
    isSaving.value = false
  }
}

const confirmCancel = async (): Promise<void> => {
  const confirm = await showConfirmAlert({
    title: t('cashAdvanceRealization.form.toast.cancelConfirmTitle'),
    text: t('cashAdvanceRealization.form.toast.cancelConfirmText'),
    confirmButtonText: t('common.actions.confirm'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (confirm.isConfirmed)
    await router.replace(LIST_PATH)
}

const goBack = async (): Promise<void> => {
  await router.replace(LIST_PATH)
}

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canUpdate.value) {
    await router.replace('/forbidden')

    return
  }

  if (!publicId.value) {
    await router.replace(LIST_PATH)

    return
  }

  isCheckingPermission.value = false

  await loadRealization()
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
            {{ t('cashAdvanceRealization.form.loadingTitle') }}
          </div>

          <div class="text-body-2 text-medium-emphasis">
            {{ t('common.alert.pleaseWait') }}
          </div>
        </div>
      </div>
    </VCardText>
  </VCard>

  <section v-else>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between flex-wrap gap-3">
        <div>
          <div class="text-h6 font-weight-bold">
            {{ t('cashAdvanceRealization.form.editTitle') }} — {{ realizationNumber }}
          </div>

          <div class="text-body-2 text-medium-emphasis">
            {{ t('cashAdvanceRealization.form.editSubtitle') }}
          </div>
        </div>

        <VBtn
          prepend-icon="mdi-arrow-left"
          variant="text"
          color="secondary"
          class="text-none"
          @click="goBack"
        >
          {{ t('cashAdvanceRealization.form.backButton') }}
        </VBtn>
      </VCardTitle>

      <VDivider />

      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="8"
          >
            <!--
              FPU induk terkunci: memindahkannya akan membuat nomor, cabang,
              dan department dokumen ini tidak lagi cocok dengan isinya.
            -->
            <VTextField
              :model-value="sourceCashAdvance?.advance_number || '-'"
              :label="t('cashAdvanceRealization.form.fields.cashAdvance')"
              density="comfortable"
              readonly
              append-inner-icon="tabler-lock"
            />
          </VCol>

          <VCol
            cols="12"
            md="4"
          >
            <AppDateTimePicker
              v-model="form.date"
              :label="t('cashAdvanceRealization.form.fields.date')"
              :placeholder="t('cashAdvanceRealization.form.placeholders.date')"
              :config="{ dateFormat: 'Y-m-d', position: 'below' }"
              :error="isSubmitted && !form.date"
              :error-messages="isSubmitted && !form.date ? [t('cashAdvanceRealization.form.validation.date')] : []"
            />
          </VCol>
        </VRow>

        <!-- RINGKASAN FPU SUMBER -->
        <VCard
          v-if="selectedCashAdvance"
          flat
          class="car-source-card mt-2"
        >
          <VCardText>
            <div class="text-subtitle-1 font-weight-bold mb-1">
              {{ t('cashAdvanceRealization.form.source.sectionTitle') }}
            </div>

            <div class="text-caption text-medium-emphasis mb-4">
              {{ t('cashAdvanceRealization.form.source.hint') }}
            </div>

            <VRow dense>
              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.advanceNumber') }}
                </div>
                <div class="font-weight-bold">
                  {{ selectedCashAdvance.advance_number }}
                </div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.branch') }}
                </div>
                <div>{{ selectedCashAdvance.branch }}</div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.department') }}
                </div>
                <div>{{ selectedCashAdvance.department }}</div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.transactionCategory') }}
                </div>
                <div>{{ selectedCashAdvance.transaction_category || '-' }}</div>
              </VCol>

              <VCol
                cols="12"
                md="4"
              >
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.totalAmount') }}
                </div>
                <div class="font-weight-bold">
                  Rp {{ formatMoney(selectedCashAdvance.total_amount) || '0' }}
                </div>
              </VCol>

              <VCol cols="12">
                <div class="text-caption text-medium-emphasis">
                  {{ t('cashAdvanceRealization.form.source.subject') }}
                </div>
                <div class="text-pre-line">
                  {{ selectedCashAdvance.subject }}
                </div>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <!-- RINCIAN REALISASI -->
        <template v-if="selectedCashAdvance">
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 mt-6 mb-3">
            <div>
              <div class="text-subtitle-1 font-weight-bold">
                {{ t('cashAdvanceRealization.form.items.sectionTitle') }}
              </div>

              <div class="text-caption text-medium-emphasis">
                {{ t('cashAdvanceRealization.form.items.sectionSubtitle') }}
              </div>
            </div>

            <VBtn
              type="button"
              color="primary"
              variant="tonal"
              size="small"
              prepend-icon="tabler-list-details"
              class="text-none"
              @click="openItemFullscreen"
            >
              {{ t('cashAdvanceRealization.form.items.addButton') }}

              <VTooltip
                activator="parent"
                location="top"
                :offset="4"
              >
                {{ t('cashAdvanceRealization.form.items.addButtonTooltip') }}
              </VTooltip>
            </VBtn>
          </div>

          <!--
            Ringkasan baca-saja. Penyuntingan dilakukan di modal layar penuh,
            mengikuti pola Purchase Requisition.
          -->
          <VCard
            flat
            class="car-summary-card"
          >
            <VCardText>
              <VAlert
                v-if="needsBusinessTrip ? !hasBreakdownSummary : !hasItemDetails"
                type="info"
                variant="tonal"
                density="compact"
              >
                {{ t('cashAdvanceRealization.form.items.emptyAlert', { action: t('cashAdvanceRealization.form.items.addButton') }) }}
              </VAlert>

              <!--
                Syaratnya disebut, bukan v-else.

                v-else berpasangan dengan apa pun yang kebetulan berdiri
                tepat di atasnya; sekali ada yang menyisipkan peringatan di
                antaranya, pasangannya berpindah tanpa bersuara.
              -->
              <!--
                RINGKASAN PERJALANAN DINAS

                Dikelompokkan per kategori, tanpa tanggal -- bentuk yang sama
                dengan modalnya dan dengan FPU yang direalisasikan.

                Kategori yang diurus GA ikut ditampilkan walau tanpa baris:
                kosongnya disengaja, dan itu perlu terbaca. Tanpa ini,
                realisasi yang seluruhnya diurus GA tampak seperti belum diisi.
              -->
              <div
                v-if="needsBusinessTrip && hasBreakdownSummary"
                class="d-flex flex-column gap-3"
              >
                <div
                  v-for="kelompok in formItemsByCategory"
                  :key="`ringkas-${kelompok.category.id}`"
                  class="car-cat-card"
                >
                  <div class="car-cat-head">
                    <div class="car-cat-name">
                      {{ kelompok.category.name }}
                    </div>

                    <VChip
                      v-if="kelompok.arranged"
                      size="x-small"
                      variant="tonal"
                      color="warning"
                    >
                      {{ t('cashAdvanceRealization.form.breakdown.arrangedByGa') }}
                    </VChip>

                    <div class="car-cat-total">
                      <span class="text-caption text-medium-emphasis">
                        {{ t('cashAdvanceRealization.form.items.totalRealization') }}
                      </span>

                      <strong>Rp {{ formatMoney(kelompok.total) || '0' }}</strong>
                    </div>
                  </div>

                  <div
                    v-if="kelompok.arranged"
                    class="car-cat-arranged"
                  >
                    {{ t('cashAdvanceRealization.form.breakdown.arrangedNotice') }}
                  </div>

                  <template v-else>
                    <div
                      v-for="(item, index) in kelompok.rows"
                      :key="`ringkas-${kelompok.category.id}-${index}`"
                      class="car-sum-line"
                    >
                      <div class="min-w-0 flex-grow-1">
                        <div class="font-weight-medium">
                          {{ item.description || '-' }}

                          <VChip
                            v-if="!item.cash_advance_item_id"
                            size="x-small"
                            variant="tonal"
                            color="warning"
                            class="ms-1"
                          >
                            {{ t('cashAdvanceRealization.form.items.extra') }}
                          </VChip>
                        </div>

                        <div class="text-caption text-medium-emphasis mt-1">
                          {{ t('cashAdvanceRealization.form.breakdown.tableQty') }}
                          {{ item.qty ?? '-' }}
                          &times; Rp {{ formatMoney(item.unit_price) || '0' }}
                        </div>
                      </div>

                      <div class="font-weight-bold text-no-wrap">
                        Rp {{ formatMoney(item.realization_amount) || '0' }}
                      </div>
                    </div>
                  </template>
                </div>
              </div>

              <div
                v-if="!needsBusinessTrip && hasItemDetails"
                class="d-flex flex-column gap-3"
              >
                <div
                  v-for="(item, index) in form.items"
                  :key="`summary-item-${index}`"
                  class="car-summary-row"
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

                        <VChip
                          v-if="!item.cash_advance_item_id"
                          size="x-small"
                          variant="tonal"
                          color="warning"
                          class="ms-1"
                        >
                          {{ t('cashAdvanceRealization.form.items.extra') }}
                        </VChip>
                      </div>

                      <div class="text-caption text-medium-emphasis mt-1">
                        {{ t('cashAdvanceRealization.form.items.tableAdvance') }}:
                        <strong>Rp {{ formatMoney(item.advance_amount) || '0' }}</strong>
                        <span class="mx-1">•</span>
                        {{ t('cashAdvanceRealization.form.items.tableDifference') }}:
                        <strong>{{ item.cash_advance_item_id ? formatSigned(itemDifference(item)) : '—' }}</strong>
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
                        {{ t('cashAdvanceRealization.form.items.tableRealization') }}
                      </div>

                      <div class="font-weight-bold">
                        Rp {{ formatMoney(item.realization_amount) || '0' }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </VCardText>
          </VCard>

          <div class="d-flex justify-end mt-4">
            <div class="car-total-box">
              <div class="car-total-row">
                <span>{{ t('cashAdvanceRealization.form.items.totalAdvance') }}</span>
                <strong>Rp {{ formatMoney(totalAdvance) || '0' }}</strong>
              </div>

              <div class="car-total-row">
                <span>{{ t('cashAdvanceRealization.form.items.totalRealization') }}</span>
                <strong>Rp {{ formatMoney(totalRealization) || '0' }}</strong>
              </div>

              <VDivider class="my-2" />

              <div class="car-total-row">
                <span>{{ t('cashAdvanceRealization.form.items.difference') }}</span>

                <div class="d-flex align-center gap-2">
                  <VChip
                    size="small"
                    :color="differenceColor"
                    variant="tonal"
                  >
                    {{ differenceLabel }}
                  </VChip>

                  <strong>Rp {{ formatMoney(Math.abs(differenceAmount)) || '0' }}</strong>
                </div>
              </div>
            </div>
          </div>

          <!-- BUKTI TERSIMPAN -->
          <div
            v-if="existingAttachments.length"
            class="mt-6"
          >
            <div class="text-subtitle-1 font-weight-bold mb-2">
              {{ t('cashAdvanceRealization.form.attachment.existingTitle') }}
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
                    :icon="attachment.mime_type === 'application/pdf' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
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
                  {{ formatFileSize(attachment.file_size) }}
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
                    {{ t('cashAdvanceRealization.form.attachment.deleteButton') }}
                  </VBtn>
                </template>
              </VListItem>
            </VList>
          </div>

          <!--
            Bukti baru tidak lagi berdiri di tingkat dokumen -- setiap baris
            rincian membawa buktinya sendiri di kolom Bukti.
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
            :label="t('cashAdvanceRealization.form.fields.notes')"
            :placeholder="t('cashAdvanceRealization.form.placeholders.notes')"
            rows="3"
            auto-grow
          />
        </template>

        <VAlert
          v-else
          type="info"
          variant="tonal"
          class="mt-4"
        >
          {{ t('cashAdvanceRealization.form.placeholders.cashAdvance') }}
        </VAlert>

        <VDivider class="mt-6 mb-4" />

        <div class="d-flex justify-end gap-3">
          <VBtn
            type="button"
            color="secondary"
            variant="outlined"
            class="text-none"
            @click.prevent.stop="confirmCancel"
          >
            {{ t('cashAdvanceRealization.form.buttons.cancel') }}
          </VBtn>

          <VBtn
            type="button"
            color="primary"
            class="text-none"
            :loading="isSaving"
            :disabled="!selectedCashAdvance"
            @click.prevent.stop="save($event)"
          >
            {{ t('cashAdvanceRealization.form.buttons.update') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>

    <!--
      MODAL RINCIAN (layar penuh)
      Mengikuti pola Purchase Requisition: penyuntingan dilakukan di sini agar
      kolom tidak berdesakan, lalu hasilnya tampil sebagai ringkasan teks.
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
            {{ t('cashAdvanceRealization.form.itemDialog.title') }}
          </VToolbarTitle>

          <VSpacer />

          <!--
            Disembunyikan pada bentuk berkategori: ia melahirkan baris tanpa
            kategori, yang tidak tergambar di kelompok mana pun. Di sana
            barisnya lahir dari tombol milik kategorinya masing-masing.
          -->
          <VBtn
            v-if="!needsBusinessTrip"
            variant="flat"
            class="me-3 text-none"
            prepend-icon="tabler-plus"
            @click="addExtraItem"
          >
            {{ t('cashAdvanceRealization.form.itemDialog.addRowButton') }}
          </VBtn>

          <VBtn
            variant="flat"
            class="me-3 text-none"
            @click="saveItemsFromDialog"
          >
            {{ t('cashAdvanceRealization.form.itemDialog.saveButton') }}
          </VBtn>
        </VToolbar>

        <VCardText class="pa-4">
          <!-- Bentuk biasa: satu tabel datar, bertanggal, dengan nominal diketik. -->
          <template v-if="!needsBusinessTrip">
            <div class="car-table-wrapper">
              <VTable class="car-item-table">
                <thead>
                  <tr>
                    <th class="car-col-no">
                      {{ t('cashAdvanceRealization.form.items.tableNo') }}
                    </th>
                    <th class="car-col-date">
                      {{ t('cashAdvanceRealization.form.items.tableDate') }}
                      <span class="text-error">*</span>
                    </th>
                    <th>
                      {{ t('cashAdvanceRealization.form.items.tableDescription') }}
                    </th>
                    <th class="car-col-money text-end">
                      {{ t('cashAdvanceRealization.form.items.tableAdvance') }}
                    </th>
                    <th class="car-col-money">
                      {{ t('cashAdvanceRealization.form.items.tableRealization') }}
                    </th>
                    <th class="car-col-money text-end">
                      {{ t('cashAdvanceRealization.form.items.tableDifference') }}
                    </th>
                    <th class="car-col-attachment">
                      {{ t('cashAdvanceRealization.form.items.tableAttachment') }}
                      <span class="text-error">*</span>
                    </th>
                    <th class="car-col-action text-center">
                      {{ t('cashAdvanceRealization.form.items.tableActions') }}
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
                        :error="isSubmitted && !item.date"
                        :error-messages="isSubmitted && !item.date ? [t('cashAdvanceRealization.form.validation.itemDate')] : []"
                      />
                    </td>

                    <td>
                      <VTextField
                        v-model="item.description"
                        density="compact"
                        variant="outlined"
                        hide-details="auto"
                        :error="isSubmitted && !item.description.trim()"
                        :error-messages="isSubmitted && !item.description.trim() ? [t('cashAdvanceRealization.form.validation.itemDescription')] : []"
                      />

                      <VChip
                        size="x-small"
                        variant="tonal"
                        :color="item.cash_advance_item_id ? 'primary' : 'warning'"
                        class="mt-1"
                      >
                        {{ item.cash_advance_item_id
                          ? t('cashAdvanceRealization.form.items.fromAdvance')
                          : t('cashAdvanceRealization.form.items.extra') }}
                      </VChip>
                    </td>

                    <td class="text-end text-medium-emphasis">
                      {{ item.cash_advance_item_id ? `Rp ${formatMoney(item.advance_amount) || '0'}` : '—' }}
                    </td>

                    <td>
                      <VTextField
                        :model-value="formatMoney(item.realization_amount)"
                        density="compact"
                        variant="outlined"
                        hide-details="auto"
                        prefix="Rp"
                        inputmode="numeric"
                        @input="handleAmountInput($event, index)"
                      />
                    </td>

                    <td
                      class="text-end font-weight-medium"
                      :class="{
                        'text-success': itemDifference(item) > 0,
                        'text-warning': itemDifference(item) < 0,
                        'text-medium-emphasis': itemDifference(item) === 0,
                      }"
                    >
                      {{ item.cash_advance_item_id ? formatSigned(itemDifference(item)) : '—' }}
                    </td>

                    <!-- Bukti milik baris ini -->
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
                          {{ t('cashAdvanceRealization.form.attachment.addButton') }}
                        </VBtn>

                        <div
                          v-if="isSubmitted && lineMissingAttachment(index)"
                          class="text-error text-caption"
                        >
                          {{ t('cashAdvanceRealization.form.validation.itemAttachment') }}
                        </div>

                        <div
                          v-for="attachment in item.existing"
                          :key="`item-${index}-existing-${attachment.id}`"
                          class="car-line-file"
                        >
                          <VIcon
                            :icon="attachment.mime_type === 'application/pdf' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                            size="18"
                            color="primary"
                          />

                          <div class="car-line-file__body">
                            <a
                              v-if="attachment.url"
                              :href="attachment.url"
                              target="_blank"
                              rel="noopener"
                              class="car-line-file__name d-block text-primary"
                            >
                              {{ attachment.original_filename || attachment.filename }}
                            </a>

                            <div
                              v-else
                              class="car-line-file__name"
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

                        <div
                          v-for="(file, fileIndex) in item.attachments"
                          :key="`item-${index}-file-${fileIndex}`"
                          class="car-line-file"
                        >
                          <VIcon
                            :icon="file.type === 'application/pdf' ? 'mdi-file-pdf-box' : 'mdi-file-image-outline'"
                            size="18"
                            color="success"
                          />

                          <div class="car-line-file__body">
                            <div class="car-line-file__name">
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
                        :disabled="item.cash_advance_item_id !== null"
                        @click="removeItem(index)"
                      >
                        <VIcon icon="tabler-trash" />
                        <VTooltip
                          activator="parent"
                          location="top"
                          max-width="260"
                        >
                          {{ item.cash_advance_item_id !== null
                            ? t('cashAdvanceRealization.form.items.removeHint')
                            : t('common.actions.delete') }}
                        </VTooltip>
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
          </template>

          <!--
            Bentuk perjalanan dinas: rincian dikelompokkan menurut kategori,
            tanpa tanggal, dengan Qty dan Rincian Biaya.

            Totalnya baca-saja -- ia selalu Qty x Rincian Biaya, dan server
            menghitungnya sendiri. Kotak isian untuk angka yang akan dihitung
            ulang hanya mengundang orang mengetik sesuatu yang lalu diabaikan.
          -->
          <template v-else>
            <VAlert
              v-if="!expenseCategoryList.length"
              type="warning"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              {{ t('cashAdvanceRealization.form.breakdown.noCategory') }}
            </VAlert>

            <div
              v-for="kelompok in tempItemsByCategory"
              :key="`kategori-${kelompok.category.id}`"
              class="car-cat-card"
            >
              <div class="car-cat-head">
                <div class="car-cat-name">
                  {{ kelompok.category.name }}
                </div>

                <!--
                  Diurus GA: kategori ini tidak dikeluarkan pemohon sama sekali,
                  jadi tidak ada yang bisa dilaporkannya. Menyalakannya membuang
                  baris yang terlanjur ada -- keduanya pernyataan yang saling
                  meniadakan, dan server menolak dokumen yang mengatakan
                  dua-duanya.
                -->
                <VSwitch
                  :model-value="kelompok.arranged"
                  color="warning"
                  density="compact"
                  hide-details
                  :label="t('cashAdvanceRealization.form.breakdown.arrangedByGa')"
                  class="car-cat-switch"
                  @update:model-value="toggleArranged(kelompok.category.id)"
                />

                <div class="car-cat-total">
                  <span class="text-caption text-medium-emphasis">
                    {{ t('cashAdvanceRealization.form.items.totalRealization') }}
                  </span>

                  <strong>Rp {{ formatMoney(kelompok.total) || '0' }}</strong>
                </div>
              </div>

              <div
                v-if="kelompok.arranged"
                class="car-cat-arranged"
              >
                {{ t('cashAdvanceRealization.form.breakdown.arrangedNotice') }}
              </div>

              <template v-else>
                <div class="car-table-wrapper">
                  <VTable class="car-item-table car-breakdown-table">
                    <thead>
                      <tr>
                        <th class="car-col-no">
                          {{ t('cashAdvanceRealization.form.items.tableNo') }}
                        </th>
                        <th>
                          {{ t('cashAdvanceRealization.form.breakdown.tableItem') }}
                        </th>
                        <th class="car-col-qty">
                          {{ t('cashAdvanceRealization.form.breakdown.tableQty') }}
                        </th>
                        <th class="car-col-money">
                          {{ t('cashAdvanceRealization.form.breakdown.tableUnitPrice') }}
                        </th>
                        <th class="car-col-money text-end">
                          {{ t('cashAdvanceRealization.form.items.tableRealization') }}
                        </th>
                        <th class="car-col-money text-end">
                          {{ t('cashAdvanceRealization.form.items.tableAdvance') }}
                        </th>
                        <th class="car-col-attachment">
                          {{ t('cashAdvanceRealization.form.items.tableAttachment') }}
                          <span class="text-error">*</span>
                        </th>
                        <th class="car-col-action text-center">
                          {{ t('cashAdvanceRealization.form.items.tableActions') }}
                        </th>
                      </tr>
                    </thead>

                    <tbody>
                      <tr v-if="!kelompok.rows.length">
                        <td
                          colspan="8"
                          class="text-center text-medium-emphasis py-4"
                        >
                          {{ t('cashAdvanceRealization.form.breakdown.categoryEmpty') }}
                        </td>
                      </tr>

                      <tr
                        v-for="(baris, urut) in kelompok.rows"
                        :key="`baris-${kelompok.category.id}-${baris.index}`"
                      >
                        <td class="text-medium-emphasis">
                          {{ urut + 1 }}
                        </td>

                        <td>
                          <VTextField
                            v-model="baris.item.description"
                            density="compact"
                            variant="outlined"
                            hide-details="auto"
                            :placeholder="t('cashAdvanceRealization.form.breakdown.itemPlaceholder')"
                            :error="isSubmitted && !baris.item.description.trim()"
                          />

                          <VChip
                            size="x-small"
                            variant="tonal"
                            :color="baris.item.cash_advance_item_id ? 'primary' : 'warning'"
                            class="mt-1"
                          >
                            {{ baris.item.cash_advance_item_id
                              ? t('cashAdvanceRealization.form.items.fromAdvance')
                              : t('cashAdvanceRealization.form.items.extra') }}
                          </VChip>
                        </td>

                        <td>
                          <VTextField
                            v-model.number="baris.item.qty"
                            type="number"
                            min="0"
                            step="any"
                            density="compact"
                            variant="outlined"
                            hide-details="auto"
                            :error="isSubmitted && !(Number(baris.item.qty) > 0)"
                          />
                        </td>

                        <td>
                          <VTextField
                            :model-value="formatMoney(baris.item.unit_price)"
                            density="compact"
                            variant="outlined"
                            hide-details="auto"
                            prefix="Rp"
                            inputmode="numeric"
                            :error="isSubmitted && !(Number(baris.item.unit_price) > 0)"
                            @input="handleUnitPriceInput($event, baris.index)"
                          />
                        </td>

                        <!-- Baca-saja: ia selalu Qty x Rincian Biaya. -->
                        <td class="text-end font-weight-bold">
                          Rp {{ formatMoney(lineTotal(baris.item)) || '0' }}
                        </td>

                        <!--
                          Nominal pengajuannya, sebagai pembanding. Baris yang
                          tidak berasal dari FPU tidak punya pembanding -- itu
                          pengeluaran yang tidak direncanakan, bukan nol.
                        -->
                        <td class="text-end text-medium-emphasis">
                          {{ baris.item.cash_advance_item_id
                            ? `Rp ${formatMoney(baris.item.advance_amount) || '0'}`
                            : '—' }}
                        </td>

                        <td>
                          <div class="d-flex flex-column gap-2">
                            <input
                              :ref="el => setLineFileRef(el, baris.index)"
                              type="file"
                              multiple
                              accept=".pdf,.jpg,.jpeg,.png"
                              class="d-none"
                              @change="handleLineFileUpload($event, baris.index)"
                            >

                            <VBtn
                              type="button"
                              size="small"
                              variant="outlined"
                              class="text-none align-self-start"
                              :color="isSubmitted && lineMissingAttachment(baris.index) ? 'error' : 'primary'"
                              prepend-icon="tabler-paperclip"
                              @click="triggerLineFileInput(baris.index)"
                            >
                              {{ t('cashAdvanceRealization.form.attachment.addButton') }}
                            </VBtn>

                            <div
                              v-if="isSubmitted && lineMissingAttachment(baris.index)"
                              class="text-error text-caption"
                            >
                              {{ t('cashAdvanceRealization.form.validation.itemAttachment') }}
                            </div>

                            <div class="d-flex flex-wrap gap-1">
                              <VChip
                                v-for="(file, fileIndex) in baris.item.attachments"
                                :key="`berkas-${baris.index}-${fileIndex}`"
                                size="x-small"
                                variant="tonal"
                                color="primary"
                                closable
                                @click:close="removeLineFile(baris.index, fileIndex)"
                              >
                                {{ file.name }}
                              </VChip>
                            </div>
                          </div>
                        </td>

                        <td class="text-center">
                          <VBtn
                            icon
                            size="small"
                            variant="text"
                            color="error"
                            @click="removeItemRow(baris.index)"
                          >
                            <VIcon icon="tabler-trash" />
                          </VBtn>
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </div>

                <div class="pa-3">
                  <VBtn
                    size="small"
                    variant="tonal"
                    color="primary"
                    prepend-icon="tabler-plus"
                    class="text-none"
                    @click="addItemRowTo(kelompok.category.id)"
                  >
                    {{ t('cashAdvanceRealization.form.breakdown.addRowTo', { name: kelompok.category.name }) }}
                  </VBtn>
                </div>
              </template>
            </div>

            <div class="d-flex justify-end mt-2">
              <div class="car-total-box">
                <div class="car-total-row">
                  <span>{{ t('cashAdvanceRealization.form.items.totalRealization') }}</span>
                  <strong>Rp {{ formatMoney(tempItemsTotal) || '0' }}</strong>
                </div>
              </div>
            </div>
          </template>
        </VCardText>
      </VCard>
    </VDialog>

    <VDialog
      v-model="confirmCloseItemDialog"
      max-width="460"
    >
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('cashAdvanceRealization.form.itemDialog.discardTitle') }}
        </VCardTitle>

        <VCardText>
          {{ t('cashAdvanceRealization.form.itemDialog.discardText') }}
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
            {{ t('cashAdvanceRealization.form.itemDialog.discardButton') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </section>
</template>

<style scoped>
.car-source-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
}

/* Ringkasan rincian pada halaman utama. */
.car-summary-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
}

.car-summary-row {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  padding-block: 0.75rem;
  padding-inline: 0.875rem;
}

/* Kartu kategori pada rincian perjalanan dinas. */
.car-cat-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  margin-block-end: 1rem;
  overflow: hidden;
}

.car-cat-head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  background-color: rgba(var(--v-theme-primary), 0.06);
  gap: 0.75rem;
  padding-block: 0.625rem;
  padding-inline: 1rem;
}

.car-cat-name {
  font-size: 0.95rem;
  font-weight: 600;
}

.car-cat-switch {
  flex: 0 0 auto;
}

.car-cat-total {
  display: flex;
  align-items: center;
  margin-inline-start: auto;
  gap: 0.5rem;
}

.car-cat-arranged {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.875rem;
  padding-block: 0.875rem;
  padding-inline: 1rem;
}

.car-sum-line {
  display: flex;
  align-items: flex-start;
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  gap: 1rem;
  padding-block: 0.625rem;
  padding-inline: 1rem;
}

.car-breakdown-table {
  min-inline-size: 1100px;
}

.car-col-qty {
  inline-size: 6rem;
}

.car-table-wrapper {
  overflow-x: auto;
}

.car-item-table {
  min-inline-size: 1300px;
}

.car-col-no {
  inline-size: 3.5rem;
}

/*
| Kolom tanggal butuh ruang untuk "2026-08-30" beserta padding field dan
| tombol clear-nya. Tanpa min-inline-size, tata letak tabel otomatis
| menyusutkan kolom ini demi kolom deskripsi dan tanggalnya terpotong.
*/
.car-col-date {
  inline-size: 14rem;
  min-inline-size: 14rem;
}

.car-col-attachment {
  inline-size: 17rem;
  min-inline-size: 17rem;
}

/* Kartu berkas yang menempel pada satu baris rincian. */
.car-line-file {
  display: flex;
  align-items: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  gap: 0.5rem;
  padding-block: 0.25rem;
  padding-inline: 0.5rem;
}

.car-line-file__body {
  flex: 1;
  min-inline-size: 0;
}

.car-line-file__name {
  overflow: hidden;
  font-size: 0.8rem;
  text-decoration: none;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.car-col-money {
  inline-size: 12rem;
}

.car-col-action {
  inline-size: 5rem;
}

.car-total-box {
  border-radius: 8px;
  background: rgba(var(--v-theme-primary), 0.08);
  min-inline-size: 24rem;
  padding-block: 0.75rem;
  padding-inline: 1rem;
}

.car-total-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 2rem;
  padding-block: 0.2rem;
}
</style>
