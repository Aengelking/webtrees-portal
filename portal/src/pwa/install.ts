import { useSyncExternalStore } from 'react'

/**
 * "Put this on your home screen" — and the seven answers a browser can give.
 *
 * None of this can be asked directly. There is no "is this app installed" to
 * call; there is an event Chrome may or may not fire, a media query that says
 * how the *current window* is being displayed, one API that answers a narrower
 * question than it looks like, and a user-agent string. So the state is
 * assembled from what is observable.
 *
 * The first version had one rule for everything it could not identify: say
 * nothing. That is right for a browser where installing is impossible, and
 * wrong for Android, where installing is perfectly possible and Chrome simply
 * did not hand over a prompt — the member is then looking at a screen that
 * promises an app and offers no way to get one. Every case below that *can*
 * install now says how.
 */
export type InstallState =
  /** This window *is* the installed app. Offering it is a door marked "door". */
  | 'standalone'
  /** In a tab, but the app is on this device already. Say so, offer nothing. */
  | 'installed'
  /** A prompt was offered and kept. One tap does the whole thing. */
  | 'ready'
  /** iOS: no prompt exists, so the Share sheet has to be described. */
  | 'apple'
  /** Android, but no prompt in hand: Chrome's own menu is the way. */
  | 'android'
  /** Another app's built-in browser. It cannot install; leaving it can. */
  | 'webview'
  /** Installing is not possible here. Say nothing at all. */
  | 'unavailable'

/**
 * Chrome's `beforeinstallprompt`, which is in no browser's type definitions
 * because it is in no specification — a Chromium extension that Firefox and
 * Safari have both declined to implement.
 */
interface InstallPromptEvent extends Event {
  prompt: () => Promise<void>
}

/**
 * `navigator.getInstalledRelatedApps()`, which answers "is one of the apps
 * this manifest claims kinship with installed?" — and, because the manifest
 * names *itself* under `related_applications`, therefore also "is this app
 * installed?". It is the only way to know that from an ordinary tab: the
 * display-mode query below only ever describes the window it is asked in.
 *
 * Chromium-only, and it may quietly answer "no" — in which case the member
 * gets the ordinary offer, which is a fair thing to show somebody whose
 * browser will not say.
 */
interface RelatedApp {
  platform?: string
}

export interface InstallStore {
  /** Start listening. Idempotent; called once from `main.tsx`. */
  watch: () => void
  subscribe: (listener: () => void) => () => void
  state: () => InstallState
  /**
   * Show the browser's own install dialogue. Resolves when it has been shown,
   * not when it has been answered — what the member chooses is between them
   * and their browser, and the portal has no business knowing.
   */
  prompt: () => Promise<void>
}

export function createInstallStore(): InstallStore {
  let saved: InstallPromptEvent | null = null
  let installed = false
  let watching = false

  const listeners = new Set<() => void>()

  const announce = () => {
    for (const listener of listeners) {
      listener()
    }
  }

  return {
    watch() {
      if (watching) {
        return
      }

      watching = true

      window.addEventListener('beforeinstallprompt', (event) => {
        // Without this Chrome shows its own bar at the foot of the screen, at
        // a moment of its choosing. The offer belongs in Settings, next to the
        // explanation of what installing does.
        event.preventDefault()

        saved = event as InstallPromptEvent
        announce()
      })

      window.addEventListener('appinstalled', () => {
        // A saved prompt is spent as soon as the app exists.
        saved = null
        installed = true
        announce()
      })

      void alreadyInstalled().then((yes) => {
        if (yes) {
          installed = true
          announce()
        }
      })
    },

    subscribe(listener) {
      listeners.add(listener)

      return () => {
        listeners.delete(listener)
      }
    },

    state() {
      if (isStandalone()) {
        return 'standalone'
      }

      if (installed) {
        return 'installed'
      }

      if (saved !== null) {
        return 'ready'
      }

      if (isApple()) {
        return 'apple'
      }

      if (isAndroid()) {
        return isWebView() ? 'webview' : 'android'
      }

      return 'unavailable'
    },

    async prompt() {
      const event = saved

      if (event === null) {
        return
      }

      // A prompt may be shown once; a second `prompt()` on the same event
      // throws. Chrome fires a fresh one on a later visit if the member
      // dismissed this one — and until it does, the offer falls back to
      // describing Chrome's menu rather than disappearing.
      saved = null
      announce()

      await event.prompt()
    },
  }
}

/** Whether the window this is running in was opened from a home screen. */
function isStandalone(): boolean {
  if (typeof window.matchMedia === 'function') {
    if (window.matchMedia('(display-mode: standalone), (display-mode: minimal-ui)').matches) {
      return true
    }
  }

  // iOS's own flag, which predates the media query and is still the only one
  // Safari sets.
  return (navigator as { standalone?: boolean }).standalone === true
}

async function alreadyInstalled(): Promise<boolean> {
  const query = (navigator as { getInstalledRelatedApps?: () => Promise<RelatedApp[]> })
    .getInstalledRelatedApps

  if (typeof query !== 'function') {
    return false
  }

  try {
    const apps = await query.call(navigator)

    // `webapp` is this portal itself; any other platform would be a native app
    // that is none of our business.
    return apps.some((app) => app.platform === 'webapp')
  } catch {
    // Not a secure context, not supported, refused — all of which mean the
    // same thing here: the browser will not say, so do not pretend to know.
    return false
  }
}

/**
 * iPhone and iPad, where installing exists but is never offered: Safari has no
 * `beforeinstallprompt`, and every browser on iOS is Safari underneath, so the
 * Share sheet is the only route and describing it is the only help possible.
 *
 * An iPad reports itself as a Macintosh. The touch points give it away — a
 * desktop Mac has none.
 */
function isApple(): boolean {
  const agent = navigator.userAgent

  return (
    /iPhone|iPad|iPod/.test(agent) || (/Macintosh/.test(agent) && navigator.maxTouchPoints > 1)
  )
}

function isAndroid(): boolean {
  return /Android/.test(navigator.userAgent)
}

/**
 * Android's embedded browser — what WhatsApp, Facebook and half the mailing
 * apps open a link in. It has no menu to install from and no home screen to
 * install to, and for this portal's audience it is the single likeliest reason
 * an install offer never appeared: the link arrived in a family chat and was
 * tapped there.
 *
 * `wv` in the user agent is Android WebView announcing itself, which it has
 * done since Lollipop. An app that opens links in a Custom Tab is real Chrome
 * and is not caught here, correctly — a Custom Tab can install.
 */
function isWebView(): boolean {
  return /;\s*wv\)/.test(navigator.userAgent)
}

export const installStore = createInstallStore()

export function useInstallState(): InstallState {
  return useSyncExternalStore(installStore.subscribe, installStore.state)
}
