# webtrees Member Portal

A member portal for an existing webtrees installation.

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

**Phases 1 to 5 are built.** Members can be invited, read the tree within
their privacy level, change their own portal settings, propose changes to
their own record, and reset their own password. The social graph — messages
between members, shared contact details — is not in scope.

**No edit writes to the tree.** A member's change goes to webtrees' pending
changes list with a `CHAN` entry naming them, and an editor approves it in
webtrees exactly as they would any other edit.

Endpoints: `GET /csrf`, `POST|DELETE /session`, `GET /me`,
`PATCH /me/profile`, `PUT /me/individual`, `GET /individuals/{xref}`,
`GET /members`, `GET /members/{id}`, `GET /individuals/{xref}/ancestors`,
`GET /media/{xref}/{fact}/{size}`, `POST /password/request`,
`POST /password/reset`, `POST /invitation/preview`,
`POST /invitation/accept`.

Screens: login, accept an invitation, forgotten password, set a new password,
My profile, edit my details, person, ancestors, Members, member detail,
Settings.

What a member may change about themselves: given names, surname, date and
place of birth, occupation, and contact details (address, email, telephone,
website). Contact details are published on the member's *own* record only,
never on anyone else's.

Photographs from webtrees are shown: a face beside every name, a gallery on a
person's page, full size on a tap. Read-only — uploading is not built.

The tree can be walked in the portal: every relative is a link, four
generations of ancestors are one request, and a record says how the signed-in
member is related to it. Drawn charts (fan, descendancy) are still webtrees'
job, and every record still links out for them.

Members get in by invitation. An administrator picks the person out of the
tree and gets a one-time link to send; the invitee chooses their own username
and password. Nobody registers who was not asked.

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
   | **Portal address** | Where members reach the portal, e.g. `https://portal.example.org`. Used for the link in password reset emails, which must point at the portal rather than at webtrees. Leave empty to switch password resets off. |
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
`portal_member_profile`, and **members set it themselves** under Settings.
There is nothing for an administrator to do.

Nobody is listed until they ask to be. `consent_recorded_at` records when they
did, and is cleared again if they withdraw — consent that has been taken back
should not leave a note saying it was given.

`display_name_override` is the name shown in the directory; when it is empty
the member's webtrees real name is used.

### Inviting a member

*Control panel → Modules → Member portal API → Invitations.*

1. Pick the person in the family tree. The new account will be linked to that
   record, so the portal can show them their own page.
2. Optionally note the email address you are sending to. It prefills their
   form; it is not a password and it is not checked against anything.
3. **Create an invitation.** The link appears once. Copy it and send it the
   way you would normally reach that person — this module does not send email,
   deliberately: guessing the wrong address means mailing a working credential
   to a stranger.

The invitee opens the link, sees who the invitation is for, and chooses a
username and password. The account is created verified, approved, with the
role *member* on the portal's tree, and already linked to the record. They are
signed in immediately — there is nothing else for you to approve.

Some details worth knowing:

* **The link is shown once.** Only a hash of it is stored, so it cannot be
  looked up later. If you lose it, withdraw the invitation and issue another.
* **It works once**, and expires after the number of days set in the module
  preferences (14 by default, 90 at most).
* **Withdraw** removes an unspent invitation immediately.
* **Accounts with no linked record** is listed on the same page. An account in
  that list can sign in but the portal has nothing of its own to show it —
  usually an account created by hand before invitations existed, or one whose
  record moved in a re-import. Open it in webtrees and set the individual
  record.

If webtrees' own registration page is switched on
(*Control panel → Website preferences → Allow visitors to request a new user
account*), it is a second way in that this module knows nothing about. With
invitations in place there is no reason to leave it on.

### Trying the write path safely

A second family tree in the same webtrees installation is a sound way to
rehearse this, with three things worth knowing first.

**The module serves exactly one tree.** *Family tree* in the module's settings
is the only tree it will ever read or write — `PortalTreeService` refuses to
guess. So pointing it at a test tree isolates the live one completely. The
same setting is why you cannot rehearse and serve members at the same time
from one installation: while it points at the test tree, that is what the
portal shows everyone.

