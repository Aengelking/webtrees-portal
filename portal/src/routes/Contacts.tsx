import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  useAcceptConnection,
  useConnect,
  useConnections,
  useConnectionCode,
  useRemoveConnection,
  useRevokeConnectionCode,
} from '../api/queries'
import type { Connection } from '../api/types'
import { QrCode } from '../components/QrCode'
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

/**
 * The member's own address book, and the two ways of adding to it.
 *
 * The order on the screen is the order of the two questions a member arrives
 * with. "Somebody is standing in front of me" comes first, because that is
 * the case with a moment in it — a family gathering ends. "Somebody gave me
 * their number" comes second, because that one keeps.
 *
 * Requests waiting for an answer are above the list of people, for the same
 * reason unread messages are: a thing asked of you outranks a thing you own.
 */
export function Contacts() {
  const { t } = useTranslation()
  const { data, isPending, isError, error, refetch } = useConnections()

  return (
    <>
      <PageHeading>{t('contacts.title')}</PageHeading>

      {isPending && <Loading />}

      {isError && (
        <div className="mt-6">
          <ErrorNotice error={error} onRetry={() => void refetch()} />
        </div>
      )}

      {data !== undefined && (
        <>
          <p className="mt-3 text-base text-slate-700">{t('contacts.intro')}</p>

          {!data.enabled && (
            <div className="mt-6">
              <Notice title={t('contacts.off.title')} body={t('contacts.off.body')} />
            </div>
          )}

          {data.enabled && (
            <>
              <Section title={t('contacts.showCode')}>
                <MyCode minutes={data.code_valid_minutes} />
              </Section>

              <Section title={t('contacts.byReference')}>
                <ByReference />
              </Section>
            </>
          )}

          {data.enabled && data.incoming.length > 0 && (
            <Section title={t('contacts.incoming')}>
              <ul className="space-y-3">
                {data.incoming.map((connection) => (
                  <li key={connection.id}>
                    <IncomingCard connection={connection} />
                  </li>
                ))}
              </ul>
            </Section>
          )}

          {data.enabled && data.outgoing.length > 0 && (
            <Section title={t('contacts.outgoing')}>
              <ul className="space-y-3">
                {data.outgoing.map((connection) => (
                  <li key={connection.id}>
                    <Card>
                      <p className="text-lg font-semibold text-slate-900">{connection.name}</p>
                      <p className="mt-1 text-base text-slate-700">{t('contacts.waiting')}</p>
                      <EndButton connection={connection} label={t('contacts.withdraw')} />
                    </Card>
                  </li>
                ))}
              </ul>
            </Section>
          )}

          <Section title={t('contacts.list')}>
            {data.connections.length === 0 ? (
              <Notice title={t('contacts.none.title')} body={t('contacts.none.body')} />
            ) : (
              <ul className="space-y-3">
                {data.connections.map((connection) => (
                  <li key={connection.id}>
                    <ContactCard connection={connection} />
                  </li>
                ))}
              </ul>
            )}
          </Section>
        </>
      )}
    </>
  )
}

/**
 * The code to hold up.
 *
 * Nothing is issued until the member asks: a code that appeared whenever this
 * screen was opened would be a live credential on a screen nobody meant to
 * show. It says when it stops working, and it can be put away again — which
 * is the answer to "somebody photographed my telephone".
 */
function MyCode({ minutes }: { minutes: number }) {
  const { t } = useTranslation()
  const issue = useConnectionCode()
  const revoke = useRevokeConnectionCode()

  const code = revoke.isSuccess ? undefined : issue.data

  function show() {
    revoke.reset()
    issue.mutate()
  }

  return (
    <Card>
      <p className="text-base text-slate-700">{t('contacts.codeBody', { count: minutes })}</p>

      {issue.isError && (
        <div className="mt-4">
          <ErrorNotice error={issue.error} />
        </div>
      )}

      {code !== undefined && (
        <div className="mt-5 flex flex-col items-center">
          <QrCode value={code.url} label={t('contacts.codeAlt')} />
          <p className="mt-3 text-center text-base text-slate-700">
            {t('contacts.codeValid', { count: code.valid_minutes })}
          </p>
        </div>
      )}

      <div className="mt-5 flex flex-wrap gap-3">
        <Button onClick={show} disabled={issue.isPending}>
          {code === undefined ? t('contacts.codeShow') : t('contacts.codeRenew')}
        </Button>

        {code !== undefined && (
          <Button
            variant="secondary"
            disabled={revoke.isPending}
            onClick={() => {
              revoke.mutate()
              issue.reset()
            }}
          >
            {t('contacts.codeHide')}
          </Button>
        )}
      </div>

      {revoke.isSuccess && (
        <div className="mt-4">
          <SuccessNote>{t('contacts.codeHidden')}</SuccessNote>
        </div>
      )}
    </Card>
  )
}

