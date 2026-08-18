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
  | 'server_error'
  | 'network_error'

export interface ApiErrorBody {
  error: ApiErrorCode
  message: string
}

export interface CsrfToken {
  csrf_token: string
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

export type Sex = 'M' | 'F' | 'X' | 'U'

export interface IndividualRef {
  xref: string
  name: string
  sex: Sex
  is_deceased: boolean
  lifespan: string | null
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

export interface MemberDetail extends MemberSummary {
  individual_detail: Individual | null
}

export interface MemberPage {
  items: MemberSummary[]
  total: number
  page: number
  per_page: number
}