**Pending changes are per tree.** Each row in webtrees' `change` table carries
its tree, so a proposal made against the test tree appears in the test tree's
pending list and nowhere else.

**Check `auto_accept` on the account you test with.** webtrees applies an edit
immediately, with no pending change, when the *acting* user has that
preference — editors and administrators usually do, members do not. Testing
with your own administrator account therefore does not exercise the queue at
all. The API says which happened (`status: applied` rather than
`pending_approval`) and the portal words it accordingly, so you will not be
misled, but you also will not have tested what you meant to. Use a plain
member account.

One thing a second tree does **not** isolate: `portal_member_profile` is keyed
on the webtrees user, not on a tree, so directory visibility and display-name
choices are shared. Harmless for a rehearsal, worth knowing before you read
anything into it.

If the test tree is a copy of the real one, remember it now holds living
people's data under whatever privacy settings the copy inherited. Check
*Privacy* on the new tree before giving anyone access to it.

### Approving what members propose

A member's edit appears in webtrees under **Control panel → Pending changes**,
attributed to them. Approving or rejecting it is the ordinary webtrees flow.

Until someone approves it, the member sees the previous version of their
record with a note saying their change is being reviewed, and the portal will
not let them submit a second one. That is deliberate: a member cannot see
pending changes, so a second edit would be built from the approved record and
would silently discard the first when both were applied.

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
environment variable, so it never appears in the process list or in the sftp
batch file.

If you ever add key authentication, set `SFTP_PRIVATE_KEY` to the private key
and the script will use it in preference to the password — nothing else needs
to change. Worth doing eventually: an account that can write to `modules_v4/`
can install any code it likes, and a key cannot be guessed or reused from
another leak.

#### Running it

* **Automatically** — a push to `main` that touches `module/portal_api/**`.
* **By hand** — Actions → Deploy → *Run workflow*, choosing what to upload and
  whether to do a dry run. A dry run prints the batch of sftp commands the real
  run would send and contacts nothing.

The `deploy` job runs in a GitHub environment called `production`. Add required
reviewers to it in the repository settings if you want a human to approve each
deployment.

The tests are split by what is being shipped: a module deployment runs the
module's PHPUnit suite — the privacy assertions — and skips the portal's build,
unit tests and browser smoke path, which are only relevant when the portal is
being uploaded. A push never uploads the portal, so a push-triggered deployment
waits for the fast half only.

There is a third box, **Skip the test suite**, for when the thing being
debugged is the upload itself and waiting several minutes for a green suite
before each attempt is dead time. It is deliberately awkward to misuse:

* only a hand-started run can set it — a push to `main` always runs the tests,
  so nothing reaches the server untested by accident;
* it refuses to upload the portal, because the portal is *built* by the test
  job and there would be nothing to upload;
* the deploy job posts a warning on the run saying it uploaded untested code.

Untick it as soon as the upload works. This deploys code that reads living
people's data, and the privacy tests are the reason to trust a release.

The faster loop while debugging is not CI at all — run the script against the
server from a terminal (see *Running it locally* below). Same script, same
result, no waiting for a runner.

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

#### Why the upload is plain OpenSSH `sftp`, in exactly two connections

Two separate problems produced the same red job, and both are worth knowing
about.

**One: lftp.** Deployments kept dying with

```
mirror: Fatal error: max-retries exceeded (Connection closed by 81.169.145.126 port 22)
```

The same host accepts an upload from OpenSSH's own `sftp` over exactly the same
`ssh` and `sshpass`, so the connection was never the problem. lftp keeps many
requests in flight and opens a second connection when it feels like it; this
server tolerates neither. `sftp` asks for one thing at a time.

A middle version kept lftp for the recursive deletes. That was worse than
useless: those deletes ran through a helper that ignored failures, so on a host
lftp could not talk to at all the old version was silently never moved aside —
and the run then failed at the *next* step, reporting a permissions problem
that did not exist.

**Two: the number of connections.** After lftp was gone the log looked like

```
==> Uploading to ....upload
Connection closed by 81.169.145.126 port 22
```

