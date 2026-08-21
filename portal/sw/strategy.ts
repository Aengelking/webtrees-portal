/**
 * What the service worker does with a request — and, far more importantly,
 * what it refuses to do with one.
 *
 * A service worker in front of a portal like this one is mostly a liability.
 * Everything on screen is living people's personal data: names, dates,
 * addresses, photographs. The React Query client is configured to keep none of
 * it (`gcTime: 0`), the PHP module marks every answer `private, no-store`, and
 * the Worker in `edge/proxy.ts` refuses to let the word `public` past. A cache
 * in the browser that quietly kept the same answers would undo all three — on
 * the one device most likely to be handed to somebody else.
 *
 * So the rule this file exists to state is: **the shell may be cached, the
 * data may never be.** These are pure functions with no `caches` and no
 * `fetch` in sight, so that rule is testable without a service worker.
 */

/**
 * Everything the portal knows lives behind /api. Nothing under it is ever
 * stored, or even intercepted.
 *
 * `edge/proxy.ts` decides the same thing for the Worker and the two are
 * deliberately not shared — they are typechecked against different libraries,
 * and one import would drag Cloudflare's types into a service worker. If they
 * ever drift, this one must be the broader of the pair: bypassing something
 * cacheable costs a round trip, caching something private is a breach.
 */
export function isApiPath(pathname: string): boolean {
  return pathname === '/api' || pathname.startsWith('/api/')
}

export type Handling =
  /** Not our business. The browser does exactly what it would with no service worker at all. */
  | 'bypass'
  /** A page load: the network decides, and the cached shell is the fallback. */
  | 'document'
  /** A script, stylesheet, font or icon: the cache decides. */
  | 'asset'

/**
 * A fetch event's request, reduced to the three things the decision uses.
 *
 * Not a `Request`, on purpose: `new Request(url, { mode: 'navigate' })` throws
 * by specification, so a test could not otherwise construct the case that
 * matters most.
 */
export interface RoutedRequest {
  readonly method: string
  readonly mode: string
  readonly url: string
}

export function handlingFor(request: RoutedRequest, scope: string): Handling {
  // A write is never answered from a cache and never put in one. Sending a
  // message is not a thing that has a stale version.
  if (request.method !== 'GET') {
    return 'bypass'
  }

  let url: URL

  try {
    url = new URL(request.url)
  } catch {
    return 'bypass'
  }

  // Another origin's response is opaque to us — we cannot see whether it was
  // an error, and storing one is storing something we cannot inspect.
  if (url.origin !== new URL(scope).origin) {
    return 'bypass'
  }

  if (isApiPath(url.pathname)) {
    return 'bypass'
  }

  return request.mode === 'navigate' ? 'document' : 'asset'
}

/** The parts of a response the storage decision looks at. */
export type StorableResponse = Pick<Response, 'status' | 'type' | 'headers' | 'redirected'>

/**
 * Whether an asset response is safe to keep.
 *
 * The html check is the one that is not obvious. Both deployment targets
 * answer an unknown path with `index.html` so the client-side router can take
 * it — which means that after a deployment, a request for a hashed asset that
 * no longer exists comes back **200, with the app's HTML in it**. Cached under
 * a `.js` URL, that is a portal that will not start and will not repair itself
 * until the cache is cleared by hand. A response that claims to be a page is
 * never an asset.
 */
export function mayStoreAsset(response: StorableResponse): boolean {
  // Only a plain, complete, first-hand 200. Not a 206, not a redirect, and
  // not an opaque response we cannot see inside.
  if (response.status !== 200 || response.redirected) {
    return false
  }

  if (response.type === 'opaque' || response.type === 'opaqueredirect') {
    return false
  }

  // If whatever produced this said not to store it, that is the end of it.
  // Nothing outside /api says so today; the rule holds if that ever changes.
  if (/\bno-store\b/i.test(response.headers.get('cache-control') ?? '')) {
    return false
  }

  return !/^text\/html\b/i.test(response.headers.get('content-type') ?? '')
}

/**
 * The files the shell loads, read out of the shell itself.
 *
 * An HTML file in a cache is not an app: the script and the stylesheet beside
 * it have hashed names that only the build knows, and without them a portal
 * opened offline is a blank page. Rather than have the build write a list —
 * one more generated file to keep honest — the service worker reads the one
 * authoritative list there is, which is the document's own markup.
 *
 * Only root-relative paths, which is what Vite emits for everything it owns.
 * Anything else is somebody else's to fetch.
 */
export function assetsIn(html: string): string[] {
  const found = new Set<string>()

  for (const match of html.matchAll(/<(?:script|link)\b[^>]*?\b(?:src|href)="(\/[^"]*)"/gi)) {
    const path = match[1]

    if (path !== undefined) {
      found.add(path)
    }
  }

  return [...found]
}
