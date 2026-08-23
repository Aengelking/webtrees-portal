import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider, useAuth } from './auth/AuthProvider'
import { Notifications } from './components/Notifications'
import { applicationServerKey } from './pwa/notifications'
import './i18n'

/**
 * Phase 13: being told a message arrived.
 *
 * The feature was built under one condition — **nothing about the message may
 * reach a lock screen** — and the module keeps that promise by sending a push
 * with no payload at all. What this file checks is the client's half of the
 * same promise: that the sentence saying so is on the screen where a member
 * decides, before they decide.
 *
 * The rest is refusals. A browser that cannot do this, a family that switched
 * it off and a member who once said no must each get a sentence rather than a
 * button that does nothing — the lesson §2.33 records from the two times this
 * repository got that wrong.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const posted: { endpoint?: string; method?: string }[] = []

/** Every request, in order, so that "before the session goes" can be asserted. */
const calls: string[] = []

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)'
const DESKTOP = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/140.0.0.0 Safari/537.36'

function stub(
  push: { available: boolean; public_key: string; subscribed: boolean },
  browser: {
    permission?: NotificationPermission
    request?: NotificationPermission
    existing?: { endpoint: string } | null
    /** False for a browser with no push API at all — every iOS tab. */
    capable?: boolean
    userAgent?: string
  } = {},
) {
  posted.length = 0
  calls.length = 0

  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)

      calls.push(`${init?.method ?? 'GET'} ${url}`)

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

      if (url.includes('/push')) {
        if (init?.method !== undefined && init.method !== 'GET') {
          posted.push({ ...(JSON.parse(String(init.body)) as { endpoint: string }), method: init.method })
        }

        return jsonResponse(push)
      }

      return jsonResponse({})
    }),
  )

  const subscribe = vi.fn().mockResolvedValue({ endpoint: 'https://push.example.test/new' })
  const unsubscribe = vi.fn().mockResolvedValue(true)
  const existing =
    browser.existing === undefined || browser.existing === null
      ? null
      : { ...browser.existing, unsubscribe }

  const capable = browser.capable !== false

  if (capable) {
    vi.stubGlobal('Notification', {
      permission: browser.permission ?? 'default',
      requestPermission: vi.fn().mockResolvedValue(browser.request ?? 'granted'),
    })

    vi.stubGlobal('PushManager', function PushManager() {})
  }

  vi.stubGlobal('navigator', {
    ...navigator,
    userAgent: browser.userAgent ?? DESKTOP,
    maxTouchPoints: 0,
    ...(capable
      ? {
          serviceWorker: {
            ready: Promise.resolve({
              pushManager: {
                subscribe,
                getSubscription: vi.fn().mockResolvedValue(existing),
              },
            }),
          },
        }
      : {}),
  })

  return { subscribe, unsubscribe }
}

