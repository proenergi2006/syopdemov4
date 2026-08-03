export const getApiErrorMessage = (
  error: any,
  fallback = 'Terjadi kesalahan pada server.',
): string => {
  const status = error?.response?.status
  const data = error?.response?.data

  if (!status)
    return 'Tidak dapat terhubung ke server.'

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
      return 'Permintaan tidak valid.'
    case 401:
      return 'Sesi Anda telah berakhir. Silakan login kembali.'
    case 403:
      return 'Anda tidak memiliki akses.'
    case 404:
      return 'Data tidak ditemukan.'
    case 422:
      return 'Data yang dikirim tidak valid.'
    case 500:
      return 'Terjadi kesalahan pada server.'
    default:
      return fallback
  }
}
