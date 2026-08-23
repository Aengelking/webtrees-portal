import { existsSync, readFileSync } from 'node:fs'
import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { InstallPortal } from './components/InstallPortal'
import { InstallPrompt } from './components/InstallPrompt'
import { OfflineNotice } from './components/OfflineNotice'
import { de } from './i18n/de'
import { en } from './i18n/en'
import { createInstallStore, installStore } from './pwa/install'
import { registerServiceWorker } from './pwa/register'
import './i18n'

/**
 * The portal as an installed app.
 *
 * Three things have to hold for a home-screen icon to be worth having, and all
 * three fail silently: the manifest has to be linked and point at icons that
 * exist, the service worker has to stay out of `vite dev`, and a member with
 * no connection has to be told so rather than shown a portal that has
 * apparently forgotten them.
 */

const manifest = JSON.parse(readFileSync('public/manifest.webmanifest', 'utf8')) as {
  name: string
  short_name: string
  start_url: string
  scope: string
  display: string
  icons: { src: string; purpose: string }[]
  prefer_related_applications: boolean
  related_applications: { platform: string; url: string }[]
}

const html = readFileSync('index.html', 'utf8')

// `vi.restoreAllMocks` in the shared setup does not undo a stubbed global, and
// a navigator left standing in one test is a navigator missing `onLine` in the
// next.
afterEach(() => {
  vi.unstubAllGlobals()
})

describe('the manifest', () => {
  it('is linked from the page, or no browser ever reads it', () => {
    expect(html).toMatch(/<link rel="manifest" href="\/manifest\.webmanifest"/)
  })

  /**
   * iOS reads none of the manifest's icons. Without this line it takes a
   * screenshot of whatever was on screen and uses that as the home-screen
   * icon — which, on this portal, is a screenshot of somebody's family.
   */
  it('has an apple-touch-icon, which iOS uses instead', () => {
    expect(html).toMatch(/<link rel="apple-touch-icon" href="\/icons\/apple-touch-icon\.png"/)
    expect(existsSync('public/icons/apple-touch-icon.png')).toBe(true)
  })

  it('opens the whole portal, not one screen of it', () => {
    expect(manifest.start_url).toBe('/')
    expect(manifest.scope).toBe('/')
    expect(manifest.display).toBe('standalone')
  })

  it('points only at icons that exist', () => {
    for (const icon of manifest.icons) {
      expect(existsSync(`public${icon.src}`), icon.src).toBe(true)
    }
  })

  /**
   * The only way a page in an ordinary tab can find out whether its own app is
   * already installed: name yourself, then ask `getInstalledRelatedApps()`
   * which of the named ones are there.
   */
  it('names itself, so the browser can be asked whether the app is installed', () => {
    expect(manifest.related_applications).toEqual([
      { platform: 'webapp', url: '/manifest.webmanifest' },
    ])
  })

  /**
   * And the trap that comes with it. `prefer_related_applications: true` tells
   * a browser to point at a store app *instead of* installing this one — which
   * would suppress the very prompt the entry above exists to support. It is
   * asserted rather than assumed because the two lines look like a pair and
   * are not.
   */
  it('does not prefer a related app over itself', () => {
    expect(manifest.prefer_related_applications).toBe(false)
  })

  /**
   * Android crops every icon to a shape of its own choosing. Without an icon
   * that says it can take that, it puts the square one on a white tile — which
   * looks like an app that was not finished.
   */
  it('offers a maskable icon', () => {
    expect(manifest.icons.some((icon) => icon.purpose === 'maskable')).toBe(true)
  })
})

/**
 * The portal has two names, and both are written down more than once in files
 * that cannot read each other. **Sack** is the family's name and the label
 * under the icon, where there is room for one word; **Sack Familienapp** is
 * the full one, for an install dialogue, a browser tab and a bookmark. Get the
 * pairing wrong and the app is called one thing on the home screen and another
 * on the screen it opens — and nothing else would notice.
 */
