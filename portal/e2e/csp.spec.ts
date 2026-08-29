import { expect, test, type Page } from '@playwright/test'
import { CONTENT_SECURITY_POLICY } from '../edge/security'
import { installOfferAnswered, stubApi } from './fixtures'

/**
 * The portal, walked with its own Content-Security-Policy switched on.
 *
 * The policy is written in `edge/security.ts` and sent by the Worker, which
 * this run does not use — `vite preview` serves the same files with no headers
 * at all. So the header is put back on the document here, out of the same
 * constant the Worker sends, and the browser is asked whether it minds.
 *
 * What this catches is the failure that unit tests cannot: a policy that is
 * correct in the abstract and wrong about this app. An inline script from a
 * future dependency, an icon moved to a CDN, a `<style>` written by a library
 * — each is a screen that silently does not work, in production, only.
 */

/** Every refusal the browser made, in the order it made them. */
declare global {
  interface Window {
    __violations?: string[]
  }
}

async function underThePolicy(page: Page): Promise<void> {
  await page.addInitScript(() => {
    window.__violations = []

    document.addEventListener('securitypolicyviolation', (event) => {
      window.__violations?.push(`${event.violatedDirective} blocked ${event.blockedURI}`)
    })
  })

  await page.route('**/*', async (route) => {
    if (route.request().resourceType() !== 'document') {
      return route.fallback()
    }

    const response = await route.fetch()

    await route.fulfill({
      response,
      headers: {
        ...response.headers(),
        'content-security-policy': CONTENT_SECURITY_POLICY,
      },
    })
  })
}

async function violations(page: Page): Promise<string[]> {
  return page.evaluate(() => window.__violations ?? [])
}

test.describe('the portal under its own policy', () => {
  test.beforeEach(async ({ page }) => {
    await underThePolicy(page)
    await installOfferAnswered(page)
    await stubApi(page)
  })

  test('signs in and walks the screens a member uses, with nothing blocked', async ({ page }) => {
    await page.goto('/me')

    // The login screen: the shell, the bundle, the stylesheet, an icon.
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill('anna')
    await page.getByLabel('Passwort').fill('geheim')
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await page.getByRole('heading', { name: 'Mein Profil' }).waitFor()

    // A screen of each kind: a record, the archive, the directory, and the
    // settings — where the QR code is drawn and photographs are loaded.
    await page.goto('/tree')
    await page.getByLabel('Name oder SB-Nr.').fill('Beispiel')
    await page.getByRole('link', { name: /Beispiel/ }).first().waitFor({ timeout: 5000 })

    await page.goto('/contacts')
    await page.getByRole('tab', { name: 'Neu verbinden' }).click()
    await page.getByRole('button', { name: 'Code anzeigen' }).click()
    await page.getByRole('img', { name: /QR-Code/ }).waitFor()

    await page.goto('/settings')
    await page.getByRole('heading', { name: 'Einstellungen' }).waitFor()

    expect(await violations(page)).toEqual([])
  })

  /**
   * The check on the check. A policy nobody is enforcing would let the test
   * above pass while proving nothing, so one thing that *must* be refused is
   * asked for on purpose.
   */
  test('is really being enforced', async ({ page }) => {
    await page.goto('/login')
    await page.getByRole('button', { name: 'Anmelden' }).waitFor()

    await page.evaluate(() => {
      const script = document.createElement('script')

      script.textContent = 'window.__inlineRan = true'
      document.body.append(script)
    })

    expect(await violations(page)).toHaveLength(1)
    expect((await violations(page))[0]).toContain('script-src')
    expect(await page.evaluate(() => '__inlineRan' in window)).toBe(false)
  })
})
