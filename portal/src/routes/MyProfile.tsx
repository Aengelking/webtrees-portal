import { useTranslation } from 'react-i18next'
import { useMe } from '../api/queries'
import { IndividualView } from '../components/IndividualView'
import { ErrorNotice, Loading, Notice, PageHeading } from '../components/ui'

export function MyProfile() {
  const { t } = useTranslation()
  const { data, isPending, isError, error, refetch } = useMe()

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
              <p className="mb-6 rounded-lg bg-slate-200 p-4 text-base text-slate-800">
                {t('profile.readOnly')}
              </p>
              <IndividualView individual={data.individual} />
            </>
          )}
        </div>
      )}
    </>
  )
}
