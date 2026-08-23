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
    if (url.includes('/conversations')) {
      const conversation = { id: 3, member_id: 7, name: 'Dieter Beispiel', unread: 0, last_message: null }

      // Opening answers with the conversation; the screen it lands on then
      // asks for the transcript, which is a different shape.
      return (
        options.message?.() ??
        (method === 'POST'
          ? jsonResponse({ conversation })
          : jsonResponse({ conversation, messages: [], before: null }))
      )
    }

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

/**
 * The settings screen reads before it writes: what a member shares is on the
 * screen, and the form that changes it is behind a button. Every test that is
 * about the form has to open it first.
 */
async function openTheForm(): Promise<void> {
  await userEvent.setup().click(await screen.findByRole('button', { name: 'Kontaktdaten ändern' }))
}

describe('my own contact details', () => {
  /**
   * The commoner errand is looking, not changing — so looking is what the
   * screen answers first, in sentences rather than in form controls.
   */
  it('shows what is shared without opening anything', async () => {
    stub()
    renderAt('/settings')

    expect(await screen.findByText('0511 12345')).toBeDefined()
    expect(screen.getByText('Sichtbar für: Alle Mitglieder im Portal')).toBeDefined()

    // The machinery is away until it is asked for.
    expect(screen.queryByLabelText('Telefonnummer')).toBeNull()
    expect(screen.queryByRole('radio', { name: 'Nur meine enge Familie' })).toBeNull()
  })

  /**
   * An entry nobody has filled in is listed too, and says so. Leaving it out
   * would make the list look complete when it is not.
   */
  it('says which entries are not given', async () => {
    stub()
    renderAt('/settings')

    await screen.findByText('0511 12345')

    expect(screen.getAllByText('Nicht angegeben')).toHaveLength(2)
  })

  it('offers an audience for every kind, not one for all of them', async () => {
    stub()
    renderAt('/settings')
    await openTheForm()

    // Three kinds, each with its own set of choices.
    expect(await screen.findByLabelText('Telefonnummer')).toBeDefined()
    expect(screen.getByLabelText('E-Mail-Adresse')).toBeDefined()
    expect(screen.getByLabelText('Straße und Hausnummer')).toBeDefined()

    expect(screen.getAllByRole('radio', { name: 'Nur meine enge Familie' })).toHaveLength(3)
  })

  it('shows what the member already shares', async () => {
    stub()
    renderAt('/settings')
    await openTheForm()

    expect(await screen.findByDisplayValue('0511 12345')).toBeDefined()
  })

  /** Nothing typed and abandoned may be waiting the next time it is opened. */
  it('forgets a change that was abandoned', async () => {
    stub()
    renderAt('/settings')
    await openTheForm()

    const user = userEvent.setup()

    await user.clear(await screen.findByLabelText('Telefonnummer'))
    await user.type(screen.getByLabelText('Telefonnummer'), '0511 99999')
    await user.click(screen.getByRole('button', { name: 'Abbrechen' }))

    // The summary still says what the server says.
    expect(await screen.findByText('0511 12345')).toBeDefined()

    await openTheForm()

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
    await openTheForm()

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

  /**
   * The audience a member built themselves is offered only where it means
   * something. Offering "only my contacts" in a family that has switched
   * connections off would be a form telling somebody an untruth.
   */
  it('offers the contacts audience only where connections exist', async () => {
    stub({ contact: { ...CONTACT, connections_enabled: true } })
    renderAt('/settings')
    await openTheForm()

    expect(await screen.findAllByRole('radio', { name: 'Nur meine Kontakte' })).toHaveLength(3)
  })

  it('drops the contacts audience when the family switched connections off', async () => {
    stub({ contact: { ...CONTACT, connections_enabled: false } })
    renderAt('/settings')
    await openTheForm()

    await screen.findByLabelText('Telefonnummer')

    expect(screen.queryByRole('radio', { name: 'Nur meine Kontakte' })).toBeNull()
    expect(screen.getAllByRole('radio', { name: 'Nur meine enge Familie' })).toHaveLength(3)
  })

  /**
   * An address is four answers, not one line — and the browser's own autofill
   * can only help when each field says what it is.
   */
  it('puts an address into its fields', async () => {
    stub({
      contact: {
        enabled: true,
        contact: {
          address: {
            value: 'Musterstraße 12\n29223 Celle\nDeutschland',
            parts: { street: 'Musterstraße 12', postcode: '29223', city: 'Celle', country: 'Deutschland' },
            audience: 'close_family',
          },
        },
      },
    })
    renderAt('/settings')
    await openTheForm()

    expect((await screen.findByLabelText('Straße und Hausnummer') as HTMLInputElement).value).toBe('Musterstraße 12')
    expect((screen.getByLabelText('Postleitzahl') as HTMLInputElement).value).toBe('29223')
    expect((screen.getByLabelText('Ort') as HTMLInputElement).value).toBe('Celle')
    expect((screen.getByLabelText('Land') as HTMLInputElement).value).toBe('Deutschland')
  })

  /**
   * Both shapes go up: the fields for a server that has them, and the address
   * as text for one that predates them. The module ships over SFTP and the
   * portal through CI, so a portal one deployment ahead must not silently
   * empty everybody's address.
   */
  it('sends an address as fields and as text', async () => {
    const fetchMock = stub()
    renderAt('/settings')
    await openTheForm()

    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Straße und Hausnummer'), 'Musterstraße 12')
    await user.type(screen.getByLabelText('Postleitzahl'), '29223')
    await user.type(screen.getByLabelText('Ort'), 'Celle')
    await user.click(screen.getByRole('button', { name: 'Kontaktdaten speichern' }))

    await waitFor(() => {
      const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')

      expect(patch).toBeDefined()
      expect(JSON.parse(String(patch?.[1]?.body))).toMatchObject({
        contact: {
          address: {
            parts: { street: 'Musterstraße 12', postcode: '29223', city: 'Celle', country: '' },
            // An empty field takes its line with it rather than leaving a gap.
            value: 'Musterstraße 12\n29223 Celle',
          },
        },
      })
    })
  })

  /**
   * A server that only sends text. The whole of it goes in the street, which
   * is the one place it cannot be wrong: nothing is lost, and the member's
   * first save puts each piece where it belongs.
   */
  it('survives a server that has no address fields yet', async () => {
    stub({
      contact: {
        enabled: true,
        contact: { address: { value: 'Musterweg 1\n29223 Celle', audience: 'members' } },
      },
    })
    renderAt('/settings')
    await openTheForm()

    expect((await screen.findByLabelText('Straße und Hausnummer') as HTMLInputElement).value).toBe(
      'Musterweg 1, 29223 Celle',
    )
  })

  it('says so when the family has switched contact details off', async () => {
    stub({ contact: { enabled: false, contact: {} } })
    renderAt('/settings')

    expect(await screen.findByText('Kontaktdaten sind ausgeschaltet')).toBeDefined()
    expect(screen.queryByLabelText('Telefonnummer')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Kontaktdaten ändern' })).toBeNull()
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
   * This assertion used to read the other way round: webtrees delivered the
   * announcement with the sender's own address as the reply address, and an
   * unavoidable disclosure had to be said out loud. It is not unavoidable any
   * more — the announcement carries no text, no name and no reply address — so
   * what has to be said out loud is what the other person actually gets.
   */
  it('says what the other person will be told, before I write anything', async () => {
    stub()
    renderAt('/members/7')

    expect(await screen.findByText(/nur, dass eine Nachricht im Portal wartet/)).toBeDefined()
    expect(screen.getByText(/Ihre E-Mail-Adresse wird nicht mitgeschickt/)).toBeDefined()
  })

  /**
   * Writing used to be a form here that sent one message and kept no copy.
   * It now opens a conversation, which is the step the directory rule guards —
   * so this asks the server for one and goes to it.
   */
  it('opens the conversation and goes there', async () => {
    const fetchMock = stub()
    renderAt('/members/7')

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Nachricht schreiben' }))

    await waitFor(() => {
      expect(
        fetchMock.mock.calls.some(
          ([url, init]) => String(url).includes('/conversations') && init?.method === 'POST',
        ),
      ).toBe(true)
    })
  })

  it('explains a refusal rather than failing silently', async () => {
    stub({ message: () => jsonResponse({ error: 'quota_reached', message: 'Too many.' }, 409) })
    renderAt('/members/7')

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Nachricht schreiben' }))

    expect(await screen.findByRole('alert')).toBeDefined()
  })

  it('offers no way in when the family has switched messages off', async () => {
    stub({ member: { can_message: false } })
    renderAt('/members/7')

    await screen.findByRole('heading', { name: 'Dieter Beispiel' })

    expect(screen.queryByRole('button', { name: 'Nachricht schreiben' })).toBeNull()
  })
})
