import { useSyncExternalStore } from 'react'

/**
 * "Put this on your home screen" — and the four answers a browser can give.
 *
 * Nothing here can be asked directly. There is no API for "is this app
 * installed"; there is only an event a browser may or may not fire, a media
 * query that says how the current window is being displayed, and — on iOS —
 * neither of those. So the state is assembled from what is observable, and the
 * default is to say nothing at all.
 */
export type InstallState =
  /** Already on a home screen, or being read from one. Offering again is noise. */
  | 'installed'
  /** The browser offered a prompt and we kept it. One tap does the whole thing. */
  | 'ready'
  /** iOS: there is no prompt to keep, so the two taps have to be described. */
  | 'manual'
  /** Nothing useful to offer. Say nothing rather than something that will not work. */
  | 'unavailable'

/**
 * Chrome's `beforeinstallprompt`, which is not in the DOM's type definitions
 * because it is not in any specification — it is a Chromium extension that
 * Firefox and Safari have both declined to implement.
 */
interface InstallPromptEvent extends Event {
  prompt: () => Promise<void>
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
    },

    subscribe(listener) {
      listeners.add(listener)

      return () => {
        listeners.delete(listener)
      }
    },

    state() {
      if (installed || isInstalled()) {
        return 'installed'
      }

      if (saved !== null) {
        return 'ready'
      }

      return isApple() ? 'manual' : 'unavailable'
    },

    async prompt() {
      const event = saved

      if (event === null) {
        return
      }

      // A prompt may be shown once; a second `prompt()` on the same event
      // throws. Chrome fires a fresh one on a later visit if the member
      // dismissed this one, which is also why the offer disappearing after a
      // dismissal is the right behaviour rather than a bug.
      saved = null
      announce()

      await event.prompt()
    },
  }
}

/**
 * Whether the window this is running in was opened from a home screen.
 *
 * `display-mode: standalone` matches the manifest; `minimal-ui` is what some
 * browsers substitute. `navigator.standalone` is iOS's own flag, which
 * predates the media query and is still the only one Safari sets.
 */
function isInstalled(): boolean {
  if (typeof window.matchMedia === 'function') {
    if (window.matchMedia('(display-mode: standalone), (display-mode: minimal-ui)').matches) {
      return true
    }
  }

  return (navigator as { standalone?: boolean }).standalone === true
}

/**
 * iPhone and iPad, where installing exists but is not offered: Safari has no
 * `beforeinstallprompt`, and every browser on iOS is Safari underneath, so the
 * Share sheet is the only route and describing it is the only help possible.
 *
 * An iPad reports itself as a Macintosh. The touch points are what give it
 * away — a desktop Mac has none.
 */
function isApple(): boolean {
  const agent = navigator.userAgent

  return (
    /iPhone|iPad|iPod/.test(agent) || (/Macintosh/.test(agent) && navigator.maxTouchPoints > 1)
  )
}

export const installStore = createInstallStore()

export function useInstallState(): InstallState {
  return useSyncExternalStore(installStore.subscribe, installStore.state)
}
