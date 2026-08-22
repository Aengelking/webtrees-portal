import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
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

function stub(
  push: { available: boolean; public_key: string; subscribed: boolean },
  browser: {
    permission?: NotificationPermission
    request?: NotificationPermission
    existing?: { endpoint: string } | null
  } = {},
) {
  posted.length = 0

  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)

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

  vi.stubGlobal('Notification', {
    permission: browser.permission ?? 'default',
    requestPermission: vi.fn().mockResolvedValue(browser.request ?? 'granted'),
  })

  vi.stubGlobal('PushManager', function PushManager() {})

  vi.stubGlobal('navigator', {
    ...navigator,
    serviceWorker: {
      ready: Promise.resolve({
        pushManager: {
          subscribe,
          getSubscription: vi.fn().mockResolvedValue(existing),
        },
      }),
    },
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
