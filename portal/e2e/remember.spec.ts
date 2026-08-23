import { expect, test } from '@playwright/test'
import { stubApi } from './fixtures'

/**
 * "Angemeldet bleiben", in a real browser.
 *
 * The unit tests assert this against a stubbed `fetch`, which proves the
 * component and not the page. This proves the page: that the switch the
 * server offered is drawn, that tapping it changes something, and that what
 * leaves the browser is a boolean saying what the member chose.
 *
 * It exists because the switch was invisible to every browser test until the
 * fixture learned to send `remember_days` — the field that decides whether it
 * is drawn at all.
 */
test.describe('staying signed in', () => {
  test('the switch is offered, and what leaves the browser is what was chosen', async ({ page }) => {
    await stubApi(page)
    await page.goto('/login')

    const remember = page.getByRole('switch', { name: 'Angemeldet bleiben' })

    await expect(remember).toBeVisible()
    await expect(remember).toHaveAttribute('aria-checked', 'false')
    await expect(page.getByText(/30 Tage angemeldet/)).toBeVisible()

    await remember.click()
    await expect(remember).toHaveAttribute('aria-checked', 'true')

    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill('anna')
    await page.getByLabel('Passwort').fill('geheim')

    const login = page.waitForRequest(
      (request) => request.url().includes('/api/v1/session') && request.method() === 'POST',
    )

    await page.getByRole('button', { name: 'Anmelden' }).click()

    // A boolean, and nothing about how long: the duration is the server's
    // answer and is never taken from the browser.
    expect((await login).postDataJSON()).toEqual({
      username: 'anna',
      password: 'geheim',
      remember: true,
    })

    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()
  })

  test('left alone, it says no rather than saying nothing', async ({ page }) => {
    await stubApi(page)
    await page.goto('/login')

    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill('anna')
    await page.getByLabel('Passwort').fill('geheim')

    const login = page.waitForRequest(
      (request) => request.url().includes('/api/v1/session') && request.method() === 'POST',
    )

    await page.getByRole('button', { name: 'Anmelden' }).click()

    expect((await login).postDataJSON()).toEqual({
      username: 'anna',
      password: 'geheim',
      remember: false,
    })
  })
})
