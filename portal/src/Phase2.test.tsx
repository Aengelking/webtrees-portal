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

const INDIVIDUAL = {
  xref: 'X1',
  name: 'Anna Beispiel',
  sex: 'F',
  is_deceased: false,
  lifespan: '1985–',
  name_alternative: null,
  birth: {
    tag: 'INDI:BIRT',
    label: 'Geburt',
    value: null,
    date: { display: '12. März 1985', gedcom: '12 MAR 1985', year: 1985 },
    place: 'Hannover',
  },
  death: null,
  events: [{ tag: 'INDI:OCCU', label: 'Beruf', value: 'Tischlerin', date: null, place: null }],
  parents: [],
  siblings: [],
  spouses: [],
  children: [],
  pending_change: false,
  webtrees_url: 'https://webtrees.example.org/tree/portal/individual/X1',
}

function me(overrides: Record<string, unknown> = {}) {
  return {
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
      visible_in_directory: false,
      display_name_override: null,
      consent_recorded_at: null,
    },
    individual: INDIVIDUAL,
    tree: { name: 'portal', title: 'Familie Beispiel' },
    csrf_token: 'token-1',
    ...overrides,
  }
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

describe('the birth date is a calendar', () => {
  it('offers the stored date as a date field, and sends GEDCOM back', async () => {
    let sent: unknown = null

    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
        const url = String(input)

        if (url.endsWith('/csrf')) {
          return jsonResponse({ csrf_token: 'token-1' })
        }

        if (url.endsWith('/me/individual')) {
          sent = JSON.parse(String(init?.body))
          return jsonResponse({ status: 'pending_approval', pending_change: true, individual: INDIVIDUAL }, 202)
        }

        return jsonResponse(me())
      }),
    )

    renderAt('/me/edit')

    const field = (await screen.findByLabelText('Geburtsdatum')) as HTMLInputElement

    // A real date picker, prefilled from "12 MAR 1985".
    expect(field.type).toBe('date')
    expect(field.value).toBe('1985-03-12')

    const user = userEvent.setup()
    await user.clear(field)
    await user.type(field, '1985-03-14')
    await user.click(screen.getByRole('button', { name: 'Änderung einreichen' }))

    // What goes to the server is GEDCOM, because that is what is stored.
    await waitFor(() => {
      expect(sent).toEqual({ birth_date: '14 MAR 1985' })
    })
  })

  /**
   * The one that matters. A date the picker cannot hold must not be quietly
   * replaced by an empty field — the member gets told what is on file, and
   * submitting without touching it changes nothing.
   */
  it('leaves an approximate date alone instead of deleting it', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input) =>
      String(input).endsWith('/csrf')
        ? jsonResponse({ csrf_token: 'token-1' })
        : jsonResponse(
            me({
              individual: {
                ...INDIVIDUAL,
                birth: {
                  ...INDIVIDUAL.birth,
                  date: { display: 'etwa 1985', gedcom: 'ABT 1985', year: 1985 },
                },
              },
            }),
          ),
    )
    vi.stubGlobal('fetch', fetchMock)

    renderAt('/me/edit')

    const field = (await screen.findByLabelText('Geburtsdatum')) as HTMLInputElement

    expect(field.value).toBe('')
    expect(screen.getByText(/Bisher gespeichert: etwa 1985/)).toBeDefined()

    await userEvent.setup().click(screen.getByRole('button', { name: 'Änderung einreichen' }))

    expect(await screen.findByText('Sie haben nichts geändert.')).toBeDefined()
    expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/me/individual'))).toHaveLength(0)
  })
})

describe('editing my own record', () => {
  it('sends only the fields that changed', async () => {
    let sent: unknown = null

    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)

      if (url.endsWith('/csrf')) {
        return jsonResponse({ csrf_token: 'token-1' })
      }

      if (url.endsWith('/me/individual')) {
        sent = JSON.parse(String(init?.body))
        return jsonResponse({ status: 'pending_approval', pending_change: true, individual: INDIVIDUAL }, 202)
      }

      return jsonResponse(me())
    })
    vi.stubGlobal('fetch', fetchMock)

    renderAt('/me/edit')

    const user = userEvent.setup()
    const occupation = await screen.findByLabelText('Beruf')
    await user.clear(occupation)
    await user.type(occupation, 'Möbelrestauratorin')
    await user.click(screen.getByRole('button', { name: 'Änderung einreichen' }))

    await waitFor(() => {
      expect(sent).not.toBeNull()
    })

    // Untouched fields are absent, so the server leaves those facts alone
    // rather than being asked to set them to what they already are.
    expect(sent).toEqual({ occupation: 'Möbelrestauratorin' })
  })

  it('clearing a field asks for the fact to be removed', async () => {
    let sent: unknown = null

    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
        const url = String(input)

        if (url.endsWith('/csrf')) {
          return jsonResponse({ csrf_token: 'token-1' })
        }

        if (url.endsWith('/me/individual')) {
          sent = JSON.parse(String(init?.body))
          return jsonResponse({ status: 'pending_approval', pending_change: true, individual: INDIVIDUAL }, 202)
        }

        return jsonResponse(me())
      }),
    )

    renderAt('/me/edit')

    const user = userEvent.setup()
    await user.clear(await screen.findByLabelText('Beruf'))
    await user.click(screen.getByRole('button', { name: 'Änderung einreichen' }))

    await waitFor(() => {
      expect(sent).toEqual({ occupation: null })
    })
  })

  it('submitting nothing does not call the server', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input) =>
      String(input).endsWith('/csrf') ? jsonResponse({ csrf_token: 'token-1' }) : jsonResponse(me()),
    )
    vi.stubGlobal('fetch', fetchMock)

    renderAt('/me/edit')

    await screen.findByLabelText('Beruf')
    await userEvent.setup().click(screen.getByRole('button', { name: 'Änderung einreichen' }))

    expect(await screen.findByText('Sie haben nichts geändert.')).toBeDefined()
    expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/me/individual'))).toHaveLength(0)
  })

  it('says the change is waiting rather than showing it as done', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn<typeof fetch>()
        .mockImplementation(async () => jsonResponse(me({ individual: { ...INDIVIDUAL, pending_change: true } }))),
    )

    renderAt('/me')

    expect(await screen.findByText('Ihre Änderung wird geprüft')).toBeDefined()
    // ...and the edit form is not offered while one is outstanding.
    expect(screen.queryByRole('link', { name: 'Meine Daten ändern' })).toBeNull()
  })
})

