import { useId, useRef, useState } from 'react'
import type { FormEvent, KeyboardEvent, ReactNode } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  useAcceptConnection,
  useConnect,
  useConnections,
  useConnectionCode,
  useConnectionLink,
  useRemoveConnection,
  useRevokeConnectionCode,
  useRevokeConnectionLink,
} from '../api/queries'
import type { Connection, SentLink } from '../api/types'
import { QrCode } from '../components/QrCode'
import { referenceLabel } from '../components/reference'
import { ShareLink } from '../components/ShareLink'
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
 * The member's own address book, and the ways of adding to it — in two tabs.
 *
 * It was one column, in the order of the two questions a member arrives with:
 * the ways of connecting first, the people second. That order is right for
 * somebody standing at a family gathering and wrong for everybody else, every
 * other day. The address book is the thing a member comes back to, and it had
 * four cards of machinery stacked on top of it — a search, a QR code, a link,
 * a number — so reading it began with scrolling past all of them.
 *
 * So the two questions are two tabs, and neither is above the other. Which one
 * opens is in the address bar, which means the browser's Back button, a
 * refresh and a link all keep it. A member with nothing in their address book
 * yet is put on the tab that fills it — the empty half is not what they came
 * for.
 *
 * Requests waiting for an answer stay at the top of the contacts tab, which is
 * also the tab that opens: a thing asked of you outranks a thing you own, and
 * the navigation already carries the count for the times it does not.
 */
