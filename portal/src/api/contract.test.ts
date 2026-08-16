// @vitest-environment node
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * openapi.yaml is the contract, and it is easy for it to drift out of step
 * with the client by one forgotten endpoint. This does not validate schemas —
 * it checks the cheapest thing that actually goes wrong.
 */
const spec = readFileSync(resolve(process.cwd(), '../openapi.yaml'), 'utf-8')

function specPaths(): string[] {
  const body = spec.split(/^paths:$/m)[1]?.split(/^components:$/m)[0] ?? ''

  return [...body.matchAll(/^ {2}(\/\S*):$/gm)].map((match) => match[1] as string).sort()
}

const client = readFileSync(resolve(process.cwd(), 'src/api/client.ts'), 'utf-8')

describe('openapi.yaml and the API client agree', () => {
  it('documents exactly the endpoints Phase 1 uses', () => {
    expect(specPaths()).toEqual([
      '/csrf',
      '/individuals/{xref}',
      '/me',
      '/members',
      '/members/{id}',
      '/session',
    ])
  })

  it('has a client call for every documented path', () => {
    const templates: Record<string, string> = {
      '/csrf': "'/csrf'",
      '/session': "'/session'",
      '/me': "'/me'",
      '/members': "'/members'",
      '/individuals/{xref}': '`/individuals/${',
      '/members/{id}': '`/members/${',
    }

    for (const path of specPaths()) {
      expect(client, `no client call for ${path}`).toContain(templates[path] as string)
    }
  })

  it('pins the base path to the same-origin proxy route', () => {
    expect(client).toContain("const BASE = '/api/v1'")
    expect(spec).toContain('- url: /api/v1')
  })
})
