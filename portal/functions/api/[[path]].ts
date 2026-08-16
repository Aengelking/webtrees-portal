import { proxyToWebtrees, type ProxyEnv } from '../../edge/proxy'

/**
 * The Cloudflare *Pages* entry point for the same proxy.
 *
 * This repository deploys to Workers (see wrangler.jsonc), where this file is
 * ignored — Pages Functions are a Pages-only mechanism. It is kept so that a
 * move back to Pages is a configuration change rather than a rewrite, and it
 * shares its implementation with the Worker so the two cannot drift.
 *
 * A Pages deployment also needs `public/_redirects` restored with
 * `/*    /index.html   200` for the SPA fallback. That file is deliberately
 * absent, because the Workers asset validator rejects it as a redirect loop —
 * see NOTES.md.
 */
export const onRequest: PagesFunction<ProxyEnv> = (context) =>
  proxyToWebtrees(context.request, context.env)
