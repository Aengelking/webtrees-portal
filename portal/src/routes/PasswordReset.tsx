import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, Navigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthProvider'
import { ApiError } from '../api/client'
import { Button, Field, Notice, PageHeading } from '../components/ui'

const MINIMUM_LENGTH = 8

/**
 * Set a new password from the emailed link.
 *
 * A successful reset signs the member in, so this ends on the profile rather
 * than sending them back to a login screen they have just proved they cannot
 * use.
 */
export function PasswordReset() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const { status, resetPassword } = useAuth()

  const token = params.get('token') ?? ''

  const [password, setPassword] = useState('')
  const [repeat, setRepeat] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  if (status === 'signed-in') {
    return <Navigate to="/me" replace />
  }

  if (token === '') {
    return (
      <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-10">
        <Notice
          title={t('password.missingToken.title')}
          body={t('password.missingToken.body')}
          action={
            <Link
              to="/password/request"
              className="inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white"
            >
              {t('password.missingToken.action')}
            </Link>
          }
        />
      </div>
    )
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (password.length < MINIMUM_LENGTH) {
      setError(t('password.tooShort'))
      return
    }

    if (password !== repeat) {
      setError(t('password.mismatch'))
      return
    }

    setBusy(true)
    setError(null)

    try {
      await resetPassword(token, password)
    } catch (cause) {
      if (cause instanceof ApiError && cause.code === 'invalid_token') {
        setError(t('password.expired'))
      } else if (cause instanceof ApiError && cause.code === 'network_error') {
        setError(t('error.network'))
      } else {
        setError(t('error.unknown'))
      }

      setPassword('')
      setRepeat('')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-10">
      <PageHeading>{t('password.resetTitle')}</PageHeading>
      <p className="mt-2 text-base text-slate-700">{t('password.resetIntro')}</p>

      <form onSubmit={onSubmit} noValidate className="mt-8">
        {error !== null && (
          <p role="alert" className="mb-5 rounded-lg border border-amber-400 bg-amber-50 p-4 text-base text-slate-900">
            {error}
          </p>
        )}

        <Field
          label={t('password.newPassword')}
          type="password"
          autoComplete="new-password"
          required
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />

        <Field
          label={t('password.repeatPassword')}
          type="password"
          autoComplete="new-password"
          required
          value={repeat}
          onChange={(event) => setRepeat(event.target.value)}
        />

        <Button type="submit" className="w-full" disabled={busy}>
          {busy ? t('password.saving') : t('password.save')}
        </Button>
      </form>

      <p className="mt-6">
        <Link
          to="/password/request"
          className="inline-flex min-h-[44px] items-center text-base font-semibold text-sky-800 underline underline-offset-4"
        >
          {t('password.missingToken.action')}
        </Link>
      </p>
    </div>
  )
}
