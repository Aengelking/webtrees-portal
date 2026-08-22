import { useState } from 'react'
import type { FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useInvitations, useInvite, useWithdrawInvitation } from '../api/queries'
import { ApiError } from '../api/client'
import type { InvitationCandidate, MemberInvitation } from '../api/types'
import { ShareLink } from '../components/ShareLink'
import { Button, Card, ErrorNotice, Field, Loading, Notice, PageHeading, Section } from '../components/ui'

/**
 * A member invites their own close family.
 *
 * The list on this screen is the same walk the member's own page already
 * does, at the same access level, stopping at the limit the family set — so
 * opening it discloses nobody they could not already reach. Everyone on it is
 * named by their relationship, because "Ihr Bruder — Dieter Beispiel" is what
 * makes it obvious that the right person is about to be picked.
 *
 * The link appears once. That is not an interface decision: the server keeps
 * only a hash of it, so there is nothing to show again later. The screen says
 * so where the link is, not in a help page nobody opens.
 *
 * `?xref=` preselects somebody — how a member arrives from that person's own
 * page. It is a starting position and nothing more: the list is still the
 * list, the choice can still be changed, and an XREF that is not on it simply
 * selects nobody, because the server re-checks every rule anyway and a URL is
 * not an authority on who may be invited.
 */
export function Invite() {
  const { t } = useTranslation()
  const { data, isPending, isError, error, refetch } = useInvitations()
  const invite = useInvite()
  const withdraw = useWithdrawInvitation()

  const [params] = useSearchParams()

  const [selected, setSelected] = useState(params.get('xref') ?? '')
  const [email, setEmail] = useState('')
  const [link, setLink] = useState<string | null>(null)
  const [failure, setFailure] = useState<string | null>(null)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (selected === '') {
      setFailure(t('invite.pickSomebody'))
      return
    }

    setFailure(null)

    try {
      const issued = await invite.mutateAsync({ xref: selected, email: email.trim() })

      setLink(issued.link)
      setSelected('')
      setEmail('')
    } catch (cause) {
      setFailure(messageFor(cause, t))
    }
  }

  return (
    <>
      <PageHeading>{t('invite.title')}</PageHeading>
      <p className="mt-2 text-base text-slate-700">{t('invite.intro')}</p>

      {isPending && <Loading />}

      {isError && (
        <div className="mt-6">
          <ErrorNotice error={error} onRetry={() => void refetch()} />
        </div>
      )}

      {data !== undefined && (
        <>
          {link !== null && <IssuedLink link={link} onDismiss={() => setLink(null)} />}

          {!data.enabled && (
            <div className="mt-6">
              <Notice title={t('invite.off.title')} body={t('invite.off.body')} />
            </div>
          )}

          {data.enabled && !data.linked && (
            <div className="mt-6">
              <Notice title={t('invite.noRecord.title')} body={t('invite.noRecord.body')} />
            </div>
          )}

          {data.enabled && data.linked && (
            <Section title={t('invite.chooseTitle')}>
              {data.remaining === 0 ? (
                <Notice title={t('invite.quota.title')} body={t('invite.quota.body')} />
              ) : data.candidates.length === 0 ? (
                <Notice title={t('invite.none.title')} body={t('invite.none.body')} />
              ) : (
                <form onSubmit={onSubmit} noValidate>
                  {failure !== null && (
                    <p
                      role="alert"
                      className="mb-5 rounded-lg border border-amber-400 bg-amber-50 p-4 text-base text-slate-900"
                    >
                      {failure}
                    </p>
                  )}

                  <fieldset className="mb-5">
                    <legend className="mb-3 text-base font-medium text-slate-900">
                      {t('invite.whoLegend')}
                    </legend>

                    <ul className="space-y-2">
                      {data.candidates.map((candidate) => (
                        <li key={candidate.xref}>
                          <Candidate
                            candidate={candidate}
                            checked={selected === candidate.xref}
                            onSelect={() => setSelected(candidate.xref)}
                          />
                        </li>
                      ))}
                    </ul>
                  </fieldset>

                  <Field
                    label={t('invite.email')}
                    type="email"
                    autoComplete="off"
                    value={email}
                    hint={t('invite.emailHint')}
                    onChange={(event) => setEmail(event.target.value)}
                  />

                  <Button type="submit" disabled={invite.isPending}>
                    {invite.isPending ? t('invite.creating') : t('invite.create')}
                  </Button>

                  <p className="mt-3 text-base text-slate-700">
                    {t('invite.remaining', { count: data.remaining })}
                  </p>
                </form>
              )}
            </Section>
          )}

          {data.invitations.length > 0 && (
            <Section title={t('invite.outstandingTitle')}>
              <ul className="space-y-3">
                {data.invitations.map((invitation) => (
                  <li key={invitation.id}>
                    <Outstanding
                      invitation={invitation}
                      busy={withdraw.isPending}
                      onWithdraw={() => void withdraw.mutateAsync(invitation.id).catch(() => undefined)}
                    />
                  </li>
                ))}
              </ul>
            </Section>
          )}
        </>
      )}
    </>
  )
}

