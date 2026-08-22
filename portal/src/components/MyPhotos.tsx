import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQueryClient } from '@tanstack/react-query'
import { api } from '../api/client'
import type { Photo } from '../api/types'
import { Button, Card, ErrorNotice, Section, SuccessNote } from './ui'

/**
 * A member's own photographs: adding one, and taking it down again.
 *
 * **This is the other half of a privacy rule**, and it only makes sense
 * alongside it. Since Phase 15 a photograph of a living person is shown in the
 * portal only where that person uploaded it themselves — see the module's
 * `Schema/Migration9.php` for the argument. A rule like that with no way to
 * add a photograph is not a privacy feature; it is a portal with no faces in
 * it. So the two shipped together, and this screen says what the rule is,
 * because a member who finds their picture gone deserves to read why rather
 * than to guess.
 *
 * Deleting needs no confirmation step. It is the member's own photograph, the
 * consequence is visible immediately, and putting it back is the button above.
 * A confirmation here would be ceremony around the one action in this portal
 * that is *supposed* to be easy — §2.x's argument about withdrawal.
 */
export function MyPhotos({ photos }: { photos: Photo[] }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const chooser = useRef<HTMLInputElement>(null)

  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState<unknown>(null)
  const [waiting, setWaiting] = useState(false)

  async function add(file: File) {
    setBusy(true)
    setFailed(null)
    setWaiting(false)

    try {
      const result = await api.addPhoto(file)

      setWaiting(result.pending)
      await queryClient.invalidateQueries({ queryKey: ['me'] })
    } catch (cause) {
      setFailed(cause)
    } finally {
      setBusy(false)

      // So the same file can be chosen twice — after a failure, most of all.
      if (chooser.current !== null) {
        chooser.current.value = ''
      }
    }
  }

  async function remove(photo: Photo) {
    setBusy(true)
    setFailed(null)
    setWaiting(false)

    try {
      await api.removePhoto(xrefOf(photo))
      await queryClient.invalidateQueries({ queryKey: ['me'] })
    } catch (cause) {
      setFailed(cause)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Section title={t('myPhotos.title')}>
      <Card>
        <p className="text-base text-slate-700">{t('myPhotos.body')}</p>

        {/* Why a photograph a member did not upload is not on their record. */}
        <p className="mt-3 text-base text-slate-900">{t('myPhotos.rule')}</p>

        {photos.length > 0 && (
          <ul className="mt-5 grid grid-cols-3 gap-3 sm:grid-cols-4">
            {photos.map((photo) => (
              <li key={photo.id}>
                <img
                  src={photo.thumbnail_url}
                  alt={photo.title ?? t('myPhotos.untitled')}
                  className="aspect-square w-full rounded-lg border border-slate-300 bg-slate-100 object-cover"
                />
                <Button
                  variant="secondary"
                  className="mt-2 w-full"
                  disabled={busy}
                  onClick={() => void remove(photo)}
                >
                  {t('myPhotos.remove')}
                </Button>
              </li>
            ))}
          </ul>
        )}

        {failed !== null && (
          <div className="mt-4">
            <ErrorNotice error={failed} />
          </div>
        )}

        {waiting && (
          <div className="mt-4">
            <SuccessNote>{t('myPhotos.waiting')}</SuccessNote>
          </div>
        )}

        <div className="mt-5">
          <label
            htmlFor="my-photo"
            className="mb-2 block text-base font-medium text-slate-900"
          >
            {t('myPhotos.choose')}
          </label>
          <input
            id="my-photo"
            ref={chooser}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            disabled={busy}
            onChange={(event) => {
              const file = event.target.files?.[0]

              if (file !== undefined) {
                void add(file)
              }
            }}
            className="block w-full text-base text-slate-900 file:mr-4 file:min-h-[44px] file:rounded-lg file:border-0 file:bg-sky-800 file:px-5 file:py-3 file:text-base file:font-semibold file:text-white"
          />
          <p className="mt-2 text-base text-slate-700">{t('myPhotos.hint')}</p>
        </div>
      </Card>
    </Section>
  )
}

/**
 * The media record's xref, which the portal knows only as part of the URL it
 * was handed: `/api/v1/media/{xref}/{fact}/thumbnail`.
 *
 * Read out rather than carried as its own field, because the field would be a
 * second name for something the client already has — and one more thing for
 * the two sides to disagree about.
 */
function xrefOf(photo: Photo): string {
  return photo.thumbnail_url.split('/')[4] ?? ''
}
