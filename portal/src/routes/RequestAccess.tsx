import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ApiError, api } from '../api/client'
import { Button, Field, Notice, PageHeading } from '../components/ui'
import { LanguageSwitcher } from '../components/LanguageSwitcher'

/**
 * Asking for a way in, for the reader no list holds.
 *
 * The campaign page one door along can answer by itself, because being on the
 * family's mailing list already settles the only hard question. A notice in
 * the family magazine reaches further than that list does — people the portal
 * has never had an address for — and for them there is nothing software may
 * decide. So this form decides nothing: it writes down who somebody says they
 * are and an administrator reads it.
 *
 * **Four fields, and two of them optional.** The archive number is worth
 * asking for because this family prints it beside every name, and where it
 * names exactly one record the invitation can arrive already linked — but a
 * cousin who has never seen their own number must not be stopped by it, so it
 * is not required and neither is the note.
 *
 * **The confirmation says only that it arrived.** Not that the number matched,
 * not that the name is in the tree, not that an invitation is coming: that
 * would make this a way of asking who belongs to this family, of a portal
 * whose whole point is that the answer is nobody's business. The same rule as
 * the claim page and the password screen, worded to be true either way.
 */
export function RequestAccess() {
  const { t } = useTranslation()

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const [sending, setSending] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    // Only the two the person on the other end cannot act without: somebody to
    // address and somewhere to answer. Checked here so the field can be
    // pointed at, and again on the server, which says nothing either way.
    if (name.trim() === '' || email.trim() === '') {
      setError(t('request.missing'))
      return
    }

    setError(null)
    setSending(true)

    try {
      await api.requestAccess({
        name: name.trim(),
        email: email.trim(),
        reference: reference.trim(),
        note: note.trim(),
      })
      setSent(true)
    } catch (cause) {
      setError(cause instanceof ApiError && cause.code === 'network_error' ? t('error.network') : t('error.unknown'))
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-10">
      <PageHeading>{t('request.title')}</PageHeading>

      {sent ? (
        <div className="mt-6">
          <Notice
            title={t('request.sent.title')}
            body={t('request.sent.body')}
            action={
              <Link
                to="/login"
                className="inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white"
              >
                {t('request.backToLogin')}
              </Link>
            }
          />
        </div>
      ) : (
        <form onSubmit={(event) => void onSubmit(event)} className="mt-6">
          <p className="mb-6 text-base text-slate-700">{t('request.intro')}</p>

          <Field
            label={t('request.name')}
            hint={t('request.nameHint')}
            autoComplete="name"
            value={name}
            onChange={(event) => setName(event.target.value)}
          />

          <Field
            label={t('request.email')}
            hint={t('request.emailHint')}
            type="email"
            autoComplete="email"
            inputMode="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />

          <Field
            label={t('request.reference')}
            hint={t('request.referenceHint')}
            autoComplete="off"
            value={reference}
            onChange={(event) => setReference(event.target.value)}
          />

          <div className="mb-5">
            <label htmlFor="request-note" className="mb-2 block text-base font-medium text-slate-900">
              {t('request.note')}
            </label>
            <textarea
              id="request-note"
              rows={3}
              value={note}
              maxLength={500}
              onChange={(event) => setNote(event.target.value)}
              className="w-full rounded-lg border border-slate-400 bg-white px-4 py-3 text-base text-slate-900"
            />
            <p className="mt-2 text-base text-slate-600">{t('request.noteHint')}</p>
          </div>

          {error !== null && (
            <p role="alert" className="mb-4 text-base text-red-700">
              {error}
            </p>
          )}

          <Button type="submit" className="w-full" disabled={sending}>
            {sending ? t('request.sending') : t('request.submit')}
          </Button>

          <p className="mt-6 text-base text-slate-700">
            {t('request.haveAccount')}{' '}
            <Link to="/login" className="font-semibold text-sky-800 underline">
              {t('request.backToLogin')}
            </Link>
          </p>
        </form>
      )}

      <div className="mt-10">
        <LanguageSwitcher />
      </div>
    </div>
  )
}
