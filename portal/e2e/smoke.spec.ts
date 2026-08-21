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

    // The directory, which now lives inside Kontakte.
    await page.getByRole('link', { name: 'Kontakte' }).click()
    await expect(page.getByRole('heading', { name: 'Meine Kontakte' })).toBeVisible()
    await page.getByRole('button', { name: 'Im Verzeichnis suchen' }).click()
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

  test('the family tree can be walked without leaving the portal', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the fixture tree.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    // A relative is a link, not a dead end. This is the whole of phase 3.
    await page.getByRole('link', { name: /Bertha Beispiel/ }).first().click()

    await expect(page.getByRole('heading', { name: 'Bertha Beispiel' })).toBeVisible()
    await expect(page.getByText('Für Sie: Ihre Mutter')).toBeVisible()

    // And the pedigree, which is one request rather than fifteen.
    await page.goBack()
    await page.getByRole('link', { name: 'Vorfahren anzeigen' }).click()

    await expect(page.getByRole('heading', { name: 'Vorfahren' })).toBeVisible()
    await expect(page.getByText('Mütterliche Linie')).toBeVisible()
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

test.describe('phase 5', () => {
  test('an invited person creates an account and is signed in', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Would create a real account.')

    await page.goto('/invitation?token=einladung-fuer-anna')

    // Who it is for, before anything is asked of them.
    await expect(page.getByText('Anna Beispiel')).toBeVisible()

    // A username somebody already has is explained, and does not cost them
    // the invitation or what they typed.
    await page.getByLabel('Benutzername').fill('anna')
    await page.getByLabel('Neues Passwort').fill('sehr-geheim-2026')
    await page.getByLabel('Passwort wiederholen').fill('sehr-geheim-2026')
    await page.getByRole('button', { name: 'Zugang anlegen' }).click()

    await expect(page.getByRole('alert')).toContainText('Benutzernamen gibt es schon')
    await expect(page.getByLabel('E-Mail-Adresse')).toHaveValue('anna@example.test')

    await page.getByLabel('Benutzername').fill('anna2')
    await page.getByLabel('Neues Passwort').fill('sehr-geheim-2026')
    await page.getByLabel('Passwort wiederholen').fill('sehr-geheim-2026')
    await page.getByRole('button', { name: 'Zugang anlegen' }).click()

    // Signed in, with no second trip through the login screen.
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()
  })

  test('a spent invitation says so instead of showing a form', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the stubbed invitation.')

    await page.goto('/invitation?token=schon-benutzt')

    await expect(page.getByText('Diese Einladung gilt nicht mehr')).toBeVisible()
    await expect(page.getByLabel('Benutzername')).toBeHidden()
  })
})

test.describe('phase 7', () => {
  test('a member invites their brother and is shown the link once', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Would create a real invitation.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    // Reached from Settings, not from the navigation bar: three destinations
    // was a decision, and this is a thing a member does once or twice.
    await page.getByRole('link', { name: 'Einstellungen' }).click()
    await page.getByRole('link', { name: 'Jemanden einladen' }).click()

    // The relationship is named, so it is obvious who is about to be picked.
    await expect(page.getByText('Ihr Bruder')).toBeVisible()

    await page.getByRole('radio', { name: /Dieter Beispiel/ }).check()
    await page.getByRole('button', { name: 'Einladung erstellen' }).click()

    const link = page.getByLabel('Einladungslink')
    await expect(link).toHaveValue(/token=einladung-fuer-dieter/)
    await expect(page.getByRole('status')).toContainText('nur dieses eine Mal')

    // Dieter is no longer on offer, and the invitation is listed as pending.
    await page.getByRole('button', { name: 'Habe ich kopiert' }).click()
    await expect(page.getByRole('button', { name: 'Zurücknehmen' })).toBeVisible()
  })
})

test.describe('phase 9', () => {
  test('a member is warned that their address travels with a message', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Would send a real message.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await page.getByRole('link', { name: 'Kontakte' }).click()
    await page.getByRole('button', { name: 'Im Verzeichnis suchen' }).click()

    // Dieter, not the first row — the first row is Anna herself, and nobody
    // writes to themselves.
    await page.getByRole('listitem').filter({ hasText: 'Dieter' }).first().getByRole('link').click()

    // The warning is above the button, not after it is pressed. Phase 12 turned
    // the form here into the way into a conversation; the disclosure it carries
    // did not change, because webtrees' notification still travels with the
    // sender's address on it.
    await expect(page.getByText(/Ihre E-Mail-Adresse als Absenderadresse mitgeschickt/)).toBeVisible()

    await page.getByRole('button', { name: 'Nachricht schreiben' }).click()

    await expect(page).toHaveURL(/\/conversations\//)
  })

  test('each contact detail has its own audience', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the stubbed contact details.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await page.getByRole('link', { name: 'Einstellungen' }).click()

    await expect(page.getByLabel('Telefonnummer')).toHaveValue('0511 12345')
    await expect(page.getByRole('radio', { name: 'Nur meine enge Familie' })).toHaveCount(3)
  })
})

