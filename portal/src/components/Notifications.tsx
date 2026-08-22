import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import type { PushState } from '../api/types'
import { disable, enable, permission, subscribedHere } from '../pwa/notifications'
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
 */
export function Notifications() {
  const { t } = useTranslation()

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
  // switched notifications off, or this browser has no push at all.
  if (data?.available !== true || state === 'unsupported') {
    return null
  }

  async function switchOn() {
    setBusy(true)
    setFailed(null)

    try {
      const endpoint = await enable(data?.public_key ?? '')

      if (endpoint !== null) {
        await api.subscribeToPush(endpoint)
        setHere(true)
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
                <p className="mb-3 text-base font-medium text-slate-900">{t('notifications.on')}</p>
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
