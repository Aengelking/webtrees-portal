import { expect, test } from '@playwright/test'
import { stubApi } from './fixtures'

const REAL_BACKEND = process.env.E2E_BASE_URL !== undefined

const username = process.env.E2E_USERNAME ?? 'anna'
const password = process.env.E2E_PASSWORD ?? 'geheim'

test.beforeEach(async ({ page }) => {
  if (!REAL_BACKEND) {
    await stubApi(page)
  }
})

test.describe('the smoke path', () => {
  test('sign in, my profile, directory', async ({ page }) => {
    await page.goto('/me')

    // Not signed in: the portal asks, in German.
    await expect(page.getByRole('button', { name: 'Anmelden' })).toBeVisible()

    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    // My profile: my own record, read-only.
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    // The name is a name — the archive number is its own line under it, not a
    // badge glued to the front the way webtrees shows it.
    const name = page.getByRole('heading', { level: 2 }).first()
    await expect(name).toBeVisible()

    if (!REAL_BACKEND) {
      await expect(name).toHaveText('Anna Beispiel')
      await expect(page.getByText('SB 4711')).toBeVisible()
    }

    // The directory.
    await page.getByRole('link', { name: 'Mitglieder' }).click()
    await expect(page.getByRole('heading', { name: 'Mitglieder' })).toBeVisible()
    await expect(page.getByRole('listitem').first()).toBeVisible()

    // Searching narrows it.
    await page.getByLabel('Nach Namen suchen').fill('anna')
    await expect(page).toHaveURL(/q=anna/)
    await expect(page.getByRole('listitem').filter({ hasText: 'Anna' }).first()).toBeVisible()

    // And a member opens.
    await page.getByRole('listitem').first().getByRole('link').click()
    await expect(page.getByRole('link', { name: 'Zurück zur Übersicht' })).toBeVisible()
  })

  test('a wrong password says nothing useful', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Do not fire failed logins at a real installation.')

    await page.goto('/login')

    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill('nobody')
    await page.getByLabel('Passwort').fill('wrong')
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await expect(page.getByRole('alert')).toHaveText(
      'Benutzername oder Passwort ist falsch. Bitte versuchen Sie es noch einmal.',
    )
  })

  test('a deep link survives a page refresh', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    // The SPA fallback in _redirects is what makes this a 200 and not a 404.
    await page.goto('/members')
    await expect(page.getByRole('heading', { name: 'Mitglieder' })).toBeVisible()
  })

  test('the language switch works and nothing personal reaches storage', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await page.getByRole('link', { name: 'Einstellungen' }).click()
    await page.getByRole('button', { name: 'English' }).click()

    await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible()
    await expect(page.locator('html')).toHaveAttribute('lang', 'en')

    const stored = await page.evaluate(() => ({
      local: { ...window.localStorage },
      session: { ...window.sessionStorage },
    }))

    expect(Object.keys(stored.local)).toEqual(['portal.language'])
    expect(Object.keys(stored.session)).toEqual([])
  })

  test('signing out closes the door', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await page.getByRole('link', { name: 'Einstellungen' }).click()
    await page.getByRole('button', { name: 'Abmelden' }).click()

    await expect(page.getByRole('button', { name: 'Anmelden' })).toBeVisible()

    // Going straight back to a protected route does not get in.
    await page.goto('/me')
    await expect(page.getByRole('button', { name: 'Anmelden' })).toBeVisible()
  })
})

test.describe('phase 2', () => {
  test.skip(REAL_BACKEND, 'These write. Do not aim them at a real installation.')

  async function signIn(page: import('@playwright/test').Page) {
    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()
  }

  test('a member proposes a change and is told it is waiting', async ({ page }) => {
    await signIn(page)

    await page.getByRole('link', { name: 'Meine Daten ändern' }).click()
    await expect(page.getByRole('heading', { name: 'Meine Daten ändern' })).toBeVisible()

    await page.getByLabel('Beruf').fill('Möbelrestauratorin')
    await page.getByRole('button', { name: 'Änderung einreichen' }).click()

    // Back on the profile, told the change is not live yet.
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()
    await expect(page.getByText('Ihre Änderung wird geprüft')).toBeVisible()

    // And the form is not offered again while one is outstanding.
    await expect(page.getByRole('link', { name: 'Meine Daten ändern' })).toBeHidden()
  })

  test('a member can leave the directory', async ({ page }) => {
    await signIn(page)

    await page.getByRole('link', { name: 'Einstellungen' }).click()

    const toggle = page.getByRole('switch', { name: /Mitgliederverzeichnis/ })
    await expect(toggle).toHaveAttribute('aria-checked', 'true')

    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-checked', 'false')
  })

  test('the forgotten-password path says nothing about who has an account', async ({ page }) => {
    await page.goto('/login')
    await page.getByRole('link', { name: 'Passwort vergessen?' }).click()

    await page.getByLabel('E-Mail-Adresse').fill('niemand@example.test')
    await page.getByRole('button', { name: 'Link anfordern' }).click()

    await expect(page.getByText('Bitte sehen Sie in Ihr Postfach')).toBeVisible()
  })

  test('a reset link with no token explains itself', async ({ page }) => {
    await page.goto('/password/reset')

    await expect(page.getByText('Dieser Link ist unvollständig')).toBeVisible()
  })
})
