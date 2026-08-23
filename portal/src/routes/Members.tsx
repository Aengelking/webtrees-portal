import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAcceptConnection, useConnect, useMembers } from '../api/queries'
import type { MemberSummary } from '../api/types'
import { Portrait } from '../components/Photos'
import { referenceLabel } from '../components/reference'
import { Button, Card, ErrorNotice, Field, Loading, Notice, PageHeading } from '../components/ui'

const PER_PAGE = 25

export function Members() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()

  const query = params.get('q') ?? ''
  const page = Math.max(1, Number(params.get('page') ?? '1') || 1)

  // The text field stays responsive while the request that matches it is
  // still in flight.
  const [draft, setDraft] = useState(query)

  useEffect(() => {
    setDraft(query)
  }, [query])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      if (draft !== query) {
        setParams(draft === '' ? {} : { q: draft }, { replace: true })
      }
    }, 250)

    return () => {
      window.clearTimeout(timer)
    }
  }, [draft, query, setParams])

  const { data, isPending, isError, error, refetch } = useMembers(query, page)

  const pages = data === undefined ? 1 : Math.max(1, Math.ceil(data.total / PER_PAGE))

  function goToPage(next: number) {
    const updated: Record<string, string> = {}

    if (query !== '') {
      updated['q'] = query
    }

    if (next > 1) {
      updated['page'] = String(next)
    }

    setParams(updated)
    window.scrollTo({ top: 0 })
  }

  return (
    <>
      {/*
        The directory used to be a tab of its own and is now reached from
        Kontakte, so it needs the way back that every sub-screen has. Same
        shape as the one on a member's own page.
      */}
      <p className="mb-4">
        <Link
          to="/contacts"
          className="inline-flex min-h-[44px] items-center text-base font-semibold text-sky-800 underline underline-offset-4"
        >
          {t('members.back')}
        </Link>
      </p>

      <PageHeading>{t('members.title')}</PageHeading>

      <div className="mt-6">
        <Field
          label={t('members.search')}
          hint={t('members.searchHint')}
          type="search"
          inputMode="search"
          autoComplete="off"
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
        />
      </div>

      {isPending && <Loading />}

      {isError && <ErrorNotice error={error} onRetry={() => void refetch()} />}

      {data !== undefined && data.items.length === 0 && (
        <Notice
          title={query === '' ? t('members.empty.title') : t('members.noResults.title')}
          body={
            query === ''
              ? t('members.empty.body')
              : t('members.noResults.body', { query })
          }
          action={
            query === '' ? undefined : (
              <Button variant="secondary" onClick={() => setParams({})}>
                {t('members.noResults.action')}
              </Button>
            )
          }
        />
      )}

      {data !== undefined && data.items.length > 0 && (
        <>
          <p aria-live="polite" className="mb-3 text-base text-slate-700">
            {t('members.count', { count: data.total })}
          </p>

          <ul className="space-y-3">
            {data.items.map((member) => (
              <li key={member.id}>
                <MemberRow member={member} offerConnection={data.connections_enabled !== false} />
              </li>
            ))}
          </ul>

          {pages > 1 && (
            <nav className="mt-6 flex items-center justify-between gap-3">
              <Button variant="secondary" disabled={page <= 1} onClick={() => goToPage(page - 1)}>
                {t('members.previous')}
              </Button>
              <span className="text-base text-slate-700">{t('members.page', { page, pages })}</span>
              <Button
                variant="secondary"
                disabled={page >= pages}
                onClick={() => goToPage(page + 1)}
              >
                {t('members.next')}
              </Button>
            </nav>
          )}
        </>
      )}
    </>
  )
}

/**
 * One line of the directory: who they are, and the one thing to do about it.
 *
 * The name is the link and the button sits beside it, rather than the whole
 * card being a link with a button inside it — nesting a control in a link
 * makes a row where the same tap does two different things depending on the
 * pixel it landed on.
 */
