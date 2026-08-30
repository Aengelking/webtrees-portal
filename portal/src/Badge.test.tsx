import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { App } from './App'
import { AuthProvider } from './auth/AuthProvider'
import './i18n'

/**
 * The count on the home-screen icon.
 *
 * The navigation bar has carried this number since Phase 10; the icon is the
 * same number where a member looks without opening anything. It is also the
 * only thing the portal shows about them outside its own window, which is why
 * what it shows is a number and nothing else — the line §2.36 drew for the
 * lock screen, one surface over.
 *
 * The clearing matters more than the setting. A number left on the icon after
 * signing out is a stranger's unread count on a shared phone.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function me(unread: number, conversations = 0) {
  return {
    user: { id: 1, username: 'anna', real_name: 'Anna Beispiel', email: 'a@b.test', language: 'de', role: 'member' },
    profile: { id: 1, visible_in_directory: true, display_name_override: null, consent_recorded_at: null, directory_decided: true },
    individual: null,
    tree: { name: 'portal', title: 'Familie Beispiel' },
    unread_messages: unread,
    unread_conversations: conversations,
    csrf_token: 'token-1',
  }
}

function badging() {
  const setAppBadge = vi.fn().mockResolvedValue(undefined)
  const clearAppBadge = vi.fn().mockResolvedValue(undefined)

  vi.stubGlobal('navigator', { ...navigator, setAppBadge, clearAppBadge })

  return { setAppBadge, clearAppBadge }
}

function renderApp(unread: number, conversations = 0) {
  vi.stubGlobal(
    'fetch',
    vi.fn<typeof fetch>().mockImplementation(async (input) => {
      const url = String(input)

      if (url.endsWith('/csrf')) return jsonResponse({ csrf_token: 'token-1' })

      return jsonResponse(me(unread, conversations))
    }),
  )

  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })}
    >
      <MemoryRouter initialEntries={['/me']}>
        <AuthProvider>
          <App />
        </AuthProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('the count on the app icon', () => {
  it('is both lists added, exactly as the navigation bar counts them', async () => {
    const { setAppBadge } = badging()

    renderApp(2, 3)
    await screen.findByRole('heading', { name: 'Mein Profil' })

    await waitFor(() => {
      expect(setAppBadge).toHaveBeenCalledWith(5)
    })
  })

  it('is cleared rather than set to nothing when there is nothing', async () => {
    const { setAppBadge, clearAppBadge } = badging()

    renderApp(0)
    await screen.findByRole('heading', { name: 'Mein Profil' })

    await waitFor(() => {
      expect(clearAppBadge).toHaveBeenCalled()
    })

    expect(setAppBadge).not.toHaveBeenCalled()
  })

  /**
   * Signing out unmounts the layout, which is the moment the count stops being
   * anybody's. A shared phone must not keep showing it.
   */
  it('is cleared when the member is no longer signed in', async () => {
    const { clearAppBadge } = badging()

    const { unmount } = renderApp(4)
    await screen.findByRole('heading', { name: 'Mein Profil' })

    clearAppBadge.mockClear()
    unmount()

    expect(clearAppBadge).toHaveBeenCalled()
  })

  /** Most browsers have no badges, and a portal that throws over one is worse than one without. */
  it('says nothing where the browser has no badges', async () => {
    vi.stubGlobal('navigator', { ...navigator, setAppBadge: undefined, clearAppBadge: undefined })

    renderApp(2)

    expect(await screen.findByRole('heading', { name: 'Mein Profil' })).toBeDefined()
  })

  it('survives a browser that refuses the badge', async () => {
    vi.stubGlobal('navigator', {
      ...navigator,
      setAppBadge: vi.fn().mockRejectedValue(new Error('not allowed')),
      clearAppBadge: vi.fn().mockRejectedValue(new Error('not allowed')),
    })

    renderApp(2)

    expect(await screen.findByRole('heading', { name: 'Mein Profil' })).toBeDefined()
  })
})
