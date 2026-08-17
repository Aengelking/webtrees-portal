import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import { setRequestLanguage } from '../api/client'
import { de } from './de'
import { en } from './en'

export const LANGUAGES = [
  { code: 'de', label: 'Deutsch' },
  { code: 'en', label: 'English' },
] as const

export type LanguageCode = (typeof LANGUAGES)[number]['code']

/**
 * The chosen language is the only thing the portal keeps in localStorage. It
 * is a device preference, not personal data — no name, date or place ever
 * goes near browser storage.
 */
const STORAGE_KEY = 'portal.language'

function isLanguage(value: string | null): value is LanguageCode {
  return LANGUAGES.some((language) => language.code === value)
}

export function storedLanguage(): LanguageCode {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY)

    if (isLanguage(stored)) {
      return stored
    }
  } catch {
    // Storage can be unavailable (private mode, blocked cookies). German is
    // the default anyway.
  }

  // German by default, deliberately — not the browser's language. This is a
  // German family's portal, and the switcher is one tap away on every screen
  // for the members who want English.
  return 'de'
}

export function rememberLanguage(code: LanguageCode): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, code)
  } catch {
    // Not being able to remember the choice is not worth an error message.
  }
}

void i18n.use(initReactI18next).init({
  resources: {
    de: { translation: de },
    en: { translation: en },
  },
  lng: storedLanguage(),
  fallbackLng: 'de',
  interpolation: { escapeValue: false },
})

i18n.on('languageChanged', (language) => {
  if (isLanguage(language)) {
    rememberLanguage(language)
    document.documentElement.lang = language
    // The server renders fact labels and dates, so it has to be told too.
    setRequestLanguage(language)
  }
})

document.documentElement.lang = i18n.language
setRequestLanguage(i18n.language)

export default i18n
