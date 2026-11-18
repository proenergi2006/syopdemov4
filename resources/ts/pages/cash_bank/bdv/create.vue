<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

import axios from '@axios'
import { showErrorToast, showInfoToast } from '@/utils/alert'
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

interface DokumenItem {
  no: number
  date: string | null
  description: string | null

  /* Hanya terisi pada item FPU; item Claim tidak memakai qty dan harga satuan. */
  qty: number | null
  unit_price: number | null

  amount: number
  category: string | null
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
const akunPerItem = ref<Record<number, AkunAccurate | null>>({})
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

    /*
     * Kode jenis akun datang dari server, tidak ditulis di sini: bila
     * Accurate memakai kode yang berbeda, yang diubah satu baris config.
     */
    kodeJenisKasBank.value = data?.data?.cash_bank_account_type ?? ''
  }
  catch (error: unknown) {
    documentTypes.value = []

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
const dokumenTerpilih = ref<DokumenRingkas | null>(null)
const kataCari = ref('')

const batasTampil = computed(() => dokumen.value.length)

let jedaCari: ReturnType<typeof setTimeout> | null = null

/*
| Nomor urut permintaan, dengan alasan yang sama seperti di halaman daftar:
| mengetik cepat melahirkan beberapa pencarian sekaligus, dan yang berangkat
| lebih dulu tidak selalu tiba lebih dulu.
*/
let nomorPermintaanDokumen = 0

const muatDokumen = async (): Promise<void> => {
  if (!jenisTerpilih.value) {
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
  if (dokumenTerpilih.value && nilai === dokumenTerpilih.value.number)
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
const rincian = ref<DokumenRincian | null>(null)
const rincianLoading = ref(false)

const muatRincian = async (): Promise<void> => {
  if (!jenisTerpilih.value || !dokumenTerpilih.value) {
    rincian.value = null

    return
  }

  rincianLoading.value = true

  try {
    const { data } = await axios.get(`${ENDPOINT}/document-detail`, {
      headers: { Accept: 'application/json' },
      params: {
        document_type: jenisTerpilih.value,
        public_id: dokumenTerpilih.value.public_id,
      },
    })

    rincian.value = data?.data ?? null
  }
  catch (error: unknown) {
    rincian.value = null
    dokumenTerpilih.value = null

    showErrorToast({
      title: t('common.alert.error'),
      text: getApiErrorMessage(error, t('bdv.create.step3.loadFailed')),
    })

    /* Dokumennya mungkin baru saja diangkat orang lain -- daftar disegarkan. */
    await muatDokumen()
  }
  finally {
    rincianLoading.value = false
  }
}

/*
| Berganti jenis dokumen membatalkan pilihan di bawahnya. Membiarkan nomor
| dokumen FPU tetap terpilih setelah jenisnya diubah ke Claim akan
| menampilkan rincian yang tidak mungkin benar.
*/
watch(jenisTerpilih, async () => {
  dokumenTerpilih.value = null
  rincian.value = null
  kataCari.value = ''

  await muatDokumen()
})

watch(dokumenTerpilih, muatRincian)

const gantiDokumen = (): void => {
  dokumenTerpilih.value = null
  rincian.value = null
}

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

/* Diambil dari dokumen, bukan diketik. Dibaca saja. */
const branchDokumen = computed<string | null>(() => rincian.value?.branch ?? null)

/*
| Berganti dokumen mengosongkan pilihan akun: nomor baris pada dokumen yang
| baru tidak ada hubungannya dengan nomor baris dokumen sebelumnya, dan
| membiarkannya akan memasangkan akun ke item yang sama sekali berbeda.
*/
watch(rincian, () => {
  akunPerItem.value = {}

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

/* Dibaca oleh tahap 2 dan 3 untuk menentukan mana yang masih terkunci. */
const tahapAktif = computed<number>(() => {
  if (!jenisTerpilih.value)
    return 1

  if (!rincian.value)
    return 2

  return 3
})

/*
| Kolom qty dan harga satuan hanya ditampilkan bila memang ada isinya. Item
| Claim tidak memakai keduanya, dan kolom yang selalu kosong hanya menambah
| lebar tabel tanpa menambah keterangan apa pun.
*/
const adaKolomQty = computed<boolean>(() =>
  (rincian.value?.items ?? []).some(i => i.qty !== null || i.unit_price !== null))

const daftarItem = computed<DokumenItem[]>(() => rincian.value?.items ?? [])

const jumlahItem = computed<number>(() =>
  daftarItem.value.reduce((total, i) => total + Number(i.amount ?? 0), 0))

/*
|--------------------------------------------------------------------------
| Simpan
|--------------------------------------------------------------------------
| Penyimpanannya BELUM tersambung ke server. Tombolnya tetap ada dan tetap
| memvalidasi, supaya aturan wajib isinya bisa dinilai sekarang.
|--------------------------------------------------------------------------
*/
const formRef = ref()
const menyimpan = ref(false)

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

  showInfoToast({
    title: t('bdv.create.voucher.notSavedTitle'),
    text: t('bdv.create.voucher.notSavedText'),
  })
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
              <span class="text-body-2 font-weight-bold">1</span>
            </VAvatar>

            <div class="flex-grow-1">
              <div class="text-h6">
                {{ t('bdv.create.step1.title') }}
              </div>

              <div class="text-body-2 text-medium-emphasis mb-4">
                {{ t('bdv.create.step1.subtitle') }}
              </div>

              <VRow>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="jenisTerpilih"
                    :items="documentTypeOptions"
                    :label="t('bdv.create.step1.label')"
                    density="comfortable"
                    hide-details
                    prepend-inner-icon="tabler-file-text"
                  />
                </VCol>
              </VRow>
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
              <span class="text-body-2 font-weight-bold">2</span>
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
                      return-object: yang disimpan dokumennya utuh, bukan
                      id-nya. Lihat keterangan pada dokumenTerpilih.

                      menu-props membatasi tinggi daftar pilihan. Tanpa itu
                      daftarnya setinggi seluruh isinya, dan pada 30 dokumen
                      ia melampaui tinggi layar lalu dibalik ke atas kolom --
                      yang terlihat sebagai daftar raksasa menutupi halaman,
                      bukan dropdown di bawah kolomnya.
                    -->
                    <VAutocomplete
                      v-model="dokumenTerpilih"
                      v-model:search="kataCari"
                      :items="dokumen"
                      item-title="number"
                      return-object
                      :menu-props="{ maxHeight: 260, location: 'bottom' }"
                      :loading="dokumenLoading"
                      :label="t('bdv.create.step2.label')"
                      :hint="t('bdv.create.step2.hint')"
                      persistent-hint
                      density="comfortable"
                      prepend-inner-icon="tabler-hash"
                      no-filter
                      :no-data-text="kataCari
                        ? t('bdv.create.step2.emptySearch')
                        : t('bdv.create.step2.empty')"
                    />

                    <!--
                      Tanpa slot #item: pilihannya cukup menampilkan nomor saja.
                      Perihal, tanggal, dan nilainya memang tidak hilang -- ia
                      muncul lengkap pada tahap 3 begitu nomornya dipilih, jadi
                      menampilkannya juga di daftar pilihan hanya membuat tiap
                      barisnya setinggi tiga baris tanpa menambah keyakinan.
                    -->
                  </VCol>
                </VRow>

                <div
                  v-if="!dokumenLoading && batasTampil > 0 && !dokumenTerpilih"
                  class="text-caption text-medium-emphasis mt-2"
                >
                  {{ t('bdv.create.step2.limited', { count: batasTampil }) }}
                </div>
              </template>
            </div>
          </div>

          <VDivider class="my-6" />

          <!-- ============ TAHAP 3: RINCIAN ============ -->
          <div class="d-flex gap-4">
            <VAvatar
              size="32"
              :color="tahapAktif >= 3 ? 'primary' : 'secondary'"
              :variant="tahapAktif >= 3 ? 'flat' : 'tonal'"
              class="flex-shrink-0 mt-1"
            >
              <span class="text-body-2 font-weight-bold">3</span>
            </VAvatar>

            <div class="flex-grow-1 min-width-0">
              <div class="d-flex align-center justify-space-between flex-wrap gap-2">
                <div>
                  <div class="text-h6">
                    {{ t('bdv.create.step3.title') }}
                  </div>

                  <div class="text-body-2 text-medium-emphasis">
                    {{ t('bdv.create.step3.subtitle') }}
                  </div>
                </div>

                <VBtn
                  v-if="rincian"
                  size="small"
                  variant="text"
                  color="primary"
                  class="text-none"
                  prepend-icon="tabler-refresh"
                  @click="gantiDokumen"
                >
                  {{ t('bdv.create.step3.changeDocument') }}
                </VBtn>
              </div>

              <div
                v-if="rincianLoading"
                class="py-8 text-center"
              >
                <VProgressCircular
                  indeterminate
                  color="primary"
                />
              </div>

              <div
                v-else-if="!rincian"
                class="text-body-2 text-disabled mt-4"
              >
                {{ t('bdv.create.step3.waiting') }}
              </div>

              <template v-else>
                <VCard
                  variant="tonal"
                  color="primary"
                  class="mt-4"
                >
                  <VCardText>
                    <VRow>
                      <VCol
                        cols="12"
                        md="4"
                      >
                        <div class="text-caption text-medium-emphasis">
                          {{ t('bdv.create.detail.number') }}
                        </div>
                        <div class="text-body-1 font-weight-bold">
                          {{ rincian.number }}
                        </div>
                      </VCol>

                      <VCol
                        cols="12"
                        md="4"
                      >
                        <div class="text-caption text-medium-emphasis">
                          {{ t('bdv.create.detail.documentType') }}
                        </div>
                        <div class="text-body-1">
                          {{ rincian.document_type_label }}
                        </div>
                      </VCol>

                      <VCol
                        cols="12"
                        md="4"
                      >
                        <div class="text-caption text-medium-emphasis">
                          {{ t('bdv.create.detail.amount') }}
                        </div>
                        <div class="text-body-1 font-weight-bold">
                          {{ rupiah(rincian.total_amount) }}
                        </div>
                      </VCol>
                    </VRow>
                  </VCardText>
                </VCard>

                <VRow class="mt-2">
                  <VCol
                    cols="12"
                    md="4"
                  >
                    <div class="text-caption text-medium-emphasis">
                      {{ t('bdv.create.detail.date') }}
                    </div>
                    <div class="text-body-2">
                      {{ tanggal(rincian.date) }}
                    </div>
                  </VCol>

                  <VCol
                    cols="12"
                    md="8"
                  >
                    <div class="text-caption text-medium-emphasis">
                      {{ t('bdv.create.detail.subject') }}
                    </div>
                    <div class="text-body-2">
                      {{ isiAtauStrip(rincian.subject) }}
                    </div>
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <div class="text-caption text-medium-emphasis">
                      {{ t('bdv.create.detail.branch') }}
                    </div>
                    <div class="text-body-2">
                      {{ isiAtauStrip(rincian.branch) }}
                    </div>
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <div class="text-caption text-medium-emphasis">
                      {{ t('bdv.create.detail.department') }}
                    </div>
                    <div class="text-body-2">
                      {{ isiAtauStrip(rincian.department) }}
                    </div>
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <div class="text-caption text-medium-emphasis">
                      {{ t('bdv.create.detail.category') }}
                    </div>
                    <div class="text-body-2">
                      {{ isiAtauStrip(rincian.transaction_category) }}
                    </div>
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <div class="text-caption text-medium-emphasis">
                      {{ t('bdv.create.detail.requester') }}
                    </div>
                    <div class="text-body-2">
                      {{ isiAtauStrip(rincian.requester) }}
                    </div>
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <div class="text-caption text-medium-emphasis">
                      {{ t('bdv.create.detail.receivedAt') }}
                    </div>
                    <div class="text-body-2">
                      {{ tanggal(rincian.received_at) }}
                    </div>
                  </VCol>

                  <VCol
                    cols="12"
                    md="4"
                  >
                    <div class="text-caption text-medium-emphasis">
                      {{ t('bdv.create.detail.scheduledPaymentDate') }}
                    </div>
                    <div class="text-body-2">
                      {{ tanggal(rincian.scheduled_payment_date) }}
                    </div>
                  </VCol>

                  <VCol
                    v-if="rincian.notes"
                    cols="12"
                  >
                    <div class="text-caption text-medium-emphasis">
                      {{ t('bdv.create.detail.notes') }}
                    </div>
                    <div class="text-body-2">
                      {{ rincian.notes }}
                    </div>
                  </VCol>
                </VRow>
              </template>
            </div>
          </div>

          <!-- ============ TAHAP 4: DATA VOUCHER ============ -->
          <template v-if="rincian">
            <VDivider class="my-6" />

            <div class="d-flex gap-4">
              <VAvatar
                size="32"
                color="primary"
                variant="flat"
                class="flex-shrink-0 mt-1"
              >
                <span class="text-body-2 font-weight-bold">4</span>
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
                        :menu-props="{ maxHeight: 300, attach: 'body' }"
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
                      <VTextField
                        v-model="form.payee"
                        density="comfortable"
                        clearable
                        :label="t('bdv.create.voucher.fields.payee')"
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
                        {{ t('bdv.create.items.subtitle') }}
                      </div>
                    </div>

                    <VChip
                      size="small"
                      variant="tonal"
                      color="info"
                    >
                      {{ t('bdv.create.items.count', { count: daftarItem.length }) }}
                    </VChip>
                  </div>

                  <VAlert
                    v-if="daftarItem.length === 0"
                    type="info"
                    variant="tonal"
                    density="compact"
                  >
                    {{ t('bdv.create.items.empty') }}
                  </VAlert>

                  <VTable
                    v-else
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
                      </tr>
                    </thead>

                    <tbody>
                      <tr
                        v-for="item in daftarItem"
                        :key="item.no"
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
                          class="text-end"
                        >
                          {{ item.qty === null ? t('bdv.create.detail.empty') : angka(item.qty) }}
                        </td>

                        <td
                          v-if="adaKolomQty"
                          class="text-end"
                        >
                          {{ item.unit_price === null
                            ? t('bdv.create.detail.empty')
                            : rupiah(item.unit_price) }}
                        </td>

                        <td class="text-end font-weight-medium text-no-wrap">
                          {{ rupiah(item.amount) }}
                        </td>

                        <!--
                          Satu-satunya kolom yang bisa diisi di tabel ini.
                          Sisanya sengaja teks biasa: rinciannya datang dari
                          dokumen yang sudah disetujui, jadi tidak ada yang
                          boleh diubah dari sini.
                        -->
                        <td>
                          <!--
                            hide-details="auto" supaya pesan wajib isinya muncul
                            hanya ketika memang ada yang salah. Dengan
                            hide-details biasa, aturannya tetap dijalankan
                            tetapi pesannya tidak pernah terlihat -- barisnya
                            tampak baik-baik saja padahal menahan penyimpanan.
                          -->
                          <VAutocomplete
                            v-model="akunPerItem[item.no]"
                            :items="akunList"
                            :loading="akunLoading"
                            item-title="text"
                            return-object
                            clearable
                            density="compact"
                            hide-details="auto"
                            variant="outlined"
                            :rules="[wajibIsi]"
                            :label="t('bdv.create.items.columns.account')"
                            :menu-props="{ maxHeight: 300, attach: 'body' }"
                            no-filter
                            @update:search="cariAkun"
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

                            <!-- Nomor akun membedakan nama-nama yang mirip. -->
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
                      </tr>
                    </tbody>

                    <tfoot>
                      <tr>
                        <td
                          :colspan="adaKolomQty ? 6 : 4"
                          class="text-end font-weight-medium"
                        >
                          {{ t('bdv.create.items.total') }}
                        </td>

                        <td class="text-end font-weight-bold text-no-wrap">
                          {{ rupiah(jumlahItem) }}
                        </td>

                        <td />
                      </tr>
                    </tfoot>
                  </VTable>

                  <!--
                    Jumlah item tidak selalu sama dengan nilai dokumen: nilainya
                    bisa direvisi saat berkasnya diterima Finance, dan yang
                    direvisi adalah nilai dokumennya. Selisihnya disebutkan
                    supaya tidak terbaca sebagai salah hitung.
                  -->
                  <VAlert
                    v-if="daftarItem.length > 0 && jumlahItem !== rincian.total_amount"
                    type="warning"
                    variant="tonal"
                    density="compact"
                    class="mt-3"
                  >
                    {{ t('bdv.create.items.mismatch', {
                      items: rupiah(jumlahItem),
                      document: rupiah(rincian.total_amount),
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
</style>