function renderIt() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter>
        <Notifications />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('offering notifications', () => {
  /**
   * The assertion this feature exists to keep. A member deciding whether to
   * switch this on is entitled to know what the person next to them on the
   * sofa will be able to read.
   */
  it('says what a lock screen will show, before anybody switches it on', async () => {
    stub({ available: true, public_key: 'BKxQ', subscribed: false })
    renderIt()

    expect(await screen.findByText(/Auf dem Sperrbildschirm steht nur/)).toBeDefined()
    expect(screen.getByText(/Weder der Name der Person noch der Text/)).toBeDefined()
  })

  it('asks the browser and sends the address on', async () => {
    const { subscribe } = stub({ available: true, public_key: 'BKxQ', subscribed: false })
    renderIt()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Benachrichtigungen einschalten' }))

    await waitFor(() => {
      expect(posted).toEqual([{ endpoint: 'https://push.example.test/new', method: 'POST' }])
    })

    expect(subscribe).toHaveBeenCalledWith(
      expect.objectContaining({ userVisibleOnly: true }),
    )
  })

  /** Saying no is an answer. Nothing is sent, and nothing is reported as broken. */
  it('sends nothing when the member refuses the browser prompt', async () => {
    stub({ available: true, public_key: 'BKxQ', subscribed: false }, { request: 'denied' })
    renderIt()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Benachrichtigungen einschalten' }))

    await waitFor(() => {
      expect(screen.queryByRole('alert')).toBeNull()
    })

    expect(posted).toEqual([])
  })

  /**
   * A page cannot ask twice. Offering a button that the browser will silently
   * ignore is the thing §2.33 is about — so this says where the switch really
   * is instead.
   */
  it('explains a browser that is blocking, rather than offering a dead button', async () => {
    stub({ available: true, public_key: 'BKxQ', subscribed: false }, { permission: 'denied' })
    renderIt()

    expect(await screen.findByText(/Ihr Browser blockiert Benachrichtigungen/)).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Benachrichtigungen einschalten' })).toBeNull()
  })

  it('offers a way back off when this device is subscribed', async () => {
    const { unsubscribe } = stub(
      { available: true, public_key: 'BKxQ', subscribed: true },
      { permission: 'granted', existing: { endpoint: 'https://push.example.test/old' } },
    )
    renderIt()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Auf diesem Gerät ausschalten' }))

    await waitFor(() => {
      expect(posted).toEqual([{ endpoint: 'https://push.example.test/old', method: 'DELETE' }])
    })

    // Told to the browser as well, not only to the server: a member who
    // switched it off must stop being knocked on even if the call failed.
    expect(unsubscribe).toHaveBeenCalled()
  })

  it('says nothing at all where the family has switched notifications off', async () => {
    stub({ available: false, public_key: '', subscribed: false })
    const { container } = renderIt()

    await waitFor(() => {
      expect(container.innerHTML).toBe('')
    })
  })

  /**
   * The case this section used to disappear for, and the one that matters
   * most: iOS has no push API in a tab at all, so `permission()` says
   * `unsupported` for an audience largely on iPhones. Installing is the whole
   * difference, which makes this §2.33's "merely harder" rather than
   * "impossible" — and merely harder gets a sentence.
   */
  it('tells an iPhone in a tab that the home screen is what is missing', async () => {
    stub(
      { available: true, public_key: 'BKxQ', subscribed: false },
      { capable: false, userAgent: IPHONE },
    )
    renderIt()

    expect(await screen.findByText(/nur, wenn die App auf dem Home-Bildschirm liegt/)).toBeDefined()
    expect(screen.getByText(/Auf den Startbildschirm/)).toBeDefined()

    // Still no button: the browser would ignore it, which is the whole rule.
    expect(screen.queryByRole('button', { name: 'Benachrichtigungen einschalten' })).toBeNull()
  })

  /** Where installing would change nothing, the silence is still right. */
  it('says nothing in a browser that simply has no push', async () => {
    stub(
      { available: true, public_key: 'BKxQ', subscribed: false },
      { capable: false, userAgent: DESKTOP },
    )
    const { container } = renderIt()

    await waitFor(() => {
      expect(container.innerHTML).toBe('')
    })
  })

  it('says that signing out will switch this device off again', async () => {
    stub(
      { available: true, public_key: 'BKxQ', subscribed: true },
      { permission: 'granted', existing: { endpoint: 'https://push.example.test/old' } },
    )
    renderIt()

    expect(await screen.findByText(/Wenn Sie sich abmelden/)).toBeDefined()
  })
})

/**
 * A subscription is not session state — a row against a user id, and an
 * address the browser's push service holds. Nothing about signing out reaches
 * either of them, so without this the phone goes on buzzing for an account
 * somebody has just signed out of, and the switch that would stop it is behind
 * the sign-in they just left.
 */
