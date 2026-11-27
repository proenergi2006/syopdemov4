<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'

import axios from '@axios'
import {
  closeAlert,
  queueToast,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showWarningToast,
} from '@/utils/alert'
import { getApiErrorMessage } from '@/utils/apiHelper'
import { usePermissionStore } from '@/stores/permission'
import { formatAngka, formatUang } from '@/utils/numberFormatter'

/*
|--------------------------------------------------------------------------
| Formulir BDV -- ubah
|--------------------------------------------------------------------------
| Kembaran halaman buat, dan sejak satu BDV bisa mencairkan banyak dokumen,
| DAFTAR DOKUMENNYA PUN BISA DIUBAH DI SINI: ditambah, dikeluarkan, bahkan
| diganti jenisnya dari FPU ke Claim selama seluruhnya tetap sejenis,
| secabang, dan sedepartment.
|
| Yang menegakkan aturan itu tetap server. Penyaring di layar -- department
| dan cabang dipilih lebih dulu, kolom jenis terkunci begitu dokumen pertama
| dipilih -- ada supaya penolakannya datang saat dokumennya dipilih, bukan
| setelah seluruh formulir terisi. Penyaring layar bukan penjaga: siapa pun
| yang memanggil endpoint langsung tidak melewatinya.
|
| Hanya draft yang boleh diubah, dan itu dijawab server lewat can_update.
| Begitu diajukan, isinya sudah menjadi dasar orang lain menyetujui.
|
| Kunci terjemahan untuk rincian dokumen, kolom voucher, dan tabel item
| sengaja memakai milik halaman buat. Isinya memang kalimat yang sama persis,
| dan menggandakannya hanya melahirkan dua tempat yang harus diubah
| berbarengan setiap kali salah satu katanya diperbaiki.
|--------------------------------------------------------------------------
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
  qty: number | null
  unit_price: number | null
  amount: number
  category: string | null

  /*
   * Bukti milik baris ini, diangkut dari dokumen sumbernya. Ditampilkan
   * di sini, bukan hanya di halaman detail: akun Accurate tiap baris
   * dipilih di layar INI, dan yang memilihnya perlu melihat buktinya untuk
   * tahu pengeluaran apa yang sedang dibebankan.
   */
  attachments: Lampiran[]
}

interface DokumenRincian {
  public_id: string
  document_type: string
  document_type_label: string
  number: string
  date: string | null
  subject: string | null
  status: string | null

  /*
   * Id cabang dan department, di samping namanya.
   *
   * Namanya untuk dibaca, id-nya untuk MEMULIHKAN pilihan di tahap 1: kedua
   * kolom itu bernilai id, dan mengisinya dengan nama hanya menghasilkan
   * kolom yang tampak kosong padahal vouchernya punya cabang.
   */
  branch_id: string | null
  branch: string | null

  department_id: number | null
  department: string | null

  transaction_category: string | null
  total_amount: number
  notes: string | null
  requester: string | null
  received_at: string | null
  scheduled_payment_date: string | null
  items: DokumenItem[]
}

/** BDV yang sedang diubah, sebagaimana dikirim server. */
interface BdvDetail {
  public_id: string
  bdv_number: string | null
  date: string | null
  branch: string | null
  department: string | null
  transaction_category: string | null
  total_amount: number
  notes: string | null
  status: string | null
  requester: string | null
  can_update: boolean

  /* Isian voucher yang harus dipulihkan ke formulir. */
  cash_bank_account_no: string | null
  cash_bank_account_name: string | null
  check_number: string | null
  payee: string | null

  /*
   * SELURUH dokumen sumbernya, masing-masing lengkap dengan rincian hidup
   * dari dokumen aslinya dan pilihan akun milik voucher ini.
   *
   * Dikirim bersama voucher dalam satu jawaban, bukan diambil terpisah lewat
   * document-detail: pintu itu menyaring dokumen yang "layak diangkat",
   * sedangkan dokumen milik BDV yang sudah terbit justru TIDAK lagi layak --
   * karena sudah diangkat voucher ini. Akibatnya rinciannya selalu kosong.
   */
  sources: SumberBdv[]
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

interface KasBank {
  id: string
  text: string
}

interface AkunAccurate {
  id: string
  text: string
  noWithIndent?: string
}

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const permissionStore = usePermissionStore()

const ENDPOINT = '/cash-bank/bdv'

const canUpdate = computed(() => permissionStore.can('bdv.update'))

/* Mengikuti modul pengajuan dana: id dibawa lewat query, bukan segmen path. */
const publicId = ref<string>(String(route.query.id || ''))

/*
|--------------------------------------------------------------------------
| Dokumen yang sedang diubah
|--------------------------------------------------------------------------
*/
const bdv = ref<BdvDetail | null>(null)
const memuat = ref(false)
const gagalMuat = ref(false)

/*
| Terkunci bila server bilang begitu. Dipisahkan dari canUpdate karena
| sebabnya berbeda: yang satu soal izin, yang satu soal status dokumennya.
| Keduanya sama-sama membuat tombol simpan tidak berlaku, tetapi kalimat
| yang pantas ditampilkan tidak sama.
*/
const terkunci = computed<boolean>(() => bdv.value !== null && !bdv.value.can_update)

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

/* Status yang belum punya terjemahan ditampilkan apa adanya, bukan sebagai kunci. */
const labelStatus = (status: string): string => {
  const kunci = `bdv.status.${status}`
  const hasil = t(kunci)

  return hasil === kunci ? status : hasil
}

/*
| Terkunci karena dua sebab yang berbeda, dan keduanya harus dibedakan.
|
| Yang pertama status: dokumennya sudah lewat draft. Yang kedua cakupan:
| dokumennya masih draft, tetapi izin ubah orang ini bercakupan OWN_DATA,
| OWN_CABANG, atau OWN_DEPARTMENT, dan dokumen ini di luar cakupan itu.
|
| Menyebut keduanya dengan kalimat yang sama -- "hanya draft yang bisa
| diubah" pada dokumen yang jelas-jelas berstatus draft -- membuat orangnya
| mengira ada yang rusak, lalu mencoba berkali-kali.
*/
const alasanTerkunci = computed<string>(() => {
  const status = String(bdv.value?.status ?? '').trim().toUpperCase()

  if (status === 'DRAFT')
    return t('bdv.edit.locked.textScope')

  return t('bdv.edit.locked.text', { status: labelStatus(bdv.value?.status ?? '-') })
})

/*
|--------------------------------------------------------------------------
| Kas / Bank dari Accurate
|--------------------------------------------------------------------------
*/
const kodeJenisKasBank = ref<string>('')
const kasBankList = ref<KasBank[]>([])
const kasBankLoading = ref(false)
const kasBankGagal = ref(false)
const kataCariKasBank = ref('')

let nomorPermintaanKasBank = 0

/* Jeda pencarian nomor dokumen -- dipakai muatDokumen() jauh di bawah. */
let jedaCari: ReturnType<typeof setTimeout> | null = null

let jedaCariKasBank: ReturnType<typeof setTimeout> | null = null

const muatKasBank = async (kataKunci = ''): Promise<void> => {
  const nomorPermintaan = ++nomorPermintaanKasBank

  kataCariKasBank.value = kataKunci
  kasBankLoading.value = true
  kasBankGagal.value = false

  try {
    const { data } = await axios.get('/accurate/accounts', {
      headers: { Accept: 'application/json' },
      params: {
        q: kataKunci || undefined,
        page: 1,
        account_type: kodeJenisKasBank.value || undefined,
      },
    })

    if (nomorPermintaan !== nomorPermintaanKasBank)
      return

    /*
    | Accurate tidak selalu gagal lewat HTTP error: ia bisa menjawab 200
    | dengan success:false, misalnya saat kuota per detik penuh. Tanpa
    | pemeriksaan ini kolomnya hanya tampak kosong seolah tidak ada akun.
    */
    if (data?.success === false)
      throw new Error(t('bdv.create.voucher.cashBankLoadFailed'))

    kasBankList.value = Array.isArray(data?.data) ? data.data : []
  }
  catch (error: unknown) {
    if (nomorPermintaan !== nomorPermintaanKasBank)
      return

    kasBankList.value = []
    kasBankGagal.value = true

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.create.voucher.cashBankLoadFailed')),
    })
  }
  finally {
    if (nomorPermintaan === nomorPermintaanKasBank)
      kasBankLoading.value = false
  }
}

