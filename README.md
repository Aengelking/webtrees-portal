# webtrees Member Portal — Phase 1

A read-only member portal for an existing webtrees installation.

webtrees stays exactly as it is: it remains the genealogy engine and back
office — GEDCOM import/export, the editor, charts, admin. The portal sits
alongside it for the majority of members, who only want to look at their own
record and find other members.

```
  Cloudflare Pages ── React SPA (static build)
        │
        │  /api/*  via Pages Function (same-origin reverse proxy)
        ▼
  webtrees host ── custom module "portal_api" ── webtrees core
                     PHP, JSON endpoints,          Auth, GedcomRecord,
                     session cookie auth,          privacy filtering,
                     portal_* tables               user accounts
```

One backend, one database, one user store. Portal members *are* webtrees
users; there is no second identity system.

## What is in this repository

| Path | What it is |
| --- | --- |
| `openapi.yaml` | The contract between the two halves. Written first, kept in step by `portal/src/api/contract.test.ts`. |
| `module/portal_api/` | The webtrees custom module. Copy this folder into `modules_v4/`. |
| `module/tests/` | PHPUnit tests, including the privacy acceptance criteria. Not shipped to the server. |
| `module/tools/setup-test-env.sh` | Creates a webtrees checkout for those tests to run against. |
| `portal/` | The React app, plus the Cloudflare Pages Function. |

## Scope

**Phase 1 is read-only.** There are no write endpoints. Editing arrives in
Phase 2 and will go through webtrees' pending-changes queue.

Endpoints: `GET /csrf`, `POST /session`, `DELETE /session`, `GET /me`,
`GET /individuals/{xref}`, `GET /members`, `GET /members/{id}`.

Screens: login, My profile, Members, member detail, Settings.

Charts (pedigree, fan, descendancy) are not implemented — every record links
out to webtrees for those.

---

## Installing the module

### Requirements

* webtrees 2.2.x — developed and tested against **2.2.1**
* PHP 8.3–8.4 (webtrees 2.2's own requirement; the handoff said 8.2+, which is
  no longer enough for webtrees itself — see `NOTES.md`)
* MySQL / MariaDB, or any database webtrees supports

### Steps

1. Copy `module/portal_api/` into the webtrees installation:

   ```bash
   rsync -a module/portal_api/ /path/to/webtrees/modules_v4/portal_api/
   ```

   The folder must be named `portal_api` — webtrees derives the module's
   internal name (`_portal_api_`) from it.

2. In webtrees, go to **Control panel → Modules → All modules** and make sure
   *Member portal API* is enabled. The `portal_member_profile` and
   `portal_login_attempt` tables are created automatically on the first
   request after that; there is no install script to run.

3. Open the module's settings (the wrench next to it in the module list) and
   set:

   | Setting | Meaning |
   | --- | --- |
   | **Family tree** | The one tree the portal serves. Leave empty to use the site's default tree. |
   | **Proxy secret** | Shared secret the Pages Function sends in `X-Portal-Proxy-Secret`. Leave empty for local development. |
   | **Failed attempts per IP address** | Default 30. `0` disables. |
   | **Failed attempts per username** | Default 5. `0` disables. |
   | **Time window (seconds)** | Default 900. |

### Module settings reference

Settings are stored in webtrees' `module_setting` table under the module name
`_portal_api_`. The schema version is in `site_setting` as
`PORTAL_API_SCHEMA_VERSION`.

### Linking a webtrees user to a member profile

Two separate links, and they do different jobs.

**1. Which individual record is "me"?** This is webtrees' own link, and the
portal only reads it.

*Control panel → Users → (edit a user) → Individual record* — pick the person
in the tree. Internally this is the per-tree user setting `gedcomid`. The
portal never copies it into its own tables: an XREF changes on re-import, so
it is not a key.

If it is not set, `/me` still works and simply returns `individual: null`; the
portal shows a plain-language "your entry in the family tree is missing"
message.

**2. Is this member in the directory?** This is the portal's own data, in
`portal_member_profile`. Phase 1 has no UI for changing it — that is Phase 2 —
so for now:

```sql
INSERT INTO portal_member_profile
    (wt_user_id, visible_in_directory, consent_recorded_at, created_at, updated_at)
VALUES
    (42, 1, NOW(), NOW(), NOW());
```

`display_name_override` is optional; when it is null the member's webtrees
real name is used. **Only insert a row with `visible_in_directory = 1` for
members who have actually agreed to be listed** — that column is a consent
record, and `consent_recorded_at` is when they gave it.

### webtrees' own login is untouched