describe('signing out', () => {
  function Harness() {
    const { signOut } = useAuth()

    return <button onClick={() => void signOut()}>Abmelden</button>
  }

  function renderHarness() {
    return render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
      >
        <AuthProvider>
          <Harness />
        </AuthProvider>
      </QueryClientProvider>,
    )
  }

  it('forgets this device, and does it while there is still a session to do it with', async () => {
    const { unsubscribe } = stub(
      { available: true, public_key: 'BKxQ', subscribed: true },
      { permission: 'granted', existing: { endpoint: 'https://push.example.test/old' } },
    )
    renderHarness()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Abmelden' }))

    await waitFor(() => {
      expect(posted).toEqual([{ endpoint: 'https://push.example.test/old', method: 'DELETE' }])
    })

    expect(unsubscribe).toHaveBeenCalled()

    // `DELETE /push` is authenticated by the session it is deleting itself
    // out of, so the order is the assertion: after `DELETE /session` it would
    // be a 401 and the row would survive.
    const relevant = calls.filter((call) => call.includes('/push') || call.includes('/session'))
    expect(relevant).toEqual(['DELETE /api/v1/push', 'DELETE /api/v1/session'])
  })

  /**
   * `navigator.serviceWorker.ready` never settles in a browser that supports
   * service workers and has none registered — a tab where registration failed,
   * and every tab in the moment before it finishes. Awaited unbounded, that is
   * a member stuck on a disabled *Abmelden* button.
   */
  it('signs out anyway when the browser never answers', async () => {
    stub(
      { available: true, public_key: 'BKxQ', subscribed: true },
      { permission: 'granted', existing: { endpoint: 'https://push.example.test/old' } },
    )

    vi.stubGlobal('navigator', {
      ...navigator,
      userAgent: DESKTOP,
      // The promise this is really about: supported, registered by nobody.
      serviceWorker: { ready: new Promise(() => {}) },
    })

    vi.useFakeTimers()
    renderHarness()

    // `fireEvent` rather than `userEvent`, and no `waitFor`: both drive
    // themselves with timers this test has just replaced, and Testing Library
    // does not recognise Vitest's fakes as fakes — it would wait on a clock
    // that only moves when asked.
    fireEvent.click(screen.getByRole('button', { name: 'Abmelden' }))

    await act(async () => {
      await vi.advanceTimersByTimeAsync(3000)
    })

    expect(calls).toContain('DELETE /api/v1/session')
    expect(posted).toEqual([])
  })

  /** Nobody asked about notifications. They asked to be signed out. */
  it('signs out anyway when the push service cannot be told', async () => {
    stub(
      { available: true, public_key: 'BKxQ', subscribed: true },
      { permission: 'granted', existing: { endpoint: 'https://push.example.test/old' } },
    )

    // A browser that will not give up its subscription — a revoked
    // permission, a service worker that never became ready.
    vi.stubGlobal('navigator', {
      ...navigator,
      userAgent: DESKTOP,
      // A getter, so the rejection is created at the moment it is awaited.
      // Built eagerly it is an unhandled rejection before the click, which
      // Vitest reports as an error against a test that passed.
      serviceWorker: {
        get ready() {
          return Promise.reject(new Error('no service worker'))
        },
      },
    })

    renderHarness()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Abmelden' }))

    await waitFor(() => {
      expect(calls).toContain('DELETE /api/v1/session')
    })

    expect(posted).toEqual([])
  })
})

/**
 * The server sends base64url and `pushManager.subscribe` wants bytes. Getting
 * this wrong fails at subscribe time with a message that names neither the key
 * nor the encoding, which is a bad afternoon.
 */
describe('the application server key', () => {
  it('decodes base64url, padding and all', () => {
    // "hello" in base64url, one padding character short of base64.
    expect([...applicationServerKey('aGVsbG8')]).toEqual([104, 101, 108, 108, 111])
  })

  it('translates the two characters base64url replaces', () => {
    // "-_-_" is "+/+/" in ordinary base64: 62, 63, 62, 63, which repacks
    // into 0xFB 0xFF 0xBF. The two substituted characters are the whole point.
    expect([...applicationServerKey('-_-_')]).toEqual([251, 255, 191])
  })
})