const muatUlangKasBank = (): void => {
  muatKasBank(kataCariKasBank.value)
}

const cariKasBank = (kataKunci: string): void => {
  if (jedaCariKasBank)
    clearTimeout(jedaCariKasBank)

  jedaCariKasBank = setTimeout(() => muatKasBank(kataKunci), 400)
}

/*
|--------------------------------------------------------------------------
| Akun Accurate per item
|--------------------------------------------------------------------------
| Tidak menyaring jenis akun, berbeda dari kolom Kas/Bank: biaya pada
| rincian bisa jatuh ke akun mana pun di bagan akun.
|--------------------------------------------------------------------------
*/
const akunList = ref<AkunAccurate[]>([])

/*
| Dikunci "<public_id dokumen>:<nomor baris>", bukan nomor baris saja.
|
| Nomor baris berulang antar dokumen -- FPU A punya baris 1, Claim B juga.
| Kunci bernomor saja akan membuat pilihan akun baris 1 dokumen kedua
| menimpa baris 1 dokumen pertama, tanpa satu pun tanda di layar.
*/
const akunPerItem = ref<Record<string, AkunAccurate | null>>({})

const kunciItem = (idDokumen: string, nomorBaris: number): string =>
  `${idDokumen}:${nomorBaris}`

const akunLoading = ref(false)
const akunGagal = ref(false)
const kataCariAkun = ref('')

let nomorPermintaanAkun = 0
let jedaCariAkun: ReturnType<typeof setTimeout> | null = null

const muatAkun = async (kataKunci = ''): Promise<void> => {
  const nomorPermintaan = ++nomorPermintaanAkun

  kataCariAkun.value = kataKunci
  akunLoading.value = true
  akunGagal.value = false

  try {
    const { data } = await axios.get('/accurate/accounts', {
      headers: { Accept: 'application/json' },
      params: {
        q: kataKunci || undefined,
        page: 1,
        exclude_account_type: kodeJenisKasBank.value || undefined,
      },
    })

    if (nomorPermintaan !== nomorPermintaanAkun)
      return

    if (data?.success === false)
      throw new Error(t('bdv.create.items.accountLoadFailed'))

    akunList.value = Array.isArray(data?.data) ? data.data : []
  }
  catch (error: unknown) {
    if (nomorPermintaan !== nomorPermintaanAkun)
      return

    akunList.value = []
    akunGagal.value = true

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.create.items.accountLoadFailed')),
    })
  }
  finally {
    if (nomorPermintaan === nomorPermintaanAkun)
      akunLoading.value = false
  }
}

const muatUlangAkun = (): void => {
  muatAkun(kataCariAkun.value)
}

const cariAkun = (kataKunci: string): void => {
  if (jedaCariAkun)
    clearTimeout(jedaCariAkun)

  jedaCariAkun = setTimeout(() => muatAkun(kataKunci), 400)
}

onBeforeUnmount(() => {
  if (jedaCari)
    clearTimeout(jedaCari)

  if (jedaCariKasBank)
    clearTimeout(jedaCariKasBank)

  if (jedaCariAkun)
    clearTimeout(jedaCariAkun)
})

/*
|--------------------------------------------------------------------------
| Data voucher
|--------------------------------------------------------------------------
*/
const form = reactive({
  kasBank: null as KasBank | null,
  bdvNumber: '',
  date: null as string | null,
  checkNumber: '',
  payee: '',
  notes: '',
})

const labelWajib = (kunci: string): string => `${t(kunci)} *`

const wajibIsi = (nilai: unknown): true | string =>
  (nilai !== null && nilai !== undefined && String(nilai).trim() !== '')
  || t('bdv.create.voucher.required')

/*
|--------------------------------------------------------------------------
| Dokumen sumber -- bisa diganti selama vouchernya masih draft
|--------------------------------------------------------------------------
| Bentuknya sama persis dengan halaman buat, dan itu disengaja: aturan yang
| sama ditegakkan dengan cara yang sama, sehingga satu perbaikan tidak perlu
| dikerjakan dua kali dengan dua cara berbeda.
|
| Bedanya satu: keadaan awalnya BUKAN kosong, melainkan salinan daftar
| dokumen yang sudah tersimpan -- lihat pulihkanSumberDari().
|--------------------------------------------------------------------------
*/
interface PilihanDokumen {
  code: string
  label: string
}

interface PilihanCabang {
  value: string | number
  title: string
}

interface DokumenRingkas {
  public_id: string
  number: string
  date: string | null
  subject: string | null
  total_amount: number
}

const documentTypes = ref<PilihanDokumen[]>([])
const daftarCabang = ref<PilihanCabang[]>([])
const daftarDepartment = ref<PilihanCabang[]>([])

const departmentDipilih = ref<number | null>(null)
const cabangDipilih = ref<string | null>(null)
const jenisTerpilih = ref<string | null>(null)

const documentTypeOptions = computed(() =>
  documentTypes.value.map(d => ({ value: d.code, title: d.label })))

/*
| Penanda bahwa yang sedang terjadi adalah PEMULIHAN, bukan orang mengganti
| pilihannya.
|
| Tanpa ini, mengisi departmentDipilih/cabangDipilih/jenisTerpilih dari
| voucher yang tersimpan akan memicu pengawasnya, dan pengawas itu memang
| bertugas membuang seluruh dokumen yang terpilih. Halaman ubah akan terbuka
| dengan daftar dokumen yang kosong -- padahal vouchernya punya isi.
*/
const memulihkan = ref(false)

const dokumen = ref<DokumenRingkas[]>([])
const dokumenLoading = ref(false)

/*
| Dokumennya disimpan UTUH, bukan sekadar public_id-nya -- alasannya sama
| dengan halaman buat: daftar pilihan berganti isi tiap kali pencarian
| dijalankan, dan kolom yang hanya memegang id akan menampilkan id mentahnya
| begitu dokumen terpilih tidak lagi ada di daftar yang sedang ditampilkan.
*/
const dokumenDipilih = ref<DokumenRingkas[]>([])
const kataCari = ref('')

const batasTampil = computed(() => dokumen.value.length)

/*
| Daftar pilihan tanpa dokumen yang sudah dipilih.
|
| DIBANDINGKAN MENURUT NOMOR, BUKAN public_id: public_id adalah hasil
| enkripsi ber-IV acak, jadi dokumen yang sama menghasilkan ciphertext yang
| berbeda tiap kali daftarnya dimuat. Membandingkan public_id tidak pernah
| cocok, dan dokumen yang sudah dipilih tetap muncul lalu bisa dipilih untuk
| kedua kalinya.
*/
const dokumenTersisa = computed<DokumenRingkas[]>(() =>
  dokumen.value.filter(d => !dokumenDipilih.value.some(dipilih => dipilih.number === d.number)))

/*
| Rincian tiap dokumen dan jenisnya, dikunci menurut public_id.
|
| Peta, bukan daftar sejajar: dokumen bisa ditambah dan dikeluarkan dalam
| urutan apa pun, dan daftar sejajar menuntut dua benda tetap sinkron
| panjangnya -- yang cepat atau lambat meleset.
*/
const rincianPerDokumen = ref<Record<string, DokumenRincian>>({})
const rincianLoading = ref(false)
const jenisPerDokumen = ref<Record<string, string>>({})

/*
| Nomor urut permintaan: mengetik cepat melahirkan beberapa pencarian
| sekaligus, dan yang berangkat lebih dulu tidak selalu tiba lebih dulu.
*/
let nomorPermintaanDokumen = 0

