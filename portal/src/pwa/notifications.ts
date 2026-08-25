/**
 * The browser half of a notification: permission, and an address to knock on.
 *
 * Everything here is deliberately small and returns plain values, because the
 * decisions worth testing are not the API calls — they are what state a member
 * is in and what the screen may therefore offer them.
 */

/** What the browser will allow, before anybody is asked. */
export type Permission =
  /** No service worker, no push API, or no `Notification`. Offer nothing. */
  | 'unsupported'
  /** Never asked. The one state where a button may ask. */
  | 'askable'
  /** Asked and allowed. */
  | 'granted'
  /**
   * Asked and refused. A page cannot ask again — only the member can undo it,
   * in the browser's own settings, so the screen has to say that rather than
   * offer a button that will do nothing.
   */
  | 'blocked'

export function permission(): Permission {
  if (
    typeof Notification === 'undefined' ||
    !('serviceWorker' in navigator) ||
    !('PushManager' in window)
  ) {
    return 'unsupported'
  }

  if (Notification.permission === 'granted') {
    return 'granted'
  }

  return Notification.permission === 'denied' ? 'blocked' : 'askable'
}

/**
 * The VAPID public key, as the browser wants it.
 *
 * The server sends base64url, `pushManager.subscribe` wants bytes. Getting
 * this wrong fails at subscribe time with a message that names neither the key
 * nor the encoding.
 */
export function applicationServerKey(base64url: string): Uint8Array<ArrayBuffer> {
  const padded = base64url.padEnd(base64url.length + ((4 - (base64url.length % 4)) % 4), '=')
  const binary = atob(padded.replace(/-/g, '+').replace(/_/g, '/'))
  // Explicitly over an ArrayBuffer: `subscribe` wants a BufferSource, and a
  // Uint8Array over a SharedArrayBuffer is not one.
  const bytes = new Uint8Array(new ArrayBuffer(binary.length))

  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index)
  }

  return bytes
}

/**
 * Ask, subscribe, and return the address to store — or null if the member
 * said no, which is an answer rather than a failure.
 */
export async function enable(publicKey: string): Promise<string | null> {
  if (permission() === 'blocked' || publicKey === '') {
    return null
  }

  // Must be called from a user gesture, which is why this is reached from a
  // button and never from an effect.
  if ((await Notification.requestPermission()) !== 'granted') {
    return null
  }

  // Not `resume()`: it guards on `Notification.permission`, which is exactly
  // the question that was just answered — and a browser that has said yes to
  // the prompt has not necessarily updated that property yet.
  return subscribeHere(publicKey)
}

/**
 * Subscribe without asking anybody anything.
 *
 * The half of `enable()` after the question, for the case where the question
 * has already been answered: this device was switched on, the member signed
 * out, and now the same member has signed back in (`AuthProvider`). Permission
 * belongs to the browser and outlives the session, so there is nothing to ask
 * — and asking is exactly what may not happen here, because a permission
 * prompt outside a user gesture is a prompt nobody clicked anything to get.
 *
 * Anything short of `granted` answers null rather than prompting.
 */
export async function resume(publicKey: string): Promise<string | null> {
  if (permission() !== 'granted' || publicKey === '') {
    return null
  }

  return subscribeHere(publicKey)
}

/** The subscription itself, once somebody else has established that it may be made. */
async function subscribeHere(publicKey: string): Promise<string | null> {
  const registration = await navigator.serviceWorker.ready

  // An existing subscription is reused rather than replaced: it is the same
  // device, and re-subscribing would hand out a new address while the old one
  // is still on file.
  const existing = await registration.pushManager.getSubscription()

  if (existing !== null) {
    return existing.endpoint
  }

  const subscription = await registration.pushManager.subscribe({
    // Required by Chrome, and honest here: every push this portal sends does
    // show something.
    userVisibleOnly: true,
    applicationServerKey: applicationServerKey(publicKey),
  })

  return subscription.endpoint
}

