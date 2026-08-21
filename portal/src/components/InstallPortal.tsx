import { useTranslation } from 'react-i18next'
import { installStore, useInstallState } from '../pwa/install'
import { Button, Card, Section } from './ui'

/**
 * The offer to install, and — where an offer is impossible but installing is
 * not — the way to do it by hand.
 *
 * It lives in Settings rather than in a bar across the top of every screen. A
 * prompt that follows a member around is the thing that teaches them to
 * dismiss whatever appears at the top of this portal, and the next thing to
 * appear there is "no connection", which they need to read. Settings is one of
 * four destinations, always one tap away.
 *
 * Only two states render nothing: this window already *is* the app, and a
 * browser where installing cannot happen at all. Everywhere else there is
 * either a button or a sentence saying where the member has to tap instead —
 * because a screen that promises an app and offers no way to get one is the
 * bug this component was rewritten to fix.
 */
export function InstallPortal() {
  const { t } = useTranslation()
  const state = useInstallState()

  if (state === 'standalone' || state === 'unavailable') {
    return null
  }

  if (state === 'installed') {
    return (
      <Section title={t('install.title')}>
        <Card>
          <p className="text-base text-slate-700">{t('install.done')}</p>
        </Card>
      </Section>
    )
  }

  return (
    <Section title={t('install.title')}>
      <Card>
        <p className="text-base text-slate-700">{t('install.body')}</p>

        {state === 'ready' ? (
          <div className="mt-4">
            <Button onClick={() => void installStore.prompt()}>{t('install.action')}</Button>
          </div>
        ) : (
          // No prompt to show — either because this browser has none to give
          // (iOS), because it did not give one (Android), or because the page
          // is inside another app. Each of those has a different way out, and
          // the sentence is the way out.
          <p className="mt-4 text-base text-slate-900">{t(`install.${state}`)}</p>
        )}
      </Card>
    </Section>
  )
}
