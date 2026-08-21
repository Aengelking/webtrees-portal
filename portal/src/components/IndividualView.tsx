import { Link } from 'react-router-dom'
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

  if (references.length === 0) {
    return null
  }

  return (
    <p className="mt-1 text-base text-slate-600">
      <span className="sr-only">{t('individual.reference')}: </span>
      {references
        .map((reference) =>
          reference.type === null ? reference.number : `${reference.type} ${reference.number}`,
        )
        .join(' · ')}
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
            <Link
              to={`/individuals/${encodeURIComponent(person.xref)}`}
              className="flex items-center gap-3 rounded-xl border border-slate-300 bg-white p-4 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
            >
              <Portrait person={person} />
              <span className="min-w-0">
                <span className="block text-base font-medium text-sky-900 underline underline-offset-4">
                  {person.name}
                </span>
                {person.lifespan !== null && (
                  <span className="mt-1 block text-base text-slate-700">{person.lifespan}</span>
                )}
              </span>
            </Link>
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

  // A signpost, not a permission boundary. `webtrees_url` is in the payload
  // every member receives — it is the public record address, built by
  // webtrees from its own base_url — and webtrees decides for itself what the
  // person following it may do on arrival. Nothing is kept from a member by
  // not drawing this link; the portal simply stops offering a door that leads
  // somewhere they were not going.
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

      {/*
        The only way out of the portal to webtrees, and only for the people
        who have something to do when they arrive.

        It is an absolute URL on the webtrees host, built by webtrees from its
        own configured base_url — so a plain anchor rather than a router link:
        this leaves the portal.
      */}
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

      <p className="mt-6">
        <Link
          to={`/individuals/${encodeURIComponent(individual.xref)}/ancestors`}
          className="inline-flex min-h-[44px] w-full items-center justify-center rounded-lg border border-sky-800 px-5 py-3 text-base font-semibold text-sky-900"
        >
          {t('individual.showAncestors')}
        </Link>
      </p>

      <RelativeList title={t('individual.parents')} people={individual.parents} />
      <RelativeList title={t('individual.siblings')} people={individual.siblings} />
      <RelativeList title={t('individual.spouses')} people={individual.spouses} />
      <RelativeList title={t('individual.children')} people={individual.children} />

    </article>
  )
}
