import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useContact, useUpdateContact } from '../api/queries'
import type { ContactAudience, ContactKind, OwnContact } from '../api/types'
import { Button, ErrorNotice, Field, Loading, Notice, SuccessNote } from '../components/ui'

const KINDS: ContactKind[] = ['email', 'phone', 'address']

const AUDIENCES: ContactAudience[] = ['nobody', 'close_family', 'members']

/**
 * What I share, and with whom — one decision per entry.
 *
 * Per entry rather than one switch for everything, because "my email may go
 * to the whole family" and "my address is for my brother" are genuinely
 * different answers, and a single switch forces the narrower one onto both.
 *
 * The rule that makes the form readable is that an empty field shares
 * nothing, whatever the selector next to it says. So there is never a state
 * where somebody has to reason about what a blank value with an audience
 * means: the server deletes the row either way.
 */
export function ContactSettings() {
  const { t } = useTranslation()
  const { data, isPending, isError, error, refetch } = useContact()
  const mutation = useUpdateContact()

  const [entries, setEntries] = useState<OwnContact>({})
  const [saved, setSaved] = useState(false)

  // The form follows the server until the member starts typing.
  useEffect(() => {
    if (data !== undefined) {
      setEntries(data.contact)
    }
  }, [data])

  function set(kind: ContactKind, patch: Partial<{ value: string; audience: ContactAudience }>) {
    setSaved(false)
    setEntries((current) => ({
      ...current,
      [kind]: {
        value: current[kind]?.value ?? '',
        audience: current[kind]?.audience ?? 'nobody',
        ...patch,
      },
    }))
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaved(false)

    // Every kind is sent, including the ones left empty, so that clearing a
    // field reaches the server as the withdrawal it is.
    const payload: OwnContact = {}

    for (const kind of KINDS) {
      payload[kind] = {
        value: entries[kind]?.value ?? '',
        audience: entries[kind]?.audience ?? 'nobody',
      }
    }

    try {
      await mutation.mutateAsync(payload)
      setSaved(true)
    } catch {
      // The error is rendered from the mutation below.
    }
  }

  if (isPending) {
    return <Loading />
  }

  if (isError) {
    return <ErrorNotice error={error} onRetry={() => void refetch()} />
  }

  if (data !== undefined && !data.enabled) {
    return <Notice title={t('contact.off.title')} body={t('contact.off.body')} />
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <p className="mb-5 text-base text-slate-700">{t('contact.intro')}</p>

      {mutation.isError && (
        <div className="mb-5">
          <ErrorNotice error={mutation.error} />
        </div>
      )}

      {saved && <SuccessNote>{t('contact.saved')}</SuccessNote>}

      {KINDS.map((kind) => (
        <div key={kind} className="mb-6 rounded-xl border border-slate-300 bg-white p-4">
          <Field
            label={t(`contact.kind.${kind}`)}
            type={kind === 'email' ? 'email' : 'text'}
            autoComplete="off"
            value={entries[kind]?.value ?? ''}
            hint={t(`contact.hint.${kind}`)}
            onChange={(event) => set(kind, { value: event.target.value })}
          />

          <fieldset>
            <legend className="mb-2 text-base font-medium text-slate-900">
              {t('contact.audienceLegend')}
            </legend>

            <div className="space-y-2">
              {AUDIENCES.map((audience) => (
                <label key={audience} className="flex min-h-[44px] items-center gap-3">
                  <input
                    type="radio"
                    name={`${kind}-audience`}
                    value={audience}
                    checked={(entries[kind]?.audience ?? 'nobody') === audience}
                    onChange={() => set(kind, { audience })}
                    className="h-5 w-5"
                  />
                  <span className="text-base text-slate-900">{t(`contact.audience.${audience}`)}</span>
                </label>
              ))}
            </div>
          </fieldset>
        </div>
      ))}

      <Button type="submit" disabled={mutation.isPending}>
        {mutation.isPending ? t('contact.saving') : t('contact.save')}
      </Button>
    </form>
  )
}
