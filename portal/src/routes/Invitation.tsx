import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, Navigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthProvider'
import { ApiError, api } from '../api/client'
import type { InvitationPreview } from '../api/types'
import { Button, Field, Loading, Notice, PageHeading } from '../components/ui'

const MINIMUM_PASSWORD_LENGTH = 8

/**
 * Accepting an invitation: the one screen where somebody who has no account
 * gets one.
 *
 * It opens by asking the server what the invitation is for, and shows the
 * answer — the family tree and the name it was issued for — before asking for
 * anything. That is the step that lets the reader tell "this is my family's
 * portal and it knows who I am" from "this is a form on the internet", and it
 * costs one request.
 *
 * On success the member is signed in and the router takes them to their own
 * page. There is no "your account has been created, now please sign in": they
 * have just typed the password, and asking for it again is a step to fail at.
 */
export function Invitation() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const { status, acceptInvitation } = useAuth()

  const token = params.get('token') ?? ''

  const [preview, setPreview] = useState<InvitationPreview | null>(null)
  const [checking, setChecking] = useState(true)
  const [expired, setExpired] = useState(false)

  const [username, setUsername] = useState('')
  const [realName, setRealName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [repeat, setRepeat] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (token === '') {
      setChecking(false)

      return
    }

    let cancelled = false

    api
      .previewInvitation(token)
      .then((result) => {
        if (cancelled) {
          return
        }

        setPreview(result)
        // Prefilled, not fixed: both are the member's to change. The name is
        // how they are addressed, and the address is where they want mail.
        setRealName(result.invited_name ?? '')
        setEmail(result.email ?? '')
      })
      .catch(() => {
        if (!cancelled) {
          setExpired(true)
        }
      })
      .finally(() => {
        if (!cancelled) {
          setChecking(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [token])

  // Someone who is already signed in has nothing to do here. Redeeming the
  // invitation would replace the session they are already using.
  if (status === 'signed-in') {
    return <Navigate to="/me" replace />
  }

  if (checking) {
    return (
      <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-10">
        <Loading />
      </div>
    )
  }

  if (token === '' || expired || preview === null) {
    return (
      <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-10">
        <Notice
          title={t('invitation.unusable.title')}
          body={t('invitation.unusable.body')}
          action={
            <Link
              to="/login"
              className="inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white"
            >
              {t('invitation.unusable.action')}
            </Link>
          }
        />
      </div>
    )
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (password.length < MINIMUM_PASSWORD_LENGTH) {
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
      await acceptInvitation({
        token,
        username: username.trim(),
        real_name: realName.trim(),
        email: email.trim(),
        password,
      })
    } catch (cause) {
      setError(messageFor(cause, t))

      // Only the passwords are cleared. Retyping a username that was merely
      // taken, or an address the server did not like, is busywork.
      setPassword('')
      setRepeat('')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-4 py-10">
      <PageHeading>{t('invitation.title')}</PageHeading>

      <p className="mt-2 text-base text-slate-700">
        {t('invitation.intro', { tree: preview.tree.title })}
      </p>

      {preview.invited_name !== null && (
        <p className="mt-4 rounded-xl border border-slate-300 bg-white p-4 text-base text-slate-900">
          {t('invitation.invitedAs')} <strong>{preview.invited_name}</strong>
        </p>
      )}

      <form onSubmit={onSubmit} noValidate className="mt-8">
        {error !== null && (
          <p role="alert" className="mb-5 rounded-lg border border-amber-400 bg-amber-50 p-4 text-base text-slate-900">
            {error}
          </p>
        )}

        <Field
          label={t('invitation.realName')}
          autoComplete="name"
          required
          value={realName}
          onChange={(event) => setRealName(event.target.value)}
        />

        <Field
          label={t('invitation.username')}
          autoComplete="username"
          required
          value={username}
          hint={t('invitation.usernameHint')}
          onChange={(event) => setUsername(event.target.value)}
        />

        <Field
          label={t('invitation.email')}
          type="email"
          autoComplete="email"
          required
          value={email}
          hint={t('invitation.emailHint')}
          onChange={(event) => setEmail(event.target.value)}
        />

        <Field
          label={t('password.newPassword')}
          type="password"
          autoComplete="new-password"
          required
          value={password}
          hint={t('invitation.passwordHint')}
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
          {busy ? t('invitation.saving') : t('invitation.save')}
        </Button>
      </form>

      <p className="mt-6 text-base text-slate-700">{t('invitation.privacyNote')}</p>
    </div>
  )
}

/**
 * Every refusal the member can act on gets its own sentence.
 *
 * The server sends a message too, but in whatever language webtrees is
 * running in and worded for a genealogy program. The portal keys off the code
 * and says it in the member's own language, as the password screen does.
 */
function messageFor(cause: unknown, t: (key: string) => string): string {
  if (!(cause instanceof ApiError)) {
    return t('error.unknown')
  }

  switch (cause.code) {
    case 'username_taken':
      return t('invitation.usernameTaken')
    case 'email_taken':
      return t('invitation.emailTaken')
    case 'invalid_token':
      return t('invitation.unusable.body')
    case 'bad_request':
      return t('invitation.badDetails')
    case 'network_error':
      return t('error.network')
    default:
      return t('error.unknown')
  }
}
