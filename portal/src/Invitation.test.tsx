import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import './i18n'

/**
 * Phase 5: accepting an invitation.
 *
 * This is the only screen in the portal that someone with no account ever
 * sees more than a login box on, and the only one that creates anything. The
 * things worth pinning are that it says who the invitation is for *before*
 * asking for anything, that a refusal it can do something about does not
 * throw away what the member typed, and that a dead link says so plainly
 * rather than showing a form that cannot work.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const PREVIEW = {
  tree: { name: 'portal', title: 'Familie Beispiel' },
  invited_name: 'Anna Beispiel',
  email: 'anna@example.test',
  expires_at: '2026-09-01T12:00:00+00:00',
}

const ME = {
  user: { id: 7, username: 'anna', real_name: 'Anna Beispiel', email: 'anna@example.test', language: 'de', role: 'member' },
  profile: { id: 1, visible_in_directory: false, display_name_override: null, consent_recorded_at: null, directory_decided: true },
  individual: null,
  tree: { name: 'portal', title: 'Familie Beispiel' },
  csrf_token: 'token-1',
}

/**
 * `accept` decides what POST /invitation/accept answers. Everything else is
 * the same in every test here: no session yet, a good invitation.
 */
function stub(accept: () => Response = () => jsonResponse(ME, 201)) {
  const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input) => {
    const url = String(input)

    if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })
    if (url.endsWith('/invitation/preview')) return jsonResponse(PREVIEW)
    if (url.endsWith('/invitation/accept')) return accept()

    // No session: the AuthProvider's opening GET /me.
    return jsonResponse({ error: 'unauthenticated', message: 'Please sign in.' }, 401)
  })

  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

function renderAt(path: string) {
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

async function fillIn(user: ReturnType<typeof userEvent.setup>) {
  await user.type(await screen.findByLabelText('Benutzername'), 'anna')
  await user.type(screen.getByLabelText('Neues Passwort'), 'correct-horse')
  await user.type(screen.getByLabelText('Passwort wiederholen'), 'correct-horse')
}

describe('accepting an invitation', () => {
  it('says who the invitation is for before asking for anything', async () => {
    stub()
    renderAt('/invitation?token=abc123')

    expect(await screen.findByText('Anna Beispiel')).toBeDefined()
    expect(screen.getByText(/Familie Beispiel/)).toBeDefined()
  })

  /**
   * The token is a credential. In a query string it is already in the browser
   * history; putting it in the request line as well would write it into the
   * webserver's log and into the `Referer` of everything this page loads.
   */
  it('sends the token in the body of a POST, never in the URL', async () => {
    const fetchMock = stub()
    renderAt('/invitation?token=abc123')

    await screen.findByText('Anna Beispiel')

    const call = fetchMock.mock.calls.find(([url]) => String(url).includes('/invitation/preview'))

    expect(call).toBeDefined()
    expect(String(call?.[0])).not.toContain('abc123')
    expect(String(call?.[1]?.body)).toContain('abc123')
    expect(call?.[1]?.method).toBe('POST')
  })

  it('prefills the name and address the invitation carried', async () => {
    stub()
    renderAt('/invitation?token=abc123')

    expect(await screen.findByDisplayValue('Anna Beispiel')).toBeDefined()
    expect(screen.getByDisplayValue('anna@example.test')).toBeDefined()
  })

  it('creates the account and lands the new member on their own page', async () => {
    stub()
    renderAt('/invitation?token=abc123')

    const user = userEvent.setup()
    await fillIn(user)
    await user.click(screen.getByRole('button', { name: 'Zugang anlegen' }))

    expect(await screen.findByRole('heading', { name: 'Mein Profil' })).toBeDefined()
  })

  it('explains a taken username and keeps everything else the member typed', async () => {
    stub(() => jsonResponse({ error: 'username_taken', message: 'Duplicate username.' }, 409))
    renderAt('/invitation?token=abc123')

    const user = userEvent.setup()
    await fillIn(user)
    await user.click(screen.getByRole('button', { name: 'Zugang anlegen' }))

    expect((await screen.findByRole('alert')).textContent).toMatch(/Benutzernamen gibt es schon/)

    // Retyping a name that was merely taken, or an address, is busywork.
    expect(screen.getByLabelText<HTMLInputElement>('Benutzername').value).toBe('anna')
    expect(screen.getByLabelText<HTMLInputElement>('E-Mail-Adresse').value).toBe('anna@example.test')
  })

  it('does not send two different passwords to the server', async () => {
    const fetchMock = stub()
    renderAt('/invitation?token=abc123')

    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Benutzername'), 'anna')
    await user.type(screen.getByLabelText('Neues Passwort'), 'correct-horse')
    await user.type(screen.getByLabelText('Passwort wiederholen'), 'wrong-horse')
    await user.click(screen.getByRole('button', { name: 'Zugang anlegen' }))

    expect(await screen.findByRole('alert')).toBeDefined()

    await waitFor(() => {
      expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/invitation/accept'))).toBe(
        false,
      )
    })
  })

  it('says a dead invitation is dead, rather than showing a form that cannot work', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) => {
        const url = String(input)

        if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })
        if (url.endsWith('/invitation/preview')) {
          return jsonResponse({ error: 'invalid_token', message: 'Expired.' }, 400)
        }

        return jsonResponse({ error: 'unauthenticated', message: 'Please sign in.' }, 401)
      }),
    )

    renderAt('/invitation?token=abc123')

    expect(await screen.findByText('Diese Einladung gilt nicht mehr')).toBeDefined()
    expect(screen.queryByLabelText('Benutzername')).toBeNull()
  })

  it('says the same thing when the link arrives with no token at all', async () => {
    stub()
    renderAt('/invitation')

    expect(await screen.findByText('Diese Einladung gilt nicht mehr')).toBeDefined()
  })

  /**
   * The server can refuse for reasons that have nothing to do with the token
   * — it did, on every tree that requires a login. Reporting that as a spent
   * invitation is worse than saying nothing: the invitee asks for a new link,
   * and the new one fails in exactly the same way.
   */
  it('does not blame the invitation for a server that could not answer', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) => {
        const url = String(input)

        if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })
        if (url.endsWith('/invitation/preview')) {
          return jsonResponse({ error: 'not_configured', message: 'Not configured.' }, 503)
        }

        return jsonResponse({ error: 'unauthenticated', message: 'Please sign in.' }, 401)
      }),
    )

    renderAt('/invitation?token=abc123')

    expect(await screen.findByText('Da ist etwas schiefgelaufen')).toBeDefined()
    expect(screen.queryByText('Diese Einladung gilt nicht mehr')).toBeNull()
  })
})
