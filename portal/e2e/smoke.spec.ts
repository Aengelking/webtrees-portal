import { expect, test } from '@playwright/test'
import { installOfferAnswered, stubApi } from './fixtures'

const REAL_BACKEND = process.env.E2E_BASE_URL !== undefined

const username = process.env.E2E_USERNAME ?? 'anna'
const password = process.env.E2E_PASSWORD ?? 'geheim'

test.beforeEach(async ({ page }) => {
  await installOfferAnswered(page)

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

      // And under the number, the part of it a member would say out loud.
      await expect(page.getByText('Ernestinische Linie – Zweig Cleve')).toBeVisible()
    }

    // The directory, which now lives inside Kontakte — on the half about
    // adding somebody, which is what looking one up is the start of.
    await page.getByRole('link', { name: 'Kontakte' }).click()
    await expect(page.getByRole('heading', { name: 'Meine Kontakte' })).toBeVisible()
    await page.getByRole('tab', { name: 'Neu verbinden' }).click()
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

  test('the archive can be searched and read down', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the fixture tree.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    // The way in is on the record, beside the pedigree — the two ways of
    // going further from a person.
    await page.getByRole('link', { name: 'Stammbaum durchsuchen' }).click()
    await expect(page.getByRole('heading', { name: 'Stammbaum' })).toBeVisible()

    // Typing a name finds the person, and the card says who they are to me.
    await page.getByLabel('Name oder SB-Nr.').fill('Bertha')
    const card = page.getByRole('link', { name: /Bertha Beispiel/ })
    await expect(card).toBeVisible()
    await expect(card).toContainText('SB 4712')
    await expect(card).toContainText('Für Sie: Ihre Großmutter')

    // The archive number finds her too, which is how this family quotes
    // people to each other.
    await page.getByLabel('Name oder SB-Nr.').fill('4712')
    await expect(page.getByRole('link', { name: /Bertha Beispiel/ })).toBeVisible()

    // And the other half: reading down the names rather than asking for one.
    await page.getByRole('tab', { name: 'Namen' }).click()
    await page.getByRole('button', { name: /Fernab/ }).click()

    await expect(page.getByRole('heading', { name: /Alle mit dem Namen Fernab/ })).toBeVisible()
    await expect(page.getByRole('link', { name: /Otto Fernab/ })).toBeVisible()

    // Back out to the index, which is where the member came from.
    await page.getByRole('button', { name: 'Zurück zu den Namen' }).click()
    await expect(page.getByRole('button', { name: /Beispiel/ })).toBeVisible()

    // And the calculator, which touches no records at all: the member's own
    // number is already in the first field, so the question is "how am I
    // related to this one".
    await page.getByRole('tab', { name: 'Rechner' }).click()
    await expect(page.getByLabel('SB-Nr. 1')).toHaveValue('10/1335.11')

    await page.getByLabel('SB-Nr. 2').fill('24/b6')
    await expect(page.getByText('Cousin/Cousine 3. Grades')).toBeVisible()
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

    // Three keys, and every one of them a device preference: which language to
    // show, and whether each of the two offers has been made. Nothing about
    // anybody.
    expect(Object.keys(stored.local).sort()).toEqual([
      'portal.install.offered',
      'portal.language',
      'portal.notifications.offered',
    ])
    expect(Object.values(stored.local).sort()).toEqual(['1', '1', 'en'])
    expect(Object.keys(stored.session)).toEqual([])
  })

  /**
   * The lists live in Exchange and the portal holds only the wish, so what is
   * worth proving in a browser is that the wish makes the round trip: the
   * switch moves, the answer comes back, and it is still there on the way
   * back to the screen.
   */
  test('a member can leave one of the family’s mailing lists and rejoin it', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await page.getByRole('link', { name: 'Einstellungen' }).click()

    const invitations = page.getByRole('switch', { name: /Einladungen/ })

    await expect(invitations).toHaveAttribute('aria-checked', 'true')
    await invitations.click()
    await expect(invitations).toHaveAttribute('aria-checked', 'false')

    // As easy to undo as to do. A list a member cannot rejoin is one they will
    // ask somebody to put them back on, which is the arrangement this replaced.
    await invitations.click()
    await expect(invitations).toHaveAttribute('aria-checked', 'true')

    // The address the post goes to is on the screen; the list's own address is
    // not, and never leaves the server.
    await expect(page.getByText(/Sie bekommen diese Rundmails an anna@example\.test/)).toBeVisible()
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

    // Reached from Mein Profil or from Settings, not from the navigation bar:
    // three destinations was a decision. Mein Profil is the screen a member is
    // looking at when they notice who is missing, so the offer stands there
    // too — including for an account with no record of its own.
    await expect(page.getByRole('link', { name: 'Jemanden einladen' })).toHaveCount(1)

    await page.getByRole('link', { name: 'Einstellungen' }).click()
    await page.getByRole('link', { name: 'Jemanden einladen' }).click()

    // The relationship is named on the line, so it is obvious who is about to
    // be picked — and picked by value, because that is what the form sends.
    const chooser = page.getByLabel('Person auswählen')

    await expect(chooser.getByRole('option', { name: /Ihr Bruder — Dieter Beispiel/ })).toHaveCount(1)
    await chooser.selectOption('X4')
    await page.getByRole('button', { name: 'Einladung erstellen' }).click()

    const link = page.getByLabel('Einladungslink')
    await expect(link).toHaveValue(/token=einladung-fuer-dieter/)
    await expect(page.getByRole('status')).toContainText('nur dieses eine Mal')

    // Where the member is looking, without scrolling. It used to be rendered
    // above the whole list of relatives, so on a phone the one thing they came
    // for appeared off-screen behind them.
    await expect(link).toBeInViewport()

    // The link is shown once, so it has to leave the screen in one piece:
    // selecting a URL by hand on a phone is how half of one ends up in a chat.
    await expect(page.getByRole('button', { name: 'Kopieren' })).toBeVisible()

    // Dieter is no longer on offer, and the invitation is listed as pending.
    await page.getByRole('button', { name: 'Habe ich kopiert' }).click()
    await expect(page.getByRole('button', { name: 'Zurücknehmen' })).toBeVisible()
  })
})

