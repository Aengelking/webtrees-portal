/**
 * The shapes in openapi.yaml, in TypeScript.
 *
 * Hand-written rather than generated, because the surface is small enough
 * that a generator would cost more than it saves — but it is kept in step
 * with the spec by hand, and `src/api/contract.test.ts` checks that the two
 * agree on the endpoint list.
 */

export type ApiErrorCode =
  | 'bad_request'
  | 'unauthenticated'
  | 'csrf_token_invalid'
  | 'proxy_secret_invalid'
  | 'invalid_credentials'
  | 'not_found'
  | 'not_configured'
  | 'no_linked_record'
  | 'record_locked'
  | 'change_pending'
  | 'invalid_token'
  | 'not_allowed'
  | 'quota_reached'
  | 'cannot_reply'
  | 'not_delivered'
  | 'username_taken'
  | 'email_taken'
  | 'server_error'
  | 'network_error'

export interface ApiErrorBody {
  error: ApiErrorCode
  message: string
  /**
   * Present only when the server recorded the failure — that is, only when it
   * was the portal's own fault rather than a refusal it meant to give.
   */
  reference?: string
}

/** A close relative a member may invite, with the relationship named. */
export interface InvitationCandidate extends IndividualRef {
  relationship: string | null
}

export interface MemberInvitation {
  id: number
  name: string | null
  email: string | null
  expires_at: string
}

export interface InvitationOverview {
  /** False when the family has switched member invitations off entirely. */
  enabled: boolean
  /**
   * Whom this reader may invite: their close family, or anybody they can see.
   *
   * The second is what an editor gets, and it is why the screen has two
   * shapes — a wheel of a handful of relatives, or a search over the whole
   * archive. It cannot be worked out from the list, because a short list is
   * also what a small family looks like.
   *
   * Optional: the module and the portal deploy separately, and a server that
   * predates it means the close-family screen, which is what it did before.
   */
  scope?: 'close_family' | 'anyone'
  /** False when this account is not linked to anybody in the tree. */
  linked: boolean
  quota: number
  remaining: number
  candidates: InvitationCandidate[]
  invitations: MemberInvitation[]
}

/** The link is in the response once and is never recoverable afterwards. */
export interface IssuedInvitation {
  link: string
  invitation: MemberInvitation | null
}

export interface Message {
  id: number
  /** The sender's name where it could be resolved, the address where it could not. */
  from: string
  subject: string
  /** The message, without the email webtrees wrapped around it. */
  body: string
  sent_at: string
  read: boolean
  /**
   * False when the address on the message belongs to no account — a webtrees
   * contact form filled in by a visitor, a sender who has changed their
   * address, a deleted account — and for one's own copy of a broadcast.
   */
  can_reply: boolean
}

export interface Inbox {
  messages: Message[]
  unread: number
}

export interface Health {
  status: 'ok'
  version: string
  schema_version: number
}

export interface CsrfToken {
  csrf_token: string
  /**
   * How many days a member may stay signed in for, or 0 where the family has
   * not switched that on.
   *
   * On this endpoint because the login screen is the one screen with no
   * session, and therefore no `/me` to ask. It is also the number the offer
   * has to state, so a boolean would not have been enough.
   */
  remember_days: number
}

/**
 * What an invitation opens, as far as somebody who is not signed in may know.
 *
 * `invited_name` is a snapshot the administrator's screen took when the
 * invitation was issued, not a lookup — so opening an invitation link never
 * reads the family tree.
 */
export interface InvitationPreview {
  tree: Tree
  invited_name: string | null
  email: string | null
  expires_at: string
}

export interface InvitationAcceptance {
  token: string
  username: string
  real_name: string
  email: string
  password: string
}

export interface Credentials {
  username: string
  password: string
  /**
   * Stay signed in on this device. Honoured only if the family allows it, and
   * only by the request that carried the password.
   */
  remember?: boolean
}

export interface Tree {
  name: string
  title: string
}

