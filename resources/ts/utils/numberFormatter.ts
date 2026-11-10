/*
|--------------------------------------------------------------------------
| Format angka -- satu tempat untuk seluruh aplikasi
|--------------------------------------------------------------------------
| Pemisah ribuan koma, pemisah desimal titik: 1,250,000.75
|
| Seluruh angka di aplikasi ini melewati berkas ini. Bila suatu hari
| formatnya berubah lagi, yang diubah cukup LOCALE_ANGKA di bawah -- bukan
| puluhan halaman satu per satu.
|--------------------------------------------------------------------------
*/

/** Satu-satunya penentu format angka di aplikasi ini. */
export const LOCALE_ANGKA = 'en-GB'

/** Lambang mata uang yang dipakai di depan nominal. */
export const LAMBANG_UANG = 'Rp'

/** Pemisah desimal, dipakai saat nominal sedang diketik. */
const PEMISAH_DESIMAL = '.'

interface OpsiAngka {
  minDesimal?: number
  maxDesimal?: number
}

const keAngka = (value: unknown): number => {
  const parsed = Number(value ?? 0)

  return Number.isFinite(parsed) ? parsed : 0
}

/**
 * Angka biasa tanpa lambang mata uang: 1,250,000.75
 *
 * Desimalnya tidak dipaksa muncul. Nominal bulat tetap tampil bulat, karena
 * ",00" di belakang setiap angka hanya menambah yang harus dibaca tanpa
 * menambah yang diketahui.
 */
export const formatAngka = (
  value: number | string | null | undefined,
  options: OpsiAngka = {},
): string => {
  const { minDesimal = 0, maxDesimal = 2 } = options

  return new Intl.NumberFormat(LOCALE_ANGKA, {
    minimumFractionDigits: minDesimal,
    maximumFractionDigits: Math.max(minDesimal, maxDesimal),
  }).format(keAngka(value))
}

/**
 * Nominal uang beserta lambangnya: Rp 1,250,000
 *
 * Lambangnya ditempel sendiri, tidak lewat `style: 'currency'`. Intl dengan
 * mata uang IDR pada locale Inggris menulis "IDR", bukan "Rp" -- dan yang
 * dibaca orang di sini adalah "Rp".
 */
export const formatUang = (
  value: number | string | null | undefined,
  options: OpsiAngka = {},
): string => {
  const angka = keAngka(value)
  const tanda = angka < 0 ? '-' : ''

  return `${tanda}${LAMBANG_UANG} ${formatAngka(Math.abs(angka), options)}`
}

/*
| Pemisah tunggal itu bermakna ganda. "1,500" adalah seribu lima ratus dalam
| format aplikasi ini, tapi satu koma lima dalam format Indonesia -- teksnya
| sama persis, artinya berbeda seribu kali lipat.
|
| Yang membedakan hanya pengelompokannya: pemisah ribuan selalu diikuti tepat
| tiga digit, dan tidak pernah berdiri sendiri di ujung angka.
*/
const komaItuPemisahDesimal = (bersih: string): boolean => {
  const adaKoma = bersih.includes(',')
  const adaTitik = bersih.includes('.')

  // Dua-duanya hadir: yang paling kanan selalu pemisah desimalnya.
  if (adaKoma && adaTitik)
    return bersih.lastIndexOf(',') > bersih.lastIndexOf('.')

  if (!adaKoma)
    return false

  const berkelompokTiga = /^\d{1,3}(,\d{3})+$/.test(bersih)

  return !berkelompokTiga
}

/** Menyatukan angka menjadi satu bentuk yang bisa dibaca Number(). */
const normalkan = (bersih: string): string => {
  if (komaItuPemisahDesimal(bersih)) {
    // Gaya Indonesia: titik memisahkan ribuan, koma terakhir memisahkan desimal.
    const posisi = bersih.lastIndexOf(',')
    const utuh = bersih.slice(0, posisi).replace(/[.,]/g, '')
    const pecahan = bersih.slice(posisi + 1).replace(/[.,]/g, '')

    return `${utuh}.${pecahan}`
  }

  // Koma selalu pemisah ribuan di sini, jadi dibuang.
  const tanpaKoma = bersih.replace(/,/g, '')

  /*
  | Titik lebih dari satu hanya mungkin berarti pemisah ribuan gaya lama
  | ("1.250.000"); satu titik adalah pemisah desimal seperti format kita.
  */
  return (tanpaKoma.match(/\./g) ?? []).length > 1
    ? tanpaKoma.replace(/\./g, '')
    : tanpaKoma
}

/**
 * Kebalikan dari format di atas: "1,234.56" -> 1234.56
 *
 * Menerima apa pun yang sempat diketik atau ditempel orang, termasuk angka
 * berformat Indonesia yang ditempel dari dokumen lama.
 */
