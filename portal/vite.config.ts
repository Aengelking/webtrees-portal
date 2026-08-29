import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// In development the portal runs on localhost while the API runs on a
// webtrees host. Vite proxies /api onto it so that the browser still sees a
// single origin — the same arrangement the Cloudflare Pages Function creates
// in production, and the reason no CORS handling exists in the PHP module.
const apiTarget = process.env.VITE_API_TARGET ?? 'http://localhost:8080'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    // Off because the polyfill is injected as an **inline script**, and the
    // portal's Content-Security-Policy allows scripts from this origin only
    // (see edge/security.ts). Left on, a build that produced more than one
    // chunk would ship a page that refuses to run its own first line — on
    // exactly the older browsers the polyfill exists to help.
    //
    // Nothing is lost on a browser that understands `modulepreload`, and a
    // browser that does not simply loads the modules a moment later.
    modulePreload: { polyfill: false },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: apiTarget,
        changeOrigin: true,
        // webtrees sets its session cookie for its own host; rewrite the
        // domain so the browser keeps it for localhost.
        cookieDomainRewrite: '',
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    // edge/ and sw/ too: the proxy decides what a CDN may keep and the service
    // worker decides what a phone may keep, which are both worth a test.
    include: ['src/**/*.test.{ts,tsx}', 'edge/**/*.test.ts', 'sw/**/*.test.ts'],
    globals: true,
    // Longer than the `asyncUtilTimeout` in `src/test/setup.ts`, and that
    // relationship is the whole point of the number.
    //
    // Testing Library was given five seconds there to wait for a screen to
    // settle, with a good argument: a shared CI core is slower than a laptop.
    // But vitest's own default is also five seconds, for the *whole test* —
    // so the permission to wait longer was never real. A `findBy…` that took
    // four seconds and would have succeeded killed the test at five instead,
    // and it did so in whichever file happened to draw the slowest worker,
    // which is why this looked like several unrelated flaky tests rather than
    // one setting. See NOTES §2.90.
    //
    // A test that is genuinely stuck still fails; it now says so with the
    // assertion that failed rather than with an opaque timeout.
    testTimeout: 20_000,
  },
})
