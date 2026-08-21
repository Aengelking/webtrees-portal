/**
 * The portal's service worker.
 *
 * It exists for one reason: an installed app has to survive being opened. A
 * home-screen icon that leads to a browser error page when the train goes into
 * a tunnel is worse than a bookmark, and only a service worker can put
 * something better there.
 *
 * It does not exist to make the portal work offline, because it cannot: every
 * screen is a question about living people that only the server can answer,
 * and the answers may not be kept (see `strategy.ts`). What is cached here is
 * the shell — the HTML, the JavaScript, the stylesheet, the icons — none of
 * which knows anything about anybody.
 *
 * Two strategies, and the split is the whole design:
 *
 *   documents — network first, cached shell as the fallback. A deployment
 *     replaces every hashed asset, so a page served from cache could ask for
 *     files that no longer exist. Online, the network always decides; the
 *     cached copy is what a tunnel gets.
 *   assets — cache first. Their URLs contain a content hash, so an entry can
 *     never be stale: a changed file is a different URL.
 *
 * Everything else — the API, other origins, anything that is not a GET — is
 * not answered here at all. Returning without calling `respondWith` leaves the
 * request exactly as it would have been with no service worker installed.
 */

import { assetsIn, handlingFor, mayStoreAsset } from './strategy'

/**
 * `self` is typed as a plain worker by the standard library. Narrowing it once
 * here is the usual way to get the service worker's own globals — `clients`,
 * `skipWaiting`, `registration.scope` — without redeclaring `self` itself.
 */
const worker = self as unknown as ServiceWorkerGlobalScope

/**
 * Stamped in at build time by `vite.sw.config.ts`, so that every deployment
 * gets a cache of its own — and, on activation, throws the previous one away.
 * That is what stops old hashed assets accumulating forever: there is no list
 * of what is still referenced, so the only honest answer is to start again.
 */
declare const __SW_BUILD__: string

const CACHE = `portal-shell-${__SW_BUILD__}`

/** The one document ever stored. Every navigation falls back to this entry. */
const SHELL = '/'

/**
 * Match on the URL and nothing else.
 *
 * Without this the cache is very nearly useless here, and in a way that only
 * shows up offline. Vite marks its script and stylesheet `crossorigin`, so the
 * browser fetches them as CORS requests and sends an `Origin` header; a static
 * server that answers those with `Vary: Origin` — `vite preview` does, and so
 * do plenty of real ones — makes every stored entry match only a request whose
 * `Origin` is identical to the one that stored it. The service worker's own
 * `cache.add` sends no `Origin` at all, so nothing it precached would ever be
 * found again, and a portal opened in a tunnel would be a blank page with a
 * title.
 *
 * Ignoring `Vary` is right rather than merely convenient: these URLs contain a
 * content hash, so the URL *is* the identity of the file. There is no second
 * variant to pick the wrong one of.
 */
const BY_URL = { ignoreVary: true } as const

worker.addEventListener('install', (event) => {
  event.waitUntil(
    precache()
      // A shell that could not be fetched is not a reason to refuse to
      // install. The next navigation fills it in.
      .catch(() => undefined)
      .then(() => worker.skipWaiting()),
  )
})

worker.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((names) =>
        Promise.all(names.filter((name) => name !== CACHE).map((name) => caches.delete(name))),
      )
      // Taking over the open pages immediately is safe here in a way it is not
      // in most apps: nothing cached is version-specific except by URL, and
      // documents come from the network anyway. It is also what makes a fixed
      // service worker — or one deployed to switch this off — take effect at
      // once rather than whenever the last tab is closed.
      .then(() => worker.clients.claim()),
  )
})

worker.addEventListener('fetch', (event) => {
  const handling = handlingFor(event.request, worker.registration.scope)

  if (handling === 'bypass') {
    return
  }

  event.respondWith(handling === 'document' ? page(event.request) : asset(event.request))
})

/**
 * Store the shell *and the files it loads*.
 *
 * Caching index.html alone is the mistake that makes a portal look installed
 * and open blank on the first flight: the document is there, the hashed script
 * it asks for is not, and nothing on the device can go and get it. The
 * document names them, so the document is where the list comes from.
 */
async function precache(): Promise<void> {
  const cache = await caches.open(CACHE)

  // `cache: 'reload'` so the shell comes from the server rather than from
  // whatever the browser's own HTTP cache happens to be holding.
  const response = await fetch(new Request(SHELL, { cache: 'reload' }))

  if (response.status !== 200) {
    return
  }

  const html = await response.clone().text()

  await cache.put(SHELL, response)

  // Not `addAll`, which is all-or-nothing: one asset that 404s would throw the
  // shell away with it. The assets are hashed, so the browser's own cache
  // almost certainly has them and this costs close to nothing.
  await Promise.all(
    assetsIn(html).map((path) => cache.add(path).catch(() => undefined)),
  )
}

async function page(request: Request): Promise<Response> {
  try {
    const response = await fetch(request)

    // Keyed on the shell rather than on the path that was asked for: /me,
    // /members and /messages are all the same file, and one entry that is
    // always the newest beats three that each go stale separately.
    //
    // A redirected response is deliberately not stored — the browser refuses
    // to serve one back for a navigation, which would strand the fallback.
    if (response.status === 200 && !response.redirected) {
      const cache = await caches.open(CACHE)
      await cache.put(SHELL, response.clone())
    }

    return response
  } catch {
    const cached = await caches.match(SHELL, { cacheName: CACHE, ...BY_URL })

    return cached ?? offline()
  }
}

async function asset(request: Request): Promise<Response> {
  const cache = await caches.open(CACHE)
  const cached = await cache.match(request, BY_URL)

  if (cached !== undefined) {
    return cached
  }

  const response = await fetch(request)

  if (mayStoreAsset(response)) {
    await cache.put(request, response.clone())
  }

  return response
}

/**
 * The last resort: offline, and the shell was never cached — a portal opened
 * for the first time with no connection. Deliberately a single self-contained
 * file with no request of its own to make.
 *
 * German, then English, because the service worker cannot see the language the
 * member chose: that lives in localStorage, which is a window's, not a
 * worker's. German is the portal's default for the same reason it is
 * everywhere else.
 */
function offline(): Response {
  const html = `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Keine Verbindung</title>
<style>
body{margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;background:#f1f5f9;color:#0f172a;font:1rem/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
main{max-width:24rem;padding:2rem;text-align:center}
h1{font-size:1.375rem;margin:0 0 .75rem}
p{margin:0 0 1rem}
p.en{color:#475569}
</style>
</head>
<body>
<main>
<h1>Keine Verbindung</h1>
<p>Die Sack Familienapp braucht eine Internetverbindung. Bitte versuchen Sie es noch einmal, sobald Sie wieder online sind.</p>
<p class="en" lang="en">The Sack family app needs an internet connection. Please try again once you are back online.</p>
</main>
</body>
</html>
`

  return new Response(html, {
    status: 503,
    headers: {
      'Content-Type': 'text/html; charset=utf-8',
      'Cache-Control': 'no-store',
    },
  })
}
