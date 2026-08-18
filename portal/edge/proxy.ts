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
   * Whether to address webtrees as /index.php?route=/api/v1/csrf rather than
   * /api/v1/csrf. **Defaults to true**, because that is what a stock webtrees
   * needs.
   *
   * webtrees ships no rewrite rules — the only .htaccess in the distribution
   * is a deny-all for data/ — and `rewrite_urls` in config.ini.php is off by
   * default. So on an ordinary install nothing maps a path like /api/v1/csrf
   * onto index.php: the webserver looks for a file of that name, does not find
   * one, and answers its own 404 without PHP ever running.
   *
   * Set to "false" only if URL rewriting is configured on the server *and*
   * `rewrite_urls="1"` is set in config.ini.php. Getting this backwards is
   * visible either way: with it wrongly false you get a bare webserver 404,
   * and with it wrongly true webtrees answers a 308 redirect to the pretty
   * form — which points at the webtrees host and so leaves the portal's
   * origin, taking the session cookie out of scope.
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

  responseHeaders.set('Cache-Control', privateCacheControl(upstream.headers.get('cache-control')))

  rewriteSetCookies(upstream.headers, responseHeaders)

  return new Response(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: responseHeaders,
  })
}

/**
 * Never let anything but the member's own browser keep a response.
 *
 * The module marks photographs `private, max-age=...` — a browser may keep
 * them, a shared cache may not — and everything else `private, no-store`. This
 * passes a `private` directive through and replaces anything else, so the
 * decision stays with the module and the worst case is that a response is not
 * cached at all.
 *
 * The reason this is a filter and not a passthrough: webtrees answers media
 * requests with `public, max-age=31536000`. Reasonable on a site serving its
 * own pages; here, `public` means a Cloudflare edge could hold one member's
 * photograph and hand it to the next member who asks for that URL. The word
 * never gets past this function.
 */
function privateCacheControl(upstream: string | null): string {
  if (upstream !== null && /^private\b/i.test(upstream.trim()) && !/\bpublic\b/i.test(upstream)) {
    return upstream
  }

  return 'private, no-store'
}

/**
 * Re-scope webtrees' cookies to the portal's own origin.
 *
 * webtrees sets its session cookie with `Domain=` and `Path=` taken from the
 * `base_url` in its config.ini.php — so `Domain=webtrees.example.org`. A
 * browser on the portal's origin *rejects* that outright: a response may not
 * set a cookie for an unrelated domain. The failure is quiet and confusing —
 * login returns 200 with a valid body, the cookie is dropped on the floor, and
 * the next request is 401, so the member is bounced back to the login screen
 * having apparently just signed in successfully.
 *
 * Dropping `Domain` makes it a host-only cookie for whatever origin the portal
 * is served from, which is exactly right. `Path` is forced to `/` because
 * webtrees' path is its own install directory, which would not match /api/v1.
 *
 * Everything else — HttpOnly, Secure, SameSite — is passed through untouched.
 */
function rewriteSetCookies(from: Headers, to: Headers): void {
  const cookies =
    typeof from.getSetCookie === 'function'
      ? from.getSetCookie()
      : [from.get('set-cookie')].filter((value): value is string => value !== null)

  if (cookies.length === 0) {
    return
  }

  to.delete('set-cookie')

  for (const cookie of cookies) {
    to.append('set-cookie', rescopeCookie(cookie))
  }
}

function rescopeCookie(cookie: string): string {
  const [nameValue, ...attributes] = cookie.split(';')

  const kept = attributes.filter((attribute) => {
    const name = attribute.trim().split('=')[0]?.toLowerCase()

    return name !== 'domain' && name !== 'path'
  })

  return [nameValue, ...kept, ' Path=/'].join(';')
}

function buildTargetUrl(incoming: URL, origin: string, env: ProxyEnv): URL {
  // Throws on a malformed origin, which the caller turns into a 503 that says
  // so — rather than a confusing failure further downstream.
  const base = new URL(origin)

  // webtrees may be installed in a subdirectory. Without this the prefix is
  // silently dropped, because an absolute path in `new URL(path, base)`
  // replaces the base's path entirely.
  const prefix = base.pathname.replace(/\/+$/, '')

  // Defaults to ugly: that is what an unconfigured webtrees understands.
  const pretty = env.WEBTREES_UGLY_URLS === 'false' || env.WEBTREES_UGLY_URLS === '0'

  if (pretty) {
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
