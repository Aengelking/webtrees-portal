import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import i18n from './i18n'

/**
 * Half of what a member reads is translated here, in the browser, and half of
 * it — fact labels, formatted dates, webtrees' privacy placeholders — is
 * rendered by the server. Both halves have to move together, or the portal
 * reads German with "Birth" and "Occupation" scattered through it.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function individual(labels: { birth: string; occupation: string }) {
  return {
    xref: 'X1',
    name: 'Anna Beispiel',
    sex: 'F',
    is_deceased: false,
    lifespan: '1985–',
    name_alternative: null,
    birth: {
      tag: 'INDI:BIRT',
      label: labels.birth,
      value: null,
      date: { display: '12. März 1985', gedcom: '12 MAR 1985', year: 1985 },
      place: 'Hannover',
    },
    death: null,
    events: [
      {
        tag: 'INDI:BIRT',
        label: labels.birth,
        value: null,
        date: { display: '12. März 1985', gedcom: '12 MAR 1985', year: 1985 },
        place: 'Hannover',
      },
      { tag: 'INDI:OCCU', label: labels.occupation, value: 'Tischlerin', date: null, place: null },
    ],
    parents: [],
    siblings: [],
    spouses: [],
    children: [],
    pending_change: false,
    webtrees_url: 'https://webtrees.example.org/tree/portal/individual/X1',
  }
}

/** What the module does: answers in the language the request asked for. */
function meFor(language: string | null) {
  const german = language === null || language.startsWith('de')

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
      visible_in_directory: true,
      display_name_override: null,
      consent_recorded_at: '2026-01-01 00:00:00',
    },
    individual: german
      ? individual({ birth: 'Geburt', occupation: 'Beruf' })
      : individual({ birth: 'Birth', occupation: 'Occupation' }),
    tree: { name: 'portal', title: 'Familie Beispiel' },
    csrf_token: 'token-1',
  }
}

function languageOf(init: RequestInit | undefined): string | null {
  const headers = (init?.headers ?? {}) as Record<string, string>

  return headers['Accept-Language'] ?? null
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

afterEach(async () => {
  await i18n.changeLanguage('de')
})

describe('the language the server answers in', () => {
  it('is sent on every request', async () => {
    const sent: (string | null)[] = []

    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
        sent.push(languageOf(init))

        return String(input).endsWith('/csrf')
          ? jsonResponse({ csrf_token: 'token-1' })
          : jsonResponse(meFor(languageOf(init)))
      }),
    )

    renderAt('/me')

    await screen.findByText('Geburt')

    expect(sent.length).toBeGreaterThan(0)
    expect(sent.every((language) => language === 'de')).toBe(true)
  })

  /**
   * The regression this file exists for: switching to English used to leave
   * the server-rendered labels in German, because nothing told the server and
   * nothing refetched.
   */
  it('follows the language switch, and the labels follow with it', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input, init) =>
        String(input).endsWith('/csrf')
          ? jsonResponse({ csrf_token: 'token-1' })
          : jsonResponse(meFor(languageOf(init))),
      ),
    )

    renderAt('/me')

    expect(await screen.findByText('Geburt')).toBeDefined()

    await userEvent.setup().click(screen.getByRole('link', { name: 'Einstellungen' }))
    await userEvent.setup().click(await screen.findByRole('button', { name: 'English' }))

    // Back to the profile: it is refetched, in English this time.
    await userEvent.setup().click(screen.getByRole('link', { name: 'My profile' }))

    await waitFor(() => {
      expect(screen.getByText('Birth')).toBeDefined()
    })

    expect(screen.queryByText('Geburt')).toBeNull()
  })
})

/**
 * A language is a fact about a person, not about a telephone. It lives on the
 * account — the same webtrees preference the account's own settings page sets
 * — so it follows the member to the next device, and so the mail webtrees
 * sends them arrives in the language they read.
 */
describe('the language the member chose', () => {
  it('comes from the account, not from this device', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input, init) =>
        String(input).endsWith('/csrf')
          ? jsonResponse({ csrf_token: 'token-1' })
          : jsonResponse({ ...meFor(languageOf(init)), user: { ...meFor(null).user, language: 'en-US' } }),
      ),
    )

    renderAt('/me')

    // The account says English, so English is what the member gets — whatever
    // this browser last remembered.
    expect(await screen.findByRole('heading', { name: 'My profile' })).toBeDefined()
  })

  /** A tag the portal has no translation for leaves the language alone. */
  it('stays put for a language the portal does not have', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input, init) =>
        String(input).endsWith('/csrf')
          ? jsonResponse({ csrf_token: 'token-1' })
          : jsonResponse({ ...meFor(languageOf(init)), user: { ...meFor(null).user, language: 'fr' } }),
      ),
    )

    renderAt('/me')

    expect(await screen.findByRole('heading', { name: 'Mein Profil' })).toBeDefined()
  })

  it('is saved to the account when the switch is used', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) =>
      String(input).endsWith('/csrf')
        ? jsonResponse({ csrf_token: 'token-1' })
        : jsonResponse(meFor(languageOf(init))),
    )

    vi.stubGlobal('fetch', fetchMock)

    renderAt('/settings')

    await userEvent.setup().click(await screen.findByRole('button', { name: 'English' }))

    await waitFor(() => {
      const patch = fetchMock.mock.calls.find(
        ([url, init]) => init?.method === 'PATCH' && String(url).endsWith('/me/profile'),
      )

      expect(patch).toBeDefined()
      expect(JSON.parse(String(patch?.[1]?.body))).toEqual({ language: 'en' })
    })
  })

  /**
   * The login screen has the switcher too, and there is no account to save it
   * to yet. The device preference is the whole answer there.
   */
  it('is not saved when nobody is signed in', async () => {
    const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input) =>
      String(input).endsWith('/csrf')
        ? jsonResponse({ csrf_token: 'token-1' })
        : jsonResponse({ error: 'unauthenticated' }, 401),
    )

    vi.stubGlobal('fetch', fetchMock)

    renderAt('/login')

    await userEvent.setup().click(await screen.findByRole('button', { name: 'English' }))

    expect(await screen.findByRole('button', { name: 'Sign in' })).toBeDefined()
    expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(false)
  })
})

