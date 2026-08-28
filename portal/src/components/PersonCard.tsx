import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import type { IndividualRef } from '../api/types'
import { referenceLabel } from './reference'
import { Portrait } from './Photos'

/**
 * One person, as a tappable card.
 *
 * There is one design for these in the portal and it is used in three places —
 * the relatives on a record, a page of search results, an index a member
 * tapped into — so it lives here rather than being written out three times
 * and drifting.
 *
 * **The whole card is the link.** §2.45 made that argument for the contact
 * cards: underlining the name inside a tappable row says the opposite of what
 * is true, that the word is the target and the rest of the row is decoration.
 *
 * Three lines, and each of them earns its place:
 *
 * - the name, which is what was searched for;
 * - the years and the archive number, because this family has more than one
 *   Dieter Beispiel and a card that does not say which is a card that has to
 *   be opened;
 * - **how the reader is related**, which is the line that turns a list of
 *   strangers' names into a list of relatives. A page of search results
 *   without it is a phone book.
 *
 * A fourth line appears only for the few people it is true of: the office
 * they hold in the foundation. It is the one thing on the card that says why
 * this person, of all the names here, is the one to write to — so it is set
 * apart rather than folded in with the years and the number.
 *
 * The relationship is absent rather than blank when there is none to name —
 * see openapi.yaml for the four reasons, one of which is a disclosure the
 * portal will not make. "Nicht verwandt" is not among them and is not said:
 * silence here means "no answer within four steps", which is a different
 * thing from "no relation".
 */
/**
 * An office, set apart from the archive's own lines about a person.
 *
 * Shared by the two cards so the office looks the same in the directory and
 * in the tree; a member should not have to work out that the amber line here
 * and the amber line there are the same kind of thing.
 */
export function Office({ title }: { title: string }) {
  const { t } = useTranslation()

  return (
    <span className="mt-1 inline-block rounded-md bg-amber-100 px-2 py-0.5 text-sm font-medium text-amber-900">
      {/*
        Colour and a box say "this is a different kind of thing" to a reader
        who can see them and nothing at all to a reader who cannot. The words
        that carry the distinction go in the text itself.
      */}
      <span className="sr-only">{t('common.office')}: </span>
      {title}
    </span>
  )
}

export function PersonCard({ person }: { person: IndividualRef }) {
  const { t } = useTranslation()

  const details = [person.lifespan, referenceLabel(person.references)].filter(
    (part): part is string => part !== null && part !== '',
  )

  const relationship = person.relationship ?? null
  const office = person.office ?? null

  return (
    <Link
      to={`/individuals/${encodeURIComponent(person.xref)}`}
      className="flex items-center gap-3 rounded-xl border border-slate-300 bg-white p-4 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
    >
      <Portrait person={person} />
      <span className="min-w-0">
        <span className="block text-base font-medium text-slate-900">{person.name}</span>
        {office !== null && office !== '' && <Office title={office} />}
        {details.length > 0 && (
          <span className="mt-1 block text-base text-slate-700">{details.join(' · ')}</span>
        )}
        {relationship !== null && relationship !== '' && (
          <span className="mt-1 block text-base font-medium text-sky-900">
            {t('individual.relationship', { relationship })}
          </span>
        )}
      </span>
    </Link>
  )
}
