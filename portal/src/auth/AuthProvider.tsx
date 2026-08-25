import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, forgetCsrfToken, setUnauthenticatedHandler } from '../api/client'
import type { Credentials, InvitationAcceptance, Me } from '../api/types'
import i18n, { portalLanguage } from '../i18n'
import {
  disable,
  permission,
  rememberedNotifications,
  resume,
  subscriptionChanged,
} from '../pwa/notifications'

type Status = 'checking' | 'signed-in' | 'signed-out'

interface AuthContextValue {
  status: Status
  me: Me | null
  signIn: (credentials: Credentials) => Promise<void>
  signOut: () => Promise<void>
  /** A completed reset signs the member in, so it lands here rather than in a route. */
  resetPassword: (token: string, password: string) => Promise<void>
  /** Accepting an invitation creates the account and signs it in, so it lands here too. */
  acceptInvitation: (details: InvitationAcceptance) => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

/**
 * Holds the answer to one question: is someone signed in?
 *
 * Nothing is persisted. On a reload the portal asks the server, because the
 * server is the only thing that knows whether the session cookie is still
 * good — and a cookie the browser will not show us is the whole point of the
 * design.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<Status>('checking')
  const [me, setMe] = useState<Me | null>(null)
  const queryClient = useQueryClient()

  const reset = useCallback(() => {
    setMe(null)
    setStatus('signed-out')
    forgetCsrfToken()
    queryClient.clear()
  }, [queryClient])

  // Any 401, from any request, drops the portal back to signed-out. The
  // router then sends the member to /login.
  useEffect(() => {
    setUnauthenticatedHandler(reset)

    return () => {
      setUnauthenticatedHandler(null)
    }
  }, [reset])

  useEffect(() => {
    let cancelled = false

    api
      .me()
      .then((result) => {
        if (!cancelled) {
          setMe(result)
          setStatus('signed-in')
        }
      })
      .catch(() => {
        if (!cancelled) {
          setMe(null)
          setStatus('signed-out')
        }
      })

    return () => {
      cancelled = true
    }
  }, [])

  /**
   * The member's own language, from their account.
   *
   * A language is a fact about a person, not about a telephone: somebody who
   * reads English reads English on the tablet too, and on the phone they buy
   * next year. So the account decides, and the device preference is only what
   * answers before anybody is signed in.
   *
   * It runs when `me` arrives — a reload, a sign-in, an accepted invitation —
   * and never fights the switcher, which changes the account at the same time
   * as it changes the screen. A tag the portal has no translation for leaves
   * the language alone rather than dropping the member into German.
   */
  useEffect(() => {
    // Defensively addressed: a payload without a language, or without a user
    // at all, must leave the portal in the language it is already reading.
    const code = portalLanguage(me?.user?.language)

    if (code !== null && code !== i18n.language) {
      void i18n.changeLanguage(code)
    }
  }, [me?.user?.language])

  /**
   * Notifications back on, where this member had them on here.
   *
   * The other half of `forgetThisDevice()`. Signing out switches the device
   * off — see there for why it must — and a member who had switched it on had
   * to go and find the switch again on every visit. Leaving is not the same as
   * changing your mind.
   *
   * Runs whenever `me` arrives: a sign-in, an accepted invitation, and an
   * ordinary reload. The last one is not waste — a browser that dropped its
   * subscription (an update, a cleared site setting) gets it back without
   * anybody noticing it was gone.
   */
  useEffect(() => {
    const id = me?.user?.id

    if (id === undefined) {
      return
    }

    void restoreThisDevice(id).then((restored) => {
      if (!restored) {
        return
      }

      // Two audiences, and neither is optional. The switch in Settings asks
      // the *browser* whether this device is subscribed, and it asks once —
      // so it has to be told, or a member who opens Settings straight after
      // signing in reads "off" about a device that is on. And `/push` is now
      // out of date, because the row it counts was created a moment ago.
      subscriptionChanged()

      void queryClient.invalidateQueries({ queryKey: ['push'] })
    })
  }, [me?.user?.id, queryClient])

  const signIn = useCallback(
    async (credentials: Credentials) => {
      const result = await api.login(credentials)

      queryClient.clear()
      queryClient.setQueryData(['me'], result)
      setMe(result)
      setStatus('signed-in')
    },
    [queryClient],
  )

