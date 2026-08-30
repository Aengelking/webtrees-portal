import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import './i18n'

/**
 * The question about the member directory, asked until it is answered.
 *
 * The switch has always been in the settings, off by default, and nothing ever
 * asked — so the directory stayed empty and the silence was read as "no",
 * when it only ever meant "nobody was asked". The card puts the question on
 * the screen every member lands on.
 *
 * The two things worth pinning are the ones a device-local "already asked"
 * flag would get wrong, and they are both about *whose* fact this is: the card
 * is shown on the server's word, and both answers — including the one that
 * changes nothing — are sent to the server. See `Migration20`.
 */

const ANNA = {
  xref: 'X1',
  name: 'Anna Beispiel',
  sex: 'F',
  is_deceased: false,
  lifespan: '1985–',
  portrait: null,
  name_alternative: null,
  relationship: null,
  references: [],
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

function jsonResponse(body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

const patched: Record<string, unknown>[] = []

/**
 * @param profile what the server says about this member's profile — `null`
 *   for an account that has no `portal_member_profile` row at all.
 */
function stub(profile: Record<string, unknown> | null) {
  patched.length = 0

  let current = profile

  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)

      if (url.endsWith('/csrf')) {
        return jsonResponse({ csrf_token: 'token-1' })
      }

      if (url.endsWith('/me/profile')) {
        const body = JSON.parse(String(init?.body)) as Record<string, unknown>

        patched.push(body)

        // What the server does with it: the answer is recorded, and from now
        // on the question is answered whatever the answer was.
        current = {
          id: 1,
          visible_in_directory: body.visible_in_directory === true,
          display_name_override: null,
          consent_recorded_at: body.visible_in_directory === true ? '2026-08-30 10:00:00' : null,
          directory_decided: true,
        }

        return jsonResponse(current)
      }

      return jsonResponse({
        user: {
          id: 1,
          username: 'anna',
          real_name: 'Anna Beispiel',
          email: 'anna@example.test',
          language: 'de',
          role: 'member',
        },
        profile: current,
        individual: ANNA,
        tree: { name: 'portal', title: 'Familie Beispiel' },
        csrf_token: 'token-1',
      })
    }),
  )
}

const UNDECIDED = {
  id: 1,
  visible_in_directory: false,
  display_name_override: null,
  consent_recorded_at: null,
  directory_decided: false,
}

function renderProfile() {
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

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('asking about the member directory', () => {
  it('asks a member who has never answered', async () => {
    stub(UNDECIDED)
    renderProfile()

    expect(await screen.findByText('Im Mitgliederverzeichnis erscheinen?')).toBeDefined()
    expect(screen.getByRole('button', { name: 'Ja, anzeigen' })).toBeDefined()
    expect(screen.getByRole('button', { name: 'Nein, nicht anzeigen' })).toBeDefined()
  })

  /**
   * The member who has been in the portal for months and has an answer on
   * record. This is the case a flag in this browser's storage would get wrong
   * on their next telephone.
   */
  it('does not ask a member who has answered', async () => {
    stub({ ...UNDECIDED, directory_decided: true })
    renderProfile()

    // The profile screen has arrived …
    expect(await screen.findByRole('heading', { name: 'Mein Profil' })).toBeDefined()
    // … and the card is not on it.
    expect(screen.queryByText('Im Mitgliederverzeichnis erscheinen?')).toBeNull()
  })

  /**
   * An account that has never touched a setting has no profile row at all.
   * That is the emptiest possible "never answered", not a reason to skip it.
   */
  it('asks an account that has no profile at all', async () => {
    stub(null)
    renderProfile()

    expect(await screen.findByText('Im Mitgliederverzeichnis erscheinen?')).toBeDefined()
  })

  it('lists the member who says yes, and stops asking', async () => {
    stub(UNDECIDED)
    renderProfile()

    await userEvent.click(await screen.findByRole('button', { name: 'Ja, anzeigen' }))

    expect(patched).toEqual([{ visible_in_directory: true }])
    expect(screen.queryByText('Im Mitgliederverzeichnis erscheinen?')).toBeNull()
  })

  /**
   * The half that would not exist without a column for it. "No thank you"
   * leaves the member exactly as unlisted as they already were — so unless it
   * is *sent*, nothing has changed and the card comes back tomorrow.
   */
  it('sends the refusal too, rather than only remembering it here', async () => {
    stub(UNDECIDED)
    renderProfile()

    await userEvent.click(await screen.findByRole('button', { name: 'Nein, nicht anzeigen' }))

    expect(patched).toEqual([{ visible_in_directory: false }])
    expect(screen.queryByText('Im Mitgliederverzeichnis erscheinen?')).toBeNull()
  })

  /** No third button: a question that can be postponed is one that is. */
  it('offers no way to put the question off', async () => {
    stub(UNDECIDED)
    renderProfile()

    await screen.findByText('Im Mitgliederverzeichnis erscheinen?')

    expect(screen.queryByRole('button', { name: /Später|Nicht jetzt/ })).toBeNull()
  })
})
