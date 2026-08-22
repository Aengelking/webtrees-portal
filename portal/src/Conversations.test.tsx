import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import type { Conversation, ConversationMessage } from './api/types'
import './i18n'

/**
 * Phase 12: the chat.
 *
 * `module/tests/ConversationTest.php` pins who may read and write what. What
 * only the client can get wrong is here: that both halves of an exchange are
 * on screen and told apart, that what a member typed is not lost when sending
 * fails, and that "delete" says which of the two copies it removes — because
 * on this screen the wrong answer to that would be a promise the portal cannot
 * keep.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const HERS: ConversationMessage = {
  id: 1,
  mine: true,
  body: 'Kommst du zum Familientreffen?',
  sent_at: '2026-08-01T10:00:00+00:00',
  read: true,
}

const HIS: ConversationMessage = {
  id: 2,
  mine: false,
  body: 'Ja, gerne!',
  sent_at: '2026-08-01T11:00:00+00:00',
  read: true,
}

const CONVERSATION: Conversation = {
  id: 3,
  member_id: 7,
  name: 'Dieter Beispiel',
  unread: 0,
  last_message: HIS,
}

function me() {
  return {
    user: { id: 1, username: 'anna', real_name: 'Anna Beispiel', email: 'a@b.test', language: 'de', role: 'member' },
    profile: { id: 1, visible_in_directory: true, display_name_override: null, consent_recorded_at: null },
    individual: null,
    tree: { name: 'portal', title: 'Familie Beispiel' },
    unread_messages: 0,
    unread_conversations: 0,
    csrf_token: 'token-1',
  }
}

const sent: { body: string }[] = []

function stub(options: { post?: () => Response; conversations?: Conversation[] } = {}) {
  let messages = [HERS, HIS]

  sent.length = 0

  const fetchMock = vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
    const url = String(input)
    const method = init?.method ?? 'GET'

    if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

    if (url.includes('/conversations/3/messages')) {
      if (method === 'DELETE') {
        messages = messages.filter((message) => message.id !== 1)

        return jsonResponse({ conversation: CONVERSATION, messages, before: null })
      }

      sent.push(JSON.parse(String(init?.body)) as { body: string })

      return (
        options.post?.() ??
        jsonResponse({ message: { id: 3, mine: true, body: 'Bis dann', sent_at: HIS.sent_at, read: false } }, 201)
      )
    }

    if (url.includes('/conversations/3')) {
      return jsonResponse({ conversation: CONVERSATION, messages, before: null })
    }

    if (url.includes('/conversations')) {
      return jsonResponse({ conversations: options.conversations ?? [CONVERSATION] })
    }

    if (url.includes('/messages')) return jsonResponse({ messages: [], unread: 0 })

    return jsonResponse(me())
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

describe('a conversation', () => {
  /**
   * The whole reason this exists. webtrees kept one copy of a message, owned
   * by whoever received it, so a member could never see what they had written.
   */
  it('shows both halves of the exchange', async () => {
    stub()
    renderAt('/conversations/3')

    expect(await screen.findByText('Kommst du zum Familientreffen?')).toBeDefined()
    expect(screen.getByText('Ja, gerne!')).toBeDefined()
  })

  it('sends what was typed and empties the box', async () => {
    stub()
    renderAt('/conversations/3')

    const user = userEvent.setup()
    const box = await screen.findByLabelText('Ihre Nachricht')

    await user.type(box, 'Bis dann')
    await user.click(screen.getByRole('button', { name: 'Senden' }))

    await waitFor(() => {
      expect(sent).toEqual([{ body: 'Bis dann' }])
    })

    expect((box as HTMLTextAreaElement).value).toBe('')
  })

  /**
   * What somebody typed is theirs. A send that fails must not take it with it
   * — on a phone, that is a lost paragraph and a member who stops trying.
   */
  it('gives the words back when sending fails', async () => {
    stub({ post: () => jsonResponse({ error: 'quota_reached', message: 'Too many.' }, 409) })
    renderAt('/conversations/3')

    const user = userEvent.setup()
    const box = await screen.findByLabelText('Ihre Nachricht')

    await user.type(box, 'Bis dann')
    await user.click(screen.getByRole('button', { name: 'Senden' }))

    expect(await screen.findByRole('alert')).toBeDefined()
    await waitFor(() => {
      expect((box as HTMLTextAreaElement).value).toBe('Bis dann')
    })
  })

  /**
   * The sentence under the button is the assertion. "Delete" on a shared
   * transcript can only mean one of two things, and the portal can only do
   * one of them.
   */
  it('says that deleting leaves the other side’s copy alone', async () => {
    stub()
    renderAt('/conversations/3')

    const user = userEvent.setup()
    await user.click((await screen.findAllByRole('button', { name: 'Für mich löschen' }))[0] as HTMLElement)

    expect(screen.getByText(/Die andere Person behält ihre Kopie/)).toBeDefined()

    await user.click(screen.getByRole('button', { name: 'Löschen' }))

    await waitFor(() => {
      expect(screen.queryByText('Kommst du zum Familientreffen?')).toBeNull()
    })

    expect(screen.getByText('Ja, gerne!')).toBeDefined()
  })

  it('offers the other person’s profile, because a name is not a link', async () => {
    stub()
    renderAt('/conversations/3')

    const link = await screen.findByRole('link', { name: 'Zum Profil' })

    expect(link.getAttribute('href')).toBe('/members/7')
  })
})

describe('the list of conversations', () => {
  it('sits above the inbox and leads into the exchange', async () => {
    stub()
    renderAt('/messages')

    const link = await screen.findByRole('link', { name: /Dieter Beispiel/ })

    expect(link.getAttribute('href')).toBe('/conversations/3')
  })

  /** The digit is a colour; the count has to be in words as well. */
  it('says how many are unread in the accessible name', async () => {
    stub({ conversations: [{ ...CONVERSATION, unread: 2 }] })
    renderAt('/messages')

    expect(await screen.findByRole('link', { name: /2 ungelesen/ })).toBeDefined()
  })

  /**
   * The first version rendered nothing here while there were none, and the
   * first person to look for the feature reported exactly that: "I only see
   * Sonstige Nachrichten". The way in is on somebody else's page, which is not
   * where you look when you are standing on this screen — so this screen has
   * to say where it is.
   */
  it('says where to start while there are none', async () => {
    stub({ conversations: [] })
    renderAt('/messages')

    expect(await screen.findByText('Noch keine Gespräche')).toBeDefined()
    expect(screen.getByText(/Nachricht schreiben/)).toBeDefined()

    const toContacts = screen.getByRole('link', { name: 'Zu meinen Kontakten' })

    expect(toContacts.getAttribute('href')).toBe('/members')
  })
})
