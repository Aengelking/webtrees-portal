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
})
