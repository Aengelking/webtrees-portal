/**
 * Installing the service worker — the only line the app itself has in the
 * matter. Everything the worker does is in `sw/`.
 */

/**
 * @param enabled Only in a real build. In `vite dev` the browser is served
 *   unbundled modules that change on every keystroke, and a cache in front of
 *   that is nothing but a source of confusing failures. The default is what
 *   production passes; tests pass their own.
 */
export function registerServiceWorker(enabled: boolean = import.meta.env.PROD): void {
  if (!enabled || !('serviceWorker' in navigator)) {
    return
  }

  // After load, not during it. Registering is a fetch of its own, and it may
  // not compete with the JavaScript and the first API call the member is
  // actually waiting for.
  window.addEventListener(
    'load',
    () => {
      void navigator.serviceWorker.register('/sw.js').catch(() => {
        // A registration that fails — a private window, an unsupported
        // browser, a policy that forbids it — leaves an ordinary web app that
        // works. It is not worth telling anybody about.
      })
    },
    // The module script that calls this runs before `load` fires, always, so
    // the listener is never added too late — and `once` means it does not
    // outlive the one event it is waiting for.
    { once: true },
  )
}
