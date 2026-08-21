import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import type { Inbox, Message } from './api/types'
import './i18n'

/**
 * Phase 10: the inbox.
 *
 * The server already strips webtrees' email wrapper and decides whose
 * messages these are — `module/tests/InboxTest.php` pins that. What only the
 * client can get wrong is here: that opening a message is what marks it read,
 * that the unread state is legible without relying on a coloured dot, and
 * that deleting says what it really does.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const FIRST: Message = {
  id: 9,
  from: 'Dieter Beispiel',
  subject: 'Familientreffen',
  body: 'Kommst du zum Familientreffen?',
  sent_at: '2026-08-01T10:00:00+00:00',
  read: false,
  can_reply: true,
}

const INBOX: Inbox = { unread: 1, messages: [FIRST] }

function me(unread: number) {
  return {
    user: { id: 1, username: 'anna', real_name: 'Anna Beispiel', email: 'a@b.test', language: 'de', role: 'member' },
    profile: { id: 1, visible_in_directory: true, display_name_override: null, consent_recorded_at: null },
    individual: null,
    tree: { name: 'portal', title: 'Familie Beispiel' },
    unread_messages: unread,
    csrf_token: 'token-1',
  }
}

const replied: { body: string }[] = []

function stub(inbox: Inbox = INBOX) {
  let current = inbox

  replied.length = 0

  const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
    const url = String(input)
    const method = init?.method ?? 'GET'

    if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

    if (url.includes('/reply')) {
      replied.push(JSON.parse(String(init?.body)) as { body: string })
      return jsonResponse({ status: 'sent' }, 202)
    }

    if (url.includes('/messages')) {
      if (method === 'PATCH') {
        const { read } = JSON.parse(String(init?.body)) as { read: boolean }
        current = {
          unread: read ? 0 : 1,
          messages: current.messages.map((m) => ({ ...m, read })),
        }
      }

      if (method === 'DELETE') {
        current = { unread: 0, messages: [] }
      }

      return jsonResponse(current)
    }

    return jsonResponse(me(current.unread))
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

describe('the inbox', () => {
  it('lists a message with who it is from', async () => {
    stub()
    renderAt('/messages')

    expect(await screen.findByText('Familientreffen')).toBeDefined()
    expect(screen.getByText(/Dieter Beispiel/)).toBeDefined()
  })

  it('keeps the message closed until it is opened', async () => {
    stub()
    renderAt('/messages')

    await screen.findByText('Familientreffen')

    expect(screen.queryByText('Kommst du zum Familientreffen?')).toBeNull()
  })

  /**
   * Opening a message *is* marking it read. Asking for a second deliberate
   * act to make the badge go away is the kind of thing people stop doing.
   */
  it('marks a message read by opening it', async () => {
    const fetchMock = stub()
    renderAt('/messages')

    await userEvent.setup().click(await screen.findByRole('button', { name: /Familientreffen/ }))

    expect(await screen.findByText('Kommst du zum Familientreffen?')).toBeDefined()

    await waitFor(() => {
      const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH')

      expect(patch).toBeDefined()
      expect(JSON.parse(String(patch?.[1]?.body))).toEqual({ read: true })
    })
  })

  it('does not mark an already-read message again', async () => {
    const fetchMock = stub({
      unread: 0,
      messages: [{ ...FIRST, read: true }],
    })

    renderAt('/messages')

    await userEvent.setup().click(await screen.findByRole('button', { name: /Familientreffen/ }))
    await screen.findByText('Kommst du zum Familientreffen?')

    await waitFor(() => {
      expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PATCH')).toBe(false)
    })
  })

  /**
   * The unread state is carried by a coloured dot, which is nothing at all to
   * a screen reader. The accessible name has to say it.
   */
  it('says "unread" in the accessible name, not only in a colour', async () => {
    stub()
    renderAt('/messages')

    expect(await screen.findByRole('button', { name: /ungelesen/ })).toBeDefined()
  })

  it('deletes a message and says the inbox is empty', async () => {
    stub()
    renderAt('/messages')

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: /Familientreffen/ }))
    await user.click(await screen.findByRole('button', { name: 'Löschen' }))

    expect(await screen.findByText('Keine Nachrichten')).toBeDefined()
  })

  /**
   * The portal is not a second mailbox keeping a quiet copy. Deleting here
   * deletes in webtrees, and a member should know that before pressing it.
   */
  it('says that this is the webtrees mailbox, not a copy', async () => {
    stub()
    renderAt('/messages')

    expect(await screen.findByText(/Was Sie hier löschen, ist auch dort gelöscht/)).toBeDefined()
  })

  it('explains an empty inbox rather than showing an empty list', async () => {
    stub({ unread: 0, messages: [] })
    renderAt('/messages')

    expect(await screen.findByText('Keine Nachrichten')).toBeDefined()
  })
})

