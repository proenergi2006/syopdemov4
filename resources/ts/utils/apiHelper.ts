export const getApiErrorMessage = (
  error: any,
  fallback = 'Terjadi kesalahan pada server.'
): string => {
  const status = error?.response?.status
  const message = error?.response?.data?.message

  if (!status) {
    return 'Tidak dapat terhubung ke server.'
  }

  if (message && typeof message === 'string') {
    return message
  }

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