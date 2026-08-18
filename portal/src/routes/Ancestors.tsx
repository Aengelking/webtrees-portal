import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAncestors } from '../api/queries'
import type { Ancestor } from '../api/types'
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
 */
export function Ancestors() {
  const { t } = useTranslation()
  const { xref } = useParams<{ xref: string }>()
  const { data, isPending, isError, error, refetch } = useAncestors(xref, GENERATIONS)

  const people = data?.people ?? []
  const root = people.find((person) => person.position === 1)

  return (
    <>
      <PageHeading>{t('ancestors.title')}</PageHeading>

      {root !== undefined && <p className="mt-2 text-base text-slate-700">{root.name}</p>}

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
                  <Link
                    to={`/individuals/${encodeURIComponent(person.xref)}`}
                    className="block min-h-[44px] rounded-xl border border-slate-300 bg-white p-4 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
                  >
                    <p className="text-sm font-medium uppercase tracking-wide text-slate-600">
                      {t(`ancestors.line.${lineOf(person)}`)}
                    </p>
                    <p className="mt-1 text-base font-medium text-sky-900 underline underline-offset-4">
                      {person.name}
                    </p>
                    {person.lifespan !== null && (
                      <p className="mt-1 text-base text-slate-700">{person.lifespan}</p>
                    )}
                  </Link>
                </li>
              ))}
            </ol>
          )}

          {/*
            Said plainly rather than left to be noticed. A pedigree with people
            missing from it invites the reader to wonder what is missing and
            why, and the honest answer — that they may simply not be allowed to
            see everyone — is better said than guessed at.
          */}
          <p className="mt-6 text-base text-slate-700">{t('ancestors.privacyNote')}</p>
        </div>
      )}
    </>
  )
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
