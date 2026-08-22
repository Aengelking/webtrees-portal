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
  it('documents exactly the endpoints the portal uses', () => {
    expect(specPaths()).toEqual([
      '/connections',
      '/connections/{id}',
      '/conversations',
      '/conversations/{id}',
      '/conversations/{id}/messages',
      '/conversations/{id}/messages/{message}',
      '/csrf',
      '/health',
      '/individuals/{xref}',
      '/individuals/{xref}/ancestors',
      '/invitation/accept',
      '/invitation/preview',
      '/invitations',
      '/invitations/{id}',
      '/me',
      '/me/connection-code',
      '/me/connection-link',
      '/me/connection-links/{id}',
      '/me/contact',
      '/me/individual',
      '/me/profile',
      '/media/{xref}/{fact}/{size}',
      '/members',
      '/members/{id}',
      '/members/{id}/message',
      '/messages',
      '/messages/{id}',
      '/messages/{id}/reply',
      '/password/request',
      '/password/reset',
      '/push',
      '/session',
    ])
  })

  it('has a client call for every documented path', () => {
    const templates: Record<string, string> = {
      '/connections': "'/connections'",
      '/connections/{id}': '`/connections/${',
      '/conversations': "'/conversations'",
      '/conversations/{id}': '`/conversations/${',
      '/conversations/{id}/messages': '}/messages`',
      '/conversations/{id}/messages/{message}': '}/messages/${',
      '/csrf': "'/csrf'",
      '/health': "'/health'",
      '/session': "'/session'",
      '/me': "'/me'",
      '/members': "'/members'",
      '/individuals/{xref}': '`/individuals/${',
      '/individuals/{xref}/ancestors': '}/ancestors`',
      '/invitation/preview': "'/invitation/preview'",
      '/invitation/accept': "'/invitation/accept'",
      '/invitations': "'/invitations'",
      '/invitations/{id}': '`/invitations/${',
      // Served straight into <img src>, so the client has no fetch for it —
      // the paths come from the API in Photo.thumbnail_url / image_url.
      '/media/{xref}/{fact}/{size}': '',
      '/members/{id}': '`/members/${',
      '/members/{id}/message': '}/message`',
      '/me/connection-code': "'/me/connection-code'",
      '/me/connection-link': "'/me/connection-link'",
      '/me/connection-links/{id}': '`/me/connection-links/${',
      '/me/contact': "'/me/contact'",
      '/messages': "'/messages'",
      '/messages/{id}': '`/messages/${',
      '/messages/{id}/reply': '}/reply`',
      '/me/profile': "'/me/profile'",
      '/me/individual': "'/me/individual'",
      '/password/request': "'/password/request'",
      '/push': "'/push'",
      '/password/reset': "'/password/reset'",
    }

    for (const path of specPaths()) {
      expect(client, `no client call for ${path}`).toContain(templates[path] as string)
    }
  })

  it('agrees on the error codes the UI branches on', () => {
    // ErrorNotice picks a sentence from the code, so a code the spec knows
    // and the client's union does not would render the fallback.
    // Scoped to the Error schema's enum. Matching every 12-space list item in
    // the file also picks up `required:` entries from other schemas.
    const error_schema = spec.split(/^ {4}Error:$/m)[1]?.split(/^ {4}\w/m)[0] ?? ''
    const enum_block = error_schema.split(/^ {10}enum:$/m)[1] ?? ''
    const spec_codes = [...enum_block.matchAll(/^ {12}- (\w+)$/gm)].map((match) => match[1] as string)
    const client_types = readFileSync(resolve(process.cwd(), 'src/api/types.ts'), 'utf-8')

    expect(spec_codes.length).toBeGreaterThan(5)

    for (const code of spec_codes) {
      expect(client_types, `ApiErrorCode is missing ${code}`).toContain(`'${code}'`)
    }
  })

  it('pins the base path to the same-origin proxy route', () => {
    expect(client).toContain("const BASE = '/api/v1'")
    expect(spec).toContain('- url: /api/v1')
  })
})
