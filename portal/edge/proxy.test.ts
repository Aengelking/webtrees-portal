import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { isApiRequest, isOAuthDiscoveryRequest, noOAuthHere, proxyToWebtrees } from './proxy'

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


/**
 * A web page arriving where an API answer was asked for.
 *
 * A member reported that signing in sometimes failed. In the console the
 * session call carried Apache's stock *503 Service Unavailable* page — under a
 * **200**. Passed through, the portal got a page where it asked for a record,
 * and told the member their password was wrong. See §2.102.
 */
describe('a page where an answer was asked for', () => {
  async function through(upstream: Response): Promise<Response> {
    vi.stubGlobal('fetch', vi.fn<typeof fetch>().mockResolvedValue(upstream))

    return proxyToWebtrees(new Request('https://portal.example/api/v1/me'), env)
  }

  function page(status: number): Response {
    return new Response('<html><head><title>503 Service Unavailable</title></head></html>', {
      status,
      headers: { 'Content-Type': 'text/html; charset=iso-8859-1' },
    })
  }

  /**
   * The status is *not* repeated. A success code on an error page is the lie
   * that made this hard to find; sending it on would keep it hidden.
   */
  it('refuses to repeat a success status that came with an error page', async () => {
    const response = await through(page(200))

    expect(response.status).toBe(502)
    expect(response.headers.get('content-type')).toContain('application/json')
    await expect(response.json()).resolves.toMatchObject({ error: 'unreadable_answer' })
  })

  /** What the origin really said, because that was the thing nobody could see. */
  it('says what the family server actually answered', async () => {
    expect((await through(page(200))).headers.get('x-portal-upstream-status')).toBe('200')
  })

  /** A page that already admits to being an error keeps its own status. */
  it('keeps a status that was honest to begin with', async () => {
    expect((await through(page(503))).status).toBe(503)
  })

  /**
   * Redirects are left entirely alone: webtrees answers a stale CSRF token
   * with one, the client reads it as exactly that and retries, and the HTML
   * body on it is only the browser's courtesy page.
   */
  it('leaves a redirect alone, HTML body and all', async () => {
    const redirect = new Response('<html>Moved</html>', {
      status: 302,
      headers: { 'Content-Type': 'text/html', Location: 'https://webtrees.example/login' },
    })

    expect((await through(redirect)).status).toBe(302)
  })

  /**
   * **The session survives the refusal.**
   *
   * The first version of this built a fresh response and carried nothing
   * across — `Set-Cookie` included. webtrees hands out its session cookie on
   * exactly the requests this is most likely to catch, so dropping it did not
   * make one answer worse: it left a client that could no longer establish a
   * session at all, and every CSRF check after it failed. An intermittent
   * fault became a permanent one. See §2.103.
   */
  it('keeps the session cookie the family server set', async () => {
    const withCookie = new Response('<html>503</html>', {
      status: 503,
      headers: {
        'Content-Type': 'text/html',
        'Set-Cookie': 'WT_SESSION=abc; Path=/; Domain=webtrees.example; HttpOnly; SameSite=Lax',
      },
    })

    const cookies = (await through(withCookie)).headers.getSetCookie()

    expect(cookies).toHaveLength(1)
    expect(cookies[0]).toContain('WT_SESSION=abc')
    // Rescoped for the portal's own origin, exactly as a real answer is.
    expect(cookies[0]).not.toContain('Domain=')
  })

  /**
   * **The shape it actually arrived in, which the first version walked past.**
   *
   * Caught in the act at last: Apache had stopped handing `.php` to PHP and
   * was serving the file, so the reply carried `application/x-httpd-php` — a
   * 200, with the 503 page as its body. The first version of this asked
   * whether the reply was `text/html`, which is the shape the failure had been
   * *seen* in, and this went straight past it. See §2.103.
   */
  it('refuses a reply the webserver made about itself, whatever it calls it', async () => {
    const served = new Response('<html><head><title>503 Service Unavailable</title></head></html>', {
      status: 200,
      headers: { 'Content-Type': 'application/x-httpd-php' },
    })

    const response = await through(served)

    expect(response.status).toBe(502)
    await expect(response.json()).resolves.toMatchObject({ error: 'unreadable_answer' })
    expect(response.headers.get('x-portal-upstream-type')).toBe('application/x-httpd-php')
  })

  /** A photograph is an answer, and must not be caught by the rule above. */
  it('lets a picture through', async () => {
    const picture = new Response('\u0089PNG', {
      status: 200,
      headers: { 'Content-Type': 'image/png' },
    })

    expect((await through(picture)).status).toBe(200)
  })

  /** So must an answer with no body: a `204` carries no content type at all. */
  it('lets an answer with no body through', async () => {
    expect((await through(new Response(null, { status: 204 }))).status).toBe(204)
  })

  it('lets a real answer past untouched', async () => {
    const response = await through(
      new Response('{"ok":true}', { status: 200, headers: { 'Content-Type': 'application/json' } }),
    )

    expect(response.status).toBe(200)
    await expect(response.json()).resolves.toMatchObject({ ok: true })
  })
})


/**
 * The MCP server's bearer token, which does not survive the trip under its own
 * name: Apache does not pass `Authorization` to PHP under CGI or FastCGI
 * unless `CGIPassAuth On` is set. Everything else in this portal authenticates
 * with a cookie, so nothing noticed until a good token was answered with 401.
 */
