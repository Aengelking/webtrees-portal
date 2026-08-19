import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import type { InvitationOverview } from './api/types'
import './i18n'

/**
 * Phase 7: a member invites their own close family.
 *
 * The API has already decided who may be invited — the screen cannot widen
 * that and does not try. What is worth pinning here is the part the server
 * cannot do: that the member is told plainly the link is shown once, that a
 * refusal is explained rather than swallowed, and that the three states in
 * which nobody can be invited each say which one it is.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const ME = {
  user: { id: 1, username: 'anna', real_name: 'Anna Beispiel', email: 'a@b.test', language: 'de', role: 'member' },
  profile: { id: 1, visible_in_directory: true, display_name_override: null, consent_recorded_at: null },
  individual: null,
  tree: { name: 'portal', title: 'Familie Beispiel' },
  csrf_token: 'token-1',
}

const OVERVIEW: InvitationOverview = {
  enabled: true,
  linked: true,
  quota: 3,
  remaining: 3,
  candidates: [
    {
      xref: 'X4',
      name: 'Dieter Beispiel',
      sex: 'M',
      is_deceased: false,
      lifespan: '1990–',
      portrait: null,
      relationship: 'Ihr Bruder',
    },
  ],
  invitations: [],
}

function stub(overrides: Partial<InvitationOverview> = {}, post?: () => Response) {
  const overview = { ...OVERVIEW, ...overrides }

  const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
    const url = String(input)
    const method = init?.method ?? 'GET'

    if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

    if (url.includes('/invitations')) {
      if (method === 'POST') {
        return post?.() ?? jsonResponse(
          {
            link: 'https://portal.example.test/invitation?token=geheim',
            invitation: { id: 9, name: 'Dieter Beispiel', email: null, expires_at: '2026-09-01T12:00:00+00:00' },
          },
          201,
        )
      }

      if (method === 'DELETE') return jsonResponse({ ...overview, invitations: [] })

      return jsonResponse(overview)
    }

    return jsonResponse(ME)
  })

  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
}

function renderInvite() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter initialEntries={['/invite']}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('inviting close family', () => {
  it('names the relationship next to each person', async () => {
    stub()
    renderInvite()

    expect(await screen.findByText('Ihr Bruder')).toBeDefined()
    expect(screen.getByText('Dieter Beispiel')).toBeDefined()
  })

  it('creates the invitation and shows the link', async () => {
    stub()
    renderInvite()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('radio', { name: /Dieter Beispiel/ }))
    await user.click(screen.getByRole('button', { name: 'Einladung erstellen' }))

    expect(await screen.findByDisplayValue('https://portal.example.test/invitation?token=geheim')).toBeDefined()
  })

  /**
   * Not a nicety. The server keeps only a hash of the token, so there is
   * genuinely nothing to show again — a member who assumes they can look it
   * up later has been misled by the interface.
   */
  it('says outright that the link will not be shown again', async () => {
    stub()
    renderInvite()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('radio', { name: /Dieter Beispiel/ }))
    await user.click(screen.getByRole('button', { name: 'Einladung erstellen' }))

    expect((await screen.findByRole('status')).textContent).toMatch(/nur dieses eine Mal/)
  })

  it('refuses to send anything when nobody has been picked', async () => {
    const fetchMock = stub()
    renderInvite()

    await screen.findByText('Dieter Beispiel')
    await userEvent.setup().click(screen.getByRole('button', { name: 'Einladung erstellen' }))

    expect((await screen.findByRole('alert')).textContent).toMatch(/Person aus/)

    await waitFor(() => {
      const posts = fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST')
      expect(posts.every(([url]) => !String(url).includes('/invitations'))).toBe(true)
    })
  })

  it('explains a refusal from the server instead of failing silently', async () => {
    stub({}, () => jsonResponse({ error: 'not_allowed', message: 'No.' }, 403))
    renderInvite()

    const user = userEvent.setup()
    await user.click(await screen.findByRole('radio', { name: /Dieter Beispiel/ }))
    await user.click(screen.getByRole('button', { name: 'Einladung erstellen' }))

    expect((await screen.findByRole('alert')).textContent).toMatch(/können Sie nicht einladen/)
  })

  it('explains the quota rather than showing a form that will be rejected', async () => {
    stub({ remaining: 0 })
    renderInvite()

    expect(await screen.findByText('Sie haben schon genug offene Einladungen')).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Einladung erstellen' })).toBeNull()
  })

  /**
   * Three different reasons the form is absent, three different sentences.
   * "Nothing here" would leave a member unable to tell "I have nobody to
   * invite" from "my account is broken".
   */
  it('tells apart nobody-to-invite, switched-off and not-linked', async () => {
    stub({ candidates: [] })
    const first = renderInvite()

    expect(await screen.findByText('Zurzeit niemand zum Einladen')).toBeDefined()
    first.unmount()

    stub({ enabled: false, candidates: [] })
    const second = renderInvite()

    expect(await screen.findByText('Einladungen durch Mitglieder sind ausgeschaltet')).toBeDefined()
    second.unmount()

    stub({ linked: false, candidates: [] })
    renderInvite()

    expect(await screen.findByText('Ihr Konto ist noch nicht verknüpft')).toBeDefined()
  })

  it('lists an outstanding invitation and can withdraw it', async () => {
    const fetchMock = stub({
      invitations: [
        { id: 9, name: 'Dieter Beispiel', email: 'dieter@example.test', expires_at: '2026-09-01T12:00:00+00:00' },
      ],
    })

    renderInvite()

    expect(await screen.findByText('dieter@example.test')).toBeDefined()

    await userEvent.setup().click(screen.getByRole('button', { name: 'Zurücknehmen' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          ([url, init]) => String(url).includes('/invitations/9') && init?.method === 'DELETE',
        ),
      ).toBe(true)
    })
  })
})
