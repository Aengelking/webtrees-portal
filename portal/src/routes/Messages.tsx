import { useState } from 'react'
import type { FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useDeleteMessage, useMarkMessage, useMessages, useReplyToMessage } from '../api/queries'
import type { Message } from '../api/types'
import { formatTimestamp } from '../api/dates'
import { Conversations } from '../components/Conversations'
import { Button, Card, ErrorNotice, Loading, Notice, PageHeading, SuccessNote } from '../components/ui'

/**
 * The member's own messages.
 *
 * A list of closed cards that open one at a time, rather than a list and a
 * separate reading screen. On a phone the second screen costs a navigation
 * each way for two sentences from an aunt, and family messages are short.
 *
 * Opening one marks it read — which is what opening a message means, and it
 * saves the member a second deliberate act to make the badge go away.
 */
export function Messages() {
  const { t } = useTranslation()
  const { data, isPending, isError, error, refetch } = useMessages()
  const mark = useMarkMessage()
  const remove = useDeleteMessage()

  const [open, setOpen] = useState<number | null>(null)

  function toggle(message: Message) {
    const next = open === message.id ? null : message.id

    setOpen(next)

    if (next !== null && !message.read) {
      void mark.mutateAsync({ id: message.id, read: true }).catch(() => undefined)
    }
  }

  return (
    <>
      <PageHeading>{t('messages.title')}</PageHeading>

      {/* Renders nothing until there is a conversation to show. */}
      <Conversations />

      {isPending && <Loading />}

      {isError && (
        <div className="mt-6">
          <ErrorNotice error={error} onRetry={() => void refetch()} />
        </div>
      )}

      {data !== undefined && (
        <div className="mt-6">
          <h2 className="text-lg font-semibold text-slate-900">{t('messages.inboxTitle')}</h2>

          {data.messages.length === 0 ? (
            <Notice title={t('messages.none.title')} body={t('messages.none.body')} />
          ) : (
            <ul className="space-y-3">
              {data.messages.map((message) => (
                <li key={message.id}>
                  <MessageCard
                    message={message}
                    open={open === message.id}
                    busy={remove.isPending}
                    onToggle={() => toggle(message)}
                    onMarkUnread={() =>
                      void mark.mutateAsync({ id: message.id, read: false }).catch(() => undefined)
                    }
                    onDelete={() => void remove.mutateAsync(message.id).catch(() => undefined)}
                  />
                </li>
              ))}
            </ul>
          )}

          {/*
            Said plainly, because it is the question a member will have: the
            portal is not a second mailbox that quietly keeps a copy — this is
            webtrees' own, and deleting here deletes there.
          */}
          <p className="mt-6 text-base text-slate-700">{t('messages.note')}</p>
        </div>
      )}
    </>
  )
}

function MessageCard({
  message,
  open,
  busy,
  onToggle,
  onMarkUnread,
  onDelete,
}: {
  message: Message
  open: boolean
  busy: boolean
  onToggle: () => void
  onMarkUnread: () => void
  onDelete: () => void
}) {
  const { t, i18n } = useTranslation()
  const [replying, setReplying] = useState(false)

  return (
    <Card>
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={open}
        className="flex min-h-[44px] w-full items-start gap-3 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
      >
        {/*
          The dot is not the only signal — the subject is bold when unread as
          well, because a coloured dot alone is invisible to anyone who cannot
          see it.
        */}
        <span
          aria-hidden="true"
          className={`mt-2 h-3 w-3 shrink-0 rounded-full ${message.read ? 'bg-transparent' : 'bg-sky-700'}`}
        />
        <span className="min-w-0 flex-1">
          <span className={`block text-base ${message.read ? 'text-slate-900' : 'font-semibold text-slate-900'}`}>
            {message.subject}
            {!message.read && <span className="sr-only"> — {t('messages.unread')}</span>}
          </span>
          <span className="mt-1 block text-base text-slate-700">
            {message.from} · {formatTimestamp(message.sent_at, i18n.language)}
          </span>
        </span>
      </button>

      {open && (
        <>
          <p className="mt-4 whitespace-pre-line border-t border-slate-200 pt-4 text-base text-slate-900">
            {message.body}
          </p>

          <div className="mt-4 flex flex-wrap gap-3">
            {message.can_reply && (
              <Button variant="secondary" onClick={() => setReplying((current) => !current)}>
                {t('messages.reply')}
              </Button>
            )}
            <Button variant="secondary" onClick={onMarkUnread}>
              {t('messages.markUnread')}
            </Button>
            <Button variant="secondary" disabled={busy} onClick={onDelete}>
              {t('messages.delete')}
            </Button>
          </div>

          {/*
            Said where it applies rather than only on the "write to a member"
            form: an answer discloses the answerer's address exactly as a new
            message does, and somebody replying from their inbox never passed
            that form.
          */}
          {!message.can_reply && (
            <p className="mt-4 text-base text-slate-700">{t('messages.replyImpossible')}</p>
          )}

          {message.can_reply && replying && (
            <ReplyForm id={message.id} onSent={() => setReplying(false)} />
          )}
        </>
      )}
    </Card>
  )
}

/**
 * An answer.
 *
 * No subject field: the server puts webtrees' `RE: ` on the original. One
 * less thing to fill in on a phone, and no way to write an answer that
 * arrives looking like a new conversation.
 */
function ReplyForm({ id, onSent }: { id: number; onSent: () => void }) {
  const { t } = useTranslation()
  const mutation = useReplyToMessage(id)

  const [body, setBody] = useState('')
  const [sent, setSent] = useState(false)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSent(false)

    try {
      await mutation.mutateAsync(body.trim())

      setSent(true)
      setBody('')
    } catch {
      // Rendered from the mutation below.
    }
  }

  return (
    <form onSubmit={onSubmit} noValidate className="mt-4 border-t border-slate-200 pt-4">
      {mutation.isError && (
        <div className="mb-4">
          <ErrorNotice error={mutation.error} />
        </div>
      )}

      {/*
        The reply stays on screen after sending, with the answer said plainly:
        webtrees keeps no copy of what one sends, so there is no sent folder
        to point at and pretending otherwise would be a small lie.
      */}
      {sent && <SuccessNote>{t('messages.replySent')}</SuccessNote>}

      <label htmlFor={`reply-${id}`} className="mb-2 block text-base font-medium text-slate-900">
        {t('messages.replyLabel')}
      </label>
      <textarea
        id={`reply-${id}`}
        rows={5}
        required
        value={body}
        onChange={(event) => setBody(event.target.value)}
        className="w-full rounded-lg border border-slate-400 bg-white px-4 py-3 text-base text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-sky-700"
      />

      <p className="mt-4 rounded-lg border border-slate-300 bg-slate-50 p-4 text-base text-slate-800">
        {t('messages.replyAddressNotice')}
      </p>

      <div className="mt-4 flex flex-wrap gap-3">
        <Button type="submit" disabled={mutation.isPending || body.trim() === ''}>
          {mutation.isPending ? t('messages.replySending') : t('messages.replySend')}
        </Button>
        <Button variant="secondary" type="button" onClick={onSent}>
          {t('messages.replyCancel')}
        </Button>
      </div>
    </form>
  )
}

