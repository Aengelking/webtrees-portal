import { describe, expect, it, vi } from 'vitest'
import { proxyToWebtrees } from './proxy'

/**
 * The proxy is the only thing between webtrees' headers and a CDN, so the one
 * rule worth testing here is the one that would be a data breach if it broke:
 * nothing shared may ever keep an authenticated response.
 */

const env = { WEBTREES_ORIGIN: 'https://webtrees.example.org' }

function upstream(cacheControl: string | null) {
  const headers = new Headers({ 'Content-Type': 'image/png' })

  if (cacheControl !== null) {
    headers.set('Cache-Control', cacheControl)
  }

  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async () => new Response('bytes', { headers })),
  )
}

async function cacheControlFor(fromWebtrees: string | null): Promise<string | null> {
  upstream(fromWebtrees)

  const response = await proxyToWebtrees(
    new Request('https://portal.example/api/v1/media/M1/abc/thumbnail'),
    env,
  )

  return response.headers.get('cache-control')
}

describe('what may keep a response', () => {
  it('lets a browser keep a photograph the module marked private', async () => {
    expect(await cacheControlFor('private, max-age=86400')).toBe('private, max-age=86400')
  })

  /**
   * webtrees' own answer for media. Fine on a site serving its own pages; on
   * the far side of a CDN, "public" means an edge could hand one member's
   * photograph to the next member who asks for that URL.
   */
  it('refuses webtrees’ public, max-age=31536000 outright', async () => {
    expect(await cacheControlFor('public, max-age=31536000')).toBe('private, no-store')
  })

  it('refuses anything that merely mentions private alongside public', async () => {
    expect(await cacheControlFor('private, public, max-age=60')).toBe('private, no-store')
  })

  it('stores nothing when the header is missing or unrecognised', async () => {
    expect(await cacheControlFor(null)).toBe('private, no-store')
    expect(await cacheControlFor('max-age=600')).toBe('private, no-store')
    expect(await cacheControlFor('no-cache')).toBe('private, no-store')
  })
})
