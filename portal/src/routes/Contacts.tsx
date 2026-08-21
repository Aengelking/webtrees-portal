import { useId, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
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

          {/*
            The directory was a tab of its own until this screen took its
            place, so its search comes with it — and comes first. It is the
            most ordinary thing a member does here (look somebody up, read
            their page, write to them), and burying the only way to it under a
            QR code would make the commonest errand the longest one.

            The field navigates rather than searching in place: results are
            paged and each one leads somewhere, which is a screen's worth of
            work. Empty means everybody, so this doubles as the plain way in.
          */}
          <Section title={t('contacts.find')}>
            <FindMember />
          </Section>

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
 * The way into the member directory.
 *
 * A form rather than a link, because a member usually arrives with a name in
 * mind, and typing it here saves the round trip through a list of everybody.
 */
function FindMember() {
  const { t } = useTranslation()
  const navigate = useNavigate()

  const [name, setName] = useState('')

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const trimmed = name.trim()

    void navigate(trimmed === '' ? '/members' : `/members?q=${encodeURIComponent(trimmed)}`)
  }

  return (
    <Card>
      <form onSubmit={onSubmit} noValidate>
        <p className="mb-4 text-base text-slate-700">{t('contacts.findBody')}</p>

        <Field
          label={t('contacts.findLabel')}
          type="search"
          inputMode="search"
          autoComplete="off"
          value={name}
          onChange={(event) => setName(event.target.value)}
        />

        <Button type="submit">{t('contacts.findAction')}</Button>
      </form>
    </Card>
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

/**
 * The family's branches, which are what stands before the slash in an SB
 * number. Thirty-four of them, so a wheel rather than a row of buttons.
 *
 * A number is picked here and not typed, because "/" on a telephone keyboard
 * is two taps into a second layout — for a punctuation mark whose only job is
 * to separate two numbers the form already keeps apart.
 *
 * There is no "no branch" among them. Every number in this family has one;
 * the dash at the top of the wheel means "not chosen yet" and cannot be sent.
 */
const BRANCHES = Array.from({ length: 34 }, (_, index) => String(index + 1))

/**
 * Compose what the member picked and typed into the number as it is written.
 *
 * A number already carrying a slash is passed through untouched: somebody who
 * typed the whole thing into the second field meant it, and prefixing a
 * branch onto it would produce something nobody has. That is also the escape
 * hatch if the family ever grows a thirty-fifth branch — see `BRANCHES`.
 */
export function composeReference(branch: string, number: string): string {
  const typed = number.trim()

  if (branch === '' || typed.includes('/')) {
    return typed
  }

  return `${branch}/${typed}`
}

/**
 * Whether the two fields amount to a number worth sending.
 *
 * **Every number in this family has a branch.** So a number with neither a
 * chosen branch nor a slash of its own is not a number anybody carries, and
 * the button stays out of reach rather than sending it and reporting that
 * nobody was found — which would be true, and useless.
 */
export function isCompleteReference(branch: string, number: string): boolean {
  const typed = number.trim()

  if (typed === '') {
    return false
  }

  return branch !== '' || typed.includes('/')
}

/**
 * The number, laid out the way it is written: a branch, a slash, the rest.
 *
 * One control per part, with the slash between them as a printed character
 * rather than a keystroke — that is the whole point of the arrangement, and
 * it means the form on screen reads as the number the member is holding.
 *
 * The slash is `aria-hidden` and each control names itself instead. A screen
 * reader announcing "Zweig, 10" and "Nummer, 1335.21" says more than one
 * announcing a punctuation mark between two unlabelled boxes.
 */
function ReferenceInput({
  branch,
  number,
  onBranch,
  onNumber,
}: {
  branch: string
  number: string
  onBranch: (value: string) => void
  onNumber: (value: string) => void
}) {
  const { t } = useTranslation()
  const id = useId()

  return (
    <div className="mb-5">
      <p id={id} className="mb-2 block text-base font-medium text-slate-900">
        {t('contacts.referenceGroup')}
      </p>

      <div role="group" aria-labelledby={id} className="flex items-center gap-2">
        <select
          aria-label={t('contacts.branchLabel')}
          value={branch}
          onChange={(event) => onBranch(event.target.value)}
          className="min-h-[48px] rounded-lg border border-slate-400 bg-white px-3 py-3 text-base text-slate-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-sky-700"
        >
          <option value="">{t('contacts.branchPlaceholder')}</option>
          {BRANCHES.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>

        <span aria-hidden="true" className="text-xl font-semibold text-slate-500">
          /
        </span>

        <input
          aria-label={t('contacts.referenceLabel')}
          // Digits and a separator, which is the whole of a number after the
          // branch. A comma instead of a full stop is no trouble:
          // punctuation is not what the server compares.
          inputMode="decimal"
          autoComplete="off"
          placeholder={t('contacts.referencePlaceholder')}
          value={number}
          onChange={(event) => onNumber(event.target.value)}
          className="min-h-[48px] w-full flex-1 rounded-lg border border-slate-400 bg-white px-4 py-3 text-base text-slate-900 placeholder:text-slate-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-sky-700"
        />
      </div>

      <p className="mt-2 text-base text-slate-700">{t('contacts.referenceHint')}</p>
    </div>
  )
}

/** The other way: the number printed under the name on somebody's record. */
function ByReference() {
  const { t } = useTranslation()
  const connect = useConnect()
  const [branch, setBranch] = useState('')
  const [number, setNumber] = useState('')

  const reference = composeReference(branch, number)

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    try {
      await connect.mutateAsync({ reference })
      setNumber('')
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
              {/*
                No name means the server is not saying whether that number
                belongs to anybody — which is how a member who stayed out of
                the directory can be asked at all. See the module's
                `Connections::requestByReference()`.
              */}
              {connect.data.name === null
                ? t('contacts.requestedQuietly')
                : connect.data.status === 'connected'
                  ? t('contacts.connected', { name: connect.data.name })
                  : t('contacts.requested', { name: connect.data.name })}
            </SuccessNote>
          </div>
        )}

        <ReferenceInput
          branch={branch}
          number={number}
          onBranch={setBranch}
          onNumber={setNumber}
        />

        <Button type="submit" disabled={connect.isPending || !isCompleteReference(branch, number)}>
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