test.describe('the reference number', () => {
  /**
   * On the card, not one tap further in. The family tells two people of the
   * same name apart by this number, so a row without it is a row that has to
   * be opened to be sure who it is.
   */
  test('is on a directory row, beside the years', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the fixture members.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    await page.goto('/members')

    const row = page.getByRole('listitem').filter({ hasText: 'Dieter Beispiel' }).first()

    await expect(row).toContainText('SB 4714')
    await expect(row).toContainText('1990')
  })
})

test.describe('the navigation bar', () => {
  /**
   * It stays at the bottom of the screen while the page moves under it. That
   * is the whole of it, and it was broken on a phone held sideways: the bar
   * went into the flow of the page above 640px *wide*, which a phone on its
   * side is, and scrolled away on the one screen shape with the least room to
   * spare.
   */
  test('stays at the bottom of a phone, upright and on its side', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the fixture members.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    const nav = page.getByRole('navigation', { name: 'Hauptnavigation' })

    for (const size of [
      { width: 390, height: 560 },
      // The same phone, turned. Wide enough for the desktop layout by width
      // alone, and nowhere near tall enough to want it.
      { width: 780, height: 390 },
    ]) {
      await page.setViewportSize(size)
      await page.evaluate(() => window.scrollTo(0, 0))

      // Something to scroll, whatever the fixture happens to be worth.
      await page.evaluate(() => {
        document.querySelector('main')?.insertAdjacentHTML(
          'beforeend',
          '<div style="height:3000px" data-filler></div>',
        )
      })

      await page.evaluate(() => window.scrollBy(0, 1200))
      await expect
        .poll(async () => Math.round((await nav.boundingBox())!.y + (await nav.boundingBox())!.height))
        .toBe(size.height)

      await page.evaluate(() => document.querySelector('[data-filler]')?.remove())
    }
  })
})

