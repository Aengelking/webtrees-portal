import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import i18n from './i18n'

/**
 * Asking for a way in, for the reader no mailing list holds.
 *
 * The page one door along — the campaign's — can answer by itself, because
 * being on the family's list has already settled who somebody is. A notice in
 * the family magazine reaches people no list has ever held, and for them that
 * page is a dead end by design.
 *
 * So what is worth pinning here is not that a form posts. It is that this
 * screen promises no more than the server does: it takes what somebody typed,
 * says it arrived, and says nothing about whether the number they gave means
 * anything to this family — which is the question the magazine puts a copy of
 * in three hundred letterboxes.
 */

const posted: Record<string, string>[] = []

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

      // Nobody is signed in. That is the premise of the whole page.
      if (url.endsWith('/me')) {
        return new Response(JSON.stringify({ error: 'unauthenticated' }), {
          status: 401,
          headers: { 'Content-Type': 'application/json' },
        })
      }

      posted.push(JSON.parse(String(init?.body)) as Record<string, string>)

      return new Response(JSON.stringify({ status: 'received' }), {
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
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

afterEach(async () => {
  vi.unstubAllGlobals()
  await i18n.changeLanguage('de')
})

describe('asking for access', () => {
  it('sends what was typed and says only that it arrived', async () => {
    stub()
    renderAt('/zugang')

    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Dein Name'), 'Antje Beispiel')
    await user.type(screen.getByLabelText('Deine E-Mail-Adresse'), 'antje@example.test')
    await user.type(screen.getByLabelText(/SB-Nummer/), '22/1a32.124')
    await user.type(screen.getByLabelText(/Wie gehörst du zur Familie/), 'Über Bertha.')
    await user.click(screen.getByRole('button', { name: 'Antrag absenden' }))

    expect(await screen.findByText('Dein Antrag ist angekommen')).toBeDefined()

    expect(posted).toHaveLength(1)
    expect(posted[0]).toMatchObject({
      name: 'Antje Beispiel',
      email: 'antje@example.test',
      reference: '22/1a32.124',
      note: 'Über Bertha.',
    })
  })

  /**
   * The number is what makes a linked account possible, and the magazine
   * prints it — but a cousin who has never seen their own must not be stopped
   * by it. Neither it nor the note is required.
   */
  it('asks for a number but does not insist on one', async () => {
    stub()
    renderAt('/zugang')

    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Dein Name'), 'Antje Beispiel')
    await user.type(screen.getByLabelText('Deine E-Mail-Adresse'), 'antje@example.test')
    await user.click(screen.getByRole('button', { name: 'Antrag absenden' }))

    expect(await screen.findByText('Dein Antrag ist angekommen')).toBeDefined()
    expect(posted[0]).toMatchObject({ reference: '', note: '' })
  })

  /**
   * Nothing is sent without the two things the person answering cannot act
   * without. The screen says so rather than posting an entry nobody can use.
   */
  it('will not send a request nobody could answer', async () => {
    stub()
    renderAt('/zugang')

    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Dein Name'), 'Antje Beispiel')
    await user.click(screen.getByRole('button', { name: 'Antrag absenden' }))

    expect((await screen.findByRole('alert')).textContent).toMatch(/Namen und deine E-Mail-Adresse/)
    expect(posted).toHaveLength(0)
  })

  /**
   * The confirmation is the same sentence for everybody, because the server's
   * answer is. Nothing here may say whether the number was recognised.
   */
  it('says nothing about whether the family knows this person', async () => {
    stub()
    renderAt('/zugang')

    const user = userEvent.setup()

    await user.type(await screen.findByLabelText('Dein Name'), 'Wildfremde Person')
    await user.type(screen.getByLabelText('Deine E-Mail-Adresse'), 'fremd@example.test')
    await user.type(screen.getByLabelText(/SB-Nummer/), '999999')
    await user.click(screen.getByRole('button', { name: 'Antrag absenden' }))

    const notice = await screen.findByText('Dein Antrag ist angekommen')

    expect(notice).toBeDefined()
    expect(screen.queryByText(/nicht gefunden|unbekannt|kein Eintrag/)).toBeNull()
  })
})

/**
 * Two ways in, for two kinds of reader. Whoever holds a campaign link should
 * not have to guess that there is another door; whoever typed the portal's
 * address into a browser should not find a login screen and nothing else.
 */
describe('the ways to the form', () => {
  it('is offered on the page the round-robin letter points at', async () => {
    stub()
    renderAt('/einladung?aktion=abc')

    const link = await screen.findByRole('link', { name: 'Zugang beantragen' })

    expect(link.getAttribute('href')).toBe('/zugang')
  })

  it('is offered on the sign-in screen', async () => {
    stub()
    renderAt('/login')

    const link = await screen.findByRole('link', { name: /Noch keinen Zugang/ })

    expect(link.getAttribute('href')).toBe('/zugang')
  })
})
