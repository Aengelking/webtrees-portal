import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import type { Role } from './api/types'
import './i18n'

/**
 * The link to webtrees, and who is shown it.
 *
 * Editors and above; nobody else. This is a signpost rather than a permission
 * boundary, and the tests say so: `webtrees_url` is still in the payload every
 * member receives, and webtrees decides what the person following it may do.
 * Not drawing the link keeps nothing from a member — it stops offering them a
 * door that leads somewhere they were not going.
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
  birth: null,
  death: null,
  events: [],
  parents: [],
  siblings: [],
  spouses: [],
  children: [],
  webtrees_url: 'https://webtrees.example.org/tree/portal/individual/X1',
}

function stub(role: Role) {
  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input) => {
      const url = String(input)

      if (url.endsWith('/csrf')) {
        return jsonResponse({ csrf_token: 'token-1' })
      }

      return jsonResponse({
        user: {
          id: 1,
          username: 'anna',
          real_name: 'Anna Beispiel',
          email: 'anna@example.test',
          language: 'de',
          role,
        },
        profile: {
          id: 1,
          visible_in_directory: true,
          display_name_override: null,
          consent_recorded_at: null,
        },
        individual: ANNA,
        tree: { name: 'portal', title: 'Familie Beispiel' },
        unread_messages: 0,
        csrf_token: 'token-1',
      })
    }),
  )
}

function renderMe() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter initialEntries={['/me']}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('the link to webtrees', () => {
  it('offers an editor the record they maintain', async () => {
    stub('editor')
    renderMe()

    const link = await screen.findByRole('link', { name: 'In webtrees öffnen und bearbeiten' })

    expect(link.getAttribute('href')).toBe(ANNA.webtrees_url)
  })

  it.each<Role>(['moderator', 'manager', 'administrator'])(
    'offers it to a %s as well',
    async (role) => {
      stub(role)
      renderMe()

      expect(
        await screen.findByRole('link', { name: 'In webtrees öffnen und bearbeiten' }),
      ).toBeDefined()
    },
  )

  /**
   * A member's screen has no way out to webtrees at all now — not a
   * differently worded one, not a quieter one. The assertion is on the host
   * rather than on a label, so a link reintroduced under any wording fails it.
   */
  it('leaves a member no link to webtrees at all', async () => {
    stub('member')
    renderMe()

    // Wait for the record itself, so this is not passing on an empty screen.
    await screen.findByRole('heading', { name: 'Anna Beispiel' })

    const external = screen
      .queryAllByRole('link')
      .filter((link) => (link.getAttribute('href') ?? '').includes('webtrees.example.org'))

    expect(external).toEqual([])
  })

  /** An editor is told why they see something the rest of the family does not. */
  it('says that the link is there because of the role', async () => {
    stub('editor')
    renderMe()

    expect(await screen.findByText(/weil Sie den Stammbaum bearbeiten dürfen/)).toBeDefined()
  })
})
