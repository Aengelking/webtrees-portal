import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useConversations } from '../api/queries'
import { formatTimestamp } from '../api/dates'
import { ErrorNotice, Loading, Notice } from './ui'

/**
 * The conversations, above the inbox.
 *
 * Two lists rather than one, because they are two different things and merging
 * them would make both worse. A conversation is an exchange with one person
 * that continues; the inbox below holds what arrives from elsewhere — webtrees'
 * contact form, an administrator's broadcast — and is read once.
 *
 * **The way in is here**, next to the heading, and it is a button rather than
 * a sentence about where to look. The first version rendered nothing at all
 * while a member had none, which was reported within a day — "I only see
 * Sonstige Nachrichten" — and was fixed with an empty state explaining that a
 * conversation starts on the other person's page. That was still one screen
 * short: knowing where the way in is, is not the same as being able to take
 * it. So the heading carries the button, at all times and not only while the
 * list is empty, because "write to somebody" is not a thing a member does once.
 */
export function Conversations() {
  const { t, i18n } = useTranslation()
  const { data, isPending, isError, error, refetch } = useConversations()

  if (isPending) {
    return <Loading />
  }

  if (isError) {
    return (
      <div className="mt-6">
        <ErrorNotice error={error} onRetry={() => void refetch()} />
      </div>
    )
  }

  return (
    <section className="mt-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-slate-900">{t('conversation.listTitle')}</h2>

        <Link
          to="/conversations/new"
          className="inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white hover:bg-sky-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
        >
          {t('conversation.start')}
        </Link>
      </div>

      {data.conversations.length === 0 && (
        <div className="mt-3">
          <Notice
            title={t('conversation.noneTitle')}
            body={t('conversation.noneBody')}
            action={
              <Link
                to="/conversations/new"
                className="inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
              >
                {t('conversation.noneAction')}
              </Link>
            }
          />
        </div>
      )}

      <ul className="mt-3 space-y-3">
        {data.conversations.map((conversation) => (
          <li key={conversation.id}>
            <Link
              to={`/conversations/${conversation.id}`}
              className="flex min-h-[72px] items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white p-4 hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
            >
              <span className="min-w-0">
                <span className="block text-base font-semibold text-slate-900">
                  {conversation.name}
                  {conversation.unread > 0 && (
                    <span className="sr-only"> — {t('conversation.unread', { count: conversation.unread })}</span>
                  )}
                </span>

                {conversation.last_message !== null && (
                  <>
                    {/*
                      One line of it, cut by CSS rather than by JavaScript: a
                      preview that ends mid-word is a preview, and truncating
                      in code would put the ellipsis in the accessible name too.
                    */}
                    <span className="mt-1 block truncate text-base text-slate-700">
                      {conversation.last_message.mine && <>{t('conversation.you')}: </>}
                      {conversation.last_message.body}
                    </span>
                    <span className="mt-1 block text-sm text-slate-600">
                      {formatTimestamp(conversation.last_message.sent_at, i18n.language)}
                    </span>
                  </>
                )}
              </span>

              {conversation.unread > 0 && (
                <span
                  aria-hidden="true"
                  className="min-w-[24px] shrink-0 rounded-full bg-sky-800 px-2 py-1 text-center text-sm font-semibold text-white"
                >
                  {conversation.unread > 9 ? '9+' : conversation.unread}
                </span>
              )}
            </Link>
          </li>
        ))}
      </ul>
    </section>
  )
}
