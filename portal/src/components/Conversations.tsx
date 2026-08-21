import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useConversations } from '../api/queries'
import { formatTimestamp } from '../api/dates'
import { ErrorNotice, Loading } from './ui'

/**
 * The conversations, above the inbox.
 *
 * Two lists rather than one, because they are two different things and merging
 * them would make both worse. A conversation is an exchange with one person
 * that continues; the inbox below holds what arrives from elsewhere — webtrees'
 * contact form, an administrator's broadcast — and is read once.
 *
 * Nothing is rendered at all while there are none. A member who has never
 * written to anybody should not be shown an empty box explaining a feature; the
 * way in is on the other person's page, where the thought occurs.
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

  if (data.conversations.length === 0) {
    return null
  }

  return (
    <section className="mt-6">
      <h2 className="text-lg font-semibold text-slate-900">{t('conversation.listTitle')}</h2>

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
