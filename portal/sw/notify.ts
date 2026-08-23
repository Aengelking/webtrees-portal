/**
 * What happens when a member taps the notification.
 *
 * The obvious version of this is `client.navigate('/messages')` on whatever
 * window is open, and it is the version that shipped. It does not work, and
 * the way it fails is the point: **`navigate()` is only allowed on a client
 * the service worker controls**, and `matchAll({includeUncontrolled: true})`
 * deliberately returns clients it does not — a window loaded before this
 * worker took over, one claimed by another registration. On those it rejects.
 * The rejection was swallowed, `focus()` ran anyway, and the app came up
 * exactly where the member had left it. Which is what was reported: it opens,
 * and nothing moves.
 *
 * So the running app is *asked* rather than steered. It is a React app with a
 * router; a message it can act on is both more reliable than a navigation
 * imposed from outside and cheaper — no reload, no second render of a screen
 * the member is already looking at. The window that has no listener (an old
 * build still running) is simply focused, which is no worse than before.
 *
 * Kept out of `service-worker.ts` for the same reason as `strategy.ts`: this
 * is the part worth testing, and it can be tested only if it does not need a
 * service worker to run.
 */

/** Where a notification leads. No payload travels, so it is the list, not one message. */
export const MESSAGES_PATH = '/messages'

/** What the app listens for. Namespaced: a page hears every extension's messages too. */
export const NAVIGATE_MESSAGE = 'portal:navigate'

/** The window-ish parts of a `WindowClient` this needs. */
export interface Focusable {
  url: string
  focus: () => Promise<unknown>
  postMessage: (message: unknown) => void
}

export interface Windows {
  // Readonly, because that is what `Clients.matchAll()` actually hands back.
  matchAll: (options: {
    type: 'window'
    includeUncontrolled: boolean
  }) => Promise<readonly Focusable[]>
  openWindow: (url: string) => Promise<unknown>
}

/**
 * Focus what is open and tell it where to go; open a window when nothing is.
 *
 * Focus comes first and its failure is ignored: on Android the tap has already
 * brought the app forward, and a `focus()` that rejects must not cost the
 * member the navigation that was the whole point of tapping.
 */
export async function openMessages(clients: Windows, origin: string): Promise<void> {
  const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true })

  // Only this portal's own windows. A service worker's clients are same-origin
  // by definition, but this is one line and the alternative is postMessaging
  // a page nobody here wrote.
  const mine = windows.filter((client) => sameOrigin(client.url, origin))

  if (mine.length === 0) {
    await clients.openWindow(origin.replace(/\/$/, '') + MESSAGES_PATH)

    return
  }

  // The most recently focused window is first in `matchAll`'s order, which is
  // the one the member was last looking at and the one they expect to return
  // to.
  const target = mine[0] as Focusable

  await target.focus().catch(() => undefined)

  target.postMessage({ type: NAVIGATE_MESSAGE, path: MESSAGES_PATH })
}

function sameOrigin(url: string, origin: string): boolean {
  try {
    return new URL(url).origin === new URL(origin).origin
  } catch {
    return false
  }
}

/**
 * Mark the icon while the app is shut.
 *
 * **Without a number, and that is not a shortcut.** The push carries no
 * payload, so the worker does not know how many are waiting — and the way to
 * find out would be to ask `/api`, which is the one thing `strategy.ts` says
 * this worker never does. `setAppBadge()` with no argument is the flag every
 * platform draws for exactly this case: something is there. The app replaces
 * it with the real count the moment it is opened.
 */
export function flagWaiting(badging: {
  setAppBadge?: (count?: number) => Promise<void>
}): void {
  try {
    void badging.setAppBadge?.().catch(() => undefined)
  } catch {
    // Half-implemented in some browsers, absent in most. A badge is not worth
    // a failed push.
  }
}
