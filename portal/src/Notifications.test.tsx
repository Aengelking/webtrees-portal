import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider, useAuth } from './auth/AuthProvider'
import { Notifications } from './components/Notifications'
import { NotificationPrompt } from './components/NotificationPrompt'
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

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'
const IPHONE_CHROME =
  'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/140.0 Mobile/15E148 Safari/604.1'
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

  /**
   * Chrome on an iPhone is still an iPhone: installing is possible, it is
   * just done from Safari. `appleOther` is a separate state because the
   * Share button is somewhere else, not because the answer changes.
   */
  it('tells Chrome on an iPhone the same thing', async () => {
    stub(
      { available: true, public_key: 'BKxQ', subscribed: false },
      { capable: false, userAgent: IPHONE_CHROME },
    )
    renderIt()

    expect(await screen.findByText(/nur, wenn die App auf dem Home-Bildschirm liegt/)).toBeDefined()
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

  /**
   * Both halves of it. Signing out unsubscribes the device, and signing back
   * in switches it on again — the second is the surprising one, so it is the
   * one that most needs saying.
   */
  it('says what signing out and back in does to this device', async () => {
    stub(
      { available: true, public_key: 'BKxQ', subscribed: true },
      { permission: 'granted', existing: { endpoint: 'https://push.example.test/old' } },
    )
    renderIt()

    const sentence = await screen.findByText(/Beim Abmelden/)

    expect(sentence.textContent).toMatch(/schaltet es sich von selbst wieder ein/)
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
/** Switching on records the wish, which is what the next sign-in reads. */
describe('switching on', () => {
  afterEach(() => {
    window.localStorage.clear()
  })

  it('remembers whose device this is', async () => {
    stub({ available: true, public_key: 'BKxQ', subscribed: false })

    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
      >
        <MemoryRouter>
          <Notifications account={7} />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await userEvent.setup().click(
      await screen.findByRole('button', { name: 'Benachrichtigungen einschalten' }),
    )

    await waitFor(() => {
      expect(window.localStorage.getItem('portal.notifications')).toBe('7')
    })
  })
})

/**
 * And the other direction, which is the point of the first one being safe to
 * do at all.
 *
 * Signing out unsubscribes the device — see above, and it must — but leaving
 * is not the same as changing your mind. A member who switched notifications
 * on here and signed out had to go and find the switch again on every visit.
 * So the *wish* is kept (`rememberNotifications`), and the next sign-in acts
 * on it without asking anybody anything.
 */
describe('signing back in', () => {
  /** Signed in as this account, which is what the wish is keyed by. */
  const ME = {
    user: { id: 7, username: 'anna', real_name: 'Anna', email: 'a@b.test', language: 'de', role: 'member' },
    profile: null,
    individual: null,
    tree: { name: 'portal', title: 'Familie Beispiel' },
    csrf_token: 'token-1',
  }

  function signedIn(push: { available: boolean; public_key: string; subscribed: boolean }) {
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

        return jsonResponse(ME)
      }),
    )

    const subscribe = vi.fn().mockResolvedValue({ endpoint: 'https://push.example.test/new' })

    vi.stubGlobal('Notification', { permission: 'granted', requestPermission: vi.fn() })
    vi.stubGlobal('PushManager', function PushManager() {})
    vi.stubGlobal('navigator', {
      ...navigator,
      userAgent: DESKTOP,
      maxTouchPoints: 0,
      serviceWorker: {
        ready: Promise.resolve({
          pushManager: { subscribe, getSubscription: vi.fn().mockResolvedValue(null) },
        }),
      },
    })

    return { subscribe }
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

  afterEach(() => {
    window.localStorage.clear()
  })

  it('switches this device back on for the member who had it on', async () => {
    const { subscribe } = signedIn({ available: true, public_key: 'BKxQ', subscribed: false })

    window.localStorage.setItem('portal.notifications', '7')

    renderApp()

    await waitFor(() => {
      expect(posted).toEqual([{ endpoint: 'https://push.example.test/new', method: 'POST' }])
    })

    // Silently: the permission was given once and belongs to the browser, and
    // a prompt nobody tapped anything to get is a prompt out of nowhere.
    expect(subscribe).toHaveBeenCalled()
    expect(vi.mocked(Notification.requestPermission)).not.toHaveBeenCalled()
  })

  /**
   * The whole reason the wish is an account id rather than a flag. A shared
   * tablet must not start buzzing for the person who used it last.
   */
  it('leaves somebody else’s device alone', async () => {
    signedIn({ available: true, public_key: 'BKxQ', subscribed: false })

    window.localStorage.setItem('portal.notifications', '9')

    renderApp()

    await screen.findByRole('heading', { name: 'Mein Profil' })

    expect(posted).toEqual([])
  })

  /** Nothing remembered is the ordinary case, and it stays quiet. */
  it('does nothing where nobody ever switched it on here', async () => {
    signedIn({ available: true, public_key: 'BKxQ', subscribed: false })
    renderApp()

    await screen.findByRole('heading', { name: 'Mein Profil' })

    expect(posted).toEqual([])
  })

  /** The family's switch still decides, and it is asked before anything else. */
  it('does not resubscribe where the family switched notifications off', async () => {
    signedIn({ available: false, public_key: '', subscribed: false })

    window.localStorage.setItem('portal.notifications', '7')

    renderApp()

    await screen.findByRole('heading', { name: 'Mein Profil' })

    expect(posted).toEqual([])
  })

  /** A browser that is blocking is not asked, and is not worked around. */
  /**
   * Switching off *is* changing your mind, and it clears the wish — otherwise
   * the next sign-in would undo the decision the member just made.
   */
  it('forgets the wish when the member switches this device off', async () => {
    const { unsubscribe } = stub(
      { available: true, public_key: 'BKxQ', subscribed: true },
      { permission: 'granted', existing: { endpoint: 'https://push.example.test/old' } },
    )

    window.localStorage.setItem('portal.notifications', '7')

    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
      >
        <MemoryRouter>
          <Notifications account={7} />
        </MemoryRouter>
      </QueryClientProvider>,
    )

    await userEvent.setup().click(
      await screen.findByRole('button', { name: 'Auf diesem Gerät ausschalten' }),
    )

    await waitFor(() => {
      expect(unsubscribe).toHaveBeenCalled()
    })

    expect(window.localStorage.getItem('portal.notifications')).toBeNull()
  })

  it('does not resubscribe where the browser is blocking', async () => {
    signedIn({ available: true, public_key: 'BKxQ', subscribed: false })

    vi.stubGlobal('Notification', { permission: 'denied', requestPermission: vi.fn() })
    window.localStorage.setItem('portal.notifications', '7')

    renderApp()

    await screen.findByRole('heading', { name: 'Mein Profil' })

    expect(posted).toEqual([])
  })
})

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

/**
 * Tapping the notification, from the app's side.
 *
 * The first version had the service worker call `client.navigate()`, which is
 * only allowed on a client it controls — so on a window it did not control the
 * call rejected, the rejection was swallowed, and the app came up exactly
 * where the member had left it. Reported as: it opens, and nothing moves.
 *
 * `sw/notify.test.ts` pins the worker's half. This is the half that has to
 * hear it.
 */
describe('arriving from a notification', () => {
  function listeningApp() {
    const listeners: ((event: MessageEvent) => void)[] = []

    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) => {
        const url = String(input)

        if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })
        if (url.includes('/conversations')) return jsonResponse({ conversations: [] })
        if (url.includes('/messages')) return jsonResponse({ messages: [], unread: 0 })
        if (url.includes('/push')) return jsonResponse({ available: false, public_key: '', subscribed: false })

        return jsonResponse({
          user: { id: 1, username: 'anna', real_name: 'Anna Beispiel', email: 'a@b.test', language: 'de', role: 'member' },
          profile: { id: 1, visible_in_directory: true, display_name_override: null, consent_recorded_at: null },
          individual: null,
          tree: { name: 'portal', title: 'Familie Beispiel' },
          unread_messages: 0,
          unread_conversations: 0,
          csrf_token: 'token-1',
        })
      }),
    )

    vi.stubGlobal('navigator', {
      ...navigator,
      serviceWorker: {
        addEventListener: (_type: string, handler: (event: MessageEvent) => void) => {
          listeners.push(handler)
        },
        removeEventListener: () => undefined,
      },
    })

    render(
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

    return (data: unknown) => {
      for (const listener of listeners) {
        listener({ data } as MessageEvent)
      }
    }
  }

  it('goes to the messages when the worker says so', async () => {
    const post = listeningApp()

    expect(await screen.findByRole('heading', { name: 'Mein Profil' })).toBeDefined()

    post({ type: 'portal:navigate', path: '/messages' })

    expect(await screen.findByRole('heading', { name: 'Nachrichten' })).toBeDefined()
  })

  /**
   * A page hears from more than its own service worker. `//elsewhere.example`
   * begins with a slash and is a URL rather than a path, which is the whole
   * reason the check is not `startsWith('/')`.
   */
  it('ignores a message that is not one of ours', async () => {
    const post = listeningApp()

    expect(await screen.findByRole('heading', { name: 'Mein Profil' })).toBeDefined()

    post({ type: 'portal:navigate', path: '//elsewhere.example/messages' })
    post({ type: 'something-else', path: '/messages' })
    post('go to /messages')
    post(null)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Mein Profil' })).toBeDefined()
    })
  })
})

