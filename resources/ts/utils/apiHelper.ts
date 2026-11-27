import i18n from '@/plugins/i18n'

/*
|--------------------------------------------------------------------------
| Tenggat habis, bukan koneksi putus
|--------------------------------------------------------------------------
| Keduanya sama-sama tiba tanpa `response`, sehingga selama ini keduanya
| dijawab "Tidak dapat terhubung ke server." Untuk pengiriman berlampiran
| kalimat itu menyesatkan: koneksinya baik-baik saja, yang terjadi adalah
| unggahannya belum selesai ketika tenggatnya habis, lalu diputus di tengah
| jalan -- sehingga tidak ada yang tersimpan.
|
| Bedanya penting karena tindakannya berbeda. Koneksi putus cukup diulang;
| tenggat habis perlu lampirannya dikurangi atau dikirim bertahap, sebab
| diulang apa adanya akan habis di tenggat yang sama.
|--------------------------------------------------------------------------
*/
const pesanTanpaJawaban = (error: any): string => {
  const kode = String(error?.code ?? '')

  const tenggatHabis
    = kode === 'ECONNABORTED'
    || kode === 'ETIMEDOUT'
    || String(error?.message ?? '').toLowerCase().includes('timeout')

  return i18n.global.t(
    tenggatHabis
      ? 'common.httpStatus.uploadTimeout'
      : 'common.httpStatus.noConnection',
  )
}

/*
|--------------------------------------------------------------------------
| Jawaban 2xx BELUM TENTU berarti tersimpan
|--------------------------------------------------------------------------
| Selama ini layar menyimpulkan "berhasil" dari satu hal saja: axios tidak
| melempar. Itu lebih longgar daripada kelihatannya.
|
| Pengalihan 302 diikuti browser sampai mendarat di halaman yang menjawab
| 200, dan yang sampai ke axios adalah 200 itu -- bukan 302-nya. Gateway dan
| WAF pun lazim menjawab 200 berisi halaman HTML. Dua-duanya resolve, dan
| dua-duanya tidak menyimpan apa pun.
|
| Maka yang diperiksa bukan statusnya, melainkan JANJI YANG DIBUAT SERVER:
| success bernilai true, dan ada public_id dokumen yang benar-benar lahir.
| Jawaban yang tidak memuat keduanya diperlakukan sebagai kegagalan --
| menebak "mungkin tersimpan" lalu memindahkan halaman adalah cara terburuk
| untuk salah, sebab isian beserta lampirannya ikut hilang.
|--------------------------------------------------------------------------
*/
export class SimpanTidakTerbukti extends Error {
  constructor(pesan: string) {
    super(pesan)
    this.name = 'SimpanTidakTerbukti'
  }
}

/**
 * Memastikan jawaban server benar-benar menyatakan dokumennya tersimpan.
 *
 * Dipanggil SEBELUM halaman berpindah -- sesudah berpindah, formulirnya
 * sudah kosong dan pengisinya harus menyusun ulang seluruh lampirannya.
 *
 * @returns public_id dokumen yang tersimpan
 * @throws SimpanTidakTerbukti bila jawabannya tidak menyatakan itu
 */
export const pastikanDokumenTersimpan = (response: any): string => {
  const data = response?.data

  if (data?.success === true) {
    const publicId = data?.data?.public_id

    if (typeof publicId === 'string' && publicId !== '')
      return publicId
  }

  throw new SimpanTidakTerbukti(
    i18n.global.t('common.httpStatus.saveUnconfirmed'),
  )
}

export const getApiErrorMessage = (
  error: any,
  fallback?: string,
): string => {
  /*
  | Kegagalan yang disimpulkan layar sendiri, bukan dijawab server. Ia tidak
  | punya response, jadi tanpa cabang ini ia akan jatuh ke "Tidak dapat
  | terhubung ke server." -- kalimat yang justru menyesatkan, sebab
  | servernya menjawab dengan baik.
  */
  if (error instanceof SimpanTidakTerbukti)
    return error.message

  const status = error?.response?.status
  const data = error?.response?.data

  if (!status)
    return pesanTanpaJawaban(error)

  /*
  |--------------------------------------------------------------------------
  | Laravel ValidationException
  |--------------------------------------------------------------------------
  | `message` selalu generik ("The given data was invalid."), pesan
  | spesifiknya ada di `errors`. Utamakan pesan field pertama supaya
  | ValidationException::withMessages([...]) di backend benar-benar
  | tersampaikan ke user.
  |--------------------------------------------------------------------------
  */
  const errors = data?.errors

  if (errors && typeof errors === 'object') {
    const firstField = Object.keys(errors)[0]
    const firstMessage = firstField ? errors[firstField]?.[0] : null

    if (firstMessage && typeof firstMessage === 'string')
      return firstMessage
  }

  const message = data?.message

  if (message && typeof message === 'string')
    return message

  switch (status) {
    case 400:
      return i18n.global.t('common.httpStatus.badRequest')
    case 401:
      return i18n.global.t('common.httpStatus.sessionExpired')
    case 403:
      return i18n.global.t('common.httpStatus.forbidden')
    case 404:
      return i18n.global.t('common.httpStatus.notFound')
    case 422:
      return i18n.global.t('common.httpStatus.validationError')
    case 500:
      return i18n.global.t('common.httpStatus.serverError')
    default:
      return fallback ?? i18n.global.t('common.httpStatus.serverError')
  }
}
