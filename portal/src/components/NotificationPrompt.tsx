import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import type { PushState } from '../api/types'
import { useInstallState } from '../pwa/install'
import { enable, permission } from '../pwa/notifications'
import { Button, ErrorNotice } from './ui'

/**
 * The offer to be told, once, inside the app that can tell them.
 *
 * The moment worth asking is the first run of the installed app, and not
 * before: notifications only reach a member who has the app on their home
 * screen — on iOS that is the *only* way they work at all — so asking in a
 * browser tab is asking for something the member cannot have yet. `standalone`
 * is what says the app is installed and this window is it.
 *
 * Everything that made the install offer acceptable applies here unchanged and
 * for the same reasons: asked **once**, answered in one tap, remembered before
 * it can be asked again, and costing nothing to refuse because the switch
 * stays in Settings. See `InstallPrompt`, which this deliberately mirrors —
 * two dialogues that behave differently would be two dialogues to learn.
 *
 * **And it says what a lock screen will show before it asks for anything.**
 * That sentence is not decoration: the push carries no payload, so the
 * notification says a message arrived and never who from — the condition this
 * whole feature was built under (§2.36). A member deciding this on the sofa
 * next to somebody is entitled to know that before the browser's own
 * permission box appears, not after.
 */
const OFFERED_KEY = 'portal.notifications.offered'

export function NotificationPrompt() {
  const { t } = useTranslation()
  const installed = useInstallState() === 'standalone'

  const { data, refetch } = useQuery<PushState>({
    queryKey: ['push'],
    queryFn: ({ signal }) => api.push(signal),
    // Nothing to ask about outside the installed app, so nothing to fetch.
    enabled: installed,
  })

  const [asked, setAsked] = useState(() => alreadyOffered())
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState<unknown>(null)

  // `askable` is the only state worth a dialogue. Granted means this device is
  // already subscribed or about to be from Settings; blocked can only be undone
  // in the browser's own settings, and a dialogue that says so is a dialogue
  // nobody asked for.
  const open =
    !asked && installed && data?.available === true && data.public_key !== '' && permission() === 'askable'

  if (!open) {
    return null
  }

  function dismiss() {
    remember()
    setAsked(true)
  }

  async function switchOn() {
    setBusy(true)
    setFailed(null)

    try {
      const endpoint = await enable(data?.public_key ?? '')

      // Null means the member said no to the browser's own question, which is
      // an answer. Either way this dialogue is finished.
      if (endpoint !== null) {
        await api.subscribeToPush(endpoint)
      }

      dismiss()
    } catch (cause) {
      // Shown here rather than swallowed: the member asked for something and
      // it did not happen. The dialogue stays so they can try again or leave.
      setFailed(cause)
    } finally {
      setBusy(false)
      void refetch()
    }
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="notification-prompt-title"
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          dismiss()
        }
      }}
      className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/60 p-4 sm:items-center"
    >
      <div className="w-full max-w-md rounded-xl border border-slate-300 bg-white p-5 shadow-lg">
        <h2 id="notification-prompt-title" className="text-lg font-semibold text-slate-900">
          {t('notifications.title')}
        </h2>

        <p className="mt-2 text-base text-slate-700">{t('notifications.body')}</p>

        {/* Before the browser's box, not after it. */}
        <p className="mt-3 text-base text-slate-900">{t('notifications.privacy')}</p>

        {failed !== null && (
          <div className="mt-4">
            <ErrorNotice error={failed} />
          </div>
        )}

        <div className="mt-5 flex flex-wrap gap-3">
          <Button disabled={busy} onClick={() => void switchOn()}>
            {busy ? t('notifications.working') : t('notifications.switchOn')}
          </Button>

          <Button variant="secondary" disabled={busy} onClick={dismiss}>
            {t('install.later')}
          </Button>
        </div>

        <p className="mt-4 text-sm text-slate-600">{t('install.staysInSettings')}</p>
      </div>
    </div>
  )
}

function alreadyOffered(): boolean {
  try {
    return window.localStorage.getItem(OFFERED_KEY) === '1'
  } catch {
    return false
  }
}

function remember(): void {
  try {
    window.localStorage.setItem(OFFERED_KEY, '1')
  } catch {
    // Asking again next time is the failure worth having, as with the install
    // offer. Nothing is lost either way — the switch is in Settings.
  }
}
