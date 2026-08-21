import { existsSync, readFileSync } from 'node:fs'
import { act, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { OfflineNotice } from './components/OfflineNotice'
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
