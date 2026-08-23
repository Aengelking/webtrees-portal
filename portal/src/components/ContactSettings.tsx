import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useContact, useUpdateContact } from '../api/queries'
import type { AddressParts, ContactAudience, ContactKind, OwnContact } from '../api/types'
import { addressFields, composeAddress, EMPTY_ADDRESS } from './address'
import { Button, ErrorNotice, Field, Loading, Notice, SuccessNote } from '../components/ui'

const KINDS: ContactKind[] = ['email', 'phone', 'address']

const AUDIENCES: ContactAudience[] = ['nobody', 'close_family', 'connections', 'members']

/** The fields an address is made of, in the order they are written down. */
const PARTS: (keyof AddressParts)[] = ['street', 'postcode', 'city', 'country']

/**
 * "Only my contacts" is dropped when the family has switched connections off,
 * because it would then share nothing at all. An older server does not say
 * either way, and the choice is offered — the server is the one that decides
 * what an audience means, and it will refuse to disclose anything it should
 * not.
 */
function audiencesFor(connectionsEnabled: boolean | undefined): ContactAudience[] {
  return connectionsEnabled === false
    ? AUDIENCES.filter((audience) => audience !== 'connections')
    : AUDIENCES
}

/**
 * What I share, and with whom — one decision per entry.
 *
 * Per entry rather than one switch for everything, because "my email may go
 * to the whole family" and "my address is for my brother" are genuinely
 * different answers, and a single switch forces the narrower one onto both.
 *
 * Four audiences, and only one of them is a list the member built
 * themselves: "my contacts" is the people they connected with, each of whom
 * agreed to it. Close family is decided by the tree and "all members" by the
 * directory.
 *
 * The rule that makes the form readable is that an empty field shares
 * nothing, whatever the selector next to it says. So there is never a state
 * where somebody has to reason about what a blank value with an audience
 * means: an entry with nothing in it is deleted, whoever it was for.
 *
 * **And that is now the only way to delete one.** "Niemand" used to delete
 * the entry as well; it keeps it and shows it to nobody, because an address
 * shared with no relative is still the address the family magazine is posted
 * to. The form says both halves of that out loud — what "Niemand" keeps, and
 * that emptying the field is the delete — because an entry a member believes
 * they deleted would be the worse bargain.
 *
 * **It reads before it writes.** Settings used to open with the whole form
 * unrolled — three text fields and twelve radio buttons, every one of them
 * live — for a member who had come to look at what they were sharing, which
 * is the far commoner errand. Now the answer is the first thing on the screen
 * and the form is behind a button. Nothing is hidden by that: what a member
 * shares is *shown*, in plain sentences, including the entries they have not
 * filled in. What is put away is the machinery for changing it.
 */
