import { NavLink, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { ReactNode } from 'react'
import { useAuth } from '../auth/AuthProvider'

/**
 * Four destinations.
 *
 * Still four in Phase 11, and the second one is now Kontakte rather than
 * Mitglieder — the two swapped places. Phase 11 reached contacts *from* the
 * directory, on the reasoning that the directory is where somebody looking
 * for a person already goes. That had it the wrong way round: the directory
 * is everybody, and a member looks somebody up now and then; contacts are
 * their own people, and that is the screen they come back to. So the
 * directory moved inside Kontakte, where its search sits at the top, and the
 * badge for "somebody wants to connect with you" is now on the entry it
 * actually belongs to.
 *
 * This said "three, no more" until Phase 10, and the rule was a good one: it
 * kept "invite somebody" off the bar, where it did not belong. Messages are
 * the case it was wrong about. The test the rule was really applying is *how
 * often a member comes back to it* — inviting happens once or twice, and an
 * inbox is checked whenever something might have arrived. A message nobody
 * notices is worse than a slightly busier bar, and the unread badge only
 * works somewhere permanently visible.
 *
 * Four is the limit. At ~80px each they still fit a 320px screen; a fifth
 * would not, and anything wanting one belongs on one of these four.
 */
const DESTINATIONS = [
  { to: '/me', key: 'nav.profile', icon: PersonIcon, badge: 'none' },
  { to: '/contacts', key: 'nav.contacts', icon: PeopleIcon, badge: 'connections' },
  { to: '/messages', key: 'nav.messages', icon: MessageIcon, badge: 'unread' },
  { to: '/settings', key: 'nav.settings', icon: GearIcon, badge: 'none' },
] as const

export function Layout() {
  const { t } = useTranslation()
  const { me } = useAuth()

  // Both lists, added: the badge answers "is there something for me", and a
  // member who has one message and one conversation message has two things.
  const unread = (me?.unread_messages ?? 0) + (me?.unread_conversations ?? 0)
  // Optional on `Me`: the module and the portal deploy separately, so a
  // server that predates connections simply has no number to show.
  const requests = me?.connection_requests ?? 0

  const counts = { none: 0, unread, connections: requests } as const

  return (
    <div className="min-h-dvh bg-slate-100 text-slate-900">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-3 focus:text-base focus:font-semibold focus:outline focus:outline-2 focus:outline-sky-700"
      >
        {t('app.skipToContent')}
      </a>

      <main id="main" className="mx-auto w-full max-w-2xl px-4 pb-28 pt-6 sm:pb-10">
        <Outlet />
      </main>

      <nav
        aria-label={t('nav.main')}
        className="fixed inset-x-0 bottom-0 border-t border-slate-300 bg-white sm:static sm:mx-auto sm:max-w-2xl sm:border-0"
      >
        <ul className="mx-auto flex max-w-2xl">
          {DESTINATIONS.map(({ to, key, icon: Icon, badge }) => (
            <li key={to} className="flex-1">
              <NavLink
                to={to}
                className={({ isActive }) =>
                  [
                    'flex min-h-[60px] flex-col items-center justify-center gap-1 px-2 py-2 text-sm font-medium',
                    'focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-sky-700',
                    isActive ? 'text-sky-800' : 'text-slate-700 hover:text-slate-900',
                  ].join(' ')
                }
              >
                {({ isActive }) => (
                  <>
                    <span className="relative">
                      <Icon active={isActive} />
                      {counts[badge] > 0 && (
                        // Hidden from the accessible name on purpose: the
                        // number is repeated in words below, and without this
                        // the link reads as "1 Nachrichten — 1 ungelesen".
                        <span
                          aria-hidden="true"
                          className="absolute -right-2 -top-1 min-w-[18px] rounded-full bg-sky-800 px-1 text-center text-xs font-semibold leading-[18px] text-white"
                        >
                          {counts[badge] > 9 ? '9+' : counts[badge]}
                        </span>
                      )}
                    </span>
                    <span>
                      {t(key)}
                      {/*
                        The badge is a number in a circle, which a screen
                        reader reads as a stray digit next to a label. The
                        count belongs in the link's name instead.
                      */}
                      {counts[badge] > 0 && (
                        <span className="sr-only"> — {t(`nav.badge.${badge}`, { count: counts[badge] })}</span>
                      )}
                    </span>
                  </>
                )}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>
    </div>
  )
}

function iconProps(active: boolean) {
  return {
    width: 24,
    height: 24,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: active ? 2.4 : 1.8,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    'aria-hidden': true,
  }
}

function PersonIcon({ active }: { active: boolean }): ReactNode {
  return (
    <svg {...iconProps(active)}>
      <circle cx="12" cy="8" r="4" />
      <path d="M4 21c0-4 3.6-6 8-6s8 2 8 6" />
    </svg>
  )
}

function PeopleIcon({ active }: { active: boolean }): ReactNode {
  return (
    <svg {...iconProps(active)}>
      <circle cx="9" cy="8" r="3.5" />
      <path d="M2 20c0-3.5 3.1-5.5 7-5.5s7 2 7 5.5" />
      <path d="M16 5.5a3.5 3.5 0 0 1 0 7" />
      <path d="M18 14.8c2.4.6 4 2.4 4 5.2" />
    </svg>
  )
}

function MessageIcon({ active }: { active: boolean }): ReactNode {
  return (
    <svg {...iconProps(active)}>
      <path d="M4 5.5h16v11H8.5L4 20V5.5Z" />
    </svg>
  )
}

function GearIcon({ active }: { active: boolean }): ReactNode {
  return (
    <svg {...iconProps(active)}>
      <circle cx="12" cy="12" r="3.2" />
      <path d="M12 2.5v2.2M12 19.3v2.2M4.2 4.2l1.6 1.6M18.2 18.2l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.2 19.8l1.6-1.6M18.2 5.8l1.6-1.6" />
    </svg>
  )
}