/** The other way: the number printed under the name on somebody's record. */
function ByReference() {
  const { t } = useTranslation()
  const connect = useConnect()
  const [reference, setReference] = useState('')

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    try {
      await connect.mutateAsync({ reference: reference.trim() })
      setReference('')
    } catch {
      // Rendered from the mutation below.
    }
  }

  return (
    <Card>
      <form onSubmit={onSubmit} noValidate>
        <p className="mb-4 text-base text-slate-700">{t('contacts.referenceBody')}</p>

        {connect.isError && (
          <div className="mb-4">
            <ErrorNotice error={connect.error} />
          </div>
        )}

        {connect.isSuccess && (
          <div className="mb-4">
            <SuccessNote>
              {connect.data.status === 'connected'
                ? t('contacts.connected', { name: connect.data.name })
                : t('contacts.requested', { name: connect.data.name })}
            </SuccessNote>
          </div>
        )}

        <Field
          label={t('contacts.referenceLabel')}
          hint={t('contacts.referenceHint')}
          autoComplete="off"
          value={reference}
          onChange={(event) => setReference(event.target.value)}
        />

        <Button type="submit" disabled={connect.isPending || reference.trim() === ''}>
          {connect.isPending ? t('contacts.asking') : t('contacts.ask')}
        </Button>
      </form>
    </Card>
  )
}

function IncomingCard({ connection }: { connection: Connection }) {
  const { t } = useTranslation()
  const accept = useAcceptConnection()

  return (
    <Card>
      <p className="text-lg font-semibold text-slate-900">{connection.name}</p>
      <p className="mt-1 text-base text-slate-700">
        {connection.individual === null
          ? t('contacts.asksYou')
          : t('contacts.asksYouAs', { name: connection.individual.name })}
      </p>

      {accept.isError && (
        <div className="mt-4">
          <ErrorNotice error={accept.error} />
        </div>
      )}

      <div className="mt-4 flex flex-wrap gap-3">
        <Button
          disabled={accept.isPending}
          onClick={() => void accept.mutateAsync(connection.id).catch(() => undefined)}
        >
          {t('contacts.accept')}
        </Button>
        <EndButton connection={connection} label={t('contacts.decline')} inline />
      </div>
    </Card>
  )
}

function ContactCard({ connection }: { connection: Connection }) {
  const { t } = useTranslation()

  const lifespan = connection.individual?.lifespan ?? null

  return (
    <Card>
      {connection.member_id === null ? (
        <p className="text-lg font-semibold text-slate-900">{connection.name}</p>
      ) : (
        <Link
          to={`/members/${connection.member_id}`}
          className="inline-flex min-h-[44px] items-center text-lg font-semibold text-sky-800 underline underline-offset-4"
        >
          {connection.name}
        </Link>
      )}

      <p className="mt-1 text-base text-slate-700">
        {connection.individual === null
          ? t('contacts.noRecord')
          : [connection.individual.name, lifespan].filter((part) => part !== null && part !== '').join(' · ')}
      </p>

      <EndButton connection={connection} label={t('contacts.disconnect')} />
    </Card>
  )
}

/**
 * Ending a connection, refusing a request and withdrawing one are one button,
 * because they are one thing: this row should not exist any more.
 *
 * It asks once first. Not a browser dialogue — those are dismissed by reflex
 * on a telephone — but the button turning into the question, so the answer is
 * given in the same place the tap was.
 */
function EndButton({
  connection,
  label,
  inline = false,
}: {
  connection: Connection
  label: string
  inline?: boolean
}) {
  const { t } = useTranslation()
  const remove = useRemoveConnection()
  const [asking, setAsking] = useState(false)

  const wrapper = inline ? '' : 'mt-4'

  if (!asking) {
    return (
      <div className={wrapper}>
        <Button variant="secondary" onClick={() => setAsking(true)}>
          {label}
        </Button>
      </div>
    )
  }

  return (
    <div className={wrapper}>
      {remove.isError && (
        <div className="mb-3">
          <ErrorNotice error={remove.error} />
        </div>
      )}

      <p className="mb-3 text-base text-slate-800">{t('contacts.sure', { name: connection.name })}</p>

      <div className="flex flex-wrap gap-3">
        <Button
          disabled={remove.isPending}
          onClick={() => void remove.mutateAsync(connection.id).catch(() => undefined)}
        >
          {t('contacts.sureYes')}
        </Button>
        <Button variant="secondary" onClick={() => setAsking(false)}>
          {t('contacts.sureNo')}
        </Button>
      </div>
    </div>
  )
}
