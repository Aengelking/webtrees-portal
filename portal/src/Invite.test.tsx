import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
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

/**
 * `invitable` is the record's own, and it is the server's answer rather than
 * anything this screen works out.
 *
 * It moved there when an editor's candidate list became the whole archive:
 * the person's page cannot hold thousands of records to answer one question
 * about one of them. Which of the reasons made it `false` — dead, already an
 * account holder, already invited, too distant, no quota left — is
 * deliberately not distinguishable, here or anywhere.
 */
function stub(
  overrides: Partial<InvitationOverview> = {},
  post?: () => Response,
  invitable = true,
) {
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
        invitable,
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
  /**
   * One line per person rather than a card each: the list used to push the
   * button, and with it the link the button produces, off the bottom of a
   * phone. What the line has to keep is the relationship — "Ihr Bruder" is
   * what makes it obvious that the right person is about to be picked.
   */
  it('names the relationship and the years on one line', async () => {
    stub()
    renderInvite()

    const chooser = await screen.findByLabelText('Person auswählen')

    expect(within(chooser).getByRole('option', { name: 'Ihr Bruder — Dieter Beispiel (1990–)' })).toBeDefined()

    // Nobody is chosen until somebody chooses.
    expect((chooser as HTMLSelectElement).value).toBe('')
  })

  it('creates the invitation and shows the link', async () => {
    stub()
    renderInvite()

    const user = userEvent.setup()
    await user.selectOptions(await screen.findByLabelText('Person auswählen'), 'X4')
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
    await user.selectOptions(await screen.findByLabelText('Person auswählen'), 'X4')
    await user.click(screen.getByRole('button', { name: 'Einladung erstellen' }))

    expect((await screen.findByRole('status')).textContent).toMatch(/nur dieses eine Mal/)
  })

  it('refuses to send anything when nobody has been picked', async () => {
    const fetchMock = stub()
    renderInvite()

    await screen.findByLabelText('Person auswählen')
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
    await user.selectOptions(await screen.findByLabelText('Person auswählen'), 'X4')
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
 * The link is handed over once — the server keeps a hash — so what is on the
 * screen is the only copy of it that will ever exist. A member selecting that
 * by hand on a phone is how half a URL ends up in a chat and an invitation has
 * to be withdrawn and re-issued.
 */
describe('handing the invitation link over', () => {
  /**
   * `userEvent.setup()` installs a clipboard stub of its own, which quietly
   * replaces the one under test and makes every clipboard assertion here
   * meaningless. The direct calls leave the browser's globals alone.
   */
  async function issue() {
    await userEvent.selectOptions(await screen.findByLabelText('Person auswählen'), 'X4')
    await userEvent.click(screen.getByRole('button', { name: 'Einladung erstellen' }))
  }

  it('shares the link where the browser has a share sheet', async () => {
    const share = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { ...navigator, share })

    stub()
    renderInvite()

    await issue()
    await userEvent.click(await screen.findByRole('button', { name: 'Teilen' }))

    expect(share).toHaveBeenCalledWith(
      expect.objectContaining({ url: 'https://portal.example.test/invitation?token=geheim' }),
    )

    vi.unstubAllGlobals()
  })

  /** Cancelling a share sheet rejects. It is an answer, not a failure. */
  it('says nothing when the share sheet is dismissed', async () => {
    vi.stubGlobal('navigator', {
      ...navigator,
      share: vi.fn().mockRejectedValue(new Error('AbortError')),
    })

    stub()
    renderInvite()

    await issue()
    await userEvent.click(await screen.findByRole('button', { name: 'Teilen' }))

    await waitFor(() => {
      expect(screen.queryByRole('alert')).toBeNull()
    })

    // And the link is still on screen to try again with.
    expect(screen.getByLabelText('Einladungslink')).toBeDefined()

    vi.unstubAllGlobals()
  })

  /**
   * Most desktops have no share sheet, and that is where a member sits when
   * they write the e-mail. Copying is offered always rather than as the
   * fallback nobody sees.
   */
  it('copies on a browser with no share sheet at all', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { ...navigator, share: undefined, clipboard: { writeText } })

    stub()
    renderInvite()

    await issue()

    expect(screen.queryByRole('button', { name: 'Teilen' })).toBeNull()

    await userEvent.click(await screen.findByRole('button', { name: 'Kopieren' }))

    expect(writeText).toHaveBeenCalledWith('https://portal.example.test/invitation?token=geheim')
    expect(await screen.findByText(/Der Link ist kopiert/)).toBeDefined()

    vi.unstubAllGlobals()
  })

  /** A browser that refuses the clipboard has taken nothing away: the link is on screen. */
  it('claims nothing when the clipboard is refused', async () => {
    vi.stubGlobal('navigator', {
      ...navigator,
      share: undefined,
      clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) },
    })

    stub()
    renderInvite()

    await issue()
    await userEvent.click(await screen.findByRole('button', { name: 'Kopieren' }))

    await waitFor(() => {
      expect(screen.queryByText(/Der Link ist kopiert/)).toBeNull()
    })

    expect(screen.getByLabelText('Einladungslink')).toBeDefined()

    vi.unstubAllGlobals()
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
   * account holder, already invited, too distant and no quota left are one
   * answer — the server gives it, and a button here must not undo it by being
   * present for exactly one of the five.
   */
  it('says nothing at all where the server makes no offer', async () => {
    stub({}, undefined, false)
    renderAt('/individuals/X4')

    // The person is on screen; the offer is not.
    expect(await screen.findByText(/Ihr Bruder/)).toBeDefined()
    expect(screen.queryByText('Noch nicht im Portal')).toBeNull()
    expect(screen.queryByRole('link', { name: 'Einladen' })).toBeNull()
  })

  /**
   * The module and the portal deploy separately, so a server that predates the
   * field simply makes no offer — rather than making one it would refuse.
   */
  it('makes no offer where the server does not answer the question', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) => {
        const url = String(input)

        if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

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
      }),
    )

    renderAt('/individuals/X4')

    expect(await screen.findByText(/Ihr Bruder/)).toBeDefined()
    expect(screen.queryByRole('link', { name: 'Einladen' })).toBeNull()
  })

  /**
   * And the person's page asks nobody but the record. It used to fetch the
   * whole candidate list to find out; with an editor that list is the archive.
   */
  it('asks for the record and nothing else', async () => {
    const fetchMock = stub()
    renderAt('/individuals/X4')

    await screen.findByText('Noch nicht im Portal')

    expect(
      fetchMock.mock.calls.some(([input]) => String(input).includes('/invitations')),
    ).toBe(false)
  })

  it('arrives on the invite screen with that person already chosen', async () => {
    stub()
    renderAt('/invite?xref=X4')

    const chooser = await screen.findByLabelText('Person auswählen')

    expect((chooser as HTMLSelectElement).value).toBe('X4')
  })

  /** A URL is not an authority on who may be invited; the server re-checks anyway. */
  it('chooses nobody when the address bar names somebody who is not on the list', async () => {
    stub()
    renderAt('/invite?xref=X999')

    const chooser = await screen.findByLabelText('Person auswählen')

    expect((chooser as HTMLSelectElement).value).toBe('')
  })
})

