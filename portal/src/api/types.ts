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

/** One ancestor, positioned by Ahnentafel number. */
export interface Ancestor extends IndividualRef {
  position: number
  generation: number
}

export interface AncestorPage {
  generations: number
  people: Ancestor[]
}

export interface Individual extends IndividualRef {
  name_alternative: string | null
  /**
   * How the signed-in member is related to this person, in their language,
   * or null when there is nothing safe to say — see openapi.yaml.
   *
   * Optional for the same reason as `references`: the module and the portal
   * deploy separately and can be a version apart.
   */
  relationship?: string | null
  photos?: Photo[]
  /**
   * Optional on purpose. The module and the portal deploy separately — the
   * module by SFTP, the portal by CI — so the portal has to survive a server
   * that predates this field rather than throwing on the profile screen.
   */
  references?: Reference[]
  birth: Event | null
  death: Event | null
  events: Event[]
  parents: IndividualRef[]
  siblings: IndividualRef[]
  spouses: IndividualRef[]
  children: IndividualRef[]
  /** True while an edit of the member's own record awaits approval. */
  pending_change: boolean
  webtrees_url: string
}

export interface Me {
  /** Carried on /me so the navigation badge needs no request of its own. */
  unread_messages: number
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
}

/** The kinds a member may share. Closed, and decided by the server. */
export type ContactKind = 'email' | 'phone' | 'address'

/** Per entry, never per member: two details can have two different answers. */
export type ContactAudience = 'nobody' | 'close_family' | 'members'

export interface ContactEntry {
  value: string
  audience: ContactAudience
}

/** A member's own entries, audience and all. Only ever their own. */
export type OwnContact = Partial<Record<ContactKind, ContactEntry>>

export interface ContactSettings {
  /** False when the family has switched contact sharing off entirely. */
  enabled: boolean
  contact: OwnContact
}

export interface MemberDetail extends MemberSummary {
  individual_detail: Individual | null
  /**
   * Only what this viewer may see, already decided by the server — the values
   * of the entries that reached them, and nothing about the ones that did not.
   */
  contact: Partial<Record<ContactKind, string>>
  can_message: boolean
}

export interface MemberPage {
  items: MemberSummary[]
  total: number
  page: number
  per_page: number
}
