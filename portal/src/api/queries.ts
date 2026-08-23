/**
 * Server state, and only server state, lives in TanStack Query.
 *
 * `gcTime: 0` matters: genealogy data must not sit in a cache after the
 * screen showing it is gone. It is living people's personal data, and the
 * device may be shared.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from './client'
import type {
  AncestorPage,
  ConnectionCode,
  ConnectionLink,
  ConnectionOverview,
  ConnectionRequest,
  ConnectionResult,
  Individual,
  IndividualUpdate,
  MemberDetail,
  MemberPage,
  MemberProfile,
  MemberProfileUpdate,
  Me,
  ContactSettings,
  Conversation,
  ConversationMessage,
  Transcript,
  Inbox,
  InvitationOverview,
  IssuedInvitation,
  OwnContact,
  PendingIndividual,
  RelationshipResult,
  SearchPage,
  TreeIndex,
} from './types'

export const queryKeys = {
  me: ['me'] as const,
  individual: (xref: string) => ['individual', xref] as const,
  ancestors: (xref: string, generations: number) => ['ancestors', xref, generations] as const,
  members: (q: string, page: number) => ['members', q, page] as const,
  member: (id: number) => ['member', id] as const,
  search: (params: SearchParams) =>
    ['search', params.q ?? '', params.surname ?? '', params.place ?? '', params.page] as const,
  treeIndex: ['tree-index'] as const,
  relationship: (a: string, b: string) => ['relationship', a, b] as const,
  invitations: ['invitations'] as const,
  contact: ['contact'] as const,
  messages: ['messages'] as const,
  conversations: ['conversations'] as const,
  conversation: (id: number) => ['conversation', id] as const,
  connections: ['connections'] as const,
}

/**
 * The language belongs in the key, because it is part of the answer.
 *
 * Fact labels and formatted dates are rendered by the server, so the same
 * request in German and in English are two different responses. Keying on the
 * language means switching it refetches instead of leaving "Birth" on a German
 * screen. It goes last so that `queryKeys.me` still matches every language's
 * entry when a mutation invalidates it.
 */
function useLanguage(): string {
  const { i18n } = useTranslation()

  return i18n.language
}

export function useMe() {
  const language = useLanguage()

  return useQuery<Me>({
    queryKey: [...queryKeys.me, language],
    queryFn: ({ signal }) => api.me(signal),
  })
}

export function useIndividual(xref: string | undefined) {
  const language = useLanguage()

  return useQuery<Individual>({
    queryKey: [...queryKeys.individual(xref ?? ''), language],
    queryFn: ({ signal }) => api.individual(xref as string, signal),
    enabled: xref !== undefined && xref !== '',
  })
}

export function useAncestors(xref: string | undefined, generations: number) {
  const language = useLanguage()

  return useQuery<AncestorPage>({
    queryKey: [...queryKeys.ancestors(xref ?? '', generations), language],
    queryFn: ({ signal }) => api.ancestors(xref as string, generations, signal),
    enabled: xref !== undefined && xref !== '',
  })
}

export function useMembers(q: string, page: number) {
  const language = useLanguage()

  return useQuery<MemberPage>({
    queryKey: [...queryKeys.members(q, page), language],
    queryFn: ({ signal }) => api.members({ q, page, per_page: 25 }, signal),
    // Keeps the list on screen while a new search runs, instead of flashing
    // an empty page under the reader's thumb.
    placeholderData: keepPreviousData,
  })
}

/** What the tree screen is asking, in the one shape the API takes. */
export interface SearchParams {
  q?: string
  surname?: string
  place?: string
  page: number
}

/**
 * Looking through the tree.
 *
 * Disabled until there is something to ask, so that arriving on the screen
 * does not fire a search for the empty string — which the server would answer
 * with nothing anyway, at the cost of a request.
 */
export function useSearch(params: SearchParams) {
  const language = useLanguage()
  const asked =
    (params.q ?? '') !== '' || (params.surname ?? '') !== '' || (params.place ?? '') !== ''

  return useQuery<SearchPage>({
    queryKey: [...queryKeys.search(params), language],
    queryFn: ({ signal }) => api.search(params, signal),
    enabled: asked,
    // The list stays put while the next page or the next term loads, rather
    // than blanking under the reader's thumb.
    placeholderData: keepPreviousData,
  })
}

/**
 * The indexes.
 *
 * Kept for a while: they are the most expensive thing the module computes —
 * one pass over every record — and they change when the family archive
 * changes, which is not while somebody is tapping between two tabs.
 */
export function useTreeIndex(enabled: boolean) {
  const language = useLanguage()

  return useQuery<TreeIndex>({
    queryKey: [...queryKeys.treeIndex, language],
    queryFn: ({ signal }) => api.treeIndex(signal),
    enabled,
    staleTime: 10 * 60 * 1000,
  })
}

/**
 * The archive-number calculator.
 *
 * Only asked once both fields have something in them: half a question has no
 * answer, and firing on every keystroke of the first number would fill the
 * screen with "keine gültige Nummer" while somebody is still typing it.
 */
