import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthProvider'
import { useUpdateProfile } from '../api/queries'
import { LANGUAGES } from '../i18n'
import { ErrorNotice } from './ui'

/**
 * Two languages, both always visible. A dropdown would hide the option a
 * member needs precisely when they cannot read the label on it.
 *
 * **The choice goes to the account, not to the telephone.** A language is a
 * fact about a person: somebody who reads English reads English on the tablet
 * too, and on whatever they buy next year. So the button changes the screen
 * *and* saves the choice — `PATCH /me/profile`, the same preference webtrees'
 * own account page sets, which is also what decides the language a
 * notification from webtrees reaches them in.
 *
 * The screen changes first and does not wait for the server. The member asked
 * for English; making them look at German until a round trip finishes would be
 * the wrong way round, and if the save fails they are told so with the portal
 * already in the language they asked for.
 *
 * Signed out — the login screen — there is no account to save it to, and the
 * device preference is the whole answer.
 */
export function LanguageSwitcher() {
  const { t, i18n } = useTranslation()
  const { me } = useAuth()
  const mutation = useUpdateProfile()

  function choose(code: string): void {
    void i18n.changeLanguage(code)

    if (me !== null) {
      mutation.mutate({ language: code })
    }
  }

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
              onClick={() => choose(language.code)}
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

      <p className="mt-2 text-base text-slate-700">
        {me === null ? t('settings.languageHint') : t('settings.languageAccountHint')}
      </p>

      {/*
        A failed save is worth saying: the screen is in the new language, so
        nothing looks wrong, and the member would otherwise find German again
        on their next telephone with no idea why.
      */}
      {mutation.isError && (
        <div className="mt-3">
          <ErrorNotice error={mutation.error} />
        </div>
      )}
    </div>
  )
}