describe('what the app is called', () => {
  const appleTitle = /<meta name="apple-mobile-web-app-title" content="([^"]+)" \/>/.exec(html)?.[1]

  it('uses the short name under the icon, on both platforms', () => {
    // iOS reads none of the manifest; this tag is its `short_name`.
    expect(appleTitle).toBe(manifest.short_name)
  })

  it('has a full name that begins with the family’s', () => {
    expect(manifest.name.startsWith(manifest.short_name)).toBe(true)
    expect(manifest.name).not.toBe(manifest.short_name)
  })

  it('puts the full name in the tab and on the sign-in screen', () => {
    expect(html).toContain(`<title>${manifest.name}</title>`)
    expect(de.app.name).toBe(manifest.name)
  })

  /**
   * "Sack" is a family's name and survives translation; "Familienapp" is an
   * ordinary word and does what ordinary words do. So the assertion is not
   * that the two languages agree — it is that neither loses the name.
   */
  it('keeps the family’s name in either language', () => {
    expect(de.app.name).toContain(manifest.short_name)
    expect(en.app.name).toContain(manifest.short_name)
  })

  /**
   * The service worker has no React and no i18n, so it carries its own copy of
   * the name — which makes the offline page the place a rename is forgotten.
   */
  it('is the name the offline page uses', () => {
    expect(readFileSync('sw/service-worker.ts', 'utf8')).toContain(manifest.short_name)
  })
})

describe('registering the service worker', () => {
  function fakeRegistration() {
    const register = vi.fn().mockResolvedValue({})

    vi.stubGlobal('navigator', {
      ...navigator,
      serviceWorker: { register },
    })

    return register
  }

  it('registers once the page has loaded', () => {
    const register = fakeRegistration()

    registerServiceWorker(true)
    expect(register).not.toHaveBeenCalled()

    window.dispatchEvent(new Event('load'))
    expect(register).toHaveBeenCalledWith('/sw.js')
  })

  /**
   * `vite dev` serves unbundled modules that change on every keystroke. A
   * cache in front of that is a day lost to a bug that is not there.
   */
  it('stays out of development', () => {
    const register = fakeRegistration()

    registerServiceWorker(false)
    window.dispatchEvent(new Event('load'))

    expect(register).not.toHaveBeenCalled()
  })

  it('survives a browser that has no service workers at all', () => {
    vi.stubGlobal('navigator', { userAgent: 'test' })

    expect(() => registerServiceWorker(true)).not.toThrow()
  })
})

describe('losing the connection', () => {
  function online(value: boolean) {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(value)
  }

  const message = /Keine Internetverbindung/

  it('says nothing while there is a connection', () => {
    online(true)
    const { container } = render(<OfflineNotice />)

    expect(screen.queryByText(message)).toBeNull()
    // The live region itself is there all along, empty, or the message would
    // appear and the announcement would not.
    expect(container.querySelector('[aria-live]')).not.toBeNull()
  })

  it('says so when there is none', () => {
    online(false)
    render(<OfflineNotice />)

    expect(screen.getByText(message)).toBeDefined()
  })

  it('clears itself when the connection comes back', () => {
    online(false)
    render(<OfflineNotice />)

    online(true)
    act(() => {
      window.dispatchEvent(new Event('online'))
    })

    expect(screen.queryByText(message)).toBeNull()
  })
})

/**
 * The offer to install.
 *
 * Every branch is a guess about a browser, because none of it can be asked
 * directly: there is no "is this installed" to call from a tab except one API
 * that answers a narrower question, an event Chrome may fire, a media query
 * about the current window, and a user-agent string. The tests worth having
 * are the ones that pin down *which* of those a given situation lands on —
 * above all that a browser which can install is never left with nothing.
 */
