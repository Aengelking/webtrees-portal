/**
 * The one place the portal talks to the API.
 *
 * No component builds a URL or calls fetch. Everything goes through here, so
 * that credentials, CSRF and the 401 rule are decided once.
 */

import type {
  AncestorPage,
  ApiErrorBody,
  ApiErrorCode,
  Credentials,
  CsrfToken,
  Health,
  Individual,
  IndividualUpdate,
  InvitationAcceptance,
  InvitationPreview,
  Me,
  MemberDetail,
  MemberPage,
  MemberProfile,
  MemberProfileUpdate,
  PendingIndividual,
} from './types'

const BASE = '/api/v1'

export class ApiError extends Error {
  constructor(
    readonly code: ApiErrorCode,
    readonly status: number,
    message: string,
    /**
     * A short code the server recorded alongside the failure, present only on
     * the failures that are the portal's own fault. It is shown to the member
     * so they can quote it, and it is the only thing that connects "it did not
     * work" to a particular row in the administrator's error log.
     */
    readonly reference: string | null = null,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

/**
 * The CSRF token lives in a module variable, never in localStorage or
 * sessionStorage. It dies with the tab, which is the point.
 */
let csrfToken: string | null = null

/**
 * The language the portal is being read in, sent on every request.
 *
 * Not everything on screen is translated in the browser: GEDCOM fact labels
 * ("Geburt", "Beruf"), formatted dates and webtrees' own privacy placeholders
 * are rendered by the server, which needs to be told which language to render
 * them in. Set from i18n, so there is one answer and the switch changes it.
 */
let requestLanguage: string | null = null

export function setRequestLanguage(language: string | null): void {
  requestLanguage = language
}

/** Called when any request comes back 401, so the app can reset itself. */
let onUnauthenticated: (() => void) | null = null

export function setUnauthenticatedHandler(handler: (() => void) | null): void {
  onUnauthenticated = handler
}

export function forgetCsrfToken(): void {
  csrfToken = null
}

export function rememberCsrfToken(token: string): void {
  csrfToken = token
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

interface RequestOptions {
  method?: string
  body?: unknown
  query?: Record<string, string | number | undefined>
  signal?: AbortSignal
}

async function ensureCsrfToken(): Promise<string> {
  if (csrfToken !== null) {
    return csrfToken
  }

  const token = await send<CsrfToken>('/csrf', {})
  csrfToken = token.csrf_token

  return csrfToken
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = new URL(BASE + path, window.location.origin)

  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== '') {
      url.searchParams.set(key, String(value))
    }
  }

  return url.pathname + url.search
}

async function send<T>(path: string, options: RequestOptions, csrf?: string): Promise<T> {
  const method = options.method ?? 'GET'
  const headers: Record<string, string> = { Accept: 'application/json' }

  if (requestLanguage !== null && requestLanguage !== '') {
    headers['Accept-Language'] = requestLanguage
  }

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  if (csrf !== undefined) {
    headers['X-CSRF-TOKEN'] = csrf
  }

  let response: Response

  try {
    response = await fetch(buildUrl(path, options.query), {
      method,
      headers,
      // Same origin: the Cloudflare Worker proxies /api onto the webtrees
      // host, so the session cookie is a first-party cookie.
      credentials: 'same-origin',
      cache: 'no-store',
      ...(options.body === undefined ? {} : { body: JSON.stringify(options.body) }),
      ...(options.signal === undefined ? {} : { signal: options.signal }),
    })
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === 'AbortError') {
      throw cause
    }

    throw new ApiError('network_error', 0, 'The portal could not reach the server.')
  }

  if (response.status === 401) {
    forgetCsrfToken()
    onUnauthenticated?.()
  }

  const payload = await readJson(response)

  if (!response.ok) {
    const body = payload as Partial<ApiErrorBody> | null

    throw new ApiError(
      body?.error ?? 'server_error',
      response.status,
      body?.message ?? response.statusText,
      body?.reference ?? null,
    )
  }

  return payload as T
}

