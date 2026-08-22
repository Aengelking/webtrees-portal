import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { installStore, useInstallState } from '../pwa/install'
import { Button } from './ui'

/**
 * The offer to install, once, on the way in.
 *
 * `InstallPortal` in Settings says why a permanent offer does not sit across
 * the top of every screen: a prompt that follows a member around teaches them
 * to dismiss whatever appears there, and the next thing to appear there is
 * "no connection", which they need to read. That argument is about a *standing*
 * banner and it still holds. This is the other thing — asked **once**, after
 * signing in, and then never again on this device.
 *
 * "Never again" is the part that makes it acceptable, so it is remembered
 * before it can be shown a second time. What is stored is one flag saying the
 * question has been asked. It is a device preference in the same sense the
 * language is (§2.x): no name, no date, nothing about anybody.
 *
 * And dismissing it costs nothing, which is why the sentence says so: the
 * offer stays in Settings for good, so a member who taps "Später" — or taps it
 * by accident, which on a phone is the same thing — has not lost the app.
 */
const OFFERED_KEY = 'portal.install.offered'

/** The states where a member can act on this from where they are standing. */
const ACTIONABLE = ['ready', 'apple', 'appleOther', 'android'] as const

export function InstallPrompt() {
  const { t } = useTranslation()
  const state = useInstallState()
  const first = useRef<HTMLButtonElement>(null)

  const [asked, setAsked] = useState(() => alreadyOffered())

  const actionable = (ACTIONABLE as readonly string[]).includes(state)
  const open = !asked && actionable

  useEffect(() => {
    if (open) {
      first.current?.focus?.()
    }
  }, [open])

  if (!open) {
    return null
  }

  function dismiss() {
    remember()
    setAsked(true)
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="install-prompt-title"
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          dismiss()
        }
      }}
      className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/60 p-4 sm:items-center"
    >
      <div className="w-full max-w-md rounded-xl border border-slate-300 bg-white p-5 shadow-lg">
        <h2 id="install-prompt-title" className="text-lg font-semibold text-slate-900">
          {t('install.title')}
        </h2>

        <p className="mt-2 text-base text-slate-700">{t('install.body')}</p>

        {state === 'ready' ? (
          <div className="mt-5 flex flex-wrap gap-3">
            {/*
              The browser's own dialogue answers to the member, not to the
              portal — so this closes either way. Whether they installed it is
              between them and their browser, and the state will say so on its
              own the moment it changes.
            */}
            <Button
              ref={first}
              onClick={() => {
                void installStore.prompt().catch(() => undefined)
                dismiss()
              }}
            >
              {t('install.action')}
            </Button>

            <Button variant="secondary" onClick={dismiss}>
              {t('install.later')}
            </Button>
          </div>
        ) : (
          <>
            {/* No prompt to give — iOS has none, Android did not hand one over. */}
            <p className="mt-4 text-base text-slate-900">{t(`install.${state}`)}</p>

            <div className="mt-5">
              <Button ref={first} onClick={dismiss}>
                {t('install.understood')}
              </Button>
            </div>
          </>
        )}

        <p className="mt-4 text-sm text-slate-600">{t('install.staysInSettings')}</p>
      </div>
    </div>
  )
}

function alreadyOffered(): boolean {
  try {
    return window.localStorage.getItem(OFFERED_KEY) === '1'
  } catch {
    // Storage blocked. Asking once per visit is better than never asking, and
    // the member can still say no.
    return false
  }
}

function remember(): void {
  try {
    window.localStorage.setItem(OFFERED_KEY, '1')
  } catch {
    // Nothing to do. The offer comes back next time, which is the failure
    // worth having compared with never offering at all.
  }
}
