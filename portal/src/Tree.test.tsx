import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import './i18n'

/**
 * Phase 3: the family tree inside the portal.
 *
 * Everything these screens show has already been filtered by the API at the
 * member's access level, so there is nothing for the client to hide. What the
 * tests here are about is that it is *walkable* — that a relative is a link
 * and not a dead end, which is the whole point of the phase.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const ANNA = {
  xref: 'X1',
  name: 'Anna Beispiel',
  sex: 'F',
  is_deceased: false,
  lifespan: '1985–',
  name_alternative: null,
  relationship: null,
  references: [],
  birth: null,
  death: null,
  events: [],
  parents: [
    {
      xref: 'X2',
      name: 'Bertha Beispiel',
      sex: 'F',
      is_deceased: true,
      lifespan: '1889–1976',
      references: [{ number: '4712', type: 'SB', branch: null }],
    },
  ],
  siblings: [],
  spouses: [],
  children: [],
  pending_change: false,
  webtrees_url: 'https://webtrees.example.org/tree/portal/individual/X1',
}

const BERTHA = {
  ...ANNA,
  xref: 'X2',
  name: 'Bertha Beispiel',
  lifespan: '1889–1976',
  is_deceased: true,
  relationship: 'Ihre Mutter',
  parents: [],
}

const ME = {
  user: { id: 1, username: 'anna', real_name: 'Anna Beispiel', email: 'a@b.test', language: 'de', role: 'member' },
  profile: { id: 1, visible_in_directory: true, display_name_override: null, consent_recorded_at: null },
  individual: ANNA,
  tree: { name: 'portal', title: 'Familie Beispiel' },
  csrf_token: 'token-1',
}

const ANCESTORS = {
  generations: 4,
  people: [
    { position: 1, generation: 0, private: false, xref: 'X1', name: 'Anna Beispiel', sex: 'F', is_deceased: false, lifespan: '1985–' },
    { position: 2, generation: 1, private: false, xref: 'X5', name: 'Emil Beispiel', sex: 'M', is_deceased: true, lifespan: '1884–1961' },
    { position: 3, generation: 1, private: false, xref: 'X2', name: 'Bertha Beispiel', sex: 'F', is_deceased: true, lifespan: '1889–1976' },
    // Emil's mother is alive and out of reach, and she is in the member
    // directory — so she is named from there, and from nowhere else.
    { position: 5, generation: 2, private: true, member: { id: 9, display_name: 'Helene Beispiel' } },
    { position: 6, generation: 2, private: false, xref: 'X10', name: 'Konrad Beispiel', sex: 'M', is_deceased: true, lifespan: '1858–1929' },
    // Bertha's mother is confidential: a position, and nothing else.
    { position: 7, generation: 2, private: true, member: null },
    // And the line carries on above her, which is the point of the placeholder.
    { position: 14, generation: 3, private: false, xref: 'X12', name: 'Otto Fernab', sex: 'M', is_deceased: true, lifespan: '1830–1899' },
  ],
}

function stub() {
  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input) => {
      const url = String(input)

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })
      if (url.includes('/ancestors')) return jsonResponse(ANCESTORS)
      if (url.includes('/individuals/X2')) return jsonResponse(BERTHA)
      if (url.includes('/individuals/X1')) return jsonResponse(ANNA)

      return jsonResponse(ME)
    }),
  )
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

describe('walking the tree', () => {
  it('a relative is a link, and following it opens them', async () => {
    stub()
    renderAt('/me')

    const mother = await screen.findByRole('link', { name: /Bertha Beispiel/ })

    await userEvent.setup().click(mother)

    expect(await screen.findByRole('heading', { name: 'Bertha Beispiel' })).toBeDefined()
  })

  it('says how the member is related to the person they opened', async () => {
    stub()
    renderAt('/individuals/X2')

    expect(await screen.findByText('Für Sie: Ihre Mutter')).toBeDefined()
  })

  it('says nothing at all when there is no relationship to name', async () => {
    stub()
    renderAt('/individuals/X1')

    await screen.findByRole('heading', { name: 'Anna Beispiel' })

    expect(screen.queryByText(/Für Sie:/)).toBeNull()
  })

  it('survives a server that does not send a relationship yet', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) => {
        const url = String(input)
        if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

        const { relationship, ...withoutRelationship } = BERTHA

        return url.includes('/individuals/') ? jsonResponse(withoutRelationship) : jsonResponse(ME)
      }),
    )

    renderAt('/individuals/X2')

    expect(await screen.findByRole('heading', { name: 'Bertha Beispiel' })).toBeDefined()
    expect(screen.queryByText(/Für Sie:/)).toBeNull()
  })
})

describe('the ancestors view', () => {
  it('lists the generations, and each one opens', async () => {
    stub()
    renderAt('/individuals/X1/ancestors')

    expect(await screen.findByRole('heading', { name: 'Vorfahren' })).toBeDefined()

    // The heading renders before the request comes back, so wait for the list.
    expect(await screen.findByRole('link', { name: /Emil Beispiel/ })).toBeDefined()

    for (const name of ['Bertha Beispiel', 'Konrad Beispiel']) {
      expect(screen.getByRole('link', { name: new RegExp(name) })).toBeDefined()
    }

    // Father's and mother's lines are told apart, which is what the indent
    // alone cannot say to a screen reader.
    expect(screen.getAllByText('Väterliche Linie').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Mütterliche Linie').length).toBeGreaterThan(0)
  })

  /**
   * A row the reader cannot open invites them to wonder what is behind it.
   * Saying it outright is better than letting them guess.
   */
  it('says why some rows cannot be opened', async () => {
    stub()
    renderAt('/individuals/X1/ancestors')

    expect(await screen.findByText(/das sind fast immer die Lebenden/)).toBeDefined()
  })

  /**
   * The placeholder, and what makes it one: it is a position and not a
   * person, so there is nothing to open and no link to offer.
   */
  it('shows a rung the reader may not see as a row that does not open', async () => {
    stub()
    renderAt('/individuals/X1/ancestors')

    const placeholder = await screen.findByText('Nicht freigegeben')

    expect(placeholder.closest('a')).toBeNull()
  })

  it('carries the line on above a rung it may not show', async () => {
    stub()
    renderAt('/individuals/X1/ancestors')

    expect(await screen.findByRole('link', { name: /Otto Fernab/ })).toBeDefined()
  })

  /**
   * The one thing a placeholder may carry, and it is that person's own doing.
   *
   * The link goes to their member page rather than to a record, because the
   * member page is what they consented to publish — and the row says so, so
   * that a name here is not mistaken for the family tree opening up.
   */
  it('names a rung whose member listed themselves, and links to their member page', async () => {
    stub()
    renderAt('/individuals/X1/ancestors')

    const listed = await screen.findByRole('link', { name: /Helene Beispiel/ })

    expect(listed.getAttribute('href')).toBe('/members/9')
    expect(screen.getByText('Im Mitgliederverzeichnis eingetragen')).toBeDefined()
  })

  it('explains an empty pedigree rather than showing an empty list', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) =>
        String(input).endsWith('/csrf')
          ? jsonResponse({ csrf_token: 'token-1' })
          : String(input).includes('/ancestors')
            ? jsonResponse({ generations: 4, people: [ANCESTORS.people[0]] })
            : jsonResponse(ME),
      ),
    )

    renderAt('/individuals/X1/ancestors')

    expect(await screen.findByText('Keine Vorfahren hinterlegt')).toBeDefined()
  })

  it('is reachable from a person, and asks for four generations', async () => {
    stub()
    renderAt('/me')

    await userEvent.setup().click(await screen.findByRole('link', { name: 'Vorfahren anzeigen' }))

    expect(await screen.findByRole('heading', { name: 'Vorfahren' })).toBeDefined()

    const asked = vi.mocked(fetch).mock.calls.map(([url]) => String(url))

    expect(asked.some((url) => url.includes('/ancestors') && url.includes('generations=4'))).toBe(true)
  })
})

/**
 * The archive's number on the card, not only on the record.
 *
 * This family has more than one Dieter Beispiel and tells them apart by it, so
 * a card that leaves it out is a card that has to be opened to be sure. The
 * module sends it with every mention of a person; the same facts at the same
 * access level, so nothing is disclosed that the record one tap away did not
 * already show.
 */
describe('the reference number on a card', () => {
  it('is under the name of a relative, beside the years', async () => {
    stub()
    renderAt('/me')

    const card = await screen.findByRole('link', { name: /Bertha Beispiel/ })

    expect(card.textContent).toContain('SB 4712')
    expect(card.textContent).toContain('1889–1976')
  })

  /** A record with no number shows no empty line where one would be. */
  it('is simply absent where a person has none', async () => {
    stub()
    renderAt('/individuals/X2')

    expect(await screen.findByRole('heading', { name: 'Bertha Beispiel' })).toBeDefined()
    expect(screen.queryByText(/SB 4712/)).toBeNull()
  })
})
