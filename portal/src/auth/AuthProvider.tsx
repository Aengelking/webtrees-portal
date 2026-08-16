import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, forgetCsrfToken, setUnauthenticatedHandler } from '../api/client'
import type { Credentials, Me } from '../api/types'

type Status = 'checking' | 'signed-in' | 'signed-out'

interface AuthContextValue {
  status: Status
  me: Me | null
  signIn: (credentials: Credentials) => Promise<void>
  signOut: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

/**
 * Holds the answer to one question: is someone signed in?
 *
 * Nothing is persisted. On a reload the portal asks the server, because the
 * server is the only thing that knows whether the session cookie is still
 * good — and a cookie the browser will not show us is the whole point of the
 * design.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<Status>('checking')
  const [me, setMe] = useState<Me | null>(null)
  const queryClient = useQueryClient()

  const reset = useCallback(() => {
    setMe(null)
    setStatus('signed-out')
    forgetCsrfToken()
    queryClient.clear()
  }, [queryClient])

  // Any 401, from any request, drops the portal back to signed-out. The
  // router then sends the member to /login.
  useEffect(() => {
    setUnauthenticatedHandler(reset)

    return () => {
      setUnauthenticatedHandler(null)
    }
  }, [reset])

  useEffect(() => {
    let cancelled = false

    api
      .me()
      .then((result) => {
        if (!cancelled) {
          setMe(result)
          setStatus('signed-in')
        }
      })
      .catch(() => {
        if (!cancelled) {
          setMe(null)
          setStatus('signed-out')
        }
      })

    return () => {
      cancelled = true
    }
  }, [])

  const signIn = useCallback(
    async (credentials: Credentials) => {
      const result = await api.login(credentials)

      queryClient.clear()
      queryClient.setQueryData(['me'], result)
      setMe(result)
      setStatus('signed-in')
    },
    [queryClient],
  )

  const signOut = useCallback(async () => {
    await api.logout()
    reset()
  }, [reset])

  const value = useMemo<AuthContextValue>(
    () => ({ status, me, signIn, signOut }),
    [status, me, signIn, signOut],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (context === null) {
    throw new Error('useAuth must be used inside an AuthProvider')
  }

  return context
}
