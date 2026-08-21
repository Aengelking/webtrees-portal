import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

/**
 * Whether the browser thinks it has a connection.
 *
 * `navigator.onLine` is famously weak — it says whether there is *a* network,
 * not whether anything is reachable across it — so nothing here is allowed to
 * depend on it. It never suppresses a request and never changes what is
 * fetched; the API client's own `network_error` remains the truth. All it does
 * is explain a screen that has stopped answering, which is exactly the
 * question it can answer well: a phone that knows its radio is off knows it
 * immediately, while a request has to time out first.
 */
export function useOnline(): boolean {
  const [online, setOnline] = useState(() => navigator.onLine !== false)

  useEffect(() => {
    const update = () => setOnline(navigator.onLine !== false)

    window.addEventListener('online', update)
    window.addEventListener('offline', update)

    // Between the first render and this effect the radio may already have gone.
    update()

    return () => {
      window.removeEventListener('online', update)
      window.removeEventListener('offline', update)
    }
  }, [])

  return online
}

/**
 * Said once, at the top of every screen, rather than as an error on each one.
 *
 * This matters most in the installed app: with no address bar and no browser
 * chrome, a portal that has quietly stopped working looks broken rather than
 * offline, and there is nothing on screen to suggest otherwise.
 *
 * The wrapper is always rendered, empty, so that the live region exists before
 * the text appears in it — a live region added to the page at the same moment
 * as its content is not reliably announced.
 *
 * `aria-live` alone, deliberately, and not `role="status"`: several screens
 * already have a status of their own ("the link is shown only once"), and a
 * second, permanently present one on every page would be one more thing for a
 * screen reader user to walk past on the way to it.
 */
export function OfflineNotice() {
  const { t } = useTranslation()
  const online = useOnline()

  return (
    <div aria-live="polite" className="sticky top-0 z-40">
      {!online && (
        <p className="border-b border-amber-400 bg-amber-100 px-4 py-3 text-center text-base font-medium text-slate-900">
          {t('app.offline')}
        </p>
      )}
    </div>
  )
}
