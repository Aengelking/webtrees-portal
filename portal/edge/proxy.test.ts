import { describe, expect, it, vi } from 'vitest'
import { isOAuthDiscoveryRequest, noOAuthHere, proxyToWebtrees } from './proxy'

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
   * Both, not one. A host that does pass `Authorization` through should go on
   * using it; the module reads that one first.
   */
  it('forwards the original as well', async () => {
    const headers = await forwarded({ Authorization: 'Bearer wtmcp_abc' })

    expect(headers.get('authorization')).toBe('Bearer wtmcp_abc')
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
