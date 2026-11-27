<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import axios from '@axios'
import {
  closeAlert,
  flushQueuedToast,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import DecisionDialog from '@/components/approval/DecisionDialog.vue'
import { approvalStatusColor } from '@/utils/approvalStatus'
import { buildPrintLoadingPage } from '@/utils/printLoadingPage'
import RevisionHistory from '@/components/fund_request/RevisionHistory.vue'
import type { RevisionEntry } from '@/components/fund_request/revision'
import { usePermissionStore } from '@/stores/permission'
import { formatAngka, formatUang } from '@/utils/numberFormatter'

/*
|--------------------------------------------------------------------------
| Daftar Bank Disbursement Voucher
|--------------------------------------------------------------------------
| Susunan layarnya sengaja dibuat sama dengan modul pengajuan dana lain --
| kartu penyaring di atas, kartu tabel di bawahnya, jumlah baris dan halaman
| di kakinya. Orang yang sudah terbiasa dengan FPU dan Claim tidak perlu
| belajar apa pun lagi di sini.
|--------------------------------------------------------------------------
*/

interface BdvRow {
  public_id: string
  bdv_number: string | null
  date: string | null

  /*
   * JAMAK, sejak satu BDV bisa mencairkan beberapa dokumen.
   *
   * Jenisnya selalu satu -- seluruh dokumen dalam satu BDV wajib sejenis --
   * tetapi tetap dikirim sebagai daftar: bentuknya mengikuti sumbernya,
   * bukan aturan yang kebetulan berlaku hari ini.
   */
  document_types: string[]
  source_numbers: string[]
  branch: string | null
  department: string | null
  total_amount: number
  status: string
  requester: string | null
  created_at: string | null

  /*
   * Kosong berarti vouchernya BELUM sampai ke pembukuan Accurate.
   *
   * Ditandai di daftar, bukan hanya di detailnya: sejak halaman buat dan
   * ubah ditinggalkan meski Accurate menolak, inilah satu-satunya tempat
   * orang bisa menemukan voucher mana yang masih tertinggal.
   */
  accurate_id: string | null
  accurate_number: string | null

  /*
   * Apakah ISI TERAKHIR sudah sampai Accurate. Inilah yang menentukan
   * penandanya, bukan accurate_id -- voucher yang pernah terkirim lalu
   * perubahannya ditolak tetap punya id, dan penanda yang dihitung dari id
   * tidak akan pernah muncul untuknya.
   */
  accurate_in_sync: boolean

  /*
   * Apa yang boleh dikerjakan ORANG INI pada BARIS INI, dijawab server.
   *
   * Bukan disimpulkan di layar dari "punya izin bdv.update atau tidak":
   * izinnya bisa bercakupan OWN_DATA, OWN_CABANG, atau OWN_DEPARTMENT,
   * sehingga izin yang sama berarti boleh pada satu baris dan tidak boleh
   * pada baris di bawahnya. Hanya server yang tahu cakupan itu.
   */
  can_update: boolean
  can_delete: boolean
  can_submit: boolean

  /*
   * Boleh tidaknya ORANG INI memutuskan voucher ini SEKARANG.
   *
   * Dihitung server dari rantai approval -- baris berstatus WAITING atas
   * nama orang ini -- bukan dari izin bdv.approve saja. Pemegang izin yang
   * tahapannya belum tiba tidak boleh ditawari tombolnya.
   *
   * Ketiganya dikirim terpisah walau nilainya kini selalu sama: masing-
   * masing kelak bisa punya syaratnya sendiri, dan layar yang membaca
   * can_approve untuk tombol Kembalikan akan menyembunyikan perbedaan itu.
   */
  can_approve?: boolean
  can_reject?: boolean
  can_return_for_revision?: boolean

  /*
   * Berapa kali voucher ini pernah dikembalikan untuk direvisi. Dipakai
   * sebagai penanda kecil di sebelah statusnya -- itulah yang menjelaskan
   * kenapa sebuah voucher tua masih berstatus draft.
   */
  revision_count?: number

  /* Hanya voucher yang sudah disetujui penuh yang boleh dicetak. */
  can_print?: boolean
}

/*
| Bentuk jawaban detail. Dipakai modalnya saja, dan sengaja ditaruh di sini
| bukan di berkas tersendiri: ia hanya menggambarkan satu endpoint, dan
| satu-satunya yang membacanya ada di layar ini.
*/
interface Lampiran {
  id: number
  filename: string | null
  original_filename: string | null
  mime_type: string | null
  file_size: number | null
  url: string | null
}

interface DokumenItem {
  no: number
  date: string | null
  description: string | null
  category: string | null
  qty: number | null
  unit_price: number | null
  amount: number

  /*
   * Bukti milik baris ini, diangkut dari dokumen sumbernya. Hanya lampiran
   * pengajuan yang menempel pada sebuah baris -- lampiran tingkat dokumen
   * tidak ikut, sebab ia bukan milik baris mana pun.
   */
  attachments: Lampiran[]
}

/* Satu tahap pada rantai persetujuan voucher ini. */
interface RiwayatApproval {
  step: number | null
  role: string | null
  approver: string | null
  status: string | null
  acted_at: string | null
  notes: string | null
}

interface DokumenRincian {
  public_id: string
  document_type: string
  document_type_label: string
  number: string
  date: string | null
  subject: string | null
  status: string | null
  branch: string | null
  department: string | null
  transaction_category: string | null
  total_amount: number
  notes: string | null
  requester: string | null
  received_at: string | null
  scheduled_payment_date: string | null
  items: DokumenItem[]
}

/** Satu dokumen sumber beserta rincian dan pilihan akunnya. */
interface SumberBdv {
  public_id: string
  document_type: string
  document_type_label: string
  source_number: string | null
  total_amount: number

  /* Rincian hidup dari dokumen aslinya; null bila sudah tidak terbaca. */
  detail: DokumenRincian | null

  items: Array<{
    line_no: number
    accurate_account_no: string | null
    accurate_account_name: string | null
  }>
}

interface BdvDetail {
  public_id: string
  bdv_number: string | null
  date: string | null
  document_types: string[]
  source_numbers: string[]
  branch: string | null
  department: string | null
  transaction_category: string | null
  total_amount: number
  notes: string | null
  status: string | null
  requester: string | null
  can_update: boolean

  cash_bank_account_no: string | null
  cash_bank_account_name: string | null
  check_number: string | null
  payee: string | null

  accurate_id: string | null
  accurate_number: string | null
  accurate_sent_at: string | null
  accurate_in_sync: boolean

  /*
   * SELURUH dokumen sumbernya, masing-masing lengkap dengan rincian hidup
   * dari dokumen aslinya dan pilihan akun milik voucher ini.
   */
  sources: SumberBdv[]

  /*
   * Berapa bukti yang dipegang voucher ini, dan berapa yang sudah sampai
   * Accurate. Selisih di antara keduanya berarti menyimpan ulang dari
   * halaman ubah akan mencoba lagi yang tertinggal.
   */
  attachments?: {
    total: number
    terkirim: number
  }

  approvals?: RiwayatApproval[]

  /*
   * Boleh tidaknya ORANG INI menyetujui atau menolak voucher ini SEKARANG.
   *
   * Dijawab server, dengan alasan yang sama seperti can_update: hanya server
   * yang tahu siapa approver tahap berjalan, dan tahap mana yang sedang
   * menunggu. Layar tidak boleh menyimpulkannya dari status saja -- "IN
   * PROGRESS" tidak berarti menunggu orang yang kebetulan membukanya.
   *
   * Selama alur approval BDV belum ada, keduanya selalu false dan tombolnya
   * tidak pernah muncul. Begitu alurnya dibangun, yang berubah hanya server.
   */
  can_approve?: boolean
  can_reject?: boolean
  can_submit?: boolean
  can_return_for_revision?: boolean
  can_print?: boolean

  revision_count?: number

  /*
   * Revisi yang SEDANG berjalan: sudah dikembalikan approver, belum
   * diajukan ulang pemohon. Inilah satu-satunya tempat pemohon membaca apa
   * yang harus ia perbaiki, jadi ia ditampilkan paling atas pada detail --
   * bukan dikubur di bawah rincian.
   */
  open_revision?: {
    revision_number: number
    returned_by: string | null
    returned_at: string | null
    returned_step_label: string | null
    return_reason: string | null
  } | null

  /*
   * Seluruh putaran revisi yang pernah dialami voucher ini, termasuk yang
   * sedang berjalan. Bentuknya sama dengan modul lain -- satu tabel, satu
   * penyaji di server -- jadi penampilnya pun komponen yang sama.
   */
  revisions?: RevisionEntry[]
}

interface PilihanDokumen {
  code: string
  label: string
}

interface PilihanUmum {
  value: string | number
  title: string
}

const { t } = useI18n()
const router = useRouter()
const permissionStore = usePermissionStore()

const ENDPOINT = '/cash-bank/bdv'

const rows = ref<BdvRow[]>([])
const loading = ref(false)

const documentTypes = ref<PilihanDokumen[]>([])
const statuses = ref<string[]>([])
const branches = ref<PilihanUmum[]>([])
const departments = ref<PilihanUmum[]>([])

const filter = reactive({
  search: '',
  status: null as string | null,
  documentType: null as string | null,
  branch: null as string | null,
  departmentId: null as number | null,

  /* null, bukan string kosong: itu yang dikirim pemilih tanggal saat dibersihkan. */
  dateFrom: null as string | null,
  dateTo: null as string | null,
})

/*
| Saringan "hanya yang menunggu approval saya".
|
| Dipisahkan dari objek filter di atas, persis seperti di FPU dan Claim: ia
| bukan nilai yang diketik atau dipilih, melainkan sakelar -- dan tempatnya
| di layar pun bukan di deretan isian itu.
*/
const onlyWaitingMyApproval = ref(false)

/*
| Jawaban server tentang orang yang sedang membuka daftar ini.
|
| Hanya satu hal untuk sekarang: apakah ia pernah berdiri di rantai approval
| BDV. Tidak bisa dijawab dari izin -- bdv.approve menerangkan BOLEH
| memutuskan, bukan PERNAH DIMINTA memutuskan -- dan tidak bisa dijawab dari
| layar, sebab rantainya ada di sisi sana.
*/
const abilities = reactive({
  is_approver: false,
})

const currentPage = ref(1)
const rowPerPage = ref(25)
const totalData = ref(0)
const totalPage = ref(1)

const canView = computed(() => permissionStore.can('bdv.view'))
const canCreate = computed(() => permissionStore.can('bdv.create'))

/*
| Tidak ada canUpdate/canDelete di layar ini. Boleh tidaknya mengubah atau
| menghapus dijawab server per baris lewat can_update dan can_delete, karena
| izin saja tidak menentukan -- scope-nya yang menentukan. Lihat keterangan
| pada blok "Aksi per baris".
*/

const adaPenyaringAktif = computed(() =>
  Boolean(
    filter.search
    || filter.status
    || filter.documentType
    || filter.branch
    || filter.departmentId
    || filter.dateFrom
    || filter.dateTo
    || onlyWaitingMyApproval.value,
  ))

const paginationData = computed(() => {
  if (totalData.value === 0)
    return t('bdv.list.showing', { from: 0, to: 0, total: 0 })

  const dari = (currentPage.value - 1) * rowPerPage.value + 1
  const sampai = Math.min(currentPage.value * rowPerPage.value, totalData.value)

  return t('bdv.list.showing', { from: dari, to: sampai, total: totalData.value })
})

/*
|--------------------------------------------------------------------------
| Tampilan nilai
|--------------------------------------------------------------------------
*/
const rupiah = (nilai: number | null | undefined): string => formatUang(nilai ?? 0)

const tanggal = (nilai: string | null): string => {
  if (!nilai)
    return '-'

  const d = new Date(nilai)

  if (Number.isNaN(d.getTime()))
    return '-'

  return d.toLocaleDateString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

const warnaStatus = (status: string): string => {
  const peta: Record<string, string> = {
    'DRAFT': 'secondary',
    'IN PROGRESS': 'warning',
    'NEED REVISION': 'warning',
    'APPROVED': 'success',
    'REJECTED': 'error',
    'CANCELLED': 'secondary',
  }

  return peta[status] ?? 'secondary'
}

/* Status yang belum punya terjemahan ditampilkan apa adanya, bukan sebagai kunci. */
const labelStatus = (status: string): string => {
  const kunci = `bdv.status.${status}`
  const hasil = t(kunci)

  return hasil === kunci ? status : hasil
}

const statusOptions = computed(() =>
  statuses.value.map(s => ({ value: s, title: labelStatus(s) })))

const documentTypeOptions = computed(() =>
  documentTypes.value.map(d => ({ value: d.code, title: d.label })))

/*
|--------------------------------------------------------------------------
| Memuat daftar
|--------------------------------------------------------------------------
| Memakai nomor urut permintaan dengan alasan yang sama seperti di daftar FPU
| dan Claim: penyaring yang diubah berturut-turut melahirkan beberapa
| permintaan sekaligus, dan yang berangkat lebih dulu tidak selalu tiba lebih
| dulu. Hanya jawaban dari permintaan terakhir yang boleh dipakai.
|--------------------------------------------------------------------------
*/
let nomorPermintaanDaftar = 0

/*
| Penyaring yang kosong dikirim sebagai undefined, bukan string kosong: axios
| membuang undefined dari query string, sehingga server menerima permintaan
| yang bersih tanpa perlu menyaring nilai kosong sendiri.
*/
const buildFilterParams = (): Record<string, unknown> => ({
  search: filter.search || undefined,
  status: filter.status || undefined,
  document_type: filter.documentType || undefined,
  branch: filter.branch || undefined,
  department_id: filter.departmentId || undefined,
  date_from: filter.dateFrom || undefined,
  date_to: filter.dateTo || undefined,
  waiting_my_approval: onlyWaitingMyApproval.value ? 1 : undefined,
})

const fetchRows = async (): Promise<void> => {
  if (!canView.value)
    return

  const nomorPermintaan = ++nomorPermintaanDaftar

  loading.value = true

  try {
    const { data } = await axios.get(ENDPOINT, {
      headers: { Accept: 'application/json' },
      params: {
        page: currentPage.value,
        per_page: rowPerPage.value,
        ...buildFilterParams(),
      },
    })

    if (nomorPermintaan !== nomorPermintaanDaftar)
      return

    rows.value = Array.isArray(data?.data) ? data.data : []
    totalData.value = Number(data?.meta?.total ?? 0)
    totalPage.value = Number(data?.meta?.last_page ?? 1)
    abilities.is_approver = Boolean(data?.abilities?.is_approver)
  }
  catch (error: unknown) {
    if (nomorPermintaan !== nomorPermintaanDaftar)
      return

    rows.value = []
    totalData.value = 0
    totalPage.value = 1

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.list.loadFailed')),
    })
  }
  finally {
    if (nomorPermintaan === nomorPermintaanDaftar)
      loading.value = false
  }
}

