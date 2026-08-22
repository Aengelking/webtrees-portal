import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useIndividual, useInvitations } from '../api/queries'
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
 * The offer appears only for somebody `GET /invitations` already names as a
 * candidate, so nothing new is disclosed here — and its absence stays as
 * uninformative as it is on that screen: dead, already an account holder,
 * already invited and too distant are all simply "no button", exactly so that
 * a member cannot learn which by looking.
 */
export function PersonDetail() {
  const { t } = useTranslation()
  const { xref } = useParams<{ xref: string }>()
  const { data, isPending, isError, error, refetch } = useIndividual(xref)

  // A second request, and deliberately not a second failure: an offer that
  // could not be loaded is an offer that is not made. The person is what this
  // screen is for, and it must not break over the button beside them.
  const invitations = useInvitations()

  const invitable =
    invitations.data?.enabled === true &&
    invitations.data.linked &&
    invitations.data.remaining > 0 &&
    invitations.data.candidates.some((candidate) => candidate.xref === xref)

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
