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
 *
 * **This number is half of a pair.** vitest's `testTimeout` governs the whole
 * test and has to stay comfortably above it, or the permission granted here is
 * not real: for a while both were five seconds, and a `findBy…` that needed
 * four left one second for everything else. See `vite.config.ts` and NOTES
 * §2.90 — raising one without the other is not a change.
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
