/**
 * The headers this origin sends on everything it serves.
 *
 * The PHP module already hardens its own answers — `private, no-store`,
 * `nosniff`, `noindex` — but those are headers about *that* response. Two
 * things can only be said by whoever answers on the portal's own hostname,
 * because they are statements about the origin as a whole: that it is only
 * ever to be reached over HTTPS, and what a page served from it is allowed to
 * load. Until now nothing said either, and the SPA — the part a member
 * actually has open — went out bare.
 */

/**
 * A year, and the subdomains too.
 *
 * The portal is served from one hostname over TLS and nothing else, so there
 * is no plaintext deployment to strand. `includeSubDomains` is the deliberate
 * part: without it, a browser can still be walked onto `http://anything.<the
 * portal's domain>` and have the session cookie asked for there — the cookie
 * is host-only (see `rescopeCookie`), but the invitation token in a URL is
 * not, and neither is a member's willingness to type a password into
 * something that looks right.
 *
 * No `preload`. That is a submission to a list shipped inside browsers, it
 * covers the whole domain rather than this host, and it is not this deploy's
 * to make on behalf of every other name the family may use.
 */
export const HSTS = 'max-age=31536000; includeSubDomains'

/**
 * What a page from this origin may load.
 *
 * Everything the portal needs is its own: one script bundle, one stylesheet,
 * its icons, and an API on the same hostname. Nothing is fetched from a CDN —
 * deliberately, and in more than one place (see `QrCode` in the module, which
 * draws its own rather than asking an image service). So the policy can be the
 * short, strict one, and any future dependency on a third party has to be
 * argued for here rather than added quietly.
 *
 * `'unsafe-inline'` is granted to **styles only**, and it is not a formality:
 * three components size or move an element from JavaScript — a photograph's
 * box, the pull-to-refresh arrow — and a style attribute is what React writes
 * for that. `style-src-attr` would be the narrower grant, but Safari only
 * learned it in 15.4 and this portal is read on old phones. An inline style
 * cannot run code; an inline script can, and `script-src 'self'` says no.
 *
 * That last one has a build-time consequence worth knowing about:
 * `modulePreload.polyfill` is off in vite.config.ts, because the polyfill is
 * injected as an inline script and would be blocked here — a portal that will
 * not start, on exactly the browsers the polyfill exists for.
 */
export const CONTENT_SECURITY_POLICY = [
  "default-src 'self'",
  "script-src 'self'",
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self'",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join('; ')

/**
 * The policy for an answer that is data rather than a page.
 *
 * A JSON body is not a document and loads nothing, so the page policy would
 * only be noise — with one exception that is not noise at all: nothing may
 * put this origin's answers in a frame. Everything else is left alone,
 * because `default-src 'none'` on a media response breaks the ordinary act of
 * opening a photograph in its own tab, where the image *is* the document.
 */
export const API_CONTENT_SECURITY_POLICY = "frame-ancestors 'none'"

/**
 * Add them to a response that has already been built.
 *
 * A new `Response` rather than a mutation: what comes back from the asset
 * binding, and from `fetch`, has immutable headers.
 *
 * `secure` is the caller's answer to "was this asked for over HTTPS?", and it
 * decides HSTS alone. A browser ignores the header on a plaintext request
 * anyway; not sending it keeps `wrangler dev` on http://localhost from
 * looking like it is trying to.
 */
export function harden(response: Response, policy: string, secure: boolean): Response {
  const headers = new Headers(response.headers)

  headers.set('Content-Security-Policy', policy)

  if (secure) {
    headers.set('Strict-Transport-Security', HSTS)
  }

  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers,
  })
}

/** Whether the request that produced a response arrived over TLS. */
export function overHttps(url: URL): boolean {
  return url.protocol === 'https:'
}