describe('offering to install', () => {
  const ANDROID =
    'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36'
  const ANDROID_WEBVIEW =
    'Mozilla/5.0 (Linux; Android 14; Pixel 7; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/140.0.0.0 Mobile Safari/537.36'
  const IPHONE =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'
  /** The same phone, in Chrome. Same engine, same two taps, different end of the screen. */
  const IPHONE_CHROME =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/140.0.0.0 Mobile/15E148 Safari/604.1'
  const DESKTOP =
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'

  function browser(userAgent: string, extra: object = {}) {
    vi.stubGlobal('navigator', { ...navigator, userAgent, maxTouchPoints: 0, ...extra })
  }

  /** Chrome's event, which no browser type definition has, faked as Chrome fires it. */
  function fireInstallPrompt() {
    const event = new Event('beforeinstallprompt', { cancelable: true })
    const prompt = vi.fn().mockResolvedValue(undefined)

    Object.assign(event, { prompt })
    window.dispatchEvent(event)

    return { event, prompt }
  }

  function watching() {
    const store = createInstallStore()
    store.watch()

    return store
  }

  it('keeps the prompt Chrome offers, and takes Chrome’s own bar off the screen', () => {
    browser(ANDROID)
    const store = watching()
    const { event } = fireInstallPrompt()

    expect(store.state()).toBe('ready')
    expect(event.defaultPrevented).toBe(true)
  })

  it('shows the browser’s dialogue when asked, and only once', async () => {
    browser(ANDROID)
    const store = watching()
    const { prompt } = fireInstallPrompt()

    await store.prompt()
    expect(prompt).toHaveBeenCalledTimes(1)

    // A spent prompt cannot be shown again — a second `prompt()` on the same
    // event throws — so the offer stops being a button. It does not stop being
    // an offer: Chrome's own menu still works, and that is what is said next.
    await store.prompt()
    expect(prompt).toHaveBeenCalledTimes(1)
    expect(store.state()).toBe('android')
  })

  /**
   * The failure this whole rewrite is about. Chrome hands out
   * `beforeinstallprompt` when it feels like it and not at all if it thinks
   * the app is installed, if the page was opened a moment ago, or for reasons
   * it does not explain. Before, that left the member on a screen that
   * promised an app and offered no way to get one.
   */
  it('tells an Android browser where its own menu is, when no prompt arrives', () => {
    browser(ANDROID)

    expect(watching().state()).toBe('android')
  })

  /**
   * Where the link in the family chat actually opens. Android's embedded
   * browser can neither install nor reach a home screen, so the only useful
   * sentence is "leave this app first".
   */
  it('recognises another app’s built-in browser, which can only be left', () => {
    browser(ANDROID_WEBVIEW)

    expect(watching().state()).toBe('webview')
  })

  it('describes the Share sheet on an iPhone, where there is no prompt to offer', () => {
    browser(IPHONE)

    expect(watching().state()).toBe('apple')
  })

  /**
   * Every browser on iOS is WebKit underneath, so what differs is not what
   * they can do but where their buttons are: Safari's Share button is at the
   * bottom of the screen and Chrome's is at the top. An instruction naming the
   * wrong end of the phone is worse than one naming neither — this portal's
   * audience looks where it is told and then gives up.
   */
  it('tells Chrome on an iPhone apart from Safari, because the button is elsewhere', () => {
    browser(IPHONE_CHROME)

    expect(watching().state()).toBe('appleOther')
  })

  /** An iPad calls itself a Macintosh. Only the touch points give it away. */
  it('recognises an iPad pretending to be a Mac', () => {
    browser('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', { maxTouchPoints: 5 })

    expect(watching().state()).toBe('apple')
  })

  it('says nothing where installing is not a thing that happens', () => {
    browser(DESKTOP)

    expect(watching().state()).toBe('unavailable')
  })

  it('stops offering once the app exists', () => {
    browser(ANDROID)
    const store = watching()
    fireInstallPrompt()

    window.dispatchEvent(new Event('appinstalled'))

    expect(store.state()).toBe('installed')
  })

  /**
   * The one an ordinary tab cannot work out for itself. `display-mode` only
   * ever describes the window it is asked in, so a member browsing in Chrome
   * with the app already on their home screen looks exactly like a member who
   * has never installed it. The manifest names itself under
   * `related_applications` so that this question has an answer at all.
   */
  it('asks the browser whether the app is already on the device', async () => {
    browser(ANDROID, { getInstalledRelatedApps: async () => [{ platform: 'webapp' }] })
    const store = watching()

    await vi.waitFor(() => expect(store.state()).toBe('installed'))
  })

  it('ignores a native app the manifest happens to mention', async () => {
    browser(ANDROID, { getInstalledRelatedApps: async () => [{ platform: 'play' }] })
    const store = watching()

    await vi.waitFor(() => expect(store.state()).toBe('android'))
  })

  it('survives a browser that refuses the question', async () => {
    browser(ANDROID, {
      getInstalledRelatedApps: async () => {
        throw new Error('not a secure context')
      },
    })

    expect(watching().state()).toBe('android')
  })

  /**
   * The window the portal is running in is the home-screen one. Offering to
   * install it there is a door with "door" written on it — and so is telling
   * somebody it is installed.
   */
  it('says nothing at all inside the installed app', () => {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('standalone') }))

    expect(watching().state()).toBe('standalone')
  })

  it('puts nothing on the settings screen where installing cannot happen', () => {
    browser(DESKTOP)
    const { container } = render(<InstallPortal />)

    expect(container.innerHTML).toBe('')
  })

  it('describes the two taps on an iPhone rather than showing a button that cannot work', () => {
    browser(IPHONE)
    render(<InstallPortal />)

    expect(screen.getByText(/Zum Home-Bildschirm/)).toBeDefined()
    expect(screen.queryByRole('button')).toBeNull()
  })

  it('says the Share button is at the top in Chrome on an iPhone', () => {
    browser(IPHONE_CHROME)
    render(<InstallPortal />)

    expect(screen.getByText(/oben auf das Teilen-Symbol/)).toBeDefined()

    // And says where it is in Safari, because a member who has been told the
    // wrong place once will not trust the next sentence either.
    expect(screen.getByText(/In Safari sitzt dieses Symbol unten/)).toBeDefined()
  })

  it('points an Android member at the menu rather than at nothing', () => {
    browser(ANDROID)
    render(<InstallPortal />)

    expect(screen.getByText(/App installieren/)).toBeDefined()
  })

  it('tells somebody in a chat app’s browser to leave it first', () => {
    browser(ANDROID_WEBVIEW)
    render(<InstallPortal />)

    expect(screen.getByText(/Im Browser öffnen/)).toBeDefined()
  })

  /**
   * The offer on the way in, which is a different thing from the standing one
   * in Settings. It is acceptable only because it is asked **once**: a portal
   * that puts a dialogue in front of a member every time they sign in is a
   * portal that teaches them to dismiss dialogues.
   */
  describe('the offer after signing in', () => {
    beforeEach(() => {
      window.localStorage.removeItem('portal.install.offered')
    })

    it('shows the browser’s own dialogue where there is one to show', async () => {
      browser(ANDROID)

      // The component reads the shared store, so that is the one that has to
      // be listening when Chrome's event arrives.
      installStore.watch()
      const { prompt } = fireInstallPrompt()

      render(<InstallPrompt />)

      expect(screen.getByRole('dialog')).toBeDefined()

      // And it says the offer is not lost by saying no, which is what makes
      // asking only once a fair thing to do.
      expect(screen.getByText(/unter „Einstellungen“/)).toBeDefined()

      await userEvent.click(screen.getByRole('button', { name: 'Auf den Startbildschirm legen' }))

      expect(prompt).toHaveBeenCalled()
      expect(screen.queryByRole('dialog')).toBeNull()
    })

    it('describes the taps where the browser has no dialogue', () => {
      browser(IPHONE)
      render(<InstallPrompt />)

      expect(screen.getByText(/Zum Home-Bildschirm/)).toBeDefined()
    })

    /** Asked once. The second sign-in on this device is not asked again. */
    it('never asks twice on the same device', async () => {
      browser(ANDROID)
      const { unmount } = render(<InstallPrompt />)

      await userEvent.click(screen.getByRole('button', { name: 'Alles klar' }))

      expect(screen.queryByRole('dialog')).toBeNull()

      unmount()
      render(<InstallPrompt />)

      expect(screen.queryByRole('dialog')).toBeNull()
    })

    it('says nothing where installing cannot happen at all', () => {
      browser(DESKTOP)
      const { container } = render(<InstallPrompt />)

      expect(container.innerHTML).toBe('')
    })

    it('says nothing inside the installed app', () => {
      browser(ANDROID)
      vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('standalone') }))

      const { container } = render(<InstallPrompt />)

      expect(container.innerHTML).toBe('')
    })

    /**
     * A member in another app's browser cannot install from where they are
     * standing, and "leave this app first" is not what a dialogue on the way
     * in is for. Settings still says it.
     */
    it('does not stop somebody in a chat app’s browser on their way in', () => {
      browser(ANDROID_WEBVIEW)
      const { container } = render(<InstallPrompt />)

      expect(container.innerHTML).toBe('')
    })

    /** One flag, and it says nothing about anybody. */
    it('keeps only the fact that the question was asked', async () => {
      browser(ANDROID)
      render(<InstallPrompt />)

      await userEvent.click(screen.getByRole('button', { name: 'Alles klar' }))

      expect({ ...window.localStorage }).toEqual({ 'portal.install.offered': '1' })
    })
  })
})