with no sftp command echoed before it. `sftp -b` echoes each command as it runs
it, so a session that prints none died at the door, not mid-transfer. Shared
hosting rate-limits bursts of SSH connections, and a CI runner looks like a
burst; once that trips, everything after it is refused too.

So a run now opens **exactly two** sessions: one that reads the manifests it
needs, and one that does the whole deployment — clean, upload, swap, tidy up.
That is the same budget as the deployment from another project that works
against this server. An earlier version of this script needed nine.

The second session is written so that repeating it is safe: deletes and mkdirs
are tolerant, puts overwrite, and the renames come last. Running it again from
the top reaches the same end state, which is what makes a long retry delay a
real remedy — 30s, then 90s, then 270s, rather than a few seconds that outlast
nothing.

Three more details worth knowing:

* **Recursive delete comes from a manifest.** `sftp` has no recursive `rm`, so
  every upload writes `.portal-deploy-manifest` listing its contents *before*
  it writes anything else. A later run reads that and deletes exactly those
  paths. No directory listing to parse, no recursion, no second tool.
* **Every path is listed explicitly**, rather than left to `put -r` and a shell
  glob. A glob skips dotfiles and `portal/dist` contains an `.htaccess`. A path
  containing `*`, `?` or `[` stops the deployment, because `put` expands globs
  in the local path with no way to turn that off — this repository has one
  (`portal/functions/api/[[path]].ts`), which is how the case is known.
* **A rollback directory is renamed aside before it is emptied.** Renaming
  always frees the name; deleting might not. Without that order, one
  undeletable rollback directory would block the swap on every future
  deployment, permanently.

If a run still fails at "could not open an SFTP session at all", the message
lists what to check. The quickest test is to connect by hand:

```bash
sftp -P 22 "$SFTP_USERNAME@$SFTP_HOST"
```

If that works from a laptop but not from CI, the host is turning the runner
away; raise the repository variables `SFTP_RETRY_DELAY` and
`SFTP_MAX_ATTEMPTS` (Settings → Secrets and variables → Actions → Variables),
or just re-run later. Re-running is always safe.

The first run after this change is the untidy one: the live module was uploaded
by the old script and carries no manifest, so cleanup falls back to the current
file list and may leave a `portal_api.previous.orphan-…` directory behind. It
has a dot in its name, so webtrees ignores it; delete it by hand whenever.

#### Running it locally

```bash
export SFTP_HOST=your.host SFTP_USERNAME=deploy
export SFTP_REMOTE_PATH=/var/www/webtrees/modules_v4/portal_api
export SFTP_KNOWN_HOSTS="$(ssh-keyscan -p 22 your.host)"
read -rs SFTP_PASSWORD && export SFTP_PASSWORD   # typed, not in shell history

DRY_RUN=true module/tools/deploy-sftp.sh module/portal_api   # look first
module/tools/deploy-sftp.sh module/portal_api                 # then upload
```

Needs `openssh-client` and `sshpass`. `DRY_RUN=true` prints the exact batch of
sftp commands the real run would send and contacts nothing.

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

**A dashboard "Text" variable will not survive.** `wrangler.jsonc` is the
source of truth for plaintext variables, so the next deploy — including an
automatic Workers Build triggered by a push to this repository — removes
anything not listed there. A `WEBTREES_ORIGIN` typed into the dashboard as
Text therefore disappears on its own, seemingly at random.

Two things that do persist:

* **Non-secret settings in `wrangler.jsonc`.** `WEBTREES_ORIGIN` and
  `WEBTREES_UGLY_URLS` are not sensitive — a hostname and a boolean — so
  uncomment the `vars` block there and commit them.
* **Encrypted secrets.** Set with `wrangler secret put`, or by choosing
  *Secret* rather than *Text* in the dashboard. These are preserved across
  deploys.

```bash
cd portal
npx wrangler secret put PORTAL_PROXY_SECRET   # same value as the module setting
```

| Name | Required | Value |
| --- | --- | --- |
| `WEBTREES_ORIGIN` | yes | `https://webtrees.example.org` — scheme and host, no path. |
| `PORTAL_PROXY_SECRET` | recommended | A long random string. Set the *same* value as the module's **Proxy secret**. |
| `WEBTREES_UGLY_URLS` | no | Defaults to `true`, which is what a stock webtrees needs. Set to `false` only if URL rewriting is configured — see below. |

