import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../auth/AuthProvider'
import { useMe, useUpdateProfile } from '../api/queries'
import { LanguageSwitcher } from '../components/LanguageSwitcher'
import {
  Button,
  Card,
  ErrorNotice,
  Field,
  PageHeading,
  Section,
  SuccessNote,
  Toggle,
} from '../components/ui'

export function Settings() {
  const { t } = useTranslation()
  const { me, signOut } = useAuth()
  const { data } = useMe()
  const mutation = useUpdateProfile()
  const [busy, setBusy] = useState(false)

  const profile = data?.profile ?? null

  const [displayName, setDisplayName] = useState('')

  // The field follows the server until the member starts typing.
  useEffect(() => {
    setDisplayName(profile?.display_name_override ?? '')
  }, [profile?.display_name_override])

  async function onSignOut() {
    setBusy(true)
    await signOut()
  }

  const visible = profile?.visible_in_directory === true
  const nameChanged = displayName.trim() !== (profile?.display_name_override ?? '')

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
            {mutation.isError && (
              <div className="mb-4">
                <ErrorNotice error={mutation.error} />
              </div>
            )}

            {mutation.isSuccess && !nameChanged && (
              <div className="mb-4">
                <SuccessNote>{t('settings.saved')}</SuccessNote>
              </div>
            )}

            <Toggle
              label={t('settings.directoryToggle')}
              hint={t('settings.directoryExplain')}
              checked={visible}
              disabled={mutation.isPending}
              onChange={(checked) => mutation.mutate({ visible_in_directory: checked })}
            />

            <div className="mt-6">
              <Field
                label={t('settings.displayName')}
                hint={t('settings.displayNameHint')}
                value={displayName}
                onChange={(event) => setDisplayName(event.target.value)}
              />
              <Button
                variant="secondary"
                disabled={mutation.isPending || !nameChanged}
                onClick={() =>
                  mutation.mutate({
                    display_name_override: displayName.trim() === '' ? null : displayName.trim(),
                  })
                }
              >
                {mutation.isPending ? t('settings.saving') : t('settings.save')}
              </Button>
            </div>
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
