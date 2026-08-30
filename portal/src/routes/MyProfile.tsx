import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMe } from '../api/queries'
import { DirectoryPrompt } from '../components/DirectoryPrompt'
import { IndividualView } from '../components/IndividualView'
import { InviteCard } from '../components/InviteCard'
import { MyPhotos } from '../components/MyPhotos'
import { ErrorNotice, Loading, Notice, PageHeading, SuccessNote } from '../components/ui'

export function MyProfile() {
  const { t } = useTranslation()
  const location = useLocation()
  const { data, isPending, isError, error, refetch } = useMe()

  const submitted = (location.state as { submitted?: string } | null)?.submitted

  return (
    <>
      <PageHeading>{t('profile.title')}</PageHeading>

      {/*
        Above everything, and above the record itself: it is a question waiting
        for an answer, and a question below a screenful of family data is one
        nobody scrolls to. It removes itself the moment it is answered — see
        `DirectoryPrompt` for why "answered" is a fact about the member rather
        than about this telephone.
      */}
      <DirectoryPrompt />

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

              {/*
                Below the record, because it is about the record rather than
                part of it — and on the member's own page only, which is the
                only page where adding a photograph means anything.
              */}
              <MyPhotos photos={data.individual.photos ?? []} />
            </>
          )}
        </div>
      )}

      {/*
        Always, and outside everything above it: not part of the record, not
        conditional on there being one, and not conditional on the record
        having loaded. A member who has no linked record yet is exactly the
        member most likely to want somebody else brought in, and an entry that
        appears only sometimes is one nobody learns the place of.
      */}
      <InviteCard />
    </>
  )
}