#### Does your webtrees have pretty URLs?

Almost certainly not, and the default assumes it does not.

**webtrees ships no rewrite rules.** The only `.htaccess` in the distribution
is a deny-all for `data/`. And `rewrite_urls` in `data/config.ini.php` is off
unless someone turned it on. So on an ordinary install nothing maps a path like
`/api/v1/csrf` onto `index.php`: the webserver looks for a file of that name,
does not find one, and returns its own 404 without PHP ever running.

The Worker therefore addresses webtrees as `/index.php?route=/api/v1/csrf` by
default, which needs no server configuration at all.

Check which one you have by opening webtrees and reading the address bar:

* `https://host/index.php?route=/tree/mytree/…` — the normal case. Leave
  `WEBTREES_UGLY_URLS` unset.
* `https://host/tree/mytree/individual/X1` — rewriting is configured. Set
  `WEBTREES_UGLY_URLS=false`.

Getting it backwards is visible either way. Wrongly `false` gives a bare
webserver 404 with no PHP headers and no cookie. Wrongly unset on a rewriting
install gives a `308` redirect from webtrees to the pretty form, which points
at the webtrees host and so leaves the portal's origin — taking the session
cookie out of scope.

For `wrangler dev`, put the same names in `portal/.dev.vars`, which is
gitignored.

### Cookies

webtrees sets its session cookie for its *own* hostname. The Worker rewrites
each `Set-Cookie` on the way back — dropping `Domain` so the cookie is
host-only for the portal's origin, and forcing `Path=/` — while leaving
`HttpOnly`, `Secure` and `SameSite` alone.

Without that the browser rejects the cookie: login returns 200 with a valid
body, nothing is stored, and the next request is a 401 that bounces the member
back to the login screen. If you ever see "signing in appears to work but I am
immediately signed out again", this is the first thing to check.

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

If you point `WEBTREES_DIR` at a *git checkout* rather than a released ZIP, run
`php index.php compile-po-files` in it once. A checkout ships only the `.po`
translation sources, and webtrees falls back to untranslated English without
the compiled `messages.php` files — silently, which makes the language tests
fail for a reason that has nothing to do with the module.

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

**Photographs** (`module/tests/PhotoTest.php`, `portal/src/Photos.test.tsx`,
`portal/edge/proxy.test.ts`) — a confidential picture is absent from the
record and is not served if asked for by name; images load from the portal's
own origin, never webtrees'; a photograph may be kept by a browser and by
nothing else, and webtrees' own `public, max-age=31536000` is refused at the
proxy.

**The tree** (`module/tests/TreeTest.php`, `portal/src/Tree.test.tsx`) — a
confidential ancestor is absent from the pedigree and the walk stops there
rather than reaching around them; a relationship is never named through
someone the member may not see, though a manager who can see the whole path is
told; a hidden root and a missing one give byte-identical 404s.

**Names** (`module/tests/NameDecorationTest.php`) — a module that decorates
`Individual::fullName()` (the Vesta "Classic Look & Feel" badge, for one) does
not leak that decoration into the API's `name` field, in a record or in a
relative list. The reference number behind that badge is published in
`references` instead, and a confidential one is not published at all.

**Language** (`module/tests/LanguageTest.php`, `portal/src/Language.test.tsx`) —
fact labels and written-out dates come back in the language the request asked
for; `Accept-Language` goes out on every request; switching language in the
portal refetches rather than leaving English labels on a German screen; an
unavailable language changes nothing rather than failing.

**Invitations** (`module/tests/InvitationTest.php`,
`portal/src/Invitation.test.tsx`) — the raw token appears in no column of
`portal_invitation`; an invitation is redeemable once, and a second attempt
creates no account; expired, withdrawn and unknown tokens produce identical
refusals; the preview discloses no XREF and reads nothing from the family
tree; the new account is `member`, is not an administrator and cannot accept
its own edits; a taken username, a taken address and a short password are all
refused *without* burning the invitation. On the client the token travels in a
`POST` body and never in a URL, and a dead link says so instead of showing a
form that cannot work.

