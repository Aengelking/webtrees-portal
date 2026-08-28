import {
  isApiRequest,
  isOAuthDiscoveryRequest,
  noOAuthHere,
  proxyToWebtrees,
  type ProxyEnv,
} from './proxy'

/**
 * The Cloudflare Worker that serves the portal.
 *
 * Two jobs, and the order matters:
 *
 *   /api/*  is proxied to the webtrees host, so the browser only ever talks
 *           to one origin and the session cookie stays first-party;
 *   anything else is a static asset, with `not_found_handling:
 *           single-page-application` in wrangler.jsonc turning an unmatched
 *           path into index.html so the client-side router can take it.
 *
 * That SPA fallback is why `run_worker_first` is set in wrangler.jsonc: without
 * it the asset layer answers *before this Worker runs*, so a path missing from
 * that list cannot be handled here at all. `/api/*` is the obvious entry —
 * every API call would otherwise quietly return HTML. `/.well-known/*` is the
 * one that was forgotten once, which made the OAuth answer below live and dead
 * at the same time.
 */

interface Env extends ProxyEnv {
  ASSETS: Fetcher
}

export default {
  async fetch(request, env): Promise<Response> {
    const url = new URL(request.url)

    if (isApiRequest(url)) {
      return proxyToWebtrees(request, env)
    }

    // Answered here rather than left to the asset layer, which would hand an
    // MCP client the portal's own index.html with a 200 on it. See
    // `noOAuthHere` in edge/proxy.ts for what that cost.
    if (isOAuthDiscoveryRequest(url)) {
      return noOAuthHere()
    }

    return env.ASSETS.fetch(request)
  },
} satisfies ExportedHandler<Env>
