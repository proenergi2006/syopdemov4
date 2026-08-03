import { createI18n } from 'vue-i18n'
import en from './locales/en'
import id from './locales/id'

export const SUPPORTED_LOCALES = ['id', 'en'] as const

export type AppLocale = typeof SUPPORTED_LOCALES[number]

export const DEFAULT_LOCALE: AppLocale = 'id'

const LOCALE_STORAGE_KEY = 'app-locale'

function isSupportedLocale(value: string | null): value is AppLocale {
  return SUPPORTED_LOCALES.includes(value as AppLocale)
}

export function resolveInitialLocale(): AppLocale {
  if (typeof window === 'undefined')
    return DEFAULT_LOCALE

  const stored = window.localStorage.getItem(LOCALE_STORAGE_KEY)

  return isSupportedLocale(stored) ? stored : DEFAULT_LOCALE
}

export function persistLocale(locale: AppLocale): void {
  if (typeof window === 'undefined')
    return

  window.localStorage.setItem(LOCALE_STORAGE_KEY, locale)
}

export default createI18n({
  legacy: false,
  locale: resolveInitialLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  messages: {
    id,
    en,
  },
})
