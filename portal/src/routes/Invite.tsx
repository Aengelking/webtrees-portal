import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useIndividual, useInvitations, useInvite, useSearch, useWithdrawInvitation } from '../api/queries'
import { ApiError } from '../api/client'
import type { IndividualRef, InvitationCandidate, MemberInvitation } from '../api/types'
import { referenceLabel } from '../components/reference'
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
              ) : data.candidates.length === 0 && data.scope !== 'anyone' ? (
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

                  <div className="mb-5">
                    {data.scope === 'anyone' ? (
                      <FindAnybody selected={selected} onSelect={setSelected} />
                    ) : (
                      <>
                        <label
                          htmlFor="invite-candidate"
                          className="mb-2 block text-base font-medium text-slate-900"
                        >
                          {t('invite.whoLegend')}
                        </label>
                        <select
                          id="invite-candidate"
                          value={selected}
                          onChange={(event) => setSelected(event.target.value)}
                          className="min-h-[48px] w-full rounded-lg border border-slate-400 bg-white px-4 py-3 text-base text-slate-900"
                        >
                          <option value="">{t('invite.whoPlaceholder')}</option>

                          {data.candidates.map((candidate) => (
                            <option key={candidate.xref} value={candidate.xref}>
                              {nameFor(candidate)}
                            </option>
                          ))}
                        </select>
                      </>
                    )}
                  </div>

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

                  {/*
                    A member's quota, and only theirs. An editor has none — see
                    `keepsTheTree()` — and telling them they may have two
                    hundred invitations outstanding is a number invented to
                    fill this line.
                  */}
                  {data.scope !== 'anyone' && (
                    <p className="mt-3 text-base text-slate-700">
                      {t('invite.remaining', { count: data.remaining })}
                    </p>
                  )}
                </form>
              )}

              {/*
                Under the button that made it, not at the top of the screen.
                It used to be above everything, which was fine while the list
                of relatives was one line long and wrong the moment it was not:
                the member pressed a button at the bottom and the one thing
                they came for appeared off-screen behind them.
              */}
              {link !== null && (
                <div className="mt-6">
                  <IssuedLink link={link} onDismiss={() => setLink(null)} />
                </div>
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

/**
 * What one line of the dropdown says.
 *
 * The relationship first, because "Ihr Bruder" is what makes it obvious that
 * the right person is about to be picked — a family tree has more than one
 * Dieter Beispiel, and the years are what tell them apart.
 */
/**
 * Whom to invite, when the answer is "anybody in the archive".
 *
 * A member picks from a wheel of their close family — a handful of names, and
 * the shape that screen has always had. An editor may invite anybody they can
 * see, which in this archive is thousands of people, and a wheel with
 * thousands of names in it is not a way of choosing one.
 *
 * So they get the same search the Stammbaum screen has, over the same
 * endpoint. It already shows an editor the living (see `SearchConsent`), which
 * is exactly who an invitation is for, and it already matches names, archive
 * numbers and nicknames — so an editor holding a number can type it.
 *
 * **The list makes no promises about who may be invited.** It is the archive's
 * search, not a list of candidates: a person who already has an account is in
 * it like anybody else. The server answers that when the invitation is issued,
 * and it says the same "you cannot invite this person" for every reason there
 * could be. Filtering here instead would need the whole eligible set on the
 * screen, which is the thing this exists to avoid.
 */
function FindAnybody({
  selected,
  onSelect,
}: {
  selected: string
  onSelect: (xref: string) => void
}) {
  const { t } = useTranslation()
  const [typed, setTyped] = useState('')
  const [asked, setAsked] = useState('')

  useEffect(() => {
    const timer = window.setTimeout(() => setAsked(typed.trim()), 300)

    return () => {
      window.clearTimeout(timer)
    }
  }, [typed])

  const { data, isFetching } = useSearch({ q: asked, page: 1 })

  const found = data?.items ?? []
  const fromResults = found.find((person) => person.xref === selected) ?? null

  // Somebody can already be chosen before a single letter is typed: arriving
  // from a person's own page is the ordinary way onto this screen, and it
  // carries them in `?xref=`. Without this the screen showed an empty search
  // box, so the person the editor had just been looking at had to be found
  // again by name — and the invitation they pressed the button for looked
  // like it had lost them.
  const { data: preselected } = useIndividual(
    fromResults === null && selected !== '' ? selected : undefined,
  )

  const chosen = fromResults ?? preselected ?? null

  return (
    <>
      <Field
        label={t('invite.findLegend')}
        hint={t('invite.findHint')}
        type="search"
        inputMode="search"
        autoComplete="off"
        value={typed}
        onChange={(event) => setTyped(event.target.value)}
      />

      {chosen !== null && (
        <p className="mt-3 rounded-lg border border-sky-800 bg-sky-50 p-3 text-base text-slate-900">
          {t('invite.chosen', { name: nameOf(chosen) })}
        </p>
      )}

      {asked !== '' && found.length === 0 && !isFetching && (
        <p className="mt-3 text-base text-slate-700">{t('invite.findNone', { query: asked })}</p>
      )}

      {found.length > 0 && (
        <ul className="mt-3 max-h-80 space-y-2 overflow-y-auto" aria-label={t('invite.findResults')}>
          {found.map((person) => (
            <li key={person.xref}>
              <button
                type="button"
                aria-pressed={person.xref === selected}
                onClick={() => onSelect(person.xref)}
                className={[
                  'block min-h-[44px] w-full rounded-xl border p-3 text-left text-base',
                  'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700',
                  person.xref === selected
                    ? 'border-sky-800 bg-sky-50 font-semibold text-slate-900'
                    : 'border-slate-300 bg-white text-slate-900 hover:bg-slate-50',
                ].join(' ')}
              >
                {nameOf(person)}
              </button>
            </li>
          ))}
        </ul>
      )}
    </>
  )
}

/** A found person on one line: who they are, when, and their number. */
function nameOf(person: IndividualRef): string {
  return [person.name, person.lifespan, referenceLabel(person.references)]
    .filter((part): part is string => part !== null && part !== undefined && part !== '')
    .join(' · ')
}

function nameFor(candidate: InvitationCandidate): string {
  const named =
    candidate.relationship === null ? candidate.name : `${candidate.relationship} — ${candidate.name}`

  return candidate.lifespan === null ? named : `${named} (${candidate.lifespan})`
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
