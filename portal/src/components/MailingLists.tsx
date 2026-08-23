import { useTranslation } from 'react-i18next'
import { useMailingLists, useUpdateMailingLists } from '../api/queries'
import type { MailingList } from '../api/types'
import { Card, ErrorNotice, Section, Toggle } from '../components/ui'

/**
 * The family's round-robin letters, joined and left from the portal.
 *
 * The switch is the whole feature. Before it, coming off a list meant writing
 * to whoever administers Exchange and hoping — which is not really an
 * unsubscribe, and a list people cannot leave is a list they eventually mark
 * as spam instead. So this is deliberately as easy to switch off as on, and
 * nothing asks a member to confirm that they meant it.
 *
 * **Optimism is wrong here and the screen says so.** The list lives in
 * somebody else's cloud, reached over somebody else's network, and the honest
 * report is in two parts: the answer has been taken down, and it has or has
 * not been passed on yet. A switch that flicked and said nothing more would be
 * claiming the second when only the first is true.
 *
 * What a member is never shown is the list's address, or the reason a change
 * could not be delivered. The first is the administrator's business — see
 * `Schema/Migration11.php` in the module — and the second is a sentence about
 * a tenant, an application registration and a cmdlet, none of which are things
 * a family member can act on. "Somebody is looking at it" is both kinder and
 * more accurate.
 *
 * Renders nothing at all where the family has not set this up, which is §2.33's
 * rule: silence for something impossible, a sentence for something merely
 * harder.
 */
export function MailingLists() {
  const { t } = useTranslation()
  const { data, isError, error, refetch } = useMailingLists()
  const mutation = useUpdateMailingLists()

  // Nothing is said while the first read is in flight. This sits below several
  // sections a member came here for, and a placeholder that appears and
  // disappears under them would move the screen while they are reading it.
  if (data === undefined && !isError) {
    return null
  }

  if (isError) {
    return (
      <Section title={t('lists.title')}>
        <ErrorNotice error={error} onRetry={() => void refetch()} />
      </Section>
    )
  }

  if (data === undefined || !data.enabled || data.lists.length === 0) {
    return null
  }

  // An account with no address cannot be put on a list. Said plainly rather
  // than by showing switches that would refuse.
  if (data.address === '') {
    return (
      <Section title={t('lists.title')}>
        <Card>
          <p className="text-base text-slate-700">{t('lists.noAddress')}</p>
        </Card>
      </Section>
    )
  }

  return (
    <Section title={t('lists.title')}>
      {mutation.isError && (
        <div className="mb-4">
          <ErrorNotice error={mutation.error} />
        </div>
      )}

      <Card>
        <p className="mb-5 text-base text-slate-700">{t('lists.intro', { address: data.address })}</p>

        <div className="space-y-6">
          {data.lists.map((list) => (
            <Row
              key={list.key}
              list={list}
              busy={mutation.isPending}
              onChange={(subscribed) => mutation.mutate({ [list.key]: subscribed })}
            />
          ))}
        </div>
      </Card>
    </Section>
  )
}

function Row({
  list,
  busy,
  onChange,
}: {
  list: MailingList
  busy: boolean
  onChange: (subscribed: boolean) => void
}) {
  const { t } = useTranslation()

  return (
    <div>
      <Toggle
        label={list.name}
        {...(list.description === '' ? {} : { hint: list.description })}
        checked={list.subscribed}
        disabled={busy}
        onChange={onChange}
      />

      {/*
        Only ever about a change that has not landed. The ordinary state says
        nothing, because a line reading "done" under every switch is a line
        nobody reads — and it is the exception that has to be legible.
      */}
      {list.state !== 'applied' && (
        <p role="status" className="mt-2 text-base text-slate-700">
          {list.state === 'pending' ? t('lists.pending') : t('lists.failed')}
        </p>
      )}
    </div>
  )
}