const fetchOptions = async (): Promise<void> => {
  if (!canView.value)
    return

  try {
    const { data } = await axios.get(`${ENDPOINT}/options`, {
      headers: { Accept: 'application/json' },
    })

    documentTypes.value = data?.data?.document_types ?? []
    statuses.value = data?.data?.statuses ?? []
    branches.value = data?.data?.branches ?? []
    departments.value = data?.data?.departments ?? []
  }
  catch {
    /*
     * Penyaring yang gagal dimuat tidak menghalangi daftarnya: dropdown-nya
     * kosong, tetapi datanya tetap terbaca.
     */
    documentTypes.value = []
    statuses.value = []
    branches.value = []
    departments.value = []
  }
}

const terapkanFilter = async (): Promise<void> => {
  currentPage.value = 1

  await fetchRows()
}

const resetFilters = async (): Promise<void> => {
  filter.search = ''
  filter.status = null
  filter.documentType = null
  filter.branch = null
  filter.departmentId = null
  filter.dateFrom = null
  filter.dateTo = null
  onlyWaitingMyApproval.value = false

  await terapkanFilter()
}

/*
| Rentang tanggal terbalik dikoreksi saat itu juga, supaya permintaan tidak
| pernah berangkat dalam keadaan yang mustahil menghasilkan apa pun.
*/
watch(() => filter.dateTo, nilai => {
  if (!nilai || !filter.dateFrom)
    return

  if (new Date(nilai) < new Date(filter.dateFrom)) {
    filter.dateTo = null

    showErrorToast({
      title: t('common.alert.error'),
      text: t('bdv.list.dateRangeInvalid'),
    })
  }
})

watch([currentPage, rowPerPage], () => {
  fetchRows()
})

const bukaFormulirBuat = (): void => {
  router.push('/cash_bank/bdv/create')
}

/*
|--------------------------------------------------------------------------
| Aksi per baris
|--------------------------------------------------------------------------
| Susunannya mengikuti modul FPU: tindakannya menempel pada nomor dokumen,
| dan tiap entri hanya muncul bila memang berlaku untuk baris itu. Entri
| yang tampil tetapi pasti ditolak server hanya mengajari orang bahwa
| menunya tidak bisa dipercaya.
|
| BOLEH TIDAKNYA DIJAWAB SERVER, PER BARIS.
|
| Bukan disimpulkan di sini dari permission store. Izin saja tidak cukup:
| scope-nya bisa OWN_DATA, OWN_CABANG, atau OWN_DEPARTMENT, sehingga satu
| izin yang sama berarti boleh pada satu baris dan tidak boleh pada baris
| berikutnya -- dan hanya server yang tahu cakupan itu. Status draft pun
| sudah ikut diperhitungkan di sana, jadi tidak diperiksa dua kali di sini.
|--------------------------------------------------------------------------
*/
const bolehDiubah = (row: BdvRow): boolean => row.can_update === true

const bolehDihapus = (row: BdvRow): boolean => row.can_delete === true

const bolehDiajukan = (row: BdvRow): boolean => row.can_submit === true

/*
| Voucher yang belum sampai ke pembukuan Accurate.
|
| Dibaca dari accurate_id, bukan dari status: keduanya tidak berhubungan.
| Draft bisa saja sudah ada di Accurate (memang begitu seharusnya), dan
| voucher yang sudah diajukan tidak mungkin belum -- server menolak
| mengajukan yang accurate_id-nya kosong.
*/
const belumDiAccurate = (row: BdvRow): boolean => row.accurate_in_sync !== true

/*
| Detail boleh dibuka siapa pun yang sudah bisa melihat daftarnya -- ia
| hanya membaca, dan barisnya sendiri sudah lolos batas akses di server.
*/
const adaAksi = (row: BdvRow): boolean =>
  canView.value || bolehDiubah(row) || bolehDihapus(row) || bolehDiajukan(row)
  || row.can_print === true

/*
|--------------------------------------------------------------------------
| Mengajukan
|--------------------------------------------------------------------------
| Penegasannya menyebut akibatnya, bukan hanya menanyakan "yakin?".
|
| Mengajukan mengunci vouchernya: sejak saat itu ia tidak bisa diubah lagi
| sampai seseorang mengembalikannya. Orang yang menekan tombol ini sering
| mengira masih bisa menyunting sesudahnya.
|--------------------------------------------------------------------------
*/
const mengajukan = ref(false)