test.describe('inviting from a person’s page', () => {
  /**
   * Walking the tree is how a member finds out that their brother is not in
   * the portal. The offer is on the page where they find that out, and it
   * carries the person with it — nobody has to be looked up twice.
   */
  test('a relative without an account is invited from their own page', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Would create a real invitation.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    await page.goto('/individuals/X4')
    await expect(page.getByText('Noch nicht im Portal')).toBeVisible()

    await page.getByRole('link', { name: 'Einladen' }).click()

    // Arrived with him chosen, so the next tap is the last one.
    await expect(page).toHaveURL(/\/invite\?xref=X4$/)
    await expect(page.getByLabel('Person auswählen')).toHaveValue('X4')

    await page.getByRole('button', { name: 'Einladung erstellen' }).click()
    await expect(page.getByLabel('Einladungslink')).toHaveValue(/token=einladung-fuer-dieter/)
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
    await page.getByRole('tab', { name: 'Neu verbinden' }).click()
    await page.getByRole('button', { name: 'Im Verzeichnis suchen' }).click()

    // Dieter, not the first row — the first row is Anna herself, and nobody
    // writes to themselves.
    await page.getByRole('listitem').filter({ hasText: 'Dieter' }).first().getByRole('link').click()

    // Said above the button, not after it is pressed. It used to be a warning —
    // webtrees' notification carried the sender's address as the reply address
    // — and it is now a statement of what the other person gets, because the
    // announcement carries no address, no name and no text at all.
    await expect(page.getByText(/nur, dass eine Nachricht im Portal wartet/)).toBeVisible()

    await page.getByRole('button', { name: 'Nachricht schreiben' }).click()

    await expect(page).toHaveURL(/\/conversations\//)

    // And again next to the box, for everybody who never came this way.
    await expect(page.getByText(/weder Ihr Name noch der Text/)).toBeVisible()
  })

  test('each contact detail has its own audience', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the stubbed contact details.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await page.getByRole('link', { name: 'Einstellungen' }).click()

    // What is shared is on the screen; the form that changes it is behind a
    // button, because looking is the commoner errand.
    await expect(page.getByText('Sichtbar für: Alle Mitglieder im Portal')).toBeVisible()
    await expect(page.getByLabel('Telefonnummer')).toHaveCount(0)

    await page.getByRole('button', { name: 'Kontaktdaten ändern' }).click()

    await expect(page.getByLabel('Telefonnummer')).toHaveValue('0511 12345')
    await expect(page.getByRole('radio', { name: 'Nur meine enge Familie' })).toHaveCount(3)
  })

  test('an address is typed into fields and saved as one address', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the stubbed contact details.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    await page.getByRole('link', { name: 'Einstellungen' }).click()
    await page.getByRole('button', { name: 'Kontaktdaten ändern' }).click()

    await page.getByLabel('Straße und Hausnummer').fill('Musterstraße 12')
    await page.getByLabel('Postleitzahl').fill('29223')
    // Exactly "Ort": Playwright matches a label by substring, and "Alle
    // Mitglieder im Portal" contains one.
    await page.getByLabel('Ort', { exact: true }).fill('Celle')

    await page.getByRole('button', { name: 'Kontaktdaten speichern' }).click()

    // Saved, and back to the reading view with the address on one card and
    // its lines in the order an envelope would have them.
    await expect(page.getByText('Ihre Kontaktdaten sind gespeichert.')).toBeVisible()
    await expect(page.getByText('Musterstraße 12', { exact: false })).toBeVisible()
    await expect(page.getByText('29223 Celle', { exact: false })).toBeVisible()
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

    // The address book opens first, and the request waiting for an answer is
    // at the top of it.
    await expect(page.getByRole('tab', { name: 'Kontakte' })).toHaveAttribute(
      'aria-selected',
      'true',
    )
    await expect(page.getByText('Karla Beispiel')).toBeVisible()

    await page.getByRole('button', { name: 'Annehmen' }).click()
    await expect(page.getByRole('button', { name: 'Annehmen' })).toBeHidden()
    await expect(page.getByRole('link', { name: 'Karla Beispiel' })).toBeVisible()

    // Nothing is on screen until it is asked for: a live code is a
    // credential, and one that appeared by itself would be one nobody meant
    // to show.
    await page.getByRole('tab', { name: 'Neu verbinden' }).click()

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
    await page.getByRole('tab', { name: 'Neu verbinden' }).click()
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

  test('a link is made, shown once, and says it works once', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the stubbed connections.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()

    // The tab is in the address bar, so a link can point straight at it.
    await page.goto('/contacts?tab=new')

    // Nothing is issued until it is asked for: this one travels through
    // somebody else's inbox.
    await expect(page.getByLabel('Ihr Link')).toBeHidden()

    await page.getByRole('button', { name: 'Link erzeugen' }).click()

    await expect(page.getByLabel('Ihr Link')).toHaveValue(
      'https://portal.example.test/connect?code=link-fuer-anna',
    )
    await expect(page.getByText(/funktioniert genau einmal/)).toBeVisible()
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

  /**
   * The same thing started from the screen a member is actually standing on.
   * Until this existed, writing to your sister began with remembering that the
   * way in was on her page — three taps and a piece of knowledge nobody has.
   */
  test('a conversation is started from the messages screen', async ({ page }) => {
    test.skip(REAL_BACKEND, 'Depends on the fixture members.')

    await page.goto('/login')
    await page.getByLabel('Benutzername oder E-Mail-Adresse').fill(username)
    await page.getByLabel('Passwort').fill(password)
    await page.getByRole('button', { name: 'Anmelden' }).click()
    await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()

    await page.goto('/messages')
    await page.getByRole('link', { name: 'Neues Gespräch' }).click()

    await expect(page.getByRole('heading', { level: 1, name: 'Neues Gespräch' })).toBeVisible()
    await page.getByRole('button', { name: 'Dieter Beispiel' }).click()

    await expect(page).toHaveURL(/\/conversations\/3$/)
    await page.getByLabel('Ihre Nachricht').fill('Sehen wir uns Sonntag?')
    await page.getByRole('button', { name: 'Senden' }).click()

    await expect(page.getByText('Sehen wir uns Sonntag?')).toBeVisible()

    // The picker was a step on the way, not a screen to come back to: Back
    // from the conversation is where the member started.
    await page.goBack()
    await expect(page.getByRole('heading', { level: 1, name: 'Nachrichten' })).toBeVisible()
  })
})
