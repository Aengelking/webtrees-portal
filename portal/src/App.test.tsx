import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import './i18n'

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const unauthenticated = () =>
  jsonResponse({ error: 'unauthenticated', message: 'Please sign in.' }, 401)

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
  individual: {
    xref: 'X1',
    name: 'Anna Beispiel',
    sex: 'F',
    is_deceased: false,
    lifespan: '1985–',
    name_alternative: null,
    birth: null,
    death: null,
    events: [],
    parents: [],
    siblings: [],
    spouses: [],
    children: [],
    webtrees_url: 'https://webtrees.example.org/tree/portal/individual/X1',
  },
  tree: { name: 'portal', title: 'Familie Beispiel' },
  csrf_token: 'token-1',
}

function renderApp(initialPath: string) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}>
      <MemoryRouter initialEntries={[initialPath]}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('App', () => {
  it('sends a visitor with no session to the login screen', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockImplementation(async () => unauthenticated()))

    renderApp('/me')

    expect(await screen.findByRole('button', { name: 'Anmelden' })).toBeDefined()
  })

  it('shows German by default', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockImplementation(async () => unauthenticated()))

    renderApp('/login')

    expect(await screen.findByLabelText('Benutzername oder E-Mail-Adresse')).toBeDefined()
  })

  it('shows the member their own record after signing in', async () => {
    let signedIn = false

    // Every branch builds a fresh Response: a Response body can only be read
    // once, and the app reads each one.
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)

      if (url.endsWith('/csrf')) {
        return jsonResponse({ csrf_token: 'token-1' })
      }

      if (url.endsWith('/session') && init?.method === 'POST') {
        signedIn = true
        return jsonResponse(ME)
      }

      if (url.endsWith('/me')) {
        return signedIn ? jsonResponse(ME) : unauthenticated()
      }

      return unauthenticated()
    })
    vi.stubGlobal('fetch', fetchMock)

    renderApp('/me')

    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Benutzername oder E-Mail-Adresse'), 'anna')
    await user.type(screen.getByLabelText('Passwort'), 'pw')
    await user.click(screen.getByRole('button', { name: 'Anmelden' }))

    expect(await screen.findByRole('heading', { name: 'Mein Profil' })).toBeDefined()
    expect(await screen.findByRole('heading', { name: 'Anna Beispiel' })).toBeDefined()
  })

  it('shows the archive reference number under the name, not in front of it', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) =>
        String(input).endsWith('/csrf')
          ? jsonResponse({ csrf_token: 'token-1' })
          : jsonResponse({
              ...ME,
              individual: {
                ...ME.individual,
                references: [{ number: '4711', type: 'SB', branch: null }],
              },
            }),
      ),
    )

    renderApp('/me')

    const heading = await screen.findByRole('heading', { name: 'Anna Beispiel' })

    // The name is a name. The number is its own line, after it.
    expect(heading.textContent).toBe('Anna Beispiel')
    expect(screen.getByText('SB 4711')).toBeDefined()
  })

  /**
   * The line number is the bookkeeping; the branch is the answer somebody
   * actually gives when asked where in the family they come from.
   */
  it('names the branch the number belongs to, under the number', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) =>
        String(input).endsWith('/csrf')
          ? jsonResponse({ csrf_token: 'token-1' })
          : jsonResponse({
              ...ME,
              individual: {
                ...ME.individual,
                references: [
                  {
                    number: '10/1335.21',
                    type: 'SB',
                    branch: 'Ernestinische Linie – Zweig Cleve',
                  },
                ],
              },
            }),
      ),
    )

    renderApp('/me')

    expect(await screen.findByText('SB 10/1335.21')).toBeDefined()
    expect(screen.getByText('Ernestinische Linie – Zweig Cleve')).toBeDefined()
  })

  /**
   * The archive numbered some people more than once, and the older number can
   * sit in a different branch. Both are true, so both are said — and a branch
   * named twice is said once.
   */
  it('names every branch its numbers name, and each of them once', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) =>
        String(input).endsWith('/csrf')
          ? jsonResponse({ csrf_token: 'token-1' })
          : jsonResponse({
              ...ME,
              individual: {
                ...ME.individual,
                references: [
                  { number: '9', type: 'SB', branch: null },
                  {
                    number: '10/1335.21',
                    type: 'SB',
                    branch: 'Ernestinische Linie – Zweig Cleve',
                  },
                  {
                    number: '10/1341.2',
                    type: 'SB',
                    branch: 'Ernestinische Linie – Zweig Cleve',
                  },
                  {
                    number: '7/22.9',
                    type: 'SB',
                    branch: 'Ernestinische Linie – Zweig Dessau',
                  },
                ],
              },
            }),
      ),
    )

    renderApp('/me')

    // The label in front is for a screen reader — sighted readers get the
    // branch under the number and need no word saying so.
    expect((await screen.findByText(/Zweig Cleve/)).textContent).toBe(
      'Zweig der Familie: Ernestinische Linie – Zweig Cleve · Ernestinische Linie – Zweig Dessau',
    )
  })

  /**
   * The field is newer than some of the servers that will answer this app, and
   * a missing one must read as "no branch", not as the word "undefined".
   */
  it('survives a server that does not send the branch yet', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) =>
        String(input).endsWith('/csrf')
          ? jsonResponse({ csrf_token: 'token-1' })
          : jsonResponse({
              ...ME,
              individual: {
                ...ME.individual,
                references: [{ number: '10/1335.21', type: 'SB' }],
              },
            }),
      ),
    )

    renderApp('/me')

    expect(await screen.findByText('SB 10/1335.21')).toBeDefined()
    expect(screen.queryByText(/undefined/)).toBeNull()
    expect(screen.queryByText(/Zweig/)).toBeNull()
  })

  /** A number that names no branch gets no line, rather than an empty one. */
  it('says nothing about a branch where the number does not name one', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) =>
        String(input).endsWith('/csrf')
          ? jsonResponse({ csrf_token: 'token-1' })
          : jsonResponse({
              ...ME,
              individual: {
                ...ME.individual,
                references: [{ number: '4711', type: 'SB', branch: null }],
              },
            }),
      ),
    )

    renderApp('/me')

    expect(await screen.findByText('SB 4711')).toBeDefined()
    expect(screen.queryByText(/Zweig/)).toBeNull()
  })

  it('survives a server that does not send reference numbers yet', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) =>
        String(input).endsWith('/csrf') ? jsonResponse({ csrf_token: 'token-1' }) : jsonResponse(ME),
      ),
    )

    renderApp('/me')

    expect(await screen.findByRole('heading', { name: 'Anna Beispiel' })).toBeDefined()
  })

  it('says the same thing for every kind of sign-in failure', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input) =>
      String(input).endsWith('/csrf')
        ? jsonResponse({ csrf_token: 'token-1' })
        : jsonResponse({ error: 'invalid_credentials', message: 'The username or password is incorrect.' }, 401),
    )
    vi.stubGlobal('fetch', fetchMock)

    renderApp('/login')

    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Benutzername oder E-Mail-Adresse'), 'nobody')
    await user.type(screen.getByLabelText('Passwort'), 'pw')
    await user.click(screen.getByRole('button', { name: 'Anmelden' }))

    const alert = await screen.findByRole('alert')
    expect(alert.textContent).toBe('Benutzername oder Passwort ist falsch. Bitte versuchen Sie es noch einmal.')

    // The password field is cleared, and nothing about the account leaks.
    expect((screen.getByLabelText('Passwort') as HTMLInputElement).value).toBe('')
  })

  it('drops back to the login screen when a later request returns 401', async () => {
    let sessionValid = true

    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input) => {
      const url = String(input)

      if (url.endsWith('/me')) {
        return sessionValid ? jsonResponse(ME) : unauthenticated()
      }

      // Whatever the second tab happens to fetch — it was the directory until
      // Kontakte took that place, and this test is about the 401, not about
      // which endpoint produced it.
      if (url.includes('/connections') || url.includes('/members')) {
        sessionValid = false
        return unauthenticated()
      }

      return jsonResponse({ csrf_token: 'token-1' })
    })
    vi.stubGlobal('fetch', fetchMock)

    renderApp('/me')

    expect(await screen.findByRole('heading', { name: 'Mein Profil' })).toBeDefined()

    await userEvent.setup().click(screen.getByRole('link', { name: 'Kontakte' }))

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Anmelden' })).toBeDefined()
    })
  })

  it('keeps no genealogy data in browser storage', async () => {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockImplementation(async () => jsonResponse(ME)))

    renderApp('/me')
    await screen.findByRole('heading', { name: 'Anna Beispiel' })

    const stored = [
      ...Object.entries({ ...window.localStorage }),
      ...Object.entries({ ...window.sessionStorage }),
    ]

    expect(stored.every(([key]) => key === 'portal.language')).toBe(true)
    expect(JSON.stringify(stored)).not.toContain('Beispiel')
    expect(JSON.stringify(stored)).not.toContain('X1')
  })
})
