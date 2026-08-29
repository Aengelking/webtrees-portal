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
 * And in English, into `screenshots/en/`:
 *
 *     PORTAL_SCREENSHOTS=1 PORTAL_SCREENSHOTS_LANGUAGE=en \
 *       npx playwright test e2e/screenshots.spec.ts
 *
 * The `@screenshots` tag keeps it out of the ordinary run (see
 * `grepInvert` in playwright.config.ts), where a spec that asserts nothing
 * would only be a way of making the suite slower.
 */

const LANGUAGE = process.env.PORTAL_SCREENSHOTS_LANGUAGE === 'en' ? 'en' : 'de'
const SHOTS = LANGUAGE === 'en' ? 'screenshots/en' : 'screenshots'

/**
 * File names in the language of the pictures, so that whoever lays out the
 * English edition is not handed seven German words. The number is the order
 * they are meant to be printed in, and pairs the two sets up.
 */
const NAMES = {
  de: {
    login: '1-anmelden',
    request: '2-zugang-beantragen',
    profile: '3-mein-profil',
    person: '4-person',
    tree: '5-stammbaum',
    contacts: '6-kontakte',
    settings: '7-einstellungen',
  },
  en: {
    login: '1-sign-in',
    request: '2-ask-for-access',
    profile: '3-my-profile',
    person: '4-a-person',
    tree: '5-family-tree',
    contacts: '6-contacts',
    settings: '7-settings',
  },
}[LANGUAGE]

/** What each screen is called, in the language being photographed. */
const WORDS = {
  de: {
    signIn: 'Anmelden',
    username: 'Benutzername oder E-Mail-Adresse',
    password: 'Passwort',
    myProfile: 'Mein Profil',
    request: 'Antrag absenden',
    tree: 'Stammbaum',
    search: 'Name oder SB-Nr.',
    brother: /Dieter Beispiel/,
  },
  en: {
    signIn: 'Sign in',
    username: 'Username or email address',
    password: 'Password',
    myProfile: 'My profile',
    request: 'Send the request',
    tree: 'Family tree',
    search: 'Name or archive number',
    brother: /Dieter Beispiel/,
  },
}[LANGUAGE]

test.describe('@screenshots', () => {
  test.beforeEach(async ({ page }) => {
    await installOfferAnswered(page)
    await stubApi(page, { language: LANGUAGE })

    // What the portal reads in before it knows who is reading — the login
    // screen and the request form, which are reached from a magazine by
    // somebody with no account at all.
    await page.addInitScript((code) => {
      try {
        window.localStorage.setItem('portal.language', code)
      } catch {
        // A browser with no storage reads German. Nothing to do here.
      }
    }, LANGUAGE)
  })

  async function signIn(page: import('@playwright/test').Page) {
    await page.goto('/me')
    await page.getByLabel(WORDS.username).fill('anna')
    await page.getByLabel(WORDS.password).fill('geheim')
    await page.getByRole('button', { name: WORDS.signIn }).click()
    await page.getByRole('heading', { name: WORDS.myProfile }).waitFor()
  }

  test('the way in', async ({ page }) => {
    await page.goto('/login')
    await page.getByRole('button', { name: WORDS.signIn }).waitFor()
    await page.screenshot({ path: `${SHOTS}/${NAMES.login}.png` })

    await page.goto('/zugang')
    await page.getByRole('button', { name: WORDS.request }).waitFor()
    await page.screenshot({ path: `${SHOTS}/${NAMES.request}.png` })
  })

  test('my own record', async ({ page }) => {
    await signIn(page)
    await page.screenshot({ path: `${SHOTS}/${NAMES.profile}.png` })
  })

  test('a person, and the family around them', async ({ page }) => {
    await signIn(page)
    // A relative's page rather than Anna's own, and reached the way a member
    // reaches it: by tapping somebody in their own family.
    await page.getByRole('link', { name: WORDS.brother }).first().click()
    await page.getByRole('heading', { name: 'Dieter Beispiel' }).waitFor()
    await page.screenshot({ path: `${SHOTS}/${NAMES.person}.png` })
  })

  test('the archive, searched', async ({ page }) => {
    await signIn(page)
    await page.goto('/tree')
    await page.getByRole('heading', { name: WORDS.tree }).waitFor()

    // With something in it. An empty search box says what the screen is for;
    // a page of results says what it does.
    await page.getByLabel(WORDS.search).fill('Beispiel')

    // The search is debounced, so wait for a result rather than for the field.
    await page.getByRole('link', { name: /Beispiel/ }).first().waitFor({ timeout: 5000 })
    await page.screenshot({ path: `${SHOTS}/${NAMES.tree}.png` })
  })

  test('the members', async ({ page }) => {
    await signIn(page)
    await page.goto('/contacts')
    await page.waitForTimeout(500)
    await page.screenshot({ path: `${SHOTS}/${NAMES.contacts}.png` })
  })

  test('the settings', async ({ page }) => {
    await signIn(page)
    await page.goto('/settings')
    await page.waitForTimeout(500)
    await page.screenshot({ path: `${SHOTS}/${NAMES.settings}.png` })
  })
})