const muatDokumen = async (): Promise<void> => {
  if (!jenisTerpilih.value || !cabangDipilih.value || !departmentDipilih.value) {
    dokumen.value = []

    return
  }

  const nomorPermintaan = ++nomorPermintaanDokumen

  dokumenLoading.value = true

  try {
    const { data } = await axios.get(`${ENDPOINT}/available-documents`, {
      headers: { Accept: 'application/json' },
      params: {
        document_type: jenisTerpilih.value,
        branch: cabangDipilih.value || undefined,
        department_id: departmentDipilih.value || undefined,
        search: kataCari.value || undefined,

        /*
        | Voucher yang sedang diubah ikut disebut.
        |
        | Tanpa itu, dokumen MILIK VOUCHER INI tidak muncul di daftarnya
        | sendiri: saringan "layak diangkat" membuang dokumen yang sudah
        | diangkat BDV mana pun, termasuk yang ini. Orang yang mengeluarkan
        | sebuah dokumen lalu berubah pikiran tidak akan menemukannya lagi.
        */
        bdv: publicId.value || undefined,
      },
    })

    if (nomorPermintaan !== nomorPermintaanDokumen)
      return

    dokumen.value = Array.isArray(data?.data) ? data.data : []
  }
  catch (error: unknown) {
    if (nomorPermintaan !== nomorPermintaanDokumen)
      return

    dokumen.value = []

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.create.step2.loadFailed')),
    })
  }
  finally {
    if (nomorPermintaan === nomorPermintaanDokumen)
      dokumenLoading.value = false
  }
}

watch(kataCari, nilai => {
  /*
  | Memilih sebuah nomor membuat kolom pencarian terisi nomor itu sendiri.
  | Itu bukan pencarian baru, dan menjalankannya hanya mengganti isi daftar
  | tepat ketika pilihannya baru saja ditetapkan.
  */
  if (dokumenDipilih.value.some(d => d.number === nilai))
    return

  if (jedaCari)
    clearTimeout(jedaCari)

  jedaCari = setTimeout(muatDokumen, 400)
})

const muatRincianSatu = async (dok: DokumenRingkas, jenis: string): Promise<boolean> => {
  rincianLoading.value = true

  try {
    const { data } = await axios.get(`${ENDPOINT}/document-detail`, {
      headers: { Accept: 'application/json' },
      params: {
        document_type: jenis,
        public_id: dok.public_id,

        /* Sama seperti available-documents -- lihat keterangan di sana. */
        bdv: publicId.value || undefined,
      },
    })

    if (!data?.data)
      throw new Error('kosong')

    rincianPerDokumen.value = { ...rincianPerDokumen.value, [dok.public_id]: data.data }
    jenisPerDokumen.value = { ...jenisPerDokumen.value, [dok.public_id]: jenis }

    return true
  }
  catch (error: unknown) {
    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.create.step3.loadFailed')),
    })

    /* Dokumennya mungkin baru saja diangkat orang lain -- daftar disegarkan. */
    await muatDokumen()

    return false
  }
  finally {
    rincianLoading.value = false
  }
}

/*
| Jenis dokumen yang sudah terpakai voucher ini.
|
| Satu BDV hanya boleh mencairkan SATU jenis: FPU saja, atau Claim saja.
| Keduanya berbeda sifatnya -- FPU uang yang diminta di muka, Claim
| penggantian uang yang sudah lebih dulu dikeluarkan orang -- dan cetakan
| yang memuat keduanya tidak bisa menyebut dirinya voucher apa.
|
| null berarti tidak ada dokumen terpilih, sehingga jenisnya kembali bebas.
| Itulah cara mengganti FPU menjadi Claim: keluarkan dulu semuanya.
*/
const jenisDipakai = computed<string | null>(() => {
  const pertama = dokumenDipilih.value[0]

  return pertama ? (jenisPerDokumen.value[pertama.public_id] ?? null) : null
})

const cabangTerpilih = computed<string | null>(() => {
  const pertama = dokumenDipilih.value[0]

  if (!pertama)
    return null

  return rincianPerDokumen.value[pertama.public_id]?.branch ?? null
})

const tambahDokumen = async (dok: DokumenRingkas | null): Promise<void> => {
  if (!dok || !jenisTerpilih.value || terkunci.value)
    return

  /* Menurut nomor, dengan alasan yang sama seperti dokumenTersisa. */
  if (dokumenDipilih.value.some(d => d.number === dok.number))
    return

  const berhasil = await muatRincianSatu(dok, jenisTerpilih.value)

  if (!berhasil)
    return

  /*
  | Jenis dan cabang ikut dijaga di sini, bukan hanya dipercayakan pada
  | terkuncinya kolom di atas. Penjaga yang hanya berupa kolom mati adalah
  | penjaga yang hilang begitu susunan layarnya berubah.
  */
  if (jenisDipakai.value !== null && jenisTerpilih.value !== jenisDipakai.value) {
    delete rincianPerDokumen.value[dok.public_id]

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('bdv.create.step2.mixedType'),
    })

    return
  }

  const cabangBaru = rincianPerDokumen.value[dok.public_id]?.branch ?? null

  if (cabangTerpilih.value !== null && cabangBaru !== cabangTerpilih.value) {
    delete rincianPerDokumen.value[dok.public_id]

    showWarningToast({
      title: t('common.alert.warning'),
      text: t('bdv.create.step3.mixedBranch', { branch: cabangTerpilih.value ?? '-' }),
    })

    return
  }

  dokumenDipilih.value = [...dokumenDipilih.value, dok]
}

const keluarkanDokumen = (publicIdDokumen: string): void => {
  if (terkunci.value)
    return

  dokumenDipilih.value = dokumenDipilih.value.filter(d => d.public_id !== publicIdDokumen)

  const sisa = { ...rincianPerDokumen.value }

  delete sisa[publicIdDokumen]

  rincianPerDokumen.value = sisa

  /* Pilihan akun milik dokumen itu ikut dibuang, bukan ditinggalkan yatim. */
  const akun = { ...akunPerItem.value }

  for (const kunci of Object.keys(akun)) {
    if (kunci.startsWith(`${publicIdDokumen}:`))
      delete akun[kunci]
  }

  akunPerItem.value = akun
}

/*
| Berganti jenis TIDAK membuang yang sudah dipilih -- kolomnya memang
| terkunci selama masih ada dokumen terpilih, jadi keadaan itu tidak bisa
| terjadi dari layar. Yang dibersihkan hanya kolom pencarinya.
*/
watch(jenisTerpilih, async () => {
  if (memulihkan.value)
    return

  kataCari.value = ''

  await muatDokumen()
})

/*
| Berganti department atau cabang MEMBUANG dokumen yang sudah dipilih, dan
| itu disengaja: yang tertinggal dari cabang sebelumnya akan ditolak server,
| tetapi penolakannya baru datang setelah seluruh formulir terisi.
| Membuangnya sekarang -- saat orangnya memang sedang mengganti cabang --
| jauh lebih mudah dimengerti daripada penolakan di akhir.
*/
const bersihkanPilihan = async (): Promise<void> => {
  if (memulihkan.value)
    return

  dokumenDipilih.value = []
  rincianPerDokumen.value = {}
  jenisPerDokumen.value = {}
  akunPerItem.value = {}
  kataCari.value = ''

  await muatDokumen()
}

watch(departmentDipilih, bersihkanPilihan)
watch(cabangDipilih, bersihkanPilihan)

/*
| Daftar dokumen voucher ini, disusun dari pilihan di atas.
|
| Bentuknya sengaja dibuat sama dengan yang dikirim server, supaya accordion
| dan tabel rincian di bawah tidak perlu tahu bahwa isinya kini bisa berubah.
*/
const daftarSumber = computed<SumberBdv[]>(() =>
  dokumenDipilih.value.map(dok => {
    const rincian = rincianPerDokumen.value[dok.public_id] ?? null
    const jenis = jenisPerDokumen.value[dok.public_id] ?? ''

    return {
      public_id: dok.public_id,
      document_type: jenis,
      document_type_label: rincian?.document_type_label ?? jenis,
      source_number: dok.number,
      total_amount: Number(dok.total_amount ?? 0),
      detail: rincian,

      /*
      | Kosong dengan sengaja: pilihan akun sekarang hidup di akunPerItem,
      | yang bisa berubah. Kunci ini hanya ada supaya bentuknya tetap sama
      | dengan jawaban server.
      */
      items: [],
    }
  }))

const tahapAktif = computed<number>(() => {
  if (!departmentDipilih.value || !cabangDipilih.value || !jenisTerpilih.value)
    return 1

  if (dokumenDipilih.value.length === 0)
    return 2

  return 3
})

