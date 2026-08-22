import { describe, expect, it, vi } from 'vitest'
import { MESSAGES_PATH, NAVIGATE_MESSAGE, flagWaiting, openMessages } from './notify'
import type { Focusable } from './notify'

/**
 * Tapping the notification.
 *
 * This is the half of the feature a member actually touches, and the first
 * version of it did not work: `client.navigate()` is only allowed on a client
 * the service worker controls, and the list it was called on deliberately
 * includes clients it does not. The rejection was swallowed, the window was
 * focused anyway, and the app came up where the member had left it.
 *
 * So what is pinned here is the behaviour that replaced it — ask the app —
 * and the two failures around it: a focus that rejects must not cost the
 * navigation, and a window belonging to somebody else must not be messaged.
 */

const ORIGIN = 'https://portal.example/'

function windowAt(url: string, focus: () => Promise<unknown> = () => Promise.resolve()) {
  return { url, focus, postMessage: vi.fn() } satisfies Focusable
}

function clients(open: Focusable[]) {
  return {
    matchAll: vi.fn().mockResolvedValue(open),
    openWindow: vi.fn().mockResolvedValue(undefined),
  }
}

describe('tapping the notification', () => {
  it('asks the window that is already open to go to the messages', async () => {
    const portal = windowAt('https://portal.example/me')
    const all = clients([portal])

    await openMessages(all, ORIGIN)

    expect(portal.postMessage).toHaveBeenCalledWith({
      type: NAVIGATE_MESSAGE,
      path: MESSAGES_PATH,
    })
    expect(all.openWindow).not.toHaveBeenCalled()
  })

  /**
   * The tap has already brought the app forward on a phone. A `focus()` that
   * rejects — and it does, in an app that is not the foreground one — must not
   * take the navigation with it.
   */
  it('still says where to go when focusing fails', async () => {
    const portal = windowAt('https://portal.example/me', () => Promise.reject(new Error('nope')))

    await openMessages(clients([portal]), ORIGIN)

    expect(portal.postMessage).toHaveBeenCalled()
  })

  it('opens a window when the app is not running', async () => {
    const all = clients([])

    await openMessages(all, ORIGIN)

    expect(all.openWindow).toHaveBeenCalledWith('https://portal.example/messages')
  })

  /** Somebody else's page is not asked to navigate, and is not counted as ours. */
  it('ignores a window that is not this portal', async () => {
    const stranger = windowAt('https://elsewhere.example/')
    const all = clients([stranger])

    await openMessages(all, ORIGIN)

    expect(stranger.postMessage).not.toHaveBeenCalled()
    expect(all.openWindow).toHaveBeenCalledWith('https://portal.example/messages')
  })

  it('picks the window the member was last looking at', async () => {
    const recent = windowAt('https://portal.example/me')
    const older = windowAt('https://portal.example/contacts')

    await openMessages(clients([recent, older]), ORIGIN)

    expect(recent.postMessage).toHaveBeenCalled()
    expect(older.postMessage).not.toHaveBeenCalled()
  })
})

/**
 * The mark on the home-screen icon, from a worker that does not know how many
 * are waiting — and must not find out. Asking `/api` is the one thing this
 * worker never does (`strategy.ts`), so the flag has no number in it.
 */
describe('marking the icon while the app is shut', () => {
  it('flags it without a count, because it has none to give', () => {
    const setAppBadge = vi.fn().mockResolvedValue(undefined)

    flagWaiting({ setAppBadge })

    expect(setAppBadge).toHaveBeenCalledWith()
  })

  it('does nothing at all where the browser has no badges', () => {
    expect(() => flagWaiting({})).not.toThrow()
  })

  /** Safari rejects this until notifications are allowed. A push must not fail over it. */
  it('swallows a refusal rather than failing the push', () => {
    expect(() =>
      flagWaiting({ setAppBadge: vi.fn().mockRejectedValue(new Error('not allowed')) }),
    ).not.toThrow()
  })
})
