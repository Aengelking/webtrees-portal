import { existsSync, readFileSync } from 'node:fs'
import { act, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { InstallPortal } from './components/InstallPortal'
import { OfflineNotice } from './components/OfflineNotice'
import { de } from './i18n/de'
import { en } from './i18n/en'
import { createInstallStore } from './pwa/install'
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
 * Every branch of this is a guess about a browser, because none of it can be
 * asked directly: there is no "is this installed" to call, only an event
 * Chrome may fire, a media query about the current window, and — on iOS —
 * neither. So the test worth having is the one that proves the portal keeps
 * quiet when it does not know.
 */
describe('offering to install', () => {
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

  it('says nothing until a browser offers something', () => {
    expect(watching().state()).toBe('unavailable')
  })

  it('keeps the prompt Chrome offers, and takes Chrome’s own bar off the screen', () => {
    const store = watching()
    const { event } = fireInstallPrompt()

    expect(store.state()).toBe('ready')
    expect(event.defaultPrevented).toBe(true)
  })

  it('shows the browser’s dialogue when asked, and only once', async () => {
    const store = watching()
    const { prompt } = fireInstallPrompt()

    await store.prompt()
    expect(prompt).toHaveBeenCalledTimes(1)

    // A spent prompt cannot be shown again — a second `prompt()` on the same
    // event throws — so the offer goes away rather than becoming a button that
    // breaks.
    await store.prompt()
    expect(prompt).toHaveBeenCalledTimes(1)
    expect(store.state()).toBe('unavailable')
  })

  it('stops offering once the app exists', () => {
    const store = watching()
    fireInstallPrompt()

    window.dispatchEvent(new Event('appinstalled'))

    expect(store.state()).toBe('installed')
  })

  /**
   * The window the portal is running in is the home-screen one. Offering to
   * install it there is like a door with "door" written on it.
   */
  it('says nothing when it is already the installed app', () => {
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: query.includes('standalone') }))

    expect(watching().state()).toBe('installed')
  })

  it('describes the Share sheet on an iPhone, where there is no prompt to offer', () => {
    vi.stubGlobal('navigator', { ...navigator, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)' })

    expect(watching().state()).toBe('manual')
  })

  /** An iPad calls itself a Macintosh. Only the touch points give it away. */
  it('recognises an iPad pretending to be a Mac', () => {
    vi.stubGlobal('navigator', {
      ...navigator,
      userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
      maxTouchPoints: 5,
    })

    expect(watching().state()).toBe('manual')
  })

  it('is a desktop Mac when the touch points say so', () => {
    vi.stubGlobal('navigator', {
      ...navigator,
      userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
      maxTouchPoints: 0,
    })

    expect(watching().state()).toBe('unavailable')
  })

  it('puts nothing on the settings screen when there is nothing to offer', () => {
    const { container } = render(<InstallPortal />)

    expect(container.innerHTML).toBe('')
  })

  it('describes the two taps on an iPhone rather than showing a button that cannot work', () => {
    vi.stubGlobal('navigator', { ...navigator, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)' })

    render(<InstallPortal />)

    expect(screen.getByText(/Zum Home-Bildschirm/)).toBeDefined()
    expect(screen.queryByRole('button')).toBeNull()
  })
})
