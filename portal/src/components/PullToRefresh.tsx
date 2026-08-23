import { useCallback } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useInstallState } from '../pwa/install'
import { usePullToRefresh } from '../pwa/pullToRefresh'

/**
 * The one gesture the installed app was missing.
 *
 * See `usePullToRefresh` for why it exists at all and why it is only in the
 * installed app. This is the part a member sees: a disc that comes down with
 * the finger, turns when it has been pulled far enough, and spins while the
 * screen is being fetched again.
 *
 * **What it refreshes is whatever is on the screen.** `type: 'active'` asks
 * TanStack Query to refetch the queries that are currently mounted and no
 * others — the person being looked at, the list being read, the conversation
 * being held. Not a page reload: that would throw away the shell, the session
 * and the scroll position to answer a question about one record.
 */
export function PullToRefresh() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  // Only where the browser's own gesture is gone. In a tab there would be two.
  const installed = useInstallState() === 'standalone'

  const refresh = useCallback(
    () => queryClient.refetchQueries({ type: 'active' }),
    [queryClient],
  )

  const { phase, distance } = usePullToRefresh(installed, refresh)

  if (phase === 'idle') {
    return null
  }

  const spinning = phase === 'refreshing'
  const ready = phase === 'ready' || spinning

  return (
    <div
      // Announced rather than drawn only: a spinner is nothing to a screen
      // reader, and the gesture that started it is not one either.
      role="status"
      aria-live="polite"
      className="pointer-events-none fixed inset-x-0 top-0 z-50 flex justify-center"
      style={{
        // The safe area, so the disc clears a notch rather than sitting under
        // it. Half the distance, because the disc is centred on it.
        transform: `translateY(calc(${Math.round(distance)}px - 100% + env(safe-area-inset-top)))`,
        // Only on the way back, so the pull itself stays glued to the finger.
        transition: spinning ? 'transform 150ms ease-out' : 'none',
      }}
    >
      <span className="m-3 flex h-11 w-11 items-center justify-center rounded-full border border-slate-300 bg-white shadow-md">
        <Arrow spinning={spinning} ready={ready} />
      </span>

      <span className="sr-only">
        {spinning ? t('refresh.running') : ready ? t('refresh.release') : t('refresh.pull')}
      </span>
    </div>
  )
}

/**
 * One mark for the whole gesture: an arrow that turns over when the pull is
 * far enough, and spins once it is doing something.
 *
 * `motion-reduce:animate-none` because a member who has asked their phone to
 * stop moving things has asked this too — the disc is still there, and the
 * live region still says what is happening.
 */
function Arrow({ spinning, ready }: { spinning: boolean; ready: boolean }) {
  return (
    <svg
      aria-hidden="true"
      width={22}
      height={22}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={[
        'text-sky-800 transition-transform duration-150',
        spinning ? 'animate-spin motion-reduce:animate-none' : '',
        ready && !spinning ? 'rotate-180' : '',
      ].join(' ')}
    >
      {spinning ? (
        // An open circle reads as "working" while it turns; a closed one just
        // sits there looking like a full stop.
        <path d="M21 12a9 9 0 1 1-6.2-8.6" />
      ) : (
        <>
          <path d="M12 5v14" />
          <path d="m5 12 7 7 7-7" />
        </>
      )}
    </svg>
  )
}
