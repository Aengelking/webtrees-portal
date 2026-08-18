import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useIndividual } from '../api/queries'
import { IndividualView } from '../components/IndividualView'
import { ErrorNotice, Loading, PageHeading } from '../components/ui'

/**
 * One person in the tree, reached by tapping a relative.
 *
 * This is the screen that makes the family tree walkable inside the portal
 * rather than only in webtrees. It shows exactly what `/individuals/{xref}`
 * returns, which is exactly what this member may see: a person they may not
 * see is a 404 here, indistinguishable from one who does not exist.
 *
 * Deliberately no "back to my profile" — the browser's own back button is the
 * way back from a walk, and a walk can be many steps deep. What it offers
 * instead is the way *onward*: relatives are links, and there is the ancestors
 * view.
 */
export function PersonDetail() {
  const { t } = useTranslation()
  const { xref } = useParams<{ xref: string }>()
  const { data, isPending, isError, error, refetch } = useIndividual(xref)

  return (
    <>
      <PageHeading>{t('person.title')}</PageHeading>

      {isPending && <Loading />}

      {isError && (
        <div className="mt-6">
          <ErrorNotice error={error} onRetry={() => void refetch()} />
        </div>
      )}

      {data !== undefined && (
        <div className="mt-6">
          <IndividualView individual={data} />

          <p className="mt-8">
            <Link
              to="/me"
              className="inline-flex min-h-[44px] items-center text-base font-semibold text-sky-800 underline underline-offset-4"
            >
              {t('person.backToProfile')}
            </Link>
          </p>
        </div>
      )}
    </>
  )
}
