import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMember, useSendMessage } from '../api/queries'
import type { ContactKind, MemberDetail as Member } from '../api/types'
import { IndividualView } from '../components/IndividualView'
import {
  Button,
  Card,
  ErrorNotice,
  Field,
  Loading,
  Notice,
  PageHeading,
  Section,
  SuccessNote,
} from '../components/ui'

const KINDS: ContactKind[] = ['email', 'phone', 'address']

export function MemberDetail() {
  const { t } = useTranslation()
  const { id } = useParams<{ id: string }>()
  const numericId = Number(id)
  const memberId = Number.isFinite(numericId) && numericId > 0 ? numericId : undefined

  const { data, isPending, isError, error, refetch } = useMember(memberId)

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

          <ContactDetails member={data} />

          {data.can_message === true && memberId !== undefined && (
            <MessageForm id={memberId} name={data.display_name} />
          )}
        </>
      )}
    </>
  )
}

/**
 * Whatever this member chose to share with *this* reader.
 *
 * The server has already decided; there is nothing here about the entries
 * that did not reach us, not even that they exist. Absent means absent.
 */
function ContactDetails({ member }: { member: Member }) {
  const { t } = useTranslation()

  // Both fields arrived in Phase 9. A response from an older module — or one
  // that lost them on the way — must leave the page working rather than
  // white-screening on `undefined[kind]`. The same rule the tree screens
  // already follow for a missing `relationship`.
  const contact = member.contact ?? {}
  const shared = KINDS.filter((kind) => (contact[kind] ?? '') !== '')

  if (shared.length === 0) {
    return null
  }

  return (
    <Section title={t('contact.sharedTitle')}>
      <Card>
        <dl>
          {shared.map((kind) => (
            <div key={kind} className="mb-3 last:mb-0">
              <dt className="text-sm font-medium uppercase tracking-wide text-slate-600">
                {t(`contact.kind.${kind}`)}
              </dt>
              <dd className="mt-1 text-base text-slate-900">
                <ContactValue kind={kind} value={contact[kind] as string} />
              </dd>
            </div>
          ))}
        </dl>
      </Card>
    </Section>
  )
}

/** An address is text; an email address and a telephone number are actions. */
function ContactValue({ kind, value }: { kind: ContactKind; value: string }) {
  if (kind === 'email') {
    return (
      <a className="text-sky-800 underline underline-offset-4" href={`mailto:${value}`}>
        {value}
      </a>
    )
  }

  if (kind === 'phone') {
    return (
      <a className="text-sky-800 underline underline-offset-4" href={`tel:${value.replace(/\s+/g, '')}`}>
        {value}
      </a>
    )
  }

  return <span className="whitespace-pre-line">{value}</span>
}

/**
 * Writing to another member.
 *
 * The sentence above the button is the important part of this component.
 * webtrees delivers the message with the sender's own address as the reply
 * address — there is no way to make a reply possible without it — so the
 * member is told before they send, not after. An unavoidable disclosure that
 * nobody mentions is worse than the disclosure.
 */
function MessageForm({ id, name }: { id: number; name: string }) {
  const { t } = useTranslation()
  const mutation = useSendMessage(id)

  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [sent, setSent] = useState(false)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSent(false)

    try {
      await mutation.mutateAsync({ subject: subject.trim(), body: body.trim() })

      setSent(true)
      setSubject('')
      setBody('')
    } catch {
      // Rendered from the mutation below.
    }
  }

  return (
    <Section title={t('message.title', { name })}>
      <form onSubmit={onSubmit} noValidate>
        {mutation.isError && (
          <div className="mb-5">
            <ErrorNotice error={mutation.error} />
          </div>
        )}

        {sent && <SuccessNote>{t('message.sent')}</SuccessNote>}

        <Field
          label={t('message.subject')}
          required
          value={subject}
          onChange={(event) => setSubject(event.target.value)}
        />

        <div className="mb-5">
          <label htmlFor="message-body" className="mb-2 block text-base font-medium text-slate-900">
            {t('message.body')}
          </label>
          <textarea
            id="message-body"
            rows={6}
            required
            value={body}
            onChange={(event) => setBody(event.target.value)}
            className="w-full rounded-lg border border-slate-400 bg-white px-4 py-3 text-base text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-sky-700"
          />
        </div>

        <p className="mb-5 rounded-lg border border-slate-300 bg-slate-50 p-4 text-base text-slate-800">
          {t('message.replyAddressNotice')}
        </p>

        <Button
          type="submit"
          disabled={mutation.isPending || subject.trim() === '' || body.trim() === ''}
        >
          {mutation.isPending ? t('message.sending') : t('message.send')}
        </Button>
      </form>
    </Section>
  )
}
