import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from './auth/AuthProvider'
import { Login } from './routes/Login'
import './i18n'

/**
 * "Angemeldet bleiben" — the client's half.
 *
 * The server decides whether this is on offer at all and for how long, and
 * says so on `GET /csrf`, which is the only endpoint the login screen can
 * reach before anybody is signed in. So every assertion here is about the
 * screen obeying that answer rather than assuming one: no offer where the
 * family has not made it, the real number of days where they have, and a
 * `remember` that says what the member actually chose.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const sent: Record<string, unknown>[] = []

function stub(csrf: Record<string, unknown>) {
  sent.length = 0

  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)

      if (url.endsWith('/csrf')) {
        return jsonResponse(csrf)
      }

      if (url.endsWith('/session') && init?.method === 'POST') {
        sent.push(JSON.parse(String(init.body)) as Record<string, unknown>)

        return jsonResponse({ error: 'invalid_credentials', message: 'no' }, 401)
      }

      return jsonResponse({ error: 'unauthenticated', message: 'sign in' }, 401)
    }),
  )
}

function renderLogin() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter>
        <AuthProvider>
          <Login />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function signIn(remember: boolean) {
  const user = userEvent.setup()

  await user.type(await screen.findByLabelText('Benutzername oder E-Mail-Adresse'), 'anna')
  await user.type(screen.getByLabelText('Passwort'), 'pw')

  if (remember) {
    await user.click(screen.getByRole('switch', { name: 'Angemeldet bleiben' }))
  }

  await user.click(screen.getByRole('button', { name: 'Anmelden' }))
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('staying signed in', () => {
  it('offers nothing where the family has not switched it on', async () => {
    stub({ csrf_token: 'token-1', remember_days: 0 })
    renderLogin()

    expect(await screen.findByLabelText('Passwort')).toBeDefined()
    expect(screen.queryByRole('switch', { name: 'Angemeldet bleiben' })).toBeNull()
  })

  /**
   * A switch promising "stay signed in" without saying until when is a
   * promise the member has no way to check, so the number the server sent is
   * the number on screen.
   */
  it('says how long it lasts, in the number of days the server allows', async () => {
    stub({ csrf_token: 'token-1', remember_days: 30 })
    renderLogin()

    expect(await screen.findByRole('switch', { name: 'Angemeldet bleiben' })).toBeDefined()
    expect(screen.getByText(/30 Tage angemeldet/)).toBeDefined()

    // The other half of the sentence, which is the reason it is off by
    // default: this is a key left on a device.
    expect(screen.getByText(/nur auf Ihrem eigenen Telefon/)).toBeDefined()
  })

  it('asks for it only when the member did', async () => {
    stub({ csrf_token: 'token-1', remember_days: 30 })
    renderLogin()

    await signIn(true)

    await waitFor(() => {
      expect(sent).toEqual([{ username: 'anna', password: 'pw', remember: true }])
    })
  })

  /**
   * `false` rather than nothing at all: the server treats it as an
   * instruction to forget this device, which is what a member who ticked the
   * box last month and left it alone this time has just said.
   */
  it('says no rather than staying silent when the member leaves it alone', async () => {
    stub({ csrf_token: 'token-1', remember_days: 30 })
    renderLogin()

    await signIn(false)

    await waitFor(() => {
      expect(sent).toEqual([{ username: 'anna', password: 'pw', remember: false }])
    })
  })

  /**
   * The screen exists to let somebody sign in, and asking whether they may
   * stay signed in is a question it added. A blip on that question must not
   * become an error in front of a member who only wanted to type a password,
   * and must not poison the sign-in that follows — the token is fetched again
   * when the form is submitted, as it always was.
   */
  it('stays usable, and quiet, when the portal will not say whether it is on offer', async () => {
    let asked = 0
    sent.length = 0

    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
        const url = String(input)

        if (url.endsWith('/csrf')) {
          asked += 1

          // Only the first ask — the one this screen added — fails.
          return asked === 1
            ? jsonResponse({ error: 'server_error', message: 'no' }, 500)
            : jsonResponse({ csrf_token: 'token-1', remember_days: 30 })
        }

        if (url.endsWith('/session') && init?.method === 'POST') {
          sent.push(JSON.parse(String(init.body)) as Record<string, unknown>)

          return jsonResponse({ error: 'invalid_credentials', message: 'no' }, 401)
        }

        return jsonResponse({ error: 'unauthenticated', message: 'sign in' }, 401)
      }),
    )

    renderLogin()

    expect(await screen.findByLabelText('Passwort')).toBeDefined()

    // No answer means no offer, and no explanation either: "we could not find
    // out whether you may stay signed in" is not a sentence worth showing.
    expect(screen.queryByRole('switch', { name: 'Angemeldet bleiben' })).toBeNull()
    expect(screen.queryByRole('alert')).toBeNull()

    await signIn(false)

    await waitFor(() => {
      expect(sent).toEqual([{ username: 'anna', password: 'pw', remember: false }])
    })
  })
})
