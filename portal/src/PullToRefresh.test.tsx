import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import './i18n'

/**
 * Pull down at the top to load the screen again.
 *
 * The gesture only exists in the installed app, where the browser's own is
 * gone — see `usePullToRefresh`. That is the first thing asserted here,
 * because getting it wrong puts two pull-to-refreshes on one screen, and the
 * one you would notice is the browser's.
 */

/**
 * The indicator, found by what it says rather than by its role.
 *
 * `Loading` is a `role="status"` too, and on a screen that is still fetching
 * there would be two. What this asks about is the gesture, so it asks for the
 * gesture's own words.
 */
const PULLING = 'Zum Aktualisieren nach unten ziehen'
const RELEASE = 'Loslassen zum Aktualisieren'
const RUNNING = 'Wird aktualisiert …'

function indicator(): HTMLElement | null {
  return (
    screen.queryByText(PULLING) ?? screen.queryByText(RELEASE) ?? screen.queryByText(RUNNING)
  )
}

function jsonResponse(body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

const ME = {
  user: { id: 1, username: 'anna', real_name: 'Anna', email: 'a@b.test', language: 'de', role: 'member' },
  profile: { id: 1, visible_in_directory: true, display_name_override: null, consent_recorded_at: null, directory_decided: true },
  individual: null,
  tree: { name: 'portal', title: 'Familie Beispiel' },
  csrf_token: 'token-1',
}

/** How many times the screen has asked the server who the member is. */
function meRequests(): number {
  return vi.mocked(fetch).mock.calls.filter(([input]) => String(input).endsWith('/me')).length
}

function stub() {
  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input) => {
      const url = String(input)

      return url.endsWith('/csrf') ? jsonResponse({ csrf_token: 'token-1' }) : jsonResponse(ME)
    }),
  )
}

/**
 * A touch, as the handler reads one.
 *
 * jsdom has no `TouchEvent`, and building one is not what these tests are
 * about: the handler reads `touches[0].clientY` and whether it may prevent
 * the default, and that is all this has to carry.
 */
function touch(type: 'touchstart' | 'touchmove' | 'touchend', y: number): Event {
  const event = new Event(type, { bubbles: true, cancelable: true })

  Object.defineProperty(event, 'touches', { value: [{ clientY: y }] })

  return event
}

function pull(from: number, to: number, release = true): void {
  act(() => {
    window.dispatchEvent(touch('touchstart', from))
    window.dispatchEvent(touch('touchmove', to))

    if (release) {
      window.dispatchEvent(touch('touchend', to))
    }
  })
}

function renderApp() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter initialEntries={['/me']}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

/**
 * Render, and wait until the gesture is actually listening.
 *
 * Waiting for the heading is not enough, and the difference is invisible
 * until the machine is busy. `findBy…` resolves the moment the element is in
 * the document — and Testing Library turns React's act environment *off*
 * while it polls, so the `useEffect` in `usePullToRefresh` is left on the
 * real scheduler and runs in a later task. On an idle machine that task has
 * always already run by the time the assertion follows. On a loaded one it
 * has not, and the touch is dispatched at a window with no listener on it.
 *
 * That is worse than one flaky test. Four of the tests below assert that a
 * gesture does *nothing* — and a gesture nobody is listening for does
 * nothing very convincingly. So every test goes through here, and the empty
 * `act` drains the effects that commit only scheduled. See §2.97.
 */
async function startedApp(): Promise<void> {
  renderApp()

  await screen.findByRole('heading', { name: 'Mein Profil' })

  await act(async () => undefined)
}

function asInstalledApp(): void {
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: query.includes('standalone'),
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
  }))
}

beforeEach(() => {
  window.scrollY = 0
  stub()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('pull to refresh', () => {
  /**
   * In a tab the browser already does this. Ours on top would be two.
   */
  it('does nothing in a browser tab', async () => {
    await startedApp()

    const before = meRequests()
    pull(10, 300)

    expect(indicator()).toBeNull()
    expect(meRequests()).toBe(before)
  })

  it('shows the indicator once the pull starts, in the installed app', async () => {
    asInstalledApp()
    await startedApp()

    pull(10, 40, false)

    expect(screen.getByText(PULLING)).toBeDefined()
  })

  /** Far enough, and it says so before the finger comes off. */
  it('says when the pull is far enough', async () => {
    asInstalledApp()
    await startedApp()

    pull(10, 400, false)

    expect(screen.getByText(RELEASE)).toBeDefined()
  })

  /**
   * The threshold is what keeps a scroll from being a refresh. A short drag
   * has to leave the screen exactly as it found it.
   */
  it('lets go of a pull that did not go far enough', async () => {
    asInstalledApp()
    await startedApp()

    const before = meRequests()
    pull(10, 40)

    expect(indicator()).toBeNull()
    expect(meRequests()).toBe(before)
  })

  it('loads the screen again when the pull is far enough', async () => {
    asInstalledApp()
    await startedApp()

    const before = meRequests()

    pull(10, 400)

    expect(screen.getByText(RUNNING)).toBeDefined()

    await waitFor(() => {
      expect(meRequests()).toBeGreaterThan(before)
    })

    // And it puts itself away once the answer is in.
    await waitFor(() => {
      expect(indicator()).toBeNull()
    })
  })

  /** A drag upwards is a scroll, whatever it started as. */
  it('ignores a pull in the other direction', async () => {
    asInstalledApp()
    await startedApp()

    const before = meRequests()
    pull(300, 10)

    expect(indicator()).toBeNull()
    expect(meRequests()).toBe(before)
  })

  /**
   * Halfway down a long screen, a downward drag is a scroll and nothing else.
   */
  it('only starts at the very top of the page', async () => {
    asInstalledApp()
    await startedApp()

    window.scrollY = 200

    const before = meRequests()
    pull(10, 400)

    expect(indicator()).toBeNull()
    expect(meRequests()).toBe(before)
  })

  /**
   * Holding the page still is the whole reason the listener is not passive:
   * without it the page rubber-bands behind the indicator.
   */
  it('holds the page still while the pull is happening', async () => {
    asInstalledApp()
    await startedApp()

    const moving = touch('touchmove', 200)
    const prevented = vi.spyOn(moving, 'preventDefault')

    act(() => {
      window.dispatchEvent(touch('touchstart', 10))
      window.dispatchEvent(moving)
    })

    expect(prevented).toHaveBeenCalled()
  })
})