const ajukan = async (row: BdvRow): Promise<void> => {
  if (mengajukan.value)
    return

  const konfirmasi = await showConfirmAlert({
    title: t('bdv.list.submit.confirmTitle'),
    text: t('bdv.list.submit.confirmText', {
      number: row.bdv_number || t('bdv.list.notNumbered'),
    }),
    confirmButtonText: t('bdv.list.submit.confirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!konfirmasi.isConfirmed)
    return

  mengajukan.value = true

  showLoadingAlert(t('bdv.list.submit.loading'), t('common.alert.pleaseWait'))

  try {
    const { data } = await axios.post(
      `${ENDPOINT}/${encodeURIComponent(row.public_id)}/submit`,
      {},
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: data?.message || t('bdv.list.submit.success'),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    closeAlert()

    /*
    | Pesan dari server ditampilkan apa adanya, bukan diganti kalimat umum.
    | Kegagalan di sini hampir selalu soal matriks approval yang belum
    | lengkap, dan pesannya menyebutkan persis kombinasi mana yang belum
    | ada -- satu-satunya petunjuk yang bisa dipakai admin.
    */
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.list.submit.failed')),
    })
  }
  finally {
    mengajukan.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Detail -- modal, bukan halaman
|--------------------------------------------------------------------------
| Mengikuti FPU dan Claim. Orang yang sedang memeriksa daftar biasanya
| membuka beberapa dokumen berturut-turut; modal mengembalikannya ke posisi
| gulir dan penyaring yang sama, sedangkan pindah halaman membuang keduanya
| dan memaksa daftarnya dimuat ulang setiap kali kembali.
|
| Isinya datang dari endpoint show() yang sama dengan halaman ubah --
| termasuk rincian dokumen sumbernya, yang sejak perbaikan ini ikut dalam
| jawaban itu dan tidak lagi diminta terpisah.
|--------------------------------------------------------------------------
*/
const detailDialog = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const detail = ref<BdvDetail | null>(null)

const bukaDetail = async (row: BdvRow): Promise<void> => {
  detailDialog.value = true
  detailLoading.value = true
  detailError.value = ''
  detail.value = null

  try {
    const { data } = await axios.get(
      `${ENDPOINT}/${encodeURIComponent(row.public_id)}`,
      { headers: { Accept: 'application/json' } },
    )

    detail.value = data?.data ?? null

    if (!detail.value)
      detailError.value = t('bdv.edit.notFound')
  }
  catch (error: unknown) {
    detailError.value = getApiErrorMessage(error, t('bdv.edit.loadFailed'))
  }
  finally {
    detailLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Tampilan isi modal
|--------------------------------------------------------------------------
*/
const angka = (nilai: number | null | undefined): string => formatAngka(nilai ?? 0)

const strip = t('bdv.create.detail.empty')

const isiAtauStrip = (nilai: string | null | undefined): string =>
  (nilai !== null && nilai !== undefined && String(nilai).trim() !== '')
    ? String(nilai)
    : strip

/*
| Ringkasan satu dokumen untuk isi accordion.
|
| Disusun sebagai daftar pasangan label-nilai, bukan ditulis satu per satu di
| markup: isinya sama untuk setiap dokumen, dan menuliskannya berulang berarti
| sembilan tempat yang harus diubah setiap kali satu kolom ditambah. Susunannya
| sengaja sama dengan halaman buat dan ubah -- yang memeriksa voucher membaca
| kolom yang sama di tempat yang sama.
*/
const ringkasanDokumen = (r: DokumenRincian): Array<{ label: string; nilai: string }> => [
  { label: t('bdv.create.detail.date'), nilai: tanggal(r.date) },
  { label: t('bdv.create.detail.subject'), nilai: isiAtauStrip(r.subject) },
  { label: t('bdv.create.detail.branch'), nilai: isiAtauStrip(r.branch) },
  { label: t('bdv.create.detail.department'), nilai: isiAtauStrip(r.department) },
  { label: t('bdv.create.detail.category'), nilai: isiAtauStrip(r.transaction_category) },
  { label: t('bdv.create.detail.requester'), nilai: isiAtauStrip(r.requester) },
  { label: t('bdv.create.detail.receivedAt'), nilai: tanggal(r.received_at) },
  { label: t('bdv.create.detail.scheduledPaymentDate'), nilai: tanggal(r.scheduled_payment_date) },
  { label: t('bdv.create.detail.amount'), nilai: rupiah(r.total_amount) },
]

const jumlahBukti = computed<number>(() => detail.value?.attachments?.total ?? 0)

const buktiTertinggal = computed<boolean>(() =>
  jumlahBukti.value > (detail.value?.attachments?.terkirim ?? 0))

const daftarSumber = computed<SumberBdv[]>(() => detail.value?.sources ?? [])

/*
| Dokumen yang rinciannya masih terbaca. Yang tidak -- dokumennya terhapus,
| atau jenisnya sudah tidak dikenal -- tetap tampil pada daftar di atas,
| tetapi tidak punya baris rincian untuk ditampilkan.
*/
const kelompokItem = computed(() =>
  daftarSumber.value
    .filter(s => s.detail !== null)
    .map(s => ({
      public_id: s.public_id,
      number: s.source_number,
      document_type_label: s.document_type_label,
      total_amount: Number(s.total_amount ?? 0),
      items: s.detail?.items ?? [],
    })))

const itemDetail = computed<DokumenItem[]>(() =>
  kelompokItem.value.flatMap(k => k.items))

/*
| Jumlah baris satu dokumen, untuk kaki tabelnya sendiri.
|
| Dihitung dari barisnya, bukan diambil dari total_amount dokumennya: yang
| dua itu bisa berbeda -- nilai dokumen masih bisa direvisi Finance -- dan
| kaki tabel harus menjumlahkan yang benar-benar tertera di atasnya.
*/
const jumlahKelompok = (k: { items: DokumenItem[] }): number =>
  k.items.reduce((total, i) => total + Number(i.amount ?? 0), 0)

/* Kolom qty hanya muncul bila ADA dokumen yang memakainya. */
const adaKolomQty = computed<boolean>(() =>
  kelompokItem.value.some(k => k.items.some(i => i.qty !== null || i.unit_price !== null)))

/*
| Pilihan akun, dikunci "<public_id dokumen>:<nomor baris>".
|
| Nomor baris saja TIDAK cukup: ia berulang antar dokumen -- FPU A punya
| baris 1, Claim B juga -- sehingga akun dokumen kedua akan menimpa akun
| dokumen pertama, dan yang terbaca di layar adalah akun yang salah pada
| baris yang benar. Tidak ada gejala apa pun untuk itu.
*/
const akunPerBaris = computed<Record<string, string>>(() => {
  const peta: Record<string, string> = {}

  for (const sumber of daftarSumber.value) {
    for (const item of sumber.items ?? []) {
      const nomor = (item.accurate_account_no ?? '').trim()
      const nama = (item.accurate_account_name ?? '').trim()

      if (nomor === '' && nama === '')
        continue

      peta[`${sumber.public_id}:${item.line_no}`] = nama !== ''
        ? `${nomor} - ${nama}`
        : nomor
    }
  }

  return peta
})

const kunciItem = (idDokumen: string, nomorBaris: number): string =>
  `${idDokumen}:${nomorBaris}`

const jumlahItemDetail = computed<number>(() =>
  itemDetail.value.reduce((total, i) => total + Number(i.amount ?? 0), 0))

/** Nilai seluruh dokumen yang dicairkan voucher ini. */
const jumlahDokumenDetail = computed<number>(() =>
  kelompokItem.value.reduce((total, k) => total + k.total_amount, 0))

/*
| Ikon berkas dari mime type-nya. PDF dan gambar dibedakan karena itulah dua
| bentuk bukti yang hampir selalu dilampirkan; sisanya satu ikon umum.
*/
const ikonLampiran = (mime: string | null | undefined): string => {
  const jenis = String(mime || '').toLowerCase()

  if (jenis.includes('pdf'))
    return 'tabler-file-type-pdf'

  if (jenis.startsWith('image/'))
    return 'tabler-photo'

  return 'tabler-paperclip'
}

/* Warna kepala modal mengikuti status -- terbaca sebelum tulisannya dibaca. */
const warnaKepalaDetail = computed<string>(() => warnaStatus(detail.value?.status ?? ''))

const riwayatApproval = computed<RiwayatApproval[]>(() => detail.value?.approvals ?? [])

const riwayatRevisi = computed<RevisionEntry[]>(() => detail.value?.revisions ?? [])

/*
| Rantai approval kosong KARENA pernah dikembalikan -- bukan karena belum
| pernah diajukan.
|
| Dua keadaan yang kelihatan sama persis dari sisi layar: keduanya berarti
| tabel bdv_approvals kosong. Yang membedakan hanya ada tidaknya riwayat
| revisi. Tanpa pembedaan ini, voucher yang sudah beredar berminggu-minggu
| diberi keterangan "belum diajukan" -- keterangan yang membuat pemohon
| mengira pengajuannya gagal terkirim, lalu mengajukan ulang hal yang
| sebenarnya sedang menunggu perbaikannya sendiri.
*/
const rantaiDibongkarRevisi = computed<boolean>(() =>
  riwayatApproval.value.length === 0 && riwayatRevisi.value.length > 0)

/*
| Rantai putaran TERAKHIR, dibentuk ulang dari arsipnya.
|
| riwayatRevisi urut dari yang terbaru (revisions() mengurutkan
| revision_number menurun), jadi yang pertama memang putaran terakhir.
|
| Dipetakan ke bentuk yang sama dengan rantai hidup, supaya garis waktunya
| tidak perlu tahu dari mana barisnya datang -- satu tampilan, bukan dua
| yang perlahan berbeda.
*/
const rantaiPutaranLalu = computed<RiwayatApproval[]>(() => {
  if (!rantaiDibongkarRevisi.value)
    return []

  const terakhir = riwayatRevisi.value[0]

  return (terakhir?.approvals_before ?? []).map(baris => ({
    step: baris.step_order ?? 0,
    role: baris.label,
    approver: baris.approver_name_snapshot,
    status: baris.status,

    /* Waktu tindakannya: disetujui atau ditolak, mana pun yang terisi. */
    acted_at: baris.approved_at ?? baris.rejected_at,
    notes: baris.notes,
  }))
})

/* Yang benar-benar digambar garis waktunya. */
const rantaiDitampilkan = computed<RiwayatApproval[]>(() =>
  (riwayatApproval.value.length > 0 ? riwayatApproval.value : rantaiPutaranLalu.value))

/*
| Jenis dokumen voucher yang sedang dibuka.
|
| Selalu satu -- seluruh dokumen dalam satu BDV wajib sejenis -- tetapi
| dibaca dari daftarnya, bukan dari kolom tersendiri: bentuk jawabannya
| mengikuti sumbernya, bukan aturan yang kebetulan berlaku hari ini.
*/
const jenisDetail = computed<string>(() => (detail.value?.document_types ?? [])[0] ?? '')

const kasBankDetail = computed<string>(() => {
  const nomor = (detail.value?.cash_bank_account_no ?? '').trim()
  const nama = (detail.value?.cash_bank_account_name ?? '').trim()

  if (nomor === '' && nama === '')
    return strip

  return nama !== '' ? `${nama} (${nomor})` : nomor
})

/*
|--------------------------------------------------------------------------
| Setujui / Tolak
|--------------------------------------------------------------------------
| Bentuknya mengikuti FPU: penegasan dulu, lalu satu permintaan, lalu daftar
| dan modalnya dimuat ulang supaya keadaannya tidak tertinggal di layar.
|
| Bedanya dengan FPU hanya satu, dan sementara: alur approval BDV belum
| dibangun, jadi can_approve dan can_reject selalu false dan tombolnya tidak
| pernah muncul. Jalurnya sudah utuh di sini supaya tahap berikutnya hanya
| menambah di sisi server -- bukan membongkar layar ini lagi.
|
| ALASAN PENOLAKAN WAJIB, persetujuan tidak.
|
| Dokumen yang ditolak kembali ke pembuatnya, dan ia harus tahu apa yang
| perlu diperbaiki. Penolakan tanpa alasan hanya memutar dokumen itu
| berkeliling sampai seseorang menyerah dan bertanya langsung.
*/
const memproses = ref(false)

/*
| SASARAN, bukan "baris yang sedang dibuka".
|
| Ketiga keputusan bisa datang dari dua tempat: menu titik-tiga pada daftar,
| dan kaki modal detail. Keduanya butuh hal yang sama persis -- sebuah
| public_id dan sebuah nomor untuk judul dialognya -- jadi jalurnya dibuat
| menerima itu saja.
|
| Tanpa ini, tombol di daftar harus membuka modalnya lebih dulu hanya untuk
| mengisi detail.value, dan approver yang menyetujui sepuluh voucher
| berturut-turut harus membuka dan menutup sepuluh modal.
*/
interface SasaranKeputusan {
  public_id: string
  bdv_number: string | null
}

type JalurKeputusan = 'approve' | 'reject' | 'return'

/*
| SATU DIALOG UNTUK TIGA KEPUTUSAN.
|
| Dulu ketiganya memakai SweetAlert bertextarea, dan rupanya berbeda dari
| FPU: judulnya tidak menyebut nomor, tombolnya berbunyi "Ya, setujui", dan
| kotak catatannya tidak punya penanda galat. Sekarang ketiganya memakai
| DecisionDialog -- komponen yang bentuknya diambil dari FPU -- sehingga
| approver yang berpindah antar modul menemui dialog yang sama persis.
|
| Satu salinan dialog, bukan tiga: yang membedakan ketiganya cuma teks,
| warna tombol, dan wajib-tidaknya catatan. Tiga salinan berarti tiga
| tempat yang harus sama-sama diingat setiap kali bentuknya berubah.
*/
const dialogKeputusan = ref(false)
const jalurKeputusan = ref<JalurKeputusan>('approve')
const sasaranKeputusan = ref<SasaranKeputusan | null>(null)

const sasaranDari = (row?: BdvRow): SasaranKeputusan | null => {
  if (row)
    return { public_id: row.public_id, bdv_number: row.bdv_number }

  if (detail.value)
    return { public_id: detail.value.public_id, bdv_number: detail.value.bdv_number }

  return null
}

const bukaKeputusan = (jalur: JalurKeputusan, row?: BdvRow): void => {
  const sasaran = sasaranDari(row)

  if (memproses.value || !sasaran)
    return

  jalurKeputusan.value = jalur
  sasaranKeputusan.value = sasaran
  dialogKeputusan.value = true
}

/*
| Tanpa argumen berarti "yang sedang dibuka di modal". Dipanggil dari daftar
| dengan barisnya, dari kaki modal lewat pembungkus di bawahnya.
*/
const setujui = (row?: BdvRow): void => bukaKeputusan('approve', row)
const tolak = (row?: BdvRow): void => bukaKeputusan('reject', row)
const kembalikan = (row?: BdvRow): void => bukaKeputusan('return', row)

/*
| Pembungkus tanpa argumen untuk kaki modal detail.
|
| Bukan kerapian: @click="setujui" meneruskan MouseEvent sebagai argumen
| pertama, dan setujui() akan mengira event itu sebuah baris daftar lalu
| membaca public_id yang tidak ada. Menulis @click="setujui()" menghindarinya
| tetapi dilarang aturan vue/v-on-function-call. Pembungkus ini satu-satunya
| bentuk yang benar sekaligus lolos.
*/
const setujuiDetail = (): void => bukaKeputusan('approve')
const tolakDetail = (): void => bukaKeputusan('reject')
const kembalikanDetail = (): void => bukaKeputusan('return')

const nomorKeputusan = computed<string>(() =>
  sasaranKeputusan.value?.bdv_number || t('bdv.list.notNumbered'))

/*
| Teks dan perilaku tiap jalur, dikumpulkan di satu tempat.
|
| minLength memakai angka yang SAMA dengan penjaga di server: penolakan
| cukup tidak kosong, pengembalian menuntut tiga huruf. Layar yang lebih
| longgar daripada server hanya akan meloloskan sesuatu yang pasti ditolak
| sesudah orangnya menekan tombol.
*/
const konfigKeputusan = computed(() => {
  const number = nomorKeputusan.value

  if (jalurKeputusan.value === 'reject') {
    return {
      title: t('bdv.detail.reject.dialogTitle', { number }),
      subtitle: t('bdv.detail.reject.dialogSubtitle'),
      notesLabel: t('bdv.detail.reject.notesLabel'),
      notesPlaceholder: t('bdv.detail.reject.notesPlaceholder'),
      confirmText: t('bdv.detail.reject.confirmButton'),
      confirmColor: 'error',
      minLength: 1,
      requiredMessage: t('bdv.detail.reject.notesRequired'),
    }
  }

  if (jalurKeputusan.value === 'return') {
    return {
      title: t('common.revision.dialogTitle', { number }),
      subtitle: t('common.revision.dialogSubtitle'),
      notesLabel: t('common.revision.reasonLabel'),
      notesPlaceholder: t('common.revision.reasonPlaceholder'),
      confirmText: t('common.revision.confirmButton'),

      /*
      | Warnanya BUKAN merah. Mengembalikan bukan menolak: dokumennya masih
      | hidup, dan yang diminta adalah perbaikan.
      */
      confirmColor: 'warning',
      minLength: 3,
      requiredMessage: t('common.revision.reasonRequired'),
    }
  }

  return {
    title: t('bdv.detail.approve.dialogTitle', { number }),
    subtitle: t('bdv.detail.approve.dialogSubtitle'),
    notesLabel: t('bdv.detail.approve.notesLabel'),
    notesPlaceholder: t('bdv.detail.approve.notesPlaceholder'),
    confirmText: t('bdv.detail.approve.confirmButton'),
    confirmColor: 'success',

    /* Catatan persetujuan memang opsional. */
    minLength: 0,
    requiredMessage: '',
  }
})

/*
| Mengirim keputusannya.
|
| Dialognya hanya ditutup bila BERHASIL. Kegagalan jaringan membiarkannya
| terbuka beserta catatan yang sudah diketik, supaya tidak ada yang perlu
| mengetik ulang alasan sepanjang satu paragraf.
*/
const jalankanKeputusan = async (catatan: string | null): Promise<void> => {
  const sasaran = sasaranKeputusan.value

  if (!sasaran || memproses.value)
    return

  const jalur = jalurKeputusan.value
  const revisi = jalur === 'return'

  memproses.value = true

  showLoadingAlert(
    revisi ? t('common.alert.processing') : t(`bdv.detail.${jalur}.loading`),
    t('common.alert.pleaseWait'),
  )

  try {
    const { data } = await axios.post(
      `${ENDPOINT}/${encodeURIComponent(sasaran.public_id)}/${revisi ? 'return-for-revision' : jalur}`,

      /*
      | Nama kolomnya berbeda, dan itu bukan kelalaian: menyetujui dan
      | menolak meninggalkan CATATAN pada baris approval, sedangkan
      | mengembalikan menuliskan ALASAN pada riwayat revisinya. Dua kolom
      | di dua tabel yang berbeda.
      */
      revisi ? { reason: catatan } : { notes: catatan },

      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: data?.message
        || (revisi
          ? t('common.revision.successFallback')
          : t(`bdv.detail.${jalur}.success`)),
    })

    dialogKeputusan.value = false
    detailDialog.value = false

    await fetchRows()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(
        error,
        revisi
          ? t('common.revision.failedFallback')
          : t(`bdv.detail.${jalur}.failed`),
      ),
    })
  }
  finally {
    memproses.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Cetak PDF
|--------------------------------------------------------------------------
| Mengikuti pola FPU dan Claim: tab dibuka LEBIH DULU, sebelum permintaan
| async-nya, supaya tidak diblokir popup blocker -- peramban hanya
| mengizinkan window.open yang lahir langsung dari klik orangnya.
|
| Tab itu langsung diisi halaman tunggu, bukan dibiarkan putih. Halaman itu
| tetap terlihat selama server merender PDF-nya, karena navigasi baru
| mengganti isi tab setelah jawabannya datang.
|
| TIGA PILIHAN, dan ketiganya ditanyakan di muka.
|
| Bahasa selalu ditanyakan: voucher ini ikut dibaca pihak bank, dan yang
| mencetaknya tahu untuk siapa -- bukan kami. Lampiran dan dokumen sumber
| ditawarkan karena yang memeriksa pembayaran hampir selalu perlu melihat
| keduanya berdampingan: voucher yang menyatakan berapa yang keluar, dan
| dokumen yang menerangkan untuk apa.
|--------------------------------------------------------------------------
*/
type BahasaCetak = 'id' | 'en'

const dialogCetak = ref(false)
const sasaranCetak = ref<SasaranKeputusan | null>(null)
const sedangMencetak = ref(false)
const bahasaCetak = ref<BahasaCetak>('id')
const cetakDenganLampiran = ref(false)
const cetakDenganSumber = ref(false)

const pilihanBahasaCetak = computed(() => [
  { value: 'id' as BahasaCetak, title: t('bdv.list.print.langId') },
  { value: 'en' as BahasaCetak, title: t('bdv.list.print.langEn') },
])

const bukaCetak = (row?: BdvRow): void => {
  const sasaran = sasaranDari(row)

  if (!sasaran || sedangMencetak.value)
    return

  sasaranCetak.value = sasaran

  /*
  | Pilihannya dikembalikan ke bawaan tiap kali dibuka.
  |
  | Mencetak adalah tindakan yang paling sering diulang, dan pilihan yang
  | menempel dari voucher sebelumnya akan diam-diam ikut pada voucher
  | berikutnya -- orangnya baru sadar sesudah PDF-nya jadi.
  */
  bahasaCetak.value = 'id'
  cetakDenganLampiran.value = false
  cetakDenganSumber.value = false

  dialogCetak.value = true
}

/* Pembungkus tanpa argumen untuk kaki modal detail -- lihat setujuiDetail. */
const cetakDariDetail = (): void => bukaCetak()

const jalankanCetak = async (): Promise<void> => {
  const sasaran = sasaranCetak.value

  if (!sasaran || sedangMencetak.value)
    return

  dialogCetak.value = false
  sedangMencetak.value = true

  const judulTunggu = t('bdv.list.print.loading')
  const teksTunggu = t('common.alert.pleaseWait')

  let tabCetak: Window | null = null

  try {
    showLoadingAlert(judulTunggu, teksTunggu)

    tabCetak = window.open('', '_blank')

    if (!tabCetak)
      throw new Error(t('bdv.list.print.popupBlocked'))

    tabCetak.document.open()
    tabCetak.document.write(buildPrintLoadingPage(judulTunggu, teksTunggu))
    tabCetak.document.close()

    /*
    | Yang diminta hanya tautannya. Berkas PDF-nya sendiri diunduh peramban
    | saat navigasi di bawah, jadi axios tidak ikut menunggu render selesai
    | -- cetakan bergabung lampiran bisa memakan puluhan detik.
    */
    const { data } = await axios.post(
      `${ENDPOINT}/${encodeURIComponent(sasaran.public_id)}/print-url`,

      /*
      | Pilihannya ikut ditandatangani server bersama tautannya, jadi ia
      | dikirim DI SINI -- bukan ditempelkan ke URL hasilnya, yang justru
      | akan merusak tanda tangannya.
      */
      {
        lang: bahasaCetak.value,
        with_attachments: cetakDenganLampiran.value,
        with_sources: cetakDenganSumber.value,
      },

      { headers: { Accept: 'application/json' } },
    )

    const tautan = data?.url

    if (data?.success === false || typeof tautan !== 'string' || !tautan.trim())
      throw new Error(data?.message || t('bdv.list.print.urlFailed'))

    if (tabCetak.closed)
      throw new Error(t('bdv.list.print.windowClosed'))

    closeAlert()

    tabCetak.location.replace(new URL(tautan, window.location.origin).toString())
  }
  catch (error: unknown) {
    closeAlert()

    if (tabCetak && !tabCetak.closed)
      tabCetak.close()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.list.print.failed')),
    })
  }
  finally {
    sedangMencetak.value = false
  }
}

/* Dari dalam modal, menuju halaman ubah. */
const ubahDariDetail = (): void => {
  if (!detail.value)
    return

  detailDialog.value = false

  router.push(`/cash_bank/bdv/edit?id=${encodeURIComponent(detail.value.public_id)}`)
}

const bukaUbah = (row: BdvRow): void => {
  router.push(`/cash_bank/bdv/edit?id=${encodeURIComponent(row.public_id)}`)
}

/*
|--------------------------------------------------------------------------
| Hapus
|--------------------------------------------------------------------------
| Penegasannya menyebut dua hal sekaligus, dan itu disengaja: vouchernya
| hilang DAN dokumennya ikut hilang dari pembukuan Accurate. Yang kedua
| itulah yang tidak terduga -- orang mengira sedang membuang draft di
| aplikasi ini saja.
|
| Nomor BDV-nya disebutkan di dalam kalimat penegasan, bukan hanya "data
| ini": menu dibuka dari baris yang salah adalah kekeliruan yang paling
| mudah terjadi pada tabel sepanjang ini.
|--------------------------------------------------------------------------
*/
const menghapus = ref(false)

const hapus = async (row: BdvRow): Promise<void> => {
  if (menghapus.value)
    return

  const konfirmasi = await showConfirmAlert({
    title: t('bdv.list.delete.confirmTitle'),
    text: t('bdv.list.delete.confirmText', {
      number: row.bdv_number || t('bdv.list.notNumbered'),
    }),
    confirmButtonText: t('bdv.list.delete.confirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!konfirmasi.isConfirmed)
    return

  menghapus.value = true

  /*
   * Diberi tanda sedang berjalan, bukan dibiarkan senyap. Penghapusannya
   * menunggu Accurate menjawab, dan itu bisa terasa lama -- cukup lama untuk
   * membuat orang menekan Hapus sekali lagi pada baris yang sama.
   */
  showLoadingAlert(t('bdv.list.delete.loading'), t('common.alert.pleaseWait'))

  try {
    const { data } = await axios.delete(
      `${ENDPOINT}/${encodeURIComponent(row.public_id)}`,
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    showSuccessToast({
      title: t('common.alert.success'),
      text: data?.message || t('bdv.list.delete.success'),
    })

    await fetchRows()
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.list.delete.failed')),
    })
  }
  finally {
    menghapus.value = false
  }
}

onMounted(async () => {
  /*
  | Kabar dari halaman buat dan halaman ubah dikeluarkan di sini, bukan di
  | sana. Toast yang ditampilkan tepat sebelum pindah halaman ikut lenyap
  | bersama halaman yang ditinggalkan sebelum sempat terbaca.
  |
  | Dipanggil sebelum izin dimuat: titipannya tidak boleh tertahan hanya
  | karena pemeriksaan izin sempat menunggu jaringan.
  */
  flushQueuedToast()

  await permissionStore.loadPermissions()

  if (!canView.value)
    return

  await Promise.all([fetchOptions(), fetchRows()])
})
</script>

<template>
  <div>
    <VCard v-if="permissionStore.isLoaded && !canView">
      <VCardText class="text-center py-12">
        <VIcon
          icon="tabler-lock"
          size="48"
          class="text-disabled mb-3"
        />

        <div class="text-body-1">
          {{ t('bdv.noAccess') }}
        </div>
      </VCardText>
    </VCard>

    <template v-else>
      <!-- ================= PENYARING ================= -->
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
                  icon="tabler-building-bank"
                  size="24"
                />
              </VAvatar>

              <div>
                <div class="text-h5 font-weight-bold">
                  {{ t('bdv.list.filtersTitle') }}
                </div>

                <div class="text-body-2 text-medium-emphasis mt-1">
                  {{ t('bdv.list.filtersSubtitle') }}
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
              {{ t('bdv.list.resetButton') }}
            </VBtn>
          </div>

          <VRow>
            <VCol
              cols="12"
              md="4"
            >
              <VTextField
                v-model="filter.search"
                density="compact"
                clearable
                hide-details
                prepend-inner-icon="tabler-search"
                :label="t('bdv.list.search')"
                @keyup.enter="terapkanFilter"
                @click:clear="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <VSelect
                v-model="filter.documentType"
                :menu-props="{ maxHeight: 300, location: 'bottom', offset: 4 }"
                density="compact"
                clearable
                hide-details
                prepend-inner-icon="tabler-file-text"
                :items="documentTypeOptions"
                :label="t('bdv.list.documentType')"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="4"
            >
              <VSelect
                v-model="filter.status"
                :menu-props="{ maxHeight: 300, location: 'bottom', offset: 4 }"
                density="compact"
                clearable
                hide-details
                prepend-inner-icon="tabler-flag"
                :items="statusOptions"
                :label="t('bdv.list.status')"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="3"
            >
              <VAutocomplete
                v-model="filter.branch"
                :menu-props="{ maxHeight: 300, location: 'bottom', offset: 4 }"
                density="compact"
                clearable
                hide-details
                prepend-inner-icon="tabler-building"
                :items="branches"
                :label="t('bdv.list.branch')"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="3"
            >
              <VAutocomplete
                v-model="filter.departmentId"
                :menu-props="{ maxHeight: 300, location: 'bottom', offset: 4 }"
                density="compact"
                clearable
                hide-details
                prepend-inner-icon="tabler-users-group"
                :items="departments"
                :label="t('bdv.list.department')"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <!--
              Memakai AppDateTimePicker, bukan input tanggal bawaan peramban.
              Yang bawaan mengikuti setelan wilayah komputer pemakainya,
              sehingga di sebagian mesin tertulis mm/dd/yyyy -- urutan yang
              berbeda dari seluruh tanggal lain di aplikasi ini, dan sumber
              salah baca yang tidak disadari.
            -->
            <VCol
              cols="12"
              md="3"
            >
              <AppDateTimePicker
                v-model="filter.dateFrom"
                density="compact"
                clearable
                :label="t('bdv.list.dateFrom')"
                :config="{ dateFormat: 'Y-m-d', position: 'below' }"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <VCol
              cols="12"
              md="3"
            >
              <AppDateTimePicker
                v-model="filter.dateTo"
                density="compact"
                clearable
                :label="t('bdv.list.dateTo')"
                :config="{ dateFormat: 'Y-m-d', position: 'below' }"
                @update:model-value="terapkanFilter"
              />
            </VCol>

            <!--
              Hanya bagi yang memang berdiri di rantai approval BDV.

              Bagi yang tidak pernah menyetujui apa pun di sini -- pemohon,
              GA, kasir -- sakelar ini hanya tombol yang selalu mengosongkan
              daftar. Yang menyalakannya akan mengira datanya hilang, bukan
              mengira dirinya memang bukan penyetuju.
            -->
            <VCol
              v-if="abilities.is_approver"
              cols="12"
              md="6"
            >
              <div class="bdv-approval-filter">
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
                    {{ t('bdv.list.onlyMyApproval') }}
                  </div>
                </div>

                <VSwitch
                  v-model="onlyWaitingMyApproval"
                  color="warning"
                  inset
                  hide-details
                  @update:model-value="terapkanFilter"
                />
              </div>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <!-- ================= TABEL ================= -->
      <VCard>
        <VCardText class="d-flex flex-wrap gap-4 align-center">
          <VBtn
            v-if="canCreate"
            color="primary"
            prepend-icon="tabler-plus"
            class="text-none"
            @click="bukaFormulirBuat"
          >
            {{ t('bdv.list.addButton') }}
          </VBtn>

          <VSpacer />
        </VCardText>

        <VDivider />

        <VProgressLinear
          v-if="loading"
          indeterminate
          color="primary"
        />

        <VTable class="text-no-wrap">
          <thead>
            <!--
              Kolomnya berhenti di status, dan tidak ada kolom Aksi
              tersendiri: tindakannya menempel pada nomor BDV, sama seperti
              daftar FPU dan Claim. Satu kolom selebar satu tombol di ujung
              kanan hanya menggeser tabel menjauh tanpa menambah apa pun.
            -->
            <tr>
              <th
                scope="col"
                style="inline-size: 4rem;"
              >
                {{ t('bdv.list.columns.no') }}
              </th>
              <th scope="col">
                {{ t('bdv.list.columns.number') }}
              </th>
              <th scope="col">
                {{ t('bdv.list.columns.date') }}
              </th>
              <th scope="col">
                {{ t('bdv.list.columns.documentType') }}
              </th>
              <th scope="col">
                {{ t('bdv.list.columns.sourceNumber') }}
              </th>
              <th scope="col">
                {{ t('bdv.list.columns.branch') }}
              </th>
              <th scope="col">
                {{ t('bdv.list.columns.department') }}
              </th>
              <th
                scope="col"
                class="text-end"
              >
                {{ t('bdv.list.columns.amount') }}
              </th>
              <th scope="col">
                {{ t('bdv.list.columns.status') }}
              </th>
            </tr>
          </thead>

          <tbody>
            <tr v-if="!loading && rows.length === 0">
              <td
                colspan="9"
                class="text-center py-10 text-medium-emphasis"
              >
                {{ adaPenyaringAktif ? t('bdv.list.emptyFiltered') : t('bdv.list.empty') }}
              </td>
            </tr>

            <!--
              Baris yang menunggu keputusan orang yang sedang membukanya
              diberi warna latar, bukan hanya chip.

              Chip-nya menjawab "baris ini menunggu saya" kalau sudah
              dibaca; warnanya menjawab "ada yang menunggu saya di halaman
              ini" sebelum satu baris pun dibaca. Dua pertanyaan berbeda,
              dan yang kedua yang lebih dulu muncul saat daftar dibuka.
            -->
            <tr
              v-for="(row, index) in rows"
              :key="row.public_id"
              :class="{ 'bdv-row-need-approval': row.can_approve }"
            >
              <!--
                Nomornya meneruskan hitungan antar halaman, bukan mulai dari
                1 lagi di tiap halaman: baris ke-26 harus tetap bernomor 26
                supaya bisa disebut saat dibicarakan dengan orang lain.
              -->
              <td class="text-medium-emphasis">
                {{ ((currentPage - 1) * rowPerPage) + Number(index) + 1 }}
              </td>

              <!--
                Tindakannya menempel di nomor BDV. Menunya hanya dipasang
                bila memang ada isinya -- nomor yang bisa diklik tetapi
                membuka daftar kosong lebih membingungkan daripada nomor
                yang memang diam.
              -->
              <td class="font-weight-medium">
                <VMenu
                  v-if="adaAksi(row)"
                  location="bottom start"
                >
                  <template #activator="{ props }">
                    <div
                      v-bind="props"
                      class="bdv-number-action d-inline-flex align-center gap-1 text-primary"
                    >
                      <span>{{ row.bdv_number || t('bdv.list.notNumbered') }}</span>

                      <VIcon
                        icon="tabler-chevron-down"
                        size="16"
                      />

                      <!--
                        Penanda "belum sampai Accurate". Menempel pada nomor
                        BDV, bukan di kolom tersendiri: yang perlu dilihat
                        bukan "berapa banyak", melainkan "yang mana".
                      -->
                      <VTooltip
                        v-if="belumDiAccurate(row)"
                        location="top"
                      >
                        <template #activator="{ props: tip }">
                          <VIcon
                            v-bind="tip"
                            icon="tabler-cloud-off"
                            size="16"
                            color="warning"
                          />
                        </template>

                        {{ t('bdv.list.notInAccurate') }}
                      </VTooltip>
                    </div>
                  </template>

                  <VList>
                    <VListItem
                      v-if="canView"
                      href="javascript:void(0)"
                      @click="bukaDetail(row)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-eye"
                          :size="20"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle>
                        {{ t('bdv.list.menu.detail') }}
                      </VListItemTitle>
                    </VListItem>

                    <!--
                      Cetak ditaruh tepat di bawah Lihat Detail: keduanya
                      hanya membaca, dan mencetak adalah hal yang paling
                      sering diulang orang pada voucher yang sudah selesai.
                      Munculnya dijawab server lewat can_print -- status
                      saja tidak cukup dibaca layar, karena yang menentukan
                      adalah keadaan terakhir di basis data.
                    -->
                    <VListItem
                      v-if="row.can_print"
                      href="javascript:void(0)"
                      :disabled="sedangMencetak"
                      @click="bukaCetak(row)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-printer"
                          :size="20"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle>
                        {{ t('bdv.list.menu.print') }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="bolehDiubah(row)"
                      href="javascript:void(0)"
                      @click="bukaUbah(row)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-pencil"
                          :size="20"
                          class="me-3"
                        />
                      </template>

                      <VListItemTitle>
                        {{ t('bdv.list.menu.edit') }}
                      </VListItemTitle>
                    </VListItem>

                    <!--
                      Ajukan: memindahkan vouchernya ke meja penyetuju, dan
                      menguncinya dari perubahan. Diberi warna supaya tidak
                      tertukar dengan Ubah yang ada tepat di atasnya.
                    -->
                    <VListItem
                      v-if="bolehDiajukan(row)"
                      href="javascript:void(0)"
                      :disabled="mengajukan"
                      @click="ajukan(row)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-send"
                          :size="20"
                          class="me-3 text-primary"
                        />
                      </template>

                      <VListItemTitle class="text-primary">
                        {{ t('bdv.list.menu.submit') }}
                      </VListItemTitle>
                    </VListItem>

                    <!--
                      Keputusan approver, langsung dari daftar.
                      |
                      Ketiganya memakai can_* dari server, bukan status
                      barisnya: "IN PROGRESS" tidak berarti menunggu orang
                      yang kebetulan membuka daftar ini. Tanpa jawaban
                      server, setiap pemegang izin bdv.approve akan ditawari
                      Setujui pada setiap voucher berjalan, termasuk yang
                      tahapannya masih menunggu orang lain.
                    -->
                    <VListItem
                      v-if="row.can_approve"
                      href="javascript:void(0)"
                      :disabled="memproses"
                      @click="setujui(row)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-circle-check"
                          :size="20"
                          class="me-3 text-success"
                        />
                      </template>

                      <VListItemTitle class="text-success">
                        {{ t('bdv.detail.approve.button') }}
                      </VListItemTitle>
                    </VListItem>

                    <VListItem
                      v-if="row.can_reject"
                      href="javascript:void(0)"
                      :disabled="memproses"
                      @click="tolak(row)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="tabler-x"
                          :size="20"
                          class="me-3 text-error"
                        />
                      </template>

                      <VListItemTitle class="text-error">
                        {{ t('bdv.detail.reject.button') }}
                      </VListItemTitle>
                    </VListItem>

                    <!--
                      Kembalikan ditaruh SESUDAH Tolak, dan diberi warna
                      ketiga. Dua tindakan "tidak setuju" yang berdampingan
                      dengan rupa yang sama adalah cara tercepat mematikan
                      dokumen yang sebenarnya hanya perlu diperbaiki.
                    -->
                    <VListItem
                      v-if="row.can_return_for_revision"
                      href="javascript:void(0)"
                      :disabled="memproses"
                      @click="kembalikan(row)"
                    >
                      <template #prepend>
                        <VIcon
                          icon="mdi-undo-variant"
                          :size="20"
                          class="me-3 text-warning"
                        />
                      </template>

                      <VListItemTitle class="text-warning">
                        {{ t('common.revision.menuReturn') }}
                      </VListItemTitle>
                    </VListItem>

                    <!--
                      Hapus dipisah garis dan diberi warna merah: ia satu-
                      satunya entri di sini yang tidak bisa dibatalkan, dan
                      ia menghapus dokumennya di Accurate juga.
                    -->
                    <template v-if="bolehDihapus(row)">
                      <VDivider class="my-1" />

                      <VListItem
                        href="javascript:void(0)"
                        :disabled="menghapus"
                        @click="hapus(row)"
                      >
                        <template #prepend>
                          <VIcon
                            icon="tabler-trash"
                            :size="20"
                            class="me-3 text-error"
                          />
                        </template>

                        <VListItemTitle class="text-error">
                          {{ t('bdv.list.menu.delete') }}
                        </VListItemTitle>
                      </VListItem>
                    </template>
                  </VList>
                </VMenu>

                <!--
                  Tanpa menu, tetapi penandanya tetap ada: orang yang hanya
                  boleh melihat pun perlu tahu voucher mana yang belum sampai
                  pembukuan.
                -->
                <template v-else>
                  <span class="d-inline-flex align-center gap-1">
                    <span v-if="row.bdv_number">{{ row.bdv_number }}</span>

                    <span
                      v-else
                      class="text-disabled font-weight-regular"
                    >
                      {{ t('bdv.list.notNumbered') }}
                    </span>

                    <VTooltip
                      v-if="belumDiAccurate(row)"
                      location="top"
                    >
                      <template #activator="{ props: tip }">
                        <VIcon
                          v-bind="tip"
                          icon="tabler-cloud-off"
                          size="16"
                          color="warning"
                        />
                      </template>

                      {{ t('bdv.list.notInAccurate') }}
                    </VTooltip>
                  </span>
                </template>

                <!--
                  Penanda bahwa voucher ini menunggu keputusan orang yang
                  sedang membukanya.

                  Dipasang dari can_approve -- jawaban server yang sama yang
                  memunculkan tombol Setujui -- supaya penanda dan tombolnya
                  tidak mungkin berselisih.
                -->
                <div
                  v-if="row.can_approve"
                  class="mt-1"
                >
                  <VChip
                    size="x-small"
                    color="warning"
                    variant="tonal"
                  >
                    <VIcon
                      icon="tabler-alert-circle"
                      size="14"
                      start
                    />

                    {{ t('bdv.list.menu.waitingMyApprovalBadge') }}
                  </VChip>
                </div>
              </td>

              <td>{{ tanggal(row.date) }}</td>

              <td>
                <VChip
                  v-for="jenis in (row.document_types ?? [])"
                  :key="jenis"
                  size="small"
                  variant="tonal"
                  color="info"
                >
                  {{ jenis }}
                </VChip>

                <span
                  v-if="!(row.document_types ?? []).length"
                  class="text-disabled"
                >-</span>
              </td>

              <!--
                Nomor dokumennya berjajar, satu per baris.

                Bukan dirangkai dengan koma: nomor dokumen sudah memuat garis
                miring, dan dua nomor yang dirangkai pada satu baris terbaca
                sebagai satu nomor panjang yang keliru.
              -->
              <td>
                <div
                  v-if="(row.source_numbers ?? []).length"
                  class="d-flex flex-column"
                >
                  <span
                    v-for="nomor in row.source_numbers"
                    :key="nomor"
                  >{{ nomor }}</span>
                </div>

                <span
                  v-else
                  class="text-disabled"
                >-</span>
              </td>

              <td>{{ row.branch ?? '-' }}</td>

              <td>{{ row.department ?? '-' }}</td>

              <td class="text-end font-weight-medium">
                {{ rupiah(row.total_amount) }}
              </td>

              <td>
                <div class="d-flex align-center flex-wrap gap-1">
                  <VChip
                    size="small"
                    variant="tonal"
                    :color="warnaStatus(row.status)"
                  >
                    {{ labelStatus(row.status) }}
                  </VChip>

                  <!--
                    Berapa kali voucher ini pernah dipulangkan. Ditaruh di
                    sebelah statusnya, bukan di kolom tersendiri: ia hanya
                    berarti bersama status itu, dan kolom yang hampir selalu
                    kosong memakan lebar yang dibutuhkan kolom lain.
                  -->
                  <VChip
                    v-if="(row.revision_count ?? 0) > 0"
                    size="x-small"
                    variant="tonal"
                    color="warning"
                  >
                    {{ t('common.revision.badge', { n: row.revision_count }) }}
                  </VChip>
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>

        <VDivider />

        <VCardText class="d-flex flex-wrap justify-space-between align-center gap-4">
          <div class="d-flex align-center gap-3">
            <span class="text-body-2">{{ paginationData }}</span>

            <VSelect
              v-model="rowPerPage"
              :menu-props="{ maxHeight: 300, location: 'bottom', offset: 4 }"
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
    </template>

    <!-- ================= MODAL DETAIL ================= -->
    <VDialog
      v-model="detailDialog"
      max-width="1120"
      scrollable
    >
      <VCard class="bdv-detail">
        <!--
          Kepala modal: nomor, status, dan nilainya dijadikan satu blok.
          Ketiganya yang paling sering dicari, dan menaruhnya berjauhan
          memaksa mata berpindah tiga kali untuk satu pertanyaan.
        -->
        <div
          class="bdv-detail__header"
          :class="[`bdv-detail__header--${warnaKepalaDetail}`]"
        >
          <div class="d-flex align-center justify-space-between gap-3">
            <div class="bdv-detail__eyebrow">
              <VIcon
                icon="tabler-file-invoice"
                size="16"
              />

              {{ t('bdv.detail.title') }}
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
            <div style="min-inline-size: 0;">
              <div class="bdv-detail__number">
                {{ detail?.bdv_number || strip }}
              </div>

              <div class="d-flex align-center flex-wrap gap-2 mt-2">
                <VChip
                  v-if="detail?.status"
                  size="small"
                  variant="tonal"
                  :color="warnaStatus(detail.status)"
                >
                  {{ labelStatus(detail.status) }}
                </VChip>

                <VChip
                  v-for="nomor in (detail?.source_numbers ?? [])"
                  :key="nomor"
                  size="small"
                  variant="tonal"
                  color="info"
                >
                  {{ jenisDetail }} · {{ nomor }}
                </VChip>

                <!--
                  Jejak Accurate di kepala, bukan dikubur di dalam. "Sudah
                  masuk pembukuan atau belum" adalah hal pertama yang
                  ditanyakan tentang sebuah voucher.
                -->
                <!--
                  Tiga keadaan, bukan dua. Yang di tengah paling mudah luput:
                  dokumennya ADA di Accurate dengan nomornya sendiri, tetapi
                  isinya di sana bukan isi yang sedang dibaca di layar ini.
                -->
                <VChip
                  v-if="detail && detail.accurate_in_sync"
                  size="small"
                  variant="tonal"
                  color="success"
                >
                  <VIcon
                    icon="tabler-check"
                    size="14"
                    start
                  />

                  {{ detail.accurate_number || detail.accurate_id }}
                </VChip>

                <VChip
                  v-else-if="detail && detail.accurate_id"
                  size="small"
                  variant="tonal"
                  color="warning"
                >
                  <VIcon
                    icon="tabler-cloud-off"
                    size="14"
                    start
                  />

                  {{ t('bdv.detail.accurateStale') }}
                </VChip>

                <VChip
                  v-else-if="detail"
                  size="small"
                  variant="tonal"
                  color="warning"
                >
                  <VIcon
                    icon="tabler-alert-triangle"
                    size="14"
                    start
                  />

                  {{ t('bdv.detail.accurateNotSent') }}
                </VChip>
              </div>
            </div>

            <div
              v-if="detail"
              class="bdv-detail__amount"
            >
              <div class="bdv-detail__amount-label">
                {{ t('bdv.list.columns.amount') }}
              </div>

              <div class="bdv-detail__amount-value">
                {{ rupiah(detail.total_amount) }}
              </div>
            </div>
          </div>
        </div>

        <VDivider />

        <VCardText
          class="bdv-detail__body"
          style="max-block-size: 68vh;"
        >
          <div
            v-if="detailLoading"
            class="py-12 text-center"
          >
            <VProgressCircular
              indeterminate
              color="primary"
            />
          </div>

          <VAlert
            v-else-if="detailError"
            type="error"
            variant="tonal"
            density="compact"
          >
            {{ detailError }}
          </VAlert>

          <template v-else-if="detail">
            <!--
              Alasan pengembalian, PALING ATAS di dalam badan detail.
              |
              Yang membuka voucher berstatus "Butuh Revisi" datang untuk
              satu pertanyaan: apa yang harus diperbaiki. Menaruhnya di
              bawah rincian berarti ia menggulir melewati hal-hal yang
              sudah ia tahu untuk menemukan satu-satunya hal yang belum.
              |
              Di DALAM template ini, bukan sebelumnya: v-else-if harus
              bertetangga langsung dengan v-if/v-else-if di atasnya, dan
              menyelipkan elemen di antaranya memutus rantai itu.
            -->
            <VAlert
              v-if="detail.open_revision"
              type="warning"
              variant="tonal"
              density="comfortable"
              class="mb-4"
            >
              <div class="font-weight-medium mb-1">
                {{ t('common.revision.badge', { n: detail.open_revision.revision_number }) }}

                <template v-if="detail.open_revision.returned_by">
                  &middot; {{ t('common.revision.returnedBy') }}
                  {{ detail.open_revision.returned_by }}
                </template>

                <template v-if="detail.open_revision.returned_step_label">
                  {{ t('common.revision.returnedAtStep', { step: detail.open_revision.returned_step_label }) }}
                </template>
              </div>

              <div class="text-body-2">
                {{ detail.open_revision.return_reason }}
              </div>
            </VAlert>

            <!-- ---------- DATA VOUCHER ---------- -->
            <div class="bdv-detail__section-title">
              <VIcon
                icon="tabler-wallet"
                size="18"
              />

              {{ t('bdv.edit.step2.title') }}
            </div>

            <div class="bdv-detail__grid">
              <div class="bdv-detail__field">
                <VIcon
                  icon="tabler-building-bank"
                  size="18"
                />

                <div class="bdv-detail__field-body">
                  <div class="bdv-detail__label">
                    {{ t('bdv.create.voucher.fields.cashBank') }}
                  </div>

                  <div class="bdv-detail__value">
                    {{ kasBankDetail }}
                  </div>
                </div>
              </div>

              <div class="bdv-detail__field">
                <VIcon
                  icon="tabler-calendar"
                  size="18"
                />

                <div class="bdv-detail__field-body">
                  <div class="bdv-detail__label">
                    {{ t('bdv.create.voucher.fields.date') }}
                  </div>

                  <div class="bdv-detail__value">
                    {{ tanggal(detail.date) }}
                  </div>
                </div>
              </div>

              <div class="bdv-detail__field">
                <VIcon
                  icon="tabler-user-check"
                  size="18"
                />

                <div class="bdv-detail__field-body">
                  <div class="bdv-detail__label">
                    {{ t('bdv.create.voucher.fields.payee') }}
                  </div>

                  <div class="bdv-detail__value">
                    {{ isiAtauStrip(detail.payee) }}
                  </div>
                </div>
              </div>

              <div class="bdv-detail__field">
                <VIcon
                  icon="tabler-receipt"
                  size="18"
                />

                <div class="bdv-detail__field-body">
                  <div class="bdv-detail__label">
                    {{ t('bdv.create.voucher.fields.checkNumber') }}
                  </div>

                  <div class="bdv-detail__value">
                    {{ isiAtauStrip(detail.check_number) }}
                  </div>
                </div>
              </div>

              <div class="bdv-detail__field">
                <VIcon
                  icon="tabler-building"
                  size="18"
                />

                <div class="bdv-detail__field-body">
                  <div class="bdv-detail__label">
                    {{ t('bdv.create.voucher.fields.branch') }}
                  </div>

                  <div class="bdv-detail__value">
                    {{ isiAtauStrip(detail.branch) }}
                  </div>
                </div>
              </div>

              <div class="bdv-detail__field">
                <VIcon
                  icon="tabler-users-group"
                  size="18"
                />

                <div class="bdv-detail__field-body">
                  <div class="bdv-detail__label">
                    {{ t('bdv.list.columns.department') }}
                  </div>

                  <div class="bdv-detail__value">
                    {{ isiAtauStrip(detail.department) }}
                  </div>
                </div>
              </div>

              <div class="bdv-detail__field">
                <VIcon
                  icon="tabler-user"
                  size="18"
                />

                <div class="bdv-detail__field-body">
                  <div class="bdv-detail__label">
                    {{ t('bdv.detail.requester') }}
                  </div>

                  <div class="bdv-detail__value">
                    {{ isiAtauStrip(detail.requester) }}
                  </div>
                </div>
              </div>

              <div class="bdv-detail__field">
                <VIcon
                  icon="tabler-cloud-upload"
                  size="18"
                />

                <div class="bdv-detail__field-body">
                  <div class="bdv-detail__label">
                    {{ t('bdv.detail.accurate') }}
                  </div>

                  <div class="bdv-detail__value">
                    {{ detail.accurate_sent_at ? tanggal(detail.accurate_sent_at) : t('bdv.detail.accurateNotSent') }}
                  </div>
                </div>
              </div>

              <!--
                Berapa bukti yang sudah ikut ke Accurate.
                Muncul hanya bila vouchernya memang punya bukti.

                Ditulis di sini, bukan hanya sebagai toast saat menyimpan:
                toast lewat sekali, sedangkan pertanyaan "buktinya sudah
                masuk belum?" biasanya baru muncul beberapa hari kemudian.
              -->
              <div
                v-if="jumlahBukti > 0"
                class="bdv-detail__field"
              >
                <VIcon
                  icon="tabler-paperclip"
                  size="18"
                />

                <div class="bdv-detail__field-body">
                  <div class="bdv-detail__label">
                    {{ t('bdv.detail.attachments') }}
                  </div>

                  <div class="bdv-detail__value d-flex align-center flex-wrap gap-2">
                    {{ t('bdv.detail.attachmentProgress', {
                      sent: detail.attachments?.terkirim ?? 0,
                      total: jumlahBukti,
                    }) }}

                    <VChip
                      v-if="buktiTertinggal"
                      size="x-small"
                      variant="tonal"
                      color="warning"
                    >
                      {{ t('bdv.detail.attachmentPending') }}
                    </VChip>
                  </div>
                </div>
              </div>
            </div>

            <div
              v-if="detail.notes"
              class="bdv-detail__note mt-3"
            >
              <div class="bdv-detail__label mb-1">
                {{ t('bdv.create.voucher.fields.notes') }}
              </div>

              <!--
                pre-line: catatannya bisa berisi ganti baris yang diketik
                orangnya, dan ganti baris itu ikut terkirim ke keterangan
                dokumen di Accurate. Dirapatkan di sini berarti yang terbaca
                di dua tempat menjadi berbeda.
              -->
              <div
                class="bdv-detail__note-text"
                v-text="detail.notes"
              />
            </div>

            <!-- ---------- DOKUMEN SUMBER ---------- -->
            <div class="bdv-detail__section-title">
              <VIcon
                icon="tabler-file-text"
                size="18"
              />

              {{ t('bdv.edit.step1.title') }}

              <VChip
                v-if="daftarSumber.length"
                size="x-small"
                variant="tonal"
                color="info"
                class="ms-1"
              >
                {{ t('bdv.create.step3.count', { count: daftarSumber.length }) }}
              </VChip>
            </div>

            <VAlert
              v-if="daftarSumber.length === 0"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('bdv.edit.step1.unavailable') }}
            </VAlert>

            <!--
              Accordion, sama seperti halaman buat dan ubah.

              Satu voucher bisa mencairkan belasan dokumen. Rincian lengkap
              semuanya sekaligus membuat modal ini sepanjang beberapa layar,
              dan yang paling dicari -- dokumen apa saja yang dicairkan --
              justru terbenam di antaranya.
            -->
            <template v-else>
              <VExpansionPanels
                variant="accordion"
                class="bdv-detail__panel"
              >
                <VExpansionPanel
                  v-for="dok in daftarSumber"
                  :key="dok.public_id"
                >
                  <VExpansionPanelTitle>
                    <div class="d-flex align-center justify-space-between flex-wrap gap-2 w-100 pe-2">
                      <div class="d-flex align-center flex-wrap gap-2 min-width-0">
                        <VChip
                          size="x-small"
                          variant="tonal"
                          color="info"
                        >
                          {{ dok.document_type_label }}
                        </VChip>

                        <span class="font-weight-medium">{{ dok.source_number }}</span>

                        <!--
                          Dokumen yang rinciannya tidak lagi terbaca tetap
                          tampil, dengan penanda. Voucher ini mencairkannya,
                          jadi menyembunyikannya membuat nilai voucher tidak
                          bisa dipertanggungjawabkan.
                        -->
                        <VChip
                          v-if="!dok.detail"
                          size="x-small"
                          variant="tonal"
                          color="warning"
                        >
                          {{ t('bdv.edit.step1.unreadable') }}
                        </VChip>
                      </div>

                      <span class="font-weight-medium text-no-wrap">
                        {{ rupiah(dok.total_amount) }}
                      </span>
                    </div>
                  </VExpansionPanelTitle>

                  <VExpansionPanelText>
                    <VAlert
                      v-if="!dok.detail"
                      type="info"
                      variant="tonal"
                      density="compact"
                    >
                      {{ t('bdv.edit.step1.unavailable') }}
                    </VAlert>

                    <template v-else>
                      <div class="bdv-detail__grid">
                        <div
                          v-for="kolom in ringkasanDokumen(dok.detail)"
                          :key="kolom.label"
                          class="bdv-detail__cell"
                        >
                          <div class="bdv-detail__label">
                            {{ kolom.label }}
                          </div>

                          <div class="bdv-detail__value">
                            {{ kolom.nilai }}
                          </div>
                        </div>
                      </div>

                      <div
                        v-if="dok.detail.notes"
                        class="bdv-detail__note mt-3"
                      >
                        <div class="bdv-detail__label mb-1">
                          {{ t('bdv.create.detail.notes') }}
                        </div>

                        <div
                          class="bdv-detail__note-text"
                          v-text="dok.detail.notes"
                        />
                      </div>
                    </template>
                  </VExpansionPanelText>
                </VExpansionPanel>
              </VExpansionPanels>

              <!--
                Jumlah nilai SELURUH dokumen. Inilah angka yang dibandingkan
                dengan nominal voucher di atas: bila keduanya berbeda, ada
                dokumen yang ikut tercairkan atau tertinggal, dan tidak ada
                tempat lain di layar ini yang memperlihatkannya.
              -->
              <div
                v-if="daftarSumber.length > 1"
                class="d-flex justify-end align-center gap-3 mt-3"
              >
                <span class="text-body-2 text-medium-emphasis">
                  {{ t('bdv.create.step3.documentsTotal') }}
                </span>

                <span class="text-body-1 font-weight-bold">
                  {{ rupiah(jumlahDokumenDetail) }}
                </span>
              </div>
            </template>

            <!-- ---------- RINCIAN ITEM ---------- -->
            <div class="bdv-detail__section-title">
              <VIcon
                icon="tabler-list-details"
                size="18"
              />

              {{ t('bdv.create.items.title') }}

              <VChip
                v-if="itemDetail.length"
                size="x-small"
                variant="tonal"
                color="info"
                class="ms-1"
              >
                {{ t('bdv.create.items.count', { count: itemDetail.length }) }}
              </VChip>
            </div>

            <VAlert
              v-if="kelompokItem.length === 0"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('bdv.edit.step3.unavailable') }}
            </VAlert>

            <VAlert
              v-else-if="itemDetail.length === 0"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('bdv.create.items.empty') }}
            </VAlert>

            <!--
              SATU TABEL PER DOKUMEN, bukan satu tabel panjang.

              Nomor baris berulang antar dokumen. Rincian yang dirata menjadi
              satu daftar membuat yang memeriksa baris ke-7 tidak punya cara
              tahu baris itu milik dokumen yang mana -- padahal justru itulah
              yang harus diperiksa sebelum voucher ditandatangani.
            -->
            <template v-else>
              <div
                v-for="(kelompok, urutanKelompok) in kelompokItem"
                :key="`item-${kelompok.public_id}`"
                :class="urutanKelompok > 0 ? 'mt-6' : ''"
              >
                <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-2">
                  <div class="d-flex align-center flex-wrap gap-2 min-width-0">
                    <VChip
                      size="x-small"
                      variant="tonal"
                      color="info"
                    >
                      {{ kelompok.document_type_label }}
                    </VChip>

                    <span class="font-weight-medium">{{ kelompok.number }}</span>
                  </div>

                  <span class="text-body-2 font-weight-medium text-no-wrap">
                    {{ rupiah(kelompok.total_amount) }}
                  </span>
                </div>

                <div class="bdv-detail__table">
                  <VTable density="comfortable">
                    <thead>
                      <tr>
                        <th style="inline-size: 44px;">
                          {{ t('bdv.create.items.columns.no') }}
                        </th>
                        <th style="inline-size: 110px;">
                          {{ t('bdv.create.items.columns.date') }}
                        </th>
                        <th style="min-inline-size: 180px;">
                          {{ t('bdv.create.items.columns.description') }}
                        </th>
                        <th style="min-inline-size: 130px;">
                          {{ t('bdv.create.items.columns.category') }}
                        </th>

                        <th
                          v-if="adaKolomQty"
                          class="text-end"
                          style="inline-size: 70px;"
                        >
                          {{ t('bdv.create.items.columns.qty') }}
                        </th>

                        <th
                          v-if="adaKolomQty"
                          class="text-end"
                          style="inline-size: 120px;"
                        >
                          {{ t('bdv.create.items.columns.unitPrice') }}
                        </th>

                        <th
                          class="text-end"
                          style="inline-size: 130px;"
                        >
                          {{ t('bdv.create.items.columns.amount') }}
                        </th>

                        <th style="min-inline-size: 200px;">
                          {{ t('bdv.create.items.columns.account') }}
                        </th>

                        <!--
                          Bukti milik tiap baris, sama seperti pada FPU dan
                          Claim. Nominal tanpa buktinya hanya angka yang
                          harus dipercaya begitu saja -- dan yang
                          menandatangani voucher inilah yang paling perlu
                          memeriksanya.
                        -->
                        <th style="inline-size: 170px;">
                          {{ t('bdv.detail.attachments') }}
                        </th>
                      </tr>
                    </thead>

                    <tbody>
                      <tr
                        v-for="item in kelompok.items"
                        :key="`${kelompok.public_id}-${item.no}`"
                      >
                        <td class="text-medium-emphasis">
                          {{ item.no }}
                        </td>

                        <td class="text-no-wrap">
                          {{ tanggal(item.date) }}
                        </td>

                        <td>{{ isiAtauStrip(item.description) }}</td>

                        <td>{{ isiAtauStrip(item.category) }}</td>

                        <td
                          v-if="adaKolomQty"
                          class="text-end text-no-wrap"
                        >
                          {{ item.qty === null ? strip : angka(item.qty) }}
                        </td>

                        <td
                          v-if="adaKolomQty"
                          class="text-end text-no-wrap"
                        >
                          {{ item.unit_price === null ? strip : rupiah(item.unit_price) }}
                        </td>

                        <td class="text-end text-no-wrap font-weight-medium">
                          {{ rupiah(item.amount) }}
                        </td>

                        <!--
                          Baris tanpa akun ditandai, bukan dibiarkan kosong.
                          Voucher yang sudah terbit semestinya tidak punya
                          baris seperti itu -- kalau toh muncul, ia harus
                          kelihatan.
                        -->
                        <td>
                          <span v-if="akunPerBaris[kunciItem(kelompok.public_id, item.no)]">
                            {{ akunPerBaris[kunciItem(kelompok.public_id, item.no)] }}
                          </span>

                          <VChip
                            v-else
                            size="x-small"
                            variant="tonal"
                            color="warning"
                          >
                            {{ t('bdv.detail.noAccount') }}
                          </VChip>
                        </td>

                        <td>
                          <div
                            v-if="(item.attachments || []).length"
                            class="d-flex flex-column gap-1"
                          >
                            <a
                              v-for="berkas in item.attachments"
                              :key="`lampiran-${kelompok.public_id}-${item.no}-${berkas.id}`"
                              class="bdv-detail__file"
                              :href="berkas.url || undefined"
                              target="_blank"
                              rel="noopener noreferrer"
                            >
                              <VIcon
                                :icon="ikonLampiran(berkas.mime_type)"
                                size="16"
                                color="primary"
                              />

                              <span
                                class="bdv-detail__file-name"
                                :title="berkas.original_filename || berkas.filename || ''"
                              >
                                {{ berkas.original_filename || berkas.filename }}
                              </span>
                            </a>
                          </div>

                          <span
                            v-else
                            class="text-disabled"
                          >{{ strip }}</span>
                        </td>
                      </tr>
                    </tbody>
                    <tfoot>
                      <tr class="bdv-item-foot">
                        <td
                          :colspan="adaKolomQty ? 6 : 4"
                          class="text-end text-medium-emphasis"
                        >
                          {{ kelompokItem.length > 1 ? t('bdv.create.items.subtotal') : t('bdv.create.items.total') }}
                        </td>

                        <td class="text-end text-no-wrap font-weight-bold">
                          {{ rupiah(jumlahKelompok(kelompok)) }}
                        </td>

                        <td colspan="2" />
                      </tr>

                      <!--
                        Jumlah seluruh baris dari SEMUA dokumen, sekali saja di kaki tabel
                        terakhir. Kaki di atasnya hanya menjumlahkan satu dokumen, dan yang
                        menandatangani voucher perlu angka totalnya -- pada kolom yang sama,
                        bukan melayang di luar tabel.
                      -->
                      <tr
                        v-if="kelompokItem.length > 1 && urutanKelompok === kelompokItem.length - 1"
                        class="bdv-item-foot bdv-item-foot--grand"
                      >
                        <td
                          :colspan="adaKolomQty ? 6 : 4"
                          class="text-end text-medium-emphasis"
                        >
                          {{ t('bdv.create.items.total') }}
                        </td>

                        <td class="text-end text-no-wrap font-weight-bold">
                          {{ rupiah(jumlahItemDetail) }}
                        </td>

                        <td colspan="2" />
                      </tr>
                    </tfoot>
                  </VTable>
                </div>
              </div>
            </template>

            <VAlert
              v-if="itemDetail.length > 0 && jumlahItemDetail !== jumlahDokumenDetail"
              type="warning"
              variant="tonal"
              density="compact"
              class="mt-3"
            >
              {{ t('bdv.create.items.mismatch', {
                items: rupiah(jumlahItemDetail),
                document: rupiah(jumlahDokumenDetail),
              }) }}
            </VAlert>

            <!-- ---------- RIWAYAT APPROVAL ---------- -->
            <div class="bdv-detail__section-title">
              <VIcon
                icon="tabler-timeline-event"
                size="18"
              />

              {{ t('bdv.detail.approvalTitle') }}
            </div>

            <!--
              Riwayat kosong punya DUA sebab, dan keduanya dikatakan
              terang-terangan. Bagian kosong tanpa keterangan terbaca
              sebagai kerusakan; keterangan yang keliru lebih buruk lagi.
            -->
            <VAlert
              v-if="rantaiDibongkarRevisi"
              type="warning"
              variant="tonal"
              density="compact"
            >
              {{ t('bdv.detail.approvalResetByRevision') }}
            </VAlert>

            <VAlert
              v-else-if="riwayatApproval.length === 0"
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('bdv.detail.approvalEmpty') }}
            </VAlert>

            <VTimeline
              v-if="rantaiDitampilkan.length"
              side="end"
              align="start"
              density="compact"
              truncate-line="both"
              class="bdv-detail__timeline"
              :class="{ 'mt-3': rantaiDibongkarRevisi }"
            >
              <VTimelineItem
                v-for="(langkah, urutan) in rantaiDitampilkan"
                :key="`approval-${urutan}`"
                size="x-small"
                :dot-color="approvalStatusColor(langkah.status)"
              >
                <div class="d-flex align-center flex-wrap gap-2">
                  <span class="font-weight-medium">
                    {{ isiAtauStrip(langkah.role) }}
                  </span>

                  <VChip
                    v-if="langkah.status"
                    size="x-small"
                    variant="tonal"
                    :color="approvalStatusColor(langkah.status)"
                  >
                    {{ labelStatus(langkah.status) }}
                  </VChip>
                </div>

                <div class="text-body-2 text-medium-emphasis">
                  {{ isiAtauStrip(langkah.approver) }}
                  <template v-if="langkah.acted_at">
                    · {{ tanggal(langkah.acted_at) }}
                  </template>
                </div>

                <div
                  v-if="langkah.notes"
                  class="bdv-detail__quote mt-1"
                  v-text="langkah.notes"
                />
              </VTimelineItem>
            </VTimeline>

            <!--
              Riwayat revisi, beserta tanda tangan tiap putaran.

              Komponen yang sama dengan FPU/Claim/Realisasi/Perdin. Di
              dalamnya ada panel "Persetujuan sebelumnya" yang membuka
              rantai approval putaran itu -- satu-satunya tempat tanda
              tangan lama masih tercatat sesudah rantainya dibongkar untuk
              putaran yang baru.

              openRevision sengaja TIDAK diteruskan: alasan revisi yang
              sedang berjalan sudah berdiri paling atas pada modal ini, dan
              komponen ini akan memunculkannya kedua kalinya.
            -->
            <div
              v-if="riwayatRevisi.length"
              class="mt-5"
            >
              <RevisionHistory :revisions="riwayatRevisi" />
            </div>
          </template>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-4">
          <VSpacer />

          <!--
            Tolak dan Setujui hanya muncul bila server bilang orang ini
            memang approver tahap yang sedang berjalan. Selama alur approval
            BDV belum dibangun, keduanya tidak pernah muncul.

            Tolak ditaruh di kiri Setujui, dan diberi warna merah: dua tombol
            berdampingan yang sama bentuknya adalah cara tercepat menyetujui
            sesuatu yang sebenarnya hendak ditolak.
          -->
          <!--
            Ketiganya memanggil PEMBUNGKUS tanpa argumen, bukan setujui()
            langsung -- lihat keterangan pada setujuiDetail di atas.
          -->
          <VBtn
            v-if="detail?.can_return_for_revision"
            color="warning"
            variant="tonal"
            prepend-icon="mdi-undo-variant"
            class="text-none"
            :disabled="memproses"
            @click="kembalikanDetail"
          >
            {{ t('common.revision.menuReturn') }}
          </VBtn>

          <VBtn
            v-if="detail?.can_reject"
            color="error"
            variant="tonal"
            prepend-icon="tabler-x"
            class="text-none"
            :disabled="memproses"
            @click="tolakDetail"
          >
            {{ t('bdv.detail.reject.button') }}
          </VBtn>

          <VBtn
            v-if="detail?.can_approve"
            color="success"
            prepend-icon="tabler-check"
            class="text-none"
            :disabled="memproses"
            @click="setujuiDetail"
          >
            {{ t('bdv.detail.approve.button') }}
          </VBtn>

          <VBtn
            v-if="detail?.can_print"
            color="primary"
            variant="tonal"
            prepend-icon="tabler-printer"
            class="text-none"
            :disabled="sedangMencetak"
            @click="cetakDariDetail"
          >
            {{ t('bdv.list.menu.print') }}
          </VBtn>

          <!--
            Ubah ditawarkan dari dalam modal juga. Boleh tidaknya dijawab
            server lewat can_update -- ia sudah memperhitungkan izin, cakupan,
            dan status sekaligus.
          -->
          <VBtn
            v-if="detail?.can_update"
            color="primary"
            variant="tonal"
            prepend-icon="tabler-pencil"
            class="text-none"
            @click="ubahDariDetail"
          >
            {{ t('bdv.list.menu.edit') }}
          </VBtn>

          <VBtn
            color="secondary"
            variant="tonal"
            class="text-none"
            @click="detailDialog = false"
          >
            {{ t('common.actions.close') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      CETAK: bahasa, lampiran, dan dokumen sumber.

      Selalu dibuka, tidak pernah dilewati. Bahasa memang tidak punya
      jawaban bawaan yang benar untuk semua orang, dan dua pilihan lainnya
      mengubah isi berkasnya secara mendasar -- bukan sekadar rupanya.
    -->
    <VDialog
      v-model="dialogCetak"
      max-width="480"
    >
      <VCard v-if="sasaranCetak">
        <VCardTitle class="text-h6 font-weight-bold">
          {{ t('bdv.list.print.dialogTitle', {
            number: sasaranCetak.bdv_number || t('bdv.list.notNumbered'),
          }) }}
        </VCardTitle>

        <VDivider />

        <VCardText>
          <VSelect
            v-model="bahasaCetak"
            :items="pilihanBahasaCetak"
            item-title="title"
            item-value="value"
            :label="t('bdv.list.print.langLabel')"
            prepend-inner-icon="tabler-language"
            density="comfortable"
            hide-details
            class="mb-4"
          />

          <VCheckbox
            v-model="cetakDenganSumber"
            :label="t('bdv.list.print.withSources')"
            density="comfortable"
            hide-details
          />

          <VCheckbox
            v-model="cetakDenganLampiran"
            :label="t('bdv.list.print.withAttachments')"
            density="comfortable"
            hide-details
          />

          <!--
            Dua keterangan yang disebutkan DI MUKA, bukan disembunyikan
            sampai berkasnya jadi: orang yang mengira lampiran PDF-nya ikut
            tersisip akan berhenti memeriksa.
          -->
          <VAlert
            v-if="cetakDenganLampiran"
            type="info"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ cetakDenganSumber
              ? t('bdv.list.print.attachmentOnSources')
              : t('bdv.list.print.attachmentNotice') }}
          </VAlert>

          <VAlert
            v-if="bahasaCetak === 'en' && cetakDenganSumber"
            type="warning"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ t('bdv.list.print.sourceLanguageNotice') }}
          </VAlert>
        </VCardText>

        <VDivider />

        <VCardActions class="justify-end">
          <VBtn
            variant="tonal"
            color="secondary"
            class="text-none"
            @click="dialogCetak = false"
          >
            {{ t('common.actions.cancel') }}
          </VBtn>

          <VBtn
            color="primary"
            class="text-none"
            prepend-icon="tabler-printer"
            :loading="sedangMencetak"
            @click="jalankanCetak"
          >
            {{ t('bdv.list.print.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!--
      Dialog keputusan: satu untuk Setujui, Tolak, dan Kembalikan.
      Bentuknya milik bersama -- lihat components/approval/DecisionDialog.vue.
    -->
    <DecisionDialog
      v-model="dialogKeputusan"
      :title="konfigKeputusan.title"
      :subtitle="konfigKeputusan.subtitle"
      :notes-label="konfigKeputusan.notesLabel"
      :notes-placeholder="konfigKeputusan.notesPlaceholder"
      :confirm-text="konfigKeputusan.confirmText"
      :confirm-color="konfigKeputusan.confirmColor"
      :min-length="konfigKeputusan.minLength"
      :required-message="konfigKeputusan.requiredMessage"
      :loading="memproses"
      @confirm="jalankanKeputusan"
    />
  </div>
</template>

<style lang="scss" scoped>
/*
| Kaki tabel dibedakan dari barisnya: latar tipis, dan garis atas yang lebih
| tegas pada baris jumlah seluruhnya.
|
| Tanpa itu ia terbaca sebagai baris rincian berikutnya, bukan sebagai
| jumlah -- justru keliru pada tabel yang barisnya banyak, yang memang
| paling membutuhkan angka jumlahnya.
*/
:deep(.bdv-item-foot td) {
  background: rgba(var(--v-theme-on-surface), 4%);
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  font-size: 0.85rem;
}

:deep(.bdv-item-foot--grand td) {
  border-block-start-width: 2px;
  font-size: 0.95rem;
}

/* Nomor BDV yang membuka menu -- penunjuknya harus memberi tahu bahwa ia bisa diklik. */
.bdv-number-action {
  cursor: pointer;
}

/*
| Baris yang menunggu keputusan orang ini.
|
| Kadar warnanya 6% -- angka yang sama dengan FPU, dan serendah itu dengan
| sengaja: deretan baris berwarna yang mencolok berhenti berarti "yang ini
| perlu Anda" dan mulai berarti "tabel ini memang begitu rupanya". Dirangkai
| dari token tema, jadi mode gelap tidak perlu aturan tandingan sendiri.
*/
.bdv-row-need-approval {
  background: rgba(var(--v-theme-warning), 6%);
}

/*
| Kotak sakelar "hanya yang menunggu approval saya".
|
| Diberi garis tepi supaya tingginya sejajar dengan isian di sebelahnya;
| tanpa itu sakelarnya melayang di tengah baris yang tingginya ditentukan
| kotak lain.
*/
.bdv-approval-filter {
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

/*
| Modal detail
|
| Seluruh warnanya dirangkai dari token tema (--v-theme-*), bukan dari nilai
| hex: dengan begitu mode gelap tidak perlu aturan tandingan sendiri, dan
| tidak ada satu pun bagian yang tertinggal terang saat temanya berganti.
*/
.bdv-detail__header {
  padding-block: 1.25rem;
  padding-inline: 1.5rem;
}

.bdv-detail__header--success {
  background: linear-gradient(135deg, rgba(var(--v-theme-success), 14%), transparent 70%);
}

.bdv-detail__header--warning {
  background: linear-gradient(135deg, rgba(var(--v-theme-warning), 14%), transparent 70%);
}

.bdv-detail__header--error {
  background: linear-gradient(135deg, rgba(var(--v-theme-error), 14%), transparent 70%);
}

.bdv-detail__header--info {
  background: linear-gradient(135deg, rgba(var(--v-theme-info), 14%), transparent 70%);
}

.bdv-detail__header--secondary {
  background: linear-gradient(135deg, rgba(var(--v-theme-secondary), 12%), transparent 70%);
}

.bdv-detail__eyebrow {
  display: inline-flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 60%);
  font-size: 0.75rem;
  font-weight: 600;
  gap: 0.375rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.bdv-detail__number {
  font-size: 1.375rem;
  font-weight: 700;
  line-height: 1.25;
  overflow-wrap: anywhere;
}

.bdv-detail__amount {
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  box-shadow: 0 2px 8px rgba(var(--v-theme-on-surface), 8%);
  padding-block: 0.5rem;
  padding-inline: 1rem;
  text-align: end;
}

.bdv-detail__amount-label {
  color: rgba(var(--v-theme-on-surface), 60%);
  font-size: 0.7rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.bdv-detail__amount-value {
  color: rgb(var(--v-theme-primary));
  font-size: 1.25rem;
  font-weight: 700;
  line-height: 1.3;
  white-space: nowrap;
}

.bdv-detail__body {
  padding-block: 1.25rem 1.5rem;
}

/*
| Kisi yang menyesuaikan diri: kolomnya sebanyak yang muat, tanpa titik
| putus yang ditentukan sendiri. Di layar sempit ia jatuh menjadi satu kolom
| tanpa perlu aturan tambahan.
*/
.bdv-detail__grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(13.5rem, 1fr));
}

.bdv-detail__field {
  display: flex;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  gap: 0.625rem;
  padding-block: 0.625rem;
  padding-inline: 0.75rem;
}

.bdv-detail__field .v-icon {
  color: rgba(var(--v-theme-primary), 75%);
  margin-block-start: 0.125rem;
}

.bdv-detail__field-body {
  min-inline-size: 0;
}

.bdv-detail__label {
  color: rgba(var(--v-theme-on-surface), 60%);
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.bdv-detail__value {
  font-size: 0.9rem;
  font-weight: 500;
  overflow-wrap: anywhere;
}

/*
| Sel ringkasan di dalam accordion: label-nilai tanpa kotak bertepi.
|
| Kotak bertepi dipakai pada Data Voucher, yang berdiri sendiri. Di dalam
| panel accordion -- yang sudah bertepi -- tepi kedua membuat isinya terbaca
| seperti dua lapis kartu yang tidak ada gunanya.
*/
.bdv-detail__cell {
  min-inline-size: 0;
}

/*
| Panel dokumen dibuat rata dengan isi modal yang lain: tanpa bayangan, hanya
| bertepi, sudutnya sama dengan tabel rinciannya.
*/
.bdv-detail__panel {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  overflow: hidden;
}

.bdv-detail__panel :deep(.v-expansion-panel) {
  background: transparent;
}

.bdv-detail__panel :deep(.v-expansion-panel::after) {
  border-color: rgba(var(--v-border-color), var(--v-border-opacity));
}

.bdv-detail__note {
  border-radius: 10px;
  background: rgba(var(--v-theme-on-surface), 4%);
  padding-block: 0.75rem;
  padding-inline: 1rem;
}

.bdv-detail__note-text {
  font-size: 0.9rem;
  overflow-wrap: anywhere;

  /* Ganti baris yang diketik orangnya dipertahankan -- lihat keterangan di markup. */
  white-space: pre-line;
}

.bdv-detail__section-title {
  display: flex;
  align-items: center;
  color: rgba(var(--v-theme-on-surface), 85%);
  font-size: 0.95rem;
  font-weight: 700;
  gap: 0.5rem;
  margin-block: 1.5rem 0.75rem;
}

.bdv-detail__section-title .v-icon {
  color: rgba(var(--v-theme-primary), 80%);
}

.bdv-detail__table {
  overflow-x: auto;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
}

.bdv-detail__table :deep(thead th) {
  background: rgba(var(--v-theme-on-surface), 4%);
  font-size: 0.7rem !important;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

/*
| Garis selang-seling. Tabel rincian bisa panjang, dan mata yang menelusuri
| satu baris ke kanan mudah tergelincir ke baris di bawahnya -- terutama
| pada kolom nominal yang semuanya berbentuk serupa.
*/
.bdv-detail__table :deep(tbody tr:nth-child(even)) {
  background: rgba(var(--v-theme-on-surface), 2%);
}

/* Bukti yang menempel pada satu baris rincian. */
.bdv-detail__file {
  display: flex;
  align-items: center;
  color: inherit;
  gap: 0.375rem;
  min-inline-size: 0;
  text-decoration: none;
}

.bdv-detail__file:hover .bdv-detail__file-name {
  text-decoration: underline;
}

/*
| Nama berkas dipotong, bukan dibiarkan melebar. Nama hasil unggahan bisa
| sangat panjang, dan satu nama saja cukup untuk mendorong kolom nominal
| keluar dari layar. Nama penuhnya tetap terbaca lewat title.
*/
.bdv-detail__file-name {
  overflow: hidden;
  font-size: 0.8rem;
  max-inline-size: 9.5rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bdv-detail__timeline {
  justify-content: start;
  padding-block-start: 0.25rem;
}

/* Catatan approver, dibedakan dari teks biasa lewat garis tepi. */
.bdv-detail__quote {
  border-inline-start: 3px solid rgba(var(--v-theme-primary), 35%);
  background: rgba(var(--v-theme-on-surface), 4%);
  font-size: 0.85rem;
  padding-block: 0.375rem;
  padding-inline: 0.625rem;
  white-space: pre-line;
}
</style>
