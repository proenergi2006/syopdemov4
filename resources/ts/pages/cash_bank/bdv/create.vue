<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

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
| Formulir BDV -- pemilihan dokumen sumber
|--------------------------------------------------------------------------
| Tiga tahap, dan sengaja dibuka berurutan: jenis dokumen, lalu nomornya,
| lalu rinciannya. Urutan itu bukan hiasan -- daftar nomor yang ditawarkan
| memang bergantung pada jenis yang dipilih, dan rinciannya baru ada artinya
| setelah nomornya ditentukan.
|
| Tahap ini berhenti sampai rincian tampil. Pengisian data voucher dan
| pengajuan ke approval menyusul.
|--------------------------------------------------------------------------
*/

interface PilihanDokumen {
  code: string
  label: string
}

interface DokumenRingkas {
  public_id: string
  number: string
  date: string | null
  subject: string | null
  total_amount: number
}

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

  /* Hanya terisi pada item FPU; item Claim tidak memakai qty dan harga satuan. */
  qty: number | null
  unit_price: number | null

  amount: number
  category: string | null

  /*
   * Bukti milik baris ini, diangkut dari dokumen sumbernya.
   *
   * Ditampilkan sejak halaman buat, bukan hanya di halaman ubah: akun
   * Accurate tiap baris dipilih di sini, dan yang memilihnya perlu melihat
   * buktinya untuk tahu pengeluaran apa yang sedang dibebankan.
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

/** Satu kas atau bank dari daftar Accurate. */
interface KasBank {
  id: string
  text: string
}

/** Satu akun dari daftar akun Accurate. */
interface AkunAccurate {
  id: string
  text: string
  noWithIndent?: string
}

const { t } = useI18n()
const router = useRouter()
const permissionStore = usePermissionStore()

const ENDPOINT = '/cash-bank/bdv'

const canCreate = computed(() => permissionStore.can('bdv.create'))

/*
|--------------------------------------------------------------------------
| Tahap 1 -- jenis dokumen
|--------------------------------------------------------------------------
*/
const documentTypes = ref<PilihanDokumen[]>([])

/*
| Cabang dipilih LEBIH DULU, sebelum jenis dan nomor dokumennya.
|
| Bukan kenyamanan belaka: seluruh dokumen dalam satu BDV wajib berasal dari
| cabang yang sama. Menyaring daftarnya sejak awal membuat aturan itu tidak
| pernah perlu ditegakkan sebagai penolakan -- yang muncul di daftar memang
| hanya yang boleh dipilih bersama.
*/
interface PilihanCabang {
  value: string | number
  title: string
}

const daftarCabang = ref<PilihanCabang[]>([])
const cabangDipilih = ref<string | null>(null)

/*
| Department dipilih PALING DULU, sebelum cabang dan jenis dokumennya.
|
| Alasannya sama dengan cabang: seluruh dokumen dalam satu BDV wajib dari
| department yang sama. Menyaring daftarnya sejak awal membuat aturan itu
| tidak pernah perlu ditegakkan sebagai penolakan.
*/
const daftarDepartment = ref<PilihanCabang[]>([])
const departmentDipilih = ref<number | null>(null)

const jenisTerpilih = ref<string | null>(null)

const documentTypeOptions = computed(() =>
  documentTypes.value.map(d => ({ value: d.code, title: d.label })))

/*
|--------------------------------------------------------------------------
| Kas / Bank dari Accurate
|--------------------------------------------------------------------------
| Ditaruh sebelum muatJenisDokumen karena fungsi itulah yang menerima kode
| jenis akunnya dari server dan mengisinya ke sini.
|--------------------------------------------------------------------------
*/
const kodeJenisKasBank = ref<string>('')
const kasBankList = ref<KasBank[]>([])
const kasBankLoading = ref(false)

/*
| Penanda bahwa pengambilan terakhir gagal, dipakai memunculkan tombol
| muat ulang di kolomnya. Toast saja tidak cukup: ia lewat beberapa detik
| lalu hilang, dan yang tertinggal hanyalah kolom kosong tanpa petunjuk
| apa pun bahwa ada yang bisa dicoba lagi.
*/
const kasBankGagal = ref(false)

/* Kata kunci terakhir, supaya muat ulang mengulangi pencarian yang sama. */
const kataCariKasBank = ref('')

let nomorPermintaanKasBank = 0
let jedaCariKasBank: ReturnType<typeof setTimeout> | null = null

