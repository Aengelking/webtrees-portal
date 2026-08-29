import { test } from '@playwright/test'
import { installOfferAnswered, stubApi } from './fixtures'

/**
 * Pictures of the portal for the family magazine.
 *
 * Taken at the viewport rather than `fullPage`, on the phone the whole portal
 * is designed for: a magazine wants something that looks like a telephone, not
 * a five-thousand-pixel ribbon with the navigation bar stranded in the middle
 * of it.
 *
 * Not a test — nothing is asserted — but it belongs here rather than in a
 * script of its own, because the only honest way to photograph this portal is
 * to drive the real build with the same stubbed API the smoke path uses. The
 * people on these screens are the fixture's Beispiels; no member's record and
 * no living relative's data goes to a printer.
 *
 * Run it deliberately:
 *
 *     PORTAL_SCREENSHOTS=1 npx playwright test e2e/screenshots.spec.ts
 *
 * The `@screenshots` tag keeps it out of the ordinary run (see
 * `grepInvert` in playwright.config.ts), where a spec that asserts nothing
 * would only be a way of making the suite slower.
 */

const SHOTS = 'screenshots'

test.describe('@screenshots', () => {
  test.beforeEach(async ({ page }) => {
    await installOfferAnswered(page)
    await stubApi(page)
  })

  async function signIn(page: import('@playwright/test').Page) {
    await page.goto('/me')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill('anna')
    await page.getByLabel('Passwort').fill('geheim')
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await page.getByRole('heading', { name: 'Mein Profil' }).waitFor()
  }

  test('the way in', async ({ page }) => {
    await page.goto('/login')
    await page.getByRole('button', { name: 'Anmelden' }).waitFor()
    await page.screenshot({ path: `${SHOTS}/1-anmelden.png` })

    await page.goto('/zugang')
    await page.getByRole('button', { name: 'Antrag absenden' }).waitFor()
    await page.screenshot({ path: `${SHOTS}/2-zugang-beantragen.png` })
  })

  test('my own record', async ({ page }) => {
    await signIn(page)
    await page.screenshot({ path: `${SHOTS}/3-mein-profil.png` })
  })

  test('a person, and the family around them', async ({ page }) => {
    await signIn(page)
    // A relative's page rather than Anna's own, and reached the way a member
    // reaches it: by tapping somebody in their own family.
    await page.getByRole('link', { name: /Dieter Beispiel/ }).first().click()
    await page.getByRole('heading', { name: 'Dieter Beispiel' }).waitFor()
    await page.screenshot({ path: `${SHOTS}/4-person.png` })
  })

  test('the archive, searched', async ({ page }) => {
    await signIn(page)
    await page.goto('/tree')
    await page.getByRole('heading', { name: 'Stammbaum' }).waitFor()

    // With something in it. An empty search box says what the screen is for;
    // a page of results says what it does.
    await page.getByLabel('Name oder SB-Nr.').fill('Beispiel')

    // The search is debounced, so wait for a result rather than for the field.
    await page.getByRole('link', { name: /Beispiel/ }).first().waitFor({ timeout: 5000 })
    await page.screenshot({ path: `${SHOTS}/5-stammbaum.png` })
  })

  test('the members', async ({ page }) => {
    await signIn(page)
    await page.goto('/contacts')
    await page.waitForTimeout(500)
    await page.screenshot({ path: `${SHOTS}/6-kontakte.png` })
  })

  test('the settings', async ({ page }) => {
    await signIn(page)
    await page.goto('/settings')
    await page.waitForTimeout(500)
    await page.screenshot({ path: `${SHOTS}/7-einstellungen.png` })
  })
})
