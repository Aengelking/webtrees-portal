import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  useAcceptConnection,
  useConnect,
  useMember,
  useOpenConversation,
  useRemoveConnection,
} from '../api/queries'
import type { ContactKind, MemberDetail as Member } from '../api/types'
import { IndividualView } from '../components/IndividualView'
import {
  Button,
  Card,
  ErrorNotice,
  Loading,
  Notice,
  PageHeading,
  Section,
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

          {memberId !== undefined && <ConnectionPanel member={data} id={memberId} />}

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
 * Where these two stand, and the one button that means anything about it.
 *
 * The state comes from the server rather than from anything this screen
 * knows, because a connection is a fact about two people and only one of them
 * is holding this telephone. Older servers do not send it at all — the module
 * and the portal deploy separately — and then this is simply not offered,
 * which is the same rule the rest of the portal follows for a field that may
 * not be there.
 */
function ConnectionPanel({ member, id }: { member: Member; id: number }) {
  const { t } = useTranslation()
  const connect = useConnect()
  const accept = useAcceptConnection()
  const remove = useRemoveConnection()

  const state = member.connection

  if (state === undefined || state.status === 'self' || member.connections_enabled === false) {
    return null
  }

  const busy = connect.isPending || accept.isPending || remove.isPending
  const error = connect.error ?? accept.error ?? remove.error

  return (
    <Section title={t('contacts.withMember')}>
      <Card>
        {error !== null && error !== undefined && (
          <div className="mb-4">
            <ErrorNotice error={error} />
          </div>
        )}

        {state.status === 'connected' && (
          <>
            <p className="text-base text-slate-800">{t('contacts.state.connected')}</p>
            <div className="mt-4">
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() => void remove.mutateAsync(state.id as number).catch(() => undefined)}
              >
                {t('contacts.disconnect')}
              </Button>
            </div>
          </>
        )}

        {state.status === 'requested' && (
          <>
            <p className="text-base text-slate-800">{t('contacts.state.requested')}</p>
            <div className="mt-4">
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() => void remove.mutateAsync(state.id as number).catch(() => undefined)}
              >
                {t('contacts.withdraw')}
              </Button>
            </div>
          </>
        )}

        {state.status === 'incoming' && (
          <>
            <p className="text-base text-slate-800">
              {t('contacts.state.incoming', { name: member.display_name })}
            </p>
            <div className="mt-4 flex flex-wrap gap-3">
              <Button
                disabled={busy}
                onClick={() => void accept.mutateAsync(state.id as number).catch(() => undefined)}
              >
                {t('contacts.accept')}
              </Button>
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() => void remove.mutateAsync(state.id as number).catch(() => undefined)}
              >
                {t('contacts.decline')}
              </Button>
            </div>
          </>
        )}

        {state.status === 'none' && (
          <>
            <p className="text-base text-slate-700">{t('contacts.state.none')}</p>
            <div className="mt-4">
              <Button
                disabled={busy}
                onClick={() => void connect.mutateAsync({ member_id: id }).catch(() => undefined)}
              >
                {busy ? t('contacts.asking') : t('contacts.askThis')}
              </Button>
            </div>
          </>
        )}
      </Card>
    </Section>
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
 * The way in to a conversation.
 *
 * This was a form with a subject and a body that sent one message and kept no
 * copy — which was the honest shape while webtrees' table was the only store,
 * because that is exactly what it held. Now there is a transcript, and writing
 * to somebody means opening it.
 *
 * The button is the step the directory rule guards, so it can fail with a 404
 * for a member who is neither listed nor connected. The sentence about the
 * reply address stays: webtrees' notification to the other side carries the
 * sender's own address, exactly as it did, and an unavoidable disclosure that
 * nobody mentions is worse than the disclosure.
 */
function MessageForm({ id, name }: { id: number; name: string }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const open = useOpenConversation()

  async function onOpen() {
    try {
      const result = await open.mutateAsync(id)

      void navigate(`/conversations/${result.conversation.id}`)
    } catch {
      // Rendered from the mutation below.
    }
  }

  return (
    <Section title={t('message.title', { name })}>
      {open.isError && (
        <div className="mb-5">
          <ErrorNotice error={open.error} />
        </div>
      )}

      <p className="mb-5 rounded-lg border border-slate-300 bg-slate-50 p-4 text-base text-slate-800">
        {t('message.replyAddressNotice')}
      </p>

      <Button disabled={open.isPending} onClick={() => void onOpen()}>
        {open.isPending ? t('message.opening') : t('message.open')}
      </Button>
    </Section>
  )
}
