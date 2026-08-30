import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import './i18n'

/**
 * Asking to connect from a person's page.
 *
 * The offer is on the record because that is where a member finds the person;
 * what it must not become is a way of reading off which relatives are in the
 * portal. The server answers that question with one word for three different
 * situations (`Connections::recordState`), so the only thing this screen can
 * do wrong is say more than it was told — which is what these tests watch.
 */

const DIETER = {
  xref: 'X4',
  name: 'Dieter Beispiel',
  sex: 'M',
  is_deceased: false,
  lifespan: '1990–',
  portrait: null,
  name_alternative: null,
  relationship: 'Bruder',
  references: [],
  photos: [],
  birth: null,
  death: null,
  events: [],
  parents: [],
  siblings: [],
  spouses: [],
  children: [],
  pending_change: false,
  webtrees_url: 'https://webtrees.example.org/tree/portal/individual/X4',
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
    consent_recorded_at: null,
    directory_decided: true,
  },
  individual: null,
  tree: { name: 'portal', title: 'Familie Beispiel' },
  csrf_token: 'token-1',
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const posted: Record<string, unknown>[] = []

/**
 * @param individual what `/individuals/X4` answers.
 * @param result what the server says when the button is pressed.
 */
function stub(individual: Record<string, unknown>, result: Record<string, unknown> = {}) {
  posted.length = 0

  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)

      if (url.endsWith('/csrf')) {
        return jsonResponse({ csrf_token: 'token-1' })
      }

      if (url.endsWith('/connections') && init?.method === 'POST') {
        posted.push(JSON.parse(String(init.body)) as Record<string, unknown>)

        return jsonResponse(
          {
            status: 'requested',
            name: null,
            enabled: true,
            code_valid_minutes: 15,
            link_valid_days: 7,
            links: [],
            connections: [],
            incoming: [],
            outgoing: [],
            ...result,
          },
          201,
        )
      }

      if (url.includes('/individuals/')) {
        return jsonResponse(individual)
      }

      return jsonResponse(ME)
    }),
  )
}

function renderPerson() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter initialEntries={['/individuals/X4']}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('connecting from a person’s page', () => {
  it('offers it where the server says the offer is open', async () => {
    stub({ ...DIETER, connection: 'open' })
    renderPerson()

    expect(await screen.findByRole('button', { name: 'Verbinden' })).toBeDefined()
  })

  it('sends the record, not a number the member had to read off the screen', async () => {
    stub({ ...DIETER, connection: 'open' })
    renderPerson()

    await userEvent.click(await screen.findByRole('button', { name: 'Verbinden' }))

    expect(posted).toEqual([{ xref: 'X4' }])
  })

  /**
   * The whole point of the quiet answer. Without a name the server is not
   * saying whether anybody was there to receive the request, and this screen
   * may not improve on that.
   */
  it('says only that the request would be on its way, where no name comes back', async () => {
    stub({ ...DIETER, connection: 'open' })
    renderPerson()

    await userEvent.click(await screen.findByRole('button', { name: 'Verbinden' }))

    const note = await screen.findByText(/Wenn Dieter Beispiel ein Konto im Portal hat/)

    expect(note).toBeDefined()
    expect(screen.queryByText(/hat ein Konto|ist im Portal|kein Konto/)).toBeNull()
  })

  /** Where the server does name them, they are listed or already a contact. */
  it('names them where the server does', async () => {
    stub({ ...DIETER, connection: 'open' }, { status: 'requested', name: 'Dieter Beispiel' })
    renderPerson()

    await userEvent.click(await screen.findByRole('button', { name: 'Verbinden' }))

    expect(await screen.findByText(/Deine Anfrage ist bei Dieter Beispiel/)).toBeDefined()
  })

  it('says so instead of offering, where the two are already contacts', async () => {
    stub({ ...DIETER, connection: 'connected' })
    renderPerson()

    expect(await screen.findByText('Ihr seid verbunden.')).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Verbinden' })).toBeNull()
  })

  /**
   * The state the member asked for. Said only about somebody the directory
   * already names, so it discloses nothing the directory does not — and
   * without it, a member who pressed the button yesterday has no way of
   * telling whether they did.
   */
  it('says a request is waiting, where the server reports one', async () => {
    stub({ ...DIETER, connection: 'requested' })
    renderPerson()

    expect(await screen.findByText(/Deine Anfrage ist gesendet und wartet/)).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Verbinden' })).toBeNull()
  })

  /**
   * Null is "connecting cannot happen here" — the reader's own record, the
   * dead, a family that switched connections off — and a page that guessed
   * would offer something the server refuses.
   */
  it('offers nothing where the server says nothing', async () => {
    stub({ ...DIETER, connection: null })
    renderPerson()

    expect(await screen.findByRole('heading', { name: 'Dieter Beispiel' })).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Verbinden' })).toBeNull()
  })

  /** An older module does not send the field at all, and makes no offer. */
  it('offers nothing to a server that predates the field', async () => {
    stub(DIETER)
    renderPerson()

    expect(await screen.findByRole('heading', { name: 'Dieter Beispiel' })).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Verbinden' })).toBeNull()
  })

  /**
   * And it steps aside where the invitation applies. `invitable` is only ever
   * true for somebody with **no** account, so that offer has already said out
   * loud what the connect offer is careful not to imply — and asking to
   * connect with somebody the same screen calls "noch nicht im Portal" is
   * nonsense rather than discretion.
   */
  it('gives way to the invitation, which has already said there is no account', async () => {
    stub({ ...DIETER, connection: 'open', invitable: true })
    renderPerson()

    expect(await screen.findByRole('link', { name: 'Einladen' })).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Verbinden' })).toBeNull()
  })

  /** Where nobody can be invited, the offer to connect is the only one. */
  it('is offered where there is no invitation to make', async () => {
    stub({ ...DIETER, connection: 'open', invitable: false })
    renderPerson()

    expect(await screen.findByRole('button', { name: 'Verbinden' })).toBeDefined()
    expect(screen.queryByRole('link', { name: 'Einladen' })).toBeNull()
  })
})
