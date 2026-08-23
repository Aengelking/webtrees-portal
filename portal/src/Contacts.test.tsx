import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import { composeReference, isCompleteReference } from './routes/Contacts'
import type { Connection, ConnectionOverview } from './api/types'
import './i18n'

/**
 * Phase 11: the member's own contacts.
 *
 * The server decides who may connect with whom; none of that is re-decided
 * here and none of it is asserted here. What this pins is what only the
 * screen can get wrong: that a live code is never on screen until it is
 * asked for, that the code shown is the link the server issued rather than
 * anything the portal made up, that a request is answered in one tap and not
 * refused in one, and that a member whose family switched the
 * whole thing off is told so instead of being shown buttons that refuse.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const ME = {
  user: { id: 1, username: 'anna', real_name: 'Anna Beispiel', email: 'a@b.test', language: 'de', role: 'member' },
  profile: { id: 1, visible_in_directory: true, display_name_override: null, consent_recorded_at: null },
  individual: null,
  tree: { name: 'portal', title: 'Familie Beispiel' },
  unread_messages: 0,
  connection_requests: 1,
  csrf_token: 'token-1',
}

const DIETER: Connection = {
  id: 7,
  status: 'accepted',
  source: 'code',
  requested_by_me: true,
  member_id: 3,
  name: 'Dieter Beispiel',
  individual: {
    xref: 'X4',
    name: 'Dieter Beispiel',
    sex: 'M',
    is_deceased: false,
    lifespan: '1990–',
    portrait: null,
    references: [{ number: '4714', type: 'SB' }],
  },
  since: '2026-08-01T10:00:00+00:00',
}

const KARLA: Connection = {
  id: 9,
  status: 'pending',
  source: 'reference',
  requested_by_me: false,
  member_id: null,
  name: 'Karla Beispiel',
  individual: null,
  since: '2026-08-02T10:00:00+00:00',
}

const OVERVIEW: ConnectionOverview = {
  enabled: true,
  code_valid_minutes: 15,
  connections: [DIETER],
  incoming: [KARLA],
  outgoing: [],
}

const CODE_URL = 'https://portal.example.test/connect?code=geheim'

function stub(overrides: Partial<ConnectionOverview> = {}) {
  const overview = { ...OVERVIEW, ...overrides }

  const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
    const url = String(input)
    const method = init?.method ?? 'GET'

    if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

    if (url.includes('/me/connection-code')) {
      return method === 'DELETE'
        ? jsonResponse({ status: 'revoked' })
        : jsonResponse({ url: CODE_URL, expires_at: '2026-08-21T12:15:00+00:00', valid_minutes: 15 }, 201)
    }

    if (url.includes('/connections')) {
      if (method === 'PATCH') {
        return jsonResponse({
          ...overview,
          status: 'connected',
          name: 'Karla Beispiel',
          connections: [...overview.connections, { ...KARLA, status: 'accepted' }],
          incoming: [],
        })
      }

      if (method === 'DELETE') {
        return jsonResponse({ ...overview, connections: [], incoming: [] })
      }

      if (method === 'POST') {
        return jsonResponse({ ...overview, status: 'requested', name: 'Emil Beispiel' }, 201)
      }

      return jsonResponse(overview)
    }

    return jsonResponse(ME)
  })

  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

/**
 * The half of the screen the ways of connecting live on.
 *
 * The address book and the ways of adding to it are two tabs now, and the
 * tests that are about a QR code, a link or a number belong on the second
 * one. They ask for it in the address bar rather than by tapping, so that
 * what each test is about stays the first thing it does.
 */
function renderNewTab() {
  return renderAt('/contacts?tab=new')
}