/**
 * Stop, and return the address that was in use so the server can forget it
 * too. Null when there was nothing subscribed on this device.
 */
export async function disable(): Promise<string | null> {
  if (!('serviceWorker' in navigator)) {
    return null
  }

  const registration = await navigator.serviceWorker.ready
  const subscription = await registration.pushManager.getSubscription()

  if (subscription === null) {
    return null
  }

  const endpoint = subscription.endpoint

  // Told to the browser first: if the server call then fails, the member is
  // not being knocked on any more, which is what they asked for. The stale row
  // on the server is cleaned up the first time it is knocked on.
  await subscription.unsubscribe()

  return endpoint
}

/** Whether this device — not this account — has a subscription. */
export async function subscribedHere(): Promise<boolean> {
  if (!('serviceWorker' in navigator) || permission() !== 'granted') {
    return false
  }

  const registration = await navigator.serviceWorker.ready

  return (await registration.pushManager.getSubscription()) !== null
}

/**
 * Which member had notifications switched on *on this device*.
 *
 * Signing out unsubscribes the device (§2.52), and rightly: a tablet that
 * keeps buzzing for the account somebody just left announces to the next
 * person that something arrived for the last one. But leaving is not the same
 * as changing your mind, and a member who switched notifications on and then
 * signed out had to go and find the switch again every time. So the *wish* is
 * remembered here, and honoured the next time that member signs in.
 *
 * **An account id and not a boolean**, which is the whole reason this is
 * worth writing down. A flag saying "notifications were on here" would switch
 * them on for whoever signs in next, which on a shared tablet is precisely the
 * thing §2.52 refused to do. The id is what makes "this member, on this
 * device" answerable.
 *
 * It is not a credential and unlocks nothing: it is a number that says
 * somebody with that portal id reads this family's portal in this browser.
 * The third thing the portal keeps in browser storage, after the language and
 * the install prompt's "asked already" flag — and, like them, a device
 * preference rather than personal data of anybody else's.
 *
 * Switching notifications off clears it, because that *is* changing your mind.
 */
const STORAGE_KEY = 'portal.notifications'

export function rememberNotifications(userId: number): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, String(userId))
  } catch {
    // Storage can be unavailable — a private window, blocked cookies. The
    // member keeps notifications on this session; they just have to switch
    // them on again after the next sign-in, which is where this started.
  }
}

export function forgetNotifications(): void {
  try {
    window.localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Nothing to do about it, and nothing that breaks: a flag that cannot be
    // cleared only ever causes a subscription the member can switch off.
  }
}

/** The remembered account, or null where this device has no wish on file. */
export function rememberedNotifications(): number | null {
  try {
    const stored = Number(window.localStorage.getItem(STORAGE_KEY))

    return Number.isInteger(stored) && stored > 0 ? stored : null
  } catch {
    return null
  }
}

/**
 * Told when this device's subscription changed without anybody on screen
 * having pressed anything.
 *
 * There is exactly one such moment: the silent restore after a sign-in
 * (`AuthProvider`). Everywhere else the member pressed the switch, and the
 * screen that owns the switch already knows.
 *
 * A listener rather than a query invalidation, because the question the switch
 * asks — *is this device subscribed?* — is answered by the browser and not by
 * the server, and no amount of refetching `/push` makes the browser answer it
 * again. Refetching is worth doing as well, and is not enough on its own:
 * TanStack keeps the previous object where the JSON is unchanged, so a member
 * already subscribed on their telephone would see no new `data` and the
 * effect keyed on it would not run.
 */
type Listener = () => void

const listeners = new Set<Listener>()

export function onSubscriptionChange(listener: Listener): () => void {
  listeners.add(listener)

  return () => {
    listeners.delete(listener)
  }
}

export function subscriptionChanged(): void {
  for (const listener of [...listeners]) {
    listener()
  }
}
