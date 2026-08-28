import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAncestors, useMember, useMemberAncestors } from '../api/queries'
import type { Ancestor, PrivateAncestor, VisibleAncestor } from '../api/types'
import { referenceLabel } from '../components/reference'
import { ErrorNotice, Loading, Notice, PageHeading } from '../components/ui'

/**
 * As deep as the archive goes.
 *
 * The server clamps this to its own maximum, so the number here only has to be
 * large enough not to be the thing that stops the walk. It used to be four,
 * which stopped in the nineteenth century — and this archive is measured from
 * one man about a dozen generations up, which is the part the family keeps it
 * for.
 */
const GENERATIONS = 20

/**
 * The pedigree, grouped by generation.
 *
 * A drawn chart is wide and a phone is not, so this is a list — but the first
 * version of that list was indented one step per generation and labelled each
 * row "väterliche" or "mütterliche Linie", and a member said plainly that it
 * was not understandable. They were right, and for two reasons that only show
 * up once the tree is deep:
 *
 * * the indent stopped meaning anything. It capped at three steps so that deep
 *   rows would not run off the screen, so the fourth generation and the
 *   twelfth sat at the same margin;
 * * "väterliche Linie" says which of two halves a person is in and nothing
 *   about *where*. In the fourth generation that is one of eight positions,
 *   and by the tenth it is one of five hundred.
 *
 * So the structure is carried by two things that stay true at any depth. A
 * **heading per generation** — Eltern, Großeltern, Urgroßeltern, then counted —
 * which is how a family says it out loud; and on each card **the path that
 * reaches that person**, "Vaters Vaters Mutter" while that is still readable
 * and "Vater › Vater › Mutter › Vater" once it is not. Both come from the
 * Ahnentafel number, which is why the payload needs no nesting.
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

  // The root is the person being looked at, not one of their ancestors, and
  // the page already says who that is.
  const generations = byGeneration(people.filter((person) => person.generation > 0))

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
          {generations.length === 0 ? (
            <Notice title={t('ancestors.none.title')} body={t('ancestors.none.body')} />
          ) : (
            generations.map(([generation, rungs]) => (
              <section key={generation} className="mt-8 first:mt-0">
                <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-600">
                  {generationName(t, generation)}
                </h2>

                <ol className="mt-3 space-y-2">
                  {rungs.map((person) => (
                    <li key={person.position}>
                      {isPrivate(person) ? (
                        <PrivateRung person={person} />
                      ) : (
                        <PersonRung person={person} />
                      )}
                    </li>
                  ))}
                </ol>
              </section>
            ))
          )}

          {/*
            Only where it is true, and it almost never is: "the archive ends
            here" and "we stopped reading here" are different sentences, and a
            line that simply stops would otherwise be read as the first.
          */}
          {data.truncated === true && (
            <p className="mt-8 text-base text-slate-700">{t('ancestors.truncated')}</p>
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

/** Where this person stands, in the words a family uses. */
function PathLabel({ person }: { person: Ancestor }) {
  const { t } = useTranslation()

  return <p className="text-sm font-medium text-slate-600">{pathName(t, person)}</p>
}

function PersonRung({ person }: { person: VisibleAncestor }) {
  const details = [person.lifespan, referenceLabel(person.references)].filter(
    (part): part is string => part !== null && part !== '',
  )

  return (
    <Link
      to={`/individuals/${encodeURIComponent(person.xref)}`}
      className={`${CARD} hover:bg-slate-50`}
    >
      <p className="text-base font-medium text-sky-900 underline underline-offset-4">
        {person.name}
      </p>
      {details.length > 0 && (
        <p className="mt-1 text-base text-slate-700">{details.join(' · ')}</p>
      )}
      <div className="mt-1">
        <PathLabel person={person} />
      </div>
    </Link>
  )
}

/**
 * A rung that is a position and not a person.
 *
 * Two shapes, and the difference between them is one person's own decision.
 * Without a directory listing there is nothing to show and nothing to open, so
 * the row is a plain block: no link, no hover, and the muted, dashed border of
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
        <p className="text-base text-slate-600">{t('ancestors.private.name')}</p>
        <div className="mt-1">
          <PathLabel person={person} />
        </div>
      </div>
    )
  }

  return (
    <Link to={`/members/${member.id}`} className={`${CARD} hover:bg-slate-50`}>
      <p className="text-base font-medium text-sky-900 underline underline-offset-4">
        {member.display_name}
      </p>
      <p className="mt-1 text-base text-slate-700">{t('ancestors.private.member')}</p>
      <div className="mt-1">
        <PathLabel person={person} />
      </div>
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
 * The rungs in reading order, gathered under the generation they belong to.
 *
 * Ahnentafel numbering does the arranging for free: sorting by number puts a
 * whole generation together and, within it, from the father's side to the
 * mother's.
 */
function byGeneration(people: Ancestor[]): [number, Ancestor[]][] {
  const groups = new Map<number, Ancestor[]>()

  for (const person of [...people].sort((a, b) => a.position - b.position)) {
    const group = groups.get(person.generation)

    if (group === undefined) {
      groups.set(person.generation, [person])
    } else {
      group.push(person)
    }
  }

  return [...groups.entries()].sort(([a], [b]) => a - b)
}

/** i18next's own `t`, narrowed to the one call shape this file makes. */
type Translate = ReturnType<typeof useTranslation>['t']

/**
 * Eltern, Großeltern, Urgroßeltern — and then a number.
 *
 * The family words run out after three, and "Ur-ur-ur-urgroßeltern" is a thing
 * a reader has to count on their fingers. Past that the generation is named by
 * its number, which is what somebody reading a deep pedigree actually wants to
 * know.
 */
function generationName(t: Translate, generation: number): string {
  if (generation <= 4) {
    return t(`ancestors.generation.${generation}`)
  }

  return t('ancestors.generation.nth', { n: generation })
}

/**
 * The path from the person at the root to this rung, read out of the number.
 *
 * An Ahnentafel number *is* the path in binary: strip the leading 1 and every
 * remaining bit is a step, 0 to a father and 1 to a mother. Position 14 is
 * 1110, so mother, mother, father — and that is exactly how somebody would say
 * where Otto stands.
 *
 * Two shapes, because a possessive chain is the natural way to say it and
 * stops being readable at about four: "Vaters Vaters Mutter" up to the third
 * generation, then the same path with arrows.
 *
 * **The two languages do not compose alike**, which is why the chain word and
 * the arrow word are separate entries rather than one reused. German
 * capitalises its nouns wherever they stand, so "Vaters Vaters Mutter" and
 * "Vater › Mutter" are both right as written. English does not: the chain is
 * "father's father's mother" in lower case with only the first letter raised,
 * while the arrow path reads as a list of steps and takes a capital on each.
 * Composing English out of the German shape produced "Father's Father's
 * mother", which is nobody's English.
 */
function pathName(t: Translate, person: Ancestor): string {
  const steps = pathSteps(person.position, person.generation)

  if (steps.length === 0) {
    return ''
  }

  const word = (step: 'f' | 'm'): string => (step === 'f' ? 'father' : 'mother')
  const last = word(steps[steps.length - 1] as 'f' | 'm')

  if (steps.length === 1) {
    return t(`ancestors.path.your.${last}`)
  }

  if (steps.length <= 3) {
    const owners = steps.slice(0, -1).map((step) => t(`ancestors.path.possessive.${word(step)}`))

    return capitalise([...owners, t(`ancestors.path.final.${last}`)].join(' '))
  }

  return steps.map((step) => t(`ancestors.path.step.${word(step)}`)).join(' › ')
}

/**
 * Only the first letter, and only ever of a phrase this file composed.
 *
 * German needs none of it — its nouns are capitalised where they stand — so
 * this is a no-op there, and it is the reason English can keep its words lower
 * case in the catalogue, which is where they have to be for the middle of a
 * chain.
 */
function capitalise(phrase: string): string {
  return phrase.charAt(0).toLocaleUpperCase() + phrase.slice(1)
}

/** @returns one entry per step up, oldest step last. */
function pathSteps(position: number, generation: number): ('f' | 'm')[] {
  const steps: ('f' | 'm')[] = []

  for (let bit = generation - 1; bit >= 0; bit--) {
    steps.push(((position >> bit) & 1) === 0 ? 'f' : 'm')
  }

  return steps
}