/*
| Hanya akun bertipe kas dan bank yang ditawarkan, bukan seluruh bagan
| akun: voucher ini mengeluarkan uang, dan uang hanya keluar dari akun
| semacam itu. Penyaringnya dikerjakan Accurate, bukan disaring di layar
| setelah semuanya diturunkan.
*/
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
    | Kegagalan dari Accurate tidak selalu datang sebagai HTTP error.
    | Server menjawab 200 dengan success:false -- misalnya ketika kuota
    | per detik sedang penuh, atau Accurate menolak permintaannya. Tanpa
    | pemeriksaan ini, kolomnya hanya tampak kosong seolah memang tidak
    | ada akun yang cocok.
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

/* Dipanggil tombol muat ulang: mengulangi pencarian yang terakhir dicoba. */
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
| Berbeda dari kolom Kas/Bank, kolom ini TIDAK menyaring jenis akun: biaya
| pada rincian bisa jatuh ke akun mana pun di bagan akun.
|
| Satu daftar dipakai bersama seluruh baris. Isinya sama saja, dan memuat
| ulang per baris hanya menghabiskan jatah permintaan ke Accurate tanpa
| menambah apa pun di layar. Akibat yang disengaja: mengetik pencarian di
| satu baris ikut mengganti isi daftar baris lain yang sedang tertutup --
| tidak terlihat, dan tidak merusak apa pun karena yang sudah dipilih
| disimpan sebagai objek utuh.
|
| Objek utuh, bukan id saja: daftar ini berganti isi tiap kali ada
| pencarian, dan baris yang akunnya tidak lagi ada di daftar akan berubah
| menampilkan id mentah. Persoalan yang sama pernah terjadi pada pemilihan
| nomor dokumen di atas.
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

const kunciItem = (publicId: string, nomorBaris: number): string =>
  `${publicId}:${nomorBaris}`

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

        /*
         * Seluruh bagan akun KECUALI kas dan bank. Kas/bank sudah punya
         * kolomnya sendiri di atas; menawarkannya lagi di sini hanya
         * membuka peluang satu voucher menunjuk dua akun kas sekaligus.
         */
        exclude_account_type: kodeJenisKasBank.value || undefined,
      },
    })

    if (nomorPermintaan !== nomorPermintaanAkun)
      return

    /* Lihat keterangan yang sama pada muatKasBank. */
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