**Module actions** (`module/tests/ConfigurationTest.php`) — every action this
module exposes through webtrees' `/module/{name}/{action}` route has "Admin"
in its name, which is the only thing that restricts it to administrators.

**Frontend** (`portal/src/**/*.test.ts*`) — the client attaches CSRF only to
unsafe requests and retries once on a stale token; a 401 anywhere resets the
app to the login screen; nothing but the language preference reaches browser
storage.

## Troubleshooting

### The API returns 503

Only two places in the stack produce one, and the `message` in the JSON body
says which:

```bash
curl -i https://your-portal-host/api/v1/csrf
```

| `message` | Meaning | Fix |
| --- | --- | --- |
| `WEBTREES_ORIGIN is not set on this deployment.` | The Cloudflare Worker has no webtrees host to proxy to. | `cd portal && npx wrangler secret put WEBTREES_ORIGIN` |
| `The member portal is not configured correctly…` | The module cannot resolve a family tree. | Control panel → Modules → *Member portal API* → **Family tree** |

The second case deliberately tells the member nothing more, so the reason goes
to the server's error log instead — look for a line beginning
`portal_api: cannot serve any tree`. It names the configured tree and the trees
that actually exist.

The module refuses rather than falling back to another tree, on purpose: a
mistyped tree name must not quietly serve a different family's records.

If the body is HTML rather than JSON, the 503 is not from the portal at all —
check webtrees' maintenance mode (`data/offline.txt` on the host) and the
webserver.

### Every endpoint returns 302

webtrees does not answer an unmatched `GET` with a 404. Its `NoRouteFound`
middleware **redirects to the home page**, so a 302 means *no route matched* —
not that something is broken downstream.

Two causes:

1. **`WEBTREES_UGLY_URLS=false` on an install without URL rewriting** — the
   request reaches webtrees with an empty path. See above.
2. **The module is not installed or not enabled**, so its routes were never
   registered.

Both look identical from outside. To tell them apart, ask the webtrees host
directly for the ugly form:

```bash
curl -i 'https://your-webtrees-host/index.php?route=/api/v1/csrf'
```

| Result | Meaning |
| --- | --- |
| `200` with `{"csrf_token":…}` | The module is fine. Leave `WEBTREES_UGLY_URLS` unset. |
| `403 proxy_secret_invalid` | Also fine — the module just wants its proxy secret. |
| `302` to the home page | webtrees is running but has no such route: the module is not enabled. |
| `404` from Apache, no PHP headers | webtrees is not at that URL at all — check where it actually lives. |

(A `POST` to an unmatched route does return 404, which is why only the `GET`
endpoints show this.)

### The API returns 404 for every endpoint

Two likely causes, in order.

**Pretty URLs are off.** See *Does your webtrees have pretty URLs?* above. If
webtrees' own address bar shows `index.php?route=…`, set
`WEBTREES_UGLY_URLS=true` on the Worker.

**The module is not installed or not enabled.** Check that the folder on the
host is named exactly `portal_api` and sits inside webtrees' `modules_v4/` —
not beside it, and not in the SFTP login directory — then Control panel →
Modules → All modules.

### Which layer is broken?

Test the two halves separately rather than guessing. Against the webtrees host
directly, bypassing Cloudflare entirely:

```bash
curl -i https://your-webtrees-host/api/v1/csrf
# or, if pretty URLs are off:
curl -i 'https://your-webtrees-host/index.php?route=/api/v1/csrf'
```

| Result | Meaning |
| --- | --- |
| `200` with `{"csrf_token":…}` | The module is installed and configured. The problem is the Worker. |
| `403 proxy_secret_invalid` | Also fine — the module is running and enforcing its proxy secret. |
| `503 not_configured` | Installed, but no family tree is configured. |
| `404`, HTML | Not installed, not enabled, or pretty URLs are off. |

Then the same path through the Worker:

```bash
curl -i https://your-worker.your-subdomain.workers.dev/api/v1/csrf
```

---

## Open questions

`NOTES.md` lists what was decided, what was assumed, and what still needs an
answer from you — including the tree question and social login. Registration
and password reset are answered there now, in §1.3 and §1.4.
