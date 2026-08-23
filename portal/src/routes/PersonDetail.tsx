import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useIndividual } from '../api/queries'
import { IndividualView } from '../components/IndividualView'
import { ErrorNotice, Loading, Notice, PageHeading } from '../components/ui'

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
 *
 * **And an invitation, where one is possible.** Walking the tree is how a
 * member notices that their uncle is not in the portal; the invite screen is
 * where they would have to go and find him again, which is the same "knowing
 * where the way in is, is not the same as being able to take it" as §2.38.
 *
 * Whether one is possible is the record's own `invitable`, answered by the
 * same rule the endpoint that issues the invitation applies. It used to be
 * worked out here from the list of candidates, which was fine while that list
 * was a member's close family and wrong the moment it became an editor's whole
 * tree — this screen would have had to hold thousands of records to answer one
 * question about one of them.
 *
 * Its absence stays as uninformative as it ever was: dead, already an account
 * holder, already invited and too distant are all simply "no button", exactly
 * so that a member cannot learn which by looking.
 */
export function PersonDetail() {
  const { t } = useTranslation()
  const { xref } = useParams<{ xref: string }>()
  const { data, isPending, isError, error, refetch } = useIndividual(xref)

  // Optional on purpose: the module and the portal deploy separately, so a
  // server that predates this field simply makes no offer.
  const invitable = data?.invitable === true

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

          {invitable && (
            <div className="mt-8">
              <Notice
                title={t('person.invite.title')}
                body={t('person.invite.body', { name: data.name })}
                action={
                  <Link
                    to={`/invite?xref=${encodeURIComponent(xref ?? '')}`}
                    className="inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
                  >
                    {t('person.invite.action')}
                  </Link>
                }
              />
            </div>
          )}

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
