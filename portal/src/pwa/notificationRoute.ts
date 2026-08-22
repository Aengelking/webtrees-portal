import { useEffect } from 'react'
import { useNavigate } from 'react-router-dom'

/**
 * The app's half of a tapped notification.
 *
 * The service worker cannot route a React app — see `sw/notify.ts` for the
 * attempt that could not and why it failed silently. What it can do is say
 * where the member wanted to go, and this listens for that and goes there.
 *
 * A client-side navigation rather than a reload: the member has just been told
 * a message arrived, and the difference between arriving in one render and
 * waiting out a full page load is most of what taps a notification for.
 */
export const NAVIGATE_MESSAGE = 'portal:navigate'

export function useNotificationRoute(): void {
  const navigate = useNavigate()

  useEffect(() => {
    if (!('serviceWorker' in navigator)) {
      return
    }

    // Held rather than looked up again on the way out: `navigator` is not
    // guaranteed to be the same object when the effect is torn down, and a
    // cleanup that throws takes the unmount with it.
    const worker = navigator.serviceWorker

    function onMessage(event: MessageEvent) {
      const message: unknown = event.data

      if (!isNavigation(message)) {
        return
      }

      void navigate(message.path)
    }

    worker.addEventListener('message', onMessage)

    return () => worker.removeEventListener('message', onMessage)
  }, [navigate])
}

/**
 * A page hears from more than its own service worker — extensions and embedded
 * frames post messages too — so this is a shape check rather than a cast, and
 * the path is checked as well as the type.
 *
 * `//elsewhere.example` is a URL, not a path: it begins with a slash and would
 * take a member off this site. That is the whole reason this is not a
 * `startsWith('/')`.
 */
function isNavigation(message: unknown): message is { type: string; path: string } {
  if (typeof message !== 'object' || message === null) {
    return false
  }

  const candidate = message as { type?: unknown; path?: unknown }

  return (
    candidate.type === NAVIGATE_MESSAGE &&
    typeof candidate.path === 'string' &&
    candidate.path.startsWith('/') &&
    !candidate.path.startsWith('//')
  )
}
