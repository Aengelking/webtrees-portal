import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import './i18n'

/**
 * Phase 4: photographs.
 *
 * The API has already decided which pictures this member may see, so nothing
 * here is about privacy. It is about the two things the client owns: that the
 * URLs stay on the portal's own origin, and that a record with no photograph
 * looks like a record with no photograph rather than like a broken one.
 */

function jsonResponse(body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

const PHOTO = {
  id: 'a'.repeat(32),
  title: 'Anna im Garten',
  thumbnail_url: `/api/v1/media/M1/${'a'.repeat(32)}/thumbnail`,
  image_url: `/api/v1/media/M1/${'a'.repeat(32)}/image`,
}

const ANNA = {
  xref: 'X1',
  name: 'Anna Beispiel',
  sex: 'F',
  is_deceased: false,
  lifespan: '1985–',
  portrait: PHOTO,
  name_alternative: null,
  relationship: null,
  references: [],
  photos: [PHOTO],
  birth: null,
  death: null,
  events: [],
  parents: [
    {
      xref: 'X2',
      name: 'Bertha Beispiel',
      sex: 'F',
      is_deceased: true,
      lifespan: '1889–1976',
      portrait: null,
    },
  ],
  siblings: [],
  spouses: [],
  children: [],
  pending_change: false,
  webtrees_url: 'https://webtrees.example.org/tree/portal/individual/X1',
}

function me(individual: unknown = ANNA) {
  return {
    user: { id: 1, username: 'anna', real_name: 'Anna Beispiel', email: 'a@b.test', language: 'de', role: 'member' },
    profile: { id: 1, visible_in_directory: true, display_name_override: null, consent_recorded_at: null, directory_decided: true },
    individual,
    tree: { name: 'portal', title: 'Familie Beispiel' },
    csrf_token: 'token-1',
  }
}

function stub(individual: unknown = ANNA) {
  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input) =>
      String(input).endsWith('/csrf')
        ? jsonResponse({ csrf_token: 'token-1' })
        : jsonResponse(me(individual)),
    ),
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

describe('photographs', () => {
  /**
   * The one thing that would break every picture at once: a URL pointing at
   * the webtrees host, where this browser has no session.
   */
  it('loads every image from the portal’s own origin', async () => {
    stub()
    renderAt('/me')

    const gallery = await screen.findByRole('button', { name: 'Anna im Garten' })
    const image = gallery.querySelector('img')

    expect(image?.getAttribute('src')).toBe(PHOTO.thumbnail_url)
    expect(image?.getAttribute('src')).not.toContain('webtrees.example.org')
  })

  it('opens a photograph full size, and closes again', async () => {
    stub()
    renderAt('/me')

    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'Anna im Garten' }))

    const dialog = screen.getByRole('dialog')

    expect(dialog.querySelector('img')?.getAttribute('src')).toBe(PHOTO.image_url)

    await user.click(screen.getByRole('button', { name: 'Schließen' }))

    expect(screen.queryByRole('dialog')).toBeNull()
  })

  /**
   * A list where some rows have a picture and some do not, with the text
   * starting in a different place each time, is harder to read than a list
   * with no pictures at all. So the column stays put and an initial stands in.
   */
  it('keeps the column when a relative has no photograph', async () => {
    stub()
    renderAt('/me')

    const mother = await screen.findByRole('link', { name: /Bertha Beispiel/ })

    expect(mother.querySelector('img')).toBeNull()
    expect(mother.textContent).toContain('Bertha Beispiel')
  })

  it('survives a server that does not send photographs yet', async () => {
    const { portrait, photos, ...withoutPhotos } = ANNA

    stub(withoutPhotos)
    renderAt('/me')

    expect(await screen.findByRole('heading', { name: 'Anna Beispiel' })).toBeDefined()
    expect(screen.queryByRole('button', { name: 'Anna im Garten' })).toBeNull()
  })
})
