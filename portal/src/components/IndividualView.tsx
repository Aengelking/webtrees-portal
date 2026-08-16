import { useTranslation } from 'react-i18next'
import type { Individual, IndividualRef, Event } from '../api/types'
import { Card, Section } from './ui'

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

function RelativeList({ title, people }: { title: string; people: IndividualRef[] }) {
  if (people.length === 0) {
    return null
  }

  return (
    <Section title={title}>
      <ul className="space-y-2">
        {people.map((person) => (
          <li key={person.xref}>
            <Card>
              <p className="text-base font-medium text-slate-900">{person.name}</p>
              {person.lifespan !== null && (
                <p className="mt-1 text-base text-slate-700">{person.lifespan}</p>
              )}
            </Card>
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

  const headline = [
    t(`individual.sex.${individual.sex}`),
    individual.lifespan,
  ].filter((part): part is string => part !== null && part !== '')

  return (
    <article>
      <h2 className="text-xl font-semibold text-slate-900">{individual.name}</h2>
      {individual.name_alternative !== null && (
        <p className="mt-1 text-base text-slate-700">{individual.name_alternative}</p>
      )}
      <p className="mt-1 text-base text-slate-700">{headline.join(' · ')}</p>

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

      <RelativeList title={t('individual.parents')} people={individual.parents} />
      <RelativeList title={t('individual.siblings')} people={individual.siblings} />
      <RelativeList title={t('individual.spouses')} people={individual.spouses} />
      <RelativeList title={t('individual.children')} people={individual.children} />

      {/*
        An absolute URL on the webtrees host, built by webtrees from its own
        configured base_url — so a plain anchor, not a router link: this
        leaves the portal.
      */}
      <p className="mt-8">
        <a
          className="inline-flex min-h-[44px] items-center text-base font-semibold text-sky-800 underline underline-offset-4 hover:text-sky-900"
          href={individual.webtrees_url}
          rel="noopener noreferrer"
        >
          {t('profile.openInWebtrees')}
        </a>
      </p>
    </article>
  )
}