/**
 * Whom an editor may invite is everybody they can see, which is thousands of
 * people. A wheel with thousands of names in it is not a way of choosing one,
 * so the screen changes shape: the archive's own search, over the endpoint
 * that already shows an editor the living.
 */
describe('inviting anybody, as somebody who keeps the tree', () => {
  const FRITZ = {
    xref: 'X6',
    name: 'Fritz Beispiel',
    sex: 'M',
    is_deceased: false,
    lifespan: '1992–',
    portrait: null,
    references: [{ number: '4716', type: 'SB' }],
    relationship: null,
  }

  function stubEditor() {
    return vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)
      const method = init?.method ?? 'GET'

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

      if (url.includes('/search')) {
        return jsonResponse({
          items: url.includes('q=Fritz') ? [FRITZ] : [],
          total: url.includes('q=Fritz') ? 1 : 0,
          page: 1,
          per_page: 25,
          truncated: false,
        })
      }

      if (url.includes('/invitations')) {
        if (method === 'POST') {
          return jsonResponse(
            {
              link: 'https://portal.example.test/invitation?token=geheim',
              invitation: {
                id: 9,
                name: 'Fritz Beispiel',
                email: null,
                expires_at: '2026-09-01T12:00:00+00:00',
              },
            },
            201,
          )
        }

        return jsonResponse({ ...OVERVIEW, scope: 'anyone', candidates: [], remaining: 200 })
      }

      return jsonResponse(ME)
    })
  }

  /**
   * An empty candidate list used to mean "nobody to invite". For an editor it
   * means "type a name" — the screen must not put a full stop where a search
   * belongs.
   */
  it('offers a search rather than saying there is nobody', async () => {
    vi.stubGlobal('fetch', stubEditor())
    renderInvite()

    expect(await screen.findByLabelText('Person suchen')).toBeDefined()
    expect(screen.queryByText(/Im Moment gibt es niemanden/)).toBeNull()
    expect(screen.queryByLabelText('Person auswählen')).toBeNull()
  })

  it('finds somebody a member could never reach, and invites them', async () => {
    const fetchMock = stubEditor()
    vi.stubGlobal('fetch', fetchMock)
    renderInvite()

    await userEvent.type(await screen.findByLabelText('Person suchen'), 'Fritz')

    const found = await screen.findByRole('button', { name: /Fritz Beispiel/ })

    expect(found.textContent).toContain('SB 4716')

    await userEvent.click(found)

    expect(screen.getByText(/Ausgewählt: Fritz Beispiel/)).toBeDefined()

    await userEvent.type(screen.getByLabelText(/E-Mail/), 'fritz@example.test')
    await userEvent.click(screen.getByRole('button', { name: 'Einladung erstellen' }))

    await waitFor(() => {
      const posted = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')

      expect(posted).toBeDefined()
      expect(String(posted?.[1]?.body)).toContain('X6')
    })
  })

  it('says so when the search finds nobody', async () => {
    vi.stubGlobal('fetch', stubEditor())
    renderInvite()

    await userEvent.type(await screen.findByLabelText('Person suchen'), 'Zzz')

    expect(await screen.findByText(/Für „Zzz" wurde niemand gefunden/)).toBeDefined()
  })

  /** A member keeps the wheel: a handful of names is what it is good at. */
  it('leaves the member’s screen exactly as it was', async () => {
    stub()
    renderInvite()

    expect(await screen.findByLabelText('Person auswählen')).toBeDefined()
    expect(screen.queryByLabelText('Person suchen')).toBeNull()
  })
})

