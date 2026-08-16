import type { Page, Route } from '@playwright/test'

/**
 * Enough of the API to walk the smoke path, matching openapi.yaml.
 *
 * When E2E_BASE_URL is set the run is pointed at a real deployment and these
 * are not installed, so the same spec exercises the real backend.
 */

const ANNA = {
  xref: 'X1',
  name: 'Anna Beispiel',
  sex: 'F',
  is_deceased: false,
  lifespan: '1985–',
  name_alternative: null,
  birth: {
    tag: 'INDI:BIRT',
    label: 'Geburt',
    value: null,
    date: { display: '12. März 1985', gedcom: '12 MAR 1985', year: 1985 },
    place: 'Hannover, Niedersachsen, Deutschland',
  },
  death: null,
  events: [
    {
      tag: 'INDI:BIRT',
      label: 'Geburt',
      value: null,
      date: { display: '12. März 1985', gedcom: '12 MAR 1985', year: 1985 },
      place: 'Hannover, Niedersachsen, Deutschland',
    },
    { tag: 'INDI:OCCU', label: 'Beruf', value: 'Tischlerin', date: null, place: null },
  ],
  parents: [
    { xref: 'X2', name: 'Bertha Beispiel', sex: 'F', is_deceased: true, lifespan: '1889–1976' },
  ],
  siblings: [
    { xref: 'X4', name: 'Dieter Beispiel', sex: 'M', is_deceased: false, lifespan: '1990–' },
  ],
  spouses: [],
  children: [],
  webtrees_url: 'https://webtrees.example.org/tree/portal/individual/X1',
}

const ME = {
  user: {
    id: 1,
    username: 'anna',
    real_name: 'Anna Beispiel',
    email: 'anna@example.test',
    language: 'de',
    role: 'member',
  },
  profile: {
    id: 1,
    visible_in_directory: true,
    display_name_override: null,
    consent_recorded_at: '2026-01-01 00:00:00',
  },
  individual: ANNA,
  tree: { name: 'portal', title: 'Familie Beispiel' },
  csrf_token: 'token-1',
}

const MEMBERS = [
  { id: 1, display_name: 'Anna Beispiel', individual: refOf(ANNA) },
  {
    id: 2,
    display_name: 'Dieter Beispiel',
    individual: { xref: 'X4', name: 'Dieter Beispiel', sex: 'M', is_deceased: false, lifespan: '1990–' },
  },
  { id: 3, display_name: 'Nora Ohnesatz', individual: null },
]

function refOf(individual: typeof ANNA) {
  return {
    xref: individual.xref,
    name: individual.name,
    sex: individual.sex,
    is_deceased: individual.is_deceased,
    lifespan: individual.lifespan,
  }
}

function json(route: Route, body: unknown, status = 200): Promise<void> {
  return route.fulfill({
    status,
    contentType: 'application/json',
    headers: { 'Cache-Control': 'private, no-store' },
    body: JSON.stringify(body),
  })
}

export async function stubApi(page: Page): Promise<void> {
  let signedIn = false

  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace('/api/v1', '')
    const method = route.request().method()

    if (path === '/csrf') {
      return json(route, { csrf_token: 'token-1' })
    }

    if (path === '/session' && method === 'POST') {
      const body = route.request().postDataJSON() as { username?: string; password?: string }

      if (body.username === 'anna' && body.password === 'geheim') {
        signedIn = true
        return json(route, ME)
      }

      return json(
        route,
        { error: 'invalid_credentials', message: 'The username or password is incorrect.' },
        401,
      )
    }

    if (path === '/session' && method === 'DELETE') {
      signedIn = false
      return json(route, { csrf_token: 'token-2' })
    }

    if (!signedIn) {
      return json(route, { error: 'unauthenticated', message: 'Please sign in.' }, 401)
    }

    if (path === '/me') {
      return json(route, ME)
    }

    if (path === '/members') {
      const q = (url.searchParams.get('q') ?? '').toLowerCase()
      const items = MEMBERS.filter((member) => member.display_name.toLowerCase().includes(q))

      return json(route, { items, total: items.length, page: 1, per_page: 25 })
    }

    if (path === '/members/1') {
      return json(route, { ...MEMBERS[0], individual_detail: ANNA })
    }

    if (path === '/members/3') {
      return json(route, { ...MEMBERS[2], individual_detail: null })
    }

    return json(route, { error: 'not_found', message: 'This item does not exist.' }, 404)
  })
}
