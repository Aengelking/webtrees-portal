import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import './i18n'

/**
 * The office somebody holds in the foundation, wherever the portal names them.
 *
 * The server decides who holds what and none of that is re-decided here. What
 * only the screen can get wrong is the reading rule: an office reaches a
 * member's row two ways — inside the record where it is readable, beside it
 * where it is not — and a screen that reads only one of them shows the
 * chairwoman's office to some members and not others, which is the exact
 * failure this feature exists to prevent.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
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

/** A member whose record this reader may read: the office is inside it. */
const READABLE = {
  id: 3,
  display_name: 'Dieter Beispiel',
  individual: {
    xref: 'X4',
    name: 'Dieter Beispiel',
    sex: 'M',
    is_deceased: false,
    lifespan: '1990–',
    portrait: null,
    references: [],
    relationship: 'Ihr Bruder',
    office: 'Vorsitzender des Vorstands',
  },
}

/** A member whose record is closed: the office arrives beside it. */
const CLOSED = {
  id: 4,
  display_name: 'Clara Beispiel',
  individual: null,
  portrait: null,
  references: [],
  office: 'Schriftführerin',
}

/** And somebody who simply holds no office. */
const PLAIN = {
  id: 5,
  display_name: 'Ernst Beispiel',
  individual: null,
  portrait: null,
  references: [],
  office: null,
}

function stub(items: unknown[]) {
  const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input) => {
    const url = String(input)

    // Order matters, and not only here: "/members" contains "/me".
    if (url.includes('/members')) {
      return jsonResponse({ items, total: items.length, page: 1, per_page: 25 })
    }

    if (url.includes('/me')) {
      return jsonResponse(ME)
    }

    return jsonResponse({})
  })

  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

function renderApp(initialPath: string) {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter initialEntries={[initialPath]}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('an office in the foundation', () => {
  it('shows the office of a member whose record can be read', async () => {
    stub([READABLE])

    renderApp('/members')

    expect(await screen.findByText('Vorsitzender des Vorstands')).toBeTruthy()
  })

  /**
   * The case the whole feature is for. Anna may not read a word of Clara's
   * record — the row says so — and still learns that Clara is the one to
   * write to.
   */
  it('shows the office of a member whose record is closed', async () => {
    stub([CLOSED])

    renderApp('/members')

    expect(await screen.findByText('Schriftführerin')).toBeTruthy()
    expect(screen.getByText('Keine Angaben aus dem Stammbaum sichtbar')).toBeTruthy()
  })

  it('says nothing where nobody holds one', async () => {
    stub([PLAIN])

    renderApp('/members')

    expect(await screen.findByText('Ernst Beispiel')).toBeTruthy()
    expect(screen.queryByText(/Amt in der Stiftung/)).toBeNull()
  })

  /**
   * Colour and a box carry the distinction for a reader who can see them.
   * Somebody using a screen reader hears the words alone, and "Schriftführerin"
   * next to a name is not self-evidently a job — so the label is in the text.
   */
  it('tells a screen reader what the words are', async () => {
    stub([CLOSED])

    renderApp('/members')

    await screen.findByText('Schriftführerin')

    expect(screen.getByText(/Amt in der Stiftung/)).toBeTruthy()
  })

  /**
   * The reading rule, pinned from the other side: a server a version behind
   * sends no `office` at all, and the row has to survive that rather than
   * printing "undefined" beside somebody's name.
   */
  it('survives a server that has never heard of offices', async () => {
    stub([{ id: 6, display_name: 'Frieda Beispiel', individual: null }])

    renderApp('/members')

    expect(await screen.findByText('Frieda Beispiel')).toBeTruthy()
    expect(screen.queryByText(/Amt in der Stiftung/)).toBeNull()
  })
})
