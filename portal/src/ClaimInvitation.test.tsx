import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ClaimInvitation } from './routes/ClaimInvitation'
import './i18n'

/**
 * Phase 15: the page a letter to a mailing list points at.
 *
 * The letter goes to three hundred people, so what it carries cannot be a
 * credential. This page is where somebody trades the thing everybody has — the
 * link — for the thing only they have, which is a letter in their own inbox.
 *
 * What is worth testing here is not that the form posts. It is that the screen
 * keeps the secret the server is keeping: the confirmation must read the same
 * whether or not the address belongs to this family, because a page that said
 * "not on the list" would answer the question the whole endpoint refuses to.
 */

const posted: { campaign?: string; email?: string }[] = []

function stub() {
  posted.length = 0

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

      posted.push(JSON.parse(String(init?.body)) as { campaign: string; email: string })

      return new Response(JSON.stringify({ status: 'sent' }), {
        status: 202,
        headers: { 'Content-Type': 'application/json' },
      })
    }),
  )
}

function renderAt(path: string) {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter initialEntries={[path]}>
        <ClaimInvitation />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('answering the letter that went to a mailing list', () => {
  it('sends the address the reader typed, with the campaign from the link', async () => {
    stub()

    renderAt('/einladung?aktion=abc123')

    await userEvent.type(await screen.findByLabelText(/E-Mail-Adresse/), 'anna@example.test')
    await userEvent.click(screen.getByRole('button', { name: 'Einladung anfordern' }))

    expect(await screen.findByText(/Bitte sehen Sie in Ihr Postfach/)).toBeDefined()
    expect(posted).toEqual([{ campaign: 'abc123', email: 'anna@example.test' }])
  })

  /**
   * The promise. One sentence, whoever asked — and worded so that it is true
   * either way rather than merely vague.
   */
  it('never says whether the address belongs to the family', async () => {
    stub()

    const { container } = renderAt('/einladung?aktion=abc123')

    await userEvent.type(await screen.findByLabelText(/E-Mail-Adresse/), 'fremder@example.test')
    await userEvent.click(screen.getByRole('button', { name: 'Einladung anfordern' }))

    await screen.findByText(/Bitte sehen Sie in Ihr Postfach/)

    expect(container.textContent).toMatch(/Wenn diese Adresse zur Familie gehört/)
    expect(container.textContent).not.toMatch(/nicht gefunden|unbekannt|steht nicht/i)
  })

  it('asks for an address before it sends anything', async () => {
    stub()

    renderAt('/einladung?aktion=abc123')

    await userEvent.click(await screen.findByRole('button', { name: 'Einladung anfordern' }))

    expect(await screen.findByRole('alert')).toBeDefined()
    expect(posted).toEqual([])
  })

  /**
   * A link that lost its token still has to behave. The server refuses it and
   * says the same sentence, so the screen must not pretend otherwise.
   */
  it('still posts when the link carried no campaign, and says the same thing', async () => {
    stub()

    renderAt('/einladung')

    await userEvent.type(await screen.findByLabelText(/E-Mail-Adresse/), 'anna@example.test')
    await userEvent.click(screen.getByRole('button', { name: 'Einladung anfordern' }))

    expect(await screen.findByText(/Bitte sehen Sie in Ihr Postfach/)).toBeDefined()
    expect(posted).toEqual([{ campaign: '', email: 'anna@example.test' }])
  })
})