describe('answering', () => {
  it('offers an answer and sends what was typed', async () => {
    stub()
    renderAt('/messages')

    await userEvent.click(await screen.findByRole('button', { name: /Familientreffen/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Antworten' }))

    await userEvent.type(screen.getByLabelText('Ihre Antwort'), 'Ja, sehr gern.')
    await userEvent.click(screen.getByRole('button', { name: 'Antwort senden' }))

    await waitFor(() => expect(replied).toEqual([{ body: 'Ja, sehr gern.' }]))
  })

  /**
   * The most important assertion in this file, for the same reason as the one
   * on the "write to a member" form: the disclosure is unavoidable, so it has
   * to be said before the button rather than after it.
   */
  it('says that the answerer’s address travels with the answer', async () => {
    stub()
    renderAt('/messages')

    await userEvent.click(await screen.findByRole('button', { name: /Familientreffen/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Antworten' }))

    expect(screen.getByText(/E-Mail-Adresse als Absenderadresse mitgeschickt/)).toBeDefined()
  })

  /** No copy is kept, so the screen says so instead of implying a sent folder. */
  it('says that no copy is kept', async () => {
    stub()
    renderAt('/messages')

    await userEvent.click(await screen.findByRole('button', { name: /Familientreffen/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Antworten' }))
    await userEvent.type(screen.getByLabelText('Ihre Antwort'), 'Danke.')
    await userEvent.click(screen.getByRole('button', { name: 'Antwort senden' }))

    expect(await screen.findByText(/Eine Kopie wird hier nicht aufbewahrt/)).toBeDefined()
  })

  /**
   * A message from a webtrees contact form, or from an account that is gone.
   * The button is not offered, and the reason is on the screen rather than
   * left as an absence the member has to interpret.
   */
  it('explains a message that cannot be answered instead of hiding the button', async () => {
    stub({ unread: 1, messages: [{ ...FIRST, can_reply: false }] })
    renderAt('/messages')

    await userEvent.click(await screen.findByRole('button', { name: /Familientreffen/ }))

    expect(screen.queryByRole('button', { name: 'Antworten' })).toBeNull()
    expect(screen.getByText(/gehört zu keinem Konto/)).toBeDefined()
  })
})

describe('the unread badge', () => {
  it('names the count for a screen reader as well as showing it', async () => {
    stub()
    renderAt('/me')

    const link = await screen.findByRole('link', { name: /ungelesen/ })

    // The visible badge is a bare digit; it must not join the name, or the
    // link reads as "1 Nachrichten — 1 ungelesen".
    expect(link.textContent).toContain('1')
    expect(link.querySelector('[aria-hidden="true"].absolute')).not.toBeNull()
  })

  it('shows nothing when everything has been read', async () => {
    stub({ unread: 0, messages: [{ ...FIRST, read: true }] })
    renderAt('/me')

    const link = await screen.findByRole('link', { name: /Nachrichten/ })

    expect(link.textContent).not.toMatch(/ungelesen/)
  })
})
