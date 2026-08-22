import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { MyPhotos } from './components/MyPhotos'
import type { Photo } from './api/types'
import './i18n'

/**
 * Phase 15: a member's own photographs.
 *
 * The module decides what is *shown* — a living person's photograph only where
 * they uploaded it themselves, `PhotoTest.php` — and this is the screen where
 * they can do that, and undo it.
 *
 * The assertion that matters most is not the upload. It is that the rule is
 * written on the screen: a member whose picture disappeared from their record
 * when this shipped is owed the reason in words, not a guess.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const PHOTO: Photo = {
  id: 'abc',
  title: 'Anna im Garten',
  thumbnail_url: '/api/v1/media/M7/abc/thumbnail',
  image_url: '/api/v1/media/M7/abc/image',
}

const sent: { method: string; url: string; form: boolean }[] = []

function stub(post?: () => Response) {
  sent.length = 0

  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input, init) => {
      const url = String(input)
      const method = init?.method ?? 'GET'

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

      if (url.includes('/photos')) {
        sent.push({ method, url, form: init?.body instanceof FormData })

        if (method === 'POST') {
          return post?.() ?? jsonResponse({ photos: [PHOTO], pending: false }, 201)
        }

        return jsonResponse({ photos: [] })
      }

      return jsonResponse({})
    }),
  )
}

function renderIt(photos: Photo[] = []) {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter>
        <MyPhotos photos={photos} />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function chosen(name = 'foto.jpg') {
  return new File(['not really a jpeg'], name, { type: 'image/jpeg' })
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('my own photographs', () => {
  /**
   * The sentence this whole phase owes the family. Somebody whose picture
   * vanished from their record is entitled to read why on the screen where
   * they can put it back.
   */
  it('says why a photograph they did not upload is not shown', () => {
    stub()
    renderIt()

    expect(screen.getByText(/nur gezeigt, wenn die Person sie selbst hochgeladen hat/)).toBeDefined()
    expect(screen.getByText(/Fotos Verstorbener bleiben unverändert/)).toBeDefined()
  })

  /** And that the file is cleaned on the way in, before anybody chooses one. */
  it('says that the place it was taken is removed', () => {
    stub()
    renderIt()

    expect(screen.getByText(/Aufnahmeort .* werden beim Hochladen entfernt/)).toBeDefined()
  })

  it('sends the chosen file as a form, not as JSON', async () => {
    stub()
    renderIt()

    await userEvent.upload(screen.getByLabelText('Foto auswählen'), chosen())

    await waitFor(() => {
      expect(sent).toEqual([{ method: 'POST', url: '/api/v1/photos', form: true }])
    })
  })

  it('takes one down again, by the record it belongs to', async () => {
    stub()
    renderIt([PHOTO])

    await userEvent.click(screen.getByRole('button', { name: 'Entfernen' }))

    await waitFor(() => {
      expect(sent).toEqual([{ method: 'DELETE', url: '/api/v1/photos/M7', form: false }])
    })
  })

  /**
   * A record with an unapproved edit on it cannot take the photograph live,
   * because webtrees' pending changes are snapshots of the whole record. The
   * member is told rather than left wondering where their picture went.
   */
  it('says so when the photograph has to wait for an approval', async () => {
    stub(() => jsonResponse({ photos: [], pending: true }, 201))
    renderIt()

    await userEvent.upload(screen.getByLabelText('Foto auswählen'), chosen())

    expect(await screen.findByText(/wartet, erscheint es erst/)).toBeDefined()
  })

  it('explains a refusal instead of swallowing it', async () => {
    stub(() => jsonResponse({ error: 'bad_request', message: 'Kein Foto.' }, 400))
    renderIt()

    await userEvent.upload(screen.getByLabelText('Foto auswählen'), chosen())

    expect(await screen.findByRole('alert')).toBeDefined()
  })
})
