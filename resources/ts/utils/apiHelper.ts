import i18n from '@/plugins/i18n'

export const getApiErrorMessage = (
  error: any,
  fallback?: string,
): string => {
  const status = error?.response?.status
  const data = error?.response?.data

  if (!status)
    return i18n.global.t('common.httpStatus.noConnection')

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