export type Role =
  | 'visitor'
  | 'member'
  | 'editor'
  | 'moderator'
  | 'manager'
  | 'administrator'

export interface User {
  id: number
  username: string
  real_name: string
  email: string
  language: string
  role: Role
}

export interface MemberProfile {
  id: number
  visible_in_directory: boolean
  display_name_override: string | null
  consent_recorded_at: string | null
}

export interface DateValue {
  display: string
  gedcom: string
  year: number | null
}

export interface Event {
  tag: string
  label: string
  value: string | null
  date: DateValue | null
  place: string | null
}

/**
 * A user reference number from the record — the family archive's own
 * numbering (an "SB" number here), not webtrees'.
 */
export interface Reference {
  number: string
  type: string | null
  /**
   * The branch of the family the number belongs to, by the name the family
   * gives it, or null where the number does not say — see openapi.yaml.
   *
   * Derived by the server from the part in front of the oblique, because that
   * is where it is written; the portal does no reading of numbers of its own.
   */
  branch: string | null
}

/**
 * A picture, with URLs relative to the portal's own origin.
 *
 * Not webtrees' media URLs: those point at the webtrees host, where this
 * browser has no session, and would come back as a "forbidden" placeholder.
 */
export interface Photo {
  id: string
  title: string | null
  thumbnail_url: string
  image_url: string
}

export type Sex = 'M' | 'F' | 'X' | 'U'

export interface IndividualRef {
  xref: string
  name: string
  sex: Sex
  is_deceased: boolean
  lifespan: string | null
  /** Optional: the module and the portal can be a version apart. */
  portrait?: Photo | null
  /**
   * The reference number, on every mention of a person and not only on the
   * full record — this family tells its Dieters apart by it. Optional for the
   * same reason as the portrait.
   */
  references?: Reference[]
  /**
   * How the signed-in member is related to this person, in their language, or
   * null when there is nothing safe to say — see openapi.yaml.
   *
   * On the card and not only on the record: a page of search results is
   * otherwise names and years, and this is the line that turns one of them
   * into the person the reader was looking for. Optional for the same reason
   * as the portrait.
   */
  relationship?: string | null
}

/** The working behind a calculated relationship, for anybody who checks it. */
export interface RelationshipDetail {
  kind: 'self' | 'sibling' | 'ancestor' | 'descendant' | 'nephew' | 'uncle' | 'cousin'
  generations: number
  distance: number
  degree: number | null
}

/** What the archive-number calculator answers. */
export interface RelationshipResult {
  a: string
  b: string
  /** Null when there is an answer — see openapi.yaml for the four reasons. */
  problem: 'incomplete' | 'invalid_a' | 'invalid_b' | 'identical' | null
  relationship: string | null
  detail: RelationshipDetail | null
}

/** A page of people found by looking through the tree. */
export interface SearchPage {
  items: IndividualRef[]
  total: number
  page: number
  per_page: number
  /**
   * True when the answer was cut short — too many matches, or a tree larger
   * than one request will read. `total` is then a floor, and the screen says
   * so rather than letting a member believe the list is complete.
   */
  truncated: boolean
}

/** One line of an index: a surname or a place, and how many people it has. */
export interface IndexEntry {
  name: string
  count: number
}

/** The two ways of reading down the archive rather than querying it. */
export interface TreeIndex {
  surnames: IndexEntry[]
  places: IndexEntry[]
  truncated: boolean
}

/** Everything a member may change about themselves. */
export interface IndividualUpdate {
  given_names?: string | null
  surname?: string | null
  birth_date?: string | null
  birth_place?: string | null
  occupation?: string | null
  address?: string | null
  email?: string | null
  phone?: string | null
  website?: string | null
}

export type EditableField = keyof IndividualUpdate

export interface MemberProfileUpdate {
  visible_in_directory?: boolean
  display_name_override?: string | null
  /**
   * The member's own language, kept on their account rather than on this
   * telephone. `de` or `en`; the server resolves it to a tag it has enabled.
   */
  language?: string
}

