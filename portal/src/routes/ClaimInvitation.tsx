import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ApiError, api } from '../api/client'
import { Button, Field, Notice, PageHeading } from '../components/ui'
import { LanguageSwitcher } from '../components/LanguageSwitcher'

/**
 * Where the letter to the mailing list points.
 *
 * The link that brought somebody here grants nothing, and neither does this
 * page. One field, their own address, and the personal invitation goes to that
 * address if it is on one of the family's lists — so the thing that proves who
 * they are is access to their own mailbox, which is the one thing a forwarded
 * round-robin letter cannot pass on.
 *
 * **The confirmation is shown whenever the request was accepted, which is
 * always.** The server deliberately will not say whether the address belongs to
 * the family, and this screen must not undo that by saying it instead. So there
 * is no "not on the list" state here to write, and the sentence is worded to be
 * true either way: if you belong on it, the letter is on its way.
 *
 * The same shape as `PasswordRequest`, for the same reason and almost word for
 * word — which is deliberate: two screens that keep a secret should not look
 * like one of them is trying harder.
 *
 * **The language can be chosen here, as on the login screen.** This is the
 * first page of the portal a member ever sees, reached from a letter and not
 * from an account, so there is no stored preference to read them in: German is
 * simply what the device defaults to. Somebody who reads English would
 * otherwise have to fill in a German form to ask for an invitation — and then
 * receive that invitation in German too, because the chosen language travels
 * with the request (`Accept-Language`) and decides the language the letter is
 * written in.
 */
export function ClaimInvitation() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const campaign = params.get('aktion') ?? ''

  const [email, setEmail] = useState('')
  const [sending, setSending] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (email.trim() === '') {
      setError(t('claim.missing'))
      return
    }

    setError(null)
    setSending(true)

    try {
      await api.claimInvitation(campaign, email.trim())
      setSent(true)
    } catch (cause) {
      setError(cause instanceof ApiError && cause.code === 'network_error' ? t('error.network') : t('error.unknown'))
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-10">
      <PageHeading>{t('claim.title')}</PageHeading>

      {sent ? (
        <div className="mt-6">
          <Notice
            title={t('claim.sent.title')}
            body={t('claim.sent.body')}
            action={
              <Link
                to="/login"
                className="inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white"
              >
                {t('claim.backToLogin')}
              </Link>
            }
          />
        </div>
      ) : (
        <form onSubmit={(event) => void onSubmit(event)} className="mt-6">
          <p className="mb-6 text-base text-slate-700">{t('claim.intro')}</p>

          <Field
            label={t('claim.email')}
            hint={t('claim.emailHint')}
            type="email"
            autoComplete="email"
            inputMode="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />

          {error !== null && (
            <p role="alert" className="mb-4 text-base text-red-700">
              {error}
            </p>
          )}

          <Button type="submit" className="w-full" disabled={sending}>
            {sending ? t('claim.sending') : t('claim.submit')}
          </Button>

          {/*
            The way on for somebody this page cannot help.

            An address on none of the family's lists gets the same answer as
            one that is on them, which is the point — but it also means this
            screen is a dead end for a reader the lists have never held, and a
            notice in the family magazine reaches plenty of those. Offered to
            everybody, before they try and hear nothing: who it is *for* is
            exactly what this page must not appear to know.
          */}
          <p className="mt-6 text-base text-slate-700">
            {t('claim.noMail')}{' '}
            <Link to="/zugang" className="font-semibold text-sky-800 underline">
              {t('claim.askInstead')}
            </Link>
          </p>

          <p className="mt-4 text-base text-slate-700">
            {t('claim.haveAccount')}{' '}
            <Link to="/login" className="font-semibold text-sky-800 underline">
              {t('claim.backToLogin')}
            </Link>
          </p>
        </form>
      )}

      {/*
        At the foot of the page, in both states, exactly where the login
        screen keeps it — the two screens are one step apart and the switch
        should not move between them.
      */}
      <div className="mt-10">
        <LanguageSwitcher />
      </div>
    </div>
  )
}
