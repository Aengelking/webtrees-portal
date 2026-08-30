import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import './i18n'

/**
 * Phase 16: looking through the archive rather than walking it.
 *
 * The rule about who may be found lives in the module and is proved there —
 * `TreeSearchTest` is where "a living person who did not list themselves is
 * not in the answer" is asserted. This screen has no filtering of its own and
 * no way to ask for more, so what is tested here is the other half: that a
 * member can get from a name they half remember to the person, and back out
 * again, without the screen inventing an answer along the way.
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
  references: [
    { number: '4711', type: 'SB', branch: null },
    { number: '10/1335.11', type: 'SB', branch: 'Ernestinische Linie – Zweig Cleve' },
  ],
  photos: [],
  birth: null,
  death: null,
  events: [],
  parents: [],
  siblings: [],
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
    email: 'a@b.test',
    language: 'de',
    role: 'member',
  },
  profile: { id: 1, visible_in_directory: true, display_name_override: null, consent_recorded_at: null, directory_decided: true },
  individual: ANNA,
  tree: { name: 'portal', title: 'Familie Beispiel' },
  csrf_token: 'token-1',
}

const BERTHA = {
  xref: 'X2',
  name: 'Bertha Beispiel',
  sex: 'F',
  is_deceased: true,
  lifespan: '1889–1976',
  references: [{ number: '4712', type: 'SB', branch: null }],
  relationship: 'Großmutter',
}

const CALCULATION = {
  a: '10/1335.11',
  b: '24/b6',
  problem: null,
  relationship: 'Cousin/Cousine 3. Grades',
  detail: { kind: 'cousin', generations: 0, distance: 4, degree: 3 },
}

const INDEX = {
  surnames: [
    { name: 'Beispiel', count: 6 },
    { name: 'Fernab', count: 1 },
  ],
  places: [{ name: 'Celle, Niedersachsen, Deutschland', count: 2 }],
  truncated: false,
}

/** Every search request the screen made, in order, as its query string. */
const asked: string[] = []

