import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMembers } from '../api/queries'
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
                <Link
                  to={`/members/${member.id}`}
                  className="block rounded-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
                >
                  <Card>
                    <p className="text-lg font-semibold text-slate-900">{member.display_name}</p>
                    <p className="mt-1 text-base text-slate-700">
                      {member.individual === null
                        ? t('members.noRecord')
                        : [member.individual.name, member.individual.lifespan]
                            .filter((part): part is string => part !== null && part !== '')
                            .join(' · ')}
                    </p>
                  </Card>
                </Link>
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
