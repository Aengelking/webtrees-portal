import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMe } from '../api/queries'
import { IndividualView } from '../components/IndividualView'
import { ErrorNotice, Loading, Notice, PageHeading, SuccessNote } from '../components/ui'

export function MyProfile() {
  const { t } = useTranslation()
  const location = useLocation()
  const { data, isPending, isError, error, refetch } = useMe()

  const submitted = (location.state as { submitted?: string } | null)?.submitted

  return (
    <>
      <PageHeading>{t('profile.title')}</PageHeading>

      {isPending && <Loading />}

      {isError && (
        <div className="mt-6">
          <ErrorNotice error={error} onRetry={() => void refetch()} />
        </div>
      )}

      {data !== undefined && (
        <div className="mt-6">
          {data.individual === null ? (
            <Notice title={t('profile.noRecord.title')} body={t('profile.noRecord.body')} />
          ) : (
            <>
              {submitted !== undefined && (
                <div className="mb-6">
                  <SuccessNote>
                    {t(submitted === 'applied' ? 'edit.applied.body' : 'edit.submitted.body')}
                  </SuccessNote>
                </div>
              )}

              {data.individual.pending_change ? (
                <div className="mb-6">
                  <Notice title={t('profile.pending.title')} body={t('profile.pending.body')} />
                </div>
              ) : (
                <p className="mb-6">
                  <Link
                    to="/me/edit"
                    className="inline-flex min-h-[44px] w-full items-center justify-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white"
                  >
                    {t('profile.edit')}
                  </Link>
                </p>
              )}

              <IndividualView individual={data.individual} />
            </>
          )}
        </div>
      )}
    </>
  )
}
