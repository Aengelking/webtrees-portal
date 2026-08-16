/**
 * Reverse proxy for /api/* onto the webtrees host.
 *
 * This is the piece that makes everything else simple. Because the browser
 * only ever talks to the Pages origin, the session cookie is a first-party
 * cookie: no CORS handling in the PHP module, no origin allow-list, and no
 * `SameSite=None`.
 *
 * Environment:
 *   WEBTREES_ORIGIN       required, e.g. https://webtrees.example.org
 *   PORTAL_PROXY_SECRET   optional; sent as X-Portal-Proxy-Secret so the API
 *                         can reject traffic that did not come through here.
 */

interface Env {
  WEBTREES_ORIGIN?: string
  PORTAL_PROXY_SECRET?: string
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

export const onRequest: PagesFunction<Env> = async (context) => {
  const origin = context.env.WEBTREES_ORIGIN

  if (origin === undefined || origin === '') {
    return json(
      { error: 'not_configured', message: 'WEBTREES_ORIGIN is not set on this deployment.' },
      503,
    )
  }

  const incoming = new URL(context.request.url)
  const target = new URL(incoming.pathname + incoming.search, origin)

  const headers = new Headers(context.request.headers)

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

  const secret = context.env.PORTAL_PROXY_SECRET

  if (secret !== undefined && secret !== '') {
    headers.set('X-Portal-Proxy-Secret', secret)
  }

  let upstream: Response

  try {
    upstream = await fetch(target.toString(), {
      method: context.request.method,
      headers,
      body:
        context.request.method === 'GET' || context.request.method === 'HEAD'
          ? null
          : context.request.body,
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

function json(body: unknown, status: number): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'private, no-store',
    },
  })
}