function renderAt(path = '/contacts') {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter initialEntries={[path]}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('my contacts', () => {
  /**
   * The whole row, not the name in it. A name-sized target in a card-sized row
   * is a thumb-sized miss on a phone, and every other card in the portal has
   * been a whole-card link since the tree was first walkable.
   */
  it('lists the people I am connected to, and makes the whole card the link', async () => {
    stub()
    renderAt()

    const link = await screen.findByRole('link', { name: /Dieter Beispiel/ })

    expect(link.getAttribute('href')).toBe('/members/3')

    // What the tree knows of them is inside the same link, not beside it —
    // including the archive's number, which is how this family tells two
    // people of the same name apart.
    expect(link.textContent).toMatch(/1990/)
    expect(link.textContent).toContain('SB 4714')
  })

  /**
   * A code is a credential with a quarter of an hour to live. One that
   * appeared whenever this screen was opened would be a live credential on a
   * screen nobody meant to show.
   */
  it('issues no code until the member asks for one', async () => {
    const fetchMock = stub()
    renderNewTab()

    await screen.findByRole('button', { name: 'Code anzeigen' })

    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('connection-code'))).toBe(false)

    await userEvent.click(screen.getByRole('button', { name: 'Code anzeigen' }))

    await waitFor(() => {
      expect(fetchMock.mock.calls.some(([url]) => String(url).includes('connection-code'))).toBe(true)
    })
  })

  /**
   * The QR code has to hold the link the *server* issued. A code built from
   * anything the portal guessed would scan perfectly and connect nobody.
   */
  it('draws the link the server issued, and says how long it lasts', async () => {
    stub()
    const { container } = renderNewTab()

    await screen.findByRole('button', { name: 'Code anzeigen' })
    await userEvent.click(screen.getByRole('button', { name: 'Code anzeigen' }))

    const image = await screen.findByRole('img', { name: /QR-Code/ })

    expect(image).toBeDefined()
    expect(container.querySelectorAll('svg path').length).toBeGreaterThan(0)
    expect(await screen.findByText(/Gilt noch etwa 15 Minuten/)).toBeDefined()
  })

  /**
   * The link is a credential that travels through somebody else's inbox, so
   * nothing is issued until the member asks — and what is shown is the link
   * the *server* made, not one the portal guessed.
   */
  it('issues a link on request and offers it for copying', async () => {
    const clipboard = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText: clipboard } })

    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input) => {
      const url = String(input)

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

      if (url.includes('/me/connection-link')) {
        return jsonResponse(
          {
            url: 'https://portal.example.test/connect?code=post-link',
            expires_at: '2026-08-28T12:00:00+00:00',
            valid_days: 7,
          },
          201,
        )
      }

      if (url.includes('/connections')) return jsonResponse(OVERVIEW)

      return jsonResponse(ME)
    })

    vi.stubGlobal('fetch', fetchMock)
    renderNewTab()

    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('connection-link'))).toBe(false)

    await userEvent.click(await screen.findByRole('button', { name: 'Link erzeugen' }))

    const field = await screen.findByLabelText('Ihr Link')

    expect(field).toHaveProperty('value', 'https://portal.example.test/connect?code=post-link')

    // Said before it is sent, not after.
    expect(screen.getByText(/funktioniert genau einmal/)).toBeDefined()

    await userEvent.click(screen.getByRole('button', { name: 'Kopieren' }))

    await waitFor(() => {
      expect(clipboard).toHaveBeenCalledWith('https://portal.example.test/connect?code=post-link')
    })
  })

  it('lists links nobody has used and takes one back', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)
      const method = init?.method ?? 'GET'

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

      if (url.includes('/me/connection-links/')) {
        return jsonResponse({ ...OVERVIEW, links: [] })
      }

      if (url.includes('/connections')) {
        return method === 'GET'
          ? jsonResponse({
              ...OVERVIEW,
              link_valid_days: 7,
              links: [{ id: 4, created_at: '2026-08-21T12:00:00+00:00', expires_at: '2026-08-28T12:00:00+00:00' }],
            })
          : jsonResponse(OVERVIEW, 201)
      }

      return jsonResponse(ME)
    })

    vi.stubGlobal('fetch', fetchMock)
    renderNewTab()

    await userEvent.click(await screen.findByRole('button', { name: 'Zurückziehen' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          ([url, init]) => String(url).includes('/me/connection-links/4') && init?.method === 'DELETE',
        ),
      ).toBe(true)
    })
  })

  it('can put the code away again', async () => {
    const fetchMock = stub()
    renderNewTab()

    await userEvent.click(await screen.findByRole('button', { name: 'Code anzeigen' }))
    await screen.findByRole('img', { name: /QR-Code/ })

    await userEvent.click(screen.getByRole('button', { name: 'Code ungültig machen' }))

    await waitFor(() => {
      expect(screen.queryByRole('img', { name: /QR-Code/ })).toBeNull()
    })

    expect(
      fetchMock.mock.calls.some(
        ([url, init]) => String(url).includes('connection-code') && init?.method === 'DELETE',
      ),
    ).toBe(true)
  })

  /**
   * The branch is picked and the number typed, and what goes to the server is
   * the number as the family writes it — nobody had to find "/" on a
   * telephone keyboard.
   */
  it('composes the branch and the number into one SB number', async () => {
    const fetchMock = stub()
    renderNewTab()

    await userEvent.selectOptions(await screen.findByLabelText('Zweig'), '10')
    await userEvent.type(screen.getByLabelText('Nummer'), '1335.21')

    // The slash is printed between the two controls, so what is on screen
    // reads as the number itself — and nobody had to type it.
    expect(screen.getByText('/')).toBeDefined()

    await userEvent.click(screen.getByRole('button', { name: 'Anfrage senden' }))

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(
        ([url, init]) => String(url).endsWith('/connections') && init?.method === 'POST',
      )

      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ reference: '10/1335.21' })
    })

    expect(await screen.findByText(/Emil Beispiel/)).toBeDefined()
  })

  /**
   * Every number in this family has a branch, so a number without one is not
   * a number anybody carries. The button stays out of reach rather than
   * sending it and reporting that nobody was found.
   */
  it('will not send a number that has no branch', async () => {
    stub()
    renderNewTab()

    await userEvent.type(await screen.findByLabelText('Nummer'), '1335.21')

    expect(screen.getByRole('button', { name: 'Anfrage senden' })).toHaveProperty('disabled', true)
  })

  /**
   * Unless it carries its own slash: somebody who typed the whole number
   * meant it, and that is the way in if the family ever grows a
   * thirty-fifth branch.
   */
  it('sends a number typed whole, without touching the wheel', async () => {
    const fetchMock = stub()
    renderNewTab()

    await userEvent.type(await screen.findByLabelText('Nummer'), '35/1335.21')
    await userEvent.click(screen.getByRole('button', { name: 'Anfrage senden' }))

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(
        ([url, init]) => String(url).endsWith('/connections') && init?.method === 'POST',
      )

      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ reference: '35/1335.21' })
    })
  })

  /**
   * A number is not only digits. It may carry letters, and it may carry a
   * marker on its end — "!" is the spouse of the person with the same number
   * without it — and both go to the server exactly as they were typed.
   */
  it('sends a number with a marker on its end untouched', async () => {
    const fetchMock = stub()
    renderNewTab()

    await userEvent.selectOptions(await screen.findByLabelText('Zweig'), '10')
    await userEvent.type(screen.getByLabelText('Nummer'), '1335.21!')
    await userEvent.click(screen.getByRole('button', { name: 'Anfrage senden' }))

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(
        ([url, init]) => String(url).endsWith('/connections') && init?.method === 'POST',
      )

      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ reference: '10/1335.21!' })
    })
  })

  it('composes and refuses the right things', () => {
    // A branch is never glued onto a number that already has one.
    expect(composeReference('10', '4/99')).toBe('4/99')
    expect(composeReference('10', ' 1335.21 ')).toBe('10/1335.21')

    // Letters and the marker on the end are part of the number.
    expect(composeReference('10', '1335.21!')).toBe('10/1335.21!')
    expect(composeReference('10', '13C5.21')).toBe('10/13C5.21')

    expect(isCompleteReference('10', '1335.21')).toBe(true)
    expect(isCompleteReference('10', '1335.21!')).toBe(true)
    expect(isCompleteReference('', '35/1335.21')).toBe(true)
    expect(isCompleteReference('', '1335.21')).toBe(false)
    expect(isCompleteReference('10', '')).toBe(false)
  })

  /**
   * A member who stayed out of the directory can be asked, and the answer
   * names nobody — because naming them would be a way of asking which
   * relatives have an account.
   */
  it('says nothing about whom a number reached', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

      if (url.includes('/connections')) {
        return (init?.method ?? 'GET') === 'POST'
          ? jsonResponse({ ...OVERVIEW, status: 'requested', name: null }, 201)
          : jsonResponse(OVERVIEW)
      }

      return jsonResponse(ME)
    })

    vi.stubGlobal('fetch', fetchMock)
    renderNewTab()

    await userEvent.selectOptions(await screen.findByLabelText('Zweig'), '10')
    await userEvent.type(screen.getByLabelText('Nummer'), '1335.21')
    await userEvent.click(screen.getByRole('button', { name: 'Anfrage senden' }))

    expect(await screen.findByText(/Wenn diese Nummer zu einem Mitglied gehört/)).toBeDefined()
  })

  it('answers a request in one tap', async () => {
    const fetchMock = stub()
    renderAt()

    await userEvent.click(await screen.findByRole('button', { name: 'Annehmen' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          ([url, init]) => String(url).includes('/connections/9') && init?.method === 'PATCH',
        ),
      ).toBe(true)
    })
  })

  /**
   * Refusing a request is not undoable by tapping again, so it is not done by
   * one tap either — and the question is asked where the tap was, rather than
   * in a browser dialogue that a thumb dismisses by reflex.
   */
  it('asks before refusing a request', async () => {
    const fetchMock = stub()
    renderAt()

    await userEvent.click(await screen.findByRole('button', { name: 'Ablehnen' }))

    expect(
      fetchMock.mock.calls.some(([, init]) => init?.method === 'DELETE'),
    ).toBe(false)

    expect(screen.getByText(/Verbindung mit Karla Beispiel wirklich beenden/)).toBeDefined()

    await userEvent.click(screen.getByRole('button', { name: 'Ja, beenden' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          ([url, init]) => String(url).includes('/connections/9') && init?.method === 'DELETE',
        ),
      ).toBe(true)
    })
  })

  /**
   * The address book is a list to read, not a row of destructive buttons to
   * mis-tap. Ending a connection is asked for on the member's own page, which
   * the name in the row leads to.
   */
  it('does not offer to end a connection from the list', async () => {
    stub()
    renderAt()

    expect(await screen.findByRole('link', { name: /Dieter Beispiel/ })).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Verbindung lösen' })).toBeNull()
  })

  it('says so when the family has switched connections off', async () => {
    stub({ enabled: false })
    renderNewTab()

    expect(await screen.findByText('Verbindungen sind ausgeschaltet')).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Code anzeigen' })).toBeNull()

    // The list stays: a member must still be able to see what they agreed to.
    await userEvent.click(screen.getByRole('tab', { name: 'Kontakte' }))

    expect(screen.getByRole('link', { name: /Dieter Beispiel/ })).toBeDefined()
  })

  /**
   * A number that belongs to somebody already in the address book says so,
   * rather than reporting a request that was never sent.
   */
  it('says a number already belongs to a contact', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

      if (url.includes('/connections')) {
        return (init?.method ?? 'GET') === 'POST'
          ? jsonResponse({ ...OVERVIEW, status: 'already_connected', name: 'Dieter Beispiel' }, 201)
          : jsonResponse(OVERVIEW)
      }

      return jsonResponse(ME)
    })

    vi.stubGlobal('fetch', fetchMock)
    renderNewTab()

    await userEvent.selectOptions(await screen.findByLabelText('Zweig'), '10')
    await userEvent.type(screen.getByLabelText('Nummer'), '1335.21')
    await userEvent.click(screen.getByRole('button', { name: 'Anfrage senden' }))

    expect(await screen.findByText(/Sie sind bereits verbunden/)).toBeDefined()
    expect(screen.queryByText(/Ihre Anfrage ist bei/)).toBeNull()
  })

  /**
   * The address book and the ways of adding to it are two tabs, because the
   * one a member comes back to had four cards of machinery stacked on top of
   * it.
   *
   * The tab that opens is the address book, and a request waiting for an
   * answer is at the top of it: it is a thing asked of you.
   */
  it('opens on the contacts, with the waiting request above them', async () => {
    stub()
    renderAt()

    expect(await screen.findByRole('tab', { name: 'Kontakte' })).toHaveProperty(
      'ariaSelected',
      'true',
    )

    expect(screen.getByText('Karla Beispiel')).toBeDefined()
    expect(screen.getByRole('link', { name: /Dieter Beispiel/ })).toBeDefined()

    // And the machinery is not on this half at all — not merely out of sight.
    expect(screen.queryByRole('button', { name: 'Code anzeigen' })).toBeNull()
  })

  /**
   * A member with an empty address book is put on the tab that fills it. The
   * empty half is not what they came for.
   */
  it('opens on the second tab while there is nothing to show on the first', async () => {
    stub({ connections: [], incoming: [], outgoing: [] })
    renderAt()

    expect(await screen.findByRole('tab', { name: 'Neu verbinden' })).toHaveProperty(
      'ariaSelected',
      'true',
    )

    expect(screen.getByRole('button', { name: 'Code anzeigen' })).toBeDefined()
  })

  /**
   * Which tab is open is in the address bar, so a refresh, the Back button
   * and a link all keep it — and it outranks the default, which is the whole
   * reason for putting it there.
   */
  it('takes the open tab from the address bar', async () => {
    stub()
    renderNewTab()

    expect(await screen.findByRole('tab', { name: 'Neu verbinden' })).toHaveProperty(
      'ariaSelected',
      'true',
    )

    // One panel on screen, named by its tab.
    const panel = screen.getByRole('tabpanel')

    expect(panel).toHaveProperty('id', 'contacts-panel-new')
    expect(panel.getAttribute('aria-labelledby')).toBe('contacts-tab-new')

    // And tapping the other one shows the other half.
    await userEvent.click(screen.getByRole('tab', { name: 'Kontakte' }))

    expect(screen.getByRole('link', { name: /Dieter Beispiel/ })).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Code anzeigen' })).toBeNull()
  })

  /**
   * `role="tab"` promises a screen reader that the arrow keys move between
   * them, so they do — and only the chosen tab is in the tab order, which is
   * what makes Tab land in the panel rather than on the other tab.
   */
  it('moves between the tabs with the arrow keys', async () => {
    stub()
    renderAt()

    const mine = await screen.findByRole('tab', { name: 'Kontakte' })

    expect(screen.getByRole('tab', { name: 'Neu verbinden' })).toHaveProperty('tabIndex', -1)

    mine.focus()
    await userEvent.keyboard('{ArrowRight}')

    expect(screen.getByRole('tab', { name: 'Neu verbinden' })).toHaveProperty(
      'ariaSelected',
      'true',
    )
    expect(screen.getByRole('button', { name: 'Code anzeigen' })).toBeDefined()

    await userEvent.keyboard('{ArrowRight}')

    // Two tabs, and the wheel comes round rather than stopping.
    expect(screen.getByRole('tab', { name: 'Kontakte' })).toHaveProperty('ariaSelected', 'true')
  })

  it('counts the waiting requests on the navigation bar', async () => {
    stub()
    renderAt()

    const link = await screen.findByRole('link', { name: /1 Verbindungsanfrage/ })

    // On Kontakte — the screen the request is actually about — rather than on
    // a fifth destination, and counted in words in the link's own name: the
    // number in the circle is aria-hidden, so a screen reader does not read a
    // stray digit.
    expect(link.getAttribute('href')).toBe('/contacts')
  })
})