function stub(
  results: unknown = { items: [BERTHA], total: 1, page: 1, per_page: 25, truncated: false },
  calculation: unknown = CALCULATION,
) {
  asked.length = 0

  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input) => {
      const url = String(input)

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

      if (url.includes('/search')) {
        asked.push(url.slice(url.indexOf('?') + 1))

        return jsonResponse(results)
      }

      if (url.includes('/relationship')) {
        asked.push(url.slice(url.indexOf('?') + 1))

        return jsonResponse(calculation)
      }

      if (url.includes('/index')) return jsonResponse(INDEX)
      if (url.includes('/individuals/X2')) return jsonResponse({ ...ANNA, ...BERTHA, photos: [] })

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

describe('searching the archive', () => {
  /**
   * An empty field is not a question. Asking one anyway would put "keine
   * Treffer" under a field nobody has typed into yet, which reads as an answer
   * about the archive rather than about the query.
   */
  it('asks nothing until something has been typed', async () => {
    stub()
    renderAt('/tree')

    await screen.findByLabelText(/Name oder SB-Nr/)

    expect(screen.queryByText(/Keine Treffer/)).toBeNull()
    expect(asked).toEqual([])
  })

  it('finds a person and shows the years, the number and the relationship', async () => {
    stub()
    renderAt('/tree')

    await userEvent.type(await screen.findByLabelText(/Name oder SB-Nr/), 'Bertha')

    const card = await screen.findByRole('link', { name: /Bertha Beispiel/ })

    expect(card.textContent).toContain('1889–1976')
    expect(card.textContent).toContain('SB 4712')
    expect(card.textContent).toContain('Für dich: Großmutter')
  })

  it('opens the person it found', async () => {
    stub()
    renderAt('/tree?tab=search&q=Bertha')

    await userEvent.setup().click(await screen.findByRole('link', { name: /Bertha Beispiel/ }))

    expect(await screen.findByRole('heading', { name: 'Bertha Beispiel' })).toBeDefined()
  })

  it('says so when nobody matched, and offers the way out', async () => {
    stub({ items: [], total: 0, page: 1, per_page: 25, truncated: false })
    renderAt('/tree?tab=search&q=Zzz')

    expect(await screen.findByText(/Keine Treffer/)).toBeDefined()
    expect(screen.getByRole('button', { name: 'Suche zurücksetzen' })).toBeDefined()
  })

  /**
   * A truncated list that says nothing is a list a member will believe.
   */
  it('admits when there were more matches than it can show', async () => {
    stub({ items: [BERTHA], total: 500, page: 1, per_page: 25, truncated: true })
    renderAt('/tree?tab=search&q=e')

    expect(await screen.findByText(/mehr Treffer, als hier gezeigt/)).toBeDefined()
  })
})

describe('reading down the indexes', () => {
  it('lists the surnames with how many people carry each', async () => {
    stub()
    renderAt('/tree?tab=surnames')

    const entry = await screen.findByRole('button', { name: /Beispiel/ })

    expect(entry.textContent).toContain('6')
  })

  /**
   * The index and the search are two ends of one thing: tapping an entry runs
   * the search from the other side, with the name exactly as it was listed.
   */
  it('tapping a surname lists the people under it', async () => {
    stub()
    renderAt('/tree?tab=surnames')

    await userEvent.setup().click(await screen.findByRole('button', { name: /Fernab/ }))

    expect(await screen.findByRole('heading', { name: /Alle mit dem Namen Fernab/ })).toBeDefined()
    expect(asked.some((query) => query.includes('surname=Fernab'))).toBe(true)
  })

  it('lists the places, and tapping one lists the people with an event there', async () => {
    stub()
    renderAt('/tree?tab=places')

    await userEvent
      .setup()
      .click(await screen.findByRole('button', { name: /Celle, Niedersachsen, Deutschland/ }))

    expect(asked.some((query) => query.includes('place=Celle'))).toBe(true)
  })

  /**
   * The index is the most expensive thing the module computes. A member who
   * came here to type a name should not pay for it.
   */
  it('does not fetch the indexes for somebody who came to type', async () => {
    stub()
    const calls = vi.mocked(fetch)

    renderAt('/tree')
    await screen.findByLabelText(/Name oder SB-Nr/)

    expect(calls.mock.calls.some(([input]) => String(input).includes('/index'))).toBe(false)
  })
})

describe('the way in', () => {
  it('is on the member’s own profile', async () => {
    stub()
    renderAt('/me')

    const link = await screen.findByRole('link', { name: 'Stammbaum durchsuchen' })

    await userEvent.setup().click(link)

    expect(await screen.findByRole('heading', { name: 'Stammbaum' })).toBeDefined()
  })
})

describe('the archive-number calculator', () => {
  /**
   * The question a member actually has is "how am I related to this", so the
   * number they are holding goes in the second field and their own is already
   * in the first.
   */
  it('starts from the member’s own number', async () => {
    stub()
    renderAt('/tree?tab=calculator')

    const first = (await screen.findByLabelText('SB-Nr. 1')) as HTMLInputElement

    // "4711" is the archive's older numbering and is not a path; the one that
    // is gets picked out of the same list.
    expect(first.value).toBe('10/1335.11')
  })

  /**
   * A record that belongs to no line carries "GS/" and then the path itself.
   * Those are numbers too, and the calculator has to start from one.
   */
  it('accepts a number that belongs to no line', async () => {
    stub()
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) => {
        const url = String(input)

        if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })
        if (url.includes('/relationship')) return jsonResponse(CALCULATION)

        return jsonResponse({
          ...ME,
          individual: { ...ANNA, references: [{ number: 'GS/755133', type: 'SB', branch: 'Nachkommen von Georg Sack' }] },
        })
      }),
    )

    renderAt('/tree?tab=calculator')

    const first = (await screen.findByLabelText('SB-Nr. 1')) as HTMLInputElement

    expect(first.value).toBe('GS/755133')
  })

  it('asks nothing until both numbers are there', async () => {
    stub()
    renderAt('/tree?tab=calculator')

    await screen.findByLabelText('SB-Nr. 1')

    expect(asked).toEqual([])
    expect(screen.queryByText(/Cousin/)).toBeNull()
  })

  it('names the relationship once both are given', async () => {
    stub()
    renderAt('/tree?tab=calculator')

    await userEvent.type(await screen.findByLabelText('SB-Nr. 2'), '24/b6')

    expect(await screen.findByText('Cousin/Cousine 3. Grades')).toBeDefined()
    expect(asked.some((query) => query.includes('b=24%2Fb6'))).toBe(true)
  })

  /**
   * A person whose ancestors married within the family has more than one
   * archive number, and each measures a different distance. The nearest
   * leads; the rest are equally true and belong on the screen.
   */
  it('names the other ways they are related, after the nearest', async () => {
    stub(undefined, {
      ...CALCULATION,
      relationship: 'Cousin 3. Grades, einmal entfernt',
      relationships: ['Cousin 3. Grades, einmal entfernt', 'Cousin 5. Grades'],
    })
    renderAt('/tree?tab=calculator')

    await userEvent.type(await screen.findByLabelText('SB-Nr. 2'), '24/b6')

    expect(await screen.findByText('Cousin 3. Grades, einmal entfernt')).toBeDefined()
    expect(screen.getByText(/Cousin 5\. Grades/)).toBeDefined()
  })

  /** One answer stays one line: no "außerdem" where there is nothing else. */
  it('says nothing extra where there is only one way', async () => {
    stub(undefined, { ...CALCULATION, relationships: ['Cousin/Cousine 3. Grades'] })
    renderAt('/tree?tab=calculator')

    await userEvent.type(await screen.findByLabelText('SB-Nr. 2'), '24/b6')

    await screen.findByText('Cousin/Cousine 3. Grades')

    expect(screen.queryByText(/außerdem/)).toBeNull()
  })

  /** And a server that predates the field sends none, which is not a crash. */
  it('survives a server that does not send the list yet', async () => {
    stub()
    renderAt('/tree?tab=calculator')

    await userEvent.type(await screen.findByLabelText('SB-Nr. 2'), '24/b6')

    expect(await screen.findByText('Cousin/Cousine 3. Grades')).toBeDefined()
    expect(screen.queryByText(/außerdem/)).toBeNull()
  })

  /**
   * A number somebody mistyped should say which field to fix, not report a
   * failure — the request succeeded, the number is the problem.
   */
  it('points at the field that is wrong', async () => {
    stub(undefined, {
      a: '10/1335.11',
      b: 'Bertha',
      problem: 'invalid_b',
      relationship: null,
      detail: null,
    })
    renderAt('/tree?tab=calculator')

    await userEvent.type(await screen.findByLabelText('SB-Nr. 2'), 'Bertha')

    expect(await screen.findByText('SB-Nr. 2 ist keine gültige Nummer.')).toBeDefined()
  })
})