`/login` on the webtrees host still works exactly as before, for editors,
moderators and administrators — and as the way back in when the portal is
broken. The module adds routes under `/api/v1/`; it changes nothing else.

---

## Deploying the portal

### Cloudflare Pages project

| Setting | Value |
| --- | --- |
| Build command | `npm run build` |
| Build output directory | `dist` |
| Root directory | `portal` |
| Node version | 20 or later |

`portal/functions/api/[[path]].ts` is picked up automatically as a Pages
Function and proxies `/api/*` to the webtrees host. This is what keeps
everything same-origin: the session cookie is first-party, so the PHP module
needs no CORS handling and no `SameSite=None`.

`portal/public/_redirects` contains `/* /index.html 200`, the SPA fallback.
Without it, refreshing on `/members` returns a 404.

### Environment variables

Set these on the Pages project (Settings → Environment variables), for each
environment separately:

| Name | Required | Value |
| --- | --- | --- |
| `WEBTREES_ORIGIN` | yes | `https://webtrees.example.org` — scheme and host, no path. |
| `PORTAL_PROXY_SECRET` | recommended | A long random string. Set the *same* value as the module's **Proxy secret**. Store it as a secret, not a plain variable. |

### Caching

Every API response carries `Cache-Control: private, no-store` from PHP, the
Pages Function sets it again, and the proxy fetch runs with `cacheTtl: 0`.
A cached authenticated response means one member sees another member's
relatives — that is a data breach, not a bug.

**Verify it with two accounts before letting anyone in.** Sign in as member A
in one browser, load `/members`, sign in as member B in another, and check
that B never sees A's data. Then check the response headers in the browser's
network tab: `cache-control: private, no-store` and `cf-cache-status` absent
or `DYNAMIC`.

### Preview deployments

Preview deployments must not point at production data. Either point
`WEBTREES_ORIGIN` for the preview environment at a staging webtrees, or turn
preview deployments off in the Pages project settings.

---

## Local development

### The API

Run webtrees locally however you normally do (PHP's built-in server is enough)
with the module in `modules_v4/`, and leave the module's **Proxy secret**
empty so no header is required.

```bash
cd /path/to/webtrees && php -S localhost:8080
```

### The portal

```bash
cd portal
npm install
VITE_API_TARGET=http://localhost:8080 npm run dev
```

Vite proxies `/api` onto the webtrees host and rewrites the cookie domain, so
the browser still sees a single origin — the same arrangement the Pages
Function creates in production.

### Frontend checks

```bash
cd portal
npm run typecheck   # tsc for the app and, separately, for the Pages Function
npm test            # Vitest: API client, contract, app behaviour
npm run build       # typecheck + production build
npm run test:e2e    # Playwright smoke path
```

The Playwright run builds the app, serves it with `vite preview` and stubs the
API in the browser, so it needs no webtrees host. To run the same path against
a real deployment:

```bash
E2E_BASE_URL=https://portal.example.org \
E2E_USERNAME=... E2E_PASSWORD=... \
npm run test:e2e
```

### Backend checks

The module's tests need a webtrees to boot inside, because they exercise
webtrees' real privacy code rather than a stand-in for it.

```bash
module/tools/setup-test-env.sh      # clones webtrees into module/.webtrees
cd module
.webtrees/vendor/bin/phpunit
```

`WEBTREES_DIR=/path/to/existing/webtrees` uses a checkout you already have
instead. The tests run against an in-memory SQLite database and import a small
GEDCOM fixture; they touch nothing on disk.

---

## What the tests prove

**Privacy** (`module/tests/PrivacyTest.php`) — with two accounts at different
access levels plus an unauthenticated caller:

* an individual hidden from account A is absent from *every* A response —
  their own record, nested relative lists, the directory, and the raw JSON;
* a hidden record and a non-existent record produce byte-identical 404s;
* a fact carrying `RESN confidential` does not appear for a member and does
  for a manager;
* unauthenticated requests get a 401 whose body contains no record data;
* every response, including errors, carries `Cache-Control: private, no-store`.

**Session** (`module/tests/SessionTest.php`) — wrong password, unknown user,
unverified email, unapproved account and rate limiting all produce the same
401 body; the rate limiter refuses even a correct password once tripped; CSRF
is required on every unsafe method; the proxy secret is enforced when set.

**Frontend** (`portal/src/**/*.test.ts*`) — the client attaches CSRF only to
unsafe requests and retries once on a stale token; a 401 anywhere resets the
app to the login screen; nothing but the language preference reaches browser
storage.

## Open questions

`NOTES.md` lists what was decided, what was assumed, and what still needs an
answer from you — including the tree question, self-registration, password
reset and social login.
