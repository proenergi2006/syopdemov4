export const toUpper = (value: string | null | undefined): string => {
  return String(value ?? '').toUpperCase()
}

export const toLower = (value: string | null | undefined): string => {
  return String(value ?? '').toLowerCase()
}

export const toTitleCase = (value: string | null | undefined): string => {
  return String(value ?? '')
    .toLowerCase()
    .replace(/\b\w/g, char => char.toUpperCase())
}

export const onlyNumber = (value: string | null | undefined): string => {
  return String(value ?? '').replace(/[^0-9]/g, '')
}

export const onlyAlphaNumeric = (value: string | null | undefined): string => {
  return String(value ?? '').replace(/[^a-zA-Z0-9]/g, '')
}

export const onlyAlphaNumericUpper = (value: string | null | undefined): string => {
  return String(value ?? '')
    .replace(/[^a-zA-Z0-9]/g, '')
    .toUpperCase()
}

export const trimText = (value: string | null | undefined): string => {
  return String(value ?? '').trim()
}

export const formatEmail = (value: string | null | undefined): string => {
  return String(value ?? '')
    .trim()
    .toLowerCase()
}

/**
 * Validate email format
 */
export const validateEmail = (value: string | null | undefined): boolean => {
  const email = String(value ?? '').trim()

  const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

  return regex.test(email)
}

/**
 * Email validation message
 */
export const emailValidationMessage =
  "Format email tidak valid. Contoh penulisan yang benar: contoh@email.com"

export const formatStatusPKP = (value?: string | null): string => {
  if (!value) return '-'

  const normalized = value.toLowerCase()

  const map: Record<string, string> = {
    pkp: 'PKP',
    non_pkp: 'NON PKP',
  }

  return map[normalized] ?? value
}

export const formatKategoriVendor = (value?: string | null): string => {
  if (!value) return '-'

  const normalized = value.toLowerCase()

  const map: Record<string, string> = {
    trading: 'TRADING',
    non_trading: 'NON TRADING'
  }

  return map[normalized] ?? value
}