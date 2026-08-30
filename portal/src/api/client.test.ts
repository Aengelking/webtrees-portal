import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api, forgetCsrfToken, setUnauthenticatedHandler } from './client'

type FetchArgs = [input: RequestInfo | URL, init?: RequestInit]

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function calls(mock: ReturnType<typeof vi.fn>): FetchArgs[] {
  return mock.mock.calls as FetchArgs[]
}

describe('api client', () => {
  beforeEach(() => {
    forgetCsrfToken()
  })

  it('fetches a CSRF token before an unsafe request and sends it as a header', async () => {
    const fetchMock = vi.fn<typeof fetch>()
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ csrf_token: 'token-1' }))
      .mockResolvedValueOnce(jsonResponse({ user: { username: 'anna' }, csrf_token: 'token-1' }))
    vi.stubGlobal('fetch', fetchMock)

    await api.login({ username: 'anna', password: 'pw' })

    const [csrfCall, loginCall] = calls(fetchMock)
    expect(csrfCall?.[0]).toBe('/api/v1/csrf')
    expect(loginCall?.[0]).toBe('/api/v1/session')
    expect(loginCall?.[1]?.method).toBe('POST')
    expect((loginCall?.[1]?.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('token-1')
  })

  it('never sends a CSRF header on a safe request', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse({ items: [], total: 0 }))
    vi.stubGlobal('fetch', fetchMock)

    await api.members({ q: 'bert', page: 2 })

    const [call] = calls(fetchMock)
    expect(call?.[0]).toBe('/api/v1/members?q=bert&page=2')
    expect((call?.[1]?.headers as Record<string, string>)['X-CSRF-TOKEN']).toBeUndefined()
  })

  it('refreshes a stale CSRF token and retries once', async () => {
    const fetchMock = vi.fn<typeof fetch>()
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ csrf_token: 'stale' }))
      .mockResolvedValueOnce(jsonResponse({ error: 'csrf_token_invalid', message: 'expired' }, 403))
      .mockResolvedValueOnce(jsonResponse({ csrf_token: 'fresh' }))
      .mockResolvedValueOnce(jsonResponse({ user: { username: 'anna' }, csrf_token: 'fresh' }))
    vi.stubGlobal('fetch', fetchMock)

    await api.login({ username: 'anna', password: 'pw' })

    expect(fetchMock).toHaveBeenCalledTimes(4)
    const retry = calls(fetchMock)[3]
    expect((retry?.[1]?.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('fresh')
  })

  it('gives up after one retry rather than looping', async () => {
    const fetchMock = vi.fn<typeof fetch>()
    fetchMock.mockImplementation(async (input) =>
      String(input).endsWith('/csrf')
        ? jsonResponse({ csrf_token: 'never-right' })
        : jsonResponse({ error: 'csrf_token_invalid', message: 'expired' }, 403),
    )
    vi.stubGlobal('fetch', fetchMock)

    await expect(api.login({ username: 'anna', password: 'pw' })).rejects.toBeInstanceOf(ApiError)
    expect(fetchMock).toHaveBeenCalledTimes(4)
  })

  it('reports the API error code so the UI can pick a sentence', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockResolvedValue(
        jsonResponse({ error: 'not_found', message: 'This item does not exist.' }, 404),
      ),
    )

    await expect(api.individual('X999')).rejects.toMatchObject({
      code: 'not_found',
      status: 404,
    })
  })

  /**
   * The failure a member reported as "sometimes the login does not work".
   *
   * Something in front of webtrees — an overloaded Apache, a hosting
   * maintenance page — answers for it with HTML, and has been seen doing so
   * carrying a `200`. This used to parse to `null` and be handed back as the
   * record that was asked for, so the caller read a field off nothing. See
   * §2.102.
   */
  it('refuses a page where an answer was asked for, whatever status it carries', async () => {
    const page = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN">\n<html><head>\n<title>503 Service Unavailable</title>\n</head><body><h1>Service Unavailable</h1></body></html>'

    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockResolvedValue(
        new Response(page, { status: 200, headers: { 'Content-Type': 'text/html' } }),
      ),
    )

    await expect(api.me()).rejects.toMatchObject({ code: 'unreadable_answer', status: 200 })
  })

  /** The same on a write, where it used to take the caller down with it. */
  it('refuses a page on a sign-in rather than answering with nothing', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn<typeof fetch>()
        .mockResolvedValueOnce(jsonResponse({ csrf_token: 'token-1' }))
        .mockResolvedValue(new Response('<html>503</html>', { status: 200 })),
    )

    await expect(api.login({ username: 'anna', password: 'x', remember: false })).rejects.toBeInstanceOf(
      ApiError,
    )
  })

  /**
   * And the answer that *is* nothing stays nothing. `204` says so, and
   * telling the two apart is the whole of the fix above.
   */
  it('still reads an empty body as an empty answer', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockResolvedValue(new Response('', { status: 200 })))

    await expect(api.me()).resolves.toBeNull()
  })

  it('turns a transport failure into a network_error rather than an unhandled rejection', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockRejectedValue(new TypeError('Failed to fetch')))

    await expect(api.me()).rejects.toMatchObject({ code: 'network_error', status: 0 })
  })

  it('notifies the app on a 401 and forgets the token', async () => {
    const onUnauthenticated = vi.fn()
    setUnauthenticatedHandler(onUnauthenticated)

    vi.stubGlobal(
      'fetch',
      vi
        .fn<typeof fetch>()
        .mockResolvedValue(jsonResponse({ error: 'unauthenticated', message: 'Please sign in.' }, 401)),
    )

    await expect(api.me()).rejects.toMatchObject({ code: 'unauthenticated' })
    expect(onUnauthenticated).toHaveBeenCalledOnce()
  })

  it('always sends the session cookie and never caches', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse({}))
    vi.stubGlobal('fetch', fetchMock)

    await api.me()

    const [call] = calls(fetchMock)
    expect(call?.[1]?.credentials).toBe('same-origin')
    expect(call?.[1]?.cache).toBe('no-store')
  })

  it('keeps nothing in browser storage', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn<typeof fetch>()
        .mockResolvedValueOnce(jsonResponse({ csrf_token: 'token-1' }))
        .mockResolvedValueOnce(jsonResponse({ user: { username: 'anna' }, csrf_token: 'token-1' })),
    )

    await api.login({ username: 'anna', password: 'pw' })

    expect(window.localStorage.length).toBe(0)
    expect(window.sessionStorage.length).toBe(0)
  })
  /**
   * The invitation screen's own failure, in the small.
   *
   * webtrees starts a session — and a new session id — for every request that
   * arrives without one, so two requests that leave before any cookie exists
   * come back with two different cookies and the browser keeps one of them.
   * The token from the other session is then refused, and the member is told
   * the server is unreachable on a link that is perfectly good.
   *
   * What is pinned here is the remedy and not the mechanism, because the
   * mechanism is the browser's: the first request goes out alone.
   */
  it('lets the first request of a page load settle before sending another', async () => {
    vi.resetModules()

    const fresh = await import('./client')

    let inFlight = 0
    let together = 0
    let release = (): void => {}
    const held = new Promise<void>((resolve) => {
      release = resolve
    })

    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input) => {
      inFlight += 1
      together = Math.max(together, inFlight)

      if (String(input).endsWith('/csrf')) {
        await held
      }

      inFlight -= 1

      return jsonResponse(String(input).endsWith('/csrf') ? { csrf_token: 'token-1' } : {})
    })
    vi.stubGlobal('fetch', fetchMock)

    // What the screen does: asks for a token and for the member at the same
    // moment, with nothing in between.
    const first = fresh.api.csrf()
    const second = fresh.api.me()

    // Nothing may have gone out beside the first one while it is still open.
    await Promise.resolve()
    expect(fetchMock).toHaveBeenCalledTimes(1)

    release()
    await Promise.all([first, second])

    expect(together).toBe(1)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  /**
   * And where it happens anyway — a session that expired mid-visit, a second
   * tab — the answer is legible rather than fatal. webtrees' own CheckCsrf
   * redirects a mismatched token to the webtrees host, which is a different
   * origin: followed, it fails as "no internet"; read as what it is, it is a
   * stale token, which this client already knows how to fix.
   */
  it('reads a redirect as a stale token and retries with a fresh one', async () => {
    const redirect = (): Response => {
      const response = new Response(null, { status: 200 })

      Object.defineProperty(response, 'type', { value: 'opaqueredirect' })

      return response
    }

    const fetchMock = vi.fn<typeof fetch>()
    fetchMock
      .mockResolvedValueOnce(jsonResponse({ csrf_token: 'from-the-other-session' }))
      .mockResolvedValueOnce(redirect())
      .mockResolvedValueOnce(jsonResponse({ csrf_token: 'the-one-that-fits' }))
      .mockResolvedValueOnce(jsonResponse({ tree: { name: 'portal', title: 'Familie Beispiel' } }))
    vi.stubGlobal('fetch', fetchMock)

    const preview = await api.previewInvitation('einladung-fuer-anna')

    expect(preview.tree.title).toBe('Familie Beispiel')
    expect(fetchMock).toHaveBeenCalledTimes(4)

    const retry = calls(fetchMock)[3]
    expect((retry?.[1]?.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('the-one-that-fits')
  })

  /** A redirect on a read is not a token problem, and must not be called one. */
  it('does not call a redirected read a stale token', async () => {
    const response = new Response(null, { status: 200 })

    Object.defineProperty(response, 'type', { value: 'opaqueredirect' })

    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(response)
    vi.stubGlobal('fetch', fetchMock)

    await expect(api.me()).rejects.toMatchObject({ code: 'server_error' })
    expect(fetchMock).toHaveBeenCalledOnce()
  })

  it('never follows a redirect itself', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockResolvedValue(jsonResponse({}))
    vi.stubGlobal('fetch', fetchMock)

    await api.me()

    const [call] = calls(fetchMock)
    expect(call?.[1]?.redirect).toBe('manual')
  })
})
