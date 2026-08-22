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
const opened: number[] = []

/** Two accepted contacts, one of whom has no profile row yet. */
const CONTACTS = [
  { id: 11, status: 'accepted', source: 'code', requested_by_me: true, member_id: 7, name: 'Dieter Beispiel', individual: null, since: '2026-07-01T10:00:00+00:00' },
  { id: 12, status: 'accepted', source: 'code', requested_by_me: false, member_id: 9, name: 'Bernd Beispiel', individual: null, since: '2026-07-02T10:00:00+00:00' },
]

function stub(
  options: {
    post?: () => Response
    conversations?: Conversation[]
    contacts?: unknown[]
    open?: () => Response
  } = {},
) {
  let messages = [HERS, HIS]

  sent.length = 0
  opened.length = 0

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
      if (method === 'POST') {
        opened.push((JSON.parse(String(init?.body)) as { member_id: number }).member_id)

        return options.open?.() ?? jsonResponse({ conversation: CONVERSATION }, 201)
      }

      return jsonResponse({ conversations: options.conversations ?? [CONVERSATION] })
    }

    if (url.includes('/connections')) {
      return jsonResponse({
        enabled: true,
        code_valid_minutes: 15,
        connections: options.contacts ?? CONTACTS,
        incoming: [],
        outgoing: [],
      })
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

  /**
   * The other person is told something arrived. They are not told what, or by
   * whom — not in the e-mail and not on a lock screen. Said next to the box a
   * member types into, because a member who started this from the messages
   * screen never passes the other person's page, where it used to be said.
   */
  it('says what the other person will be told, next to the box', async () => {
    stub()
    renderAt('/conversations/3')

    expect(await screen.findByText(/nur, dass eine Nachricht im Portal wartet/)).toBeDefined()
    expect(screen.getByText(/weder Ihr Name noch der Text/)).toBeDefined()
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
   * Sonstige Nachrichten". The second said where the way in was. This one is
   * the way in.
   */
  it('says where to start while there are none', async () => {
    stub({ conversations: [] })
    renderAt('/messages')

    expect(await screen.findByText('Noch keine Gespräche')).toBeDefined()

    const start = screen.getByRole('link', { name: 'Gespräch beginnen' })

    expect(start.getAttribute('href')).toBe('/conversations/new')
  })

  /**
   * And while there are some. Writing to somebody is not a thing a member does
   * once, so the button is not part of the empty state.
   */
  it('offers a new conversation next to the ones that exist', async () => {
    stub()
    renderAt('/messages')

    const start = await screen.findByRole('link', { name: 'Neues Gespräch' })

    expect(start.getAttribute('href')).toBe('/conversations/new')
  })
})

/**
 * Picking somebody. This is the half §2.33 was missing: the empty list said
 * where the way in was — on the other person's page — and a member standing on
 * Nachrichten had to think of the directory to get there. Nobody does.
 */
describe('starting a conversation', () => {
  it('opens the conversation with the contact who was picked', async () => {
    stub()
    renderAt('/conversations/new')

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Bernd Beispiel' }))

    await waitFor(() => {
      expect(opened).toEqual([9])
    })

    // And lands in it, rather than leaving the member on the list they have
    // just finished with.
    expect(await screen.findByText('Kommst du zum Familientreffen?')).toBeDefined()
  })

  /**
   * A contact whose request has been made but not answered has no profile row
   * yet, so there is nothing to open. Offering the name would be offering a
   * button that fails.
   */
  it('leaves out a contact who has no member page yet', async () => {
    stub({ contacts: [CONTACTS[0], { ...CONTACTS[1], member_id: null }] })
    renderAt('/conversations/new')

    expect(await screen.findByRole('button', { name: 'Dieter Beispiel' })).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Bernd Beispiel' })).toBeNull()
  })

  /** Contacts are not everybody who may be written to, so this is not a dead end. */
  it('points at the directory for somebody who is not a contact', async () => {
    stub()
    renderAt('/conversations/new')

    const directory = await screen.findByRole('link', { name: 'Im Mitgliederverzeichnis suchen' })

    expect(directory.getAttribute('href')).toBe('/members')
  })

  it('sends nobody to an empty list, and says what to do instead', async () => {
    stub({ contacts: [] })
    renderAt('/conversations/new')

    expect(await screen.findByText('Noch keine Kontakte')).toBeDefined()

    const toContacts = screen.getByRole('link', { name: 'Zu meinen Kontakten' })

    expect(toContacts.getAttribute('href')).toBe('/contacts')
  })

  /** A search box above four names is furniture. */
  it('offers no search while the list is short', async () => {
    stub()
    renderAt('/conversations/new')

    expect(await screen.findByRole('button', { name: 'Dieter Beispiel' })).toBeDefined()
    expect(screen.queryByLabelText('Name suchen')).toBeNull()
  })

  it('offers one once the list is long enough to need it', async () => {
    stub({
      contacts: Array.from({ length: 9 }, (_, index) => ({
        ...CONTACTS[0],
        id: 100 + index,
        member_id: 100 + index,
        name: `Person ${index} Beispiel`,
      })),
    })
    renderAt('/conversations/new')

    const user = userEvent.setup()
    const search = await screen.findByLabelText('Name suchen')

    await user.type(search, 'Person 3')

    await waitFor(() => {
      expect(screen.queryByRole('button', { name: 'Person 4 Beispiel' })).toBeNull()
    })

    expect(screen.getByRole('button', { name: 'Person 3 Beispiel' })).toBeDefined()

    await user.clear(search)
    await user.type(search, 'Gertrud')

    expect(await screen.findByText('Kein Kontakt mit diesem Namen.')).toBeDefined()
  })

  /**
   * The server refuses for reasons this screen cannot see — the member left
   * the directory and the connection ended, the daily limit is spent. A member
   * who taps a name and gets nothing would tap it again.
   */
  it('says so when the server will not open one', async () => {
    stub({ open: () => jsonResponse({ error: 'quota_reached', message: 'Too many.' }, 409) })
    renderAt('/conversations/new')

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Dieter Beispiel' }))

    expect(await screen.findByRole('alert')).toBeDefined()
  })
})
