import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useDeleteMessage, useMarkMessage, useMessages } from '../api/queries'
import type { Message } from '../api/types'
import { Button, Card, ErrorNotice, Loading, Notice, PageHeading } from '../components/ui'

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

      {isPending && <Loading />}

      {isError && (
        <div className="mt-6">
          <ErrorNotice error={error} onRetry={() => void refetch()} />
        </div>
      )}

      {data !== undefined && (
        <div className="mt-6">
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
  const { t } = useTranslation()

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
            {message.from} · {formatDate(message.sent_at)}
          </span>
        </span>
      </button>

      {open && (
        <>
          <p className="mt-4 whitespace-pre-line border-t border-slate-200 pt-4 text-base text-slate-900">
            {message.body}
          </p>

          <div className="mt-4 flex flex-wrap gap-3">
            <Button variant="secondary" onClick={onMarkUnread}>
              {t('messages.markUnread')}
            </Button>
            <Button variant="secondary" disabled={busy} onClick={onDelete}>
              {t('messages.delete')}
            </Button>
          </div>
        </>
      )}
    </Card>
  )
}

/**
 * One date, formatted by the browser. Everything genealogical is formatted by
 * webtrees, but a message timestamp is not genealogy and does not need a
 * round trip.
 */
function formatDate(iso: string): string {
  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString()
}
