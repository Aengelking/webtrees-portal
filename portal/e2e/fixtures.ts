import type { Page, Route } from '@playwright/test'
import type { Connection } from '../src/api/types'

/**
 * Enough of the API to walk the smoke path, matching openapi.yaml.
 *
 * When E2E_BASE_URL is set the run is pointed at a real deployment and these
 * are not installed, so the same spec exercises the real backend.
 */

const ANNA = {
  xref: 'X1',
  name: 'Anna Beispiel',
  sex: 'F',
  is_deceased: false,
  lifespan: '1985–',
  name_alternative: null,
  relationship: null,
  references: [
    { number: '4711', type: 'SB', branch: null },
    { number: '10/1335.11', type: 'SB', branch: 'Ernestinische Linie – Zweig Cleve' },
  ],
  birth: {
    tag: 'INDI:BIRT',
    label: 'Geburt',
    value: null,
    date: { display: '12. März 1985', gedcom: '12 MAR 1985', year: 1985 },
    place: 'Hannover, Niedersachsen, Deutschland',
  },
  death: null,
  events: [
    {
      tag: 'INDI:BIRT',
      label: 'Geburt',
      value: null,
      date: { display: '12. März 1985', gedcom: '12 MAR 1985', year: 1985 },
      place: 'Hannover, Niedersachsen, Deutschland',
    },
    { tag: 'INDI:OCCU', label: 'Beruf', value: 'Tischlerin', date: null, place: null },
  ],
  parents: [
    { xref: 'X2', name: 'Bertha Beispiel', sex: 'F', is_deceased: true, lifespan: '1889–1976' },
  ],
  siblings: [
    { xref: 'X4', name: 'Dieter Beispiel', sex: 'M', is_deceased: false, lifespan: '1990–' },
  ],
  spouses: [],
  children: [],
  pending_change: false,
  webtrees_url: 'https://webtrees.example.org/tree/portal/individual/X1',
}

const ME = {
  user: {
    id: 1,
    username: 'anna',
    real_name: 'Anna Beispiel',
    email: 'anna@example.test',
    language: 'de',
    role: 'member',
  },
  profile: {
    id: 1,
    visible_in_directory: true,
    display_name_override: null,
    consent_recorded_at: '2026-01-01 00:00:00',
    directory_decided: true,
  },
  individual: ANNA,
  tree: { name: 'portal', title: 'Familie Beispiel' },
  csrf_token: 'token-1',
}

const MEMBERS = [
  { id: 1, display_name: 'Anna Beispiel', individual: refOf(ANNA) },
  {
    id: 2,
    display_name: 'Dieter Beispiel',
    individual: {
      xref: 'X4',
      name: 'Dieter Beispiel',
      sex: 'M',
      is_deceased: false,
      lifespan: '1990–',
      references: [{ number: '4714', type: 'SB', branch: null }],
      office: 'Vorsitzender des Vorstands',
    },
  },
  // Nora's record is closed to this reader, and her office reaches the row
  // beside it rather than inside it — the two ways it can arrive, both on
  // the one screen. See NOTES §2.82.
  { id: 3, display_name: 'Nora Ohnesatz', individual: null, office: 'Schriftführerin' },
]

function refOf(individual: typeof ANNA) {
  return {
    xref: individual.xref,
    name: individual.name,
    sex: individual.sex,
    is_deceased: individual.is_deceased,
    lifespan: individual.lifespan,
  }
}

/**
 * The words the *server* would have translated, for a run in English.
 *
 * Fact labels and kinship phrases are webtrees', not the portal's: in the real
 * thing they arrive already in the member's language, and a stub that answers
 * a signed-in English member in German produces a screen that exists nowhere —
 * half translated, which is exactly the sort of picture nobody should print.
 *
 * Only whole JSON string values are replaced, so a name that happens to
 * contain one of these words is left alone. Family data — a message about the
 * Familientreffen, the tree's own title — stays as the family wrote it, which
 * is what an English-reading member really sees.
 */
const AS_THE_SERVER_WOULD_SAY: Record<string, string> = {
  Geburt: 'Birth',
  Tod: 'Death',
  Beruf: 'Occupation',
  Heirat: 'Marriage',
  Bruder: 'brother',
  Schwester: 'sister',
  Mutter: 'mother',
  Vater: 'father',
  Großmutter: 'grandmother',
  Großvater: 'grandfather',
  // Dates are webtrees' too — it writes them out in the reader's language,
  // and a German date under an English label is the half-translated screen
  // this map exists to avoid. Only the fixture's own dates need an entry.
  '12. März 1985': '12 March 1985',
  '4. Juni 1990': '4 June 1990',
}

