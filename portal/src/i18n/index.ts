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
 *
 * **It is no longer where the answer comes from once somebody is signed in.**
 * A member has one language, not one per telephone, so the account is the
 * source of truth (`AuthProvider`, and `PATCH /me/profile`). What is left here
 * is the answer for the moment before the portal knows who is reading: the
 * login screen, the first paint after a reload, an expired session. Getting
 * that wrong for one screen is a small thing; asking the member to choose
 * their language again on every device is not.
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

/**
 * The portal's own code for a webtrees language tag, or null for a language
 * the portal does not have.
 *
 * webtrees speaks in tags — "de", "en-US", "en-GB" — and the portal has two
 * translations. A member whose account says "en-GB" reads the English portal;
 * one whose account says "fr" reads the German one, because that is the
 * portal's default and there is nothing else to give them.
 */
export function portalLanguage(tag: string | null | undefined): LanguageCode | null {
  if (tag === null || tag === undefined) {
    return null
  }

  const code = tag.toLowerCase().split('-')[0] ?? ''

  return LANGUAGES.some((language) => language.code === code) ? (code as LanguageCode) : null
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
