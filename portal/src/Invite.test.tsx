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

    if (url.includes('/individuals/X4')) {
      return jsonResponse({
        xref: 'X4',
        name: 'Dieter Beispiel',
        sex: 'M',
        is_deceased: false,
        lifespan: '1990–',
        portrait: null,
        name_alternative: null,
        relationship: 'Ihr Bruder',
        birth: null,
        death: null,
        events: [],
        parents: [],
        siblings: [],
        spouses: [],
        children: [],
        pending_change: false,
        webtrees_url: 'https://tree.example.test/X4',
      })
    }

    return jsonResponse(ME)
  })

  vi.stubGlobal('fetch', fetchMock)

  return fetchMock
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

function renderInvite() {
  return renderAt('/invite')
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

/**
 * Walking the tree is how a member finds out that their brother is not in the
 * portal. Until now the way to act on that was to remember the invite screen
 * exists and find him again on it — §2.38's lesson, one screen over.
 */
describe('inviting from the person’s own page', () => {
  it('offers an invitation for somebody who may be invited', async () => {
    stub()
    renderAt('/individuals/X4')

    expect(await screen.findByText('Noch nicht im Portal')).toBeDefined()
    expect(screen.getByText(/Dieter Beispiel hat noch keinen Zugang/)).toBeDefined()

    const invite = screen.getByRole('link', { name: 'Einladen' })

    expect(invite.getAttribute('href')).toBe('/invite?xref=X4')
  })

  /**
   * The absence of the button is deliberately uninformative. Dead, already an
   * account holder, already invited and too distant are one answer — that is
   * what `GET /invitations` was built to do, and a button here must not undo
   * it by being present for exactly one of the four.
   */
  it('says nothing at all about somebody who is not a candidate', async () => {
    stub({ candidates: [] })
    renderAt('/individuals/X4')

    // The person is on screen; the offer is not.
    expect(await screen.findByText(/Ihr Bruder/)).toBeDefined()
    expect(screen.queryByText('Noch nicht im Portal')).toBeNull()
  })

  it('makes no offer where the family has switched invitations off', async () => {
    stub({ enabled: false })
    renderAt('/individuals/X4')

    expect(await screen.findByText(/Ihr Bruder/)).toBeDefined()
    expect(screen.queryByRole('link', { name: 'Einladen' })).toBeNull()
  })

  /** The quota is the server's answer, and a button that will be refused is worse than none. */
  it('makes no offer once the quota is spent', async () => {
    stub({ remaining: 0 })
    renderAt('/individuals/X4')

    expect(await screen.findByText(/Ihr Bruder/)).toBeDefined()
    expect(screen.queryByRole('link', { name: 'Einladen' })).toBeNull()
  })

  it('arrives on the invite screen with that person already chosen', async () => {
    stub()
    renderAt('/invite?xref=X4')

    const chosen = await screen.findByRole('radio', { name: /Dieter Beispiel/ })

    expect((chosen as HTMLInputElement).checked).toBe(true)
  })

  /** A URL is not an authority on who may be invited; the server re-checks anyway. */
  it('chooses nobody when the address bar names somebody who is not on the list', async () => {
    stub()
    renderAt('/invite?xref=X999')

    const offered = await screen.findByRole('radio', { name: /Dieter Beispiel/ })

    expect((offered as HTMLInputElement).checked).toBe(false)
  })
})