export function useRelationship(a: string, b: string) {
  const language = useLanguage()

  return useQuery<RelationshipResult>({
    queryKey: [...queryKeys.relationship(a, b), language],
    queryFn: ({ signal }) => api.relationship(a, b, signal),
    enabled: a !== '' && b !== '',
    placeholderData: keepPreviousData,
  })
}

/**
 * Writes.
 *
 * Both invalidate `me`, because both change what /me returns — the profile
 * directly, and an edit by setting `pending_change`. Re-reading from the
 * server rather than patching the cache keeps one source of truth for what
 * has actually been accepted.
 */
export function useUpdateProfile() {
  const queryClient = useQueryClient()

  return useMutation<MemberProfile, Error, MemberProfileUpdate>({
    mutationFn: (changes) => api.updateProfile(changes),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.me })
    },
  })
}

export function useUpdateIndividual() {
  const queryClient = useQueryClient()

  return useMutation<PendingIndividual, Error, IndividualUpdate>({
    mutationFn: (changes) => api.updateIndividual(changes),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.me })
    },
  })
}

/**
 * Whom this member may invite, and whom they already have.
 *
 * Language is part of the key like everywhere else: the relationship names
 * ("Ihr Bruder") are rendered by webtrees on the server, so a language switch
 * has to refetch rather than leave German labels on an English screen.
 */
export function useInvitations() {
  const language = useLanguage()

  return useQuery<InvitationOverview>({
    queryKey: [...queryKeys.invitations, language],
    queryFn: ({ signal }) => api.invitations(signal),
  })
}

export function useInvite() {
  const queryClient = useQueryClient()

  return useMutation<IssuedInvitation, Error, { xref: string; email: string }>({
    mutationFn: ({ xref, email }) => api.invite(xref, email),
    onSuccess: () => {
      // The person just invited drops off the candidate list and the
      // remaining count falls, so the answer comes from the server rather
      // than from patching the cache.
      void queryClient.invalidateQueries({ queryKey: queryKeys.invitations })
    },
  })
}

export function useWithdrawInvitation() {
  const queryClient = useQueryClient()

  return useMutation<InvitationOverview, Error, number>({
    mutationFn: (id) => api.withdrawInvitation(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.invitations })
    },
  })
}

/**
 * The member's own inbox.
 *
 * Every mutation returns the whole inbox, so the list and the unread count
 * cannot disagree after a change — there is one answer and it comes from the
 * server.
 */
export function useMessages() {
  return useQuery<Inbox>({
    queryKey: queryKeys.messages,
    queryFn: ({ signal }) => api.messages(signal),
  })
}

export function useMarkMessage() {
  const queryClient = useQueryClient()

  return useMutation<Inbox, Error, { id: number; read: boolean }>({
    mutationFn: ({ id, read }) => api.markMessage(id, read),
    onSuccess: (result) => {
      queryClient.setQueryData(queryKeys.messages, result)
      // `me` carries the badge count for the navigation bar.
      void queryClient.invalidateQueries({ queryKey: queryKeys.me })
    },
  })
}

export function useDeleteMessage() {
  const queryClient = useQueryClient()

  return useMutation<Inbox, Error, number>({
    mutationFn: (id) => api.deleteMessage(id),
    onSuccess: (result) => {
      queryClient.setQueryData(queryKeys.messages, result)
      void queryClient.invalidateQueries({ queryKey: queryKeys.me })
    },
  })
}

/**
 * The conversations, and one of them.
 *
 * Every write answers with the piece of the world it changed — the transcript
 * for a message, the list for a deletion — so that what is on screen and what
 * is on the server cannot drift apart. `me` is invalidated alongside, because
 * it carries the badge.
 */
export function useConversations() {
  return useQuery<{ conversations: Conversation[] }>({
    queryKey: queryKeys.conversations,
    queryFn: ({ signal }) => api.conversations(signal),
  })
}

export function useConversation(id: number) {
  return useQuery<Transcript>({
    queryKey: queryKeys.conversation(id),
    queryFn: ({ signal }) => api.conversation(id, undefined, signal),
    enabled: id > 0,
  })
}

/** Opening one is the step the directory rule guards; it may 404. */
export function useOpenConversation() {
  const queryClient = useQueryClient()

  return useMutation<{ conversation: Conversation }, Error, number>({
    mutationFn: (memberId) => api.openConversation(memberId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.conversations })
    },
  })
}

export function useSendConversationMessage(id: number) {
  const queryClient = useQueryClient()

  return useMutation<{ message: ConversationMessage }, Error, string>({
    mutationFn: (body) => api.sendConversationMessage(id, body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.conversation(id) })
      void queryClient.invalidateQueries({ queryKey: queryKeys.conversations })
      void queryClient.invalidateQueries({ queryKey: queryKeys.me })
    },
  })
}

