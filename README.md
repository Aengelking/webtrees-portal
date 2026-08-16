# webtrees Member Portal — Phase 1

A read-only member portal for an existing webtrees installation.

webtrees stays exactly as it is: it remains the genealogy engine and back
office — GEDCOM import/export, the editor, charts, admin. The portal sits
alongside it for the majority of members, who only want to look at their own
record and find other members.

```
  Cloudflare Workers ── React SPA (static assets)
        │
        │  /api/*  via the Worker (same-origin reverse proxy)
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
| `module/tools/deploy-sftp.sh` | Uploads a directory to the webtrees host over SFTP, atomically. |
| `.github/workflows/deploy.yml` | Runs the tests, then that script. |
| `portal/` | The React app. |
| `portal/edge/` | The Cloudflare Worker that serves it and proxies `/api/*`. |

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
   | **Proxy secret** | Shared secret the Worker sends in `X-Portal-Proxy-Secret`. Leave empty for local development. |
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

### Deploying the module over SFTP

Step 1 above is a manual `rsync`. `.github/workflows/deploy.yml` does the same
thing over SFTP, after running the tests.

**Nothing is uploaded until the tests pass.** This deploys code that reads
living people's data; the privacy assertions are the reason to trust a
release, so there is no way to skip them.

#### Repository secrets

Settings → Secrets and variables → Actions:

| Secret | Value |
| --- | --- |
| `SFTP_HOST` | Hostname of the SFTP server. |
| `SFTP_PORT` | Optional. Defaults to 22. |
| `SFTP_USERNAME` | Login name. |
| `SFTP_PASSWORD` | The account's password. |
| `SFTP_KNOWN_HOSTS` | The server's host key — see below. |
| `SFTP_MODULE_PATH` | The module directory to replace — see below. |
| `SFTP_PORTAL_PATH` | Only if you also upload the SPA; see below. |

#### What goes in `SFTP_MODULE_PATH`

The path of the `portal_api` directory **as seen from the SFTP login**, which
on shared hosting is usually not the server's filesystem root. So typically
something like:

```
webtrees/modules_v4/portal_api
```

and not `/var/www/webtrees/modules_v4/portal_api`, unless the account really
does log in at `/`.

It must contain at least one `/`. A bare name like `portal_api` uploads into
the login directory itself rather than into webtrees' `modules_v4/`, where the
module would never be found; the script warns when the path has no directory
part. Connect with any SFTP client once and look at where you land, or use
`DRY_RUN=true`, which lists what it would change without uploading anything.

#### What goes in `SFTP_KNOWN_HOSTS`

The literal output of `ssh-keyscan` for your server — one or more lines in
`known_hosts` format:

```bash
ssh-keyscan -p 22 webtrees.example.org
```

```
webtrees.example.org ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI...
webtrees.example.org ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTI...
webtrees.example.org ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAB...
```

Paste **all** of the lines in. Multi-line secrets are fine; the script writes
the value verbatim to a temporary `known_hosts` file and points `ssh` at it.

Three things that catch people out:

* **The host string must match `SFTP_HOST` exactly.** `ssh` matches it
  literally, so scan the same name you put in `SFTP_HOST` — not the IP address
  of the same machine, and not another alias for it. A mismatch fails with
  `Host key verification failed` even when the key itself is right.
* **On a non-standard port**, pass `-p` so the entries come out bracketed,
  which is the form `ssh` looks for: `[webtrees.example.org]:2222 ssh-ed25519 …`
* **Check the fingerprint out of band.** `ssh-keyscan` trusts whatever answers
  at the moment you run it, so on an already-intercepted connection you would
  be pinning the attacker's key. Compare it against what the host publishes in
  its control panel:

  ```bash
  ssh-keyscan -p 22 webtrees.example.org 2>/dev/null | ssh-keygen -lf -
  ```

  If you have logged in to the server by hand before and checked the
  fingerprint then, `ssh-keygen -F webtrees.example.org` prints the entry from
  your own `~/.ssh/known_hosts` — a better source, because you have already
  vetted it.

This is public data: the server offers these keys to anyone who connects. The
secrecy is not the point, the pinning is. Do not put a private key or a
`SHA256:…` fingerprint here.

If `ssh-keyscan` prints the server banner and then an error such as
`choose_kex: unsupported KEX method sntrup761x25519-sha512@openssh.com`, the
problem is the local client, not the server: it advertised a key-exchange
method its own build cannot perform. `ssh-keyscan` takes no `-o` flag, but
`ssh` does, so steer around it and read the key back out afterwards:

```bash
ssh -o KexAlgorithms=curve25519-sha256 \
    -o StrictHostKeyChecking=accept-new \
    -p 22 YOUR_USER@webtrees.example.org exit

ssh-keygen -F webtrees.example.org
```

Drop the leading `# Host … found:` comment line. Hashed entries (`|1|…`) are
fine — `ssh` matches those too. Running the scan from a machine with a current
OpenSSH, or from a throwaway GitHub Actions step, works just as well.

If the hostname is load-balanced across several machines, their host keys may
differ, and pinning one will fail intermittently. Capture the key from a few
separate connections and put all the lines in the secret.

**It is required.** The script will not connect without it and offers no way to
turn host verification off. With password authentication that matters more than
usual: an intercepted session gives away the password itself, not just that one
connection.

The workflow installs `sshpass`, because `ssh` will not read a password from
anywhere but a terminal. The password is passed to it through the `SSHPASS`
environment variable, so it never appears in the process list or in the lftp
command file.

If you ever add key authentication, set `SFTP_PRIVATE_KEY` to the private key
and the script will use it in preference to the password — nothing else needs
to change. Worth doing eventually: an account that can write to `modules_v4/`
can install any code it likes, and a key cannot be guessed or reused from
another leak.

#### Running it

* **Automatically** — a push to `main` that touches `module/portal_api/**`.
* **By hand** — Actions → Deploy → *Run workflow*, choosing what to upload and
  whether to do a dry run. A dry run lists what would change and uploads
  nothing.

The `deploy` job runs in a GitHub environment called `production`. Add required
reviewers to it in the repository settings if you want a human to approve each
deployment.

#### How the upload avoids a broken site

The host is serving requests while the upload runs, so overwriting
`modules_v4/portal_api/` in place would leave a window where half the module is
new and half is old. Instead the script uploads to `portal_api.upload/` beside
it and swaps it in with two renames.

The staging and rollback directories have a dot in the name on purpose:
webtrees' `ModuleService` skips any directory under `modules_v4/` whose name
contains one, so a partial upload is never loaded as a module.

If the swap fails — usually a permissions problem — the live module is left
untouched and the site keeps serving the previous version. The upload stays in
`portal_api.upload/` and the job's log says so.

#### Running it locally

```bash
export SFTP_HOST=your.host SFTP_USERNAME=deploy
export SFTP_REMOTE_PATH=/var/www/webtrees/modules_v4/portal_api
export SFTP_KNOWN_HOSTS="$(ssh-keyscan -p 22 your.host)"
read -rs SFTP_PASSWORD && export SFTP_PASSWORD   # typed, not in shell history

DRY_RUN=true module/tools/deploy-sftp.sh module/portal_api   # look first
module/tools/deploy-sftp.sh module/portal_api                 # then upload
```

Needs `lftp`, `openssh-client` and `sshpass`.

#### Uploading the portal over SFTP instead of Cloudflare

Choosing `portal` or `both` in the manual run uploads `portal/dist` to
`SFTP_PORTAL_PATH`. That suits an installation that serves the SPA from the
same webspace as webtrees — one host, no Cloudflare, and `/api` is then already
same-origin, so no proxy is involved at all.

Two things to know if you go that way:

* `portal/public/.htaccess` provides the SPA fallback for Apache — the job
  `not_found_handling` does on Workers. On nginx you will need the equivalent
  `try_files $uri /index.html;`.
* Nothing proxies `/api/*` in this arrangement, and nothing needs to: webtrees
  is already on the same origin.
* The SPA expects to live at the domain root, because the API client asks for
  `/api/v1/…`. To serve it from a subdirectory, set Vite's `base` and adjust
  `BASE` in `portal/src/api/client.ts` to match.

### webtrees' own login is untouched

`/login` on the webtrees host still works exactly as before, for editors,
moderators and administrators — and as the way back in when the portal is
broken. The module adds routes under `/api/v1/`; it changes nothing else.

---

## Deploying the portal

The portal deploys to **Cloudflare Workers** with static assets, configured in
`portal/wrangler.jsonc`.

| Setting | Value |
| --- | --- |
| Root directory | `portal` |
| Build command | `npm run build` |
| Deploy command | `npx wrangler deploy` |
| Node version | 22 or later |

Or from a checkout: `cd portal && npm run deploy`.

### How a request is routed

`portal/edge/worker.ts` is the Worker. It does two things:

* **`/api/*`** is proxied to the webtrees host. This is what keeps everything
  same-origin: the session cookie is first-party, so the PHP module needs no
  CORS handling and no `SameSite=None`.
* **everything else** is a static asset, and
  `not_found_handling: "single-page-application"` turns an unmatched path into
  `index.html` so the client-side router can take it. Without that, refreshing
  on `/members` would 404.

Two details in `wrangler.jsonc` are load-bearing, and both are the kind of
thing that fails quietly:

* **There is no `_redirects` file, deliberately.** The usual SPA rule
  `/*  /index.html  200` is *rejected* by the Workers asset validator —
  it normalises `/index.html` back to `/`, which matches `/*` again, so the
  rule is a loop by construction. `not_found_handling` is the Workers way to
  say the same thing. (Cloudflare *Pages* still wants the `_redirects` file;
  see below.)
* **`run_worker_first: ["/api/*"]`.** Without it the SPA fallback would answer
  `/api/v1/me` with `index.html` before the Worker ever ran, and every API
  call would return HTML instead of JSON — a deploy that looks successful and
  a portal that does not work.

### Environment variables

Both are secrets, so set them with wrangler rather than committing them:

```bash
cd portal
npx wrangler secret put WEBTREES_ORIGIN       # https://webtrees.example.org
npx wrangler secret put PORTAL_PROXY_SECRET   # same value as the module setting
```

| Name | Required | Value |
| --- | --- | --- |
| `WEBTREES_ORIGIN` | yes | `https://webtrees.example.org` — scheme and host, no path. |
| `PORTAL_PROXY_SECRET` | recommended | A long random string. Set the *same* value as the module's **Proxy secret**. |

For `wrangler dev`, put the same names in `portal/.dev.vars`, which is
gitignored.

### Caching

Every API response carries `Cache-Control: private, no-store` from PHP, the
Worker sets it again, and the proxy fetch runs with `cacheTtl: 0`.
A cached authenticated response means one member sees another member's
relatives — that is a data breach, not a bug.

**Verify it with two accounts before letting anyone in.** Sign in as member A
in one browser, load `/members`, sign in as member B in another, and check
that B never sees A's data. Then check the response headers in the browser's
network tab: `cache-control: private, no-store` and `cf-cache-status` absent
or `DYNAMIC`.

### Preview deployments

Preview URLs must not point at production data. Give the preview environment
its own `WEBTREES_ORIGIN` pointing at a staging webtrees, or turn preview URLs
off for this Worker.

### If you ever move to Cloudflare Pages

`portal/functions/api/[[path]].ts` is the Pages entry point for the same
proxy, sharing its implementation with the Worker via `portal/edge/proxy.ts`
so the two cannot drift. Workers ignores it.

Moving back means creating a Pages project (root `portal`, build
`npm run build`, output `dist`) and restoring the SPA fallback that Workers
rejects:

```bash
printf '/*    /index.html   200\n' > portal/public/_redirects
```

There is no reason to do this today — Workers is where Cloudflare is putting
its effort, and it is what this repository is configured and tested for.

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
npm run typecheck   # tsc for the app and, separately, for the Worker
npm test            # Vitest: API client, contract, app behaviour
npm run build       # typecheck + production build
npm run test:e2e    # Playwright smoke path
```

The Playwright run builds the app, serves it with `vite preview` on
`127.0.0.1:4173` and stubs the API in the browser, so it needs no webtrees
host. When it fails in CI, the job uploads the Playwright report and traces as
an artifact called `playwright-report`. To run the same path against a real
deployment:

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
