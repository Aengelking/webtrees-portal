import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useRequestPasswordReset } from '../api/queries'
import { ApiError } from '../api/client'
import { Button, Field, Notice, PageHeading } from '../components/ui'

/**
 * Ask for a reset link.
 *
 * The confirmation is shown whenever the request was accepted, which is
 * always — the server deliberately does not say whether the address belongs
 * to an account, and neither does this screen. Saying "no such address" here
 * would undo the server's care.
 */
export function PasswordRequest() {
  const { t } = useTranslation()
  const mutation = useRequestPasswordReset()

  const [email, setEmail] = useState('')
  const [error, setError] = useState<string | null>(null)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (email.trim() === '') {
      setError(t('password.tooShort'))
      return
    }

    setError(null)

    try {
      await mutation.mutateAsync(email.trim())
    } catch (cause) {
      setError(cause instanceof ApiError && cause.code === 'network_error' ? t('error.network') : t('error.unknown'))
    }
  }

  return (
    <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-10">
      <PageHeading>{t('password.requestTitle')}</PageHeading>

      {mutation.isSuccess ? (
        <div className="mt-6">
          <Notice
            title={t('password.sent.title')}
            body={t('password.sent.body')}
            action={
              <Link
                to="/login"
                className="inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white"
              >
                {t('password.backToLogin')}
              </Link>
            }
          />
        </div>
      ) : (
        <>
          <p className="mt-2 text-base text-slate-700">{t('password.requestIntro')}</p>

          <form onSubmit={onSubmit} noValidate className="mt-8">
            {error !== null && (
              <p role="alert" className="mb-5 rounded-lg border border-amber-400 bg-amber-50 p-4 text-base text-slate-900">
                {error}
              </p>
            )}

            <Field
              label={t('password.email')}
              type="email"
              autoComplete="email"
              autoCapitalize="none"
              required
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />

            <Button type="submit" className="w-full" disabled={mutation.isPending}>
              {mutation.isPending ? t('password.sending') : t('password.send')}
            </Button>
          </form>

          <p className="mt-6">
            <Link to="/login" className="inline-flex min-h-[44px] items-center text-base font-semibold text-sky-800 underline underline-offset-4">
              {t('password.backToLogin')}
            </Link>
          </p>
        </>
      )}
    </div>
  )
}
