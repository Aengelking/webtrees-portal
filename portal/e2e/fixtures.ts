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
  references: [{ number: '4711', type: 'SB' }],
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
    individual: { xref: 'X4', name: 'Dieter Beispiel', sex: 'M', is_deceased: false, lifespan: '1990–' },
  },
  { id: 3, display_name: 'Nora Ohnesatz', individual: null },
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

function json(route: Route, body: unknown, status = 200): Promise<void> {
  return route.fulfill({
    status,
    contentType: 'application/json',
    headers: { 'Cache-Control': 'private, no-store' },
    body: JSON.stringify(body),
  })
}

export async function stubApi(page: Page): Promise<void> {
  let signedIn = false
  let pendingChange = false
  let visibleInDirectory = true
  let invitedDieter = false
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

  const connectionOverview = () => ({
    enabled: true,
    code_valid_minutes: 15,
    connections,
    incoming,
    outgoing: [],
  })

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace('/api/v1', '')
    const method = route.request().method()

    if (path === '/csrf') {
      return json(route, { csrf_token: 'token-1' })
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
          unread_messages: inbox.filter((m) => !m.read).length,
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

      return json(route, ME, 201)
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
                relationship: 'Ihr Bruder',
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
        unread_messages: inbox.filter((m) => !m.read).length,
        connection_requests: incoming.length,
        profile: { ...ME.profile, visible_in_directory: visibleInDirectory },
        individual: { ...ANNA, pending_change: pendingChange },
      })
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
      const body = route.request().postDataJSON() as { code?: string; reference?: string }

      if (body.code === 'code-fuer-anna') {
        return json(route, { ...connectionOverview(), status: 'connected', name: 'Emil Beispiel' }, 201)
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
      })
    }

    if (path === '/members') {
      const q = (url.searchParams.get('q') ?? '').toLowerCase()
      const items = MEMBERS.filter((member) => member.display_name.toLowerCase().includes(q))

      return json(route, { items, total: items.length, page: 1, per_page: 25 })
    }

    if (path === '/individuals/X2') {
      return json(route, {
        ...ANNA,
        xref: 'X2',
        name: 'Bertha Beispiel',
        lifespan: '1889–1976',
        is_deceased: true,
        relationship: 'Ihre Mutter',
        references: [],
        parents: [],
        siblings: [],
      })
    }

    if (path === '/individuals/X1/ancestors') {
      return json(route, {
        generations: 4,
        people: [
          { position: 1, generation: 0, ...refOf(ANNA) },
          { position: 3, generation: 1, xref: 'X2', name: 'Bertha Beispiel', sex: 'F', is_deceased: true, lifespan: '1889–1976' },
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

    return json(route, { error: 'not_found', message: 'This item does not exist.' }, 404)
  })
}
