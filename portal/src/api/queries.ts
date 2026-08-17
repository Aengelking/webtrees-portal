/**
 * Server state, and only server state, lives in TanStack Query.
 *
 * `gcTime: 0` matters: genealogy data must not sit in a cache after the
 * screen showing it is gone. It is living people's personal data, and the
 * device may be shared.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
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

export function useMe() {
  return useQuery<Me>({
    queryKey: queryKeys.me,
    queryFn: ({ signal }) => api.me(signal),
  })
}

export function useIndividual(xref: string | undefined) {
  return useQuery<Individual>({
    queryKey: queryKeys.individual(xref ?? ''),
    queryFn: ({ signal }) => api.individual(xref as string, signal),
    enabled: xref !== undefined && xref !== '',
  })
}

export function useMembers(q: string, page: number) {
  return useQuery<MemberPage>({
    queryKey: queryKeys.members(q, page),
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
  return useQuery<MemberDetail>({
    queryKey: queryKeys.member(id ?? 0),
    queryFn: ({ signal }) => api.member(id as number, signal),
    enabled: id !== undefined && Number.isFinite(id),
  })
}
