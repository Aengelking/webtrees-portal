import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import type { PushState } from '../api/types'
import { useInstallState } from '../pwa/install'
import {
  disable,
  enable,
  forgetNotifications,
  permission,
  rememberNotifications,
  subscribedHere,
} from '../pwa/notifications'
import { Button, Card, ErrorNotice, Section } from './ui'

/**
 * Being told that a message arrived, without having to open the app to look.
 *
 * **What a lock screen says is fixed and says nothing.** The push carries no
 * payload at all, so the notification reads "you have a new message" and
 * never a name or a word of what was written — see `sw/service-worker.ts` and
 * the module's `PushSubscriptions`. That is stated on this screen too, because
 * a member deciding whether to switch this on is entitled to know what the
 * person next to them on the sofa will be able to read.
 *
 * Three refusals are possible and each gets its own sentence rather than a
 * dead button: a browser that cannot do this at all, a family that has
 * switched it off, and a member who once said no — which only they can undo,
 * in the browser's own settings.
 *
 * The fourth case is not a refusal and took the longest to see. On an iPhone
 * or iPad there is no push API in a Safari tab at all — it exists only for a
 * web app that has been put on the home screen — so `permission()` answers
 * `unsupported` for the members most likely to be reading this, and the
 * section used to disappear. §2.33's rule says silence is right for something
 * impossible and wrong for something merely harder, and this is merely
 * harder: the way to notifications is one section up the same screen. So it
 * is said, rather than left to be guessed at.
 */
/**
 * @param account Whose wish to remember when this device is switched on — see
 *                `rememberNotifications`. Passed in rather than read from a
 *                context or a second query: the screen that renders this
 *                already has the member in hand, and a component that asks for
 *                the whole session to answer one number is a component that
 *                cannot be rendered on its own.
 */
export function Notifications({ account }: { account?: number }) {
  const { t } = useTranslation()
  const install = useInstallState()

  const { data, isError, error, refetch } = useQuery<PushState>({
    queryKey: ['push'],
    queryFn: ({ signal }) => api.push(signal),
  })

  // About this device, which the server cannot see: the account may be
  // subscribed on a phone while this tablet is not.
  const [here, setHere] = useState(false)
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState<unknown>(null)

  useEffect(() => {
    void subscribedHere().then(setHere)
  }, [data?.subscribed])

  const state = permission()

  if (data === undefined && !isError) {
    return null
  }

  if (isError) {
    return (
      <Section title={t('notifications.title')}>
        <ErrorNotice error={error} onRetry={() => void refetch()} />
      </Section>
    )
  }

  // Nothing on offer and nothing the member could do about it: the family has
  // switched notifications off, or the portal has no keys to send with.
  if (data?.available !== true) {
    return null
  }

  if (state === 'unsupported') {
    // Both iOS states, Safari and not: an iPhone or iPad in a tab, where
    // installing is the whole difference between no push and push. Every
    // other browser that lands here — an old iOS that is already
    // `standalone`, a desktop without the API — genuinely cannot, and silence
    // is right for that.
    if (install !== 'apple' && install !== 'appleOther') {
      return null
    }

    return (
      <Section title={t('notifications.title')}>
        <Card>
          <p className="text-base text-slate-700">{t('notifications.body')}</p>
          <p className="mt-3 text-base text-slate-900">{t('notifications.needsInstall')}</p>
        </Card>
      </Section>
    )
  }

  async function switchOn() {
    setBusy(true)
    setFailed(null)

    try {
      const endpoint = await enable(data?.public_key ?? '')

      if (endpoint !== null) {
        await api.subscribeToPush(endpoint)
        setHere(true)

        // The wish, so that signing out does not undo the decision along with
        // the subscription. See `rememberNotifications`.
        if (account !== undefined) {
          rememberNotifications(account)
        }
      }
    } catch (cause) {
      setFailed(cause)
    } finally {
      setBusy(false)
      void refetch()
    }
  }

  async function switchOff() {
    setBusy(true)
    setFailed(null)

    try {
      const endpoint = await disable()

      if (endpoint !== null) {
        await api.unsubscribeFromPush(endpoint)
      }

      // *This* is changing your mind, as opposed to signing out — so the wish
      // goes with the subscription and nothing switches itself back on.
      forgetNotifications()

      setHere(false)
    } catch (cause) {
      setFailed(cause)
    } finally {
      setBusy(false)
      void refetch()
    }
  }

  return (
    <Section title={t('notifications.title')}>
      <Card>
        <p className="text-base text-slate-700">{t('notifications.body')}</p>

        {/* The one thing somebody deciding this needs to know. */}
        <p className="mt-3 text-base text-slate-900">{t('notifications.privacy')}</p>

        {failed !== null && (
          <div className="mt-4">
            <ErrorNotice error={failed} />
          </div>
        )}

        {state === 'blocked' ? (
          <p className="mt-4 text-base text-slate-900">{t('notifications.blocked')}</p>
        ) : (
          <div className="mt-4">
            {here ? (
              <>
                <p className="mb-1 text-base font-medium text-slate-900">{t('notifications.on')}</p>
                {/* Signing out switches the device off (`forgetThisDevice`)
                    and signing in switches it back on (`restoreThisDevice`).
                    Said here rather than left to be discovered — in both
                    directions, because the second is the surprising one. */}
                <p className="mb-3 text-base text-slate-700">{t('notifications.untilSignOut')}</p>
                <Button variant="secondary" disabled={busy} onClick={() => void switchOff()}>
                  {t('notifications.switchOff')}
                </Button>
              </>
            ) : (
              <Button disabled={busy} onClick={() => void switchOn()}>
                {busy ? t('notifications.working') : t('notifications.switchOn')}
              </Button>
            )}
          </div>
        )}
      </Card>
    </Section>
  )
}