function MemberRow({ member, offerConnection }: { member: MemberSummary; offerConnection: boolean }) {
  const { t } = useTranslation()
  const connect = useConnect()
  const accept = useAcceptConnection()

  const error = connect.error ?? accept.error

  return (
    <Card>
      <div className="flex items-start justify-between gap-3">
        <Link
          to={`/members/${member.id}`}
          className="flex min-w-0 flex-1 items-center gap-3 rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
        >
          {/*
            The face, on the same rule as everywhere else: a living person's
            photograph is shown only where they uploaded it, so a row without
            one gets an initial rather than a stranger.
          */}
          {/*
            A face even where the record is closed, if the member uploaded one
            here themselves — that permission is their own. See `Recognition`.
          */}
          <Portrait
            person={{
              name: member.individual?.name ?? member.display_name,
              portrait: member.individual?.portrait ?? member.portrait ?? null,
            }}
          />

          <span className="min-w-0">
            <span className="block text-lg font-semibold text-sky-800 underline underline-offset-4">
              {member.display_name}
            </span>
            <span className="mt-1 block text-base text-slate-700">
              {member.individual === null
                ? // Not "this account has no record": the commoner reason by
                  // far is a living person this reader may not see, and the
                  // row cannot tell the two apart. See `individual.notVisible`.
                  // The archive number stands in for the record where the
                  // family publishes it.
                  referenceLabel(member.references) ?? t('individual.notVisible')
                : [
                    member.individual.name,
                    member.individual.lifespan,
                    // The archive's number, on the row rather than one tap
                    // further in: it is how this family tells two people of
                    // the same name apart.
                    referenceLabel(member.individual.references),
                  ]
                    .filter((part): part is string => part !== null && part !== '')
                    .join(' · ')}
            </span>
            {/*
              And how the reader stands to them, which is the difference
              between a directory of accounts and a directory of relatives.
            */}
            {member.individual?.relationship !== null &&
              member.individual?.relationship !== undefined &&
              member.individual.relationship !== '' && (
                <span className="mt-1 block text-base font-medium text-sky-900">
                  {t('individual.relationship', { relationship: member.individual.relationship })}
                </span>
              )}
          </span>
        </Link>

        {offerConnection && (
          <RowAction
            member={member}
            busy={connect.isPending || accept.isPending}
            onConnect={() => void connect.mutateAsync({ member_id: member.id }).catch(() => undefined)}
            onAccept={(id) => void accept.mutateAsync(id).catch(() => undefined)}
          />
        )}
      </div>

      {/* Under the row rather than in the column beside it: a refusal is a
          sentence, and a sentence in a 10rem column is a word a line. */}
      {error !== null && error !== undefined && (
        <div className="mt-3">
          <ErrorNotice error={error} />
        </div>
      )}
    </Card>
  )
}

/**
 * Ask, or answer, without opening the person first.
 *
 * The directory is where a member is already looking for somebody, so this is
 * where "verbinden" belongs — the detour through the other person's page
 * bought nothing, because everything needed to decide is on the row already.
 *
 * Only two of the five states are a button. *Angefragt* and *Verbunden* are
 * facts rather than offers, and a row with a control on it that does nothing
 * is worse than a row with a word on it.
 *
 * The button says "Verbinden" and is *named* "Verbinden mit Dieter Beispiel",
 * because twenty-five buttons all called "Verbinden" are a list nobody can
 * navigate by name — and the visible words are the start of the name, so
 * anybody speaking to their telephone still gets the button they asked for.
 */
function RowAction({
  member,
  busy,
  onConnect,
  onAccept,
}: {
  member: MemberSummary
  busy: boolean
  onConnect: () => void
  onAccept: (id: number) => void
}) {
  const { t } = useTranslation()

  const state = member.connection

  if (state === undefined || state.status === 'self') {
    return null
  }

  if (state.status === 'requested' || state.status === 'connected') {
    return (
      <span className="shrink-0 whitespace-nowrap rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700">
        {t(`members.state.${state.status}`)}
      </span>
    )
  }

  if (state.status === 'incoming') {
    return (
      <Button
        className="shrink-0 px-4"
        disabled={busy}
        aria-label={t('members.acceptFrom', { name: member.display_name })}
        onClick={() => onAccept(state.id as number)}
      >
        {t('contacts.accept')}
      </Button>
    )
  }

  return (
    <Button
      variant="secondary"
      className="shrink-0 px-4"
      disabled={busy}
      aria-label={t('members.connectWith', { name: member.display_name })}
      onClick={onConnect}
    >
      {t('contacts.askThis')}
    </Button>
  )
}
