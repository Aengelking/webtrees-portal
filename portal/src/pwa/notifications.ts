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
