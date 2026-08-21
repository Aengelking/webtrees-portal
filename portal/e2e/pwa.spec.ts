import { expect, test } from '@playwright/test'
import { stubApi } from './fixtures'

/**
 * The portal as an installed app, in a real browser.
 *
 * The unit tests in `sw/strategy.test.ts` prove what the service worker
 * *decides*. This proves what it actually *did*: that after a member has
 * signed in and walked through two screens of family data, the browser's cache
 * storage still contains nothing but the shell.
 *
 * It runs against the production build served by `vite preview` — the same
 * build a deployment uploads, and the only kind where the service worker
 * registers at all.
 */

const REAL_BACKEND = process.env.E2E_BASE_URL !== undefined

const username = process.env.E2E_USERNAME ?? 'anna'
const password = process.env.E2E_PASSWORD ?? 'geheim'

test.beforeEach(async ({ page }) => {
  if (!REAL_BACKEND) {
    await stubApi(page)
  }
})

/** Resolves once a service worker is controlling this page. */
async function controlled(page: import('@playwright/test').Page): Promise<boolean> {
  return page.evaluate(async () => {
    if (!('serviceWorker' in navigator)) {
      return false
    }

    await navigator.serviceWorker.ready

    if (navigator.serviceWorker.controller !== null) {
      return true
    }

    return new Promise<boolean>((resolve) => {
      navigator.serviceWorker.addEventListener('controllerchange', () => resolve(true), {
        once: true,
      })

      setTimeout(() => resolve(navigator.serviceWorker.controller !== null), 5000)
    })
  })
}

/** Every URL the browser is holding on this origin, across every cache. */
async function cachedUrls(page: import('@playwright/test').Page): Promise<string[]> {
  return page.evaluate(async () => {
    const names = await caches.keys()
    const urls: string[] = []

    for (const name of names) {
      const cache = await caches.open(name)

      for (const request of await cache.keys()) {
        urls.push(request.url)
      }
    }

    return urls
  })
}

test.describe('the installed app', () => {
  test('is installable: a manifest, and icons that exist', async ({ page }) => {
    await page.goto('/login')

    const href = await page.locator('link[rel="manifest"]').getAttribute('href')
    expect(href).toBe('/manifest.webmanifest')

    const response = await page.request.get('/manifest.webmanifest')
    expect(response.ok()).toBe(true)

    const manifest = (await response.json()) as {
      name: string
      start_url: string
      display: string
      icons: { src: string }[]
    }

    expect(manifest.display).toBe('standalone')
    expect(manifest.start_url).toBe('/')

    // A manifest pointing at an icon that 404s is a manifest the browser
    // quietly declines to install.
    for (const icon of manifest.icons) {
      expect((await page.request.get(icon.src)).ok(), icon.src).toBe(true)
    }

    // iOS ignores all of the above and wants this one.
    expect((await page.request.get('/icons/apple-touch-icon.png')).ok()).toBe(true)
  })

  /**
   * The assertion this whole file exists for. Sign in, look at a member's
   * record, open the directory — then ask the browser what it kept. The answer
   * has to be: the shell, and not one byte behind /api.
   */
  test('caches the shell and never the family', async ({ page }) => {
    await page.goto('/login')

    expect(await controlled(page)).toBe(true)

    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    await page.getByRole('link', { name: 'Mitglieder' }).click()
    await expect(page.getByRole('heading', { name: 'Mitglieder' })).toBeVisible()

    const urls = await cachedUrls(page)

    expect(urls.length).toBeGreaterThan(0)
    expect(urls.filter((url) => new URL(url).pathname.startsWith('/api/'))).toEqual([])
  })

  /**
   * The offer to install, end to end. Chromium decides for itself whether to
   * fire `beforeinstallprompt`, and a headless browser usually does not, so
   * the event is dispatched by hand — everything after it (the listener, the
   * saved prompt, the button, the browser dialogue being asked for) is the
   * portal's own code doing its real work.
   */
  test('offers to install itself, once, in Settings', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Signs in with the fixture account.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    await page.getByRole('link', { name: 'Einstellungen' }).click()
    await expect(page.getByRole('heading', { name: 'Einstellungen' })).toBeVisible()

    const install = page.getByRole('button', { name: 'Auf den Startbildschirm legen' })

    // Nothing on offer, nothing said.
    await expect(install).toHaveCount(0)

    await page.evaluate(() => {
      const event = new Event('beforeinstallprompt', { cancelable: true })

      Object.assign(event, {
        prompt: () => {
          Object.assign(window, { __installPrompted: true })
          return Promise.resolve()
        },
      })

      window.dispatchEvent(event)
    })

    await expect(install).toBeVisible()
    await install.click()

    expect(await page.evaluate(() => (window as { __installPrompted?: boolean }).__installPrompted)).toBe(
      true,
    )

    // Spent: a second `prompt()` on the same event throws, so the offer goes
    // rather than becoming a button that does nothing.
    await expect(install).toHaveCount(0)
  })

  /**
   * What a home-screen icon is for: with the network switched off the portal
   * still opens, from its own cache, instead of handing the member to the
   * browser's error page. Nothing here is signed in — the shell is all that is
   * cached, and the shell knows nobody.
   *
   * It asserts the *shell*, not the offline bar. Whether `navigator.onLine`
   * follows a browser's offline emulation is the browser's business and it
   * varies between Chromium builds — asserting it here tests Playwright rather
   * than the portal, and did exactly that on CI while passing locally. What
   * the bar does with what the browser reports is settled three times over in
   * `src/Pwa.test.tsx`, where the browser is not in the way.
   */
  test('opens without a connection', async ({ page, context }) => {
    await page.goto('/login')
    expect(await controlled(page)).toBe(true)

    await context.setOffline(true)

    try {
      await page.reload()

      // Polled rather than read once — the shell renders "Wird geladen …"
      // first, while the sign-in check it fires on start is still failing —
      // and read as one object so that a failure prints the page's actual
      // state. A locator that simply went unfound says nothing about why, and
      // on CI there is no browser to open and look at.
      await expect
        .poll(
          async () =>
            page.evaluate(() => ({
              controlled: navigator.serviceWorker.controller !== null,
              online: navigator.onLine,
              // Long enough to reach the sign-in button past whatever the
              // offline bar has put in front of it.
              text: document.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 400),
            })),
          { message: 'the portal, reloaded with the network off' },
        )
        // The whole app is running: the document, the script and the
        // stylesheet all came from somewhere, and offline there is nowhere
        // else they could have come from.
        .toMatchObject({ controlled: true, text: expect.stringContaining('Anmelden') })
    } finally {
      await context.setOffline(false)
    }
  })
})