/*
| Dokumen yang rinciannya masih terbaca. Yang tidak -- dokumennya terhapus,
| atau jenisnya sudah tidak dikenal -- tetap tampil pada daftar di atas,
| tetapi tidak punya baris rincian untuk ditawarkan akun.
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

const muatOpsi = async (): Promise<void> => {
  try {
    const { data } = await axios.get(`${ENDPOINT}/options`, {
      headers: { Accept: 'application/json' },
    })

    documentTypes.value = data?.data?.document_types ?? []
    daftarCabang.value = data?.data?.branches ?? []
    daftarDepartment.value = data?.data?.departments ?? []

    kodeJenisKasBank.value = data?.data?.cash_bank_account_type ?? ''
  }
  catch (error: unknown) {
    documentTypes.value = []
    daftarCabang.value = []
    daftarDepartment.value = []

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.create.step1.loadFailed')),
    })
  }
}

/*
| Memulihkan formulir dari dokumen yang tersimpan.
|
| Dipisahkan dari muatBdv bukan sekadar demi kerapian: kas/bank dan akun tiap
| baris harus dipulihkan sebagai OBJEK UTUH, bukan sekadar nomornya.
|
| Kedua kolom itu adalah pencarian ke Accurate yang baru berisi daftar sesudah
| orangnya mengetik. Dengan memegang nomornya saja, kolomnya akan tampak
| kosong saat halaman dibuka -- pilihannya ada di basis data, hanya tidak ada
| di daftar yang sedang ditampilkan. Persoalan yang sama pernah terjadi pada
| pemilihan nomor PO di Goods Receipt.
*/
/*
| Pilihan akun yang sudah tersimpan, dipulihkan ke bentuk yang dipakai kolom
| pencarian.
|
| Dipisahkan dari isiFormulirDari bukan sekadar demi kerapian: perulangan
| bersarang atas dokumen dan barisnya membuat fungsi itu melewati batas
| kerumitan yang dijaga linter, dan memecahnya di sini lebih jujur daripada
| mematikan aturannya.
*/
const akunTersimpanDari = (sumber: BdvDetail): Record<string, AkunAccurate | null> => {
  const hasil: Record<string, AkunAccurate | null> = {}

  for (const dok of sumber.sources ?? []) {
    for (const item of dok.items ?? []) {
      if (!item.accurate_account_no)
        continue

      hasil[kunciItem(dok.public_id, item.line_no)] = {
        id: item.accurate_account_no,
        text: item.accurate_account_name || item.accurate_account_no,
      }
    }
  }

  return hasil
}

/*
| Menyalin daftar dokumen voucher ke kolom pemilihan di tahap 1.
|
| Department, cabang, dan jenis diambil dari dokumen PERTAMA. Itu sah karena
| ketiganya wajib sama untuk seluruh dokumen dalam satu voucher -- aturan yang
| ditegakkan server, bukan kebetulan yang berlaku hari ini.
|
| Harus berjalan di bawah penanda memulihkan: lihat keterangannya di atas.
*/
const pulihkanSumberDari = (sumber: BdvDetail): void => {
  const pertama = sumber.sources?.[0] ?? null

  jenisTerpilih.value = pertama?.document_type ?? null
  departmentDipilih.value = pertama?.detail?.department_id ?? null
  cabangDipilih.value = pertama?.detail?.branch_id ?? null

  const terpilih: DokumenRingkas[] = []
  const rincian: Record<string, DokumenRincian> = {}
  const jenis: Record<string, string> = {}

  for (const dok of sumber.sources ?? []) {
    terpilih.push({
      public_id: dok.public_id,
      number: dok.source_number ?? '',
      date: dok.detail?.date ?? null,
      subject: dok.detail?.subject ?? null,
      total_amount: Number(dok.total_amount ?? 0),
    })

    if (dok.detail)
      rincian[dok.public_id] = dok.detail

    jenis[dok.public_id] = dok.document_type
  }

  dokumenDipilih.value = terpilih
  rincianPerDokumen.value = rincian
  jenisPerDokumen.value = jenis
}

const isiFormulirDari = (sumber: BdvDetail): void => {
  form.bdvNumber = sumber.bdv_number ?? ''
  form.date = sumber.date ?? null
  form.notes = sumber.notes ?? ''
  form.checkNumber = sumber.check_number ?? ''
  form.payee = sumber.payee ?? ''

  form.kasBank = null

  if (sumber.cash_bank_account_no) {
    form.kasBank = {
      id: sumber.cash_bank_account_no,
      text: sumber.cash_bank_account_name || sumber.cash_bank_account_no,
    }
  }

  akunPerItem.value = akunTersimpanDari(sumber)

  pulihkanSumberDari(sumber)
}

const muatBdv = async (): Promise<void> => {
  if (!publicId.value) {
    gagalMuat.value = true

    showErrorToast({
      title: t('common.alert.error'),
      text: t('bdv.edit.missingId'),
    })

    return
  }

  memuat.value = true
  gagalMuat.value = false
  memulihkan.value = true

  try {
    const { data } = await axios.get(
      `${ENDPOINT}/${encodeURIComponent(publicId.value)}`,
      { headers: { Accept: 'application/json' } },
    )

    bdv.value = data?.data ?? null

    if (!bdv.value)
      throw new Error(t('bdv.edit.notFound'))

    isiFormulirDari(bdv.value)

    /*
    | Penanda dilepas SESUDAH pengawas sempat berjalan, bukan sebelumnya.
    |
    | Pengawas Vue menyala pada akhir putaran, bukan saat nilainya diubah.
    | Melepas penandanya langsung di baris berikutnya berarti pengawas itu
    | tetap berjalan dengan penanda yang sudah mati -- dan tugasnya memang
    | membuang seluruh dokumen yang baru saja dipulihkan.
    */
    await nextTick()
    memulihkan.value = false

    /* Daftar pilihannya baru disiapkan sekarang, setelah ketiga kolomnya terisi. */
    await muatDokumen()
  }
  catch (error: unknown) {
    bdv.value = null
    gagalMuat.value = true

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.edit.loadFailed')),
    })
  }
  finally {
    memuat.value = false
    memulihkan.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Tampilan nilai
|--------------------------------------------------------------------------
*/
const rupiah = (nilai: number | null | undefined): string => formatUang(nilai ?? 0)

const angka = (nilai: number | null | undefined): string => formatAngka(nilai ?? 0)

const tanggal = (nilai: string | null): string => {
  if (!nilai)
    return t('bdv.create.detail.empty')

  const d = new Date(nilai)

  if (Number.isNaN(d.getTime()))
    return t('bdv.create.detail.empty')

  return d.toLocaleDateString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  })
}

const isiAtauStrip = (nilai: string | null | undefined): string =>
  (nilai && String(nilai).trim() !== '') ? String(nilai) : t('bdv.create.detail.empty')

/*
| Ringkasan satu dokumen untuk isi accordion.
|
| Disusun sebagai daftar pasangan label-nilai, bukan ditulis satu per satu
| di markup: isinya sama untuk setiap dokumen, dan menuliskannya berulang
| berarti sepuluh tempat yang harus diubah setiap kali satu kolom ditambah.
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

/*
| Sama persis dengan halaman daftar: status yang belum punya terjemahan
| ditampilkan apa adanya, bukan sebagai kunci mentah "bdv.status.XXX".
*/
/*
| Warna angka di dalam lingkaran tahap -- sama seperti halaman buat.
|
| Lingkarannya berlatar primary pekat, jadi angkanya harus putih di tema
| terang maupun gelap; on-primary memang #FFFFFF pada keduanya. Tanpa ini
| angkanya mewarisi warna teks halaman, yang di tema terang berarti hampir
| hitam di atas ungu.
|
| Di halaman ubah ketiga tahapnya selalu aktif: dokumennya sudah tertentu
| sejak halaman dibuka, jadi tidak ada yang menunggu giliran.
*/
const kelasNomorTahap = (aktif: boolean): string[] => [
  'text-body-2',
  'font-weight-bold',
  aktif ? 'text-white' : 'text-medium-emphasis',
]

/*
| Jumlah baris satu dokumen, untuk kaki tabelnya sendiri.
|
| Dihitung dari barisnya, bukan diambil dari total_amount dokumennya: yang
| dua itu bisa berbeda -- nilai dokumen masih bisa direvisi Finance -- dan
| kaki tabel harus menjumlahkan yang benar-benar tertera di atasnya.
*/
const jumlahKelompok = (k: { items: DokumenItem[] }): number =>
  k.items.reduce((total, i) => total + Number(i.amount ?? 0), 0)