export interface PendingIndividual {
  /**
   * `applied` when webtrees put the change live straight away, which it does
   * for a user with the `auto_accept` preference — editors and administrators
   * usually have it, members do not.
   */
  status: 'pending_approval' | 'applied'
  pending_change: boolean
  individual: Individual | null
}

/** Where a rung sits, which is true of a person and of a placeholder alike. */
export interface AncestorPlacement {
  position: number
  generation: number
  /**
   * Whether this rung is a placeholder rather than a person.
   *
   * Optional because the module and the portal deploy separately, and a
   * server that predates the field never sends a placeholder either — so its
   * absence reads correctly as `false`.
   */
  private?: boolean
}

/** An ancestor the reader may read. */
export type VisibleAncestor = IndividualRef & AncestorPlacement & { private?: false }

/**
 * Somebody stands here, and that is all the reader is told.
 *
 * No name, no dates, no picture, no archive number and no xref — so there is
 * nothing to link to and nothing to ask the API about. Nor does it say *why*
 * the record is closed: a living person outside the reader's reach and a
 * record marked confidential arrive identically.
 */
export interface PrivateAncestor extends AncestorPlacement {
  private: true
  /**
   * The directory listing this record belongs to, when the person standing
   * here is a member who put themselves in it — null otherwise, which is the
   * ordinary case.
   *
   * Their own decision, and portal data rather than genealogy data: the name
   * is the one they publish in the member directory, and nothing from the
   * family tree comes with it.
   */
  member?: { id: number; display_name: string } | null
}

/** One rung of the pedigree, positioned by Ahnentafel number. */
export type Ancestor = VisibleAncestor | PrivateAncestor

export interface AncestorPage {
  generations: number
  people: Ancestor[]
  /**
   * True where the walk stopped at its own limit rather than at the top of the
   * archive — so that a line which simply ends is not read as a cut-off one.
   *
   * Optional for the usual reason: a server that predates the field never
   * truncates either, so its absence reads correctly as `false`.
   */
  truncated?: boolean
}

export interface Individual extends IndividualRef {
  name_alternative: string | null
  photos?: Photo[]
  birth: Event | null
  death: Event | null
  events: Event[]
  parents: IndividualRef[]
  siblings: IndividualRef[]
  spouses: IndividualRef[]
  children: IndividualRef[]
  /**
   * Whether this reader could invite this person into the portal.
   *
   * Answered by the server with the same rule the invitation endpoint
   * applies, so an offer and its answer cannot disagree. `false` for every
   * reason there could be — dead, already an account holder, already invited,
   * too distant — on purpose: nobody can learn which by looking.
   *
   * Optional: the module and the portal deploy separately.
   */
  invitable?: boolean
  /** True while an edit of the member's own record awaits approval. */
  pending_change: boolean
  webtrees_url: string
}

/**
 * One exchange with one other member — the thing webtrees' message table
 * cannot hold, because it keeps a single row per message owned by whoever
 * received it. See `openapi.yaml` and NOTES §2.33.
 */
export interface Conversation {
  id: number
  /** For linking to their page. Null when they have no member profile. */
  member_id: number | null
  name: string
  unread: number
  last_message: ConversationMessage | null
}

export interface ConversationMessage {
  id: number
  mine: boolean
  /** What was typed, not a rendered email template. */
  body: string
  sent_at: string
  /** Only meaningful on one's own: the other side has read it. */
  read: boolean
}

export interface Transcript {
  conversation: Conversation
  messages: ConversationMessage[]
  /** The id to ask `before` with, or null at the beginning of the exchange. */
  before: number | null
}

/** What this portal can do about notifications, and whether this account uses it. */
export interface PushState {
  available: boolean
  /** Empty unless notifications are available. Public by design. */
  public_key: string
  /** About the account, across devices — the browser knows about this one. */
  subscribed: boolean
}

