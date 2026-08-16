import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthProvider'
import { LanguageSwitcher } from '../components/LanguageSwitcher'
import { Button, Card, PageHeading, Section } from '../components/ui'

export function Settings() {
  const { t } = useTranslation()
  const { me, signOut } = useAuth()
  const [busy, setBusy] = useState(false)

  async function onSignOut() {
    setBusy(true)
    await signOut()
  }

  return (
    <>
      <PageHeading>{t('settings.title')}</PageHeading>

      <Section title={t('settings.language')}>
        <LanguageSwitcher />
      </Section>

      {me !== null && (
        <>
          <Section title={t('settings.account')}>
            <Card>
              <p className="text-lg font-semibold text-slate-900">{me.user.real_name}</p>
              <p className="mt-1 text-base text-slate-700">{me.user.email}</p>
              <p className="mt-3 text-base text-slate-700">
                {t('settings.tree')}: {me.tree.title}
              </p>
            </Card>
          </Section>

          <Section title={t('settings.directory')}>
            <Card>
              <p className="text-base text-slate-900">
                {me.profile?.visible_in_directory === true
                  ? t('settings.directoryVisible')
                  : t('settings.directoryHidden')}
              </p>
              <p className="mt-2 text-base text-slate-700">{t('settings.directoryChange')}</p>
            </Card>
          </Section>
        </>
      )}

      <div className="mt-10">
        <Button variant="secondary" className="w-full" disabled={busy} onClick={() => void onSignOut()}>
          {t('settings.logout')}
        </Button>
      </div>
    </>
  )
}