describe('a scanned code', () => {
  /**
   * Opening a link is not consent. A page that connected on arrival would
   * connect when a link is opened by accident, by a preview, or by somebody
   * else's telephone.
   */
  it('does not connect until the button is pressed', async () => {
    const fetchMock = stub()
    renderAt('/connect?code=geheim')

    expect(await screen.findByRole('button', { name: 'Jetzt verbinden' })).toBeDefined()

    expect(
      fetchMock.mock.calls.some(([url, init]) => String(url).endsWith('/connections') && init?.method === 'POST'),
    ).toBe(false)

    await userEvent.click(screen.getByRole('button', { name: 'Jetzt verbinden' }))

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(
        ([url, init]) => String(url).endsWith('/connections') && init?.method === 'POST',
      )

      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ code: 'geheim' })
    })
  })

  it('says which link is incomplete rather than failing silently', async () => {
    stub()
    renderAt('/connect')

    expect(await screen.findByText('Dieser Link ist unvollständig')).toBeDefined()
  })
})

/**
 * The same decision, offered where a member is actually standing: on the
 * other person's page in the directory.
 */
describe('a member\u2019s own page', () => {
  const MEMBER = {
    id: 3,
    display_name: 'Dieter Beispiel',
    individual: null,
    individual_detail: null,
    contact: {},
    can_message: false,
    connections_enabled: true,
  }

  function stubMember(connection: unknown) {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)
      const method = init?.method ?? 'GET'

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

      if (url.includes('/connections')) {
        return method === 'POST'
          ? jsonResponse({ ...OVERVIEW, status: 'requested', name: 'Dieter Beispiel' }, 201)
          : jsonResponse(OVERVIEW)
      }

      if (url.includes('/members/')) return jsonResponse({ ...MEMBER, connection })

      return jsonResponse(ME)
    })

    vi.stubGlobal('fetch', fetchMock)

    return fetchMock
  }

  it('offers to connect, and asks by member id rather than by number', async () => {
    const fetchMock = stubMember({ status: 'none', id: null })
    renderAt('/members/3')

    await userEvent.click(await screen.findByRole('button', { name: 'Verbinden' }))

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(
        ([url, init]) => String(url).endsWith('/connections') && init?.method === 'POST',
      )

      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ member_id: 3 })
    })
  })

  it('offers the answer, not another request, when they asked first', async () => {
    stubMember({ status: 'incoming', id: 9 })
    renderAt('/members/3')

    expect(await screen.findByRole('button', { name: 'Annehmen' })).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Verbinden' })).toBeNull()
  })

  /**
   * A server that predates connections sends no state at all, and then the
   * screen offers nothing rather than a button that would 404.
   */
  it('offers nothing when the server does not know about connections', async () => {
    stubMember(undefined)
    renderAt('/members/3')

    await screen.findByRole('heading', { name: 'Dieter Beispiel' })

    expect(screen.queryByRole('button', { name: 'Verbinden' })).toBeNull()
  })
})

