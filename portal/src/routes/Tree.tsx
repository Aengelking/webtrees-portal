import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useRelationship, useSearch, useTreeIndex } from '../api/queries'
import type { IndexEntry, SearchPage } from '../api/types'
import { PersonCard } from '../components/PersonCard'
import { useAuth } from '../auth/AuthProvider'
import { ownReference } from '../components/reference'
import { Button, Card, ErrorNotice, Field, Loading, Notice, PageHeading } from '../components/ui'

const PER_PAGE = 25

const TAB_PARAM = 'tab'

type Tab = 'search' | 'surnames' | 'places' | 'calculator'

/**
 * Looking through the family archive, rather than walking it.
 *
 * Every other route to a person in this portal starts from somebody: your own
 * record, a relative of a relative, a member in the directory. That is a good
 * way to find your grandmother and a hopeless way to find the great-uncle
 * whose name you half remember. This screen is the other way in.
 *
 * **Three tabs, because there are three different questions.** A member who
 * knows what they are looking for types it. A member who does not reads down
 * the names, or reads down the places — and those two are not a search with an
 * empty field, they are the thing that makes an archive something you can look
 * *through*. Tapping an entry in either index runs the same search from the
 * other side, which is why all three end in the same list of cards.
 *
 * Which tab is open, what was typed and which page it is on all live in the
 * address bar, exactly as they do on Kontakte: the Back button, a refresh and
 * a shared link then all keep the reader where they were.
 *
 * **What is not here is the whole of the privacy decision.** The server
 * answers with the dead, and with the living who put themselves in the member
 * directory — nobody else — so this screen does no filtering of its own and
 * has no way to ask for more. See `SearchConsent` in the module for why a
 * search needs a rule that tapping through a family does not.
 */
export function Tree() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()

  const query = params.get('q') ?? ''
  const surname = params.get('surname') ?? ''
  const place = params.get('place') ?? ''
  const page = Math.max(1, Number(params.get('page') ?? '1') || 1)

  const chosen = params.get(TAB_PARAM)
  const active: Tab =
    chosen === 'surnames' || chosen === 'places' || chosen === 'search' || chosen === 'calculator'
      ? chosen
      : surname !== ''
        ? 'surnames'
        : place !== ''
          ? 'places'
          : 'search'

  // The field stays responsive while the request that matches it is still in
  // flight — the same arrangement the member directory uses.
  const [draft, setDraft] = useState(query)

  useEffect(() => {
    setDraft(query)
  }, [query])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      if (draft !== query) {
        setParams(draft === '' ? { tab: 'search' } : { tab: 'search', q: draft }, { replace: true })
      }
    }, 250)

    return () => {
      window.clearTimeout(timer)
    }
  }, [draft, query, setParams])

  const search = useSearch({ q: query, surname, place, page })

  // Only fetched for the tabs that show it. It is the most expensive thing the
  // module computes — one pass over every record — and a member who came here
  // to type a name should not pay for it.
  const index = useTreeIndex(active === 'surnames' || active === 'places')

  const pages =
    search.data === undefined ? 1 : Math.max(1, Math.ceil(search.data.total / PER_PAGE))

  function open(tab: Tab): void {
    setParams({ [TAB_PARAM]: tab }, { replace: true })
  }

  function browse(tab: Tab, key: 'surname' | 'place', value: string): void {
    setParams({ [TAB_PARAM]: tab, [key]: value })
    window.scrollTo({ top: 0 })
  }

  function goToPage(next: number): void {
    const updated: Record<string, string> = { [TAB_PARAM]: active }

    if (query !== '') {
      updated['q'] = query
    }

    if (surname !== '') {
      updated['surname'] = surname
    }

    if (place !== '') {
      updated['place'] = place
    }

    if (next > 1) {
      updated['page'] = String(next)
    }

    setParams(updated)
    window.scrollTo({ top: 0 })
  }

  const browsing = surname !== '' ? surname : place !== '' ? place : null

  return (
    <>
      <PageHeading>{t('tree.title')}</PageHeading>

      <p className="mt-3 text-base text-slate-700">{t('tree.intro')}</p>

      <TabBar
        active={active}
        onSelect={open}
        labels={{
          search: t('tree.tabSearch'),
          surnames: t('tree.tabSurnames'),
          places: t('tree.tabPlaces'),
          calculator: t('tree.tabCalculator'),
        }}
      />

      {active === 'calculator' && <Calculator />}

      {active === 'search' && (
        <div className="mt-5">
          <Field
            label={t('tree.search')}
            hint={t('tree.searchHint')}
            type="search"
            inputMode="search"
            autoComplete="off"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
          />
        </div>
      )}

      {(active === 'surnames' || active === 'places') && browsing === null && (
        <Index
          entries={active === 'surnames' ? (index.data?.surnames ?? []) : (index.data?.places ?? [])}
          pending={index.isPending}
          error={index.isError ? index.error : null}
          onRetry={() => void index.refetch()}
          onOpen={(name) => browse(active, active === 'surnames' ? 'surname' : 'place', name)}
          emptyTitle={t(`tree.${active}.empty.title`)}
          emptyBody={t(`tree.${active}.empty.body`)}
          truncated={index.data?.truncated === true}
        />
      )}

      {browsing !== null && (
        <p className="mt-5">
          <Button variant="secondary" onClick={() => open(active)}>
            {t(active === 'surnames' ? 'tree.backToSurnames' : 'tree.backToPlaces')}
          </Button>
        </p>
      )}

      {browsing !== null && (
        <h2 className="mt-5 text-lg font-semibold text-slate-900">
          {t(active === 'surnames' ? 'tree.showingSurname' : 'tree.showingPlace', {
            name: browsing,
          })}
        </h2>
      )}

      {/*
        Nothing at all until something has been asked. An empty results area
        under an empty field would be the portal saying "no matches" about a
        question nobody put.
      */}
      {active !== 'calculator' && (query !== '' || browsing !== null) && (
        <Results
          pending={search.isPending}
          error={search.isError ? search.error : null}
          onRetry={() => void search.refetch()}
          page={search.data}
          pages={pages}
          currentPage={page}
          onPage={goToPage}
          asked={browsing ?? query}
          onClear={() => open('search')}
        />
      )}
    </>
  )
}

