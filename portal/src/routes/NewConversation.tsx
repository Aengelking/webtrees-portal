import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useConnections, useOpenConversation } from '../api/queries'
import type { Connection } from '../api/types'
import { Button, ErrorNotice, Field, Loading, Notice, PageHeading } from '../components/ui'

/**
 * Starting a conversation, from the screen where the conversations are.
 *
 * Until now the only way in was the other person's page — a member standing on
 * *Nachrichten* wanting to write to their sister had to think of the directory
 * first, and nobody does. §2.33 already recorded half of this lesson (the empty
 * list has to say where to start); this is the other half. **Saying where the
 * way in is, is not the same as being the way in.**
 *
 * Contacts, because those are the people a member has already agreed to be in
 * touch with, and because that is what was asked for. It is not the whole set
 * of people who may be written to — anybody listed in the directory may be —
 * so the directory is offered at the bottom rather than hidden: this screen
 * covers the common case without pretending to be the only case.
 *
 * Opening is idempotent on the server, so a contact who is already in the list
 * of conversations is not filtered out. Tapping them lands in the conversation
 * that exists, which is what tapping a name should do.
 */
export function NewConversation() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { data, isPending, isError, error, refetch } = useConnections()
  const open = useOpenConversation()

  const [filter, setFilter] = useState('')

  // A contact with no member id has no profile row yet — the moment between a
  // request being made and answered. There is nothing to open, so there is
  // nothing to offer.
  const contacts = (data?.connections ?? []).filter(
    (connection): connection is Connection & { member_id: number } => connection.member_id !== null,
  )

  const needle = filter.trim().toLocaleLowerCase()
  const shown =
    needle === ''
      ? contacts
      : contacts.filter((contact) => contact.name.toLocaleLowerCase().includes(needle))

  async function start(memberId: number) {
    try {
      const result = await open.mutateAsync(memberId)

      // Replaced rather than pushed: this screen was a step on the way, and
      // Back from the conversation should be the messages screen the member
      // started on, not the list they have just finished with.
      void navigate(`/conversations/${result.conversation.id}`, { replace: true })
    } catch {
      // Rendered from the mutation below.
    }
  }

  return (
    <>
      <PageHeading>{t('newConversation.title')}</PageHeading>

      <p className="mt-3 text-base text-slate-700">
        <Link
          to="/messages"
          className="text-sky-800 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
        >
          {t('conversation.back')}
        </Link>
      </p>

      {isPending && <Loading />}

      {isError && (
        <div className="mt-6">
          <ErrorNotice error={error} onRetry={() => void refetch()} />
        </div>
      )}

      {open.isError && (
        <div className="mt-6">
          <ErrorNotice error={open.error} />
        </div>
      )}

      {data !== undefined && (
        <div className="mt-6">
          {contacts.length === 0 ? (
            <Notice
              title={t('newConversation.noneTitle')}
              body={t('newConversation.noneBody')}
              action={
                <Link
                  to="/contacts"
                  className="inline-flex min-h-[44px] items-center rounded-lg bg-sky-800 px-5 py-3 text-base font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
                >
                  {t('newConversation.noneAction')}
                </Link>
              }
            />
          ) : (
            <>
              {/*
                Only once the list is long enough to be worth searching. A
                search box above four names is furniture.
              */}
              {contacts.length > 8 && (
                <Field
                  label={t('newConversation.filter')}
                  type="search"
                  value={filter}
                  autoComplete="off"
                  onChange={(event) => setFilter(event.target.value)}
                />
              )}

              {shown.length === 0 ? (
                <p className="text-base text-slate-700">{t('newConversation.noMatch')}</p>
              ) : (
                <ul className="space-y-3">
                  {shown.map((contact) => (
                    <li key={contact.id}>
                      <Button
                        variant="secondary"
                        className="w-full justify-start text-left"
                        disabled={open.isPending}
                        onClick={() => void start(contact.member_id)}
                      >
                        {contact.name}
                      </Button>
                    </li>
                  ))}
                </ul>
              )}

              {/*
                Contacts are not the whole set of people who may be written to.
                A member looking for somebody they have not connected with is
                one tap from the place that has them, instead of a dead end.
              */}
              <p className="mt-6 text-base text-slate-700">
                {t('newConversation.elsewhere')}{' '}
                <Link
                  to="/members"
                  className="text-sky-800 underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
                >
                  {t('newConversation.elsewhereAction')}
                </Link>
              </p>
            </>
          )}
        </div>
      )}
    </>
  )
}
