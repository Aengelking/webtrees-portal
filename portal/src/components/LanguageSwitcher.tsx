import { useTranslation } from 'react-i18next'
import { LANGUAGES } from '../i18n'

/**
 * Two languages, both always visible. A dropdown would hide the option a
 * member needs precisely when they cannot read the label on it.
 */
export function LanguageSwitcher() {
  const { t, i18n } = useTranslation()

  return (
    <div>
      <p className="mb-2 text-base font-medium text-slate-900" id="language-label">
        {t('settings.language')}
      </p>
      <div role="group" aria-labelledby="language-label" className="flex gap-3">
        {LANGUAGES.map((language) => {
          const active = i18n.language === language.code

          return (
            <button
              key={language.code}
              type="button"
              lang={language.code}
              aria-pressed={active}
              onClick={() => void i18n.changeLanguage(language.code)}
              className={[
                'min-h-[44px] flex-1 rounded-lg border px-4 py-3 text-base font-semibold',
                'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700',
                active
                  ? 'border-sky-800 bg-sky-800 text-white'
                  : 'border-slate-400 bg-white text-slate-900 hover:bg-slate-100',
              ].join(' ')}
            >
              {language.label}
            </button>
          )
        })}
      </div>
      <p className="mt-2 text-base text-slate-700">{t('settings.languageHint')}</p>
    </div>
  )
}