export function Contacts() {
  const { t } = useTranslation()
  const { data, isPending, isError, error, refetch } = useConnections()
  const [params, setParams] = useSearchParams()

  const nothingYet =
    data !== undefined &&
    data.connections.length === 0 &&
    data.incoming.length === 0 &&
    data.outgoing.length === 0

  const chosen = params.get(TAB_PARAM)
  const active: Tab = chosen === 'new' || chosen === 'mine' ? chosen : nothingYet ? 'new' : 'mine'

  function select(tab: Tab): void {
    const next = new URLSearchParams(params)

    next.set(TAB_PARAM, tab)

    // Replaced rather than pushed: a tab is where you are on this screen, not
    // a screen you went to, and a member who tried both should get back to
    // wherever they came from in one tap of Back.
    setParams(next, { replace: true })
  }

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

          <TabBar
            active={active}
            onSelect={select}
            labels={{ mine: t('contacts.tabMine'), new: t('contacts.tabNew') }}
          />

          <Panel tab="mine" active={active}>
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
          </Panel>

          <Panel tab="new" active={active}>
            {/*
              The directory was a tab of its own until this screen took its
              place, so its search comes with it — and comes first here. It is
              the most ordinary thing a member does with this half of the
              screen (look somebody up, read their page, write to them), and
              burying the only way to it under a QR code would make the
              commonest errand the longest one.

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

                <Section title={t('contacts.sendLink')}>
                  <SendLink days={data.link_valid_days ?? 7} links={data.links ?? []} />
                </Section>

                <Section title={t('contacts.byReference')}>
                  <ByReference />
                </Section>
              </>
            )}
          </Panel>
        </>
      )}
    </>
  )
}

/** Which half of the screen is showing, and where that is written down. */
type Tab = 'mine' | 'new'

const TABS: Tab[] = ['mine', 'new']

const TAB_PARAM = 'tab'

/**
 * Two tabs, with the keyboard behaviour the role promises.
 *
 * `role="tab"` is a contract: a screen reader tells its user there are two of
 * something and that the arrow keys move between them, so the arrow keys have
 * to move between them. Only the chosen tab is in the tab order — that is the
 * same contract, and it is why Tab from the tab strip lands in the panel
 * rather than on the other tab.
 */
function TabBar({
  active,
  onSelect,
  labels,
}: {
  active: Tab
  onSelect: (tab: Tab) => void
  labels: Record<Tab, string>
}) {
  const { t } = useTranslation()
  const buttons = useRef<(HTMLButtonElement | null)[]>([])

  function move(event: KeyboardEvent<HTMLButtonElement>, index: number): void {
    const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0

    if (step === 0) {
      return
    }

    event.preventDefault()

    const next = (index + step + TABS.length) % TABS.length
    const tab = TABS[next]

    if (tab === undefined) {
      return
    }

    onSelect(tab)
    buttons.current[next]?.focus()
  }

  return (
    <div
      role="tablist"
      aria-label={t('contacts.tabs')}
      className="mt-5 flex gap-1 border-b border-slate-300"
    >
      {TABS.map((tab, index) => (
        <button
          key={tab}
          ref={(node) => {
            buttons.current[index] = node
          }}
          type="button"
          role="tab"
          id={tabId(tab)}
          aria-controls={panelId(tab)}
          aria-selected={tab === active}
          tabIndex={tab === active ? 0 : -1}
          onClick={() => onSelect(tab)}
          onKeyDown={(event) => move(event, index)}
          className={`-mb-px min-h-[48px] rounded-t-lg border-b-[3px] px-4 text-base focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-sky-700 ${
            tab === active
              ? 'border-sky-700 font-semibold text-sky-800'
              : 'border-transparent text-slate-700 hover:text-slate-900'
          }`}
        >
          {labels[tab]}
        </button>
      ))}
    </div>
  )
}

/**
 * The half that is showing.
 *
 * The other half is not rendered at all rather than hidden with a class: its
 * cards hold a QR code and a one-time link, and neither should exist on a
 * screen nobody chose to look at.
 */
function Panel({ tab, active, children }: { tab: Tab; active: Tab; children: ReactNode }) {
  if (tab !== active) {
    return null
  }

  return (
    <div role="tabpanel" id={panelId(tab)} aria-labelledby={tabId(tab)} tabIndex={0}>
      {children}
    </div>
  )
}

function tabId(tab: Tab): string {
  return `contacts-tab-${tab}`
}

function panelId(tab: Tab): string {
  return `contacts-panel-${tab}`
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
          // The full keyboard, not the number pad.
          //
          // A number after the branch is mostly digits and a full stop, and
          // the number pad was the obvious fit — but it is not only that. A
          // number may carry letters, and it may carry a marker on its end:
          // "!" is the spouse of the person with the same number without it.
          // Neither is reachable from a keyboard that offers digits, and a
          // member holding "10/1335.21!" would have had no way to type what
          // is printed in front of them.
          autoComplete="off"
          spellCheck={false}
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

/**
 * The same handshake as the code, for somebody who is not in the room.
 *
 * A member who already has a way to reach a relative — an address, a
 * telephone number, a chat — should not have to arrange a meeting to connect
 * with them. So: a link they send themselves, exactly as they send an
 * invitation. The portal never learns who it went to, which is why the list
 * of outstanding links carries dates and no names.
 *
 * Two properties differ from the code on the screen, and both follow from a
 * week in somebody else's inbox: it lasts days rather than minutes, and it
 * works **once**. Forwarded, quoted in a reply, left in an old chat — by then
 * it is spent, and the screen says so before it is sent rather than after.
 */
function SendLink({ days, links }: { days: number; links: SentLink[] }) {
  const { t } = useTranslation()
  const issue = useConnectionLink()
  const revoke = useRevokeConnectionLink()

  const link = issue.data

  return (
    <Card>
      <p className="text-base text-slate-700">{t('contacts.linkBody', { count: days })}</p>

      {issue.isError && (
        <div className="mt-4">
          <ErrorNotice error={issue.error} />
        </div>
      )}

      {link !== undefined && (
        <div className="mt-5">
          <ShareLink
            id="connection-link"
            url={link.url}
            label={t('contacts.linkLabel')}
            shareTitle={t('contacts.linkShareTitle')}
          />

          <p className="mt-3 text-base text-slate-700">
            {t('contacts.linkOnce', { count: link.valid_days })}
          </p>
        </div>
      )}

      <div className="mt-5">
        <Button
          variant={link === undefined ? 'primary' : 'secondary'}
          disabled={issue.isPending}
          onClick={() => issue.mutate()}
        >
          {link === undefined ? t('contacts.linkCreate') : t('contacts.linkAnother')}
        </Button>
      </div>

      {links.length > 0 && (
        <div className="mt-6 border-t border-slate-200 pt-5">
          <p className="mb-3 text-base font-medium text-slate-900">{t('contacts.linkOpen')}</p>
          <ul className="space-y-3">
            {links.map((sent) => (
              <li key={sent.id} className="flex flex-wrap items-center justify-between gap-3">
                <span className="text-base text-slate-700">
                  {t('contacts.linkExpires', { date: formatDate(sent.expires_at) })}
                </span>
                <Button
                  variant="secondary"
                  disabled={revoke.isPending}
                  onClick={() => void revoke.mutateAsync(sent.id).catch(() => undefined)}
                >
                  {t('contacts.linkWithdraw')}
                </Button>
              </li>
            ))}
          </ul>
          <p className="mt-3 text-base text-slate-700">{t('contacts.linkOpenHint')}</p>
        </div>
      )}
    </Card>
  )
}

/**
 * The server sends an ISO timestamp; one date is not worth a round trip, and
 * the browser's own locale formatting is right for it. Same as on the invite
 * screen.
 */
function formatDate(iso: string): string {
  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? iso : date.toLocaleDateString()
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
                : connect.data.status === 'already_connected'
                  ? t('contacts.alreadyConnected', { name: connect.data.name })
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

/**
 * A row of the address book, and nothing else: a name, what the tree knows of
 * them, and the way to their page.
 *
 * Ending the connection is not offered here. It is not something a member
 * comes to this list to do, and a destructive button on every row of a list
 * that is scrolled and tapped is the one place it should not be. It lives on
 * the member's own page (`MemberDetail`), which is where the decision is
 * actually made — and this whole card leads there.
 *
 * **The card is the target, not the name in it.** A name-sized link in a
 * card-sized row is a thumb-sized miss on a phone, and it teaches the wrong
 * thing about every other card in the portal, which have been whole-card
 * links since the tree was first walkable. Nothing interactive sits inside,
 * so there is nothing a link may not contain.
 */
function ContactCard({ connection }: { connection: Connection }) {
  const { t } = useTranslation()

  const lifespan = connection.individual?.lifespan ?? null

  const detail =
    connection.individual === null
      ? t('contacts.noRecord')
      : [connection.individual.name, lifespan, referenceLabel(connection.individual.references)]
          .filter((part) => part !== null && part !== '')
          .join(' · ')

  // Nobody to open: a contact whose request has not been answered has no
  // profile row yet. A card that is not a link must not look like one.
  if (connection.member_id === null) {
    return (
      <Card>
        <p className="text-lg font-semibold text-slate-900">{connection.name}</p>
        <p className="mt-1 text-base text-slate-700">{detail}</p>
      </Card>
    )
  }

  return (
    <Link
      to={`/members/${connection.member_id}`}
      className="block rounded-xl border border-slate-300 bg-white p-4 shadow-sm hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-700"
    >
      <span className="block text-lg font-semibold text-slate-900">{connection.name}</span>
      <span className="mt-1 block text-base text-slate-700">{detail}</span>
    </Link>
  )
}

/**
 * Refusing a request and withdrawing one are one button, because they are one
 * thing: this row should not exist any more. (Ending a connection is the same
 * act to the server, but it is asked for on the member's page, not here.)
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