export const parseAngka = (value: string | number | null | undefined): number => {
  if (typeof value === 'number')
    return Number.isFinite(value) ? value : 0

  const teks = String(value ?? '').trim()

  if (!teks)
    return 0

  const negatif = teks.startsWith('-')
  const bersih = teks.replace(/[^\d.,]/g, '')

  if (!bersih)
    return 0

  const angka = Number(normalkan(bersih))

  if (!Number.isFinite(angka))
    return 0

  return negatif ? -angka : angka
}

interface HasilKetik {

  /** Teks yang dikembalikan ke kotak isian, sudah berpemisah. */
  formatted: string

  /** Nilai yang disimpan ke form. */
  numeric: number | null
}

interface OpsiKetik {

  /** Banyak angka desimal yang boleh diketik. 0 berarti bilangan bulat. */
  maxDesimal?: number

  /** Batas banyak digit sebelum koma, penjaga agar nominal tidak kebablasan. */
  maxDigit?: number

  /** Boleh diawali tanda minus. */
  allowNegative?: boolean

  /** Kotak kosong dibaca sebagai 0, bukan null. */
  emptyAsZero?: boolean
}

/**
 * Memasang pemisah sambil diketik.
 *
 * Inilah sebabnya nol tidak lagi kelebihan: begitu digit keenam diketik,
 * pemisahnya langsung muncul, jadi 500,000 dan 5,000,000 terbaca berbeda
 * tanpa perlu dihitung satu per satu dengan ujung jari.
 */
export const ketikAngka = (
  value: string | number | null | undefined,
  options: OpsiKetik = {},
): HasilKetik => {
  const {
    maxDesimal = 0,
    maxDigit = 15,
    allowNegative = false,
    emptyAsZero = true,
  } = options

  const teks = String(value ?? '')
  const negatif = allowNegative && teks.trim().startsWith('-')

  // Koma yang diketik orang di tengah angka diperlakukan sebagai pemisah
  // ribuan, jadi dibuang. Titik disimpan karena itulah pemisah desimalnya.
  const bersih = teks.replace(/,/g, '').replace(/[^\d.]/g, '')

  const bagian = bersih.split(PEMISAH_DESIMAL)
  let utuh = (bagian[0] ?? '').slice(0, maxDigit)
  const pecahan = bagian.slice(1).join('').slice(0, maxDesimal)

  // Nol di depan dibuang, tapi "0" sendirian dibiarkan hidup supaya orang
  // bisa mengetik "0.5" tanpa angka nolnya hilang di tengah jalan.
  utuh = utuh.replace(/^0+(?=\d)/, '')

  const adaTitik = maxDesimal > 0 && bersih.includes(PEMISAH_DESIMAL)

  if (!utuh && !adaTitik) {
    return {
      formatted: '',
      numeric: emptyAsZero ? 0 : null,
    }
  }

  if (!utuh)
    utuh = '0'

  const utuhBerpemisah = Number(utuh)
    .toLocaleString(LOCALE_ANGKA, { maximumFractionDigits: 0 })

  const tanda = negatif ? '-' : ''

  /*
  | Titik yang baru saja diketik dipertahankan walau desimalnya masih kosong.
  | Tanpa ini, titiknya terhapus sendiri tiap kali orang mengetiknya, dan
  | desimal menjadi mustahil diisi.
  */
  const formatted = adaTitik
    ? `${tanda}${utuhBerpemisah}${PEMISAH_DESIMAL}${pecahan}`
    : `${tanda}${utuhBerpemisah}`

  const numeric = Number(`${negatif ? '-' : ''}${utuh}.${pecahan || '0'}`)

  return {
    formatted,
    numeric: Number.isFinite(numeric) ? numeric : null,
  }
}

/**
 * Banyak digit di sebelah kiri karet.
 *
 * Yang dijadikan patokan adalah digit, bukan huruf ke berapa. Pemisah yang
 * baru muncul menggeser seluruh huruf di belakangnya, jadi nomor huruf tidak
 * bertahan melewati pemformatan; jumlah digit bertahan.
 */
export const digitSebelumKaret = (teks: string, karet: number): number =>
  (teks.slice(0, Math.max(0, karet)).match(/\d/g) ?? []).length

/**
 * Letak karet pada teks yang sudah diformat ulang: tepat sesudah digit ke-n.
 */
export const posisiKaret = (teks: string, digitKiri: number): number => {
  if (digitKiri <= 0)
    return 0

  let hitung = 0

  for (let i = 0; i < teks.length; i++) {
    if (/\d/.test(teks[i]))
      hitung++

    if (hitung === digitKiri)
      return i + 1
  }

  return teks.length
}
