import { useTranslation } from 'react-i18next'
import { installStore, useInstallState } from '../pwa/install'
import { Button, Card, Section } from './ui'

/**
 * The offer to install, shown only when there is something to offer.
 *
 * It lives in Settings rather than in a bar across the top of every screen.
 * A prompt that follows a member around is the thing that teaches them to
 * dismiss whatever appears at the top of this portal, and the next thing to
 * appear there is "no connection" — which they need to read. Settings is one
 * of four destinations, always one tap away, and already the place where "my
 * own participation" is decided.
 *
 * Nothing is rendered once the portal is installed, or in a browser that will
 * not install it: an explanation of an impossible action is worse than
 * silence.
 */
export function InstallPortal() {
  const { t } = useTranslation()
  const state = useInstallState()

  if (state === 'installed' || state === 'unavailable') {
    return null
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
          // iOS, where the portal cannot ask — so it says where the button is
          // that the member has to press themselves.
          <p className="mt-4 text-base text-slate-900">{t('install.apple')}</p>
        )}
      </Card>
    </Section>
  )
}