/**
 * The index itself: a name, and how many people are under it.
 *
 * The count is the whole reason this is more useful than an alphabet. It says
 * which surnames are the family and which are the one cousin who married in,
 * and it tells a member whether tapping is worth doing before they tap.
 */
function Index({
  entries,
  pending,
  error,
  onRetry,
  onOpen,
  emptyTitle,
  emptyBody,
  truncated,
}: {
  entries: IndexEntry[]
  pending: boolean
  error: unknown
  onRetry: () => void
  onOpen: (name: string) => void
  emptyTitle: string
  emptyBody: string
  truncated: boolean
}) {
  const { t } = useTranslation()

  if (pending) {
    return <Loading />
  }

  if (error !== null) {
    return (
      <div className="mt-5">
        <ErrorNotice error={error} onRetry={onRetry} />
      </div>
    )
  }

  if (entries.length === 0) {
    return (
      <div className="mt-5">
        <Notice title={emptyTitle} body={emptyBody} />
      </div>
    )
  }

  return (
    <>
      {truncated && (
        <p className="mt-5 text-base text-slate-700">{t('tree.truncated')}</p>
      )}

      <ul className="mt-5 space-y-2">
        {entries.map((entry) => (
          <li key={entry.name}>
            <button
              type="button"
              onClick={() => onOpen(entry.name)}
              className="flex min-h-[44px] w-full items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white p-4 text-left hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
            >
              <span className="min-w-0 text-base font-medium text-slate-900">{entry.name}</span>
              <span className="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-sm font-medium text-slate-700">
                {entry.count}
              </span>
            </button>
          </li>
        ))}
      </ul>
    </>
  )
}

function Results({
  pending,
  error,
  onRetry,
  page,
  pages,
  currentPage,
  onPage,
  asked,
  onClear,
}: {
  pending: boolean
  error: unknown
  onRetry: () => void
  page: SearchPage | undefined
  pages: number
  currentPage: number
  onPage: (next: number) => void
  asked: string
  onClear: () => void
}) {
  const { t } = useTranslation()

  if (pending) {
    return <Loading />
  }

  if (error !== null) {
    return (
      <div className="mt-5">
        <ErrorNotice error={error} onRetry={onRetry} />
      </div>
    )
  }

  if (page === undefined) {
    return null
  }

  if (page.items.length === 0) {
    return (
      <div className="mt-5">
        <Notice
          title={t('tree.noResults.title')}
          body={t('tree.noResults.body', { query: asked })}
          action={
            <Button variant="secondary" onClick={onClear}>
              {t('tree.noResults.action')}
            </Button>
          }
        />
      </div>
    )
  }

  return (
    <>
      <p aria-live="polite" className="mt-5 mb-3 text-base text-slate-700">
        {t('tree.count', { count: page.total })}
      </p>

      {page.truncated && (
        <Card>
          <p className="text-base text-slate-700">{t('tree.tooMany')}</p>
        </Card>
      )}

      <ul className="mt-3 space-y-2">
        {page.items.map((person) => (
          <li key={person.xref}>
            <PersonCard person={person} />
          </li>
        ))}
      </ul>

      {pages > 1 && (
        <nav className="mt-6 flex items-center justify-between gap-3">
          <Button
            variant="secondary"
            disabled={currentPage <= 1}
            onClick={() => onPage(currentPage - 1)}
          >
            {t('members.previous')}
          </Button>
          <span className="text-base text-slate-700">
            {t('members.page', { page: currentPage, pages })}
          </span>
          <Button
            variant="secondary"
            disabled={currentPage >= pages}
            onClick={() => onPage(currentPage + 1)}
          >
            {t('members.next')}
          </Button>
        </nav>
      )}
    </>
  )
}

