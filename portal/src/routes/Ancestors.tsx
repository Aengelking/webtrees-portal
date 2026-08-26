import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAncestors, useMember, useMemberAncestors } from '../api/queries'
import type { Ancestor, PrivateAncestor, VisibleAncestor } from '../api/types'
import { ErrorNotice, Loading, Notice, PageHeading } from '../components/ui'

const GENERATIONS = 4

/**
 * Four generations of ancestors, as indented rows rather than a drawn chart.
 *
 * A pedigree diagram is wide, and a phone is not. Every attempt to fit one on
 * a small screen ends in pinching and scrolling sideways, which is precisely
 * the webtrees experience this portal exists to replace. Indented rows scroll
 * the way everything else on a phone scrolls, stay readable at 16px, and give
 * every person a 44px target.
 *
 * The indent carries the structure: each generation is one step further in,
 * and each row says whether this is a father's or a mother's line. Ahnentafel
 * numbering does the arranging — the root is 1, a father is 2n, a mother is
 * 2n+1 — so the rows can be sorted into place without a nested payload.
 *
 * **Some rows are not people.** The server sends a placeholder wherever the
 * reader may not read the record, and the line carries on above it — that is
 * how a member gets to their great-great-grandparents through a living
 * grandmother. A placeholder is deliberately not a link: there is no xref
 * behind it and nothing to open. Where the person standing there is a member
 * who put themselves in the directory, the row says so with the name they
 * publish there and opens their member page, which is the thing they
 * consented to and the only thing shown.
 */
export function Ancestors() {
  const { xref } = useParams<{ xref: string }>()
  const query = useAncestors(xref, GENERATIONS)

  return <Pedigree query={query} />
}

/**
 * The same pedigree, reached from a member's page instead of a record's.
 *
 * The way in for somebody whose genealogy record is closed to this reader —
 * there is no XREF to ask with, so the member id asks instead. The root is
 * then a placeholder like any other rung, which is why the heading takes the
 * name from the member page rather than from the pedigree. That page is where
 * the reader just came from, so it is already in the cache and this costs no
 * request.
 */
export function MemberAncestors() {
  const { id } = useParams<{ id: string }>()
  const memberId = id === undefined || !/^\d+$/.test(id) ? undefined : Number(id)
  const query = useMemberAncestors(memberId, GENERATIONS)
  const member = useMember(memberId)

  return <Pedigree query={query} name={member.data?.display_name} />
}

function Pedigree({
  query,
  name,
}: {
  query: ReturnType<typeof useAncestors>
  name?: string | undefined
}) {
  const { t } = useTranslation()
  const { data, isPending, isError, error, refetch } = query

  const people = data?.people ?? []
  const root = people.find((person) => person.position === 1)
  const heading = name ?? (root !== undefined && !isPrivate(root) ? root.name : undefined)

  return (
    <>
      <PageHeading>{t('ancestors.title')}</PageHeading>

      {heading !== undefined && heading !== '' && (
        <p className="mt-2 text-base text-slate-700">{heading}</p>
      )}

      {isPending && <Loading />}

      {isError && (
        <div className="mt-6">
          <ErrorNotice error={error} onRetry={() => void refetch()} />
        </div>
      )}

      {data !== undefined && (
        <div className="mt-6">
          {people.length <= 1 ? (
            <Notice title={t('ancestors.none.title')} body={t('ancestors.none.body')} />
          ) : (
            <ol className="space-y-2">
              {sortByPosition(people).map((person) => (
                <li key={person.position} style={{ marginInlineStart: `${indent(person)}rem` }}>
                  {isPrivate(person) ? <PrivateRung person={person} /> : <PersonRung person={person} />}
                </li>
              ))}
            </ol>
          )}

          {/*
            Said plainly rather than left to be noticed. A pedigree with rows
            the reader cannot open invites them to wonder what is behind them,
            and the honest answer — that the archive does not show living
            people to everybody — is better said than guessed at.
          */}
          <p className="mt-6 text-base text-slate-700">{t('ancestors.privacyNote')}</p>
        </div>
      )}
    </>
  )
}

const CARD =
  'block min-h-[44px] rounded-xl border border-slate-300 bg-white p-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700'

function LineLabel({ person }: { person: Ancestor }) {
  const { t } = useTranslation()

  return (
    <p className="text-sm font-medium uppercase tracking-wide text-slate-600">
      {t(`ancestors.line.${lineOf(person)}`)}
    </p>
  )
}

function PersonRung({ person }: { person: VisibleAncestor }) {
  return (
    <Link to={`/individuals/${encodeURIComponent(person.xref)}`} className={`${CARD} hover:bg-slate-50`}>
      <LineLabel person={person} />
      <p className="mt-1 text-base font-medium text-sky-900 underline underline-offset-4">
        {person.name}
      </p>
      {person.lifespan !== null && <p className="mt-1 text-base text-slate-700">{person.lifespan}</p>}
    </Link>
  )
}

/**
 * A rung that is a position and not a person.
 *
 * Two shapes, and the difference between them is one person's own decision.
 * Without a directory listing there is nothing to show and nothing to open, so
 * the row is a plain block: no link, no hover, and the muted background of
 * something that is deliberately not a target.
 *
 * With one, it is a link to that member's page in the portal — because that
 * page is what they consented to publish, and it is already one tap away from
 * Mitglieder. The second line says what the reader is looking at, so that a
 * name here is not mistaken for the family tree opening up.
 */
function PrivateRung({ person }: { person: PrivateAncestor }) {
  const { t } = useTranslation()
  const member = person.member ?? null

  if (member === null) {
    return (
      <div className={`${CARD} border-dashed bg-slate-50`}>
        <LineLabel person={person} />
        <p className="mt-1 text-base text-slate-600">{t('ancestors.private.name')}</p>
      </div>
    )
  }

  return (
    <Link to={`/members/${member.id}`} className={`${CARD} hover:bg-slate-50`}>
      <LineLabel person={person} />
      <p className="mt-1 text-base font-medium text-sky-900 underline underline-offset-4">
        {member.display_name}
      </p>
      <p className="mt-1 text-base text-slate-700">{t('ancestors.private.member')}</p>
    </Link>
  )
}

/**
 * `private` is optional in the type: a server older than this field never
 * sends a placeholder, so its absence means "a person" and not "unknown".
 */
function isPrivate(person: Ancestor): person is PrivateAncestor {
  return person.private === true
}

/**
 * Ahnentafel order is already depth-first-ish, but not quite: sorting by
 * number puts a whole generation together, which is what the indent wants.
 */
function sortByPosition(people: Ancestor[]): Ancestor[] {
  return [...people].sort((a, b) => a.position - b.position)
}

/** One step per generation, capped so deep rows do not run off the screen. */
function indent(person: Ancestor): number {
  return Math.min(person.generation, 3) * 0.75
}

/** Whether this ancestor is on a father's or a mother's line, from the number. */
function lineOf(person: Ancestor): 'root' | 'paternal' | 'maternal' {
  if (person.position === 1) {
    return 'root'
  }

  return person.position % 2 === 0 ? 'paternal' : 'maternal'
}