/**
 * The list is where a member is already looking for somebody, so it is where
 * the request should be sendable from.
 */
describe('the directory list', () => {
  function stubDirectory(connection: unknown, options: { enabled?: boolean } = {}) {
    const page = {
      items: [{ id: 3, display_name: 'Dieter Beispiel', individual: null, connection }],
      total: 1,
      page: 1,
      per_page: 25,
      connections_enabled: options.enabled ?? true,
    }

    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)
      const method = init?.method ?? 'GET'

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

      if (url.includes('/connections')) {
        return method === 'GET'
          ? jsonResponse(OVERVIEW)
          : jsonResponse({ ...OVERVIEW, status: 'requested', name: 'Dieter Beispiel' }, 201)
      }

      if (url.includes('/members')) return jsonResponse(page)

      return jsonResponse(ME)
    })

    vi.stubGlobal('fetch', fetchMock)

    return fetchMock
  }

  it('sends a request from the row, without opening the person', async () => {
    const fetchMock = stubDirectory({ status: 'none', id: null })
    renderAt('/members')

    await userEvent.click(await screen.findByRole('button', { name: 'Verbinden mit Dieter Beispiel' }))

    await waitFor(() => {
      const call = fetchMock.mock.calls.find(
        ([url, init]) => String(url).endsWith('/connections') && init?.method === 'POST',
      )

      expect(JSON.parse(String(call?.[1]?.body))).toEqual({ member_id: 3 })
    })
  })

  /**
   * Twenty-five buttons all called "Verbinden" are a list nobody can navigate
   * by name, so each is named for the person — and the visible word starts
   * that name, so speaking it still works.
   */
  it('names the button for the person it belongs to', async () => {
    stubDirectory({ status: 'none', id: null })
    renderAt('/members')

    const button = await screen.findByRole('button', { name: 'Verbinden mit Dieter Beispiel' })

    expect(button.textContent).toBe('Verbinden')
  })

  it('offers the answer where they asked first', async () => {
    const fetchMock = stubDirectory({ status: 'incoming', id: 9 })
    renderAt('/members')

    await userEvent.click(
      await screen.findByRole('button', { name: 'Anfrage von Dieter Beispiel annehmen' }),
    )

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          ([url, init]) => String(url).includes('/connections/9') && init?.method === 'PATCH',
        ),
      ).toBe(true)
    })
  })

  /** A control that does nothing is worse on a row than a word that says why. */
  it('states the two settled cases rather than offering a button', async () => {
    stubDirectory({ status: 'connected', id: 7 })
    renderAt('/members')

    expect(await screen.findByText('Verbunden')).toBeDefined()
    expect(screen.queryByRole('button', { name: /Verbinden mit/ })).toBeNull()
  })

  it('offers nothing on my own row', async () => {
    stubDirectory({ status: 'self', id: null })
    renderAt('/members')

    await screen.findByRole('link', { name: /Dieter Beispiel/ })

    expect(screen.queryByRole('button', { name: /Verbinden/ })).toBeNull()
  })

  it('offers nothing when the family has switched connections off', async () => {
    stubDirectory({ status: 'none', id: null }, { enabled: false })
    renderAt('/members')

    await screen.findByRole('link', { name: /Dieter Beispiel/ })

    expect(screen.queryByRole('button', { name: /Verbinden mit/ })).toBeNull()
  })

  /** An older server sends no state, and then the row is what it always was. */
  it('offers nothing when the server does not know about connections', async () => {
    stubDirectory(undefined)
    renderAt('/members')

    await screen.findByRole('link', { name: /Dieter Beispiel/ })

    expect(screen.queryByRole('button', { name: /Verbinden mit/ })).toBeNull()
  })
})