export function ContactSettings() {
  const { t } = useTranslation()
  const { data, isPending, isError, error, refetch } = useContact()
  const mutation = useUpdateContact()

  const [entries, setEntries] = useState<OwnContact>({})
  const [address, setAddress] = useState<AddressParts>(EMPTY_ADDRESS)
  const [editing, setEditing] = useState(false)
  const [saved, setSaved] = useState(false)

  // The form follows the server until the member starts typing. `contact`
  // is addressed defensively for the same reason the screens that read a
  // record are: a response that arrives without it must leave a working form
  // rather than a white screen.
  useEffect(() => {
    if (data !== undefined) {
      setEntries(data.contact ?? {})
      setAddress(addressFields(data.contact?.address))
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

  function setPart(part: keyof AddressParts, value: string) {
    setSaved(false)
    setAddress((current) => ({ ...current, [part]: value }))
  }

  function edit() {
    setSaved(false)
    setEditing(true)
  }

  function cancel() {
    // Back to what the server says, so that abandoning a half-typed change
    // leaves nothing of it behind for the next time the form is opened.
    setEntries(data?.contact ?? {})
    setAddress(addressFields(data?.contact?.address))
    setEditing(false)
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

    // The address goes both ways at once: the fields for a server that has
    // them, and the composed text for one that predates them. A server that
    // understands `parts` ignores the text and composes the same thing.
    payload.address = {
      value: composeAddress(address),
      parts: address,
      audience: entries.address?.audience ?? 'nobody',
    }

    try {
      await mutation.mutateAsync(payload)
      setSaved(true)
      setEditing(false)
    } catch {
      // The error is rendered from the mutation below, and the form stays
      // open with what the member typed still in it.
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

  if (!editing) {
    return (
      <div>
        <p className="mb-5 text-base text-slate-700">{t('contact.summaryIntro')}</p>

        {saved && <SuccessNote>{t('contact.saved')}</SuccessNote>}

        {/*
          Straight from the server rather than from the form's state: this is
          what is being shared, and a half-typed change that was abandoned is
          not.
        */}
        <dl className="mb-6">
          {KINDS.map((kind) => (
            <Shared
              key={kind}
              kind={kind}
              value={data?.contact?.[kind]?.value ?? ''}
              audience={data?.contact?.[kind]?.audience ?? 'nobody'}
            />
          ))}
        </dl>

        <Button variant="secondary" onClick={edit}>
          {t('contact.change')}
        </Button>
      </div>
    )
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <p className="mb-3 text-base text-slate-700">{t('contact.intro')}</p>

      {/*
        Said before the first field rather than beside the radio button that
        needs it: "Niemand" now keeps the entry, and the only delete is an
        empty field. Both halves have to be read before anybody chooses.
      */}
      <p className="mb-5 text-base text-slate-700">{t('contact.keptHint')}</p>

      {mutation.isError && (
        <div className="mb-5">
          <ErrorNotice error={mutation.error} />
        </div>
      )}

      {KINDS.map((kind) => (
        <div key={kind} className="mb-6 rounded-xl border border-slate-300 bg-white p-4">
          {kind === 'address' ? (
            <fieldset className="mb-2">
              <legend className="mb-2 text-base font-medium text-slate-900">
                {t('contact.kind.address')}
              </legend>

              {PARTS.map((part) => (
                <Field
                  key={part}
                  label={t(`contact.address.${part}`)}
                  autoComplete={AUTOCOMPLETE[part]}
                  {...(part === 'postcode' ? { inputMode: 'numeric' as const } : {})}
                  value={address[part]}
                  onChange={(event) => setPart(part, event.target.value)}
                />
              ))}
            </fieldset>
          ) : (
            <Field
              label={t(`contact.kind.${kind}`)}
              type={kind === 'email' ? 'email' : 'text'}
              autoComplete="off"
              value={entries[kind]?.value ?? ''}
              hint={t(`contact.hint.${kind}`)}
              onChange={(event) => set(kind, { value: event.target.value })}
            />
          )}

          <fieldset>
            <legend className="mb-2 text-base font-medium text-slate-900">
              {t('contact.audienceLegend')}
            </legend>

            <div className="space-y-2">
              {audiencesFor(data?.connections_enabled).map((audience) => (
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

      <div className="flex flex-wrap gap-3">
        <Button type="submit" disabled={mutation.isPending}>
          {mutation.isPending ? t('contact.saving') : t('contact.save')}
        </Button>
        <Button type="button" variant="secondary" disabled={mutation.isPending} onClick={cancel}>
          {t('contact.cancel')}
        </Button>
      </div>
    </form>
  )
}

/**
 * The browser's own autofill, which is the whole practical argument for
 * fields over a box: a phone that has the member's address already can put it
 * in, and it can only do that if each field says what it is.
 */
const AUTOCOMPLETE: Record<keyof AddressParts, string> = {
  street: 'street-address',
  postcode: 'postal-code',
  city: 'address-level2',
  country: 'country-name',
}

/**
 * One entry as a sentence rather than as a control.
 *
 * An entry that is not filled in is listed too, and says so. "Not given" is an
 * answer a member came to this screen for as much as any other — and leaving
 * it out would make the list look complete when it is not.
 */
function Shared({
  kind,
  value,
  audience,
}: {
  kind: ContactKind
  value: string
  audience: ContactAudience
}) {
  const { t } = useTranslation()

  return (
    <div className="mb-4 last:mb-0">
      <dt className="text-sm font-medium uppercase tracking-wide text-slate-600">
        {t(`contact.kind.${kind}`)}
      </dt>
      <dd className="mt-1">
        {value === '' ? (
          <p className="text-base text-slate-600">{t('contact.empty')}</p>
        ) : (
          <>
            <p className="whitespace-pre-line text-base text-slate-900">{value}</p>
            <p className="mt-1 text-base text-slate-700">
              {audience === 'nobody'
                ? t('contact.keptNote')
                : t('contact.sharedWith', { audience: t(`contact.audience.${audience}`) })}
            </p>
          </>
        )}
      </dd>
    </div>
  )
}