const adaKolomQty = computed<boolean>(() =>
  kelompokItem.value.some(k => k.items.some(i => i.qty !== null || i.unit_price !== null)))

const daftarItem = computed<DokumenItem[]>(() =>
  kelompokItem.value.flatMap(k => k.items))

/** Nilai seluruh dokumen yang dicairkan voucher ini. */
const jumlahDokumen = computed<number>(() =>
  kelompokItem.value.reduce((total, k) => total + k.total_amount, 0))

/* Baris yang akunnya belum dipilih, ditandai sebelum Simpan ditekan. */
const barisTanpaAkun = computed<number>(() =>
  kelompokItem.value.reduce(
    (jumlah, k) => jumlah + k.items.filter(i => !akunPerItem.value[kunciItem(k.public_id, i.no)]).length,
    0,
  ))

const jumlahItem = computed<number>(() =>
  daftarItem.value.reduce((total, i) => total + Number(i.amount ?? 0), 0))

/*
| Cabang diambil dari dokumen bila rinciannya berhasil dimuat, dan dari
| vouchernya sendiri bila tidak. Keduanya menyebut cabang yang sama --
| vouchernya memang menyalin cabang dokumen saat diterbitkan.
*/
const branchDokumen = computed<string | null>(() =>
  cabangTerpilih.value ?? bdv.value?.branch ?? null)

/*
|--------------------------------------------------------------------------
| Simpan
|--------------------------------------------------------------------------
| Tiga keadaan, sama seperti halaman buat: tersimpan dan masuk Accurate,
| tersimpan tetapi belum masuk, atau gagal tersimpan. Yang kedua ditahan di
| halaman ini supaya bisa dicoba lagi -- menekan Simpan sekali lagi adalah
| cara menyelesaikannya.
|
| Dokumen sumbernya tidak ikut dikirim: ia memang tidak bisa diganti dari
| sini, dan server pun membacanya dari dokumen yang sudah tersimpan.
|
| Ditegaskan dulu sebelum dikirim, dan kabar berhasilnya dititipkan ke
| daftar BDV -- alasannya sama persis dengan halaman buat, lihat di sana.
| Di halaman ini malah lebih berat: perubahan di sini menimpa dokumen yang
| SUDAH ada di pembukuan Accurate, bukan menambah yang baru.
|--------------------------------------------------------------------------
*/
const formRef = ref()
const menyimpan = ref(false)

const sumberUntukServer = (): Array<Record<string, unknown>> =>
  daftarSumber.value.map(dok => ({
    document_type: dok.document_type,
    public_id: dok.public_id,

    items: (dok.detail?.items ?? []).map(item => ({
      line_no: item.no,
      accurate_account_no: akunPerItem.value[kunciItem(dok.public_id, item.no)]?.id ?? null,
      accurate_account_name: akunPerItem.value[kunciItem(dok.public_id, item.no)]?.text ?? null,
    })),
  }))

const simpan = async (): Promise<void> => {
  if (menyimpan.value || terkunci.value)
    return

  const hasil = await formRef.value?.validate()

  if (hasil && hasil.valid === false) {
    showErrorToast({
      title: t('common.alert.error'),
      text: t('bdv.create.voucher.invalid'),
    })

    return
  }

  const konfirmasi = await showConfirmAlert({
    title: t('bdv.edit.confirmTitle'),
    text: t('bdv.edit.confirmText'),
    confirmButtonText: t('bdv.edit.confirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!konfirmasi.isConfirmed)
    return

  menyimpan.value = true

  /*
   * Penghalang layar selama menyimpan -- alasannya sama seperti di halaman
   * buat, dan di sini sedikit lebih berat: perubahan ini menimpa dokumen
   * yang SUDAH ada di pembukuan Accurate, dan panggilan itulah yang lama.
   */
  showLoadingAlert(
    t('bdv.edit.saving'),
    t('common.alert.pleaseWait'),
  )

  try {
    const { data } = await axios.put(
      `${ENDPOINT}/${encodeURIComponent(publicId.value)}`,
      {
        bdv_number: form.bdvNumber,
        date: form.date,

        cash_bank_account_no: form.kasBank?.id ?? null,
        cash_bank_account_name: form.kasBank?.text ?? null,

        check_number: form.checkNumber || null,
        payee: form.payee,
        notes: form.notes || null,

        sources: sumberUntukServer(),
      },
      { headers: { Accept: 'application/json' } },
    )

    /*
     * Ditutup SEBELUM toast apa pun muncul: penghalangnya berlapis lebih
     * tinggi daripada toast, jadi menutupnya belakangan menutupi kabar yang
     * baru saja ditampilkan.
     */
    closeAlert()

    /*
     * Dari adanya warning, bukan hanya accurate_sent -- selama ada kabar
     * buruk yang perlu dibaca, ia tidak boleh tertutup toast hijau.
     */
    /*
     * Tersimpan, tetapi Accurate belum menerimanya. Diperlakukan sama seperti
     * berhasil -- kembali ke daftar, hanya warnanya yang berbeda. Alasannya
     * sama persis dengan halaman buat; lihat keterangan panjang di sana.
     */
    if (data?.warning || data?.accurate_sent === false) {
      queueToast('warning', {
        title: t('common.alert.warning'),
        text: data?.warning || t('bdv.create.voucher.accurateFailed'),
      })

      router.push('/cash_bank/bdv')

      return
    }

    queueToast('success', {
      title: t('common.alert.success'),
      text: data?.message || t('bdv.create.voucher.saved'),
    })

    router.push('/cash_bank/bdv')
  }
  catch (error: unknown) {
    closeAlert()

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.create.voucher.saveFailed')),
    })
  }
  finally {
    /* Penutup terakhir, untuk jalur yang tidak terduga. */
    closeAlert()

    menyimpan.value = false
  }
}

const kembali = (): void => {
  router.push('/cash_bank/bdv')
}

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canUpdate.value)
    return

  /*
   * Berurutan, bukan berbarengan. Daftar kas/bank butuh kode jenis akun
   * yang baru diketahui dari jawaban opsi, dan Accurate dibatasi sekitar
   * delapan permintaan per detik -- permintaan yang berangkat bersamaan
   * dari setiap orang yang membuka halaman ini menghabiskan jatah itu
   * lebih cepat daripada yang terlihat.
   */
  await muatOpsi()
  await muatBdv()

  await muatKasBank()
  await muatAkun()
})
</script>