/**
 * The offer inside the installed app.
 *
 * Asking in a browser tab would be asking for something the member cannot have
 * yet — on iOS notifications reach only an app on the home screen — so the
 * moment worth asking is the first run of the installed one. What has to hold
 * is the same as for the install offer: asked once, answered in one tap, and
 * costing nothing to refuse.
 */
describe('offering notifications after installing', () => {
  function installed(standalone: boolean) {
    vi.stubGlobal('matchMedia', (query: string) => ({
      matches: standalone && query.includes('standalone'),
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
    }))
  }

  function renderPrompt() {
    return render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
      >
        <MemoryRouter>
          <NotificationPrompt />
        </MemoryRouter>
      </QueryClientProvider>,
    )
  }

  afterEach(() => {
    window.localStorage.removeItem('portal.notifications.offered')
  })

  it('asks inside the installed app, and says what a lock screen will show', async () => {
    stub({ available: true, public_key: 'BKxQ', subscribed: false })
    installed(true)
    renderPrompt()

    expect(await screen.findByRole('dialog')).toBeDefined()
    expect(screen.getByText(/Weder der Name der Person noch der Text/)).toBeDefined()
    expect(screen.getByText(/unter „Einstellungen“/)).toBeDefined()
  })

  it('switches on and tells the server about this device', async () => {
    const { subscribe } = stub({ available: true, public_key: 'BKxQ', subscribed: false })
    installed(true)
    renderPrompt()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Benachrichtigungen einschalten' }))

    await waitFor(() => {
      expect(posted).toEqual([{ endpoint: 'https://push.example.test/new', method: 'POST' }])
    })

    expect(subscribe).toHaveBeenCalled()
    expect(screen.queryByRole('dialog')).toBeNull()
  })

  /** Asked once. Saying "later" is an answer, and it is remembered as one. */
  it('never asks twice on the same device', async () => {
    stub({ available: true, public_key: 'BKxQ', subscribed: false })
    installed(true)
    const { unmount } = renderPrompt()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Später' }))

    expect(screen.queryByRole('dialog')).toBeNull()

    unmount()
    renderPrompt()

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull()
    })
  })

  it('does not ask in a browser tab, where notifications would not arrive', async () => {
    stub({ available: true, public_key: 'BKxQ', subscribed: false })
    installed(false)
    const { container } = renderPrompt()

    await waitFor(() => {
      expect(container.innerHTML).toBe('')
    })
  })

  it('does not ask where the family has switched notifications off', async () => {
    stub({ available: false, public_key: '', subscribed: false })
    installed(true)
    const { container } = renderPrompt()

    await waitFor(() => {
      expect(container.innerHTML).toBe('')
    })
  })

  /**
   * A member who once said no can only undo that in the browser's own
   * settings, so a dialogue about it is a dialogue nobody asked for. Settings
   * says where the switch is.
   */
  it('does not ask a browser that is already blocking', async () => {
    stub({ available: true, public_key: 'BKxQ', subscribed: false }, { permission: 'denied' })
    installed(true)
    const { container } = renderPrompt()

    await waitFor(() => {
      expect(container.innerHTML).toBe('')
    })
  })
})
