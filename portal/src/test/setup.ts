import { afterEach, beforeEach, vi } from 'vitest'
import { cleanup, configure } from '@testing-library/react'
import { forgetCsrfToken, setUnauthenticatedHandler } from '../api/client'

/**
 * How long a `findBy…` waits before giving up.
 *
 * Testing Library's own second is generous on a laptop and marginal on a CI
 * runner with two shared cores — and the queries that time out first are the
 * expensive ones, `getByRole` with a name, which re-run on every mutation
 * while a screen settles. A suite that fails on the machine it is meant to
 * gate is worse than one that takes a moment longer.
 *
 * It costs nothing when a test passes, and a test that is genuinely broken
 * still fails — four seconds later.
 */
configure({ asyncUtilTimeout: 5000 })

const hasDom = typeof window !== 'undefined'

beforeEach(() => {
  forgetCsrfToken()
  setUnauthenticatedHandler(null)
})

afterEach(() => {
  vi.restoreAllMocks()

  if (hasDom) {
    cleanup()
    window.localStorage.clear()
    window.sessionStorage.clear()
  }
})
