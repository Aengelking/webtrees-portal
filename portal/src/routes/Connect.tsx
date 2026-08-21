import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useConnect } from '../api/queries'
import { Button, Card, ErrorNotice, Notice, PageHeading, SuccessNote } from '../components/ui'

/**
 * Where a scanned connection code lands.
 *
 * The QR code holds a link to this screen, so the telephone's own camera is
 * the scanner — nothing to install, no camera permission for the portal, and
 * it works on iOS, where the browser API for reading barcodes does not exist.
 *
 * **It does not connect on arrival.** Opening a link is not consent to
 * anything, and a page that acts before it is read is a page that acts when a
 * link is opened by accident, by a preview, or by somebody else's telephone.
 * So it says what is about to happen and waits for the button.
 *
 * Somebody who is not signed in is sent to sign in first and comes back here
 * with the code intact, because the router keeps the address it turned away.
 */
export function Connect() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const connect = useConnect()
  const [done, setDone] = useState<string | null>(null)

  const code = params.get('code') ?? ''

  async function onConnect() {
    try {
      const result = await connect.mutateAsync({ code })
      setDone(result.name)
    } catch {
      // Rendered from the mutation below.
    }
  }

  if (code === '') {
    return (
      <>
        <PageHeading>{t('connect.title')}</PageHeading>
        <div className="mt-6">
          <Notice title={t('connect.missing.title')} body={t('connect.missing.body')} />
        </div>
        <p className="mt-6">
          <Link
            to="/contacts"
            className="inline-flex min-h-[44px] items-center text-base font-semibold text-sky-800 underline underline-offset-4"
          >
            {t('connect.toContacts')}
          </Link>
        </p>
      </>
    )
  }

  return (
    <>
      <PageHeading>{t('connect.title')}</PageHeading>

      <div className="mt-6">
        {done === null ? (
          <Card>
            <p className="text-base text-slate-700">{t('connect.intro')}</p>

            {connect.isError && (
              <div className="mt-4">
                <ErrorNotice error={connect.error} />
              </div>
            )}

            <div className="mt-5">
              <Button disabled={connect.isPending} onClick={() => void onConnect()}>
                {connect.isPending ? t('connect.connecting') : t('connect.connect')}
              </Button>
            </div>
          </Card>
        ) : (
          <>
            <SuccessNote>{t('connect.done', { name: done })}</SuccessNote>
            <p className="mt-6">
              <Link
                to="/contacts"
                className="inline-flex min-h-[44px] items-center text-base font-semibold text-sky-800 underline underline-offset-4"
              >
                {t('connect.toContacts')}
              </Link>
            </p>
          </>
        )}
      </div>
    </>
  )
}