export interface Me {
  /** Carried on /me so the navigation badge needs no request of its own. */
  unread_messages: number
  /** Counted apart from the inbox: they are two lists on the screen. */
  unread_conversations: number
  /** The same, for members waiting to hear whether they are known. Optional. */
  connection_requests?: number
  user: User
  profile: MemberProfile | null
  individual: Individual | null
  tree: Tree
  csrf_token: string
}

export interface MemberSummary {
  id: number
  display_name: string
  individual: IndividualRef | null
  /**
   * What may be shown of somebody whose record this reader may not read —
   * **only ever present when `individual` is null**.
   *
   * `portrait` is a photograph that person uploaded to the portal themselves;
   * their own consent covers it whatever the record's privacy says.
   * `references` is their archive number, and is empty unless the family
   * switched that on in the control panel.
   *
   * Where `individual` *is* present, both live inside it and follow the
   * record's own rules — so read `individual?.portrait ?? portrait` and never
   * both.
   */
  portrait?: Photo | null
  references?: Reference[]
  /**
   * Where the reader and this member stand. Carried on every row of the
   * directory, so a request can be sent from the list without opening the
   * person first — it is one row of one table, unlike contact details, which
   * walk the tree and are deliberately not in the list.
   *
   * Optional for the usual reason: the module ships over SFTP and the portal
   * through CI, so the two can be a version apart.
   */
  connection?: ConnectionState
}

/** The kinds a member may share. Closed, and decided by the server. */
export type ContactKind = 'email' | 'phone' | 'address'

/** Per entry, never per member: two details can have two different answers. */
export type ContactAudience = 'nobody' | 'close_family' | 'connections' | 'members'

/**
 * An address as fields rather than as one line.
 *
 * Optional throughout, and for two different reasons: an e-mail address and a
 * telephone number genuinely have none, and a server that predates this sends
 * none for the address either. Both resolve the same way — fall back to the
 * text, which every version of the module has always sent.
 */
export interface AddressParts {
  street: string
  postcode: string
  city: string
  country: string
}

export interface ContactEntry {
  /**
   * The entry as one readable piece of text — for an address, its lines. This
   * is what every reader gets; the fields below exist for the member who is
   * typing it.
   */
  value: string
  /** The address only, and only from a server that knows about fields. */
  parts?: AddressParts
  audience: ContactAudience
}

/** A member's own entries, audience and all. Only ever their own. */
export type OwnContact = Partial<Record<ContactKind, ContactEntry>>

export interface ContactSettings {
  /** False when the family has switched contact sharing off entirely. */
  enabled: boolean
  /**
   * Whether "only my contacts" is worth offering. Optional: the module and
   * the portal deploy separately, and an older server simply does not say.
   */
  connections_enabled?: boolean
  contact: OwnContact
}

/**
 * Whether Exchange agrees with the member's answer yet.
 *
 * `applied` is the ordinary state and the screen says nothing about it. The
 * other two are both "we have your answer": one is on its way, the other could
 * not be delivered and somebody has been told. Neither is the member's problem
 * to solve, and neither is ever accompanied by Exchange's own words.
 */
export type MailingListState = 'applied' | 'pending' | 'failed'

/** One of the family's round-robin letters, as offered to a member. */
export interface MailingList {
  /** Opaque and stable. The list's address is never sent to the portal. */
  key: string
  name: string
  /** What arrives and how often, in the administrator's words. May be empty. */
  description: string
  subscribed: boolean
  state: MailingListState
}

export interface MailingLists {
  /** False when the family has not set this up. The portal then shows nothing. */
  enabled: boolean
  /** The account address a subscription is made under. Empty if there is none. */
  address: string
  lists: MailingList[]
}

/** Where the reader and another member stand with each other. */
export type ConnectionStatus = 'none' | 'requested' | 'incoming' | 'connected' | 'self'

