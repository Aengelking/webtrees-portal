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
  Individual,
  IndividualUpdate,
  MemberDetail,
  MemberPage,
  MemberProfile,
  MemberProfileUpdate,
  Me,
  PendingIndividual,
} from './types'

export const queryKeys = {
  me: ['me'] as const,
  individual: (xref: string) => ['individual', xref] as const,
  members: (q: string, page: number) => ['members', q, page] as const,
  member: (id: number) => ['member', id] as const,
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
