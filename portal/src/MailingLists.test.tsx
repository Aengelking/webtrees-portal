import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { MailingLists } from './components/MailingLists'
import type { MailingLists as State } from './api/types'
import './i18n'

/**
 * Phase 14: joining and leaving the family's mailing lists.
 *
 * Three things are worth a test here, and they are all about honesty rather
 * than about switches working.
 *
 * The first is that the list's address never reaches the browser. It is not
 * in the payload the module sends, and this asserts the consequence: whatever
 * the screen renders, the address is not on it.
 *
 * The second is that "pending" is said out loud. The list lives in Exchange
 * and the portal holds only the wish, so a switch that flicked and said
 * nothing more would be claiming something it does not know.
 *
 * The third is the pair of silences — a family that has not set this up, and
 * an account with no address — which §2.33 says must be a nothing and a
 * sentence respectively, not a section full of switches that refuse.
 */

const OFF: State = { enabled: false, address: '', lists: [] }

const READY: State = {
  enabled: true,
  address: 'anna@example.test',
  lists: [
    {
      key: 'aa11',
      name: 'Familiennachrichten',
      description: 'Ein- bis zweimal im Jahr.',
      subscribed: false,
      state: 'applied',
    },
    {
      key: 'bb22',
      name: 'Einladungen',
      description: '',
      subscribed: true,
      state: 'applied',
    },
  ],
}

/** Every PATCH body, in order. */
const patched: Record<string, boolean>[] = []

function stub(first: State, next?: State) {
  patched.length = 0

  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)

      if (url.endsWith('/csrf')) {
        return new Response(JSON.stringify({ csrf_token: 'token-1' }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }

      if (init?.method === 'PATCH') {
        patched.push((JSON.parse(String(init.body)) as { lists: Record<string, boolean> }).lists)

        return new Response(JSON.stringify(next ?? first), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }

      return new Response(JSON.stringify(first), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }),
  )
}

function renderIt() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter>
        <MailingLists />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('the family’s mailing lists', () => {
  it('offers one switch per list, and says where the post will go', async () => {
    stub(READY)

    renderIt()

    const news = await screen.findByRole('switch', { name: /Familiennachrichten/ })

    expect(news.getAttribute('aria-checked')).toBe('false')
    expect(screen.getByRole('switch', { name: /Einladungen/ }).getAttribute('aria-checked')).toBe(
      'true',
    )

    expect(screen.getByText(/anna@example\.test/)).toBeDefined()
  })

  /**
   * The promise the opaque key exists to keep. A member is offered *the family
   * news*; where that is delivered from is the administrator's business, and
   * putting it in every browser would be handing out the family's distribution
   * addresses for no reason at all.
   */
  it('never shows a list’s address', async () => {
    stub(READY)

    const { container } = renderIt()

    await screen.findByRole('switch', { name: /Familiennachrichten/ })

    expect(container.textContent).not.toContain('@example.de')
    expect(container.textContent).not.toContain('familie@')
  })

  it('sends only the list that moved', async () => {
    stub(READY, { ...READY, lists: [{ ...READY.lists[0]!, subscribed: true }, READY.lists[1]!] })

    renderIt()

    await userEvent.click(await screen.findByRole('switch', { name: /Familiennachrichten/ }))

    await waitFor(() => expect(patched).toHaveLength(1))

    // Not all three. Two members changing different lists in the same minute
    // must not undo each other.
    expect(patched[0]).toEqual({ aa11: true })
  })

  /**
   * Exchange is somebody else's service. The answer has been taken down, and
   * saying so is the whole of what the portal can honestly promise until it
   * has been passed on.
   */
  it('says when an answer has not been passed on yet', async () => {
    const pending: State = {
      ...READY,
      lists: [{ ...READY.lists[0]!, subscribed: true, state: 'pending' }, READY.lists[1]!],
    }

    stub(pending)

    renderIt()

    expect(await screen.findByText(/wird gerade übernommen/i)).toBeDefined()
  })

  it('tells a member that somebody is looking at it, and never why', async () => {
    const failed: State = {
      ...READY,
      lists: [{ ...READY.lists[0]!, subscribed: true, state: 'failed' }, READY.lists[1]!],
    }

    stub(failed)

    const { container } = renderIt()

    expect(await screen.findByText(/Wir kümmern uns darum/i)).toBeDefined()

    // The reason is a sentence about a tenant and an application registration.
    // A member can do nothing with it, so they are not given it.
    expect(container.textContent).not.toMatch(/exchange/i)
    expect(container.textContent).not.toMatch(/HTTP/)
  })

  it('renders nothing at all where the family has not set this up', async () => {
    stub(OFF)

    const { container } = renderIt()

    await waitFor(() => expect(container.innerHTML).toBe(''))
  })

  /**
   * The other half of §2.33's rule. This one is not impossible, it is merely
   * blocked — so it gets a sentence naming who can unblock it, rather than
   * switches that would refuse.
   */
  it('says so when the account has no address to subscribe', async () => {
    stub({ enabled: true, address: '', lists: READY.lists })

    renderIt()

    expect(await screen.findByText(/keine E-Mail-Adresse/i)).toBeDefined()
    expect(screen.queryByRole('switch')).toBeNull()
  })
})
