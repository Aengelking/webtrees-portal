import { afterEach, beforeEach, vi } from 'vitest'
import { cleanup } from '@testing-library/react'
import { forgetCsrfToken, setUnauthenticatedHandler } from '../api/client'

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
