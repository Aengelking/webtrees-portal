import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMe, useUpdateIndividual } from '../api/queries'
import type { Individual, IndividualUpdate } from '../api/types'
import { Button, ErrorNotice, Field, Loading, Notice, PageHeading, Section } from '../components/ui'

/** The fields the API accepts, and where each one comes from on the record. */
type FormValues = Record<keyof IndividualUpdate, string>

const EMPTY: FormValues = {
  given_names: '',
  surname: '',
  birth_date: '',
  birth_place: '',
  occupation: '',
  address: '',
  email: '',
  phone: '',
  website: '',
}

/**
 * Fill the form from the record.
 *
 * The API returns a rendered record rather than the raw field values, so the
 * form reads what it can back out of it. Anything it cannot recover starts
 * empty — and because the request only sends *changed* fields, an empty box
 * the member never touches leaves that fact alone rather than deleting it.
 */
function initialValues(individual: Individual): FormValues {
  const eventValue = (tag: string): string =>
    individual.events.find((event) => event.tag === tag)?.value ?? ''

  const [given = '', surname = ''] = splitName(individual.name)

  return {
    ...EMPTY,
    given_names: given,
    surname,
    birth_date: individual.birth?.date?.gedcom ?? '',
    birth_place: individual.birth?.place ?? '',
    occupation: eventValue('INDI:OCCU'),
    address: eventValue('INDI:ADDR'),
    email: eventValue('INDI:EMAIL'),
    phone: eventValue('INDI:PHON'),
    website: eventValue('INDI:WWW'),
  }
}

/**
 * The displayed name is "given names surname" with no marker between them, so
 * the last word is taken as the surname. Wrong for a two-word surname — which
 * is why both boxes are editable and prefilled rather than parsed on submit.
 */
function splitName(name: string): [string, string] {
  const parts = name.trim().split(/\s+/)

  if (parts.length < 2) {
    return [name.trim(), '']
  }

  return [parts.slice(0, -1).join(' '), parts[parts.length - 1] as string]
}

export function EditProfile() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { data, isPending, isError, error, refetch } = useMe()
  const mutation = useUpdateIndividual()

  const [values, setValues] = useState<FormValues | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  if (isPending) {
    return <Loading />
  }

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />
  }

  const individual = data?.individual ?? null

  if (individual === null) {
    return (
      <>
        <PageHeading>{t('edit.title')}</PageHeading>
        <div className="mt-6">
          <Notice title={t('profile.noRecord.title')} body={t('edit.noRecord')} />
        </div>
      </>
    )
  }

  if (individual.pending_change) {
    return (
      <>
        <PageHeading>{t('edit.title')}</PageHeading>
        <div className="mt-6">
          <Notice
            title={t('edit.blocked.title')}
            body={t('edit.blocked.body')}
            action={
              <Link
                to="/me"
                className="inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white"
              >
                {t('edit.submitted.action')}
              </Link>
            }
          />
        </div>
      </>
    )
  }

  const original = initialValues(individual)
  const current = values ?? original

  function set(field: keyof IndividualUpdate, value: string) {
    setValues({ ...current, [field]: value })
    setNotice(null)
  }

  /** Only what actually changed; an empty box becomes null, which removes the fact. */
  function changedFields(): IndividualUpdate {
    const changes: IndividualUpdate = {}

    for (const key of Object.keys(EMPTY) as (keyof IndividualUpdate)[]) {
      if (current[key].trim() !== original[key].trim()) {
        changes[key] = current[key].trim() === '' ? null : current[key].trim()
      }
    }

    return changes
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const changes = changedFields()

    if (Object.keys(changes).length === 0) {
      setNotice(t('edit.unchanged'))
      return
    }

    try {
      await mutation.mutateAsync(changes)
      void navigate('/me', { state: { submitted: true } })
    } catch {
      // Rendered from mutation.error below.
    }
  }

  return (
    <>
      <PageHeading>{t('edit.title')}</PageHeading>
      <p className="mt-2 text-base text-slate-700">{t('edit.intro')}</p>

      <form onSubmit={onSubmit} noValidate className="mt-6">
        {mutation.isError && (
          <div className="mb-6">
            <ErrorNotice error={mutation.error} />
          </div>
        )}

        {notice !== null && (
          <p role="status" className="mb-6 rounded-lg bg-slate-200 p-4 text-base text-slate-800">
            {notice}
          </p>
        )}

        <Section title={t('edit.section.name')}>
          <Field
            label={t('edit.givenNames')}
            autoComplete="given-name"
            value={current.given_names}
            onChange={(event) => set('given_names', event.target.value)}
          />
          <Field
            label={t('edit.surname')}
            autoComplete="family-name"
            value={current.surname}
            onChange={(event) => set('surname', event.target.value)}
          />
        </Section>

        <Section title={t('edit.section.birth')}>
          <Field
            label={t('edit.birthDate')}
            hint={t('edit.birthDateHint')}
            value={current.birth_date}
            onChange={(event) => set('birth_date', event.target.value)}
          />
          <Field
            label={t('edit.birthPlace')}
            value={current.birth_place}
            onChange={(event) => set('birth_place', event.target.value)}
          />
        </Section>

        <Section title={t('edit.section.work')}>
          <Field
            label={t('edit.occupation')}
            value={current.occupation}
            onChange={(event) => set('occupation', event.target.value)}
          />
        </Section>

        <Section title={t('edit.section.contact')}>
          <p className="mb-4 text-base text-slate-700">{t('edit.contactHint')}</p>
          <Field
            label={t('edit.address')}
            autoComplete="street-address"
            value={current.address}
            onChange={(event) => set('address', event.target.value)}
          />
          <Field
            label={t('edit.email')}
            type="email"
            autoComplete="email"
            value={current.email}
            onChange={(event) => set('email', event.target.value)}
          />
          <Field
            label={t('edit.phone')}
            type="tel"
            autoComplete="tel"
            value={current.phone}
            onChange={(event) => set('phone', event.target.value)}
          />
          <Field
            label={t('edit.website')}
            type="url"
            value={current.website}
            onChange={(event) => set('website', event.target.value)}
          />
        </Section>

        <div className="mt-8 flex flex-col gap-3">
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? t('edit.submitting') : t('edit.submit')}
          </Button>
          <Link
            to="/me"
            className="inline-flex min-h-[44px] items-center justify-center rounded-lg border border-slate-400 bg-white px-5 py-3 text-base font-semibold text-slate-900"
          >
            {t('edit.cancel')}
          </Link>
        </div>
      </form>
    </>
  )
}