test.describe('phase 10', () => {
  test('an unread message is badged, opened and cleared', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the stubbed inbox.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    // The count is in the link's name, not only in a coloured circle.
    const inbox = page.getByRole('link', { name: /Nachrichten/ })
    await expect(inbox).toContainText('1')

    await inbox.click()
    await expect(page.getByRole('heading', { level: 1, name: 'Nachrichten' })).toBeVisible()

    // Closed until opened, then the message itself — not webtrees' email
    // wrapper around it.
    await expect(page.getByText('Kommst du zum Familientreffen?')).toBeHidden()
    await page.getByRole('button', { name: /Familientreffen/ }).click()
    await expect(page.getByText('Kommst du zum Familientreffen?')).toBeVisible()

    await page.getByRole('button', { name: 'Löschen' }).click()
    await expect(page.getByText('Keine Nachrichten')).toBeVisible()
  })

  test('a message is answered, and the answerer is told what travels with it', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the stubbed inbox.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await page.getByRole('link', { name: /Nachrichten/ }).click()
    await page.getByRole('button', { name: /Familientreffen/ }).click()
    await page.getByRole('button', { name: 'Antworten' }).click()

    // Before the button, not after it.
    await expect(page.getByText(/als Absenderadresse mitgeschickt/)).toBeVisible()

    await page.getByLabel('Ihre Antwort').fill('Ja, sehr gern.')
    await page.getByRole('button', { name: 'Antwort senden' }).click()

    await expect(page.getByText(/Eine Kopie wird hier nicht aufbewahrt/)).toBeVisible()
  })
})

test.describe('phase 11', () => {
  test('a member answers a request and shows their code', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the stubbed connections.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    // Somebody waiting for an answer is counted on Kontakte, which is the
    // screen the request is about — and still not on a fifth destination.
    await expect(page.getByRole('link', { name: /Kontakte/ })).toContainText('1')

    await page.getByRole('link', { name: /Kontakte/ }).click()

    await expect(page.getByRole('heading', { name: 'Meine Kontakte' })).toBeVisible()
    await expect(page.getByText('Karla Beispiel')).toBeVisible()

    await page.getByRole('button', { name: 'Annehmen' }).click()
    await expect(page.getByRole('button', { name: 'Annehmen' })).toBeHidden()
    await expect(page.getByRole('link', { name: 'Karla Beispiel' })).toBeVisible()

    // Nothing is on screen until it is asked for: a live code is a
    // credential, and one that appeared by itself would be one nobody meant
    // to show.
    await expect(page.getByRole('img', { name: /QR-Code/ })).toBeHidden()
    await page.getByRole('button', { name: 'Code anzeigen' }).click()
    await expect(page.getByRole('img', { name: /QR-Code/ })).toBeVisible()

    await page.getByRole('button', { name: 'Code ungültig machen' }).click()
    await expect(page.getByRole('img', { name: /QR-Code/ })).toBeHidden()
  })

  test('a request is sent from the directory list itself', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the stubbed connections.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await page.getByRole('link', { name: /Kontakte/ }).click()
    await page.getByRole('button', { name: 'Im Verzeichnis suchen' }).click()

    // Each button is named for its row, so twenty-five of them are still a
    // list somebody can navigate.
    const row = page.getByRole('button', { name: 'Verbinden mit Nora Ohnesatz' })
    await expect(row).toBeVisible()

    // Somebody already a contact is a word, not a button, and one's own row
    // offers nothing at all.
    await expect(page.getByRole('button', { name: 'Verbinden mit Dieter Beispiel' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Verbinden mit Anna Beispiel' })).toHaveCount(0)

    await row.click()

    await expect(page.getByText('Angefragt')).toBeVisible()
  })

  test('a scanned code asks before it connects anybody', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the stubbed connections.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await page.goto('/connect?code=code-fuer-anna')

    await expect(page.getByRole('heading', { name: 'Verbinden' })).toBeVisible()
    await page.getByRole('button', { name: 'Jetzt verbinden' }).click()

    await expect(page.getByText(/Sie sind jetzt mit Emil Beispiel verbunden/)).toBeVisible()
  })
})

test.describe('phase 12', () => {
  /**
   * The exchange webtrees could not hold. Opening it from a member's page,
   * saying something, and finding both halves on one screen is the whole
   * feature — and the half that used to be missing is the one the member
   * wrote themselves.
   */
  test('a member writes, and the conversation keeps both halves', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the fixture members.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    await page.goto('/members/2')
    await page.getByRole('button', { name: 'Nachricht schreiben' }).click()

    // Landed in the conversation, with what the other person said already there.
    await expect(page).toHaveURL(/\/conversations\/3$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Dieter Beispiel' })).toBeVisible()
    await expect(page.getByText('Hast du die Fotos von Oma gesehen?')).toBeVisible()

    await page.getByLabel('Ihre Nachricht').fill('Ja, ich komme!')
    await page.getByRole('button', { name: 'Senden' }).click()

    await expect(page.getByText('Ja, ich komme!')).toBeVisible()
    // And the one it answers is still there — this is a transcript, not an inbox.
    await expect(page.getByText('Hast du die Fotos von Oma gesehen?')).toBeVisible()
  })
})
