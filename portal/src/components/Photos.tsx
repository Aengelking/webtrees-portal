import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { IndividualRef, Photo } from '../api/types'
import { Section } from './ui'

/**
 * A face beside a name, or the space where one would be.
 *
 * The space is deliberate: a list where some rows have a picture and some do
 * not, with the text starting at a different place each time, is harder to
 * read than a list with none at all. So the initial stands in, and the column
 * stays put.
 *
 * `alt` is empty on purpose. The name is right there in the markup next to it;
 * a screen reader announcing "photograph of Anna Beispiel, Anna Beispiel" is
 * worse than one that skips a decorative image.
 */
export function Portrait({ person, size = 48 }: { person: IndividualRef; size?: number }) {
  const portrait = person.portrait ?? null

  if (portrait === null) {
    return (
      <span
        aria-hidden="true"
        style={{ width: size, height: size }}
        className="flex shrink-0 items-center justify-center rounded-full bg-slate-200 text-base font-semibold text-slate-600"
      >
        {initial(person.name)}
      </span>
    )
  }

  return (
    <img
      src={portrait.thumbnail_url}
      alt=""
      width={size}
      height={size}
      loading="lazy"
      style={{ width: size, height: size }}
      className="shrink-0 rounded-full bg-slate-200 object-cover"
    />
  )
}

/**
 * The pictures on a record.
 *
 * Tapping one opens it full size — not in a new tab, because the URL needs the
 * session cookie and a bare image URL in the address bar is a dead end with no
 * way back. The overlay is dismissed by tapping anywhere or pressing Escape,
 * which are the two things people try.
 */
export function Gallery({ photos }: { photos: Photo[] }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState<Photo | null>(null)

  if (photos.length === 0) {
    return null
  }

  return (
    <Section title={t('individual.photos')}>
      <ul className="grid grid-cols-3 gap-2 sm:grid-cols-4">
        {photos.map((photo) => (
          <li key={photo.id}>
            <button
              type="button"
              onClick={() => setOpen(photo)}
              className="block w-full overflow-hidden rounded-lg border border-slate-300 bg-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
            >
              <img
                src={photo.thumbnail_url}
                alt={photo.title ?? t('individual.photoUntitled')}
                loading="lazy"
                className="aspect-square w-full bg-slate-100 object-cover"
              />
            </button>
          </li>
        ))}
      </ul>

      {open !== null && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label={open.title ?? t('individual.photoUntitled')}
          onClick={() => setOpen(null)}
          onKeyDown={(event) => {
            if (event.key === 'Escape') {
              setOpen(null)
            }
          }}
          className="fixed inset-0 z-50 flex flex-col items-center justify-center bg-slate-900/90 p-4"
        >
          <img
            src={open.image_url}
            alt={open.title ?? t('individual.photoUntitled')}
            className="max-h-[80vh] max-w-full object-contain"
          />
          {open.title !== null && <p className="mt-4 text-base text-white">{open.title}</p>}
          <button
            type="button"
            onClick={() => setOpen(null)}
            className="mt-6 min-h-[44px] rounded-lg bg-white px-5 py-3 text-base font-semibold text-slate-900"
          >
            {t('individual.photoClose')}
          </button>
        </div>
      )}
    </Section>
  )
}

function initial(name: string): string {
  return [...name.trim()][0]?.toUpperCase() ?? '?'
}
