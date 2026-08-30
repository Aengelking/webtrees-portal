import { useTranslation } from 'react-i18next'
import { useMe, useUpdateProfile } from '../api/queries'
import { Button, Card, ErrorNotice, Section } from './ui'

/**
 * The one question the portal asks about the member directory.
 *
 * The switch has always existed, in the settings, off by default — which is
 * the right default and had one flaw: nothing ever asked. A member who never
 * opens the settings never meets it, so the directory stays empty, and the
 * contacts screen is useless for everybody including the people who would
 * gladly have said yes. Silence was being read as "no", when it only ever
 * meant "nobody was asked".
 *
 * So it asks here, on the screen every member lands on, and it asks **until
 * there is an answer** rather than once per device. That distinction is the
 * whole reason the state lives on the server (`directory_decided`, see
 * `Migration20`): a flag in this browser would ask the same person again on
 * their next telephone, and would never reach the members who signed in months
 * ago — who are exactly the ones this exists for.
 *
 * Two buttons and no third. There is deliberately no "later": a card that can
 * be postponed is one that gets postponed, and the member would meet it again
 * on the next visit having gained nothing. Both answers are equally easy to
 * press and neither is preselected — the point is a decision, not a nudge
 * towards being listed. And neither is final: the switch in the settings goes
 * on saying what it always said, and changing it there is changing an answer,
 * not being asked again.
 */
export function DirectoryPrompt() {
  const { t } = useTranslation()
  const { data } = useMe()
  const mutation = useUpdateProfile()

  // Nothing while the answer is unknown: a card that flickers into view after
  // the profile arrives and out again once it says "already answered" is worse
  // than one that appears a moment later.
  //
  // A member with no profile row at all — never connected to anybody, never
  // changed a setting — has certainly never answered this, so the missing row
  // asks rather than hides. Answering writes the row (`updateProfile`).
  if (data === undefined || data.profile?.directory_decided === true) {
    return null
  }

  return (
    <Section title={t('directoryPrompt.title')}>
      <Card>
        <p className="text-base text-slate-700">{t('directoryPrompt.body')}</p>
        <p className="mt-2 text-base text-slate-600">{t('directoryPrompt.hint')}</p>

        {mutation.isError && (
          <div className="mt-4">
            <ErrorNotice error={mutation.error} />
          </div>
        )}

        <div className="mt-4 flex flex-col gap-3 sm:flex-row">
          <Button
            disabled={mutation.isPending}
            onClick={() => mutation.mutate({ visible_in_directory: true })}
          >
            {t('directoryPrompt.yes')}
          </Button>
          <Button
            variant="secondary"
            disabled={mutation.isPending}
            onClick={() => mutation.mutate({ visible_in_directory: false })}
          >
            {t('directoryPrompt.no')}
          </Button>
        </div>
      </Card>
    </Section>
  )
}