let speakEnglish = false

function json(route: Route, body: unknown, status = 200): Promise<void> {
  let serialised = JSON.stringify(body)

  if (speakEnglish) {
    for (const [german, english] of Object.entries(AS_THE_SERVER_WOULD_SAY)) {
      serialised = serialised.split(`"${german}"`).join(`"${english}"`)
    }
  }

  return route.fulfill({
    status,
    contentType: 'application/json',
    headers: { 'Cache-Control': 'private, no-store' },
    body: serialised,
  })
}

/**
 * Answer the install offer before it is asked.
 *
 * The portal asks once, after signing in, and blocks the screen until it is
 * answered — which is the point of it and would stop every walk below on the
 * first tap. The offer has its own test; everything else says "already asked"
 * and gets on with what it came to check.
 */
export async function installOfferAnswered(page: Page): Promise<void> {
  await page.addInitScript(() => {
    try {
      window.localStorage.setItem('portal.install.offered', '1')
      // The notification offer only appears inside the installed app, so no
      // walk here should meet it — answered anyway, because a dialogue that
      // turns up unexpectedly would stop every one of them and the failure
      // would look like anything but this.
      window.localStorage.setItem('portal.notifications.offered', '1')
    } catch {
      // A browser with no storage will show the offer. Nothing to do here.
    }
  })
}

/**
 * @param options.language what the account says, and therefore what the portal
 *   reads in. Defaults to German, which is what the family reads.
 */
