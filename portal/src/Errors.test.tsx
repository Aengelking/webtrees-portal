import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ApiError, api } from './api/client'
import { ErrorNotice } from './components/ui'
import './i18n'

/**
 * Phase 6: what a member sees when the portal itself is broken, and what they
 * can do with it.
 *
 * The reference is the whole point. Without one, a report reads "it did not
 * work yesterday, on my phone", and there is nothing to look up. With one, the
 * member reads eight characters aloud and the administrator has the request.
 */

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('an error the member can report', () => {
  it('shows the reference the server sent', () => {
    render(<ErrorNotice error={new ApiError('server_error', 500, 'Boom', 'ab12cd34')} />)

    expect(screen.getByText('ab12cd34')).toBeDefined()
    expect(screen.getByRole('alert').textContent).toMatch(/Kennung/)
  })

  /**
   * A code nobody can look up is worse than no code: it invites a member to
   * quote something the administrator will not find.
   */
  it('shows nothing when the server recorded nothing', () => {
    render(<ErrorNotice error={new ApiError('not_found', 404, 'Nope')} />)

    expect(screen.getByRole('alert').textContent).not.toMatch(/Kennung/)
  })

  it('says nothing about a reference for a failure that never reached the server', () => {
    render(<ErrorNotice error={new ApiError('network_error', 0, 'offline')} />)

    expect(screen.getByRole('alert').textContent).not.toMatch(/Kennung/)
  })

  it('carries the reference off the wire and onto the error', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockResolvedValue(
        jsonResponse({ error: 'server_error', message: 'Boom', reference: 'deadbeef' }, 500),
      ),
    )

    await expect(api.me()).rejects.toMatchObject({
      code: 'server_error',
      reference: 'deadbeef',
    })
  })

  it('leaves the reference null when the body has none', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockResolvedValue(
        jsonResponse({ error: 'not_found', message: 'Nope' }, 404),
      ),
    )

    await expect(api.me()).rejects.toMatchObject({ code: 'not_found', reference: null })
  })
})

describe('the health endpoint', () => {
  /**
   * No screen calls this. It exists for the deployment smoke check and for an
   * uptime monitor, and the test exists so the client call does not quietly
   * drift away from openapi.yaml because nothing references it.
   */
  it('reports the version that is running', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn<typeof fetch>().mockResolvedValue(
        jsonResponse({ status: 'ok', version: '1.0.0', schema_version: 3 }),
      ),
    )

    await expect(api.health()).resolves.toEqual({
      status: 'ok',
      version: '1.0.0',
      schema_version: 3,
    })

    expect(String(vi.mocked(fetch).mock.calls[0]?.[0])).toContain('/api/v1/health')
  })
})
