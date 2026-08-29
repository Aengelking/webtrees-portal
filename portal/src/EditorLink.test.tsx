import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import type { Role } from './api/types'
import './i18n'

/**
 * The link to webtrees, and who is shown which one.
 *
 * This is a signpost rather than a permission boundary, and the tests say so:
 * the address is the same one every member has always been given, and
 * webtrees decides what the person following it may do. What is being pinned
 * is that a member is not pointed at an editing screen, that an editor is not
 * left hunting for the tree they maintain, and that neither of them is shown
 * two links to one page.
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

    const link = await screen.findByRole('link', { name: /In webtrees öffnen und bearbeiten/ })

    expect(link.getAttribute('href')).toBe(ANNA.webtrees_url)
  })

  /**
   * In a window of its own, and said out loud.
   *
   * The installed app has no Back button: followed in place, this link puts a
   * member inside webtrees with no way home. The name carries the warning
   * because a link that opens a new window without saying so is the standard
   * complaint about `target="_blank"`, and `rel` keeps the new page from
   * getting a handle on this one.
   */
  it('opens webtrees in a window of its own, and says so', async () => {
    stub('editor')
    renderMe()

    const link = await screen.findByRole('link', { name: /In webtrees öffnen und bearbeiten/ })

    expect(link.getAttribute('target')).toBe('_blank')
    expect(link.getAttribute('rel')).toBe('noopener noreferrer')
    expect(link.textContent).toMatch(/öffnet in einem neuen Fenster/)
  })

  /** The member's own link is the same link, and gets the same treatment. */
  it('does the same for the link a member gets', async () => {
    stub('member')
    renderMe()

    const link = await screen.findByRole('link', { name: /Stammbaum und Diagramme öffnen/ })

    expect(link.getAttribute('target')).toBe('_blank')
    expect(link.getAttribute('rel')).toBe('noopener noreferrer')
  })

  it.each<Role>(['moderator', 'manager', 'administrator'])(
    'offers it to a %s as well',
    async (role) => {
      stub(role)
      renderMe()

      expect(
        await screen.findByRole('link', { name: /In webtrees öffnen und bearbeiten/ }),
      ).toBeDefined()
    },
  )

  it('does not point a member at an editing screen', async () => {
    stub('member')
    renderMe()

    // The member's own link is still there — this is about wording and
    // destination, not about taking anything away.
    expect(await screen.findByRole('link', { name: /Stammbaum und Diagramme öffnen/ })).toBeDefined()
    expect(screen.queryByRole('link', { name: /In webtrees öffnen und bearbeiten/ })).toBeNull()
  })

  /** The same address twice, worded differently, is a question nobody needs. */
  it('gives an editor one link rather than two', async () => {
    stub('manager')
    renderMe()

    await screen.findByRole('link', { name: /In webtrees öffnen und bearbeiten/ })

    expect(screen.queryByRole('link', { name: /Stammbaum und Diagramme öffnen/ })).toBeNull()
  })

  /** An editor is told why they see something the rest of the family does not. */
  it('says that the link is there because of the role', async () => {
    stub('editor')
    renderMe()

    expect(await screen.findByText(/weil du den Stammbaum bearbeiten darfst/)).toBeDefined()
  })
})
