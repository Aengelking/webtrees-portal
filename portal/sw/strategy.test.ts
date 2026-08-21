import { describe, expect, it } from 'vitest'
import { assetsIn, handlingFor, mayStoreAsset } from './strategy'

/**
 * The service worker sits between a member's phone and everything the portal
 * knows about their family. One rule matters more than the rest of this file
 * put together: **nothing behind /api is ever intercepted, and so can never be
 * stored.** The rest guards the failure that would take the portal down
 * without anyone being able to fix it from outside — a cached page pretending
 * to be a script.
 */

const SCOPE = 'https://portal.example/'

function request(url: string, overrides: { method?: string; mode?: string } = {}) {
  return {
    method: overrides.method ?? 'GET',
    mode: overrides.mode ?? 'no-cors',
    url,
  }
}

describe('what the service worker will touch', () => {
  it('never touches the API, whatever is being asked for', () => {
    expect(handlingFor(request('https://portal.example/api/v1/me'), SCOPE)).toBe('bypass')
    expect(handlingFor(request('https://portal.example/api/v1/members?q=anna'), SCOPE)).toBe(
      'bypass',
    )
    expect(handlingFor(request('https://portal.example/api'), SCOPE)).toBe('bypass')
  })

  /**
   * A photograph of a living person, fetched as an ordinary image by an
   * ordinary <img>. It looks exactly like an asset from here — same origin,
   * GET, not a navigation — and only the path says otherwise.
   */
  it('never touches a photograph', () => {
    expect(
      handlingFor(request('https://portal.example/api/v1/media/M1/abc/thumbnail'), SCOPE),
    ).toBe('bypass')
  })

  it('leaves anything that is not a GET alone', () => {
    expect(handlingFor(request('https://portal.example/', { method: 'POST' }), SCOPE)).toBe(
      'bypass',
    )
  })

  it('leaves other origins alone', () => {
    expect(handlingFor(request('https://webtrees.example.org/index.php'), SCOPE)).toBe('bypass')
  })

  it('treats a page load as a document, whichever path the router owns', () => {
    expect(handlingFor(request('https://portal.example/', { mode: 'navigate' }), SCOPE)).toBe(
      'document',
    )
    expect(
      handlingFor(request('https://portal.example/members', { mode: 'navigate' }), SCOPE),
    ).toBe('document')
  })

  it('treats the shell’s own files as assets', () => {
    expect(handlingFor(request('https://portal.example/assets/index-a1b2c3.js'), SCOPE)).toBe(
      'asset',
    )
    expect(handlingFor(request('https://portal.example/icons/icon-192.png'), SCOPE)).toBe('asset')
  })
})

describe('what may be kept', () => {
  function response(
    init: { status?: number; type?: string; redirected?: boolean; headers?: Record<string, string> } = {},
  ) {
    return {
      status: init.status ?? 200,
      type: (init.type ?? 'basic') as ResponseType,
      redirected: init.redirected ?? false,
      headers: new Headers(init.headers ?? { 'Content-Type': 'application/javascript' }),
    }
  }

  it('keeps an ordinary asset', () => {
    expect(mayStoreAsset(response())).toBe(true)
  })

  /**
   * The one that would be unrecoverable. Both deployment targets answer an
   * unknown path with index.html so the router can take it — so a request for
   * a hashed file that a deployment has removed comes back 200 with the app's
   * HTML in it. Stored under a .js URL, that is a portal that will not start,
   * on a device nobody can reach.
   */
  it('refuses the SPA fallback masquerading as an asset', () => {
    expect(mayStoreAsset(response({ headers: { 'Content-Type': 'text/html; charset=utf-8' } }))).toBe(
      false,
    )
  })

  it('refuses anything that is not a plain, complete 200', () => {
    expect(mayStoreAsset(response({ status: 206 }))).toBe(false)
    expect(mayStoreAsset(response({ status: 404 }))).toBe(false)
    expect(mayStoreAsset(response({ status: 500 }))).toBe(false)
    expect(mayStoreAsset(response({ redirected: true }))).toBe(false)
    expect(mayStoreAsset(response({ type: 'opaque' }))).toBe(false)
  })

  it('obeys no-store, so the rule still holds if something private ever moves out of /api', () => {
    expect(
      mayStoreAsset(
        response({ headers: { 'Content-Type': 'image/jpeg', 'Cache-Control': 'private, no-store' } }),
      ),
    ).toBe(false)
  })
})

/**
 * Caching index.html alone is the mistake that makes a portal look installed
 * and open blank in a tunnel: the document is cached, the hashed script it
 * asks for is not, and offline there is no way to go and get it.
 */
describe('the files the shell needs', () => {
  const built = `<!doctype html>
<html lang="de">
  <head>
    <script type="module" crossorigin src="/assets/index-DI-V1sbw.js"></script>
    <link rel="stylesheet" crossorigin href="/assets/index-pBjUxoRz.css">
    <link rel="manifest" href="/manifest.webmanifest" />
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png" />
    <link rel="preconnect" href="https://example.org/" />
  </head>
  <body><div id="root"></div></body>
</html>`

  it('finds the script and the stylesheet the build named', () => {
    expect(assetsIn(built)).toContain('/assets/index-DI-V1sbw.js')
    expect(assetsIn(built)).toContain('/assets/index-pBjUxoRz.css')
  })

  it('takes the manifest and the icons along, which is what makes the install survive', () => {
    expect(assetsIn(built)).toContain('/manifest.webmanifest')
    expect(assetsIn(built)).toContain('/icons/apple-touch-icon.png')
  })

  it('leaves other origins to fetch themselves', () => {
    expect(assetsIn(built)).not.toContain('https://example.org/')
  })

  it('names each file once, however often the document mentions it', () => {
    const twice = `<link rel="icon" href="/icons/icon.svg"><link rel="apple-touch-icon" href="/icons/icon.svg">`

    expect(assetsIn(twice)).toEqual(['/icons/icon.svg'])
  })
})