<template>
  <div>
    <VCard v-if="permissionStore.isLoaded && !canUpdate">
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
      <!-- ================= JUDUL ================= -->
      <VCard class="mb-6">
        <VCardText class="pa-5">
          <div class="d-flex align-center justify-space-between flex-wrap gap-3">
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
                  {{ t('bdv.edit.title') }}
                </div>

                <div class="text-body-2 text-medium-emphasis mt-1">
                  {{ bdv?.bdv_number || t('bdv.edit.subtitle') }}
                </div>
              </div>
            </div>

            <VBtn
              color="secondary"
              variant="tonal"
              prepend-icon="tabler-arrow-left"
              class="text-none"
              @click="kembali"
            >
              {{ t('bdv.edit.back') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>

      <!-- ================= SEDANG MEMUAT ================= -->
      <VCard v-if="memuat">
        <VCardText class="text-center py-12">
          <VProgressCircular
            indeterminate
            color="primary"
          />

          <div class="text-body-2 text-medium-emphasis mt-3">
            {{ t('bdv.edit.loading') }}
          </div>
        </VCardText>
      </VCard>

      <!-- ================= GAGAL DIMUAT ================= -->
      <VCard v-else-if="gagalMuat || !bdv">
        <VCardText class="text-center py-12">
          <VIcon
            icon="tabler-file-off"
            size="48"
            class="text-disabled mb-3"
          />

          <div class="text-body-1">
            {{ t('bdv.edit.notFound') }}
          </div>

          <VBtn
            color="primary"
            variant="tonal"
            class="text-none mt-4"
            @click="kembali"
          >
            {{ t('bdv.edit.back') }}
          </VBtn>
        </VCardText>
      </VCard>

      <VCard v-else>
        <VCardText class="pa-5">
          <!--
            Muncul hanya bila dokumennya sudah lewat tahap draft. Tombol
            simpannya memang ikut dimatikan, tetapi tombol mati tanpa
            keterangan hanya memindahkan pertanyaannya, bukan menjawabnya.
          -->
          <VAlert
            v-if="terkunci"
            type="warning"
            variant="tonal"
            density="comfortable"
            class="mb-6"
            icon="tabler-lock"
          >
            <div class="font-weight-medium">
              {{ t('bdv.edit.locked.title') }}
            </div>

            <div class="text-body-2">
              {{ alasanTerkunci }}
            </div>
          </VAlert>

          <!-- ============ TAHAP 1: DEPARTMENT, CABANG, JENIS ============ -->
          <div class="d-flex gap-4">
            <VAvatar
              size="32"
              color="primary"
              variant="flat"
              class="flex-shrink-0 mt-1"
            >
              <span :class="kelasNomorTahap(true)">1</span>
            </VAvatar>

            <div class="flex-grow-1">
              <div class="text-h6">
                {{ t('bdv.create.step1.title') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mb-4">
                {{ t('bdv.create.step1.subtitle') }}
              </div>

              <VRow>
                <!--
                  DEPARTMENT, CABANG, lalu JENIS -- urutannya menentukan,
                  sama persis dengan halaman buat: daftar nomor dokumen
                  disaring ke ketiganya, sehingga yang muncul memang hanya
                  yang boleh dipilih bersama.

                  Mengganti department atau cabang MEMBUANG dokumen yang sudah
                  terpilih. Itu disengaja -- lihat keterangannya di skrip.
                -->
                <VCol
                  cols="12"
                  md="4"
                >
                  <VAutocomplete
                    v-model="departmentDipilih"
                    :menu-props="{ maxHeight: 300, location: 'bottom', offset: 4 }"
                    :items="daftarDepartment"
                    :label="labelWajib('bdv.create.step1.department')"
                    :disabled="terkunci"
                    density="comfortable"
                    prepend-inner-icon="tabler-users-group"
                    hide-details
                  />
                </VCol>

                <VCol
                  cols="12"
                  md="4"
                >
                  <VAutocomplete
                    v-model="cabangDipilih"
                    :menu-props="{ maxHeight: 300, location: 'bottom', offset: 4 }"
                    :items="daftarCabang"
                    :label="labelWajib('bdv.create.step1.branch')"
                    :disabled="terkunci || !departmentDipilih"
                    density="comfortable"
                    prepend-inner-icon="tabler-building"
                    hide-details
                  />
                </VCol>

                <VCol
                  cols="12"
                  md="4"
                >
                  <!--
                    Terkunci selama masih ada dokumen terpilih. Untuk berganti
                    jenis -- FPU menjadi Claim -- dokumennya dikeluarkan dulu
                    semuanya; keterangannya muncul di bawah kolom ini.
                  -->
                  <VSelect
                    v-model="jenisTerpilih"
                    :menu-props="{ maxHeight: 300, location: 'bottom', offset: 4 }"
                    :items="documentTypeOptions"
                    :label="t('bdv.create.step1.label')"
                    :disabled="terkunci || !departmentDipilih || !cabangDipilih || jenisDipakai !== null"
                    density="comfortable"
                    :hint="jenisDipakai !== null ? t('bdv.create.step1.typeLocked') : undefined"
                    :persistent-hint="jenisDipakai !== null"
                    :hide-details="jenisDipakai === null"
                    prepend-inner-icon="tabler-file-text"
                  />
                </VCol>
              </VRow>

              <div class="text-caption text-medium-emphasis mt-2">
                {{ t('bdv.create.step1.filterHint') }}
              </div>
            </div>
          </div>

          <VDivider class="my-6" />

          <!-- ============ TAHAP 2: NOMOR DOKUMEN ============ -->
          <div class="d-flex gap-4">
            <VAvatar
              size="32"
              :color="tahapAktif >= 2 ? 'primary' : 'secondary'"
              :variant="tahapAktif >= 2 ? 'flat' : 'tonal'"
              class="flex-shrink-0 mt-1"
            >
              <span :class="kelasNomorTahap(tahapAktif >= 2)">2</span>
            </VAvatar>

            <div class="flex-grow-1">
              <div class="text-h6">
                {{ t('bdv.create.step2.title') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mb-4">
                {{ t('bdv.create.step2.subtitle') }}
              </div>

              <div
                v-if="!jenisTerpilih"
                class="text-body-2 text-disabled"
              >
                {{ t('bdv.create.step2.waitingType') }}
              </div>

              <template v-else>
                <VRow>
                  <VCol
                    cols="12"
                    md="8"
                  >
                    <!--
                      Memilih TIDAK mengisi kolom ini, melainkan menambah satu
                      baris ke daftar di bawahnya. Kolomnya langsung kosong
                      lagi, supaya dokumen berikutnya bisa dicari tanpa
                      menghapus isian sebelumnya.
                    -->
                    <VAutocomplete
                      v-model:search="kataCari"
                      :model-value="null"
                      :items="dokumenTersisa"
                      item-title="number"
                      return-object
                      :menu-props="{ maxHeight: 260, location: 'bottom', offset: 4 }"
                      :loading="dokumenLoading || rincianLoading"
                      :label="t('bdv.create.step2.label')"
                      :hint="t('bdv.create.step2.hintMulti')"
                      persistent-hint
                      :disabled="terkunci"
                      density="comfortable"
                      prepend-inner-icon="tabler-hash"
                      no-filter
                      :no-data-text="kataCari
                        ? t('bdv.create.step2.emptySearch')
                        : t('bdv.create.step2.empty')"
                      @update:model-value="tambahDokumen"
                    />
                  </VCol>
                </VRow>

                <!--
                  Dokumen yang sudah dipilih, berjajar sebagai chip. Dikeluarkan
                  dari sini juga -- tidak perlu mencarinya lagi di daftar.
                -->
                <div
                  v-if="dokumenDipilih.length"
                  class="d-flex flex-wrap gap-2 mt-4"
                >
                  <VChip
                    v-for="dok in dokumenDipilih"
                    :key="dok.public_id"
                    color="primary"
                    variant="tonal"
                    :closable="!terkunci"
                    @click:close="keluarkanDokumen(dok.public_id)"
                  >
                    <VIcon
                      icon="tabler-file-text"
                      size="14"
                      start
                    />

                    {{ dok.number }}
                  </VChip>
                </div>

                <!--
                  Voucher tanpa dokumen tidak bisa disimpan -- server menuntut
                  sedikitnya satu. Dikatakan di sini, bukan dibiarkan muncul
                  sebagai penolakan setelah Simpan ditekan.
                -->
                <VAlert
                  v-else
                  type="warning"
                  variant="tonal"
                  density="compact"
                  class="mt-4"
                >
                  {{ t('bdv.edit.step1.emptied') }}
                </VAlert>

                <div
                  v-if="cabangTerpilih"
                  class="text-caption text-medium-emphasis mt-2"
                >
                  {{ t('bdv.create.step2.branchLocked', { branch: cabangTerpilih }) }}
                </div>

                <div
                  v-if="!dokumenLoading && batasTampil > 0 && dokumenDipilih.length === 0"
                  class="text-caption text-medium-emphasis mt-2"
                >
                  {{ t('bdv.create.step2.limited', { count: batasTampil }) }}
                </div>
              </template>
            </div>
          </div>

          <VDivider class="my-6" />

          <!-- ============ TAHAP 3: RINCIAN DOKUMEN ============ -->
          <div class="d-flex gap-4">
            <VAvatar
              size="32"
              :color="tahapAktif >= 3 ? 'primary' : 'secondary'"
              :variant="tahapAktif >= 3 ? 'flat' : 'tonal'"
              class="flex-shrink-0 mt-1"
            >
              <span :class="kelasNomorTahap(tahapAktif >= 3)">3</span>
            </VAvatar>

            <div class="flex-grow-1 min-width-0">
              <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
                <div>
                  <div class="text-h6">
                    {{ t('bdv.edit.step1.title') }}
                  </div>

                  <div class="text-body-2 text-medium-emphasis">
                    {{ t('bdv.edit.step1.subtitle') }}
                  </div>
                </div>

                <VChip
                  v-if="daftarSumber.length"
                  size="small"
                  variant="tonal"
                  color="info"
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
                {{ t('bdv.create.step3.waiting') }}
              </VAlert>

              <!--
                Accordion, bukan seluruhnya terbuka. Satu BDV bisa memegang
                belasan dokumen, dan rincian lengkap semuanya sekaligus
                membuat halaman ini sepanjang beberapa layar sebelum orangnya
                sampai ke kolom yang harus diisi.
              -->
              <VExpansionPanels
                v-else
                variant="accordion"
                class="bdv-dokumen-panel"
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

                    <VRow v-else>
                      <VCol
                        v-for="kolom in ringkasanDokumen(dok.detail)"
                        :key="kolom.label"
                        cols="12"
                        md="4"
                      >
                        <div class="text-caption text-medium-emphasis">
                          {{ kolom.label }}
                        </div>

                        <div class="text-body-2">
                          {{ kolom.nilai }}
                        </div>
                      </VCol>

                      <VCol
                        v-if="dok.detail.notes"
                        cols="12"
                      >
                        <div class="text-caption text-medium-emphasis">
                          {{ t('bdv.create.detail.notes') }}
                        </div>

                        <div class="text-body-2">
                          {{ dok.detail.notes }}
                        </div>
                      </VCol>
                    </VRow>
                  </VExpansionPanelText>
                </VExpansionPanel>
              </VExpansionPanels>
            </div>
          </div>

          <VDivider class="my-6" />

          <!--
            Satu VForm membungkus TAHAP 4 dan TAHAP 5 sekaligus.

            Bukan satu per tahap: pemilih akun pada tiap baris rincian punya
            aturan wajib isi sendiri, dan aturan itu hanya ikut diperiksa
            formRef.validate() bila kolomnya berada di dalam VForm yang sama
            dengan isian vouchernya. Di luar itu, baris tanpa akun baru
            ketahuan setelah ditolak server -- sebagai toast merah, bukan
            sebagai tanda merah pada kolom yang bersangkutan.
          -->
          <VForm ref="formRef">
            <!-- ============ TAHAP 4: DATA VOUCHER ============ -->
            <div class="d-flex gap-4">
              <VAvatar
                size="32"
                color="primary"
                variant="flat"
                class="flex-shrink-0 mt-1"
              >
                <span :class="kelasNomorTahap(true)">4</span>
              </VAvatar>

              <div class="flex-grow-1 min-width-0">
                <div class="text-h6">
                  {{ t('bdv.edit.step2.title') }}
                </div>

                <div class="text-body-2 text-medium-emphasis mb-4">
                  {{ t('bdv.edit.step2.subtitle') }}
                </div>

                <VRow>
                  <VCol
                    cols="12"
                    md="4"
                  >
                    <!--
                      attach: 'body' supaya menunya diposisikan terhadap
                      kolomnya sendiri, bukan terhadap kartu pembungkusnya.
                    -->
                    <VAutocomplete
                      v-model="form.kasBank"
                      :items="kasBankList"
                      :loading="kasBankLoading"
                      no-filter
                      item-title="text"
                      return-object
                      clearable
                      :disabled="terkunci"
                      density="comfortable"
                      :label="labelWajib('bdv.create.voucher.fields.cashBank')"
                      :rules="[wajibIsi]"
                      :menu-props="{ maxHeight: 300, attach: 'body', location: 'bottom', offset: 4 }"
                      @update:search="cariKasBank"
                    >
                      <template #append-inner>
                        <VBtn
                          v-if="kasBankGagal"
                          icon
                          size="x-small"
                          variant="text"
                          color="error"
                          :loading="kasBankLoading"
                          :title="t('bdv.create.voucher.cashBankReload')"
                          @click.stop="muatUlangKasBank"
                        >
                          <VIcon icon="tabler-refresh" />
                        </VBtn>
                      </template>

                      <template #prepend-item>
                        <div
                          v-if="kasBankLoading"
                          class="pa-2"
                        >
                          <VProgressLinear
                            indeterminate
                            color="primary"
                          />

                          <div class="text-caption mt-1 text-center">
                            {{ t('bdv.create.voucher.cashBankLoading') }}
                          </div>
                        </div>
                      </template>

                      <template #no-data>
                        <div class="pa-3 text-center text-body-2">
                          <span v-if="kasBankLoading">
                            {{ t('bdv.create.voucher.cashBankLoading') }}
                          </span>

                          <span v-else>
                            {{ t('bdv.create.voucher.cashBankEmpty') }}
                          </span>
                        </div>
                      </template>

                      <!-- Nomor akun membedakan nama-nama yang mirip. -->
                      <template #item="{ props: itemProps, item }">
                        <VListItem v-bind="itemProps">
                          <template #subtitle>
                            {{ item.raw?.id ?? '-' }}
                          </template>
                        </VListItem>
                      </template>
                    </VAutocomplete>
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <VTextField
                      v-model="form.bdvNumber"
                      density="comfortable"
                      :disabled="terkunci"
                      :label="labelWajib('bdv.create.voucher.fields.bdvNumber')"
                      :rules="[wajibIsi]"
                    />
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <AppDateTimePicker
                      v-model="form.date"
                      density="comfortable"
                      clearable
                      :disabled="terkunci"
                      :label="labelWajib('bdv.create.voucher.fields.date')"
                      :rules="[wajibIsi]"
                      :config="{ dateFormat: 'Y-m-d', position: 'below' }"
                    />
                  </VCol>
                </VRow>

                <VRow class="mt-1">
                  <VCol
                    cols="12"
                    md="4"
                  >
                    <VTextField
                      v-model="form.checkNumber"
                      density="comfortable"
                      clearable
                      :disabled="terkunci"
                      :label="t('bdv.create.voucher.fields.checkNumber')"
                    />
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <!--
                      Wajib, mengikuti Accurate. Lihat keterangan yang sama
                      pada halaman buat.
                    -->
                    <VTextField
                      v-model="form.payee"
                      density="comfortable"
                      clearable
                      :disabled="terkunci"
                      :label="labelWajib('bdv.create.voucher.fields.payee')"
                      :rules="[wajibIsi]"
                    />
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <!-- Mengikuti dokumen, tidak diketik. -->
                    <VTextField
                      :model-value="branchDokumen ?? t('bdv.create.detail.empty')"
                      density="comfortable"
                      readonly
                      :label="t('bdv.create.voucher.fields.branch')"
                      :hint="t('bdv.create.voucher.branchFromDocument')"
                      persistent-hint
                    />
                  </VCol>

                  <VCol cols="12">
                    <VTextarea
                      v-model="form.notes"
                      rows="2"
                      auto-grow
                      density="comfortable"
                      :disabled="terkunci"
                      :label="t('bdv.create.voucher.fields.notes')"
                    />
                  </VCol>
                </VRow>
              </div>
            </div>

            <VDivider class="my-6" />

            <!-- ============ TAHAP 5: RINCIAN ITEM ============ -->
            <div class="d-flex gap-4">
              <VAvatar
                size="32"
                color="primary"
                variant="flat"
                class="flex-shrink-0 mt-1"
              >
                <span :class="kelasNomorTahap(true)">5</span>
              </VAvatar>

              <div class="flex-grow-1 min-width-0">
                <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
                  <div>
                    <div class="text-h6">
                      {{ t('bdv.edit.step3.title') }}
                    </div>

                    <div class="text-body-2 text-medium-emphasis">
                      {{ t('bdv.create.items.subtitleMulti') }}
                    </div>
                  </div>

                  <div class="d-flex align-center flex-wrap gap-2">
                    <!--
                      Berapa baris yang akunnya belum dipilih. Disebutkan di
                      sini, bukan hanya saat Simpan ditekan: pada belasan
                      baris yang terbagi beberapa dokumen, satu kolom kosong
                      yang terlewat sangat mudah luput.
                    -->
                    <VChip
                      v-if="barisTanpaAkun > 0"
                      size="small"
                      variant="tonal"
                      color="warning"
                    >
                      <VIcon
                        icon="tabler-alert-circle"
                        size="14"
                        start
                      />

                      {{ t('bdv.create.items.missingAccount', { count: barisTanpaAkun }) }}
                    </VChip>

                    <VChip
                      size="small"
                      variant="tonal"
                      color="info"
                    >
                      {{ t('bdv.create.items.count', { count: daftarItem.length }) }}
                    </VChip>
                  </div>
                </div>

                <VAlert
                  v-if="daftarItem.length === 0"
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  {{ t('bdv.create.items.empty') }}
                </VAlert>

                <!--
                  SATU TABEL PER DOKUMEN, bukan satu tabel panjang.

                  Nomor baris berulang antar dokumen, dan rincian yang
                  dirata menjadi satu daftar membuat orang yang memilih akun
                  untuk baris ke-7 tidak punya cara tahu baris itu milik
                  dokumen yang mana. Salah membebankan biaya ke akun dokumen
                  lain tidak menimbulkan galat apa pun -- hanya angka yang
                  keliru di pembukuan.
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

                    <VTable
                      density="comfortable"
                      class="bdv-item-table"
                    >
                      <thead>
                        <tr>
                          <th style="inline-size: 48px;">
                            {{ t('bdv.create.items.columns.no') }}
                          </th>
                          <th style="inline-size: 120px;">
                            {{ t('bdv.create.items.columns.date') }}
                          </th>
                          <th>{{ t('bdv.create.items.columns.description') }}</th>
                          <th>{{ t('bdv.create.items.columns.category') }}</th>

                          <th
                            v-if="adaKolomQty"
                            class="text-end"
                            style="inline-size: 90px;"
                          >
                            {{ t('bdv.create.items.columns.qty') }}
                          </th>

                          <th
                            v-if="adaKolomQty"
                            class="text-end"
                            style="inline-size: 140px;"
                          >
                            {{ t('bdv.create.items.columns.unitPrice') }}
                          </th>

                          <th
                            class="text-end"
                            style="inline-size: 150px;"
                          >
                            {{ t('bdv.create.items.columns.amount') }}
                          </th>

                          <th style="inline-size: 280px;">
                            {{ t('bdv.create.items.columns.account') }}
                            <span class="text-error">*</span>
                          </th>

                          <th style="inline-size: 160px;">
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

                          <td class="text-wrap">
                            {{ isiAtauStrip(item.description) }}
                          </td>

                          <td>{{ isiAtauStrip(item.category) }}</td>

                          <td
                            v-if="adaKolomQty"
                            class="text-end text-no-wrap"
                          >
                            {{ item.qty === null ? t('bdv.create.detail.empty') : angka(item.qty) }}
                          </td>

                          <td
                            v-if="adaKolomQty"
                            class="text-end text-no-wrap"
                          >
                            {{ item.unit_price === null ? t('bdv.create.detail.empty') : rupiah(item.unit_price) }}
                          </td>

                          <td class="text-end text-no-wrap font-weight-medium">
                            {{ rupiah(item.amount) }}
                          </td>

                          <!--
                            Dikunci "<public_id>:<nomor baris>" -- lihat
                            keterangan pada akunPerItem. Nomor baris saja
                            akan membuat pilihan dokumen kedua menimpa
                            pilihan dokumen pertama.
                          -->
                          <td>
                            <VAutocomplete
                              :model-value="akunPerItem[kunciItem(kelompok.public_id, item.no)] ?? null"
                              :items="akunList"
                              :loading="akunLoading"
                              item-title="text"
                              return-object
                              density="compact"
                              hide-details="auto"
                              variant="outlined"
                              :rules="[wajibIsi]"
                              :disabled="terkunci"
                              :label="t('bdv.create.items.columns.account')"
                              :menu-props="{ maxHeight: 300, attach: 'body', location: 'bottom', offset: 4 }"
                              no-filter
                              @update:search="cariAkun"
                              @update:model-value="(nilai) => {
                                akunPerItem = {
                                  ...akunPerItem,
                                  [kunciItem(kelompok.public_id, item.no)]: nilai,
                                }
                              }"
                            >
                              <template #prepend-item>
                                <div
                                  v-if="akunLoading"
                                  class="pa-2"
                                >
                                  <VProgressLinear
                                    indeterminate
                                    color="primary"
                                  />

                                  <div class="text-caption mt-1 text-center">
                                    {{ t('bdv.create.items.accountLoading') }}
                                  </div>
                                </div>
                              </template>

                              <template #no-data>
                                <div class="pa-3 text-center text-body-2">
                                  <span v-if="akunLoading">
                                    {{ t('bdv.create.items.accountLoading') }}
                                  </span>

                                  <span v-else>
                                    {{ t('bdv.create.items.accountEmpty') }}
                                  </span>
                                </div>
                              </template>

                              <template #item="{ props: itemProps, item: akun }">
                                <VListItem v-bind="itemProps">
                                  <template #subtitle>
                                    {{ akun.raw?.id ?? '-' }}
                                  </template>
                                </VListItem>
                              </template>

                              <!-- Muncul hanya bila pengambilan terakhir gagal. -->
                              <template #append-inner>
                                <VBtn
                                  v-if="akunGagal"
                                  icon
                                  size="x-small"
                                  variant="text"
                                  color="error"
                                  :loading="akunLoading"
                                  :title="t('bdv.create.items.accountReload')"
                                  @click.stop="muatUlangAkun"
                                >
                                  <VIcon icon="tabler-refresh" />
                                </VBtn>
                              </template>
                            </VAutocomplete>
                          </td>

                          <!--
                            Bukti milik baris ini, dibuka di tab baru. Tidak
                            ditampilkan sebagai pratinjau: satu voucher bisa
                            memegang puluhan baris, dan puluhan gambar yang
                            dimuat sekaligus membuat halaman ini berat tanpa
                            ada yang meminta.
                          -->
                          <td>
                            <div
                              v-if="(item.attachments || []).length"
                              class="d-flex flex-column gap-1"
                            >
                              <a
                                v-for="berkas in item.attachments"
                                :key="`lampiran-${kelompok.public_id}-${item.no}-${berkas.id}`"
                                class="bdv-item-file"
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
                                  class="bdv-item-file-name"
                                  :title="berkas.original_filename || berkas.filename || ''"
                                >
                                  {{ berkas.original_filename || berkas.filename }}
                                </span>
                              </a>
                            </div>

                            <span
                              v-else
                              class="text-disabled"
                            >-</span>
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
                            {{ rupiah(jumlahItem) }}
                          </td>

                          <td colspan="2" />
                        </tr>
                      </tfoot>
                    </VTable>
                  </div>
                </template>

                <VAlert
                  v-if="daftarItem.length > 0 && jumlahItem !== jumlahDokumen"
                  type="warning"
                  variant="tonal"
                  density="compact"
                  class="mt-3"
                >
                  {{ t('bdv.create.items.mismatch', {
                    items: rupiah(jumlahItem),
                    document: rupiah(jumlahDokumen),
                  }) }}
                </VAlert>
              </div>
            </div>
          </VForm>

          <VDivider class="my-6" />

          <div class="d-flex justify-end flex-wrap gap-3">
            <VBtn
              variant="tonal"
              color="secondary"
              class="text-none"
              :disabled="menyimpan"
              @click="kembali"
            >
              {{ t('common.actions.cancel') }}
            </VBtn>

            <VBtn
              color="primary"
              class="text-none"
              prepend-icon="tabler-device-floppy"
              :loading="menyimpan"
              :disabled="terkunci || daftarSumber.length === 0"
              @click="simpan"
            >
              {{ t('bdv.edit.saveButton') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </template>
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

/*
| Bukti yang menempel pada satu baris rincian -- bentuknya sama persis
| dengan yang ada di modal detail dan di daftar FPU/Claim, supaya satu hal
| yang sama tidak tampil berbeda di tiga layar.
*/
.bdv-item-file {
  display: flex;
  align-items: center;
  color: inherit;
  gap: 0.375rem;
  min-inline-size: 0;
  text-decoration: none;
}

.bdv-item-file:hover .bdv-item-file-name {
  text-decoration: underline;
}

/*
| Nama berkas dipotong, bukan dibiarkan melebar. Nama hasil unggahan bisa
| sangat panjang, dan satu nama saja cukup untuk mendorong kolom pemilih
| akun keluar dari layar. Nama penuhnya tetap terbaca lewat title.
*/
.bdv-item-file-name {
  overflow: hidden;
  font-size: 0.8rem;
  max-inline-size: 8.5rem;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
