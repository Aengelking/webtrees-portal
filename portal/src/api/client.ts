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
  ConnectionCode,
  ConnectionLink,
  ConnectionOverview,
  ConnectionRequest,
  ConnectionResult,
  Credentials,
  CsrfToken,
  Health,
  ContactSettings,
  Conversation,
  ConversationMessage,
  Inbox,
  Individual,
  IndividualUpdate,
  InvitationOverview,
  IssuedInvitation,
  InvitationAcceptance,
  InvitationPreview,
  MailingLists,
  Me,
  MemberDetail,
  MemberPage,
  MemberProfile,
  MemberProfileUpdate,
  OwnContact,
  Photo,
  PendingIndividual,
  PushState,
  RelationshipResult,
  SearchPage,
  Transcript,
  TreeIndex,
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
  /**
   * A file, rather than JSON. The two are exclusive: `FormData` sets its own
   * `Content-Type` with the multipart boundary in it, and a `Content-Type`
   * written by hand would make the body unparseable on the far side.
   */
  form?: FormData
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

  // Nothing set for a form: the browser writes the multipart boundary, and it
  // is the only thing that can.

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
      ...(options.form === undefined ? {} : { body: options.form }),
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

  /**
   * The pedigree of a member, by member id rather than by XREF.
   *
   * A different door onto the same room, for the case the other one cannot
   * open: somebody whose record is closed to this reader has no XREF here to
   * ask with. See the module's `MemberAncestorsRead`.
   */
  memberAncestors(id: number, generations: number, signal?: AbortSignal): Promise<AncestorPage> {
    return request<AncestorPage>(`/members/${id}/ancestors`, {
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

  /**
   * Looking through the tree: a name, a reference number, a surname, a place.
   *
   * One call for all four because the server treats them as one question with
   * four ways of asking — see openapi.yaml.
   */
  search(
    params: { q?: string; surname?: string; place?: string; page?: number },
    signal?: AbortSignal,
  ): Promise<SearchPage> {
    return request<SearchPage>('/search', {
      query: {
        q: params.q,
        surname: params.surname,
        place: params.place,
        page: params.page,
        per_page: 25,
      },
      ...(signal === undefined ? {} : { signal }),
    })
  },

  /**
   * How two archive numbers are related.
   *
   * Touches no records at all — the number *is* the ancestral path, so this is
   * arithmetic on two strings. See openapi.yaml.
   */
  relationship(a: string, b: string, signal?: AbortSignal): Promise<RelationshipResult> {
    return request<RelationshipResult>('/relationship', {
      query: { a, b },
      ...(signal === undefined ? {} : { signal }),
    })
  },

  /** The surnames and the places, for reading down rather than querying. */
  treeIndex(signal?: AbortSignal): Promise<TreeIndex> {
    return request<TreeIndex>('/index', signal === undefined ? {} : { signal })
  },

  member(id: number, signal?: AbortSignal): Promise<MemberDetail> {
    return request<MemberDetail>(`/members/${id}`, signal === undefined ? {} : { signal })
  },

  /** Whom may I invite, whom have I invited, and how many have I left? */
  invitations(signal?: AbortSignal): Promise<InvitationOverview> {
    return request<InvitationOverview>('/invitations', signal === undefined ? {} : { signal })
  },

  invite(xref: string, email: string): Promise<IssuedInvitation> {
    return request<IssuedInvitation>('/invitations', { method: 'POST', body: { xref, email } })
  },

  withdrawInvitation(id: number): Promise<InvitationOverview> {
    return request<InvitationOverview>(`/invitations/${id}`, { method: 'DELETE' })
  },

  /** Everything addressed to me, not only what the portal sent. */
  messages(signal?: AbortSignal): Promise<Inbox> {
    return request<Inbox>('/messages', signal === undefined ? {} : { signal })
  },

  markMessage(id: number, read: boolean): Promise<Inbox> {
    return request<Inbox>(`/messages/${id}`, { method: 'PATCH', body: { read } })
  },

  deleteMessage(id: number): Promise<Inbox> {
    return request<Inbox>(`/messages/${id}`, { method: 'DELETE' })
  },

  /** No subject: a reply carries webtrees' `RE: ` on the original, server-side. */
  replyToMessage(id: number, body: string): Promise<{ status: string }> {
    return request<{ status: string }>(`/messages/${id}/reply`, { method: 'POST', body: { body } })
  },

  /** Whether this portal can knock, and the key a browser needs to be knocked on. */
  push(signal?: AbortSignal): Promise<PushState> {
    return request<PushState>('/push', signal === undefined ? {} : { signal })
  },

  /**
   * Remember this device. Only the endpoint is sent: the keys a browser also
   * offers exist to encrypt a payload, and this portal sends none.
   */
  subscribeToPush(endpoint: string): Promise<PushState> {
    return request<PushState>('/push', { method: 'POST', body: { endpoint } })
  },

  unsubscribeFromPush(endpoint: string): Promise<PushState> {
    return request<PushState>('/push', { method: 'DELETE', body: { endpoint } })
  },

  /**
   * A photograph of oneself, which is the only kind that can be added.
   *
   * No id goes with it: the record is the one the signed-in account is linked
   * to. A photograph is permission, and nobody gives permission on somebody
   * else's behalf.
   */
  addPhoto(file: File): Promise<{ photos: Photo[]; pending: boolean }> {
    const form = new FormData()

    form.append('photo', file)

    return request<{ photos: Photo[]; pending: boolean }>('/photos', { method: 'POST', form })
  },

  /** Withdrawing the permission, which is what makes it one. */
  removePhoto(xref: string): Promise<{ photos: Photo[] }> {
    return request<{ photos: Photo[] }>(`/photos/${encodeURIComponent(xref)}`, {
      method: 'DELETE',
    })
  },

  /** The exchanges the portal keeps a transcript of. Not the webtrees inbox. */
  conversations(signal?: AbortSignal): Promise<{ conversations: Conversation[] }> {
    return request<{ conversations: Conversation[] }>(
      '/conversations',
      signal === undefined ? {} : { signal },
    )
  },

  /**
   * Open the conversation with a member, or find the one already there.
   *
   * This is the call the directory rule guards — opening a conversation is
   * finding somebody. Writing into one afterwards is not guarded that way.
   */
  openConversation(memberId: number): Promise<{ conversation: Conversation }> {
    return request<{ conversation: Conversation }>('/conversations', {
      method: 'POST',
      body: { member_id: memberId },
    })
  },

  /** Reading marks the other side's messages read, which is what opening means. */
  conversation(id: number, before?: number, signal?: AbortSignal): Promise<Transcript> {
    const query = before === undefined ? '' : `?before=${before}`

    return request<Transcript>(`/conversations/${id}${query}`, signal === undefined ? {} : { signal })
  },

  /** No subject: a conversation is one thread with one other person. */
  sendConversationMessage(id: number, body: string): Promise<{ message: ConversationMessage }> {
    return request<{ message: ConversationMessage }>(`/conversations/${id}/messages`, {
      method: 'POST',
      body: { body },
    })
  },

  /** For me. The other side keeps their copy — see openapi.yaml. */
  deleteConversationMessage(id: number, message: number): Promise<Transcript> {
    return request<Transcript>(`/conversations/${id}/messages/${message}`, { method: 'DELETE' })
  },

  clearConversation(id: number): Promise<{ conversations: Conversation[] }> {
    return request<{ conversations: Conversation[] }>(`/conversations/${id}`, { method: 'DELETE' })
  },

  /** Whom I know, who is waiting for an answer, and whom I have asked. */
  connections(signal?: AbortSignal): Promise<ConnectionOverview> {
    return request<ConnectionOverview>('/connections', signal === undefined ? {} : { signal })
  },

  /**
   * Connect with somebody, one of the three ways.
   *
   * The code goes in the body rather than in a query string for the same
   * reason an invitation token does: a body is the one place a webserver log,
   * a proxy and an outgoing `Referer` header do not keep.
   */
  connect(how: ConnectionRequest): Promise<ConnectionResult> {
    return request<ConnectionResult>('/connections', { method: 'POST', body: how })
  },

  acceptConnection(id: number): Promise<ConnectionResult> {
    return request<ConnectionResult>(`/connections/${id}`, {
      method: 'PATCH',
      body: { status: 'accepted' },
    })
  },

  /** Declining, withdrawing and disconnecting are one call, because they are one act. */
  removeConnection(id: number): Promise<ConnectionOverview> {
    return request<ConnectionOverview>(`/connections/${id}`, { method: 'DELETE' })
  },

  /** Issues a new code and kills the one before it, so it is a POST. */
  createConnectionCode(): Promise<ConnectionCode> {
    return request<ConnectionCode>('/me/connection-code', { method: 'POST' })
  },

  revokeConnectionCode(): Promise<{ status: string }> {
    return request<{ status: string }>('/me/connection-code', { method: 'DELETE' })
  },

  /** A new link every time: the server keeps a hash and cannot repeat one. */
  createConnectionLink(): Promise<ConnectionLink> {
    return request<ConnectionLink>('/me/connection-link', { method: 'POST' })
  },

  revokeConnectionLink(id: number): Promise<ConnectionOverview> {
    return request<ConnectionOverview>(`/me/connection-links/${id}`, { method: 'DELETE' })
  },

  /** What I share, and with whom. Mine only — the audience does not apply to me. */
  contact(signal?: AbortSignal): Promise<ContactSettings> {
    return request<ContactSettings>('/me/contact', signal === undefined ? {} : { signal })
  },

  updateContact(changes: OwnContact): Promise<ContactSettings> {
    return request<ContactSettings>('/me/contact', { method: 'PATCH', body: { contact: changes } })
  },

  /**
   * The family's mailing lists, and my answer to each.
   *
   * Reading is also when an outstanding change is retried on the server, so
   * this is worth asking for again after one comes back `pending`.
   */
  mailingLists(signal?: AbortSignal): Promise<MailingLists> {
    return request<MailingLists>('/me/mailing-lists', signal === undefined ? {} : { signal })
  },

  /** One switch at a time: a list left out of the body is left alone. */
  updateMailingLists(changes: Record<string, boolean>): Promise<MailingLists> {
    return request<MailingLists>('/me/mailing-lists', { method: 'PATCH', body: { lists: changes } })
  },

  /** The id is a portal member id — only a directory member can be written to. */
  sendMessage(id: number, subject: string, body: string): Promise<{ status: string }> {
    return request<{ status: string }>(`/members/${id}/message`, {
      method: 'POST',
      body: { subject, body },
    })
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
  /**
   * Answer a letter that went to a mailing list.
   *
   * The campaign token grants nothing; it says which letter is being answered.
   * The response is the same whatever the server found — on a list, on no
   * list, already an account — so there is nothing here for the screen to
   * branch on, which is the point.
   */
  claimInvitation(campaign: string, email: string): Promise<{ status: string }> {
    return request<{ status: string }>('/invitation/claim', {
      method: 'POST',
      body: { campaign, email },
    })
  },

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