/**
 * The way in, which is now on two screens rather than one.
 *
 * Settings is where a member goes to change something about themselves, and
 * that is not the frame of mind in which anybody thinks "my brother is not in
 * here". Mein Profil is: it is the screen the app opens on, the one with their
 * own family on it, and the one they are looking at when they notice who is
 * missing.
 */
describe('the standing offer to invite somebody', () => {
  it('is on Mein Profil', async () => {
    stub()
    renderAt('/me')

    await screen.findByRole('heading', { name: 'Mein Profil' })

    expect(screen.getByRole('link', { name: 'Jemanden einladen' })).toBeDefined()
  })

  /**
   * `ME` has no linked record, which is exactly the account this had to be
   * checked on: the record is what the rest of the screen is made of, and the
   * offer must not have been hung off it. A member with no record of their own
   * is if anything the likeliest to want somebody else brought in.
   */
  it('is there even when the account has no record of its own', async () => {
    stub()
    renderAt('/me')

    // The screen says there is no record — and still offers the invitation.
    expect(await screen.findByText('Ihr Eintrag im Stammbaum fehlt noch')).toBeDefined()
    expect(screen.getByRole('link', { name: 'Jemanden einladen' })).toBeDefined()
  })

  it('is still on Einstellungen', async () => {
    stub()
    renderAt('/settings')

    expect(await screen.findByRole('link', { name: 'Jemanden einladen' })).toBeDefined()
  })

  it('leads to the invitation screen', async () => {
    stub()
    renderAt('/me')

    await screen.findByRole('heading', { name: 'Mein Profil' })
    await userEvent.setup().click(screen.getByRole('link', { name: 'Jemanden einladen' }))

    expect(await screen.findByLabelText('Person auswählen')).toBeDefined()
  })
})