  const resetPassword = useCallback(
    async (token: string, password: string) => {
      const result = await api.resetPassword(token, password)

      queryClient.clear()
      queryClient.setQueryData(['me'], result)
      setMe(result)
      setStatus('signed-in')
    },
    [queryClient],
  )

  const acceptInvitation = useCallback(
    async (details: InvitationAcceptance) => {
      const result = await api.acceptInvitation(details)

      queryClient.clear()
      queryClient.setQueryData(['me'], result)
      setMe(result)
      setStatus('signed-in')
    },
    [queryClient],
  )

  const signOut = useCallback(async () => {
    await forgetThisDevice()
    await api.logout()
    reset()
  }, [reset])

  const value = useMemo<AuthContextValue>(
    () => ({ status, me, signIn, signOut, resetPassword, acceptInvitation }),
    [status, me, signIn, signOut, resetPassword, acceptInvitation],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

/**
 * Stop this device being knocked on, before the session that authorises it
 * goes away.
 *
 * A push subscription is not session state — it is a row against a user id and
 * an address held by the browser's push service, and neither notices a logout
 * (`PushSubscriptions::knock` never asks who is signed in). Left alone, the
 * phone keeps buzzing for an account somebody has just deliberately signed out
 * of. That leaks nothing — the notification carries nothing to leak — but on a
 * shared tablet it still announces to the next person that something arrived
 * for the last one, and the member can no longer switch it off, because the
 * switch is behind the sign-in they just left.
 *
 * Deliberately not the same as `switchOff` in Settings, which reports a
 * failure to a member standing in front of it. Here nobody asked about
 * notifications; they asked to be signed out, and that must happen whatever
 * the push service is doing. A row that outlives its browser subscription is
 * deleted by the first knock that gets a 410 anyway.
 *
 * Order matters: `DELETE /push` needs the session, so it goes before
 * `api.logout()` rather than after it.
 */
/**
 * Switch this device's notifications back on, silently, or do nothing at all.
 *
 * Four things have to be true, and each of them is somebody's decision rather
 * than a technicality: this member is the one who switched it on here
 * (`rememberedNotifications`), the browser still allows it, the family still
 * offers it, and the portal still has keys to send with. Any of them missing
 * is an answer, not an error.
 *
 * Answers whether it actually subscribed, so that the screens which show the
 * switch can be told — see the effect above.
 *
 * Silent throughout. Nobody asked for this *now* — it is the standing wish of
 * somebody who asked once — so a failure has no business interrupting a
 * sign-in, and the switch in Settings goes on saying what is actually true.
 */
async function restoreThisDevice(userId: number): Promise<boolean> {
  if (rememberedNotifications() !== userId || permission() !== 'granted') {
    return false
  }

  try {
    const state = await api.push()

    if (!state.available) {
      return false
    }

    const endpoint = await resume(state.public_key)

    if (endpoint === null) {
      return false
    }

    await api.subscribeToPush(endpoint)

    return true
  } catch {
    // See above: this was nobody's request at this moment.
    return false
  }
}

async function forgetThisDevice(): Promise<void> {
  try {
    const endpoint = await Promise.race([disable(), givingUp()])

    if (endpoint !== null) {
      await api.unsubscribeFromPush(endpoint)
    }
  } catch {
    // Signing out is the thing that was asked for. It happens regardless.
  }
}

/**
 * How long to wait for the browser before signing out anyway.
 *
 * `navigator.serviceWorker.ready` is a promise that **never settles** in a
 * browser that supports service workers and has none registered — which is any
 * tab where registration failed, and every tab in the moment before it
 * finishes. Awaiting it unbounded would leave a member who pressed *Abmelden*
 * looking at a disabled button for as long as they cared to wait.
 *
 * Nothing is lost by giving up. A push subscription cannot exist without a
 * registration, so a `ready` that does not arrive means there was no device to
 * forget in the first place.
 */
const GIVE_UP_AFTER = 3000

function givingUp(): Promise<null> {
  return new Promise((resolve) => {
    window.setTimeout(() => {
      resolve(null)
    }, GIVE_UP_AFTER)
  })
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (context === null) {
    throw new Error('useAuth must be used inside an AuthProvider')
  }

  return context
}