export async function stubApi(page: Page, options: { language?: 'de' | 'en' } = {}): Promise<void> {
  const language = options.language ?? 'de'

  speakEnglish = language === 'en'

  let signedIn = false
  let pendingChange = false
  let visibleInDirectory = true
  let invitedDieter = false
  // Karla is listed in the directory, so an unanswered request to her is
  // reported back on her page — see `Connections::recordState`.
  let askedKarla = false
  let inbox = [
    {
      id: 9,
      from: 'Dieter Beispiel',
      subject: 'Familientreffen',
      body: 'Kommst du zum Familientreffen?',
      sent_at: '2026-08-01T10:00:00+00:00',
      read: false,
      can_reply: true,
    },
  ]


  // Phase 11. Karla is waiting for an answer; Dieter is already a contact.
  let connections: Connection[] = [
    {
      id: 7,
      status: 'accepted',
      source: 'code',
      requested_by_me: true,
      member_id: 2,
      name: 'Dieter Beispiel',
      individual: { xref: 'X4', name: 'Dieter Beispiel', sex: 'M', is_deceased: false, lifespan: '1990–' },
      since: '2026-08-01T10:00:00+00:00',
    },
  ]
  let incoming: Connection[] = [
    {
      id: 9,
      status: 'pending',
      source: 'reference',
      requested_by_me: false,
      member_id: null,
      name: 'Karla Beispiel',
      individual: null,
      since: '2026-08-02T10:00:00+00:00',
    },
  ]

  const requestedMembers = new Set<number>()

  // Anna is member 1 — herself. Dieter (2) is already a contact, and Nora (3)
  // is the row the smoke path sends a request from.
  const connectionOn = (memberId: number) => {
    if (memberId === 1) {
      return { status: 'self', id: null }
    }

    const connected = connections.some((connection) => connection.member_id === memberId)

    if (connected) {
      return { status: 'connected', id: 7 }
    }

    return requestedMembers.has(memberId) ? { status: 'requested', id: 11 } : { status: 'none', id: null }
  }

  const connectionOverview = () => ({
    enabled: true,
    code_valid_minutes: 15,
    link_valid_days: 7,
    links: [],
    connections,
    incoming,
    outgoing: [],
  })

  const transcript: {
    id: number
    mine: boolean
    body: string
    sent_at: string
    read: boolean
  }[] = [
    {
      id: 1,
      mine: false,
      body: 'Hast du die Fotos von Oma gesehen?',
      sent_at: '2026-08-01T10:00:00+00:00',
      read: true,
    },
  ]

  /**
   * The family's mailing lists. Stateful, so that a browser test can flip a
   * switch and see the answer come back rather than only that a request left.
   */
  let lists: {
    key: string
    name: string
    description: string
    subscribed: boolean
    state: 'applied' | 'pending' | 'failed'
  }[] = [
    {
      key: 'a1b2c3',
      name: 'Familiennachrichten',
      description: 'Ein- bis zweimal im Jahr.',
      subscribed: false,
      state: 'applied',
    },
    {
      key: 'd4e5f6',
      name: 'Einladungen',
      description: '',
      subscribed: true,
      state: 'applied',
    },
  ]

  const conversationSummary = () => ({
    id: 3,
    member_id: 2,
    name: 'Dieter Beispiel',
    unread: 0,
    last_message: transcript[transcript.length - 1],
  })

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace('/api/v1', '')
    const method = route.request().method()

    if (path === '/csrf') {
      // `remember_days` is what decides whether the login screen draws the
      // "Angemeldet bleiben" switch at all. A stub that omitted it made the
      // switch invisible to every browser test, which is how it went
      // uncovered.
      return json(route, { csrf_token: 'token-1', remember_days: 30 })
    }

    if (path === '/session' && method === 'POST') {
      const body = route.request().postDataJSON() as { username?: string; password?: string }

      if (body.username === 'anna' && body.password === 'geheim') {
        signedIn = true

        // The real handler answers a sign-in with the same payload as `/me`,
        // unread count included — and the portal keeps that answer rather
        // than asking again. A stub that dropped the count would make the
        // badge look broken here and only here.
        return json(route, {
          ...ME,
          user: { ...ME.user, language },
          unread_messages: inbox.filter((m) => !m.read).length,
          unread_conversations: 0,
          connection_requests: incoming.length,
        })
      }

      return json(
        route,
        { error: 'invalid_credentials', message: 'The username or password is incorrect.' },
        401,
      )
    }

    if (path === '/password/request' && method === 'POST') {
      return json(route, { status: 'accepted' }, 202)
    }

    // Phase 5. One invitation, spelled "einladung-fuer-anna"; anything else
    // is refused the way an expired or spent one is.
    if (path === '/invitation/preview' && method === 'POST') {
      const body = route.request().postDataJSON() as { token?: string }

      if (body.token !== 'einladung-fuer-anna') {
        return json(route, { error: 'invalid_token', message: 'Expired.' }, 400)
      }

      return json(route, {
        tree: { name: 'portal', title: 'Familie Beispiel' },
        invited_name: 'Anna Beispiel',
        email: 'anna@example.test',
        expires_at: '2026-09-01T12:00:00+00:00',
      })
    }

    if (path === '/invitation/accept' && method === 'POST') {
      const body = route.request().postDataJSON() as { token?: string; username?: string }

      if (body.token !== 'einladung-fuer-anna') {
        return json(route, { error: 'invalid_token', message: 'Expired.' }, 400)
      }

      if (body.username === 'anna') {
        return json(route, { error: 'username_taken', message: 'Duplicate username.' }, 409)
      }

      signedIn = true

      return json(route, { ...ME, user: { ...ME.user, language } }, 201)
    }

    if (path === '/session' && method === 'DELETE') {
      signedIn = false
      return json(route, { csrf_token: 'token-2' })
    }

    if (!signedIn) {
      return json(route, { error: 'unauthenticated', message: 'Please sign in.' }, 401)
    }

    // Phase 7. Anna may invite her brother Dieter, and nobody else.
    if (path === '/invitations' && method === 'GET') {
      return json(route, {
        enabled: true,
        linked: true,
        quota: 3,
        remaining: invitedDieter ? 2 : 3,
        candidates: invitedDieter
          ? []
          : [
              {
                xref: 'X4',
                name: 'Dieter Beispiel',
                sex: 'M',
                is_deceased: false,
                lifespan: '1990–',
                portrait: null,
                relationship: 'Bruder',
              },
            ],
        invitations: invitedDieter
          ? [{ id: 9, name: 'Dieter Beispiel', email: null, expires_at: '2026-09-01T12:00:00+00:00' }]
          : [],
      })
    }

    if (path === '/invitations' && method === 'POST') {
      const body = route.request().postDataJSON() as { xref?: string }

      if (body.xref !== 'X4') {
        return json(route, { error: 'not_allowed', message: 'No.' }, 403)
      }

      invitedDieter = true

      return json(
        route,
        {
          link: 'https://portal.example.test/invitation?token=einladung-fuer-dieter',
          invitation: { id: 9, name: 'Dieter Beispiel', email: null, expires_at: '2026-09-01T12:00:00+00:00' },
        },
        201,
      )
    }

    if (path.startsWith('/invitations/') && method === 'DELETE') {
      invitedDieter = false

      return json(route, { enabled: true, linked: true, quota: 3, remaining: 3, candidates: [], invitations: [] })
    }

    // Phase 9. Anna shares a telephone number with every member.
    // Phase 12. One conversation, which starts with one thing said.
    if (path === '/conversations' && method === 'GET') {
      return json(route, { conversations: [conversationSummary()] })
    }

    if (path === '/conversations' && method === 'POST') {
      return json(route, { conversation: conversationSummary() })
    }

    if (path === '/conversations/3/messages' && method === 'POST') {
      const body = route.request().postDataJSON() as { body: string }

      transcript.push({
        id: transcript.length + 1,
        mine: true,
        body: body.body,
        sent_at: '2026-08-01T12:00:00+00:00',
        read: false,
      })

      return json(route, { message: transcript[transcript.length - 1] }, 201)
    }

    if (path.startsWith('/conversations/3')) {
      return json(route, { conversation: conversationSummary(), messages: transcript, before: null })
    }

    // Phase 10. One unread message, which the badge counts.
    if (path === '/messages' && method === 'GET') {
      return json(route, { messages: inbox, unread: inbox.filter((m) => !m.read).length })
    }

    if (path.endsWith('/reply') && method === 'POST') {
      return json(route, { status: 'sent' }, 202)
    }

    if (path.startsWith('/messages/') && method === 'PATCH') {
      const { read } = route.request().postDataJSON() as { read: boolean }
      inbox = inbox.map((m) => ({ ...m, read }))
      return json(route, { messages: inbox, unread: inbox.filter((m) => !m.read).length })
    }

    if (path.startsWith('/messages/') && method === 'DELETE') {
      inbox = []
      return json(route, { messages: inbox, unread: 0 })
    }

    if (path === '/me/mailing-lists') {
      if (method === 'PATCH') {
        const sent = route.request().postDataJSON() as { lists: Record<string, boolean> }

        lists = lists.map((list) =>
          list.key in sent.lists
            ? { ...list, subscribed: sent.lists[list.key] as boolean, state: 'applied' }
            : list,
        )
      }

      return json(route, { enabled: true, address: 'anna@example.test', lists })
    }

    if (path === '/me/contact') {
      if (method === 'PATCH') {
        const sent = route.request().postDataJSON() as { contact: Record<string, unknown> }
        return json(route, { enabled: true, contact: sent.contact })
      }

      return json(route, {
        enabled: true,
        contact: { phone: { value: '0511 12345', audience: 'members' } },
      })
    }

    if (path.endsWith('/message') && method === 'POST') {
      return json(route, { status: 'sent' }, 202)
    }

    if (path === '/me') {
      return json(route, {
        ...ME,
        // The account decides what the portal reads in, so this is where a
        // run in English has to say so — the switcher's stored value only
        // answers for the moment before the portal knows who is reading.
        user: { ...ME.user, language },
        unread_messages: inbox.filter((m) => !m.read).length,
        unread_conversations: 0,
        connection_requests: incoming.length,
        profile: { ...ME.profile, visible_in_directory: visibleInDirectory },
        individual: { ...ANNA, pending_change: pendingChange },
      })
    }

    if (path === '/me/connection-link' && method === 'POST') {
      return json(
        route,
        {
          url: 'https://portal.example.test/connect?code=link-fuer-anna',
          expires_at: '2026-08-28T12:00:00+00:00',
          valid_days: 7,
        },
        201,
      )
    }

    if (path.startsWith('/me/connection-links/') && method === 'DELETE') {
      return json(route, connectionOverview())
    }

    if (path === '/me/connection-code') {
      return method === 'DELETE'
        ? json(route, { status: 'revoked' })
        : json(
            route,
            {
              url: 'https://portal.example.test/connect?code=code-fuer-anna',
              expires_at: '2026-08-21T12:15:00+00:00',
              valid_minutes: 15,
            },
            201,
          )
    }

    if (path === '/connections' && method === 'GET') {
      return json(route, connectionOverview())
    }

    if (path === '/connections' && method === 'POST') {
      const body = route.request().postDataJSON() as {
        code?: string
        reference?: string
        member_id?: number
        xref?: string
      }

      // From a person's page. The quiet answer, which is the only one the
      // server gives for a record it will not talk about.
      if (body.xref !== undefined) {
        askedKarla = true

        return json(route, { ...connectionOverview(), status: 'requested', name: null }, 201)
      }

      if (body.code === 'code-fuer-anna') {
        return json(route, { ...connectionOverview(), status: 'connected', name: 'Emil Beispiel' }, 201)
      }

      if (body.member_id !== undefined) {
        requestedMembers.add(body.member_id)

        return json(route, { ...connectionOverview(), status: 'requested', name: 'Nora Ohnesatz' }, 201)
      }

      return json(route, { ...connectionOverview(), status: 'requested', name: 'Emil Beispiel' }, 201)
    }

    if (path.startsWith('/connections/') && method === 'PATCH') {
      connections = [
        ...connections,
        ...incoming.map((connection) => ({ ...connection, status: 'accepted' as const, member_id: 4 })),
      ]
      incoming = []

      return json(route, { ...connectionOverview(), status: 'connected', name: 'Karla Beispiel' })
    }

    if (path.startsWith('/connections/') && method === 'DELETE') {
      const id = Number(path.split('/')[2])

      connections = connections.filter((connection) => connection.id !== id)
      incoming = incoming.filter((connection) => connection.id !== id)

      return json(route, connectionOverview())
    }

    if (path === '/me/individual' && method === 'PUT') {
      pendingChange = true
      return json(route, { status: 'pending_approval', pending_change: true, individual: ANNA }, 202)
    }

    if (path === '/me/profile' && method === 'PATCH') {
      const body = route.request().postDataJSON() as {
        visible_in_directory?: boolean
        display_name_override?: string | null
      }

      if (body.visible_in_directory !== undefined) {
        visibleInDirectory = body.visible_in_directory
      }

      return json(route, {
        id: 1,
        visible_in_directory: visibleInDirectory,
        display_name_override: body.display_name_override ?? null,
        consent_recorded_at: visibleInDirectory ? '2026-08-17 12:00:00' : null,
        directory_decided: true,
      })
    }

    if (path === '/members') {
      const q = (url.searchParams.get('q') ?? '').toLowerCase()
      const items = MEMBERS.filter((member) => member.display_name.toLowerCase().includes(q)).map(
        (member) => ({ ...member, connection: connectionOn(member.id) }),
      )

      return json(route, {
        items,
        total: items.length,
        page: 1,
        per_page: 25,
        connections_enabled: true,
      })
    }

    // Dieter: alive, no account, and the one person whose page can offer an
    // invitation. `invitable` is the server's answer — the screen no longer
    // works it out from a list of candidates, because an editor's list is the
    // whole archive.
    if (path === '/individuals/X4') {
      const dieterBirth = {
        tag: 'INDI:BIRT',
        label: 'Geburt',
        value: null,
        date: { display: '4. Juni 1990', gedcom: '4 JUN 1990', year: 1990 },
        place: 'Celle, Niedersachsen, Deutschland',
      }

      return json(route, {
        ...ANNA,
        xref: 'X4',
        name: 'Dieter Beispiel',
        sex: 'M',
        lifespan: '1990–',
        is_deceased: false,
        relationship: 'Bruder',
        references: [],
        // His own facts, not his sister's. Spreading ANNA is a convenience
        // for the fields nothing here cares about; leaving her birth and her
        // trade on his page put a man born in 1985 and working as a
        // Tischlerin in front of a camera.
        birth: dieterBirth,
        events: [
          dieterBirth,
          { tag: 'INDI:OCCU', label: 'Beruf', value: 'Landwirt', date: null, place: null },
        ],
        parents: [],
        siblings: [],
        invitable: !invitedDieter,
        // "The offer is worth making" — and deliberately not "he has an
        // account": the server says the same word for a member who stayed out
        // of the directory and for a relative with no account at all.
        connection: 'open',
      })
    }

    // Karla: alive, and nobody this member could invite — too far out in the
    // tree for that. So her page carries the offer to connect and no
    // invitation, which is the pair the person screen has to get right.
    if (path === '/individuals/X7') {
      return json(route, {
        ...ANNA,
        xref: 'X7',
        name: 'Karla Beispiel',
        sex: 'F',
        lifespan: '1978–',
        is_deceased: false,
        relationship: null,
        references: [],
        parents: [],
        siblings: [],
        invitable: false,
        connection: askedKarla ? 'requested' : 'open',
      })
    }

    if (path === '/individuals/X2') {
      return json(route, {
        ...ANNA,
        xref: 'X2',
        name: 'Bertha Beispiel',
        lifespan: '1889–1976',
        is_deceased: true,
        relationship: 'Mutter',
        references: [],
        parents: [],
        siblings: [],
      })
    }

    if (path === '/individuals/X1/ancestors') {
      return json(route, {
        generations: 20,
        truncated: false,
        people: [
          { position: 1, generation: 0, private: false, ...refOf(ANNA) },
          // Anna's father is alive and out of reach: a rung, and no more.
          { position: 2, generation: 1, private: true, member: null },
          { position: 3, generation: 1, private: false, xref: 'X2', name: 'Bertha Beispiel', sex: 'F', is_deceased: true, lifespan: '1889–1976' },
        ],
      })
    }

    if (path === '/members/1') {
      // Anna is the signed-in member, so this is her own page: the real
      // endpoint refuses a message to oneself and says so with can_message.
      return json(route, {
        ...MEMBERS[0],
        individual_detail: ANNA,
        contact: {},
        can_message: false,
      })
    }

    if (path === '/members/2') {
      return json(route, {
        ...MEMBERS[1],
        individual_detail: null,
        contact: { phone: '0511 12345' },
        can_message: true,
      })
    }

    if (path === '/members/3') {
      return json(route, {
        ...MEMBERS[2],
        individual_detail: null,
        contact: {},
        can_message: true,
      })
    }

    // Phase 16. Looking through the archive: a search, and two indexes.
    if (path === '/search') {
      const surname = url.searchParams.get('surname') ?? ''
      const place = url.searchParams.get('place') ?? ''
      const q = (url.searchParams.get('q') ?? '').toLowerCase()

      const matched = ARCHIVE.filter((person) => {
        if (surname !== '') return person.surname === surname
        if (place !== '') return person.places.includes(place)

        return (
          q !== '' &&
          (person.name.toLowerCase().includes(q) ||
            person.references.some((reference) => reference.number === q))
        )
      })

      return json(route, {
        items: matched.map(({ surname: _surname, places: _places, ...person }) => person),
        total: matched.length,
        page: 1,
        per_page: 25,
        truncated: false,
      })
    }

    if (path === '/relationship') {
      const a = url.searchParams.get('a') ?? ''
      const b = url.searchParams.get('b') ?? ''
      const valid = /^\d{1,2}\/[1-9a-z. ]+$/i

      return json(route, {
        a,
        b,
        problem: !valid.test(a) ? 'invalid_a' : !valid.test(b) ? 'invalid_b' : null,
        relationship: valid.test(a) && valid.test(b) ? 'Cousin/Cousine 3. Grades' : null,
        detail:
          valid.test(a) && valid.test(b)
            ? { kind: 'cousin', generations: 0, distance: 4, degree: 3 }
            : null,
      })
    }

    if (path === '/index') {
      return json(route, {
        surnames: [
          { name: 'Beispiel', count: 2 },
          { name: 'Fernab', count: 1 },
        ],
        places: [{ name: 'Celle, Niedersachsen, Deutschland', count: 1 }],
        truncated: false,
      })
    }

    return json(route, { error: 'not_found', message: 'This item does not exist.' }, 404)
  })
}

