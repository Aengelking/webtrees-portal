/**
 * Reverse proxy for /api/* onto the webtrees host.
 *
 * This is the piece that makes everything else simple. Because the browser
 * only ever talks to the portal's own origin, the session cookie is a
 * first-party cookie: no CORS handling in the PHP module, no origin
 * allow-list, and no `SameSite=None`.
 *
 * Platform-agnostic on purpose. `edge/worker.ts` (Cloudflare Workers) and
 * `functions/api/[[path]].ts` (Cloudflare Pages) are both thin wrappers around
 * this, so the two deployment models cannot drift apart.
 */

export interface ProxyEnv {
  /**
   * Where webtrees lives, e.g. https://webtrees.example.org
   *
   * May include a path when webtrees is installed in a subdirectory rather
   * than at a domain root — https://example.org/webtrees — in which case that
   * prefix is put back in front of every proxied request.
   */
  WEBTREES_ORIGIN?: string
  /** Optional shared secret the PHP module checks. */
  PORTAL_PROXY_SECRET?: string
  /**
   * Set to "true" when the webtrees installation does NOT have `rewrite_urls`
   * enabled in its config.ini.php.
   *
   * webtrees only understands a path like /api/v1/csrf when URL rewriting is
   * on. With it off — which is the default, and common on shared hosting — its
   * router reads the route from a `route` query parameter and ignores the path
   * entirely, so every endpoint answers 404. This turns requests into the form
   * webtrees does understand: /index.php?route=/api/v1/csrf
   */
  WEBTREES_UGLY_URLS?: string
}

/** Hop-by-hop and Cloudflare-specific headers that must not be forwarded. */
const STRIPPED_REQUEST_HEADERS = [
  'host',
  'connection',
  'keep-alive',
  'transfer-encoding',
  'upgrade',
  'cf-connecting-ip',
  'cf-ipcountry',
  'cf-ray',
  'cf-visitor',
  'x-forwarded-host',
  // Never let a client set the secret itself — that is the whole point of it.
  'x-portal-proxy-secret',
]

const STRIPPED_RESPONSE_HEADERS = [
  'connection',
  'keep-alive',
  'transfer-encoding',
  'upgrade',
  'content-encoding',
  'content-length',
  'cf-cache-status',
]

export function isApiRequest(url: URL): boolean {
  return url.pathname === '/api' || url.pathname.startsWith('/api/')
}

export async function proxyToWebtrees(request: Request, env: ProxyEnv): Promise<Response> {
  const origin = env.WEBTREES_ORIGIN

  if (origin === undefined || origin === '') {
    return json(
      { error: 'not_configured', message: 'WEBTREES_ORIGIN is not set on this deployment.' },
      503,
    )
  }

  const incoming = new URL(request.url)

  let target: URL

  try {
    target = buildTargetUrl(incoming, origin, env)
  } catch {
    return json(
      {
        error: 'not_configured',
        message: 'WEBTREES_ORIGIN is not a valid URL. Expected something like https://webtrees.example.org',
      },
      503,
    )
  }

  const headers = new Headers(request.headers)

  for (const name of STRIPPED_REQUEST_HEADERS) {
    headers.delete(name)
  }

  // Record where the request actually came from. webtrees builds its own URLs
  // from the `base_url` in config.ini.php rather than from these headers —
  // which is what we want, since its links should point at webtrees — but a
  // reverse proxy that hides the client's origin entirely is unhelpful to
  // anyone reading the server logs.
  headers.set('X-Forwarded-Host', incoming.host)
  headers.set('X-Forwarded-Proto', incoming.protocol.replace(':', ''))

  const secret = env.PORTAL_PROXY_SECRET

  if (secret !== undefined && secret !== '') {
    headers.set('X-Portal-Proxy-Secret', secret)
  }

  const hasBody = request.method !== 'GET' && request.method !== 'HEAD'

  let upstream: Response

  try {
    upstream = await fetch(target.toString(), {
      method: request.method,
      headers,
      body: hasBody ? request.body : null,
      redirect: 'manual',
      // Authenticated responses must never be cached — at the edge or
      // anywhere else. One member seeing another member's relatives out of a
      // cache is a data breach, not a bug.
      cf: { cacheTtl: 0, cacheEverything: false },
    })
  } catch {
    return json({ error: 'server_error', message: 'The family server did not respond.' }, 502)
  }

  const responseHeaders = new Headers(upstream.headers)

  for (const name of STRIPPED_RESPONSE_HEADERS) {
    responseHeaders.delete(name)
  }

  // Belt and braces: the PHP module already sends this on every response.
  responseHeaders.set('Cache-Control', 'private, no-store')

  return new Response(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: responseHeaders,
  })
}

function buildTargetUrl(incoming: URL, origin: string, env: ProxyEnv): URL {
  // Throws on a malformed origin, which the caller turns into a 503 that says
  // so — rather than a confusing failure further downstream.
  const base = new URL(origin)

  // webtrees may be installed in a subdirectory. Without this the prefix is
  // silently dropped, because an absolute path in `new URL(path, base)`
  // replaces the base's path entirely.
  const prefix = base.pathname.replace(/\/+$/, '')

  const ugly = env.WEBTREES_UGLY_URLS === 'true' || env.WEBTREES_UGLY_URLS === '1'

  if (!ugly) {
    return new URL(prefix + incoming.pathname + incoming.search, base)
  }

  const target = new URL(prefix + '/index.php', base)
  target.searchParams.set('route', incoming.pathname)

  for (const [key, value] of incoming.searchParams) {
    if (key !== 'route') {
      target.searchParams.append(key, value)
    }
  }

  return target
}

function json(body: unknown, status: number): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'private, no-store',
    },
  })
}