export function useDeleteConversationMessage(id: number) {
  const queryClient = useQueryClient()

  return useMutation<Transcript, Error, number>({
    mutationFn: (message) => api.deleteConversationMessage(id, message),
    onSuccess: (result) => {
      queryClient.setQueryData(queryKeys.conversation(id), result)
      void queryClient.invalidateQueries({ queryKey: queryKeys.conversations })
      void queryClient.invalidateQueries({ queryKey: queryKeys.me })
    },
  })
}

export function useClearConversation() {
  const queryClient = useQueryClient()

  return useMutation<{ conversations: Conversation[] }, Error, number>({
    mutationFn: (id) => api.clearConversation(id),
    onSuccess: (result, id) => {
      queryClient.setQueryData(queryKeys.conversations, result)
      queryClient.removeQueries({ queryKey: queryKeys.conversation(id) })
      void queryClient.invalidateQueries({ queryKey: queryKeys.me })
    },
  })
}

/**
 * Answer a message.
 *
 * The inbox is refetched afterwards even though a reply changes nothing in
 * it: webtrees keeps no copy of what one sends, so the honest thing is to
 * show the list exactly as the server has it rather than to invent a sent
 * item that does not exist.
 */
export function useReplyToMessage(id: number) {
  const queryClient = useQueryClient()

  return useMutation<{ status: string }, Error, string>({
    mutationFn: (body) => api.replyToMessage(id, body),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.messages })
    },
  })
}

/**
 * My contacts, and the requests either way.
 *
 * Language-keyed like the other screens that carry records: the linked
 * individual is rendered by the server, so a language switch has to refetch
 * rather than leave German labels on an English screen.
 */
export function useConnections() {
  const language = useLanguage()

  return useQuery<ConnectionOverview>({
    queryKey: [...queryKeys.connections, language],
    queryFn: ({ signal }) => api.connections(signal),
  })
}

/**
 * Every write returns the whole overview, so the three lists cannot disagree
 * after a change: there is one answer and it comes from the server. `me` is
 * invalidated as well, because the navigation badge counts the requests.
 */
function useConnectionMutation<TVariables, TResult extends ConnectionOverview>(
  mutationFn: (variables: TVariables) => Promise<TResult>,
) {
  const queryClient = useQueryClient()
  const language = useLanguage()

  return useMutation<TResult, Error, TVariables>({
    mutationFn,
    onSuccess: (result) => {
      queryClient.setQueryData([...queryKeys.connections, language], result)
      void queryClient.invalidateQueries({ queryKey: queryKeys.connections })
      void queryClient.invalidateQueries({ queryKey: queryKeys.me })
      // A connection decides what a member's page shows and offers, and what
      // every row of the directory offers, so neither is to be trusted after
      // one changes.
      void queryClient.invalidateQueries({ queryKey: ['member'] })
      void queryClient.invalidateQueries({ queryKey: ['members'] })
    },
  })
}

export function useConnect() {
  return useConnectionMutation<ConnectionRequest, ConnectionResult>((how) => api.connect(how))
}

export function useAcceptConnection() {
  return useConnectionMutation<number, ConnectionResult>((id) => api.acceptConnection(id))
}

export function useRemoveConnection() {
  return useConnectionMutation<number, ConnectionOverview>((id) => api.removeConnection(id))
}

/** A code is issued, not read: asking for one invalidates the one before it. */
export function useConnectionCode() {
  return useMutation<ConnectionCode, Error, void>({
    mutationFn: () => api.createConnectionCode(),
  })
}

/** A link to send. Issued on request, like the code, and never twice. */
export function useConnectionLink() {
  return useMutation<ConnectionLink, Error, void>({
    mutationFn: () => api.createConnectionLink(),
  })
}

export function useRevokeConnectionLink() {
  return useConnectionMutation<number, ConnectionOverview>((id) => api.revokeConnectionLink(id))
}

export function useRevokeConnectionCode() {
  return useMutation<{ status: string }, Error, void>({
    mutationFn: () => api.revokeConnectionCode(),
  })
}

/** What I share, and with whom. Not language-keyed: these are values I typed. */
export function useContact() {
  return useQuery<ContactSettings>({
    queryKey: queryKeys.contact,
    queryFn: ({ signal }) => api.contact(signal),
  })
}

export function useUpdateContact() {
  const queryClient = useQueryClient()

  return useMutation<ContactSettings, Error, OwnContact>({
    mutationFn: (changes) => api.updateContact(changes),
    onSuccess: (result) => {
      queryClient.setQueryData(queryKeys.contact, result)
    },
  })
}

export function useSendMessage(id: number | undefined) {
  return useMutation<{ status: string }, Error, { subject: string; body: string }>({
    mutationFn: ({ subject, body }) => api.sendMessage(id as number, subject, body),
  })
}

export function useRequestPasswordReset() {
  return useMutation<{ status: string }, Error, string>({
    mutationFn: (email) => api.requestPasswordReset(email),
  })
}

export function useMember(id: number | undefined) {
  const language = useLanguage()

  return useQuery<MemberDetail>({
    queryKey: [...queryKeys.member(id ?? 0), language],
    queryFn: ({ signal }) => api.member(id as number, signal),
    enabled: id !== undefined && Number.isFinite(id),
  })
}
