import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { proxyToWebtrees } from './proxy'
import { CONTENT_SECURITY_POLICY, HSTS } from './security'
import worker from './worker'

/**
 * What this origin says about itself.
 *
 * Two of these are worth a test rather than a reading, because both fail
 * silently in the direction of doing nothing: a policy that is not sent looks
 * exactly like a page that is allowed to do anything, and a page that is
 * allowed to do anything is what a portal full of living people's addresses
 * must not be.
 */

const env = {
  WEBTREES_ORIGIN: 'https://webtrees.example.org',
  ASSETS: {
    fetch: async () =>
      new Response('<!doctype html><title>portal</title>', {
        headers: { 'Content-Type': 'text/html' },
      }),
  },
}

function get(url: string): Promise<Response> {
  return worker.fetch(new Request(url), env as never) as Promise<Response>
}

describe('what the portal says about its own origin', () => {
  it('asks to be reached over HTTPS for a year, subdomains included', async () => {
    const response = await get('https://portal.example/')

    expect(response.headers.get('Strict-Transport-Security')).toBe(
      'max-age=31536000; includeSubDomains',
    )
    expect(HSTS).not.toContain('preload')
  })

  /**
   * A browser ignores it on a plaintext request anyway. Not sending it keeps
   * `wrangler dev` from looking like it is trying to pin localhost.
   */
  it('does not send it over plain http', async () => {
    const response = await get('http://localhost:8787/')

    expect(response.headers.get('Strict-Transport-Security')).toBeNull()
  })

  it('serves the app with a policy that allows only its own script', async () => {
    const policy = (await get('https://portal.example/')).headers.get('Content-Security-Policy')

    expect(policy).toContain("script-src 'self'")
    expect(policy).toContain("default-src 'self'")
    expect(policy).toContain("frame-ancestors 'none'")
    expect(policy).toContain("object-src 'none'")
  })

  /**
   * The one grant, and the one that must not spread. An inline style cannot
   * run code; an inline script can, and this is where that line is drawn.
   */
  it('allows an inline style and never an inline script', () => {
    expect(CONTENT_SECURITY_POLICY).toContain("style-src 'self' 'unsafe-inline'")
    expect(CONTENT_SECURITY_POLICY).not.toMatch(/script-src[^;]*unsafe-inline/)
    expect(CONTENT_SECURITY_POLICY).not.toMatch(/unsafe-eval/)
  })

  it('hardens the API too, without the policy meant for a page', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockResolvedValue(new Response('{}', { status: 200 })),
    )

    const response = await proxyToWebtrees(new Request('https://portal.example/api/v1/me'), env)

    expect(response.headers.get('Strict-Transport-Security')).toBe(HSTS)
    expect(response.headers.get('Content-Security-Policy')).toBe("frame-ancestors 'none'")
  })

  /**
   * A photograph opened in its own tab is a document whose content is the
   * image, so `default-src 'none'` on an API answer would leave a member
   * looking at a blank tab. The API policy says one thing on purpose.
   */
  it('does not put default-src none on an answer that may be an image', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn<typeof fetch>()
        .mockResolvedValue(new Response('bytes', { headers: { 'Content-Type': 'image/png' } })),
    )

    const response = await proxyToWebtrees(
      new Request('https://portal.example/api/v1/media/M1/abc/thumbnail'),
      env,
    )

    expect(response.headers.get('Content-Security-Policy')).not.toContain('default-src')
  })

  it('passes the body and the status through untouched', async () => {
    // With the content type a real answer carries. webtrees' response factory
    // sets `application/json` on every payload it builds, and the proxy now
    // refuses a reply that claims to be anything else — see `notAnAnswer` in
    // proxy.ts and §2.103. Without the header this fixture was a `text/plain`
    // answer, which the API never sends.
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockResolvedValue(
        new Response('{"error":"unauthenticated"}', {
          status: 401,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    )

    const response = await proxyToWebtrees(new Request('https://portal.example/api/v1/me'), env)

    expect(response.status).toBe(401)
    expect(await response.text()).toBe('{"error":"unauthenticated"}')
  })
})

/**
 * The build has to keep its side of the bargain.
 *
 * `script-src 'self'` is a promise about what the page contains, and vite is
 * what fills the page: with `modulePreload.polyfill` left on it injects an
 * inline script, which this policy blocks — a portal that will not start.
 * The setting is in vite.config.ts; this is the reason it is there.
 */
describe('the shell the policy is written for', () => {
  const shellPath = resolve(__dirname, '../dist/index.html')

  // CI builds before it tests (`ci.yml`), so this runs where it matters. On a
  // working copy that has never been built there is nothing to read, and a
  // failure there would say "the shell is wrong" when it means "there is no
  // shell".
  it.skipIf(!existsSync(shellPath))('has no inline script in it', () => {
    const shell = readFileSync(shellPath, 'utf8')

    // Every <script> in the built shell carries a src. An inline one would be
    // `<script>` or `<script type="module">` with a body.
    for (const tag of shell.match(/<script\b[^>]*>/g) ?? []) {
      expect(tag).toContain('src=')
    }
  })
})
