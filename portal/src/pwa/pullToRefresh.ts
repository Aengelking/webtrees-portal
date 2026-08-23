import { useEffect, useState } from 'react'

/**
 * Pull down at the top of a screen to load it again.
 *
 * **The installed app has no other way.** A browser tab has one — Chrome and
 * Safari both reload when you drag past the top, and the address bar has a
 * button — but a portal opened from the home screen has neither. There is no
 * chrome, and `display: standalone` switches the browser's own gesture off. A
 * member looking at yesterday's list has been stuck with it, and closing the
 * app does not help either, because a service worker serves the shell from
 * cache.
 *
 * So this is deliberately **only in the installed app**. Running it in a tab
 * would put two pull-to-refreshes on one screen: ours firing at 72px and the
 * browser's at whatever it likes, one of them reloading the data and the other
 * reloading the page.
 */

/** How far to drag before it counts. Far enough not to fire while scrolling. */
const THRESHOLD = 72

/**
 * How far the indicator may travel, past which the pull stops moving it.
 *
 * The resistance below is what makes the gesture feel attached to the finger:
 * the first pixels follow it exactly and the last ones barely move, so the
 * screen says "this is as far as it goes" without anything having to stop.
 */
const LIMIT = 110

/** Where the drag stops following the finger one-for-one. */
const FREE = 40

export type PullPhase = 'idle' | 'pulling' | 'ready' | 'refreshing'

export interface PullState {
  phase: PullPhase
  /** How far the indicator has travelled, in pixels. */
  distance: number
}

/**
 * The gesture, as a distance and a phase.
 *
 * `onRefresh` is awaited, and the indicator stays until it settles — a spinner
 * that vanishes before the answer arrives has told the member the wrong thing.
 * A failure looks the same as a success on purpose: what went wrong belongs to
 * the screen underneath, which renders its own error, and a second one on top
 * of the first says nothing new.
 */
export function usePullToRefresh(
  enabled: boolean,
  onRefresh: () => Promise<unknown>,
): PullState {
  const [state, setState] = useState<PullState>({ phase: 'idle', distance: 0 })

  useEffect(() => {
    if (!enabled) {
      return
    }

    let start: number | null = null
    let travelled = 0
    let busy = false

    function begin(event: TouchEvent): void {
      // Only from the very top, and only a single finger: a pinch that starts
      // at the top of the page is not a pull.
      if (busy || window.scrollY > 0 || event.touches.length !== 1) {
        start = null

        return
      }

      start = event.touches[0]?.clientY ?? null
      travelled = 0
    }

    function move(event: TouchEvent): void {
      if (start === null || busy) {
        return
      }

      const y = event.touches[0]?.clientY ?? 0
      const raw = y - start

      // Scrolling up, or the page moved under us. Give the gesture back.
      if (raw <= 0 || window.scrollY > 0) {
        start = null
        travelled = 0
        setState({ phase: 'idle', distance: 0 })

        return
      }

      travelled = resisted(raw)

      // Held back so the page does not rubber-band behind the indicator. Only
      // once the pull is real: swallowing the first pixel of every downward
      // touch would make a screen that is already at the top feel stuck.
      if (raw > 4 && event.cancelable) {
        event.preventDefault()
      }

      setState({ phase: travelled >= THRESHOLD ? 'ready' : 'pulling', distance: travelled })
    }

    function end(): void {
      if (start === null || busy) {
        return
      }

      const reached = travelled >= THRESHOLD

      start = null
      travelled = 0

      if (!reached) {
        setState({ phase: 'idle', distance: 0 })

        return
      }

      busy = true
      setState({ phase: 'refreshing', distance: THRESHOLD })

      void onRefresh()
        .catch(() => undefined)
        .finally(() => {
          busy = false
          setState({ phase: 'idle', distance: 0 })
        })
    }

    // `passive: false` on the move, because that is the one that has to be
    // able to hold the page still. The other two never prevent anything.
    window.addEventListener('touchstart', begin, { passive: true })
    window.addEventListener('touchmove', move, { passive: false })
    window.addEventListener('touchend', end, { passive: true })
    window.addEventListener('touchcancel', end, { passive: true })

    return () => {
      window.removeEventListener('touchstart', begin)
      window.removeEventListener('touchmove', move)
      window.removeEventListener('touchend', end)
      window.removeEventListener('touchcancel', end)
    }
  }, [enabled, onRefresh])

  return state
}

/**
 * The first `FREE` pixels follow the finger; everything after them is dragged
 * through treacle towards `LIMIT`.
 */
function resisted(raw: number): number {
  if (raw <= FREE) {
    return raw
  }

  return Math.min(LIMIT, FREE + (raw - FREE) / 2.5)
}
