import { expect, test } from '@playwright/test'
import { stubApi } from './fixtures'

/**
 * The offer to install, on the way in.
 *
 * Its own file, and deliberately: every other walk starts with the offer
 * already answered (`installOfferAnswered`), because a dialogue on the first
 * screen would stop them on the first tap. This one wants the dialogue, so it
 * must not be in a file that has pre-answered it — and clearing the flag from
 * an init script does not work, since that script runs again on the reload
 * this test needs.
 */

const REAL_BACKEND = process.env.E2E_BASE_URL !== undefined

const username = process.env.E2E_USERNAME ?? 'anna'
const password = process.env.E2E_PASSWORD ?? 'geheim'

test.beforeEach(async ({ page }) => {
  if (!REAL_BACKEND) {
    await stubApi(page)
  }
})

test.describe('the offer to install', () => {
  /**
   * It is a dialogue in front of somebody who has just signed in, so the two
   * things that make that acceptable are tested together: it is answered in
   * one tap, and it does not come back.
   */
  test('is made once after signing in and then never again', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the fixture members.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    const offer = page.getByRole('dialog')

    await expect(offer).toBeVisible()
    await expect(offer).toContainText('Startbildschirm')

    // Saying no costs nothing, and the dialogue says where the offer stays.
    await expect(offer).toContainText('Einstellungen')
    await offer.getByRole('button').last().click()

    await expect(offer).toBeHidden()
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    // Not on the next screen, and not after a reload either.
    await page.getByRole('link', { name: 'Kontakte' }).click()
    await expect(page.getByRole('dialog')).toBeHidden()

    await page.reload()
    await expect(page.getByRole('dialog')).toBeHidden()
  })
})
