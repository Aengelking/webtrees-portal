import { Link } from 'react-router-dom'
import { referenceLabel } from './reference'
import { PersonCard } from './PersonCard'
import { useTranslation } from 'react-i18next'
import type { Individual, IndividualRef, Event, Reference, Role } from '../api/types'
import { useAuth } from '../auth/AuthProvider'
import { Gallery, Portrait } from './Photos'
import { Card, Section } from './ui'

/** The roles webtrees lets change a record. `member` and `visitor` cannot. */
const EDITING_ROLES: Role[] = ['editor', 'moderator', 'manager', 'administrator']

function EventLine({ event }: { event: Event }) {
  const details = [event.value, event.date?.display, event.place].filter(
    (part): part is string => part !== null && part !== undefined && part !== '',
  )

  return (
    <li className="border-b border-slate-200 py-3 last:border-b-0">
      <p className="text-base font-medium text-slate-900">{event.label}</p>
      {details.length > 0 && <p className="mt-1 text-base text-slate-700">{details.join(' · ')}</p>}
    </li>
  )
}

/**
 * The archive's own numbering, under the name rather than in front of it.
 *
 * webtrees shows this as a badge before the name, via the Vesta module. Here
 * it is a line of its own: a reference number is a useful thing to be able to
 * quote back to the family archive, and it is not part of anybody's name.
 */
function References({ references }: { references: Reference[] }) {
  const { t } = useTranslation()
  const label = referenceLabel(references)

  if (label === null) {
    return null
  }

  return (
    <p className="mt-1 text-base text-slate-600">
      <span className="sr-only">{t('individual.reference')}: </span>
      {label}
    </p>
  )
}

/**
 * Relatives are links, and that is the whole of the navigation.
 *
 * Everything in these lists has already been through the API's privacy
 * filtering — a relative the member may not see is not in the array at all —
 * so following one can never reach further than the list itself already
 * showed. The link goes to the same screen, which is what makes the tree
 * walkable: tap a parent, then their parent, and so on.
 *
 * The card is `PersonCard`, which is the same card the search results and the
 * indexes use. One design, one place to change it.
 */
function RelativeList({ title, people }: { title: string; people: IndividualRef[] }) {
  if (people.length === 0) {
    return null
  }

  return (
    <Section title={title}>
      <ul className="space-y-2">
        {people.map((person) => (
          <li key={person.xref}>
            <PersonCard person={person} />
          </li>
        ))}
      </ul>
    </Section>
  )
}

/**
 * One person, read-only.
 *
 * Everything here has already been filtered by the API at the signed-in
 * member's access level; relatives the member may not see are simply not in
 * the arrays. The portal does no filtering of its own, precisely so there is
 * only one place where that decision is made.
 */
export function IndividualView({ individual }: { individual: Individual }) {
  const { t } = useTranslation()
  const { me } = useAuth()

  // A signpost, not a permission boundary. `webtrees_url` goes to every
  // member — it is the same public record address the bottom link has always
  // used — and webtrees decides for itself what the person following it may
  // do when they arrive. What this check buys is that a member is not sent to
  // an editing screen they have no business on, and that an editor does not
  // have to hunt for the way back to the tree they maintain.
  const mayEdit = me !== null && EDITING_ROLES.includes(me.user.role)

  const headline = [
    t(`individual.sex.${individual.sex}`),
    individual.lifespan,
  ].filter((part): part is string => part !== null && part !== '')

  return (
    <article>
      <div className="flex items-start gap-4">
        <Portrait person={individual} size={72} />
        <div className="min-w-0">
          <h2 className="text-xl font-semibold text-slate-900">{individual.name}</h2>
          {individual.name_alternative !== null && (
            <p className="mt-1 text-base text-slate-700">{individual.name_alternative}</p>
          )}
          <References references={individual.references ?? []} />
          {individual.relationship !== null && individual.relationship !== undefined && (
            <p className="mt-1 text-base font-medium text-sky-900">
              {t('individual.relationship', { relationship: individual.relationship })}
            </p>
          )}
          <p className="mt-1 text-base text-slate-700">{headline.join(' · ')}</p>
        </div>
      </div>

      {mayEdit && (
        <p className="mt-5">
          <a
            className="inline-flex min-h-[44px] w-full items-center justify-center rounded-lg border border-slate-400 bg-white px-5 py-3 text-base font-semibold text-slate-900 hover:bg-slate-50"
            href={individual.webtrees_url}
            rel="noopener noreferrer"
          >
            {t('individual.editInWebtrees')}
          </a>
          {/*
            Said once, quietly: an editor who sees a link nobody else mentions
            should know it is their role showing, not a bug and not something
            the family can all see.
          */}
          <span className="mt-2 block text-base text-slate-600">
            {t('individual.editInWebtreesHint')}
          </span>
        </p>
      )}

      <Gallery photos={individual.photos ?? []} />

      <Section title={t('individual.events')}>
        {individual.events.length === 0 ? (
          <p className="text-base text-slate-700">{t('individual.noEvents')}</p>
        ) : (
          <Card>
            <ul>
              {individual.events.map((event, index) => (
                <EventLine key={`${event.tag}-${index}`} event={event} />
              ))}
            </ul>
          </Card>
        )}
      </Section>

      {/*
        The two ways of going further from a person: up their own line, or out
        into the rest of the archive. Side by side because they are the same
        kind of offer, and a member who came here from a search wants the
        second one to still be within reach.

        This is the *only* way into the archive, and it is deliberately on the
        record rather than on the navigation bar or on Mein Profil as well.
        There is no fifth place on the bar — see `Layout` for why four is the
        limit — and Mein Profil renders this same component, so putting one
        there too would be two identical buttons on one screen, which is the
        thing that makes people wonder which is the right one.
      */}
      <div className="mt-6 flex flex-col gap-3 sm:flex-row">
        <Link
          to={`/individuals/${encodeURIComponent(individual.xref)}/ancestors`}
          className="inline-flex min-h-[44px] flex-1 items-center justify-center rounded-lg border border-sky-800 px-5 py-3 text-base font-semibold text-sky-900"
        >
          {t('individual.showAncestors')}
        </Link>
        <Link
          to="/tree"
          className="inline-flex min-h-[44px] flex-1 items-center justify-center rounded-lg border border-sky-800 px-5 py-3 text-base font-semibold text-sky-900"
        >
          {t('tree.open')}
        </Link>
      </div>

      <RelativeList title={t('individual.parents')} people={individual.parents} />
      <RelativeList title={t('individual.siblings')} people={individual.siblings} />
      <RelativeList title={t('individual.spouses')} people={individual.spouses} />
      <RelativeList title={t('individual.children')} people={individual.children} />

      {/*
        An absolute URL on the webtrees host, built by webtrees from its own
        configured base_url — so a plain anchor, not a router link: this
        leaves the portal.

        Not shown to an editor, who already has the link above. It is the same
        address; two links to one page, differing only in wording, is the kind
        of thing that makes people wonder which is the right one.
      */}
      {!mayEdit && (
        <p className="mt-8">
          <a
            className="inline-flex min-h-[44px] items-center text-base font-semibold text-sky-800 underline underline-offset-4 hover:text-sky-900"
            href={individual.webtrees_url}
            rel="noopener noreferrer"
          >
            {t('profile.openInWebtrees')}
          </a>
        </p>
      )}
    </article>
  )
}
