import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, Navigate, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { useAuth } from '../auth/AuthProvider'
import { api, ApiError } from '../api/client'
import type { CsrfToken } from '../api/types'
import { Button, Field, Loading, PageHeading, Toggle } from '../components/ui'
import { LanguageSwitcher } from '../components/LanguageSwitcher'

export function Login() {
  const { t } = useTranslation()
  const { status, signIn } = useAuth()
  const location = useLocation()

  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  /**
   * Asked for here rather than when the form is submitted, because the answer
   * decides whether there is a switch to draw. It warms the CSRF token on the
   * way past, which the submit needed anyway.
   *
   * A failure is not reported: the member came here to sign in, and "we could
   * not find out whether you may stay signed in" is not a sentence worth
   * putting in front of them. No answer means no offer, and signing in still
   * works.
   */
  const { data: portal } = useQuery<CsrfToken>({
    queryKey: ['csrf'],
    queryFn: () => api.csrf(),
    retry: false,
    staleTime: Infinity,
  })

  const rememberDays = portal?.remember_days ?? 0

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
      await signIn({ username: username.trim(), password, remember })
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
      {/*
        The one screen that has to say whose portal this is. It is reached by
        people who followed a link in an email, so the arms do the work a
        letterhead would: /icon.svg is the same file the browser uses for the
        tab and the home screen, referenced rather than redrawn.
      */}
      <img src="/icons/icon.svg" alt="" aria-hidden="true" width="56" height="56" className="mb-5 h-14 w-14" />

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

        {/*
          Only where the family has switched it on, and it says how long for.
          A switch that promises "stay signed in" without saying until when is
          a promise the member cannot check.
        */}
        {rememberDays > 0 && (
          <div className="mb-6">
            <Toggle
              label={t('login.remember')}
              hint={t('login.rememberHint', { count: rememberDays })}
              checked={remember}
              disabled={busy}
              onChange={setRemember}
            />
          </div>
        )}

        <Button type="submit" className="w-full" disabled={busy}>
          {busy ? t('login.submitting') : t('login.submit')}
        </Button>
      </form>

      <p className="mt-6">
        <Link
          to="/password/request"
          className="inline-flex min-h-[44px] items-center text-base font-semibold text-sky-800 underline underline-offset-4"
        >
          {t('login.forgotten')}
        </Link>
      </p>

      <div className="mt-10">
        <LanguageSwitcher />
      </div>
    </div>
  )
}
