import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMember } from '../api/queries'
import { IndividualView } from '../components/IndividualView'
import { ErrorNotice, Loading, Notice, PageHeading } from '../components/ui'

export function MemberDetail() {
  const { t } = useTranslation()
  const { id } = useParams<{ id: string }>()
  const numericId = Number(id)

  const { data, isPending, isError, error, refetch } = useMember(
    Number.isFinite(numericId) && numericId > 0 ? numericId : undefined,
  )

  return (
    <>
      <p className="mb-4">
        <Link
          to="/members"
          className="inline-flex min-h-[44px] items-center text-base font-semibold text-sky-800 underline underline-offset-4"
        >
          {t('member.back')}
        </Link>
      </p>

      {isPending && <Loading />}

      {isError && <ErrorNotice error={error} onRetry={() => void refetch()} />}

      {data !== undefined && (
        <>
          <PageHeading>{data.display_name}</PageHeading>

          <div className="mt-6">
            {data.individual_detail === null ? (
              <Notice title={t('member.private.title')} body={t('member.private.body')} />
            ) : (
              <IndividualView individual={data.individual_detail} />
            )}
          </div>
        </>
      )}
    </>
  )
}