/**
 * Two rows of buttons that swap one panel for another.
 *
 * The same shape Kontakte uses, and deliberately not a `<nav>` of links: these
 * are three views of one screen, not three screens.
 */
function TabBar({
  active,
  onSelect,
  labels,
}: {
  active: Tab
  onSelect: (tab: Tab) => void
  labels: Record<Tab, string>
}) {
  return (
    <div role="tablist" className="mt-5 flex gap-2 border-b border-slate-300">
      {(['search', 'surnames', 'places', 'calculator'] as const).map((tab) => (
        <button
          key={tab}
          type="button"
          role="tab"
          aria-selected={active === tab}
          onClick={() => onSelect(tab)}
          className={[
            '-mb-px min-h-[44px] flex-1 border-b-2 px-2 py-2 text-base font-semibold',
            'focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-sky-700',
            active === tab
              ? 'border-sky-800 text-sky-900'
              : 'border-transparent text-slate-700 hover:text-slate-900',
          ].join(' ')}
        >
          {labels[tab]}
        </button>
      ))}
    </div>
  )
}

/**
 * Two archive numbers in, a relationship out.
 *
 * The family has had this as a page of its own since 2009, and it is the one
 * screen here that touches no records at all: an SB number *is* an ancestral
 * path, so this is arithmetic on two strings. Which means it answers where
 * nothing else can — any distance at all, and about people who are not in the
 * tree, or whose connecting relatives are not the reader's to see.
 *
 * **The first field is filled in with the reader's own number**, because the
 * question somebody actually has at a family gathering is not "how are these
 * two strangers related" but "how am I related to this person" — and the
 * number they are holding is the other one, read off a card or the back of a
 * photograph. Filled in, not fixed: both fields stay editable, which is what
 * makes it the calculator the family already knows.
 *
 * Nothing is asked until both fields have something in them. Firing on every
 * keystroke would answer "not a valid number" about a number somebody is
 * halfway through typing.
 */
function Calculator() {
  const { t } = useTranslation()
  const { me } = useAuth()

  const mine = ownReference(me?.individual?.references)

  const [first, setFirst] = useState(mine ?? '')
  const [second, setSecond] = useState('')
  const [asked, setAsked] = useState<[string, string]>(['', ''])

  useEffect(() => {
    const timer = window.setTimeout(() => setAsked([first.trim(), second.trim()]), 300)

    return () => {
      window.clearTimeout(timer)
    }
  }, [first, second])

  const { data, isError, error, refetch } = useRelationship(asked[0], asked[1])

  return (
    <div className="mt-5">
      <p className="text-base text-slate-700">{t('tree.calc.intro')}</p>

      <div className="mt-4 space-y-4">
        <Field
          label={t('tree.calc.first')}
          {...(mine === null ? {} : { hint: t('tree.calc.firstHint') })}
          type="text"
          inputMode="text"
          autoComplete="off"
          spellCheck={false}
          value={first}
          onChange={(event) => setFirst(event.target.value)}
        />
        <Field
          label={t('tree.calc.second')}
          type="text"
          inputMode="text"
          autoComplete="off"
          spellCheck={false}
          value={second}
          onChange={(event) => setSecond(event.target.value)}
        />
      </div>

      {isError && (
        <div className="mt-4">
          <ErrorNotice error={error} onRetry={() => void refetch()} />
        </div>
      )}

      {data !== undefined && data.problem !== 'incomplete' && (
        <div className="mt-5">
          <Card>
            {data.relationship === null ? (
              <p className="text-base text-slate-900">{t(`tree.calc.problem.${data.problem}`)}</p>
            ) : (
              <>
                <p className="text-lg font-semibold text-slate-900">{data.relationship}</p>
                <p className="mt-2 text-base text-slate-700">
                  {t('tree.calc.result', { second: data.b, first: data.a })}
                </p>
              </>
            )}
          </Card>
        </div>
      )}

      <p className="mt-4 text-sm text-slate-600">{t('tree.calc.note')}</p>
    </div>
  )
}