async function readJson(response: Response): Promise<unknown> {
  const text = await response.text()

  if (text === '') {
    return null
  }

  try {
    return JSON.parse(text) as unknown
  } catch {
    return null
  }
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? 'GET'

  if (SAFE_METHODS.has(method)) {
    return send<T>(path, options)
  }

  try {
    return await send<T>(path, options, await ensureCsrfToken())
  } catch (error) {
    // A stale token is the expected failure after the session was recycled.
    // webtrees core answers a bad CSRF token on a POST with a redirect rather
    // than a 403, so a non-JSON reply means the same thing here. Either way,
    // fetch a fresh token and try once more.
    const stale =
      error instanceof ApiError &&
      (error.code === 'csrf_token_invalid' ||
        (error.status === 404 && !SAFE_METHODS.has(method)))

    if (!stale) {
      throw error
    }

    forgetCsrfToken()

    return send<T>(path, options, await ensureCsrfToken())
  }
}

export const api = {
  async csrf(): Promise<CsrfToken> {
    const token = await request<CsrfToken>('/csrf')
    rememberCsrfToken(token.csrf_token)

    return token
  },

  async login(credentials: Credentials): Promise<Me> {
    const me = await request<Me>('/session', { method: 'POST', body: credentials })
    rememberCsrfToken(me.csrf_token)

    return me
  },

  async logout(): Promise<void> {
    try {
      const token = await request<CsrfToken>('/session', { method: 'DELETE' })
      rememberCsrfToken(token.csrf_token)
    } catch {
      // Being unable to tell the server we left is not a reason to keep the
      // member signed in locally. The caller clears the client state either
      // way; the session cookie is httpOnly and short-lived.
      forgetCsrfToken()
    }
  },

  /**
   * Not used by any screen. It exists so that the deployment smoke check and
   * an uptime monitor have one request that proves the whole chain, and so
   * that the endpoint stays in step with openapi.yaml rather than drifting
   * because nothing in the client references it.
   */
  health(signal?: AbortSignal): Promise<Health> {
    return request<Health>('/health', signal === undefined ? {} : { signal })
  },

  me(signal?: AbortSignal): Promise<Me> {
    return request<Me>('/me', signal === undefined ? {} : { signal })
  },

  individual(xref: string, signal?: AbortSignal): Promise<Individual> {
    return request<Individual>(`/individuals/${encodeURIComponent(xref)}`, signal === undefined ? {} : { signal })
  },

  ancestors(xref: string, generations: number, signal?: AbortSignal): Promise<AncestorPage> {
    return request<AncestorPage>(`/individuals/${encodeURIComponent(xref)}/ancestors`, {
      query: { generations },
      ...(signal === undefined ? {} : { signal }),
    })
  },

  members(
    params: { q?: string; page?: number; per_page?: number },
    signal?: AbortSignal,
  ): Promise<MemberPage> {
    return request<MemberPage>('/members', {
      query: { q: params.q, page: params.page, per_page: params.per_page },
      ...(signal === undefined ? {} : { signal }),
    })
  },

  member(id: number, signal?: AbortSignal): Promise<MemberDetail> {
    return request<MemberDetail>(`/members/${id}`, signal === undefined ? {} : { signal })
  },

  updateProfile(changes: MemberProfileUpdate): Promise<MemberProfile> {
    return request<MemberProfile>('/me/profile', { method: 'PATCH', body: changes })
  },

  updateIndividual(changes: IndividualUpdate): Promise<PendingIndividual> {
    return request<PendingIndividual>('/me/individual', { method: 'PUT', body: changes })
  },

  requestPasswordReset(email: string): Promise<{ status: string }> {
    return request<{ status: string }>('/password/request', { method: 'POST', body: { email } })
  },

  /**
   * A successful reset signs the member in, exactly as webtrees' own does, so
   * this behaves like login: keep the token that came back with the session.
   */
  async resetPassword(token: string, password: string): Promise<Me> {
    const me = await request<Me>('/password/reset', { method: 'POST', body: { token, password } })
    rememberCsrfToken(me.csrf_token)

    return me
  },

  /**
   * Both invitation calls are POSTs, including the one that only reads.
   *
   * The token must not travel in a URL: it would end up in the webserver's
   * access log, in any proxy along the way, and in the `Referer` of every
   * request this page goes on to make. A body is the only place it can go
   * that none of those keep.
   */
  previewInvitation(token: string): Promise<InvitationPreview> {
    return request<InvitationPreview>('/invitation/preview', { method: 'POST', body: { token } })
  },

  /** Creates the account and signs the new member in, so it behaves like login. */
  async acceptInvitation(details: InvitationAcceptance): Promise<Me> {
    const me = await request<Me>('/invitation/accept', { method: 'POST', body: details })
    rememberCsrfToken(me.csrf_token)

    return me
  },
}