/**
 * The handful of people the search fixture knows about.
 *
 * `surname` and `places` are not part of the API's own shape — they are what
 * the fixture matches on, so that a spec can tap an index entry and get a
 * believable answer back.
 */
interface ArchiveEntry {
  xref: string
  name: string
  sex: string
  is_deceased: boolean
  lifespan: string
  portrait: null
  references: { number: string; type: string; branch: string | null }[]
  relationship: string | null
  surname: string
  places: string[]
}

const ARCHIVE: ArchiveEntry[] = [
  {
    xref: 'X2',
    name: 'Bertha Beispiel',
    sex: 'F',
    is_deceased: true,
    lifespan: '1889–1976',
    portrait: null,
    references: [{ number: '4712', type: 'SB', branch: null }],
    relationship: 'Großmutter',
    surname: 'Beispiel',
    places: ['Celle, Niedersachsen, Deutschland'],
  },
  {
    xref: 'X5',
    name: 'Emil Beispiel',
    sex: 'M',
    is_deceased: true,
    lifespan: '1884–1961',
    portrait: null,
    references: [],
    relationship: 'Großvater',
    surname: 'Beispiel',
    places: [],
  },
  {
    xref: 'X12',
    name: 'Otto Fernab',
    sex: 'M',
    is_deceased: true,
    lifespan: '1830–1899',
    portrait: null,
    references: [],
    relationship: null,
    surname: 'Fernab',
    places: [],
  },
]