describe('directory settings', () => {
  it('turning visibility on sends only that', async () => {
    let sent: unknown = null

    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
        const url = String(input)

        if (url.endsWith('/csrf')) {
          return jsonResponse({ csrf_token: 'token-1' })
        }

        if (url.endsWith('/me/profile')) {
          sent = JSON.parse(String(init?.body))
          return jsonResponse({
            id: 1,
            visible_in_directory: true,
            display_name_override: null,
            consent_recorded_at: '2026-08-17 12:00:00',
          })
        }

        return jsonResponse(me())
      }),
    )

    renderAt('/settings')

    const toggle = await screen.findByRole('switch', { name: /Mitgliederverzeichnis/ })
    expect(toggle.getAttribute('aria-checked')).toBe('false')

    await userEvent.setup().click(toggle)

    await waitFor(() => {
      expect(sent).toEqual({ visible_in_directory: true })
    })
  })
})

describe('password reset', () => {
  it('says the same thing whether or not the address exists', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) =>
        String(input).endsWith('/csrf')
          ? jsonResponse({ csrf_token: 'token-1' })
          : jsonResponse({ status: 'accepted' }, 202),
      ),
    )

    renderAt('/password/request')

    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('E-Mail-Adresse'), 'niemand@example.test')
    await user.click(screen.getByRole('button', { name: 'Link anfordern' }))

    // Deliberately not "no such address" — that would undo the server's care.
    expect(await screen.findByText('Bitte sehen Sie in Ihr Postfach')).toBeDefined()
  })

  it('a link with no token explains itself instead of failing', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn<typeof fetch>()
        .mockImplementation(async () => jsonResponse({ error: 'unauthenticated', message: 'x' }, 401)),
    )

    renderAt('/password/reset')

    expect(await screen.findByText('Dieser Link ist unvollständig')).toBeDefined()
  })

  it('refuses two passwords that do not match, without calling the server', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input) =>
      String(input).endsWith('/csrf')
        ? jsonResponse({ csrf_token: 'token-1' })
        : jsonResponse({ error: 'unauthenticated', message: 'x' }, 401),
    )
    vi.stubGlobal('fetch', fetchMock)

    renderAt('/password/reset?token=abc')

    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Neues Passwort'), 'ein-langes-passwort')
    await user.type(screen.getByLabelText('Passwort wiederholen'), 'etwas-anderes')
    await user.click(screen.getByRole('button', { name: 'Passwort speichern' }))

    expect(await screen.findByText('Die beiden Passwörter stimmen nicht überein.')).toBeDefined()
    expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/password/reset'))).toHaveLength(0)
  })

  it('an expired token says so and clears the fields', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
        const url = String(input)

        if (url.endsWith('/csrf')) {
          return jsonResponse({ csrf_token: 'token-1' })
        }

        if (url.endsWith('/password/reset') && init?.method === 'POST') {
          return jsonResponse({ error: 'invalid_token', message: 'expired' }, 400)
        }

        return jsonResponse({ error: 'unauthenticated', message: 'x' }, 401)
      }),
    )

    renderAt('/password/reset?token=abc')

    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Neues Passwort'), 'ein-langes-passwort')
    await user.type(screen.getByLabelText('Passwort wiederholen'), 'ein-langes-passwort')
    await user.click(screen.getByRole('button', { name: 'Passwort speichern' }))

    expect(
      await screen.findByText('Dieser Link ist abgelaufen oder wurde schon benutzt. Bitte fordern Sie einen neuen an.'),
    ).toBeDefined()
    expect((screen.getByLabelText('Neues Passwort') as HTMLInputElement).value).toBe('')
  })

  it('a successful reset signs the member in', async () => {
    let signedIn = false

    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
        const url = String(input)

        if (url.endsWith('/csrf')) {
          return jsonResponse({ csrf_token: 'token-1' })
        }

        if (url.endsWith('/password/reset') && init?.method === 'POST') {
          signedIn = true
          return jsonResponse(me())
        }

        return signedIn ? jsonResponse(me()) : jsonResponse({ error: 'unauthenticated', message: 'x' }, 401)
      }),
    )

    renderAt('/password/reset?token=abc')

    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Neues Passwort'), 'ein-langes-passwort')
    await user.type(screen.getByLabelText('Passwort wiederholen'), 'ein-langes-passwort')
    await user.click(screen.getByRole('button', { name: 'Passwort speichern' }))

    expect(await screen.findByRole('heading', { name: 'Mein Profil' })).toBeDefined()
  })
})
