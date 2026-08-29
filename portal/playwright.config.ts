import { defineConfig, devices } from '@playwright/test'

/**
 * The smoke path: sign in, look at my own record, find someone in the
 * directory.
 *
 * By default it runs against a production build of the SPA with the API
 * stubbed in the browser, so it can run anywhere without a webtrees host.
 * Point E2E_BASE_URL at a deployment (or a `vite dev` with VITE_API_TARGET
 * set) to run the same path against a real backend; the stubs then step
 * aside — see e2e/smoke.spec.ts.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:4173'

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: process.env.CI === 'true',
  retries: process.env.CI === 'true' ? 1 : 0,
  reporter: process.env.CI === 'true' ? 'line' : 'list',
  // The screenshot spec (`e2e/screenshots.spec.ts`) asserts nothing — it takes
  // pictures of the portal for the family magazine — so it stays out of the
  // ordinary run, where it would only make the suite slower. Take them with:
  //
  //     PORTAL_SCREENSHOTS=1 npx playwright test e2e/screenshots.spec.ts
  grepInvert: process.env.PORTAL_SCREENSHOTS === '1' ? undefined : /@screenshots/,
  use: {
    baseURL,
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'mobile',
      // The portal is designed to be used one-handed on a phone, so that is
      // what the smoke path runs on.
      use: {
        ...devices['Pixel 7'],
        // Escape hatch for environments that already have a Chromium and do
        // not want `npx playwright install` to fetch another one.
        ...(process.env.PLAYWRIGHT_CHROMIUM_PATH === undefined
          ? {}
          : { launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH } }),
      },
    },
  ],
  ...(process.env.E2E_BASE_URL === undefined
    ? {
        webServer: {
          // Serve only — `npm run test:e2e` builds first. Keeping the build
          // out of here means a broken build is reported as a broken build,
          // rather than as a web server that never became ready.
          //
          // --host 127.0.0.1 is not decoration. `vite preview` otherwise binds
          // to `localhost`, which on a CI runner can resolve to ::1 alone
          // while Playwright polls 127.0.0.1 — the server comes up, nothing
          // ever answers on the address being watched, and the wait burns the
          // whole timeout with no useful error.
          command: 'npm run preview:e2e',
          url: baseURL,
          reuseExistingServer: process.env.CI !== 'true',
          timeout: 60_000,
          // Let vite's output reach the log. Without this a failure to start
          // is silent, and all you get is the timeout.
          stdout: 'pipe',
          stderr: 'pipe',
        },
      }
    : {}),
})
