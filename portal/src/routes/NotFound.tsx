import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Notice, PageHeading } from '../components/ui'

export function NotFound() {
  const { t } = useTranslation()

  return (
    <>
      <PageHeading>{t('error.pageNotFound.title')}</PageHeading>
      <div className="mt-6">
        <Notice
          title={t('error.pageNotFound.title')}
          body={t('error.pageNotFound.body')}
          action={
            <Link
              to="/me"
              className="inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white"
            >
              {t('error.pageNotFound.action')}
            </Link>
          }
        />
      </div>
    </>
  )
}
