import { useEffect } from 'react'

/**
 * The number on the home-screen icon.
 *
 * The same count the navigation bar carries — messages and conversation
 * messages added — put where a member looks without opening anything. It is
 * the one place the portal shows something about them outside its own window,
 * so it is worth being exact about what that something is: **a number and
 * nothing else.** No name, no text, no sender. The same line §2.36 drew for
 * the lock screen, and for the same reason: an icon on a home screen is read
 * by whoever picks the phone up.
 *
 * `navigator.setAppBadge` is Chromium's and Safari's, and only for an
 * installed app — in a tab it either does not exist or does nothing. Both are
 * fine and neither is worth saying anything about: this is decoration on an
 * icon that a browser tab does not have.
 */
interface Badging {
  setAppBadge?: (count?: number) => Promise<void>
  clearAppBadge?: () => Promise<void>
}

/**
 * Set it, clear it at zero, and never let it fail loudly.
 *
 * Safari rejects this until the app has been granted notification permission,
 * and rejects it in a plain tab. A badge nobody can see is not a problem to
 * report to somebody — there is nothing they could do about it, and the count
 * is on the navigation bar in front of them anyway.
 */
export function showBadge(count: number): void {
  const badging = navigator as Badging

  try {
    if (count > 0) {
      void badging.setAppBadge?.(count).catch(() => undefined)

      return
    }

    void badging.clearAppBadge?.().catch(() => undefined)
  } catch {
    // Synchronous throws happen too, in browsers that half-implement it.
  }
}

/**
 * Keep the icon in step with the screen for as long as a member is signed in.
 *
 * The cleanup clears it, which is what signing out has to do: the layout is
 * only mounted while somebody is signed in, so its unmount is exactly the
 * moment the count stops being anybody's. A number left on the icon after
 * signing out would be a stranger's unread count on a shared phone.
 */
export function useAppBadge(count: number): void {
  useEffect(() => {
    showBadge(count)
  }, [count])

  useEffect(() => {
    return () => showBadge(0)
  }, [])
}