/**
 * Shown once, and said so.
 *
 * A read-only input rather than text: it can be tapped, selected and copied
 * on a phone, which is what this is for. It is not a link — nobody should
 * open their own invitation.
 */
function IssuedLink({ link, onDismiss }: { link: string; onDismiss: () => void }) {
  const { t } = useTranslation()

  return (
    <div role="status" className="mt-6 rounded-xl border border-emerald-500 bg-emerald-50 p-5">
      <p className="text-lg font-semibold text-slate-900">{t('invite.ready.title')}</p>
      <p className="mt-2 text-base text-slate-800">{t('invite.ready.body')}</p>

      <div className="mt-4">
        <ShareLink
          id="invitation-link"
          url={link}
          label={t('invite.ready.label')}
          shareTitle={t('invite.ready.shareTitle')}
          shareText={t('invite.ready.shareText')}
        />
      </div>

      <Button variant="secondary" className="mt-4" onClick={onDismiss}>
        {t('invite.ready.done')}
      </Button>
    </div>
  )
}

function Candidate({
  candidate,
  checked,
  onSelect,
}: {
  candidate: InvitationCandidate
  checked: boolean
  onSelect: () => void
}) {
  return (
    <label
      className={`flex min-h-[44px] cursor-pointer items-start gap-3 rounded-xl border p-4 ${
        checked ? 'border-sky-700 bg-sky-50' : 'border-slate-300 bg-white'
      }`}
    >
      <input
        type="radio"
        name="candidate"
        value={candidate.xref}
        checked={checked}
        onChange={onSelect}
        className="mt-1 h-5 w-5"
      />
      <span>
        {candidate.relationship !== null && (
          <span className="block text-sm font-medium uppercase tracking-wide text-slate-600">
            {candidate.relationship}
          </span>
        )}
        <span className="block text-base font-medium text-slate-900">{candidate.name}</span>
        {candidate.lifespan !== null && (
          <span className="block text-base text-slate-700">{candidate.lifespan}</span>
        )}
      </span>
    </label>
  )
}

function Outstanding({
  invitation,
  busy,
  onWithdraw,
}: {
  invitation: MemberInvitation
  busy: boolean
  onWithdraw: () => void
}) {
  const { t } = useTranslation()

  return (
    <Card>
      <p className="text-base font-medium text-slate-900">{invitation.name}</p>
      {invitation.email !== null && (
        <p className="mt-1 text-base text-slate-700">{invitation.email}</p>
      )}
      <p className="mt-1 text-base text-slate-700">
        {t('invite.expires', { date: formatDate(invitation.expires_at) })}
      </p>
      <Button variant="secondary" className="mt-3" disabled={busy} onClick={onWithdraw}>
        {t('invite.withdraw')}
      </Button>
    </Card>
  )
}

/**
 * The server sends an ISO timestamp; everything else in the portal is
 * formatted by webtrees. One date is not worth a round trip, and the
 * browser's own locale formatting is right for it.
 */
function formatDate(iso: string): string {
  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleDateString()
}

function messageFor(cause: unknown, t: (key: string) => string): string {
  if (!(cause instanceof ApiError)) {
    return t('error.unknown')
  }

  switch (cause.code) {
    case 'not_allowed':
      return t('invite.refused')
    case 'quota_reached':
      return t('invite.quota.body')
    case 'no_linked_record':
      return t('invite.noRecord.body')
    case 'not_configured':
      return t('error.not_configured')
    case 'network_error':
      return t('error.network')
    default:
      return t('error.unknown')
  }
}