describe('carrying the MCP token past a webserver that eats it', () => {
  async function forwarded(headers: HeadersInit): Promise<Headers> {
    const sent = vi.fn<typeof fetch>().mockResolvedValue(new Response('{}'))
    vi.stubGlobal('fetch', sent)

    await proxyToWebtrees(new Request('https://portal.example/api/mcp', { headers }), env)

    return new Headers((sent.mock.calls[0]?.[1] as RequestInit).headers as HeadersInit)
  }

  it('copies Authorization under a name no webserver strips', async () => {
    const headers = await forwarded({ Authorization: 'Bearer wtmcp_abc' })

    expect(headers.get('x-portal-authorization')).toBe('Bearer wtmcp_abc')
  })

  /**
   * And takes the original off, which is the half that took a second attempt.
   * Sent alongside, it came back `302` to the very URL this proxy had just
   * asked for — something between the Worker and PHP answers the presence of
   * an `Authorization` header with a redirect, and a redirect destroys a
   * `POST`. Nothing downstream reads it, so nothing is lost by not sending it.
   */
  it('does not send the original alongside it', async () => {
    const headers = await forwarded({ Authorization: 'Bearer wtmcp_abc' })

    expect(headers.get('authorization')).toBeNull()
  })

  it('adds nothing when the client offered nothing', async () => {
    expect((await forwarded({})).get('x-portal-authorization')).toBeNull()
  })

  /**
   * The same rule as the proxy secret: a header this proxy writes is one a
   * client must never be able to write itself.
   */
  it('refuses a copy the client tried to set itself', async () => {
    const headers = await forwarded({ 'X-Portal-Authorization': 'Bearer wtmcp_forged' })

    expect(headers.get('x-portal-authorization')).toBeNull()
  })
})

/**
 * An MCP client that gets a 401 goes looking for OAuth metadata. The asset
 * layer answers any unmatched path with index.html and a 200, so it used to
 * find a web page where it expected JSON and die on `JSON.parse`.
 */
describe('OAuth metadata this server does not have', () => {
  it('recognises the paths a client probes', () => {
    for (const path of [
      '/.well-known/oauth-protected-resource',
      '/.well-known/oauth-protected-resource/api/mcp',
      '/.well-known/oauth-authorization-server',
      '/.well-known/openid-configuration',
    ]) {
      expect(isOAuthDiscoveryRequest(new URL('https://portal.example' + path))).toBe(true)
    }
  })

  it('leaves everything else to the portal', () => {
    for (const path of ['/', '/me', '/login', '/.well-known/acme-challenge/x', '/api/mcp']) {
      expect(isOAuthDiscoveryRequest(new URL('https://portal.example' + path))).toBe(false)
    }
  })

  it('answers 404 with JSON rather than a web page', async () => {
    const response = noOAuthHere()

    expect(response.status).toBe(404)
    expect(response.headers.get('content-type')).toContain('application/json')
    await expect(response.json()).resolves.toMatchObject({ error: 'not_found' })
  })
})


/**
 * Code in the Worker only runs for paths `run_worker_first` names. Everything
 * else is answered by the asset layer *before the Worker starts*, with
 * index.html and a 200 — so a handler for an unlisted path is written, shipped
 * and never reached, and the only symptom is a caller parsing a web page as
 * JSON.
 *
 * That happened once, to the OAuth 404 below. This is the check that would
 * have caught it: whatever the Worker claims to handle, wrangler.jsonc has to
 * let through.
 */
describe('wrangler.jsonc lets through what the Worker handles', () => {
  function runWorkerFirst(): string[] | boolean {
    const jsonc = readFileSync(resolve(process.cwd(), 'wrangler.jsonc'), 'utf-8')
    const json = jsonc.replace(/^\s*\/\/.*$/gm, '').replace(/,(\s*[}\]])/g, '$1')

    return JSON.parse(json).assets.run_worker_first
  }

  /** One example per path the Worker answers rather than passing to the assets. */
  const handled: Array<[string, (url: URL) => boolean]> = [
    ['/api/v1/me', isApiRequest],
    ['/api/mcp', isApiRequest],
    ['/.well-known/oauth-protected-resource', isOAuthDiscoveryRequest],
  ]

  it.each(handled)('reaches the Worker at all: %s', (path, handler) => {
    const url = new URL('https://portal.example' + path)

    // The premise: this really is a path the Worker means to answer.
    expect(handler(url)).toBe(true)

    const first = runWorkerFirst()
    const matched =
      first === true ||
      (Array.isArray(first) &&
        first.some((pattern) =>
          new RegExp(
            '^' + pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$',
          ).test(url.pathname),
        ))

    expect(matched, path + ' is handled in the Worker but never reaches it').toBe(true)
  })

  /**
   * And the page itself, which the Worker does not *answer* but does harden.
   * Left off this list, the portal is served straight off the asset layer with
   * no Content-Security-Policy and no HSTS on it — the headers exist in the
   * repository and nowhere else. See edge/security.ts.
   */
  it('lets the portal itself through, so its headers are the Worker’s to set', () => {
    expect(runWorkerFirst()).toBe(true)
  })
})
