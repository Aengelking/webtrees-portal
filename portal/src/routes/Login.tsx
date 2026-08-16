import { useState } from 'react'
import type { FormEvent } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthProvider'
import { ApiError } from '../api/client'
import { Button, Field, Loading, PageHeading } from '../components/ui'
import { LanguageSwitcher } from '../components/LanguageSwitcher'

export function Login() {
  const { t } = useTranslation()
  const { status, signIn } = useAuth()
  const location = useLocation()

  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  if (status === 'checking') {
    return <Loading />
  }

  if (status === 'signed-in') {
    const from = (location.state as { from?: string } | null)?.from
    return <Navigate to={from ?? '/me'} replace />
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (username.trim() === '' || password === '') {
      setError(t('login.missing'))
      return
    }

    setBusy(true)
    setError(null)

    try {
      await signIn({ username: username.trim(), password })
    } catch (cause) {
      // The API returns one message for every kind of failure, on purpose.
      // The portal says the same thing back, so it cannot become a way to
      // find out which usernames exist.
      setError(
        cause instanceof ApiError && cause.code === 'network_error'
          ? t('error.network')
          : t('login.failed'),
      )
      setPassword('')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-10">
      <PageHeading>{t('app.name')}</PageHeading>
      <p className="mt-2 text-base text-slate-700">{t('login.intro')}</p>

      <form onSubmit={onSubmit} noValidate className="mt-8">
        {error !== null && (
          <p role="alert" className="mb-5 rounded-lg border border-amber-400 bg-amber-50 p-4 text-base text-slate-900">
            {error}
          </p>
        )}

        <Field
          label={t('login.username')}
          name="username"
          type="text"
          autoComplete="username"
          autoCapitalize="none"
          autoCorrect="off"
          required
          value={username}
          onChange={(event) => setUsername(event.target.value)}
        />

        <Field
          label={t('login.password')}
          name="password"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(event) => setPassword(event.target.value)}
        />

        <Button type="submit" className="w-full" disabled={busy}>
          {busy ? t('login.submitting') : t('login.submit')}
        </Button>
      </form>

      <p className="mt-6 text-base text-slate-700">{t('login.forgotten')}</p>

      <div className="mt-10">
        <LanguageSwitcher />
      </div>
    </div>
  )
}