const muatJenisDokumen = async (): Promise<void> => {
  try {
    const { data } = await axios.get(`${ENDPOINT}/options`, {
      headers: { Accept: 'application/json' },
    })

    documentTypes.value = data?.data?.document_types ?? []
    daftarCabang.value = data?.data?.branches ?? []
    daftarDepartment.value = data?.data?.departments ?? []

    /*
     * Kode jenis akun datang dari server, tidak ditulis di sini: bila
     * Accurate memakai kode yang berbeda, yang diubah satu baris config.
     */
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
|--------------------------------------------------------------------------
| Tahap 2 -- nomor dokumen
|--------------------------------------------------------------------------
| Dicari ke server sambil mengetik, bukan disaring di layar: dokumen yang
| layak diangkat bisa ratusan, dan menurunkan semuanya hanya memperlambat.
|--------------------------------------------------------------------------
*/
const dokumen = ref<DokumenRingkas[]>([])
const dokumenLoading = ref(false)

/*
| Menyimpan dokumennya utuh, bukan sekadar public_id-nya.
|
| Dengan menyimpan id saja, kolom harus mencari judul yang cocok di dalam
| daftar pilihan setiap kali menggambar dirinya. Daftar itu berganti isi tiap
| kali pencarian dijalankan, dan begitu dokumen terpilih tidak lagi ada di
| dalamnya, yang tertulis di kolom berubah menjadi id mentahnya -- deretan
| huruf acak yang panjang, persis seperti yang sempat terlihat.
|
| Objek utuh membuat nomornya selalu ada di tangan, tidak bergantung pada isi
| daftar yang sedang ditampilkan.
*/
const dokumenDipilih = ref<DokumenRingkas[]>([])
const kataCari = ref('')

const batasTampil = computed(() => dokumen.value.length)

/*
| Daftar pilihan tanpa dokumen yang sudah dipilih.
|
| DIBANDINGKAN MENURUT NOMOR, BUKAN public_id.
|
| public_id adalah hasil enkripsi, dan enkripsinya memakai IV acak: dokumen
| yang sama menghasilkan ciphertext yang BERBEDA setiap kali daftarnya
| dimuat ulang. Membandingkan public_id karena itu tidak pernah cocok, dan
| dokumen yang sudah dipilih tetap muncul di daftar -- lalu bisa dipilih
| untuk kedua kalinya.
|
| Nomor dokumen stabil dan unik, dan daftarnya memang sudah disaring ke satu
| jenis, jadi tidak ada dua dokumen berbeda yang bernomor sama di sini.
*/
const dokumenTersisa = computed<DokumenRingkas[]>(() =>
  dokumen.value.filter(d => !dokumenDipilih.value.some(dipilih => dipilih.number === d.number)))

let jedaCari: ReturnType<typeof setTimeout> | null = null

/*
| Nomor urut permintaan, dengan alasan yang sama seperti di halaman daftar:
| mengetik cepat melahirkan beberapa pencarian sekaligus, dan yang berangkat
| lebih dulu tidak selalu tiba lebih dulu.
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
| Tahap 3 -- rincian dokumen
|--------------------------------------------------------------------------
*/
/*
| Rincian tiap dokumen, dikunci menurut public_id-nya.
|
| Peta, bukan daftar sejajar: dokumen bisa ditambah dan dikeluarkan dalam
| urutan apa pun, dan daftar sejajar menuntut dua benda tetap sinkron
| panjangnya -- yang cepat atau lambat meleset.
*/
const rincianPerDokumen = ref<Record<string, DokumenRincian>>({})
const rincianLoading = ref(false)

/** Dokumen yang jenisnya dicatat terpisah, karena ringkasannya tidak membawanya. */
const jenisPerDokumen = ref<Record<string, string>>({})

const muatRincianSatu = async (dok: DokumenRingkas, jenis: string): Promise<boolean> => {
  rincianLoading.value = true

  try {
    const { data } = await axios.get(`${ENDPOINT}/document-detail`, {
      headers: { Accept: 'application/json' },
      params: {
        document_type: jenis,
        public_id: dok.public_id,
      },
    })

    if (!data?.data)
      throw new Error('kosong')

    rincianPerDokumen.value = {
      ...rincianPerDokumen.value,
      [dok.public_id]: data.data,
    }

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
| SELURUH dokumen harus dari cabang yang sama -- aturan yang sama dijaga
| server. Diperiksa juga di sini supaya penolakannya datang saat dokumennya
| dipilih, bukan setelah seluruh formulir diisi dan Simpan ditekan.
*/
/*
| Jenis dokumen yang sudah terpakai voucher ini.
|
| Satu BDV hanya boleh mencairkan SATU jenis: FPU saja, atau Claim saja.
| Keduanya dokumen yang berbeda sifatnya -- FPU uang yang diminta di muka,
| Claim penggantian uang yang sudah lebih dulu dikeluarkan orang -- dan
| cetakan yang memuat keduanya tidak bisa menyebut dirinya voucher apa.
|
| null berarti belum ada dokumen terpilih, sehingga jenisnya masih bebas.
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
  if (!dok || !jenisTerpilih.value)
    return

  /*
  | Menurut nomor, dengan alasan yang sama seperti dokumenTersisa: public_id
  | berubah setiap kali daftarnya dimuat ulang.
  */
  if (dokumenDipilih.value.some(d => d.number === dok.number))
    return

  const berhasil = await muatRincianSatu(dok, jenisTerpilih.value)

  if (!berhasil)
    return

  /*
  | Jenis dokumen ikut dijaga, bukan hanya dipercayakan pada terkuncinya
  | kolom jenis. Kolom itu memang terkunci begitu dokumen pertama dipilih,
  | tetapi penjaga yang hanya berupa kolom mati adalah penjaga yang hilang
  | begitu susunan layarnya berubah.
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
      text: t('bdv.create.step3.mixedBranch', {
        branch: cabangTerpilih.value ?? '-',
      }),
    })

    return
  }

  dokumenDipilih.value = [...dokumenDipilih.value, dok]
}

const keluarkanDokumen = (publicId: string): void => {
  dokumenDipilih.value = dokumenDipilih.value.filter(d => d.public_id !== publicId)

  const sisa = { ...rincianPerDokumen.value }

  delete sisa[publicId]

  rincianPerDokumen.value = sisa

  /* Pilihan akun milik dokumen itu ikut dibuang, bukan ditinggalkan yatim. */
  const akun = { ...akunPerItem.value }

  for (const kunci of Object.keys(akun)) {
    if (kunci.startsWith(`${publicId}:`))
      delete akun[kunci]
  }

  akunPerItem.value = akun
}

/*
| Berganti jenis dokumen membatalkan pilihan di bawahnya. Membiarkan nomor
| dokumen FPU tetap terpilih setelah jenisnya diubah ke Claim akan
| menampilkan rincian yang tidak mungkin benar.
*/
/*
| Berganti jenis dokumen TIDAK membuang yang sudah dipilih: satu BDV boleh
| memegang FPU dan Claim sekaligus. Yang dibersihkan hanya kolom pencarinya.
*/
watch(jenisTerpilih, async () => {
  kataCari.value = ''

  await muatDokumen()
})

/*
| Berganti cabang MEMBUANG dokumen yang sudah dipilih, dan itu disengaja.
|
| Yang tertinggal dari cabang sebelumnya akan ditolak server, tetapi
| penolakannya baru datang setelah seluruh formulir terisi. Membuangnya
| sekarang, saat orangnya memang sedang mengubah cabang, jauh lebih mudah
| dimengerti daripada penolakan di akhir.
*/
/*
| Berganti department MEMBUANG dokumen yang sudah dipilih, dengan alasan
| yang sama seperti berganti cabang: yang tertinggal akan ditolak server,
| dan penolakannya baru datang setelah seluruh formulir terisi.
*/
watch(departmentDipilih, async () => {
  dokumenDipilih.value = []
  rincianPerDokumen.value = {}
  jenisPerDokumen.value = {}
  akunPerItem.value = {}
  kataCari.value = ''

  await muatDokumen()
})

watch(cabangDipilih, async () => {
  dokumenDipilih.value = []
  rincianPerDokumen.value = {}
  jenisPerDokumen.value = {}
  akunPerItem.value = {}
  kataCari.value = ''

  await muatDokumen()
})

/*
|--------------------------------------------------------------------------
| Data voucher
|--------------------------------------------------------------------------
| Isian milik BDV itu sendiri, bukan salinan dari dokumen sumbernya.
| Branch tidak ikut diisi tangan: ia mengikuti cabang dokumen yang dipilih,
| karena voucher ini mencairkan dokumen itu dan tidak mungkin berbeda
| cabangnya.
|
| BELUM DISIMPAN ke mana pun. Tahap penyimpanan BDV menyusul.
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

/*
| Penanda wajib isi ditempelkan ke teks labelnya, bukan sebagai elemen
| terpisah berwarna merah seperti pada tabel item formulir lain.
| Alasannya satu: AppDateTimePicker membungkus VField dan tidak
| meneruskan slot label, jadi hanya cara ini yang bisa seragam di
| ketiga kolom. Seragam lebih berguna daripada merah di dua dari tiga.
*/
const labelWajib = (kunci: string): string => `${t(kunci)} *`

const wajibIsi = (nilai: unknown): true | string =>
  (nilai !== null && nilai !== undefined && String(nilai).trim() !== '')
  || t('bdv.create.voucher.required')

/*
| Diambil dari dokumen, bukan diketik. Dibaca saja.
|
| Seluruh dokumen wajib satu cabang, jadi cukup membaca yang pertama --
| aturan itu dijaga tambahDokumen() dan ditegakkan ulang oleh server.
*/
const branchDokumen = computed<string | null>(() => cabangTerpilih.value)

/*
| Berganti dokumen mengosongkan pilihan akun: nomor baris pada dokumen yang
| baru tidak ada hubungannya dengan nomor baris dokumen sebelumnya, dan
| membiarkannya akan memasangkan akun ke item yang sama sekali berbeda.
*/
watch(dokumenDipilih, () => {
  /*
  | Isian voucher ikut dikosongkan. Nomor cek dan penerima yang tertinggal
  | dari dokumen sebelumnya adalah jenis kesalahan yang paling sulit
  | disadari: isinya tampak wajar, hanya saja milik dokumen yang lain.
  */
  form.kasBank = null
  form.bdvNumber = ''
  form.date = null
  form.checkNumber = ''
  form.payee = ''
  form.notes = ''
})

/*
|--------------------------------------------------------------------------
| Tampilan nilai
|--------------------------------------------------------------------------
*/
const rupiah = (nilai: number | null | undefined): string => formatUang(nilai ?? 0)

/* Qty bukan uang: tanpa lambang Rp, dan desimalnya hanya muncul bila ada. */
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

/* Dibaca oleh tahap 2 dan 3 untuk menentukan mana yang masih terkunci. */
const tahapAktif = computed<number>(() => {
  if (!departmentDipilih.value || !cabangDipilih.value || !jenisTerpilih.value)
    return 1

  if (dokumenDipilih.value.length === 0)
    return 2

  return 3
})

/*
| Warna angka di dalam lingkaran tahap.
|
| Lingkaran tahap yang sudah aktif berlatar primary pekat, jadi angkanya harus
| putih -- di tema terang maupun gelap, sebab on-primary memang #FFFFFF pada
| keduanya. Tanpa ini angkanya mewarisi warna teks halaman, yang di tema terang
| berarti hampir hitam di atas ungu.
|
| Tahap yang belum aktif berlatar tonal yang tipis. Di situ putih justru
| hilang, jadi dipakai warna teks redup yang sudah mengikuti tema.
*/
const kelasNomorTahap = (aktif: boolean): string[] => [
  'text-body-2',
  'font-weight-bold',
  aktif ? 'text-white' : 'text-medium-emphasis',
]

/*
| Kolom qty dan harga satuan hanya ditampilkan bila memang ada isinya. Item
| Claim tidak memakai keduanya, dan kolom yang selalu kosong hanya menambah
| lebar tabel tanpa menambah keterangan apa pun.
*/
/*
| Rincian dikelompokkan menurut dokumennya, bukan dirata menjadi satu daftar.
|
| Tanpa pengelompokan ini orang yang memilih akun untuk baris ke-7 tidak
| punya cara tahu baris itu milik FPU yang mana -- dan salah membebankan
| biaya ke akun dokumen lain tidak menimbulkan galat apa pun, hanya angka
| yang keliru di pembukuan.
*/
interface KelompokItem {
  public_id: string
  number: string | null
  document_type_label: string | null
  total_amount: number
  items: DokumenItem[]
}

const kelompokItem = computed<KelompokItem[]>(() => {
  const hasil: KelompokItem[] = []

  for (const dok of dokumenDipilih.value) {
    const r = rincianPerDokumen.value[dok.public_id]

    /*
     * Dokumen yang rinciannya belum/gagal dimuat dilewati, bukan ditampilkan
     * kosong: baris tanpa nominal dan tanpa akun hanya mengundang orang
     * menekan Simpan atas sesuatu yang belum terbaca.
     */
    if (!r)
      continue

    hasil.push({
      public_id: dok.public_id,
      number: r.number,
      document_type_label: r.document_type_label,
      total_amount: Number(r.total_amount ?? 0),
      items: r.items ?? [],
    })
  }

  return hasil
})

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
  kelompokItem.value.some(k =>
    k.items.some(i => i.qty !== null || i.unit_price !== null)))

const daftarItem = computed<DokumenItem[]>(() =>
  kelompokItem.value.flatMap(k => k.items))

const jumlahItem = computed<number>(() =>
  daftarItem.value.reduce((total, i) => total + Number(i.amount ?? 0), 0))

/** Nilai seluruh dokumen yang dipilih -- inilah nilai vouchernya. */
const jumlahDokumen = computed<number>(() =>
  kelompokItem.value.reduce((total, k) => total + k.total_amount, 0))

/* Baris yang akunnya belum dipilih, untuk menandainya sebelum Simpan ditekan. */
const barisTanpaAkun = computed<number>(() =>
  kelompokItem.value.reduce(
    (jumlah, k) => jumlah + k.items.filter(i => !akunPerItem.value[kunciItem(k.public_id, i.no)]).length,
    0,
  ))

/*
|--------------------------------------------------------------------------
| Simpan
|--------------------------------------------------------------------------
| Menyimpan BDV berarti sekaligus menuliskannya ke Accurate -- draft pun
| langsung masuk ke sana. Karena itu jawabannya bisa berupa tiga keadaan,
| bukan dua, dan ketiganya ditampilkan berbeda:
|
|   tersimpan + masuk Accurate   kembali ke daftar, toast HIJAU di sana
|   tersimpan + BELUM di Accurate kembali ke daftar, toast KUNING di sana
|   gagal tersimpan               toast merah, TETAP di halaman ini
|
| Dua yang pertama sama-sama meninggalkan halaman ini, dan itu disengaja:
| keduanya berarti draftnya sudah tersimpan. Menahan orang di formulir yang
| isinya sudah masuk basis data membuatnya mengira belum tersimpan sama
| sekali, lalu menekan Simpan lagi -- dan yang kedua bisa saja berhasil
| sampai Accurate, meninggalkan dua dokumen untuk satu voucher.
|
| Yang membedakan keduanya hanya warna toast-nya. Kuning tidak boleh
| ditampilkan hijau: orang yang pergi dengan keyakinan bahwa pembukuannya
| sudah menerima tidak akan pernah kembali memeriksanya.
|
| Yang belum sampai Accurate tidak hilang jejak: daftar menandai barisnya,
| menekan Simpan dari halaman ubahnya mengirim ulang, dan pengajuannya
| ditahan server sampai dokumennya benar-benar ada di sana.
|
| Kabar apa pun DITITIPKAN, tidak ditampilkan di sini. Toast yang muncul
| tepat sebelum pindah halaman ikut lenyap bersama halaman ini sebelum
| sempat terbaca; daftar BDV-lah yang menampilkannya setelah terpasang.
|
| Penegasan dulu sebelum semuanya. Simpan di sini bukan sekadar menulis ke
| basis data: dokumennya langsung lahir di pembukuan Accurate, dan tidak ada
| tombol batal untuk itu dari layar ini.
|--------------------------------------------------------------------------
*/
const formRef = ref()
const menyimpan = ref(false)

/** Akun yang dipilih untuk tiap baris, dalam bentuk yang diminta server. */
/*
| Satu entri per dokumen sumber, rinciannya menggantung di dalamnya.
|
| Bentuk ini yang diminta server, dan ia bukan sekadar pembungkus: nomor
| baris berulang antar dokumen, jadi daftar item yang rata tidak punya cara
| menyebut baris mana milik dokumen mana.
*/
const sumberUntukServer = (): Array<Record<string, unknown>> =>
  kelompokItem.value.map(k => ({
    document_type: jenisPerDokumen.value[k.public_id] ?? null,
    public_id: k.public_id,

    items: k.items.map(item => ({
      line_no: item.no,
      accurate_account_no: akunPerItem.value[kunciItem(k.public_id, item.no)]?.id ?? null,
      accurate_account_name: akunPerItem.value[kunciItem(k.public_id, item.no)]?.text ?? null,
    })),
  }))

const simpan = async (): Promise<void> => {
  if (menyimpan.value)
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
    title: t('bdv.create.voucher.confirmTitle'),
    text: t('bdv.create.voucher.confirmText'),
    confirmButtonText: t('bdv.create.voucher.confirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })

  if (!konfirmasi.isConfirmed)
    return

  menyimpan.value = true

  /*
   * Penghalang layar selama menyimpan, bukan hanya spinner pada tombolnya.
   *
   * Menyimpan BDV tidak berhenti di basis data: dokumennya langsung ditulis
   * ke Accurate, dan panggilan itulah yang lama. Tanpa penghalang, halaman
   * tampak diam dan orang menekan Simpan sekali lagi -- yang kedua lahir
   * sebagai dokumen baru di pembukuan, bukan sebagai perbaikan yang pertama.
   */
  showLoadingAlert(
    t('bdv.create.voucher.saving'),
    t('common.alert.pleaseWait'),
  )

  try {
    const { data } = await axios.post(ENDPOINT, {
      sources: sumberUntukServer(),

      bdv_number: form.bdvNumber,
      date: form.date,

      /*
       * Yang disimpan NOMOR akunnya, bukan id internal Accurate. Kolom
       * pencariannya memang memakai 'id' sebagai nilai, tetapi yang
       * diletakkan di sana oleh server adalah nomor akun.
       */
      cash_bank_account_no: form.kasBank?.id ?? null,
      cash_bank_account_name: form.kasBank?.text ?? null,

      check_number: form.checkNumber || null,
      payee: form.payee,
      notes: form.notes || null,
    }, {
      headers: { Accept: 'application/json' },
    })

    /*
     * Ditutup SEBELUM toast apa pun muncul. Penghalangnya punya lapisan yang
     * lebih tinggi daripada toast; menutupnya belakangan membuat kabar yang
     * baru saja ditampilkan tertutup olehnya.
     */
    closeAlert()

    /*
     * Tersimpan, tetapi Accurate belum menerimanya.
     *
     * Diperlakukan sama seperti berhasil: halamannya ditinggalkan, dan
     * kabarnya dititipkan ke daftar -- hanya warnanya yang berbeda. Draftnya
     * memang sudah tersimpan, dan menahan orang di formulir yang isinya
     * sudah masuk basis data membuatnya mengira belum tersimpan sama sekali.
     *
     * Vouchernya tetap bisa ditemukan: daftar menandai baris yang belum
     * sampai Accurate, dan menekan Simpan dari halaman ubahnya mengirim
     * ulang. Pengajuannya sendiri ditahan server sampai benar-benar masuk.
     *
     * Diperiksa dari adanya warning, bukan hanya dari accurate_sent: selama
     * ada kabar buruk yang perlu dibaca, ia tidak boleh tertutup toast hijau.
     */
    if (data?.warning || data?.accurate_sent === false) {
      queueToast('warning', {
        title: t('common.alert.warning'),
        text: data?.warning || t('bdv.create.voucher.accurateFailed'),
      })

      router.push('/cash_bank/bdv')

      return
    }

    /*
     * Dititipkan, bukan ditampilkan -- lihat keterangan di atas. Daftar BDV
     * yang mengeluarkannya begitu halaman itu terpasang.
     */
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
    /*
     * Penutup terakhir, untuk jalur yang tidak terduga -- galat yang terjadi
     * di antara closeAlert() dan selesainya fungsi ini. Memanggilnya dua kali
     * tidak berakibat apa-apa; meninggalkan layar terkunci berakibat banyak.
     */
    closeAlert()

    menyimpan.value = false
  }
}

const kembali = (): void => {
  router.push('/cash_bank/bdv')
}

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (!canCreate.value)
    return

  await muatJenisDokumen()

  /*
   * Menyusul jenis dokumen, bukan berbarengan: daftar kas/bank butuh kode
   * jenis akun yang baru diketahui dari jawaban permintaan di atas.
   */
  await muatKasBank()

  /*
   * Berurutan, bukan berbarengan: Accurate dibatasi sekitar delapan
   * permintaan per detik, dan dua permintaan yang berangkat bersamaan
   * dari setiap orang yang membuka halaman ini menghabiskan jatah itu
   * lebih cepat daripada yang terlihat.
   */
  await muatAkun()
})
</script>

<template>
  <div>
    <VCard v-if="permissionStore.isLoaded && !canCreate">
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
                  {{ t('bdv.create.title') }}
                </div>

                <div class="text-body-2 text-medium-emphasis mt-1">
                  {{ t('bdv.create.subtitle') }}
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
              {{ t('bdv.create.back') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>

      <VCard>
        <VCardText class="pa-5">
          <!-- ============ TAHAP 1: JENIS DOKUMEN ============ -->
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
                  DEPARTMENT, CABANG, lalu JENIS -- urutannya menentukan.

                  Daftar nomor dokumen disaring ke department DAN cabang yang
                  dipilih, sehingga yang muncul memang hanya yang boleh
                  dipilih bersama. Seluruh dokumen dalam satu BDV wajib
                  sedepartment, secabang, dan sejenis; menyaringnya sejak awal
                  jauh lebih baik daripada menolaknya setelah orangnya
                  terlanjur memilih.
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
                    :disabled="!departmentDipilih"
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
                    Terkunci begitu dokumen pertama dipilih.

                    Satu BDV hanya boleh mencairkan satu jenis dokumen, dan
                    mengunci kolomnya membuat aturan itu tidak pernah perlu
                    ditegakkan sebagai penolakan. Untuk berganti jenis,
                    dokumennya dikeluarkan dulu -- keterangannya muncul di
                    bawah kolom ini saat terkunci.
                  -->
                  <VSelect
                    v-model="jenisTerpilih"
                    :menu-props="{ maxHeight: 300, location: 'bottom', offset: 4 }"
                    :items="documentTypeOptions"
                    :label="t('bdv.create.step1.label')"
                    :disabled="!departmentDipilih || !cabangDipilih || jenisDipakai !== null"
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

                      menu-props membatasi tinggi daftar pilihan. Tanpa itu
                      daftarnya setinggi seluruh isinya, dan pada 30 dokumen
                      ia melampaui tinggi layar lalu dibalik ke atas kolom --
                      yang terlihat sebagai daftar raksasa menutupi halaman,
                      bukan dropdown di bawah kolomnya.
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
                    closable
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
                  Aturan satu cabang disebutkan sejak awal, bukan baru saat
                  dokumen kedua ditolak. Yang sudah memilih lima dokumen lalu
                  ditolak pada yang keenam harus mengulang dari awal.
                -->
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
                    {{ t('bdv.create.step3.title') }}
                  </div>

                  <div class="text-body-2 text-medium-emphasis">
                    {{ t('bdv.create.step3.subtitle') }}
                  </div>
                </div>

                <VChip
                  v-if="kelompokItem.length"
                  size="small"
                  variant="tonal"
                  color="info"
                >
                  {{ t('bdv.create.step3.count', { count: kelompokItem.length }) }}
                </VChip>
              </div>

              <VAlert
                v-if="kelompokItem.length === 0"
                type="info"
                variant="tonal"
                density="compact"
              >
                {{ t('bdv.create.step3.waiting') }}
              </VAlert>

              <!--
                Accordion, bukan seluruhnya terbuka.

                Satu BDV bisa memegang belasan dokumen, dan menampilkan rincian
                lengkap semuanya sekaligus membuat halaman ini sepanjang
                beberapa layar sebelum orangnya sampai ke kolom yang harus
                diisi. Yang perlu terlihat sekilas adalah NOMOR dan nilainya;
                sisanya dibuka bila memang hendak diperiksa.
              -->
              <VExpansionPanels
                v-else
                variant="accordion"
                class="bdv-dokumen-panel"
              >
                <VExpansionPanel
                  v-for="kelompok in kelompokItem"
                  :key="kelompok.public_id"
                >
                  <VExpansionPanelTitle>
                    <div class="d-flex align-center justify-space-between flex-wrap gap-2 w-100 pe-2">
                      <div class="d-flex align-center flex-wrap gap-2 min-width-0">
                        <VChip
                          size="x-small"
                          variant="tonal"
                          color="info"
                        >
                          {{ kelompok.document_type_label }}
                        </VChip>

                        <span class="font-weight-medium">{{ kelompok.number }}</span>

                        <span class="text-caption text-medium-emphasis">
                          {{ t('bdv.create.items.count', { count: kelompok.items.length }) }}
                        </span>
                      </div>

                      <span class="font-weight-medium text-no-wrap">
                        {{ rupiah(kelompok.total_amount) }}
                      </span>
                    </div>
                  </VExpansionPanelTitle>

                  <VExpansionPanelText>
                    <VRow v-if="rincianPerDokumen[kelompok.public_id]">
                      <VCol
                        v-for="kolom in ringkasanDokumen(rincianPerDokumen[kelompok.public_id])"
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
                        v-if="rincianPerDokumen[kelompok.public_id].notes"
                        cols="12"
                      >
                        <div class="text-caption text-medium-emphasis">
                          {{ t('bdv.create.detail.notes') }}
                        </div>

                        <div class="text-body-2">
                          {{ rincianPerDokumen[kelompok.public_id].notes }}
                        </div>
                      </VCol>
                    </VRow>
                  </VExpansionPanelText>
                </VExpansionPanel>
              </VExpansionPanels>

              <!--
                Selisih dihitung atas SELURUH dokumen. Nilainya bisa direvisi
                saat berkasnya diterima Finance, jadi selisih ini belum tentu
                keliru -- tetapi harus kelihatan.
              -->
              <VAlert
                v-if="kelompokItem.length > 0 && jumlahItem !== jumlahDokumen"
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

          <!-- ============ TAHAP 4: DATA VOUCHER ============ -->
          <template v-if="kelompokItem.length > 0">
            <VDivider class="my-6" />

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
                  {{ t('bdv.create.voucher.title') }}
                </div>

                <div class="text-body-2 text-medium-emphasis mb-4">
                  {{ t('bdv.create.voucher.subtitle') }}
                </div>

                <VForm ref="formRef">
                  <VRow>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <!--
                        attach: 'body' mengikuti kolom Kode Item Accurate di
                        po-supplier. Menu yang menempel pada body diposisikan
                        terhadap kolomnya sendiri, bukan terhadap kartu yang
                        membungkusnya -- itu yang membuatnya muncul tepat di
                        bawah kolom, bukan melayang di tempat lain.
                      -->
                      <VAutocomplete
                        v-model="form.kasBank"
                        :items="kasBankList"
                        :loading="kasBankLoading"
                        no-filter
                        item-title="text"
                        return-object
                        clearable
                        density="comfortable"
                        :label="labelWajib('bdv.create.voucher.fields.cashBank')"
                        :rules="[wajibIsi]"
                        :menu-props="{ maxHeight: 300, attach: 'body', location: 'bottom', offset: 4 }"
                        @update:search="cariKasBank"
                      >
                        <!--
                          Muncul hanya ketika pengambilan terakhir gagal.
                          Ditaruh di dalam kolomnya, bukan di luar, supaya
                          tombolnya berada tepat di tempat masalahnya
                          terlihat.
                        -->
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

                        <!-- Penanda sedang mengambil, di dalam menunya. -->
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

                        <!--
                          Nomor akunnya ikut ditampilkan di bawah namanya.
                          Nama akun di Accurate banyak yang mirip -- beberapa
                          "Petty Cash" hanya dibedakan kotanya -- dan nomor
                          akun itulah yang benar-benar membedakan.
                        -->
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
                        :label="t('bdv.create.voucher.fields.checkNumber')"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <!--
                        Wajib, dan bukan keputusan kita: Accurate mewajibkan
                        payee pada setiap Other Payment, sementara dokumen ini
                        ditulis ke sana begitu disimpan. Membiarkannya opsional
                        di layar hanya memindahkan penolakannya ke Accurate,
                        dengan pesan yang jauh lebih sulit dimengerti.
                      -->
                      <VTextField
                        v-model="form.payee"
                        density="comfortable"
                        clearable
                        :label="labelWajib('bdv.create.voucher.fields.payee')"
                        :rules="[wajibIsi]"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <!--
                        Tidak bisa diketik: cabangnya mengikuti dokumen yang
                        dipilih, dan voucher tidak mungkin beda cabang dengan
                        dokumen yang dicairkannya.
                      -->
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
                        :label="t('bdv.create.voucher.fields.notes')"
                      />
                    </VCol>
                  </VRow>
                  <!-- ============ RINCIAN ITEM ============ -->
                  <VDivider class="my-6" />

                  <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-3">
                    <div>
                      <div class="text-subtitle-1 font-weight-medium">
                        {{ t('bdv.create.items.title') }}
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
                    @click="simpan"
                  >
                    {{ t('bdv.create.voucher.saveButton') }}
                  </VBtn>
                </div>
              </div>
            </div>
          </template>
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

.min-width-0 {
  min-inline-size: 0;
}

/*
| Baris tabel ini memuat satu kolom isian, jadi butuh ruang tegak lebih
| daripada tabel yang seluruhnya teks -- tanpa ini kotak pilihan akunnya
| menempel ke garis barisnya.
*/
.bdv-item-table {
  :deep(tbody td) {
    padding-block: 10px;
  }
}

/*
| Bukti yang menempel pada satu baris rincian -- bentuknya sama persis
| dengan yang ada di halaman ubah dan di modal detail, supaya satu hal yang
| sama tidak tampil berbeda di tiga layar.
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
