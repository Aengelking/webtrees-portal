import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  useConversation,
  useDeleteConversationMessage,
  useSendConversationMessage,
} from '../api/queries'
import type { ConversationMessage } from '../api/types'
import { formatTimestamp } from '../api/dates'
import { Button, ErrorNotice, Loading, PageHeading } from '../components/ui'

/**
 * One conversation, read as a conversation.
 *
 * The inbox next door is a list of closed cards, which is right for post that
 * arrives from anywhere and is read once. This is the other thing: two people
 * going back and forth, where what was said before is the point. So the whole
 * exchange is on one screen, oldest at the top, and the newest is what you
 * land on.
 *
 * Sent and received differ by side and by colour, and each carries its time.
 * There is no read receipt on the other person's messages — only on one's own,
 * where it answers the question somebody actually asks: did that arrive.
 */
export function Conversation() {
  const { t } = useTranslation()
  const params = useParams()
  const id = Number(params.id ?? 0)

  const { data, isPending, isError, error, refetch } = useConversation(id)
  const send = useSendConversationMessage(id)
  const remove = useDeleteConversationMessage(id)

  const [text, setText] = useState('')
  const foot = useRef<HTMLDivElement>(null)

  // Land at the newest message, as every messaging app does. Called through
  // an optional call because jsdom has no `scrollIntoView` — a test that
  // exercises this screen should not die on a scroll it cannot perform.
  useEffect(() => {
    foot.current?.scrollIntoView?.({ block: 'end' })
  }, [data?.messages.length])

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const body = text.trim()

    if (body === '') {
      return
    }

    // Cleared first: the member has said it, and leaving their words in the
    // box while it sends invites them to send it twice.
    setText('')

    try {
      await send.mutateAsync(body)
    } catch {
      // Put it back rather than lose it. What they typed is theirs.
      setText(body)
    }
  }

  return (
    <>
      <Link
        to="/messages"
        className="inline-flex min-h-[44px] items-center text-base font-semibold text-sky-800 underline"
      >
        {t('conversation.back')}
      </Link>

      {isPending && <Loading />}

      {isError && (
        <div className="mt-6">
          <ErrorNotice error={error} onRetry={() => void refetch()} />
        </div>
      )}

      {data !== undefined && (
        <>
          <PageHeading>{data.conversation.name}</PageHeading>

          {data.conversation.member_id !== null && (
            <Link
              to={`/members/${data.conversation.member_id}`}
              className="mt-1 inline-flex min-h-[44px] items-center text-base text-sky-800 underline"
            >
              {t('conversation.profile')}
            </Link>
          )}

          <ul className="mt-4 space-y-3">
            {data.messages.length === 0 && (
              <li className="rounded-xl border border-slate-300 bg-slate-50 p-5 text-center text-base text-slate-700">
                {t('conversation.empty')}
              </li>
            )}

            {data.messages.map((message) => (
              <li key={message.id}>
                <Bubble
                  message={message}
                  busy={remove.isPending}
                  onDelete={() => void remove.mutateAsync(message.id).catch(() => undefined)}
                />
              </li>
            ))}
          </ul>

          <div ref={foot} />

          {send.isError && (
            <div className="mt-4">
              <ErrorNotice error={send.error} />
            </div>
          )}

          <form onSubmit={(event) => void onSubmit(event)} className="mt-6">
            <label htmlFor="conversation-body" className="block text-base font-medium text-slate-900">
              {t('conversation.write')}
            </label>
            <textarea
              id="conversation-body"
              value={text}
              onChange={(event) => setText(event.target.value)}
              rows={3}
              maxLength={4000}
              className="mt-2 w-full rounded-lg border border-slate-400 bg-white p-3 text-base text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
            />
            <div className="mt-3">
              <Button type="submit" disabled={send.isPending || text.trim() === ''}>
                {send.isPending ? t('conversation.sending') : t('conversation.send')}
              </Button>
            </div>

            {/*
              Said here because this is where writing happens. A member who
              started this conversation from the messages screen never passes
              the other person's page, so a sentence that lives only there is a
              sentence half the family never reads.
            */}
            <p className="mt-3 text-sm text-slate-600">{t('conversation.notifyNotice')}</p>
          </form>
        </>
      )}
    </>
  )
}

/**
 * One message.
 *
 * Deleting is on one's own and on the other's alike, because "delete" here
 * means "take it off my screen" and a member may want that for either. The
 * sentence under the button says as much — the other side keeps their copy,
 * and a portal that implied otherwise would be promising something it cannot
 * do.
 */
function Bubble({
  message,
  busy,
  onDelete,
}: {
  message: ConversationMessage
  busy: boolean
  onDelete: () => void
}) {
  const { t, i18n } = useTranslation()
  const [confirming, setConfirming] = useState(false)

  return (
    <div className={message.mine ? 'flex justify-end' : 'flex justify-start'}>
      <div
        className={[
          'max-w-[85%] rounded-xl border p-4',
          message.mine ? 'border-sky-800 bg-sky-50' : 'border-slate-300 bg-white',
        ].join(' ')}
      >
        {/* whitespace-pre-line: people write family news in paragraphs. */}
        <p className="whitespace-pre-line text-base text-slate-900">{message.body}</p>

        <p className="mt-2 text-sm text-slate-600">
          {formatTimestamp(message.sent_at, i18n.language)}
          {message.mine && message.read && <> · {t('conversation.read')}</>}
        </p>

        {confirming ? (
          <div className="mt-3">
            <p className="text-sm text-slate-700">{t('conversation.deleteExplain')}</p>
            <div className="mt-2 flex gap-2">
              <Button variant="secondary" disabled={busy} onClick={onDelete}>
                {t('conversation.deleteConfirm')}
              </Button>
              <Button variant="secondary" onClick={() => setConfirming(false)}>
                {t('common.cancel')}
              </Button>
            </div>
          </div>
        ) : (
          <button
            type="button"
            onClick={() => setConfirming(true)}
            className="mt-2 min-h-[44px] text-sm font-medium text-slate-700 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
          >
            {t('conversation.delete')}
          </button>
        )}
      </div>
    </div>
  )
}