export interface ConnectionState {
  status: ConnectionStatus
  /** The connection's id, for accepting or ending it. */
  id: number | null
}

/**
 * One entry of the member's own address book.
 *
 * `name` is portal data — the other member's published display name, or the
 * name on their account. `individual` is GEDCOM data and is nulled out
 * whenever the reader may not see it: agreeing to know somebody does not lift
 * the tree's privacy rules.
 */
export interface Connection {
  id: number
  status: 'pending' | 'accepted'
  /** How the two found each other. */
  source: 'code' | 'reference'
  /** Who asked. Not who may end it — either of them may. */
  requested_by_me: boolean
  member_id: number | null
  name: string
  individual: IndividualRef | null
  /**
   * What may be shown of somebody whose record this reader may not read —
   * **only ever present when `individual` is null**.
   *
   * `portrait` is a photograph that person uploaded to the portal themselves;
   * their own consent covers it whatever the record's privacy says.
   * `references` is their archive number, and is empty unless the family
   * switched that on in the control panel.
   *
   * Where `individual` *is* present, both live inside it and follow the
   * record's own rules — so read `individual?.portrait ?? portrait` and never
   * both.
   */
  portrait?: Photo | null
  references?: Reference[]
  since: string
}

export interface ConnectionOverview {
  /** False when the family has switched connections off entirely. */
  enabled: boolean
  /** How long a code lasts, so the screen can say so rather than guess. */
  code_valid_minutes: number
  /** The same, for a link that is sent. Optional: an older server has none. */
  link_valid_days?: number
  connections: Connection[]
  incoming: Connection[]
  outgoing: Connection[]
  /** Links sent and not yet used. Optional, for the same reason. */
  links?: SentLink[]
}

/**
 * The overview, plus what the request that returned it actually did.
 *
 * `already_connected` is the one that did nothing: the number belongs to
 * somebody already in the member's contacts, so there was nothing to send.
 */
export interface ConnectionResult extends ConnectionOverview {
  status: 'connected' | 'requested' | 'already_connected'
  name: string | null
}

/** Shown once. The server keeps only a hash, so nothing can hand it out twice. */
export interface ConnectionCode {
  url: string
  expires_at: string
  valid_minutes: number
}

/**
 * A link to send to somebody who is not in the room. Handed over once, for
 * the same reason as the code above — and unlike the code, it works once.
 */
export interface ConnectionLink {
  url: string
  expires_at: string
  valid_days: number
}

/**
 * One link the member sent and nobody has used yet.
 *
 * No name against it: they wrote to somebody themselves, and the portal never
 * learned who.
 */
export interface SentLink {
  id: number
  created_at: string
  expires_at: string
}

/** Exactly one of the three ways in. */
export type ConnectionRequest =
  | { code: string }
  | { reference: string }
  | { member_id: number }

export interface MemberDetail extends MemberSummary {
  individual_detail: Individual | null
  /**
   * Only what this viewer may see, already decided by the server — the values
   * of the entries that reached them, and nothing about the ones that did not.
   */
  contact: Partial<Record<ContactKind, string>>
  can_message: boolean
  /**
   * Optional for the usual reason: the module ships over SFTP and the portal
   * through CI, so the two can be a version apart and this screen has to
   * survive a server that predates the field.
   */
  connections_enabled?: boolean
  /**
   * Whether this member's pedigree can be opened — `/members/{id}/ancestors`
   * has somebody standing above them.
   *
   * The way in for a member whose genealogy record is closed to this reader:
   * `individual_detail` is then null, so no record view and no button of its
   * own, and the family above them was unreachable. See §2.77.
   *
   * Optional for the usual reason: the module ships over SFTP and the portal
   * through CI, so a server that predates the field simply offers nothing.
   */
  ancestors?: boolean
}

export interface MemberPage {
  items: MemberSummary[]
  total: number
  page: number
  per_page: number
  /** False when the family has switched connections off. Optional. */
  connections_enabled?: boolean
}
