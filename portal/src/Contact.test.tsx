import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import type { ContactSettings, MemberDetail } from './api/types'
import './i18n'

/**
 * Phase 9: contact details, and writing to another member.
 *
 * The server decides who may see what, and these tests do not re-check that —
 * `module/tests/ContactTest.php` does. What is pinned here is the part only
 * the client can get wrong: that a member is told their address travels with
 * a message *before* they send it, that clearing a field is sent as the
 * withdrawal it is, and that a detail which did not reach this reader leaves
 * no trace on the screen.
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

const CONTACT: ContactSettings = {
  enabled: true,
  contact: { phone: { value: '0511 12345', audience: 'members' } },
}

const MEMBER: MemberDetail = {
  id: 7,
  display_name: 'Dieter Beispiel',
  individual: null,
  individual_detail: null,
  contact: { phone: '0511 12345' },
  can_message: true,
}

function stub(options: {
  contact?: ContactSettings
  member?: Partial<MemberDetail>
  message?: () => Response
} = {}) {
  const contact = options.contact ?? CONTACT
  const member = { ...MEMBER, ...options.member }

  const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
    const url = String(input)
    const method = init?.method ?? 'GET'

    if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })
    if (url.includes('/message')) return options.message?.() ?? jsonResponse({ status: 'sent' }, 202)
    if (url.endsWith('/me/contact')) {
      if (method === 'PATCH') {
        const sent = JSON.parse(String(init?.body)) as { contact: ContactSettings['contact'] }
        return jsonResponse({ enabled: true, contact: sent.contact })
      }
      return jsonResponse(contact)
    }
    if (url.includes('/members/')) return jsonResponse(member)
    if (url.endsWith('/invitations')) {
      return jsonResponse({ enabled: false, linked: true, quota: 0, remaining: 0, candidates: [], invitations: [] })
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

describe('my own contact details', () => {
  it('offers an audience for every kind, not one for all of them', async () => {
    stub()
    renderAt('/settings')

    // Three kinds, each with its own set of choices.
    expect(await screen.findByLabelText('Telefonnummer')).toBeDefined()
    expect(screen.getByLabelText('E-Mail-Adresse')).toBeDefined()
    expect(screen.getByLabelText('Anschrift')).toBeDefined()

    expect(screen.getAllByRole('radio', { name: 'Nur meine enge Familie' })).toHaveLength(3)
  })

  it('shows what the member already shares', async () => {
    stub()
    renderAt('/settings')

    expect(await screen.findByDisplayValue('0511 12345')).toBeDefined()
  })

  /**
   * Clearing the field *is* the withdrawal, so the empty value has to reach
   * the server. Sending only the fields that still have content would leave
   * the old row in place — the member would think they had deleted it.
   */
  it('sends a cleared field so that the row is deleted', async () => {
    const fetchMock = stub()
    renderAt('/settings')

    await userEvent.setup().clear(await screen.findByLabelText('Telefonnummer'))
    await userEvent.setup().click(screen.getByRole('button', { name: 'Kontaktdaten speichern' }))

    await waitFor(() => {
      const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')

      expect(patch).toBeDefined()
      expect(JSON.parse(String(patch?.[1]?.body))).toMatchObject({
        contact: { phone: { value: '' } },
      })
    })
  })

  it('says so when the family has switched contact details off', async () => {
    stub({ contact: { enabled: false, contact: {} } })
    renderAt('/settings')

    expect(await screen.findByText('Kontaktdaten sind ausgeschaltet')).toBeDefined()
    expect(screen.queryByLabelText('Telefonnummer')).toBeNull()
  })
})

describe('another member', () => {
  it('shows what reached me, as something I can act on', async () => {
    stub()
    renderAt('/members/7')

    const link = await screen.findByRole('link', { name: '0511 12345' })

    expect(link.getAttribute('href')).toBe('tel:051112345')
  })

  /**
   * Absent means absent. Nothing on the screen may hint that there is a
   * detail this reader did not receive.
   */
  /**
   * `contact` and `can_message` both arrived in Phase 9. A response without
   * them — an older module, or a payload that lost them on the way — must
   * leave the page working rather than white-screening.
   */
  it('survives a server that does not send contact details yet', async () => {
    const { contact, can_message, ...older } = MEMBER

    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockImplementation(async (input) => {
        const url = String(input)

        if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })
        if (url.includes('/members/')) return jsonResponse(older)

        return jsonResponse(ME)
      }),
    )

    renderAt('/members/7')

    expect(await screen.findByRole('heading', { name: 'Dieter Beispiel' })).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Nachricht senden' })).toBeNull()
  })

  it('leaves no trace of a detail that did not reach me', async () => {
    stub({ member: { contact: {} } })
    renderAt('/members/7')

    await screen.findByRole('heading', { name: 'Dieter Beispiel' })

    expect(screen.queryByText('Kontakt')).toBeNull()
    expect(screen.queryByText('Telefonnummer')).toBeNull()
  })
})

describe('writing to another member', () => {
  /**
   * The single most important assertion in this file. webtrees sends the
   * message with the sender's own address as the reply address, and there is
   * no way to allow a reply without it — so the member has to be told before
   * they press the button, not after.
   */
  it('warns that my address travels with the message, before I send it', async () => {
    stub()
    renderAt('/members/7')

    expect(await screen.findByText(/Ihre E-Mail-Adresse als Absenderadresse mitgeschickt/)).toBeDefined()
  })

  it('sends the message and says so', async () => {
    stub()
    renderAt('/members/7')

    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Betreff'), 'Hallo')
    await user.type(screen.getByLabelText('Ihre Nachricht'), 'Wie geht es dir?')
    await user.click(screen.getByRole('button', { name: 'Nachricht senden' }))

    expect(await screen.findByText('Ihre Nachricht ist unterwegs.')).toBeDefined()
  })

  it('will not send an empty message', async () => {
    const fetchMock = stub()
    renderAt('/members/7')

    const send = await screen.findByRole('button', { name: 'Nachricht senden' })

    expect(send.hasAttribute('disabled')).toBe(true)

    await waitFor(() => {
      expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/message'))).toBe(false)
    })
  })

  it('explains a refusal rather than failing silently', async () => {
    stub({ message: () => jsonResponse({ error: 'quota_reached', message: 'Too many.' }, 409) })
    renderAt('/members/7')

    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Betreff'), 'Hallo')
    await user.type(screen.getByLabelText('Ihre Nachricht'), 'Wie geht es dir?')
    await user.click(screen.getByRole('button', { name: 'Nachricht senden' }))

    expect(await screen.findByRole('alert')).toBeDefined()
  })

  it('offers no form when the family has switched messages off', async () => {
    stub({ member: { can_message: false } })
    renderAt('/members/7')

    await screen.findByRole('heading', { name: 'Dieter Beispiel' })

    expect(screen.queryByRole('button', { name: 'Nachricht senden' })).toBeNull()
  })
})
