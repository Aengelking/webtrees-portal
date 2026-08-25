# NOTES

Decisions taken, things guessed, and the questions that are still yours to
answer. Written for the next person to open this repository, including you in
six months.

---

## 1. Open questions — raised, not resolved

These are §12 of the handoff, plus what turned up while building.

### 1.1 The exact webtrees and PHP version on the target host

**Answered, and it mattered.** The host runs **2.2.6**; the suite now runs
against 2.2.6 too, and `setup-test-env.sh` defaults to it.

Built originally against 2.2.1. Everything the module uses is webtrees'
documented custom-module surface (`AbstractModule`, `ModuleCustomInterface`,
`Registry::routeFactory()`, `GedcomRecord::canShow()`,
`MigrationService::updateSchema()`), which is stable across 2.2.x — but
internals do shift between minors, and in 2.2.6 they did: `Tree` went from a
three-argument constructor to nine, its title and members-only flag moved out
of `gedcom_setting` into columns of `gedcom`, and `TimeoutService` grew an
argument. That reached production as a fatal on one endpoint; see §2.70.

So: **check the host's version before installing**, run
`module/tools/setup-test-env.sh <version>` against it, and treat a suite that
only passes on one release as untested rather than green.

**One correction to the handoff:** it says PHP 8.2+. webtrees 2.2's own
`composer.json` requires `"php": "8.3 - 8.4"`. If the host is on 8.2, webtrees
2.2 will not run there at all, portal or no portal. Worth checking early.

### 1.2 Which tree(s) does the portal cover?

**Assumed single-tree for Phase 1, and built so that is easy to revisit.**

The tree is a module setting (`portal_tree`); if unset it falls back to the
site's default tree. All tree selection lives in one class,
`Services/PortalTreeService.php`. Making the portal multi-tree means changing
that class and adding a tree parameter to the routes — it does not mean
unpicking the privacy code, the presenter or the frontend.

If the answer turns out to be "several trees", this now costs more than it did:
editing is per-record, so a member with a record in each tree would need to
choose which one they are editing, and the directory would need to say which
tree an entry refers to.

### 1.3 Do members self-register? — **answered, and built in Phase 5**

**Neither. An administrator invites them, and they set their own password.**

Self-registration was the wrong answer for the reason it was always going to
be: it puts a form on the internet that anybody can fill in, and the question
it has to answer — is this person really family — is not one software can. An
administrator creating each account by hand was the other option, and it is
what the first four phases lived with; it does not scale past a handful of
people and it fails in a quiet way, because the second step (linking the
account to a genealogy record) is easy to forget and nothing says so.

So: an administrator picks the person out of the tree, gets a one-time link,
and sends it. The invitee chooses their own username and password. The account
arrives verified, approved, `member` on the portal's tree, and already linked.
See §2.22.

webtrees' own registration page is still untouched and still behaves however
it is configured on the host. If it is switched on, it is a second way in that
this module knows nothing about — worth turning off.

### 1.4 Password reset — **answered, and built in Phase 2**

A portal screen over webtrees' own backend, rather than a link into webtrees.
See §2.0.2. One thing this needs from you: the module's **Portal address**
setting must hold the portal's URL, or the emailed link has nowhere to point
and resets are effectively off.

### 1.5 Is Google/social login a requirement?

**Assumed no**, following §1 of the handoff. If it becomes one, the cost is an
OIDC module plus an identity-mapping table (`provider`, `subject`,
`wt_user_id`) — the portal's own auth surface (`SessionCreate`,
`RequireAuthentication`) would gain a second way in, not be replaced.

Nothing in Phases 1 or 2 makes that harder — but the longer the portal is in
use with passwords, the more accounts there are to migrate, so it is worth
answering sooner rather than later.

### 1.6 Where does webtrees live long-term?

**Still open.** The only thing this repository assumes is that the webtrees
host is reachable over HTTPS from Cloudflare's network and that it can set
cookies for its own hostname. Moving it means changing the `WEBTREES_ORIGIN`
secret on the Worker.

---

## 2. Decisions taken

### 2.0 Phase 2: editing goes through the queue, and loses nothing

Three things about `Services/GedcomEditor.php` are load-bearing, and all three
fail quietly rather than loudly if got wrong.

**It works from the raw GEDCOM, never from `facts()`.** `facts()` is privacy
filtered. Rebuilding a record from it would delete every fact the member is not
allowed to see, and the edit would look entirely successful. The fixture gives
Anna an `EVEN` marked `2 RESN confidential` that she cannot read, and
`EditTest::testAnEditPreservesAFactTheMemberCannotSee` proves an edit of her
occupation leaves it intact. That test is the reason this class exists in the
shape it does.

**The record is never named by the request.** `IndividualUpdate` reads whichever
individual webtrees links to the session and edits that. There is no parameter
to point elsewhere, which is the whole authorisation model.

**Values cannot carry structure.** A newline in a value would append arbitrary
level-1 facts to one's own record. Control characters are collapsed to spaces,
and a `/` is refused in a name because it delimits the surname inside `NAME`.

Only the *first* matching fact of a tag is replaced. Someone with three `OCCU`
facts keeps the other two: the portal offers one occupation box, and that is
not a mandate to delete a genealogist's research.

A second edit while one is pending is refused with `409` rather than accepted.
`AbstractGedcomRecordFactory::pendingChanges()` returns nothing to non-editors,
so a member's record always reads as the approved version — meaning a second
edit would be built from that and would overwrite the first when an editor
applied them in order. Refusing is the only option that cannot lose someone's
work silently.

Found while testing: the record factory hands back the *same instance* that
`updateRecord()` has just mutated, so re-reading after an edit showed the
member their own proposal as though it were live, while the next page load
would have disagreed. `IndividualUpdate` now photographs the approved record
before writing.

### 2.0.1 Contact details are readable only on one's own record

`ADDR`, `EMAIL`, `PHON` and `WWW` were deliberately unpublished in Phase 1.
Phase 2 makes them editable, and a field a member can change must be one they
can see — so they are now returned on the member's **own** record and nowhere
else. Publishing them on other members' records is a different question with a
different consent model, and belongs with Phase 3.

### 2.0.2 Password reset reuses webtrees' mechanism entirely

Same `password-token` preference, same one-hour expiry, same
`RateLimitService`, same email templates, same mail configuration. The only
difference is that the link points at the portal.

Requests answer `202` identically whether or not the address exists — including
when the rate limiter refuses, which is swallowed rather than reported, because
"you are being rate limited" would confirm the account exists. When there is no
such user the handler sleeps for a comparable time, as webtrees itself does.

Resets, unlike logins, *do* distinguish an expired token from a wrong one. A
token is not a secret worth protecting once it has expired, the holder already
had it, and a member who took too long needs to be told that rather than left
guessing.

### 2.1 Authentication reuses webtrees' session cookie as-is

`POST /session` verifies credentials through `UserService::findByIdentifier()`
and `User::checkPassword()`, then calls `Auth::login()`. No password hashing or
comparison is reimplemented.

webtrees' `Session::start()` already sets
`HttpOnly; Secure; SameSite=Lax; Path=…` and stores sessions in the database,
so the portal did not need to set up a cookie at all — it borrows the one
webtrees already manages. A member who signs in to the portal is, as far as
webtrees is concerned, signed in; that is intentional and is why language and
theme preferences are carried across on login.

### 2.2 CSRF, and a wrinkle in webtrees core

Unsafe requests must carry `X-CSRF-TOKEN`, holding `Session::getCsrfToken()` —
webtrees' own mechanism, not a second one. `GET /csrf` hands it out.

**The wrinkle:** webtrees' `Router` middleware applies core's `CheckCsrf` to
*every* `POST` route in the application, ours included, before our middleware
runs. Core answers a bad token with a `302` redirect rather than a `403`, and
that is not overridable from a module without patching core — which §2 of the
handoff rules out, correctly.

So:

* for `POST`, core checks first and our `RequireCsrfToken` is a redundant
  second pass;
* for `DELETE` (and `PUT`/`PATCH` in Phase 2), core does **not** check, and
  ours is the only check;
* the API client treats a redirect or non-JSON reply to an unsafe request as
  "token stale", re-fetches `/csrf` and retries exactly once
  (`portal/src/api/client.ts`).

A member never sees this. It is written down because it will look like a bug to
whoever reads the client's retry logic without knowing why.

### 2.3 Failures are deliberately indistinguishable

Wrong password, unknown user, unverified email, unapproved account and rate
limiting all return the same `401 invalid_credentials` body. The real reason
goes to webtrees' authentication log (`Log::addAuthenticationLog`), which an
administrator can read.

When the username does not exist, the handler still runs `password_verify()`
against a dummy hash, so response time does not answer the question the body
refuses to. `SessionTest::testEveryKindOfFailureLooksTheSame` asserts the
bodies are byte-identical, and the lock-out case is asserted equal to the
wrong-password case too — otherwise the limiter itself becomes an enumeration
oracle.

### 2.4 Rate limiting fails closed

`portal_login_attempt` records failures by IP and by username. If the table
cannot be read or written, `LoginRateLimiter::allows()` returns `false` and the
login is refused. An unavailable limiter is not a reason to let someone guess
passwords as fast as the server can hash them.

Usernames are stored as an HMAC-SHA256 of the lower-cased name, keyed by a
per-installation random secret generated on first use. The portal should not
grow a second list of who has an account, and the table is only ever read by
equality, so a hash costs nothing.

Successful logins clear that username's failures; expired rows are pruned
opportunistically (roughly 1 write in 20) so no cron job is needed.

### 2.5 Privacy filtering happens in exactly one class

`Services/RecordPresenter.php` is the only place in the module that reads
genealogy data. Nothing queries `individuals`, `families`, `other`, `link` or
`dates` directly; everything goes through `GedcomRecord` and the registry
factories at an explicit access level.

Its contract: **a record the caller may not see comes back as `null`, never as
a redacted object.** Callers drop nulls out of lists. This is stricter than
webtrees' own UI, which sometimes shows a private relative as "Private" — but
§7 of the handoff asks for absence, not redaction, and absence cannot leak
existence through response shape.

**The access level must be the session user's.** webtrees' privacy code
consults `Auth::user()` in places (notably the "you can always see your own
record" rule in `GedcomRecord::canShowRecord()`), so passing some other user's
access level would produce an answer that is neither user's. `PortalTreeService::accessLevel()`
is the only place it is computed, and it is computed from the session.

### 2.6 Facts are an allow-list

`RecordPresenter::PUBLISHED_TAGS` lists the level-1 `INDI` tags the portal
publishes: births, deaths and the surrounding life events, occupation,
education, residence, census, migration, religion, title, description.

An allow-list rather than a deny-list, because §7 says to omit when unsure and
a deny-list is wrong in the dangerous direction when a tree contains a tag
nobody anticipated.

**Deliberately not published in Phase 1:**

* `ADDR`, `EMAIL`, `PHON`, `WWW` — contact details. webtrees' fact-level
  privacy would cover them, but "find and connect with other members" is Phase
  3, with its own consent model, and until that exists there is no reason for
  the API to hand out a living person's phone number.
* `NOTE`, `SOUR`, `OBJE` and other pointer facts — the value is an XREF to a
  record the presenter has not privacy-checked. Any fact value containing `@`
  is nulled as a backstop.
* Media. Photos become webtrees media objects in a later phase.

Adding a tag is a one-line change plus a test.

### 2.7 Directory visibility is consent, not privacy

A member's `display_name` in `/members` comes from `portal_member_profile`
(their `display_name_override`, or their webtrees real name) — **never** from
GEDCOM. Their `individual` is GEDCOM data and is `null` whenever the caller
may not see it.

This distinction is worth being explicit about, because the two rules can
disagree: a member whose record is confidential in the tree can still choose to
appear in the directory under their own name. That is them consenting about
themselves, which is the entire point of a member directory — and it does not
open their genealogy record, which stays filtered.
`PrivacyTest::testTheDirectoryDoesNotLeakAHiddenIndividual` pins exactly this:
the name is listed, the XREF appears nowhere in the response.

### 2.8 No XREF is ever a key

`portal_member_profile` has a surrogate integer primary key, and `/members/{id}`
uses it. XREFs are rewritten on re-import; a foreign key to one is a bug with a
delay fuse. The member→individual link is webtrees' per-tree `gedcomid` user
setting, read through core each time.

### 2.9 Errors are JSON, always

webtrees' `HandleExceptions` middleware renders HTML error pages, which a JSON
client cannot use, and an unhandled exception's message can disclose internals.
`Http/Middleware/ApiEnvelope.php` wraps every API route: `ApiException`s become
their JSON body, anything else becomes a generic `500` with the real error sent
to `error_log()`.

It also stamps `Cache-Control: private, no-store` (plus `Vary: Cookie`,
`X-Content-Type-Options`, `X-Robots-Tag`) onto **every** response, errors
included — an error response is just as account-specific as a successful one.

### 2.10 The frontend keeps nothing

Server state lives in TanStack Query with `gcTime: 0` and `staleTime: 0`. The
CSRF token lives in a module variable. Nothing genealogical touches
`localStorage` or `sessionStorage`.

The single exception is the key `portal.language`, holding `de` or `en`. It is
a device preference, not personal data, and without it the language switch
would not survive a reload. Two tests assert that it is the *only* key present.

German is the default, deliberately, rather than following the browser's
language — the handoff asks for German default, and the switcher is one tap
away on every screen including login.

### 2.11 The portal is a Cloudflare Worker, not a Pages project

The handoff says "Cloudflare Pages" and "a Pages Function proxies `/api/*`".
The account deploys to **Workers with static assets** instead — the first
deploy failed with `A request to the Cloudflare API
(/accounts/…/workers/scripts/webtrees-portal/versions) failed`, which is the
Workers upload endpoint, not the Pages one.

The property the handoff actually cares about is unchanged: the browser talks
to one origin, so the session cookie is first-party and the PHP module needs
no CORS handling and no `SameSite=None`. Only the mechanism moved, from
`functions/api/[[path]].ts` to `edge/worker.ts`.

Two things bit, and both are worth knowing because one of them fails loudly
and the other does not:

**`_redirects` is rejected on Workers.** The standard SPA rule
`/*  /index.html  200` fails validation with "Infinite loop detected in this
rule": the asset layer normalises `/index.html` back to `/`, which matches
`/*` again. There is no non-looping way to write it. The Workers equivalent is
`assets.not_found_handling: "single-page-application"`, so `public/_redirects`
was deleted. A move back to Pages needs it restored — the Pages entry point
says so in a comment.

**`run_worker_first: ["/api/*"]` is not optional.** Without it the SPA
fallback answers `/api/v1/me` with `index.html` *before* the Worker runs. The
deploy succeeds, the site loads, and every API call returns HTML — which
surfaces as a confusing JSON parse error rather than as a deployment failure.

The proxy itself lives in `edge/proxy.ts` and both entry points — the Worker
and the Pages Function — are thin wrappers around it, so the two platforms
cannot drift apart while only one of them is being exercised.

Verified locally with `wrangler dev` against a stub webtrees: `/` and
`/members` both serve the SPA, `/api/v1/me` and `POST /api/v1/session` proxy
through with the body, cookie and `Cache-Control: private, no-store` intact,
and a client-supplied `X-Portal-Proxy-Secret` is stripped and replaced with
the real one rather than passed along.

### 2.12 webtrees is addressed by ugly URL, because it ships no rewrite rules

The API is mounted at `/api/v1/…`, and I first assumed a webtrees host would
serve that path. It will not, on a stock install, and the reason is more basic
than a config setting: **webtrees ships no rewrite rules at all.** The only
`.htaccess` in the distribution is `data/.htaccess`, which is a deny-all. There
is nothing to map `/api/v1/csrf` onto `index.php`, so the webserver looks for a
file of that name and returns its own 404 — no PHP, no session cookie, no
webtrees HTML. A bare `Server: Apache` 404 with no `X-Powered-By` is the
signature, and it means the request never reached the application.

`rewrite_urls` in `config.ini.php` is a second, separate switch, also off by
default: with it off `Router::process()` takes the route from a `route` query
parameter and *replaces* the request path, so even a rewriting webserver would
not help unless both are set.

So the Worker addresses webtrees as `/index.php?route=/api/v1/csrf` **by
default** — the form that needs no server configuration whatsoever.
`WEBTREES_UGLY_URLS=false` opts into the pretty form for installations that
have configured rewriting.

Getting it backwards is diagnosable in both directions, which is the point of
choosing this default rather than requiring a setting: wrongly `false` gives
the bare webserver 404 above, and wrongly defaulted on a rewriting install
gives a `308` from webtrees to the pretty URL — which points at the webtrees
host, leaving the portal's origin and taking the session cookie out of scope.

The symptom is not a 404, which is what makes it hard to place: webtrees'
`NoRouteFound` middleware **redirects an unmatched GET to the home page**, so
every endpoint answers 302. A `POST` to an unmatched route does return 404, so
the two behave differently and neither says "no such route".

`WEBTREES_ORIGIN` may also carry a path, for a webtrees installed in a
subdirectory. Without that the prefix is silently dropped, because an absolute
path in `new URL(path, base)` replaces the base's path entirely.

Verified with `wrangler dev` against a stub, all four cases:

| `WEBTREES_ORIGIN` | mode | webtrees receives |
| --- | --- | --- |
| `http://h:8099` | default | `/index.php?route=%2Fapi%2Fv1%2Fcsrf` |
| `http://h:8099` | `UGLY_URLS=false` | `/api/v1/csrf` |
| `http://h:8099/webtrees` | `UGLY_URLS=false` | `/webtrees/api/v1/csrf?x=1` |
| `http://h:8099/webtrees/` | default | `/webtrees/index.php?route=%2Fapi%2Fv1%2Fcsrf&x=1` |
| `not a url` | — | 503 naming the bad setting |

The SPA fallback is unaffected in all of them.

### 2.13 The Worker re-scopes webtrees' session cookie

webtrees sets its session cookie with `Domain=` and `Path=` taken from the
`base_url` in its own config.ini.php — so `Domain=webtrees.example.org`. The
browser is on the portal's origin, and a response may not set a cookie for an
unrelated domain, so it is rejected outright.

The failure mode is quiet and misleading: `POST /session` returns 200 with a
complete `Me` payload, the cookie is dropped on the floor, and the next request
is 401 — so the member lands back on the login screen having apparently just
signed in successfully. Nothing in either log says anything is wrong.

`rewriteSetCookies()` drops `Domain`, making it a host-only cookie for whatever
origin the portal is served from, and forces `Path=/` because webtrees' path is
its own install directory and would not match `/api/v1`. `HttpOnly`, `Secure`
and `SameSite` pass through untouched.

This is the same job `cookieDomainRewrite` does in the Vite dev proxy, which
was configured from the start — the Worker simply never had the equivalent, so
local development worked and the deployment would not have.

### 2.14 Non-secret Worker settings belong in wrangler.jsonc

A variable typed into the Cloudflare dashboard as **Text** does not survive:
`wrangler.jsonc` is the source of truth for plaintext vars, so the next deploy
— including an automatic Workers Build from a push to this repository — drops
anything not declared there. Set `WEBTREES_ORIGIN` in the dashboard as Text and
it vanishes on its own, at what looks like a random moment: whenever someone
next pushes.

Encrypted secrets are preserved, so `PORTAL_PROXY_SECRET` is safe as a secret.
`WEBTREES_ORIGIN` and `WEBTREES_UGLY_URLS` are a hostname and a boolean — not
sensitive — so they belong in the config file, in version control, where a
deploy reasserts them rather than removing them.

### 2.15 SFTP deployment swaps rather than overwrites

`module/tools/deploy-sftp.sh` uploads to `portal_api.upload/` beside the target
and swaps it in with two renames, instead of mirroring over the live
directory. The host serves requests throughout an upload, and webtrees loads
`module.php` on every request — so an in-place overwrite has a window in which
the module is half one version and half another.

The staging and rollback directory names contain a dot, which is not
cosmetic: `ModuleService::customModules()` skips any directory under
`modules_v4/` whose name contains `.`, so neither is ever loaded as a module.

The host authenticates with a **username and password**, which is what the
target host offers. That needs `sshpass`: `ssh` reads a password from a
terminal and nowhere else, by design, so there is no way to script one without
it. The password reaches sshpass through the `SSHPASS` environment variable
rather than argv, so it is not in the process list, and the lftp command file
(mode 0600, in a `mktemp -d` that is trapped for removal) contains the
username but never the password.

Host key verification is required and cannot be turned off — there is no
`StrictHostKeyChecking=no` escape hatch, deliberately. That matters more with a
password than with a key: a machine-in-the-middle on a key-authenticated
session gets that session, while on a password-authenticated one it gets the
password, and with it the ability to write to `modules_v4/` whenever it likes.

`SFTP_PRIVATE_KEY` is still supported and takes precedence when set, so moving
to key authentication later is one secret, no code change. Worth doing.

`PubkeyAuthentication=no` is set on the password path. Without it ssh offers
agent and default keys first, which on a server with a low `MaxAuthTries` can
exhaust the attempts before the password is ever tried — a failure that looks
like a wrong password and is not one. `NumberOfPasswordPrompts=1` is set for a
similar reason: sshpass answers exactly one prompt, so a rejected password
otherwise leaves ssh asking twice more with nothing to answer it.

lftp is given a **placeholder** password as well as the username. It never
sends it — over sftp all authentication happens inside the connect program,
which is ssh — but given a username and no password it tries to prompt, finds
no terminal, and falls back to logging in as `anonymous`:

    open: GetPass() failed -- assume anonymous login
    mirror: Fatal error: max-retries exceeded (Connection closed by ... port 22)

The second line looks like a network or server problem and is neither; it is
the server refusing `anonymous`. The placeholder keeps the real password in
`SSHPASS`, out of the command file.

The workflow runs the full test suite before it uploads anything, and there is
no input to skip it. The privacy assertions are the reason to trust a release
of this particular thing.

### 2.15.1 The upload is OpenSSH `sftp`, in exactly two connections

Four rounds, and each one was a different wrong theory. Writing them down
because the sequence is the lesson.

1. **"It is the network."** Retries, connection limits, lftp told to be
   patient. Helped, cured nothing.
2. **"It is lftp's transfer."** True, and found only because a working
   deployment to the *same server* from another project turned out to use
   OpenSSH `sftp` over the same `sshpass -e ssh`. lftp keeps many requests in
   flight and opens a second connection when it suits it; this host tolerates
   neither.
3. **"lftp can stay for the deletes."** Wrong, and expensively so. Those
   deletes went through `lftp_try`, which discarded failures — so on a host
   lftp could not reach at all, the old version was silently never moved
   aside, and the run failed at the *next* step reporting a permissions
   problem that did not exist. **A step allowed to fail silently is a step
   that cannot be trusted to have happened.**
4. **"It is how many connections we open."** The log that settled it showed
   `Connection closed by <ip> port 22` with *no sftp command echoed before
   it*. `sftp -b` echoes each command as it runs, so a session printing none
   died at the door. Shared hosting rate-limits bursts of SSH connections, a
   CI runner looks like a burst, and once it trips everything after it is
   refused too. The version that had just removed lftp opened nine sessions
   per run; the working project opens one.

So: two sessions. One reads the manifests, one does the entire deployment.
And because the fix is "fewer connections", the second session has to be safe
to repeat — deletes and mkdirs tolerant, puts overwriting, renames last — so
that a long retry delay is a real remedy. The delays are 30s, 90s, 270s;
seconds outlast nothing.

Design points that fell out of this, each of which cost a simulated run:

* **Recursive delete comes from a manifest.** `sftp` has no recursive `rm` and
  parsing `ls -l` differs per server. Every upload writes
  `.portal-deploy-manifest` listing its contents *before* writing anything
  else, so even an upload the server cut short leaves a complete list.
* **A rollback directory is renamed aside before it is emptied.** Renaming
  always frees the name; deleting might not. The other order deadlocks: one
  undeletable rollback directory blocks the swap on every future run, for
  ever, with a misleading error. Found by simulation, not in production.
* **`ls -1`, never `ls -d`.** sftp's `ls` takes `[-1afhlnrSt]`; an invalid flag
  would make an existence check answer "no" for everything.
* **Explicit paths, not `put -r` with a glob.** A glob skips dotfiles and
  `portal/dist` ships an `.htaccess`. A filename containing a glob character
  stops the deployment, because `put` expands globs locally with no way to
  disable it.

The test for all of this is a filesystem-backed fake SFTP server
(`mkdir`/`put`/`get`/`ls`/`rename`/`rm`/`rmdir`, honouring the `-` prefix) and
a scripted sequence of runs: upgrading from the pre-manifest layout, steady
state, every connection refused, a drop part way through the puts, and a drop
between the two renames. That last pair is what proves the batch is repeatable.

Host key verification stays on, which the off-the-shelf actions in this space
do not do — `wlixcc/SFTP-Deploy-Action` passes `StrictHostKeyChecking=no`. With
password authentication an intercepted connection hands over the password
itself, not just the session.

### 2.15.2 The birth date is a calendar, and the API is not narrowed

A member editing their *own* record is the one person who knows their birthday
exactly, so the form offers `<input type="date">` rather than asking them to
type a GEDCOM date. `api/dates.ts` is the whole translation, both ways.

The **API keeps accepting every form webtrees does** — "ABT 1985", ranges,
other calendars. It is one field on one form that asks for a plain date, not
the contract. Narrowing the API would break the day someone needs to record an
approximate date for a relative.

The case that needed care is a stored date the picker cannot hold. The field
starts empty then, and because only changed fields are sent, an untouched
empty field leaves the stored date alone. But a blank box reads as "no birth
date on file", so the hint says what is recorded, in the form the profile
shows it: *"Bisher gespeichert: etwa 1985. Dieses Datum bleibt unverändert,
solange Sie hier keines auswählen."* `Phase2.test.tsx` pins both halves —
that the field is empty, and that submitting sends nothing.

`isoToGedcom` returning null is treated as "send nothing", never as "send
null": null deletes the fact, and deleting the date a member was trying to
correct is the worst thing this form could do.

### 2.15.3 The module may not take webtrees down with it

`boot()` runs inside the same PHP request as webtrees itself, on every page of
the site, for every visitor. An exception thrown there does not break the
portal API — it breaks the family's genealogy site, for everyone, including
the administrator who would then have to go and fix it without being able to
log in.

So the whole of `boot()` is wrapped: schema migration, service registration,
route registration. A failure logs the real error to the server log and leaves
webtrees running with no portal API, which is a state the portal already knows
how to explain to a member.

This is deliberately the one place in the module where an exception is
swallowed. Everywhere else, failing loudly is right; here the cost of failing
loudly is paid by somebody who did not ask for a portal.

### 2.15.4 The portal says what happened, not what usually happens

`PUT /me/individual` used to answer `status: pending_approval` unconditionally.
That is right for a member and wrong for an editor or administrator: webtrees
applies an edit immediately, with no pending change at all, when the *acting*
user has the `auto_accept` preference.

Not a data problem — an administrator's edit going live is what webtrees would
do anyway — but a trust problem. The portal would have told them their change
was waiting for review, and they would have gone looking for it in a list it
was never going to appear in. So the handler asks `PendingChanges::existsFor()`
after the write and reports `applied` or `pending_approval` accordingly, and
the profile screen has wording for both.

Found while answering "how safe is it to test the write path on a test tree in
the same installation?" — which is exactly the situation where an
administrator makes the first edit.

### 2.15.5 An untouched field keeps its whole block, children and all

The birth fact is the only one the portal rebuilds from parts, because it
offers two of them: date and place. That rebuild used to take each part as a
*value* — `2 PLAC Reutlingen` — and write a fresh line from it. Which meant
that a member editing only the date silently threw away everything hanging
under the place:

    2 PLAC Reutlingen
    3 MAP
    4 LATI N48.4919
    4 LONG E9.2042

Coordinates, source citations, notes: gone, from a field the member never
touched. The address block beside it (`2 ADDR / 3 CITY / 3 STAE / 3 CTRY`,
which real exports carry) was always safe — `otherSubLines()` keeps deeper
lines with their level-2 parent — but that made the gap harder to notice, not
smaller.

Now a field the member did **not** change keeps its entire block verbatim, and
a field they *did* change is written fresh without the old children, because
those described the old value and would be wrong attached to the new one. Old
coordinates do not follow a place from Hannover to Reutlingen.

The address block is deliberately left alone in both cases. After a place
change it is stale — and that is something for an editor to notice while
approving, not something the portal should quietly delete on a member's
behalf.

Found by reading a GEDCOM snippet from the installation this serves, before
the first real write test. `EditTest` now carries that shape.

### 2.16 Three navigation destinations, and no component library

My profile, Members, Settings. Tailwind plus about ten local components in
`portal/src/components/`. No MUI, Chakra or Ant: the constraints that actually
matter for this audience — 16px floor, 44px targets, real contrast, plain
language — are easier to hold in a few files we own than to negotiate with a
design system.

Error and empty states are sentences with a next action, in both languages. The
API's `error` code only chooses which sentence; no code is ever shown.

### 2.17 The API answers in the language the portal is being read in

Not everything on the screen is translated in the browser. Three kinds of
string come out of webtrees itself, server-side:

* `Event.label` — the name of a GEDCOM fact ("Geburt", "Beruf", "Titel");
* `DateValue.display` — a date written out for a reader ("12. März 1985");
* the placeholders webtrees uses where a name may not be shown.

So the portal sends `Accept-Language` on every request (`api/client.ts`, set
from i18n), and `Http/Middleware/UsePortalLanguage.php` matches it against the
languages the installation has enabled — exact tag first, then language
without region, so `en` finds `en-US`. An unavailable language changes
nothing rather than failing.

Two things about that middleware are not obvious:

1. **It re-registers the GEDCOM tags.** Element labels are translated *once*,
   when webtrees' `RegisterGedcomTags` middleware builds the element factory —
   which runs before routing, and therefore before any module middleware. Just
   calling `I18N::init()` moves `I18N::translate()` and leaves every label
   behind in the language webtrees first guessed. Registering the tags again is
   the same call webtrees itself makes, against the now-correct translations.
2. **`POST /session` no longer lets the account's webtrees language win.** If
   the request carried a language, that is the member's choice, made a moment
   ago on this device. With no header it still falls back to the account
   preference, exactly as webtrees' own login does.

On the client, the language is part of every query key. The same request in two
languages is two different responses, so switching has to refetch rather than
leave "Birth" sitting on a German screen. It goes *last* in the key so that
`queryKeys.me` still matches when a mutation invalidates it.

`Vary: Cookie, Accept-Language`. Only `display` moves: `DateValue.gedcom` and
`Event.tag` are machine-readable and stay put — the edit form reads values back
out of the rendered record by `tag`, and would break if they were translated.

### 2.18 Names come from the structured name, not from `fullName()`

`Individual::fullName()` is a *display* string, and other modules decorate it.
On the installation this portal serves, the Vesta "Classic Look & Feel" module
overrides it to prepend a badge holding the SB reference number, and it can
append the XREF as well. That is a reasonable thing to do to a webtrees page
and wrong in a JSON field called `name`: a badge is not part of anybody's name,
and this API publishes no XREFs at all (§2.8).

`RecordPresenter::nameAt()` reads `getAllNames()[…]['fullNN']` instead — the
plain name webtrees itself puts in the database, underneath the display layer —
and re-applies the only two things `fullName()` does that we still want: the
placeholders for an unknown given name or surname.

This is the general shape of the problem, not a one-off: a webtrees
installation is webtrees *plus its other modules*, and anything that returns
HTML for a page to show is fair game for them to change.
`NameDecorationTest` stands in for Vesta by decorating `fullName()` the same
way, so the next module that does this is caught here rather than in
production.

### 2.19 The reference number is published, as a field of its own

`Individual.references` — a list of `{number, type}` from the record's `REFN`
facts. Usually one; GEDCOM allows several, so it is a list rather than a
scalar that would quietly drop the second one.

A field rather than an entry in `events`, because it is bookkeeping and not
something that happened to the person, and `INDI:REFN` is deliberately still
absent from `PUBLISHED_TAGS` so it cannot arrive twice under a "Reference
number" label.

It comes out of the same privacy-filtered fact collection as everything else,
so a `2 RESN confidential` under a REFN hides it without any extra code —
`PrivacyTest::testAConfidentialReferenceNumberIsNotPublished` pins that.

In the portal it is a line **under** the name, not a badge in front of it,
with a screen-reader label ("Kennnummer im Familienarchiv"). Rendered as
`SB 4711` where the record gives a type, and as the bare number where it does
not.

`references` is **optional** in the TypeScript type, on purpose. The module
ships over SFTP and the portal ships through CI, so the two can be a version
apart; the portal has to survive a server that predates the field rather than
throwing on the profile screen.

### 2.20 Phase 3: the tree is walkable, and two webtrees traps on the way

Relatives are links now, there is an ancestors view, and a record says how the
member is related to it. The API endpoint and the query hook for the first of
those had existed unused since Phase 1; what was missing was the routing.

Two things in webtrees behave differently from how their names read, and both
would have leaked if taken at face value.

**`RelationshipService::getCloseRelationshipName()` walks at
`Auth::PRIV_HIDE`.** All six of its traversal points pass it, deliberately: it
serves webtrees' own pages, where the answer is already gated. Handing its
result to a member would disclose more than a name — a relationship name
encodes the shape of the path that produced it, so "your cousin" says a shared
grandparent exists even when every record on the way is hidden. So
`RelationshipNamer` walks the same algorithm at the member's access level and
hands the resulting nodes to the public `nameFromPath()`. The names are still
webtrees', translated by webtrees.

**`Family::children($access_level)` does not mean what it looks like.** It
filters on `canShowName()`, which is true for a member whenever the tree shows
living people's names at all, and it silently escalates to `Auth::PRIV_HIDE`
when `SHOW_PRIVATE_RELATIONSHIPS` is on. So it can hand back people whose
records are hidden. `RecordPresenter` was never exposed to this — everything
it returns goes through `individualRef()`, which checks `canShow()` — which is
the design decision from §2.5 paying for itself. The relationship walk had to
filter explicitly, and `TreeTest` pins it: Otto is visible, his daughter Ida is
confidential, and a member is told nothing about how she is related to him
while a manager is told.

The reverse trap sits next to it: `Family::canShow()` is *too strict* for this
purpose, because webtrees hides a family whenever any member is private — one
confidential cousin would make "your mother" unnameable. What is checked
instead is whether the family declares a `RESN` of its own, which is somebody
saying that this connection is confidential rather than these people.

**The pedigree is Ahnentafel-numbered and full of holes on purpose.** Root 1,
father 2n, mother 2n+1, flat. A person the member may not see is absent and so
is everyone above them — the branch ends, with no placeholder and no labelled
gap, because a pedigree with a labelled hole in it tells the reader what fills
the hole. The screen says outright that some people may not be shown, rather
than leaving that to be inferred from a short tree.

Indented rows, not a drawn chart: a pedigree diagram is wide and a phone is
not, and pinch-to-zoom is the webtrees experience this portal exists to
replace.

### 2.21 Phase 4: photographs, and the one response that may be cached

Pictures from webtrees, shown in the portal. Read-only: uploading is a
separate piece of work with a file, a media folder and a disk quota in it.

**Why the portal serves the bytes itself.** webtrees' media URLs point at the
webtrees host, built from its `base_url`, and the session cookie was
deliberately re-scoped to the portal's origin (§2.13). A browser following one
would arrive with no session, fail `canShow()`, and get webtrees' "forbidden"
replacement image — every picture in the portal a grey box. So `Photo` carries
portal-relative URLs and `MediaRead` streams the file through the same proxy as
everything else. The rendering stays webtrees': its image factory makes the
thumbnail and applies the watermark it would apply on its own pages.

That also sidesteps webtrees' signed media URLs, which sign the *resize
parameters* against a site key. That protection is webtrees defending itself
against being asked for ten thousand sizes of one photograph; it is webtrees'
business, and the portal simply never hands out a URL that reaches it.

**The cache exception.** Every other response in this API is
`private, no-store`, and §2.9 says why. Photographs are the exception:
`private, max-age=86400`. A gallery re-fetched on every scroll costs a phone
its battery for nothing, and `private` is what keeps it safe — a browser may
keep it, a shared cache may not.

This needed a change at both ends. A handler asks for it through
`ApiEnvelope::PRIVATE_CACHE_HEADER`, a marker the envelope translates and
removes, so the word `public` never appears in a handler and cannot be widened
by accident. And the Worker previously flattened every upstream
`Cache-Control` to `private, no-store`; it now passes a `private` directive
through and replaces everything else. That filter is not decoration:
**webtrees answers media requests with `public, max-age=31536000`** — a year,
in any cache that will have it. Reasonable for a site serving its own
privacy-filtered pages; on the far side of a CDN it means an edge could hold
one member's photograph and hand it to the next member who asks for that URL.
`edge/proxy.test.ts` pins that exact string being refused.

**Two filters when listing, not one.** `facts(['OBJE'], …, $access_level)`
decides whether the *link* is visible; `$media->canShow($access_level)` decides
whether the media record is. They answer different questions and a record can
be restricted without its links being.

**No external files.** A media record can point at a URL on somebody else's
server. Proxying those would make the portal a fetcher of arbitrary URLs;
linking them directly would leak the member's address to that server. Neither
is worth a photograph, so they are omitted.

### 2.22 Phase 5: an invitation is a credential, and is treated like one

An invitation link is enough, on its own, to become somebody in this family's
portal. That makes it a password, and the three properties it needs are the
ones a password needs.

**It is never stored.** `portal_invitation` holds a SHA-256 of the token and
nothing else that could be used. A database is backed up, dumped, and read by
hosting support and by whoever inherits the server; a stored token is a
working account for every one of them. The raw value exists once, in the
administrator's browser, at the moment it is created — which is why the screen
says so, and why losing it means issuing a new one rather than looking it up.

A plain SHA-256 rather than a password hash, deliberately: the input is 256
bits from `random_bytes()`, so there is no dictionary to run and nothing a
work factor would buy.

**It is usable once, and the check is atomic.** `InvitationService::claim()`
is a single conditional `UPDATE` — `WHERE id = ? AND redeemed_at IS NULL AND
expires_at > ?`. Two requests arriving together cannot both match a row, so
they cannot both produce an account. A read followed by a write would look
identical in a test and be wrong exactly once, under load, for a link somebody
forwarded to two people.

**A failure that creates nothing gives it back.** Everything checkable is
checked before the claim; if account creation still throws, `release()` puts
the invitation back. Burning an invitation over a duplicate username would
lock out the very person it was sent to, and they cannot tell that from a
malicious link.

**Both endpoints are `POST`, including the one that only reads.** The token
has to travel in the body. `GET /invitation/{token}` would write the
credential into the webserver's access log, into any proxy in front of it, and
into the `Referer` of every request the resulting page goes on to make. The
same reasoning as `POST /password/reset` (§2.0.2).

**The preview does not read the family tree.** It answers with the tree's
title and the name the invitation was issued for — and that name is a snapshot
taken by the administrator's screen when the invitation was created, stored on
the row. So the one endpoint a stranger can reach with a guessed token never
touches genealogy data at all. `InvitationTest::testThePreviewDisclosesNoRecord`
pins that no XREF appears in the response.

Showing the name is deliberate, not an oversight: it is how the reader tells
"my family's portal, and it knows who I am" from "a form on the internet". The
disclosure is one name, to whoever holds a 256-bit secret that an
administrator addressed to that person.

**The XREF on the invitation is a payload, not a key** (§2.8). It is
re-resolved through the record factory at redemption. If a re-import has
renumbered the tree in between, the account is still created — locking someone
out because the genealogist reloaded the GEDCOM would be a strange thing to do
— just without a link, and the administrator's new "accounts with no linked
record" list picks it up.

**The account has nothing it was not given.** `canadmin`, `auto_accept` and
the tree role are written explicitly rather than left to default.
`auto_accept` matters most: an account that accepted its own edits would walk
straight around the pending-changes queue that the whole of Phase 2 rests on.
The role is `member`, never `editor` — an editor could change anybody's record
in webtrees, bypassing the portal entirely.

**Verified and approved on arrival.** webtrees' email round-trip and approval
queue both exist to answer "is this a real person we meant to let in". An
administrator picking this person out of the family tree by hand and sending
them a link answers it more strongly, so asking again would only add a step to
get stuck on.

**A username may not contain `@` or a space.** webtrees signs people in with
`UserService::findByIdentifier()`, which matches a username *or* an email
address in one query. A username shaped like an address could therefore stand
in front of somebody else's account at the login form.

**`username_taken` and `email_taken` are the one place the API names
something about the installation.** That is unavoidable — a registration form
that cannot say why it will not accept a name is a form nobody can complete —
and it is reachable only while holding a valid invitation, which is what keeps
it from being a way to enumerate accounts.

**The admin screen's access control is its method name.** webtrees'
`ModuleAction` refuses any module action whose name *contains the word
"Admin"* to a non-administrator, before the method runs. There is no
annotation and no second check. Renaming `getAdminInvitationsAction()` to
`getInvitationsAction()` would publish the invitation list, and the list of
accounts without a record, to anyone who could guess the URL, with nothing
failing to say so. `ConfigurationTest::testEveryModuleActionIsForAdministratorsOnly`
asserts that every action this module declares keeps the word.

**The module does not send the email.** The administrator copies the link and
sends it however they normally reach that person. Sending it would mean
guessing which address is right, and being wrong means mailing a working
credential to a stranger.

### 2.23 Phase 6: a module that cannot fail loudly has to report

Two decisions taken earlier were both right and, together, produced a blind
spot. `boot()` swallows everything it throws (§2.15.3), so that a broken
portal cannot take the family's genealogy site down with it. `ApiEnvelope`
turns every unhandled exception into a polite "please try again later"
(§2.9), so that no internal message reaches a member. The result is an
installation that can be broken for one person for weeks with nothing
anywhere looking wrong: webtrees is fine, the portal loads, and the only
record of the failure is a line in a server log that on shared hosting is
somewhere between hard to find and not readable at all.

So Phase 6 adds no feature. It adds the three places where the truth shows up.

**`portal_error`, and a reference the member can read aloud.** Every
unhandled exception is recorded and answered with an eight-character
reference, which also goes into the JSON body and onto the screen. That is
the whole mechanism: without it a report reads "it did not work yesterday, on
my phone" and there is nothing to look up.

What is deliberately *not* recorded is the request. Not the body — for
`PUT /me/individual` that is somebody's date of birth. Not the query string —
for the directory that is whom they searched for. Not the path — for
`/individuals/X123` that names a record. The route's **name** is stored
instead, which is the handler class: enough to find the bug, and the
difference between a diagnostic table and a second copy of the family's data.
`OperationsTest::testTheRecordDoesNotNameWhatWasAskedFor` pins it.

**Only genuine bugs are recorded.** An `ApiException` is a refusal this module
wrote on purpose and worded for the member; a 404 for a record somebody may
not see is ordinary traffic, and recording those would bury the handful of
rows that are actually bugs. The 503 for an unconfigured portal is excluded
for a second reason as well: an uptime monitor polling a half-configured
installation would add a row a minute, for a condition the diagnosis screen
reports far better.

Entries are deleted after 30 days. An exception message can quote the value it
was choking on, and nothing that might carry personal data should sit around
indefinitely.

**A diagnosis screen, because these failures all look alike from outside.**
"The portal says 503" is the same sentence for a tree setting still pointing
at a test import, for tables whose migrations never ran, and for a `boot()`
that threw — and the three have nothing to do with each other. The screen
checks each one and says what to do about it.

The check that could not be made any other way is **"did `boot()` finish?"**.
Because it catches everything, a module that failed to start looks exactly
like one that started, from every page of webtrees. Its routes are simply
absent — so the screen looks for one of them in the route map.

The one next to it in value is **schema version expected vs installed**. New
files on the server with their migrations not run is a deployment that
succeeded by every measure the deployment itself can take.

**`GET /health`, and one request that proves the chain.** Answering it at all
takes a request through the Worker, the proxy secret, the URL form webtrees
needs, PHP, webtrees' bootstrap, `boot()`, the database and the tree setting.
It is unauthenticated on purpose — a health check that needs credentials is a
health check nobody runs — so its payload is deliberately dull: no tree name,
no counts, nothing worth finding.

The exception is `version`, and it is the point. `deploy.yml` compares what
the endpoint reports against the version in the checkout, which turns "the
upload reported success" into "the new code is actually running". An SFTP
upload that leaves the old files in place is the deployment failure that is
otherwise invisible. It is a warning rather than a failure, because a run
that only deploys the portal legitimately leaves the module's version alone.

The step is skipped unless a `PORTAL_URL` repository variable is set, so an
installation that is not reachable from GitHub does not fail every deploy.

### 2.24 Phase 7: "the people I can see" is not "my close family"

A member can now invite their own close relatives. The intuitive rule for who
that is — the people the portal already shows them — is wrong, and wrong in
the dangerous direction. It is worth writing down exactly why, because it
looks right.

`Individual::canShowByType()` applies relationship privacy **only** when the
user has a per-user `RELATIONSHIP_PATH_LENGTH` greater than zero *and* a
linked record:

```php
$user_path_length = (int) $this->tree->getUserPreference(Auth::user(), UserInterface::PREF_TREE_PATH_LENGTH);
$gedcomid         = $this->tree->getUserPreference(Auth::user(), UserInterface::PREF_TREE_ACCOUNT_XREF);

if ($gedcomid !== '' && $user_path_length > 0) {
    return self::isRelated($this, $user_path_length);
}

// No restriction found - show living people to members only:
return Auth::PRIV_USER >= $access_level;
```

Neither is set by default, and nothing in this module sets the first. So for a
normal account, "the people I can see" means **every living person in the
tree**. Scoping invitations to visibility would have handed most members the
whole family, silently, while the code read as though it were restrictive —
and it would have changed meaning again the next time somebody edited a tree
preference.

So `Services/CloseFamily.php` measures the distance itself, by walking, with
the limit as this module's own setting (default: two steps — parents,
siblings, partners, children, plus grandparents, grandchildren, nieces,
nephews and parents-in-law). `canShow()` still applies on top: a relative the
member may not see is not a candidate whatever the distance. The walk is
`RelationshipNamer`'s, including both of the substitutions that class explains
at length — families filtered on their own `RESN`, people filtered on
`canShow()` rather than trusting `Family::children()`.

**The candidate list discloses nobody new.** It is the same walk the member's
own page already does, at the same access level, stopping at the same limit.
`MemberInvitationTest::testTheScreenDisclosesNobodyNew` asserts that the
confidential sister, the unconnected stranger and the confidential
great-grandmother all stay absent.

**Three exclusions, one of them a judgement call.** The dead, anyone who
already holds an account, and anyone already invited are dropped. The last two
are dropped *silently* — the person is simply not in the list and the member
is not told why. Saying "your brother already has an account" would route
around §2.7: appearing in the member directory is consent, and this would
disclose the same fact without it. §7 of the handoff says to omit when unsure
and write it down, so that is what this paragraph is.

**Every rule is checked again at the moment it matters.** The candidate list
is a convenience for the screen; `MemberInvitations::create()` re-runs the
whole computation, because a client can post any XREF it likes. Too distant,
hidden, already an account holder, already invited and not a person at all all
produce the same `not_allowed`, so posting an XREF is not a way to find out
which.

**What holds it in proportion.** An invitation creates an account with member
access to the tree, and a member issuing one is being *trusted* to know who is
family in a way an administrator is not. Three limits, all visible in the
module preferences: the relationship distance, a quota of outstanding
invitations per member (default three), and an off switch. Every invitation
carries who issued it, the administrator's screen now shows that column, and
an unused one can be withdrawn from there.

**The member sees the link and sends it themselves**, as the administrator's
screen already does. Having the module send the email would not be safer — the
member still types the address — and it would put a mail server between a
member and the thing they are trying to do.

**Not a fourth item in the bottom navigation.** `Layout.tsx` says "three
destinations, no more", and that was a decision rather than an accident.
Inviting somebody is a thing a member does once or twice; it lives on
Settings, where the rest of "my own participation" already is.

### 2.25 Phase 8: what a member may *see*, which is a separate question

Phase 7 answered "whom may I invite" and had to compute the answer itself,
because `canShow()` was too wide to lean on. The obvious follow-up is whether
the visibility should be narrowed to match — and it should, but it is a
different mechanism with different consequences, so it is worth being precise
about what it does.

**It restricts living people and nothing else.** In `canShowByType()` the
dead are checked *first*:

```php
if ((int) $this->tree->getPreference('SHOW_DEAD_PEOPLE') >= $access_level && $this->isDead()) {
    ...
    if (!$keep_alive) {
        return true;
    }
}
// Consider relationship privacy
```

`SHOW_DEAD_PEOPLE` defaults to `'2'` (`app/Tree.php`), so a limit never
touches an ancestor. The genealogy — the part a family portal is *for* —
stays whole. That is what makes this safe to switch on.
`VisibilityTest::testALimitHidesLivingPeopleAndLeavesTheDeadAlone` pins it: a
grandfather two steps away who died in 1929 stays visible under a one-step
limit, while a living person related to nobody does not.

**But "dead" is a guess, and it errs toward living.** `Individual::isDead()`
returns true for a death event, for any dated event more than `MAX_ALIVE_AGE`
(120) years old, or by inference from parents', spouses', children's and
grandchildren's dates. A record carrying a name and nothing else satisfies
none of those and is therefore treated as living — so a limit hides *thin*
records, not recent ones. That is the practical cost, and it is the opposite
of what "only living people" sounds like it means.

A second, off-by-default caveat: `KEEP_ALIVE_YEARS_BIRTH` and
`KEEP_ALIVE_YEARS_DEATH` (both `''` by default) make webtrees keep treating
somebody as living for N years past those events, and a person kept alive
that way falls through to the relationship test like anybody else.

**The number means the same as ours.** `isRelated()` does `$distance *= 2`
before walking, because it counts INDI→FAM and FAM→INDI as separate links. So
webtrees' "2" is the same reach as this module's two steps, and the two
settings can be read side by side without conversion.

**It needs both halves, and webtrees enforces that.** Relationship privacy
applies only when the account has a linked record *and* a path length above
zero; `UserEditAction` even forces the value back to zero when the record is
missing, since the distance is measured from it. `MemberService::applyPathLength()`
skips such accounts for the same reason rather than storing a number that does
nothing — they belong in the "no linked record" list instead.

**There is no default to inspect.** Not per site, not per tree — every
account, one at a time, in *Control panel → Users → edit*. So the state was
invisible: nothing anywhere said "every member currently sees every living
person". The diagnosis screen now does, which is most of the value of this
phase.

**New accounts get it, existing ones do not — until asked.** An invitation
sets the limit at the moment it sets the link. Existing accounts are changed
only by a button, because it changes what people who are already signed in
can see, and that is not a thing to do behind an administrator's back.
Editors, moderators, managers and administrators are never touched: they need
the whole tree to maintain it.

**Zero is written as absence.** Zero and unset mean the same thing to
webtrees, but only one of them reads as a decision, so the module writes
nothing rather than a zero.

**A trap that cost a test.** `Tree::getUserPreference()` fetches a user's
preferences once and caches them *on the instance*, and `setUserPreference()`
updates only that instance's copy. Two `Tree` objects for the same tree
therefore disagree until the next request. Harmless in webtrees, where a
request holds one of them and the admin action redirects afterwards; a trap
in a test that writes through its own `Tree` and then asks a service holding
another. `VisibilityTest::portalTree()` exists to say so.

**Left alone deliberately:** `isRelated()` keeps its results in a
function-level `static $cache` that is keyed by neither user nor tree. In a
web request there is one signed-in user, so it is correct there. It would be
wrong in a process that evaluated privacy for two different users in turn —
worth knowing before writing a test that logs in twice and asks about living
people both times.

### 2.26 Phase 9: contact details are consent, and one disclosure is unavoidable

Two things members asked for, and they pull in opposite directions: being
reachable, and not handing a telephone number to sixty relatives.

**The details do not come from the family tree.** `RecordPresenter` still
withholds `ADDR`, `EMAIL`, `PHON` and `WWW` on everybody's record but their
own (§2.6, §2.0.1), and Phase 9 does not touch that. What is shared is what
the member typed into `portal_contact_detail`, about themselves.

The reason is the one §2.7 already gives for the directory: GEDCOM contact
data is maintained by whoever keeps the tree. Consenting to publish "whatever
my record happens to say" is not informed consent — the content can change
afterwards without the member ever knowing, and they cannot correct it. Portal
data is consent; GEDCOM is genealogy; the two do not mix.

**One row is one decision.** A row carries a value *and* an audience, because
"my email may go to the whole family" and "my address is for my brother" are
different answers and one switch forces the narrower onto both. `nobody`,
`close_family` or `members`, where close family is the same distance as for
invitations — one definition of "close family" in the module, not three.

**Clearing a field and withdrawing consent are the same act.** An empty value
deletes the row rather than hiding it. A member who deletes their telephone
number has plainly finished sharing it, and keeping a copy behind a narrower
flag would be a way of not listening. The client therefore submits every kind
on every save, including the empty ones — sending only the filled ones would
leave the old row standing and the member would believe they had deleted it.

(An audience of `nobody` deleted the row too, until §2.65. It now keeps the
entry and discloses it to nobody.)

**The narrowest answer is the default, everywhere.** An unknown audience, a
missing row, a viewer with no linked record, a subject with no linked record,
the whole facility switched off — every one resolves to "not shared" rather
than to an assumption. `ContactTest::testAnUnknownAudienceSharesNothing` pins
the one that would otherwise be a guess.

**The directory list carries none of this.** Deciding "close family" means
walking the tree, and doing that once per row would turn a list into a
page-load nobody waits for. It is asked on one member's own screen and
nowhere else.

#### The disclosure that cannot be avoided

`MessageService::deliverMessage()` writes the sender's own address into the
stored message and sets it as the `Reply-To` of the email:

```php
'sender' => Auth::check() ? Auth::user()->email() : $sender->email(),
$this->email_service->send(new SiteUser(), $recipient, $sender, …)
```

So writing to another member discloses the writer's address, whether or not
they shared it. There is no version of "write to me and I will answer" that
avoids it — a reply needs somewhere to go.

The portal therefore says so **on the form, above the send button**, not in a
confirmation afterwards and not in a help page. An unavoidable disclosure that
nobody mentions is worse than the disclosure itself, and this is the sentence
`Contact.test.tsx` calls the most important assertion in the file.

The other direction is genuinely private: the recipient is reached however
they chose in webtrees, and their address never passes through the portal.

**Only a member listed in the directory can be written to**, and one who
stayed out is reported as `404` — the same answer as an id that never
existed, so this is not a way to discover who has an account.

#### A bug the tests found, not a member

`deliverMessage()` opens with `I18N::init($recipient->getPreference(PREF_LANGUAGE))`
so the message is written in the recipient's language — and `Locale::create('')`
throws a `DomainException`. An account whose language preference was never set
therefore **could not be written to at all**; the sender got a 500.

Accounts created by invitation always have one. Accounts created by hand in
webtrees may not — which is exactly the pre-existing accounts a family would
be writing to first. `MemberMessages::ensureRecipientHasALanguage()` fills in
the site default before delivering: a missing default restored, never a choice
overridden, and the same value webtrees' own `UseLanguage` middleware falls
back to. The portal met this trap once before, in `SessionCreate`, where a
blank preference would have locked a member out of signing in.

**Not built:** a website field (nobody asked, and it is the one contact detail
that is usually public anyway). An inbox was not built either, so a member
whose contact method is internal-only had to read their messages in
webtrees — which is what Phase 10 went back and fixed (§2.27).

---

### 2.27 Phase 10: reading the messages, and what webtrees stores instead of one

Phase 9 could send a message and then had nowhere to read it. A member whose
webtrees contact method is "internal messaging only" received their family's
messages into a mailbox they had no way of opening. That is the gap this
closes.

**The inbox is webtrees' `message` table, not a portal one.** Everything
addressed to the member is shown, whatever route it took to get there — a
message sent from the portal, one sent from webtrees' own contact form, an
administrator's broadcast. A member should not have to know which system
delivered something in order to find it, and a second store would have made
"my messages" mean two different lists depending on where you stood.

Two properties of that table shaped the whole phase, and neither is visible
from its column names.

**`message.body` is not what the sender typed.** It is the *rendered*
`emails/message-user-text` view: a greeting, a line naming the sender, a rule
of sixty hyphens, the message, another rule, and a note about which page the
sender was looking at. Showing that in an inbox would be showing somebody
their own email envelope. `Inbox::messageOnly()` therefore takes what lies
between the first rule and the last and drops the rest — the greeting and the
sender line are not lost information, because the inbox shows the sender in
its own column and does it better.

The **fallback** is the part that matters. A body with no rules at all is
returned whole rather than emptied, so a message stored by another version of
webtrees, or by a module with its own template, stays readable. Losing the
wrapper is a nicety; losing the message would be a bug. The rule is matched as
*a run of ten or more hyphens* rather than as exactly sixty, because the
number is a detail of a template rather than a promise.

**`message.sender` is an email address, not a user id.** webtrees stores
`Auth::user()->email()` at the moment of sending, so there is no link back to
an account. The name has to be looked up by address, and the lookup can fail
in three ordinary ways: the sender changed their address since, the account
was deleted, or the message came from a contact form filled in by a visitor
with no account at all. When it fails the address itself is shown. That
discloses nothing new — it was already in the recipient's email as the reply
address, which is the entire reason webtrees stores it (§2.26).

**There is no read flag in that table**, so `portal_message_read` supplies
one: a row means read, no row means unread. Stored per user as well as per
message even though webtrees' messages have exactly one recipient — the pair
is what the unique key is *about*, and a read state that could be written for
somebody else's message is not a thing worth being able to express. The
foreign key cascades from `message.message_id`, so deleting a message takes
its read state with it and there is nothing to tidy up.

**Deleting deletes for real.** The portal is not a second mailbox keeping a
quiet copy; `DELETE /messages/{id}` removes the row from webtrees' own table.
The screen says so in a sentence under the list, because a member who expects
a copy to survive somewhere would be wrong in a way that matters.

**Somebody else's message and a message that does not exist are the same
`404`.** `Inbox::assertOwn()` checks ownership before every read-state change
and every delete, and gives the same answer either way. Enumerating the id
space would otherwise disclose how much traffic the site carries and, by
timing, when.

#### Two decisions in the interface

**The navigation bar went from three destinations to four**, and the rule it
broke was one this project made deliberately in Phase 1. That rule was still
right about what it was aimed at — "invite somebody" stayed off the bar. What
it got wrong is the test it was really applying: *how often a member comes
back to a thing*. Inviting happens once or twice; an inbox is checked whenever
something might have arrived, and an unread badge only does its job somewhere
permanently visible. Four is the limit — at roughly 80px each they still fit a
320px screen and a fifth would not.

**The badge digit is `aria-hidden`.** The unread count is already in the
link's accessible name as words, and without hiding the numeral the link reads
as "1 Nachrichten — 1 ungelesen". The count is not being hidden from anybody;
the same fact is being said once instead of twice.

**Opening a message marks it read.** That is what opening a message means, and
the alternative is asking the member for a second deliberate act purely to
make a badge go away. Marking one unread again is one button on the open card,
for the "I will deal with this later" case that is the only real reason to
want it.

---

### 2.28 Answering, and two ways webtrees loses a message without saying so

An inbox that cannot be answered is half a feature. `POST /messages/{id}/reply`
is the other half, and it raised one design question and turned up two
delivery bugs.

**The directory rule is lifted for a reply, deliberately.** §2.26 refuses to
let a member write to anybody who kept themselves out of the directory, and
that rule is right about what it guards: picking somebody out of a list is
*finding* them. A reply finds nobody. The other person wrote first, so their
existence, their account and their name are already known to the person
answering — there is nothing left to disclose by allowing the answer. Keeping
the rule here would only produce the thing Phase 10 set out to abolish: a
message that arrives and cannot be answered.

What a reply *does* disclose is the answerer's own address, exactly as a new
message does, so the same sentence sits above the same button. The difference
is consent: being written to was not a choice, replying is.

**The Reply button appears only where a reply can be delivered.** `sender` is
an address, not an account (§2.27), so it may resolve to nobody — a contact
form filled in by a visitor, a sender who has changed their address, a deleted
account. `can_reply` is computed per message from the same lookup that
resolves the name, so it costs no extra query, and the interface never has to
refuse after the member has written something. This is exactly the condition
webtrees' own message list uses to decide whether to show its Reply link.

The portal deliberately does **not** fall back to emailing the stored address
when there is no account behind it. That address is a *reply* address on a
message, not a consent to be contacted by the portal, and the person behind it
may have nothing to do with this site.

**The subject is not the member's to write.** It is webtrees' `RE: `
convention applied to the original, decided on the server — one less field on
a phone, and no way to write an answer that arrives looking like a new
conversation. One improvement on core: core compares the prefix exactly, so a
thread whose earlier reply was written in German (`Re: `) collects a second
prefix when the next one is written in English (`RE: `). The portal compares
case-insensitively, against the translated string and the source string both.

**There is no sent folder, and the screen says so.** webtrees stores only the
*recipient's* copy of a message; nothing is kept for the sender. Rather than
invent a sent item that does not exist, the confirmation says plainly that no
copy is kept here.

#### Two bugs, both of them silent

**A recipient who never chose a contact method received nothing.**
`deliverMessage()` files an internal copy only for a `contactmethod` it
recognises and sends an email only for an emailing one. The empty string — the
value of an account whose preference was never set — is in neither list, so
the message was stored nowhere, sent to nobody, and the call still returned
`true`. The sender was told it had been delivered.

Note what this is *not*: `none` is in the internal list, so a member who chose
to be left alone still gets their copy. Only *never chose* was broken. That is
why it went unnoticed — webtrees' own registration sets the value, and so does
this module's invitation path, so it cannot happen to an account created
either way. It happens to accounts made by hand, and to old ones, which is the
same population as the language trap in §2.26 and gets the same treatment:
`ensureRecipientCanBeReached()` fills in the value webtrees' own registration
uses. A missing default restored, never a choice overridden.

This one was hiding behind a green test. `ContactTest::testAMessageReachesAListedMember`
had passed since Phase 9 — because nothing was being sent, so nothing could
fail. Fixing the delivery turned it red, which is how it was found. It now
asserts the copy rather than the status code.

**A failed email was reported as a failed message.** Phase 9 refused with
`not_delivered` whenever `deliverMessage()` returned `false`, and called it
"we are not sure": the internal copy might have been stored, but nobody could
read it in the portal, so claiming success felt worse. Phase 10 changed the
fact that answer rested on. A filed copy is now in the recipient's inbox, on
the screen they are already using — that *is* delivery, and a family whose
mail server is down should not be told their messages are failing. So the
refusal now needs both channels to have failed. Both failing is still a
failure and is still reported as one.

---

### 2.29 A link that is a signpost, not a gate

Every person screen has always linked out to that record in webtrees. It now
reads differently depending on the role the account holds in the tree:
editors and above get it at the top, worded for editing; members get it at the
foot, worded for charts. Nobody gets both, because two links to one address
differing only in wording is a question nobody needs to answer.

**The role check is presentational and is commented as such**, which matters
more than the feature. `webtrees_url` is in the payload every member already
receives — it is the public record address, built by webtrees from its own
`base_url` — so hiding or showing it grants and withholds nothing. webtrees
decides what the person arriving may do, as it does for anybody who types the
address by hand. If this check is ever read as an access control, the reading
is wrong, and a later change made on that assumption would be a real hole.

What it buys is smaller and worth having anyway: a member is not sent to an
editing screen that will only tell them no, and an editor is not left hunting
for the tree they maintain. The role comes from `/me`, where it is already
computed per tree by `MeAssembler::role()` — `Auth::isEditor()` and friends, in
the configured tree, not a guess from the account's global flags.

### 2.30 An installable portal whose cache stops at the shell

The portal is a progressive web app: a manifest, icons, and a service worker
in `portal/sw/` built separately to `/sw.js`. On a home screen it opens under
its own icon with no address bar, which is most of the point — the audience is
members who open WhatsApp by tapping a picture, not people who keep bookmarks.

**The interesting decision is what the service worker refuses to do.**
Everything else in this repository is built around never keeping personal
data: `gcTime: 0` in React Query, `private, no-store` from the module,
`privateCacheControl()` in the proxy refusing to let `public` past. A service
worker is a cache that outlives the tab, the session and the sign-out, on the
device most likely to be handed to somebody else. Adding one carelessly would
undo all three at once.

So it keeps the shell and nothing else, and requests under `/api/` are not
merely uncached but never intercepted: `handlingFor()` returns `bypass` and no
`respondWith` is called, which leaves the request exactly as it would have
been with no service worker installed. Photographs are the case that makes
this worth stating — a portrait is a same-origin GET of an image, identical to
an asset in every respect except its path.

Four details that are not obvious, each of which was a bug first:

* **Pages are network-first, assets are cache-first.** Asset URLs contain a
  content hash, so a cached one can never be wrong. `index.html` can: a
  deployment replaces every hashed file, and a cached page naming files that
  no longer exist is a portal that will not start. Online, the network always
  decides what the page is.
* **The shell is not one file.** Caching `index.html` alone produces something
  that looks installed and opens blank on the first flight — the document is
  there and the script it asks for is not. The worker reads the asset list out
  of the document's own markup at install (`assetsIn()`) rather than having the
  build write a list, because the markup is the only version of that list that
  cannot go stale. This was found by the Playwright test, not by reasoning.
* **A 200 can be a lie.** Both deployment targets answer an unknown path with
  `index.html` so the router can take it. A request for a hashed file that a
  deployment has removed therefore returns 200 with HTML in it, and storing
  that under a `.js` URL is a portal that will not start *and* cannot repair
  itself. `mayStoreAsset()` refuses anything claiming to be a page.
* **`Vary: Origin` all but disables the cache, and only offline.** Vite marks
  its script and stylesheet `crossorigin`, so the browser fetches them as CORS
  requests with an `Origin` header. A static server that answers those with
  `Vary: Origin` — `vite preview` does — makes each stored entry match only a
  request carrying the same `Origin`, and the worker's own `cache.add` carries
  none. Everything precached correctly, nothing was ever found again, and the
  symptom was a page with a title and no body. Every lookup therefore passes
  `ignoreVary`, which is not a shortcut: these URLs contain a content hash, so
  the URL is the identity of the file and there is no other variant to confuse
  it with.

**The cache is named after the build**, so a deployment gets a fresh one and
activation deletes its predecessor. There is no record of which hashed files
are still referenced, so the only honest way to keep old ones from
accumulating forever is to start again each time.

**`skipWaiting()` and `clients.claim()` are safe here** in a way they usually
are not: nothing cached is version-specific except by URL, and pages come from
the network anyway. The reason to want them is the bad day — a worker deployed
to fix or to remove a broken one should take effect when the portal is next
opened, not when the member happens to close every window.

**The browser-side test asserts the shell, not the bar.** The first version of
the offline test also expected "Keine Internetverbindung" on screen, passed
here fifteen times running, and failed twice on CI — because whether
`navigator.onLine` follows a browser's offline emulation is the browser's
business and differs between Chromium builds. That assertion was testing
Playwright. What the bar does with what the browser reports is settled three
times over in `src/Pwa.test.tsx`, where no browser is in the way; what only a
real browser can show is that the portal boots at all with the network off,
and that is what the spec now asserts — polled and read as one object, because
a locator that goes unfound on a CI runner explains nothing and there is no
browser there to open and look at.

**The offline bar is not the service worker's doing.** `navigator.onLine` says
whether there is a network, not whether anything is reachable across it, so it
never suppresses a request and never decides what is fetched; `network_error`
from the API client remains the truth. All it does is answer the question the
installed app cannot otherwise answer: with no browser chrome, a portal that
has stopped working looks broken rather than offline.

**The offer to install is in Settings, not in a banner.** It is the second
thing that could reasonably want a bar across the top of every screen, and the
first — *no connection* — is one a member has to actually read. A portal that
teaches people to dismiss whatever appears up there has spent something it
needs. Settings is one of four destinations, one tap from anywhere, and the
offer sits at the very top of it: it is shown a handful of times and then
never again, so it can have that spot for as long as it lasts. It also means
no new state — no "dismissed" flag, and so nothing else in browser storage
beside the language preference.

Seven states, and the count is the correction. The first version had four and
one rule for everything it could not identify: say nothing, because a button
that cannot work is worse than silence. That is right for a browser where
installing is impossible. It is wrong for Android, where installing is
perfectly possible and Chrome had merely declined to hand over a prompt — and
the member was then looking at a screen that promises an app and offers no way
to get one. It was reported as "the install button does not appear in Chrome
on Android", and the answer was that there had never been anything to appear.

So every situation that *can* install now says how. Chrome hands over a prompt
→ a button (with Chrome's own install bar suppressed, so the offer appears
next to the sentence explaining it). Android without a prompt → where the ⋮
menu is. iOS → where the Share sheet is, since Safari has never implemented
`beforeinstallprompt` and every browser on iOS is Safari underneath. Android
WebView → that the page has to be left first. Only two states are silent: the
window that *is* the installed app, and a browser where none of this happens.

**The WebView case is not an edge case here.** This portal's members are
people who open links from a family chat, and a link tapped in WhatsApp opens
in Android's embedded browser, which has no menu to install from and no home
screen to install to. It announces itself with `wv` in the user agent, and it
is the single likeliest reason an offer never appeared. An app that opens
links in a Custom Tab is real Chrome and is deliberately not caught.

**"Already installed" needs the manifest to name itself.** `display-mode`
describes only the window it is asked in, so a member browsing in Chrome with
the app already on their home screen is indistinguishable from one who has
never installed it. `related_applications: [{platform: "webapp", url:
"/manifest.webmanifest"}]` makes `navigator.getInstalledRelatedApps()` able to
answer. `prefer_related_applications` must stay `false` beside it: `true`
tells a browser to offer a store app *instead of* installing this one, which
would suppress the very prompt the entry exists to support. The two lines look
like a pair and are not, so a test asserts the second one.

**The manifest is German only.** The portal has a language switch, a manifest
does not — what is written under the icon is fixed when the app is installed.
German is what `index.html` and the default language already say.

**Two names, and which one goes where.** The app is **Sack Familienapp** in
full and **Sack** under the icon. That is not a shortening for its own sake: a
home-screen label gets about a dozen characters before a launcher truncates
it, and the word worth keeping is the family's. So the short one goes to the
manifest's `short_name` and to `apple-mobile-web-app-title` — which is iOS's
`short_name`, since iOS reads no manifest at all — and the full one to the
manifest's `name`, the page title, and the sign-in heading, which are the
places with room for it.

"Sack" survives translation and "Familienapp" does not, so the English is
*Sack Family App*. The rule the test enforces is therefore not that the two
languages agree — it is that neither loses the family's name, that the full
name begins with the short one, and that the two platforms label the icon
identically. All of it lives in files that cannot read each other: the
manifest, `index.html` twice, both translations, and the service worker's
offline page, which has no i18n to ask and is where a rename goes to be
forgotten.

---

### 2.30 Getting from the portal into webtrees, in both directions of the door

The link out was landing people in the wrong place, and it turned out neither
of the two obvious links can get this right on its own.

**The reason it is hard at all:** the portal and webtrees are separate origins.
The session cookie is first-party to the portal — that is the whole point of
the proxy (§2.1) — so a member following a link out arrives at webtrees as a
**signed-out visitor**, always. Meanwhile an editor reading in two tabs may
well be signed in there. Both have to land on the person they clicked.

**Linking at the record** — `$individual->url()`, which is what the module sent
until now — works only for somebody who already has a webtrees session. Without
one, what happens depends on the tree's settings. On a tree that requires
authentication, webtrees redirects to its login page carrying the address as
`url`; but that address then has to survive `Validator::isLocalUrl()`, which
compares scheme, host, port and path prefix against `base_url` and *silently*
falls back to the front page when any of them differ — which is what a proxy
or a mistyped `base_url` will do. On a tree that does not require
authentication there is no login prompt at all: a private record is simply
reported as not existing.

**Linking at the login page** with `url` set fixes the signed-out case and
breaks the other one. `LoginPage` answers an authenticated request with
`redirect(route(UserPage::class))` and discards `url` entirely, so a reader who
is already signed in is thrown to a page they did not ask for.

So there is no static link that is right in both states — which is why
`IndividualLink` exists: `GET /portal/individual/{xref}`, a browser-facing
redirect that asks the question at the moment it can be answered. Signed in,
straight to the record; signed out, to the login page carrying the record as
`url`, which `LoginAction` does honour.

Two properties keep it safe. It takes **an XREF and never a URL**, so there is
nothing to point at another site whatever the query string says. And it builds
the destination with `route()`, from the same `base_url` that `isLocalUrl()`
validates against, so the address it hands to the login page cannot fail that
check by construction. It grants nothing: the record page enforces its own
privacy on arrival, exactly as it does for an address typed by hand, so an
XREF the reader may not see still redirects and webtrees still refuses.

**It is the module's only browser-facing route**, and it carries none of the
API middleware — no proxy secret (a browser has none), no JSON envelope, no
authentication requirement, since being signed out is the case it exists to
handle. It is registered in the route map rather than as a module action,
which keeps §2.5's invariant intact: every `get…Action` on the module still
has "Admin" in its name, because that spelling is the access control.

#### The bug it shipped with, and why every test missed it

`IndividualLink` resolved the tree through `PortalTreeService::tree()`, and
that method resolves it through `TreeService::all()` — **which is filtered by
the current user**. On a tree with `REQUIRE_AUTHENTICATION`, which is what a
family portal's tree almost always has, the collection is empty for a visitor.
So `get()` returned null, `tree()` reported the configured tree as missing, and
the handler took its "nothing configured" fallback to the home page. webtrees
then sent the visitor from the home page to the login page **with no
destination** — `HomePage` ends with `redirect(route(LoginPage::class, ['url' => '']))`
when no tree is visible — and signing in landed them on their own user page.

The reported symptom named its own cause once it was written down:
`?route=/login&url=` has no tree segment and an empty `url`, and nothing in
this module can produce that. Reading it as *"then this redirect is not mine"*
is what found it.

`tree()` is right to be filtered: every API route is authenticated, and a
caller with no access to the tree has no business getting data out of it. It
is simply the wrong question here. `configuredTreeName()` asks the
configuration question instead and returns a name — and **a name is not
access**: it goes into a URL that webtrees enforces on arrival, exactly as it
would for an address typed by hand.

Two lessons worth more than the fix. The first authenticated-only assumption
in a module breaks the moment one route runs for a visitor, and this was that
route. And every test here passed with the bug present, because the fixture
tree is public — so `all()` returned it to a visitor too. `Auth::logout()` was
not enough to reproduce being signed out; the *tree setting* was the test.
Both new tests fail against the old handler.

**Proven end to end, not hop by hop.** `LinkTest::testTheWholeWayFromTheLinkToTheRecord`
walks the whole thing through webtrees' real router and real middleware: click,
login page, sign in, arrive. The single-hop tests all passed while the chain
was still an open question, and three things only the whole walk can check —
that the router matches the route from an ugly URL, that webtrees' login form
carries the destination in its hidden field, and that `LoginAction` redirects
to it — are exactly where this kind of thing breaks.

Two harness details cost an hour of wrong diagnosis and are written into that
test: `doLogin()` refuses outright when `$_COOKIE` is empty, and `CheckCsrf`
runs after routing for *every* POST, so the form's token has to be posted back.
Both produce "the username or password is incorrect", which is a misleading
thing to read while hunting a redirect bug.

A third one is worth recording on its own: **webtrees' own login throws for an
account with no language preference.** `doLogin()` ends with
`I18N::init(Auth::user()->getPreference(PREF_LANGUAGE))`, `Locale::create('')`
throws a `DomainException`, and `LoginAction` catches `Exception` — so the
sign-in is reported as a failed one. Same trap as §2.26, in webtrees' own front
door, and not something this module can fix from outside.

**What this does not fix:** the second sign-in itself. A member who follows the
link still types their password on the webtrees side, because there is no
shared session across the two origins and building one would mean either
proxying all of webtrees through the portal or handing out tokens. Neither is
worth it for a link most members follow rarely. What is fixed is that the sign
-in now ends where they were going.

---

### 2.31 The icon is the charge, not the achievement

§2.30 made the portal installable and gave it a mark to be installed *as*: a
pedigree drawn in the navigation bar's line style, two people above three,
joined. It was the right placeholder — legible at 48px, consistent with the
rest of the interface — and it said "a genealogy app" rather than "this
family". Any of a hundred portals could have worn it.

The family's arms say the second thing, but they cannot be used as they are.
They are a full achievement: the shield, the helm above it, the mantling
either side, the crest dove standing on the helm, and a banner carrying the
foundation's name. That drawing is made for a letterhead. Nothing in it
survives being redrawn at 48px, and the banner least of all — at that size
lettering is a grey smear.

So the icon keeps the **charge** and drops the rest: the dove displayed,
argent on azure, on a shield with a silver border. It is the element a member
already associates with the family, it appears twice in the arms — on the
shield and again on the helm — and it is a silhouette, which is the only kind
of drawing that survives being made small. Calling the result "the coat of
arms" would be too strong. It is the arms' subject, redrawn to be legible.

**What did not change.** The file names, the manifest, the `<link>` tags, the
service worker's precache and the tests that hold them together are all
§2.30's and are untouched. This is an artwork swap inside a structure that
already worked, which is why the diff is two SVGs, five PNGs and a line on the
login screen.

**One drawing, two files, five renders.** `icons/icon.svg` and
`icons/icon-maskable.svg` differ only in their background and one transform —
the dove is drawn once, in a 64-unit square, and placed by a `transform` in
each. The PNGs are rendered from them by `tools/build-icons.mjs` and
committed, because Safari reads none of the manifest's icons and will not take
an SVG for the home screen. The generator needs `@resvg/resvg-js`, which is
deliberately *not* a dependency of the portal: it is installed with
`--no-save` on the rare day the mark changes, rather than downloaded by every
`npm install` forever after. That script is also the file the old comment in
`icon.svg` pointed at as `tools/icons.md`, which never existed.

Parchment behind the shield rather than nothing, for two reasons: the arms are
drawn on paper, and a launcher that puts an "any"-purpose icon on a white tile
should not be handed a white icon. The maskable variant is the same picture
inset until its furthest ink — the shield's top corners — sits ~190px from the
centre, inside the 205px a circular crop leaves.

The login screen shows the same file above the heading, referenced rather than
redrawn. It is the screen reached from an emailed link, by somebody with a
fair right to ask whose portal this is.

### 2.32 Phase 11: a connection is a second kind of consent

The directory answers "who is in this family". It has never answered "whom do
I actually know", and a member scrolling two hundred names looking for the
eight people they met at the last family gathering is doing the wrong thing
with the wrong list. Connections are that second list, and they are kept by
the member rather than derived from anything.

**Both sides always said yes.** A row reaches `accepted` only because the
other person did something — confirmed a request, or showed the code that was
scanned. Nothing here can be arranged by one member alone, and that is what
makes it safe to hang a disclosure on: contact details gain an audience of
"my contacts", and two connected members can write to each other and open each
other's page even when one of them is not in the directory.

**The two ways in are deliberately not symmetrical.**

*A code connects at once.* Showing it **is** the consent, and asking somebody
to confirm what they are doing in front of you is a step that teaches people
to tap "yes" without reading. The cost is that it is a credential for as long
as it lives: anybody who can see the screen, or photograph it, can connect
until it expires. So it is short-lived (a quarter of an hour by default),
stored only as a hash, replaced by asking for another, and withdrawable
outright — the same treatment §2.22 gives an invitation, for the same reason.

*An SB number only asks.* It reaches only members listed in the
directory, and only numbers on a record the asking member may already see. Both
limits fall out of rules that already exist rather than being new policy:
listing oneself is what makes a member findable (§2.7), and a `RESN` under a
`REFN` hides it (§2.5, §2.19). Together they are why an honest "no member
carries that number" is safe to say — a member who mistyped is told what to do
instead of waiting forever for an answer that was never coming.

**Being unlisted stops you being browsed, not being asked.** The number
search reached only the directory at first, on the reasoning of §2.7: listing
yourself is what makes you findable. That turned out to answer the wrong
question. A number is not a way of browsing — you have to know it, off a
letterhead or out of a Christmas card — and the people most worth reaching
this way are exactly the ones who never opened the settings screen.

So it reaches every account the tree has a record for. What keeps §2.7 intact
is the *answer*: a number that reaches somebody unlisted and a number nobody
carries are answered identically, and the unanswered request is not in the
sender's own list either, because a row that appeared only for real numbers
would be the same oracle one step quieter. The request appears when it is
accepted, which is the person deciding to be known. A member who *is* listed
is still answered by name — they published it.

The cost is paid by the member who mistypes: they are told the same thing as
somebody who got it right, and hear nothing more. That is the §2.3 trade
again, and it is the only place in this feature where the portal knows
something it will not say.

**The number search reads past the tree's own privacy, and that is the fix
that made it work at all.** `GedcomRecord::facts()` returns nothing — not a
filtered list, nothing — for a record the reader may not see, so reading a
`REFN` at the member's own access level meant the search silently skipped
every member outside their view of the tree. In an installation with a
relationship limit (§2.25) that is nearly everybody: the directory listed a
cousin by name, and the number on that cousin's letterhead found nobody.

So the facts are read at `PRIV_HIDE` and each number is then filtered on its
own `Fact::canShow()`, which — unlike `facts()` — checks the `RESN` on the
fact rather than the privacy of the record around it. That keeps the half that
belongs here (a number marked confidential stays unsearchable) and drops the
half that never did: the search now reaches exactly the people the directory
already publishes by name. The number came off a letterhead, not out of the
tree.

The three ways this can still find nobody — no account, not listed, no `REFN`
— are indistinguishable from the form and now have a table of their own on the
Diagnosis screen. An administrator asked "why does it not work?" cannot answer
it from the portal, and guessing at it twice is what prompted the table.

**The prefix is matched loosely, and only in the direction that is safe.**
The first version compared "SB 4711" against `REFN 4711` + `TYPE SB` and
nothing else, which is right for the fixture and wrong for the tree: GEDCOM
does not require a `TYPE`, the module that renders the badge in webtrees
supplies the "SB" itself, and so most records carry the bare number. A member
reading "SB 4711" off a letterhead was told nobody carries it, while "4711"
found them — a rule that punishes people for knowing what their own family
calls the thing. A typed prefix may now fall away, but only where the record
carries no type to disagree with; `TYPE Intern` is a different numbering and
keeps its own numbers to itself.

**The branch is a choice, not a keystroke.** The numbers are `10/1335.21` —
a branch, a slash, the number within it — and the slash is the only character
in the whole portal that a member would have to change keyboard layouts for.
It is also the only character in the number that carries meaning: strip it
with the rest of the punctuation, as the first version did, and `10/1335.21`
and `101/335.21` become one string, which is two relatives wearing one number.
So the slash survives normalisation, and the form is laid out the way the
number is written: a wheel of the thirty-four branches, the slash printed
between them, a field for the rest. The slash on screen is `aria-hidden` and
each control names itself instead — "Zweig, 10" and "Nummer, 1335.21" is more
than a screen reader can make of a punctuation mark between two boxes.

Left out entirely, the number is still looked up — somebody reading one off a
letterhead should not have to know that the portal cares — but only on a
second pass, and only while it picks out exactly one person. Guessing between
two cousins is worse than saying nothing was found.

Every number in this family has a branch, so the wheel offers no way to omit
one: its dash means "not chosen yet" and cannot be sent. A number without a
branch is not one anybody carries, and letting it go would buy a round trip
to be told nobody was found — true, and useless. Thirty-four is in the
client, which is where it will be wrong first if the family ever grows a
thirty-fifth branch; a number typed whole into the field, slash and all, is
passed through untouched, and is the escape hatch until somebody changes the
constant.

**A link that is sent is a second table, not a column on the first.** The code
on the screen answers "we are standing here together": a quarter of an hour,
anybody who can see it, meant to be used by several people at one gathering.
Almost every one of those properties is wrong for something that travels by
e-mail. So `portal_connection_link` keeps its own: seven days, because a
message sent on Tuesday is read on Thursday; **single use**, claimed with the
same conditional `UPDATE` an invitation uses, because a link that has been
forwarded or quoted in a reply must already be spent; and several outstanding
at once, one per person written to, which is why nothing in it is unique per
member.

Redeeming looks in both tables and the screen never says which it found. The
person who followed the link has no idea which kind they were given and no
reason to care.

`redeemed_by` is kept rather than the row simply being deleted, so that "did
the link I sent on Tuesday get used?" has an answer. It names nobody the two
of them do not already know — the connection it made is on both their screens
— and the member's own list of outstanding links carries dates and no names,
because the portal genuinely does not know who they wrote to.

**The QR code holds a link, not a token to interpret.** That decision removed
the whole scanning half of the feature: every telephone's camera app reads a
URL and offers to open it, so there is no scanner in the portal, no camera
permission, and nothing to install. It also sidesteps the fact that
`BarcodeDetector` — the browser API a scanner would use — does not exist in
Safari, which is most of the family's telephones. The link lands on `/connect`,
behind the session like everything else, and **does not connect on arrival**:
opening a link is not consent, and a page that acts before it is read is a
page that acts when a link is opened by accident or by a preview.

**Rendering the code is ours; encoding it is not.** `qrcode-generator` is a
dependency-free implementation of a published standard, and writing a second
one would be a week of Reed–Solomon for no gain. What is worth pinning is the
only thing that matters about a QR code, and `portal/src/QrCode.test.tsx`
pins it: the matrix is rendered to pixels and read back by `jsqr`, a decoder
that shares no code with the encoder. Everything else one could assert about a
QR code can be true of one no camera will read.

**A connection is not a relationship.** Nothing is written back to the GEDCOM,
no connection is derived from the tree, and being connected lifts none of
webtrees' privacy filtering — a connected relative's record is exactly as
visible as it was. The only thing a connection changes about the tree is
nothing.

**Ending it deletes the row.** Declining, withdrawing and disconnecting are one
operation because they are one act, and there is no "declined" state and no
archive. A table that remembered who refused whom would be a record this
portal has no business keeping, and a family is exactly the place where it
would eventually be read.

**Two smaller decisions that are easy to get wrong later.**

*The request is sent from the directory row, not only from the person's page.*
The row already carries everything needed to decide — a name, a record, a
lifespan — so the detour through a second screen bought nothing. It is
affordable because where two members stand is one row of one table, read once
for the whole page; contact details stay out of the list for the opposite
reason, since deciding "close family" walks the tree per member (§2.26). Only
two of the five states are a button: *Angefragt* and *Verbunden* are facts,
and a control that does nothing is worse on a row than a word that says why.
Each button is named for its row, because twenty-five buttons called
"Verbinden" are a list nobody can navigate by name — and the visible word
starts that name, so speaking it still works.

*The badge went on Mitglieder, not on a fifth destination.* §2.16 caps the bar
at four, and the cap held: a waiting request is counted on the entry that
already leads to people, and the contacts screen is a link at the top of it.
The count rides on `/me` next to `unread_messages`, for the same reason.

*Connecting writes a `portal_member_profile` row for both sides.* A connection
needs something for a screen to link to, and the portal member id is it. The
row it creates is inert — `visible_in_directory` stays off, which is the
default and the narrower answer — and only the member themselves can change
that.

---

### 2.32 Kontakte takes the tab, and the directory moves inside it

Phase 11 put *Meine Kontakte* behind a link at the top of *Mitglieder*, on the
reasoning that the directory is where somebody looking for a person already
goes. That had it the wrong way round, and the second tab and the directory
have now swapped places.

The test is the one the bottom bar has always used (§2.27): **how often a
member comes back to a thing.** The directory is everybody — you look somebody
up now and then. Contacts are your own people, plus requests waiting for an
answer, which is a thing asked *of* you. That is the screen worth a permanent
place, and the waiting-request badge now sits on the entry it is actually
about rather than on the one next door.

**The directory did not lose anything by moving.** Its search is the first
thing on Kontakte, above the QR code, because looking somebody up is the most
ordinary errand here and burying the only way to it under a credential would
make the commonest thing the longest. The field navigates rather than
searching in place — results are paged and every row leads somewhere, which is
a screen's worth of work — and an empty search means everybody, so it doubles
as the plain way in. The directory keeps its own search box so that narrowing
a result does not mean going back, and gains the back link every sub-screen
has.

Still four destinations. The rule has now survived three phases wanting a
fifth, and each time the answer was to ask which of the four the new thing
belongs *inside*.

---

### 2.33 Phase 12: a conversation, because webtrees keeps half of one

The messaging built in Phases 9 and 10 was as good as its store allowed, and
its store is webtrees' `message` table: **one row per message, owned by the
recipient**. §2.28 wrote the consequence on the screen — "there is no sent
folder, and the screen says so" — because that was the honest thing to say
about what existed. It also means a transcript was never merely hard to
assemble. Half of every exchange, the half a member wrote themselves, was not
written down anywhere.

Three more things that table cannot do, each of which a conversation needs:
nothing ties two messages together but the string `RE: ` in a subject;
`sender` is an e-mail address rather than an account, so the link back to a
person fails in three ordinary ways (§2.27); and `body` is a rendered e-mail
template rather than what somebody typed.

**The empty list says where to start.** It did not, for a day: the
conversations section rendered nothing at all while a member had none, on the
argument that somebody who has never written to anybody should not be shown a
box explaining a feature. The first person to look for the feature reported
exactly what that produces — *"I only see Sonstige Nachrichten"* — and they
were right. The way in is on the other person's page, which is not where
anybody looks while standing on the Nachrichten screen.

This is the same mistake as §2.30's install offer, in the same week and for
the same reason: **silence is right for something impossible and wrong for
something merely not started yet.** Both times the rule was applied one case
too far. Worth remembering as a rule of its own — an empty state earns silence
only when there is nothing the member could do about it.

**So a second store, beside webtrees' rather than instead of it.** The old
inbox keeps doing the thing only it can: messages from webtrees' own contact
form, an administrator's broadcast, anything that did not come from the
portal. The Nachrichten screen shows conversations above it and calls the rest
*Sonstige Nachrichten*. Two lists, because they are two different things — one
is an exchange that continues, the other is post that arrives and is read
once.

**Two people, and the pair is the identity.** The smaller webtrees user id is
always `user_one`, so a pair has exactly one row and the unique key can say so:
two members opening a conversation at the same moment cannot produce two.
Groups were considered and declined for now — they need a membership table and
a different answer to every question below, starting with what "read" means
when there are five of you.

**The rules are the ones already settled, not new ones.** Opening a
conversation applies `MemberMessages::send()`'s rule exactly (listed in the
directory, or connected to me), because opening one is *finding* somebody.
Writing into an existing one applies no such rule, which is §2.28's argument
about replies and holds harder here: the transcript on the screen is proof
these two know each other. A member who leaves the directory stops being
findable; they do not stop being someone you were in the middle of talking to,
and their name is still shown for the same reason. Somebody else's
conversation is a 404 rather than a 403, as everywhere else.

**Deleting is for oneself, and both sides deleting is a real deletion.** Two
nullable columns on the message row, one per side of the pair — enough because
the participants are fixed. A message hidden by both is removed from the
database outright: the portal does not keep what nobody can see. Clearing a
whole conversation hides the messages, not the conversation; the other side
still has it and a new message brings it back, which is the only honest
meaning "delete for me" can have when two people share a transcript.

**One notification, not one per line.** A chat that e-mails on every message
is a chat nobody stays in. So the other side is told through the path Phase 9
already built and tested — `MemberMessages`, which respects the recipient's
contact preference and files webtrees' own copy — but only when they have
nothing unread from this member already. Once something is waiting, they have
been told; telling them again says nothing new. A failure to notify is logged
and swallowed: the message is in the conversation, which is where it will be
read, and a courtesy that fails must not fail the message.

**One quota for both ways of writing**, so `refuseIfDisabled()` and
`refuseIfTooMany()` moved from private to public rather than being duplicated.
A message is a message and the limit is about volume — the same reasoning
§2.28 gives for counting replies.

**What is now unused.** `POST /members/{id}/message` still exists, is still
tested and is still what `Conversations` notifies through — but nothing in the
portal's interface calls it any more: the member page opens a conversation
instead. It should probably go in a later phase, together with `useSendMessage`
in the client. Left standing here because removing a working, tested path is a
separate change from adding this one.

### 2.34 An SB number is not a number

The search was written against a picture of the numbering that came from the
fixture: digits, a slash, a full stop. The family's numbers also carry letters,
and they carry a marker on the end — `!` means "the spouse of the person with
this number" — and neither survived normalisation, because normalisation was a
list of the characters worth keeping and nobody had put them on it.

Losing the letters would have been a search that found nobody, which is
annoying and visible. Losing the `!` was worse: `10/1335.21` and `10/1335.21!`
collapsed to one string, so a request meant for one of a married couple went
to whichever record the walk happened to reach first. It looked exactly like
success. The person asking saw the right name, because both of them have one.

So `normalise()` is now the other way round — it names what it *throws away*
(whitespace, and the full stop, comma and hyphen that separate parts of a
number) and keeps everything else. A number is somebody's identity in this
family; what gets dropped from it should have to be argued for one character
at a time, and a list of keepers quietly loses every character nobody thought
of.

**The second pass keeps the marker.** Typing a number without its slash is a
member being human about punctuation, and the relaxed pass exists for that
(§2.32) — but a marker is not punctuation a member forgot. Dropping it there
would put back exactly the bug, only rarer: a number typed for a spouse who
has no account would find the husband instead. So `flatten()` removes the
slash and nothing else, and `7/22.9!` reaches nobody rather than reaching
`7/22.9`.

**A prefix may fall away; a letter in the middle may not.** Stripping the
spoken "SB" was a `^[A-Z]+` — fine while numbers were digits, wrong the moment
one begins with a letter: "SB 47C12" became "47" and found nobody. It now
takes off exactly the head that stands in front of the stored number, and only
when the whole head is letters.

**On the form, the number pad went.** It was the obvious fit for digits and a
full stop, and it put "!" and the letters behind a keyboard switch — the same
mistake as the slash (§2.32) with the argument running the other way. A member
holding a number they cannot type is a member who cannot use the feature.

Four of the tests around this were asserting `201`, which the endpoint returns
whether or not anybody was found — that is the whole point of the
indistinguishable answer (§2.32), and it had quietly made those tests prove
nothing. They now assert *which member* the request reached.

### 2.35 Two tabs, because the address book was at the bottom

Kontakte was one column in the order of the two questions a member arrives
with: the ways of connecting first, the people second. That order is right for
somebody standing at a family gathering with a telephone in their hand, and
wrong for the same person every other day of the year.

The address book is what a member comes back to, and it had four cards of
machinery stacked on top of it — a search, a QR code, a link, a number. Reading
it began with scrolling past all four. That is not a layout that needs
tightening; it is two screens sharing one.

So the two questions are two tabs, and neither sits above the other:
*Kontakte* holds the people, *Neu verbinden* holds the ways of adding one.

**Which tab is open lives in the address bar** (`?tab=new`), not in a
`useState`. It costs nothing and it buys the refresh, the Back button and a
link that points at the right half — the same reason the directory's search
term is in the query string (§2.24). The change is `replace` rather than
`push`: a tab is where you are on this screen, not a screen you went to, and
Back should leave, not un-switch.

**The default is derived, not stored.** A member with nothing in their address
book yet opens on the tab that fills it; everybody else opens on the address
book. It is only the default — the moment a tab is in the address, the address
wins — so it can never fight somebody who chose.

**Requests waiting for an answer stay at the top of the tab that opens.** A
thing asked of you outranks a thing you own, and that reasoning survives the
split intact: the request is on the first half of the first tab, and the
navigation carries the count for the times a member is somewhere else.

**The hidden half is not rendered.** `Panel` returns `null` rather than adding
a class, because those cards hold a live QR code and a one-time link. A
credential on a screen nobody chose to look at is the thing §2.32 spends most
of its length avoiding.

**`role="tab"` is a promise about the keyboard.** It tells a screen reader
there are two of something and that the arrow keys move between them, so the
arrow keys move between them, and only the open tab is in the tab order — that
is what makes Tab from the strip land in the panel rather than on the other
tab. Two tabs, so the arrows wrap.

### 2.36 Phase 13: a knock, and deliberately nothing else

The request was *"Push bauen, ohne Namen im Sperrbildschirm"*, and the second
half of it decided the whole design.

**A push with a payload is the normal way to build this, and it was not
built.** RFC 8291 exists so that a server can send encrypted text through a
push service it does not trust; the browser decrypts it and the service worker
shows it. That machinery — an ECDH exchange, AES-GCM, two more columns in the
table for the browser's `p256dh` and `auth` keys — exists purely to protect a
payload. Sending none removes all of it. The module POSTs an empty body with a
VAPID header, the browser wakes the worker, and the worker shows a sentence
that was compiled into it: *„Sie haben eine neue Nachricht."*

So the interesting column in `Schema/Migration8.php` is the one that is
missing. The table stores an endpoint and nothing else, which means there is no
place a name could end up even by accident — not through a later bug, not
through a well-meant change. Storing the keys "in case a later phase wants
payloads" would be storing material for a capability nobody has decided to
want; the browser still has them and can hand them over again if that phase
ever comes, and that phase can argue about names on lock screens on its own
terms. This one has answered it.

**The cost is honest and small.** The notification cannot say who wrote, cannot
deep-link to the right conversation (it opens *Nachrichten*), and cannot be
counted per sender. Against that: a phone on a kitchen table tells anybody
walking past that a message arrived, and nothing more. In a portal whose
subject is living relatives, that trade is not close.

**VAPID is the part that fails silently.** The token is a JWT signed with
ES256, and `openssl_sign()` returns a DER `SEQUENCE` of two INTEGERs where JWS
wants r and s as thirty-two raw bytes each. The INTEGERs may be short, or carry
a leading zero to keep them positive, and a conversion that ignores either
produces a signature that is wrong perhaps one time in fifty. The failure is a
bare `401` from the push service with no explanation, so this is verified in
`PushTest` against a hundred real signatures rather than reasoned about. The
first version written here was wrong, and looked fine.

**The subject is the portal's own URL**, and no push is offered without one.
VAPID's `sub` is meant to let somebody at Google or Mozilla reach whoever is
sending; an invented `mailto:` would be a lie told to a stranger having a bad
day. No URL configured therefore means `available: false` — not a push sent
with a plausible-looking placeholder.

**Every message knocks, unlike the e-mail.** §2.33 debounces mail because an
inbox with forty lines from one conversation is a mailbox nobody keeps. A
notification is not that: the browser collapses repeats under one `tag`, and
one that arrives while the member is already reading costs nothing. The two
channels differ because the media differ, not by oversight.

**Failure is swallowed, and death is believed.** `knock()` never throws — a
push is a courtesy on top of a message that is already stored and already
readable, so a push service having an afternoon must not turn into a member
being told their message failed. But a `404` or `410` is not an outage, it is
the service saying that device is gone for good, and the row is deleted. Every
other status is left alone.

**The device is the unit, and it may change hands.** Endpoints are unique in
the table, so a browser that re-subscribes the same device — which they do on
their own when a push service rotates addresses — updates a row instead of
collecting them, and a shared tablet that is handed to somebody else moves to
the new account rather than knocking twice for two people. Uniqueness hangs on
a hash of the endpoint, because databases disagree about how much of a `text`
column they will index.

**The notification's own text is German and fixed.** The service worker has
no access to the language preference — that lives in `localStorage`, which a
worker cannot read — and the alternative, `navigator.language`, is the phone's
language rather than the member's choice in the portal. Since the whole text is
one sentence that says nothing, the honest options were a fixed German sentence
or a guess that would be wrong for exactly the member who bothered to change
the setting. If a second language ever matters here, the way to do it is to
have the client write its choice into the cache the worker already owns, at
sign-in.

**And the screen says all of this before anybody agrees to it.** The sentence
under the switch is *"Auf dem Sperrbildschirm steht nur, dass eine Nachricht da
ist. Weder der Name der Person noch der Text werden angezeigt."* A member
deciding whether to switch this on is entitled to know what the person next to
them on the sofa will be able to read; that assertion is the first test in
`Notifications.test.tsx`. A browser that is already blocking notifications gets
an explanation of where its own switch is instead of a button that would do
nothing — §2.33's rule, applied on purpose this time rather than after somebody
reported it.

### 2.37 The one thing the silence was allowed to say

The number search answers a number nobody carries exactly as it answers a
number belonging to somebody unlisted (§2.32). That is what lets a member who
stayed out of the directory be reachable at all, and it is worth what it
costs — except that it was also swallowing a case where there was nothing to
protect.

Type the number of somebody already in your contacts. `link()` makes no second
row, so nothing was wrong with the data; the *answer* was wrong twice over. A
listed contact came back as "you are now connected with Dieter", which reads as
though the number had just done something. An unlisted one fell into the quiet
branch and came back as "if that number belongs to a member, your request is on
its way" — untrue, since nothing was sent, and it left the member waiting for
an answer that could not arrive. Discretion that misleads the person it is
protecting is not discretion.

So an accepted connection is checked before anything else is decided, and
answered by name whether or not that member is listed. **Naming them discloses
nothing**: they are this member's own contact, and their name is already on the
other half of the same screen. The silence exists to stop the search becoming a
way of asking who has an account; about somebody in the address book, that
question was answered when they accepted.

**A request still waiting for an answer keeps the silence.** This is the line,
and it is not the same line. "You already asked this person" would say that the
number belongs to somebody — the exact fact the quiet answer withholds — and it
would be an oracle anybody could work: type a number, get nothing, type it
again, and the second answer tells you what the first would not. Typing a number
twice must tell you no more than typing it once.

One case does come out of the silence with it: a request that **crosses** one
coming the other way. Typing the number of somebody who has already asked you
is the answer to their request, and `link()` accepts it on the spot (§2.32).
Here that can mean nothing else — the crossing path is the only way an
unaccepted request becomes accepted at this call — and their request was in the
member's own list, under their name, before a number was typed. Staying quiet
would have reported as waiting something already settled.

The result grew a third `status`, `already_connected`, rather than reusing
`connected`. "You are now connected" and "you already were" are different
sentences and only one of them is true; a screen cannot say the right one from a
status that means both.

### 2.38 The way in, rather than a sentence about where the way in is

§2.33 records a mistake made twice — rendering nothing where a member could
have done something — and the fix applied to the empty conversation list: a box
saying that a conversation starts on the other person's page. That was true and
it was still one screen short. **Knowing where the way in is, is not the same
as being able to take it.** A member standing on *Nachrichten* wanting to write
to their sister had to leave the screen, remember the directory exists, find
her, and press a button there — four steps, the first of which is a piece of
knowledge nobody has.

So the heading carries a button, and it carries it at all times rather than
only while the list is empty: writing to somebody is not a thing a member does
once, and a control that disappears after the first use is a control that has
to be re-discovered.

**Contacts, because that is the list a member thinks in.** They are also not
the whole set of people who may be written to — anybody listed in the directory
may be, and that rule is `MemberMessages::send()`'s, not this screen's. Rather
than quietly narrowing it, the picker offers the directory underneath. Nothing
about who may write to whom changed here; this is a second door to `POST
/conversations`, which already existed and already knew the rules.

**Two omissions worth naming.** A contact whose request has not been answered
yet has no profile row, so there is nothing to open — their name is left out
rather than shown as a button that fails, which is the same principle as the
blocked-notification case in §2.36. And a member with no contacts at all is
sent to the contacts screen, not left looking at an empty list: the screen
after this one has to be somewhere.

**The search appears above eight names.** A search box over four is furniture;
over thirty it is the screen. The threshold is the entire logic, so both sides
of it are tested — a family where everybody knows everybody will never see it,
and one where a member has forty contacts will not scroll.

**Back is the messages screen, not the picker.** The navigation is a replace:
this list was a step on the way to a conversation, and a member who has arrived
in the conversation is finished with it. Pushing it would put a screen nobody
wants to return to between them and where they started.


### 2.39 The e-mail was still carrying everything the push refused to

§2.36 built the push notification around one condition — nothing about the
message may reach a lock screen — and then left the older channel exactly as it
was. Asked whether these messages also send an e-mail, the answer turned out to
be yes, and the e-mail contained **the full text of the message and the
sender's name**, with the sender's address in the `Reply-To`. The portal was
refusing to put a name on a lock screen while posting the whole conversation to
an inbox.

That is worse than the lock screen, not better. An inbox is read by whoever
holds the phone, sits at the shared computer, or runs the mail server — and
unlike a notification, it is kept.

**So a conversation announces itself and says nothing else.** Not webtrees'
`deliverMessage()` any more, which is built to carry a message because a
one-shot message has nowhere else to live. A conversation does: it is on a
screen both people sign in to. What goes out is a knock with a link, written in
the recipient's language, from the site's own address, with the site as the
`Reply-To` as well — answering by e-mail would answer into a void.

`send()` and `reply()` are untouched. They are one-shot messages, the address
*is* unavoidable there, and §2.28's rule stands: an unavoidable disclosure that
nobody mentions is worse than the disclosure. What changed is that the two
cases are no longer the same case.

**The duplicate went with it.** `deliverMessage()` also files a copy in
webtrees' `message` table, so every first message of a conversation appeared a
second time under *Sonstige Nachrichten*. Not filtered out afterwards —
never written. The inbox is for what has nowhere else to go.

One consequence worth naming: a member whose webtrees contact method is
internal messaging only now gets no e-mail for a conversation, where before
they got the filed copy. They are not un-told — the message is in their
conversation list and the navigation carries the count. That is the same
argument §2.28 used in the other direction, when a filed copy stopped being a
copy nobody reads.

**And the sentence on the screen was wrong for a day.** It said the sender's
address travels with the message, which was true when the button under it sent
one and stopped being true when the button started opening a conversation.
Worse, it lived only on the other person's page, so §2.38's new way in walked
straight past it. It now says what is true — the other person is told that
something is waiting and nothing else — and it says it next to the box a
member types into, which is the one place everybody who writes will see.

Rows already filed by the old path stay where they are. They are real messages
that were really delivered; deleting somebody's post to tidy up a changed mind
is not the portal's to do.


### 2.40 A phone on its side is not a desk

The bottom navigation bar stopped staying at the bottom, and the report was
right. The bar had carried the same rule since Phase 1: fixed to the bottom of
the screen, and `sm:static` — into the flow of the page — from 640px up. That
reads as "phone below, desktop above", and it is not what it says. **It asks
about width and nothing else.** A phone held sideways is around 780px wide and
390px tall, which is the widest and the *least* roomy the screen ever gets, and
the width alone was enough to hand it the desktop layout: the bar left the
bottom edge and scrolled away with the page.

So the condition asks about both directions — a `wide` variant, `(min-width:
40rem) and (min-height: 40rem)`. A window that is genuinely roomy gets the
in-flow bar; everything else keeps the bar under the thumb.

The e2e test turns the phone and asserts that the bar's bottom edge is the
screen's bottom edge after scrolling. Written against the old rule first, where
it reported the bar sitting 2941px down a 390px screen — worth doing, because a
layout test that was never seen to fail is decoration.

**And the same bar was standing in the home indicator.** The page is drawn edge
to edge (`viewport-fit=cover`, since Phase 1), which on a phone with a gesture
strip rather than buttons means `bottom-0` is *behind* that strip. Nobody
noticed while the portal lived in a browser tab, because the browser's own
chrome sat there; installing it as an app took the chrome away and left the row
of buttons under the indicator. `env(safe-area-inset-bottom)` as padding on the
bar, and the same amount added to the gap under the content, so the last line
of a screen is not under the bar either.

Worth keeping in mind for anything else pinned to an edge: `viewport-fit=cover`
is a promise to handle the insets, and until now nothing did.


### 2.41 The offer belongs where the member notices it is missing

§2.38 put the way into a conversation on the screen a member is standing on,
rather than a sentence about where it lives. Inviting had the same shape of
problem: the invite screen is reached from *Einstellungen*, and the moment a
member realises their uncle is not in the portal is somewhere else entirely —
walking the tree, on his page. Acting on it meant remembering the screen
exists, going there, and finding him a second time on a list.

So his page carries the button, and the button carries him: `/invite?xref=X4`
arrives with that person already selected, one tap from the link.

**The offer says nothing the invite screen does not already say.** It is shown
for exactly the people `GET /invitations` already returns as candidates — the
same walk, the same access level, the same limit — so no new person and no new
fact is disclosed by opening somebody's page.

**And its absence stays uninformative, which took the most thought.** The
candidate list was built so that dead, already an account holder, already
invited and too distant are one answer, because "your brother already has an
account" routes around the consent the member directory rests on. A button that
appeared for exactly one of those four would have undone that from a different
screen. It appears for candidates and for nobody else, so "no button" means the
same nothing it means on the list.

The quota is treated the same way: at zero remaining there is no button, because
the server would refuse and a button that will be refused is worse than none.

**`?xref=` is a starting position, not an authority.** The list stays the list,
the choice can be changed, and an XREF nobody offered simply selects nobody —
the server re-checks every rule on POST regardless, which is what
`createInvitation` was written to do.


### 2.42 One card for both links, because both are handed over once

The invitation link had a text field and nothing else: select it by hand, and
mind you get all of it. The connection link, built later, already had **Teilen**
and **Kopieren** — so the two screens that do the same thing did it
differently, and the older one did it worse.

Not a second implementation, then: `components/ShareLink.tsx`, used by both.
The connection link lost four lines and gained nothing it did not have; the
invitation link gained everything.

**Why it matters more here than for an ordinary link.** Both of these are shown
*once* — the server keeps a hash, so what is on the screen is the only copy
there will ever be. A member selecting a URL by hand on a phone is how half a
link ends up in a chat, and the repair is to withdraw the invitation and issue
another. A button that takes the whole thing is not a convenience.

**Teilen when the browser has it, Kopieren always.** Sharing is absent on most
desktops, and a desktop is where somebody sits when they write the e-mail —
so copying is not the fallback nobody sees. §2.33's rule again: silence is
right for something impossible and wrong for something merely harder.

**A cancelled share sheet says nothing.** `navigator.share()` rejects when the
member backs out, which is an answer rather than a failure; a refused clipboard
is silent for the same reason, since the link is on screen and selectable
either way.

One trap, found by the tests failing: `userEvent.setup()` installs a clipboard
stub of its own, which quietly replaces the one under test — so a clipboard
assertion written the modern way passes whatever the code does. The direct
`userEvent.click()` calls leave the globals alone, which is why the connection
link's test already used them.


### 2.43 A service worker cannot route a React app

Tapping the notification opened the app and left it exactly where the member
had been. The handler looked right:

    const clients = await worker.clients.matchAll({ type: 'window', includeUncontrolled: true })
    for (const client of clients) {
      void client.navigate('/messages')
      return client.focus()
    }

**`navigate()` is only allowed on a client the service worker controls**, and
`includeUncontrolled: true` asks for exactly the clients it does not — a window
loaded before this worker took over, one claimed by another registration. On
those it rejects. The `void` swallowed the rejection, `focus()` ran anyway, and
the app came up unmoved. Every part failed quietly, which is why it took a
report from a phone rather than a test.

**So the running app is asked rather than steered.** The worker focuses the
window and posts `{type: 'portal:navigate', path: '/messages'}`; the app
listens and calls the router. That is more reliable than a navigation imposed
from outside and also better: no reload, no second render of a screen already
on the glass. A window running an old build simply gets focused, which is no
worse than what it did before.

Three details that are each one line and each a bug otherwise:

- **Focus first, and ignore its failure.** On a phone the tap has already
  brought the app forward; a `focus()` that rejects must not cost the member
  the navigation that was the point of tapping.
- **A path, not a URL.** `//elsewhere.example/messages` starts with a slash and
  is a URL — a page hears from extensions and frames as well as from its own
  worker, so the check is `startsWith('/') && !startsWith('//')` and the shape
  of the message is checked rather than assumed.
- **Hold the `serviceWorker` reference for the cleanup.** Looking it up again on
  unmount throws where `navigator` is no longer the same object, and a cleanup
  that throws takes the unmount with it. The tests found this one.

The logic moved out of `service-worker.ts` into `sw/notify.ts` for the same
reason `strategy.ts` exists: it is the part worth testing, and it can only be
tested if running it does not require a service worker.


### 2.44 A card each is a list; a line each is a choice

The invitation screen showed one card per relative — a radio button, the
relationship, the name, the years — which is right for a list somebody reads
and wrong for a choice somebody makes once. With a handful of close relatives
it pushed the button off the bottom of a phone, and with it the link that
button produces: the member pressed *Einladung erstellen* at the bottom of the
screen and the one thing they had come for appeared above the whole list,
behind them.

Two changes, and the second is the one that was actually reported.

**A dropdown.** One line per person, `Ihr Bruder — Dieter Beispiel (1990–)`.
The relationship still comes first, because that is what makes it obvious the
right person is about to be picked, and the years are kept because a family
tree has more than one Dieter Beispiel and they are what tells two apart. A
native `<select>` rather than a built one: on a phone it is the wheel the
member already knows, it is searchable by typing, and it costs nothing to make
accessible.

**The link under the button.** It was rendered at the top of the screen, which
was fine while the form was one line long and wrong the moment it was not.
Where a member is looking after pressing a button is at the button. The e2e
test asserts `toBeInViewport()` rather than mere visibility, because "in the
document" was true the whole time it was wrong.


### 2.45 The card is the target, not the name in it

Two screens named in one report — Kontakte and Mein Profil — and they turned
out to be two different faults with the same appearance.

**Kontakte was really a name-sized link.** One card per contact, and only the
name inside it navigated. On a phone that is a thumb-sized miss in a
card-sized row, and it is inconsistent with every other list in the portal:
relatives, conversations and directory rows have all been whole-card links
since the tree was first walkable. The card is now the link. Nothing
interactive sits inside it — ending a connection is asked for on the member's
own page, §2.x's reason — so there is nothing a link may not contain.

**Mein Profil already was one, and looked like it was not.** The relative rows
have always been whole-card links; the name inside them was underlined, which
says the opposite — that the word is the target and the rest of the row is
decoration. The underline is gone. What is left is a name in a row you can
tap, which is what it is.

Worth keeping as a rule: **underline a link inside a card only when the card is
not one.** A contact who has no profile row yet is exactly that case — nothing
to open, so the card stays a card, and it does not pretend otherwise.


### 2.46 Asked once is a different thing from asked always

`InstallPortal` carries an argument against putting the install offer in front
of members: a prompt that follows somebody around teaches them to dismiss
whatever appears at the top of this portal, and the next thing to appear there
is "no connection", which they need to read. That argument is about a
**standing** banner and it still holds — the offer still lives in Settings.

This is the other thing: asked **once**, after signing in, and never again on
that device. What makes it acceptable is entirely in that sentence, so all of
it is enforced:

- **Once.** The answer is written to `localStorage` before the dialogue can be
  shown a second time. One flag, `portal.install.offered`, saying the question
  has been asked — a device preference in exactly the sense the language is,
  and now the second and last thing the portal keeps in browser storage.
- **In one tap**, with Escape and a tap outside doing the same thing as the
  button, because those are what people try.
- **Costing nothing.** The dialogue says the offer stays in Settings. A member
  who taps "Später" — or taps it by accident, which on a phone is the same
  thing — has not lost the app.

**Two states are deliberately not stopped on their way in.** A browser where
installing cannot happen says nothing, as everywhere else. And so does another
app's browser: that case needs "leave this app first" (§2.30), which is a
sentence for a screen a member went looking at, not for a dialogue they did not
ask for. Settings still says it.

**What the e2e run proved before the test did.** Adding this turned nineteen
green walks red at once — every one of them stops on the first tap after
signing in, because a modal on the first screen stops everything. That is the
feature working, and it is also the cost, which is worth remembering the next
time something wants to be a dialogue. The walks now pre-answer the offer
through a helper, and the offer has a file of its own — clearing the flag from
an init script does not work, because that script runs again on the reload the
test needs.


### 2.47 The second offer, and why it waits for the first to have worked

Notifications reach a member only through the installed app — on iOS that is
not a preference but the whole mechanism — so the moment worth asking is the
first run of that app, and `standalone` is what says so. Asking in a browser
tab would be asking for something the member cannot have yet, and a member who
says no to a question they could not have answered has said no for good.

It mirrors `InstallPrompt` deliberately, down to the shape of the dismissal:
two dialogues that behaved differently would be two dialogues to learn. Asked
once, remembered before it can be asked twice, one tap either way, and the
sentence saying the switch stays in Settings — because that is what makes
asking once fair rather than a single chance.

**It says what a lock screen will show before the browser's box appears.** Not
after, and not in the settings screen the member may never open: §2.36 built
the whole feature around the promise that a notification names nobody, and the
person best placed to care about that is the one sitting next to somebody on a
sofa deciding right now. `notifications.privacy` is the same sentence Settings
uses, in the same words, one screen earlier.

**Three states get no dialogue at all**, and each for its own reason: a family
that has switched notifications off has nothing to offer; a browser already
blocking can only be undone in its own settings, which is a sentence for a
screen somebody went looking at (§2.46 said the same about a chat app's
browser); and `granted` means this is already arranged.

The e2e walks now answer both offers before they start. The notification one
should never appear there — no walk runs in standalone display mode — but a
dialogue that turns up unexpectedly stops every test, and the failure looks
like anything except what it is.


### 2.48 A number on the icon, and the number the worker does not have

The navigation bar has carried the unread count since Phase 10. Putting it on
the home-screen icon is the same number one surface further out — and that
surface is the only place the portal says anything about a member outside its
own window, so it says a **number and nothing else**. No name, no text, no
sender: §2.36's line for the lock screen, drawn again where the same argument
applies for the same reason.

**The interesting half is the service worker's.** A push arrives while the app
is shut, and the worker does not know how many messages are waiting — the push
carries no payload, deliberately. The obvious repair is for the worker to ask
`/api` for the count, and that is the one thing `strategy.ts` says this worker
never does. So it doesn't: `setAppBadge()` with no argument is the flag every
platform draws for exactly this case — *something is there* — and the app
replaces it with the real number the moment it is opened. The absence of a
count in the worker is the payload-less design showing through, not a gap in
it.

**Clearing is the part worth testing.** Setting a badge is decoration;
*failing to clear one* leaves a stranger's unread count on the icon of a phone
that has been handed to somebody else. The layout is mounted only while
somebody is signed in, so its unmount is exactly the moment the count stops
being anybody's — and that is where the clear lives.

Every call is guarded twice, because this API misbehaves in both directions:
Safari rejects it until notifications are allowed and in a plain tab, and some
browsers throw synchronously rather than rejecting. A badge nobody can see is
not worth reporting to anybody — there is nothing they could do, and the count
is on the screen in front of them.


### 2.49 The right two taps, at the wrong end of the phone

The iOS instruction said *"Tippen Sie **unten** auf das Teilen-Symbol"*, which
is true in Safari and wrong in Chrome, where it is at the top. Reported by
somebody who went and looked.

The state machine had one state for all of iOS, and that was right about the
thing it was built to answer — every browser there is WebKit underneath, none
of them has `beforeinstallprompt`, and the way in is the same Share sheet and
the same two taps. What it was not right about is the only thing the sentence
actually says: **where the button is.**

So `apple` splits into `apple` and `appleOther`, told apart by `CriOS`,
`FxiOS`, `EdgiOS` and `OPT` in the user agent — Safari being what is left.
Nothing else in the portal changes: same state machine, same offer, one more
sentence.

**The new sentence names both ends.** "Tippen Sie oben … In Safari sitzt dieses
Symbol unten." Not tidiness — a member who has been sent to the wrong end of
their phone once will not trust the next instruction either, and this
portal's audience is exactly the audience that looks where it is told and then
gives up. Saying where it is in the other browser costs nine words and buys
back the sentence's credibility.

Worth remembering as the general shape: a state that answers "what can be
done here" is not automatically the right state to hang "how do you do it" on.
This one was, until the how differed and the what did not.


### 2.50 Phase 15: a face is the least deniable thing on a record

Photographs came from webtrees, filtered by webtrees' access level, and were
uploaded by whoever keeps the tree. So a living member's face could be in front
of a hundred relatives without that member ever having put it there.

The portal already had the argument, one field over: *contact details held in
the family tree are never published — they are maintained by whoever keeps the
tree, and nobody can consent to "whatever my record happens to say".* A
photograph is the same sentence with more at stake.

**The rule: for a living person, only what they uploaded themselves.** For
somebody dead, nothing changes — nobody can consent on their behalf and nobody
needs to, because consent is a question about people who can be harmed by the
answer, and the family archive is what a portal like this is *for*. That split
is one `isDead()` and it is the whole design.

**Enforcing it needs a fact webtrees does not record.** A GEDCOM media object
says what a file is, not who put it there; in webtrees that question has one
answer and it is "the person with the keys". `portal_photo` is that missing
fact, and a row in it *is* the consent — which is why the foreign key cascades:
an account deleted takes its permissions with it and the photographs stop being
shown. They are not deleted from the tree. That is the family's record, and
withdrawing permission is not the same as pruning it.

**A rule that hides without a way to add is not a privacy feature**, it is a
portal with no faces in it. So the upload shipped in the same change, and three
things about it are worth keeping:

- **Re-encoded, never stored.** A phone writes GPS into every picture; a member
  sharing their face would be publishing the address they took it at. Decoding
  the pixels and writing a fresh JPEG drops every tag there is, which is more
  complete than deleting the ones anybody has heard of — and it answers "is
  this actually an image" in the same operation. The test asserts the metadata
  directory is gone and the tag content with it, and asserts the *incoming*
  file had one, so it cannot pass against a camera that writes none.
- **Live at once, unlike every other edit** a member may make. A name is a claim
  about a person and waits for the family; a photograph is permission *from*
  one, and waiting for somebody else to approve your own consent has the thing
  backwards.
- **Except where an edit is already waiting.** webtrees' pending changes are
  snapshots of the whole record, not patches — accepting the photograph would
  accept the unapproved name change sitting under it. So there the photograph
  waits too, and the member is told. Found while writing the code rather than
  after; it is the kind of thing that would have quietly approved somebody's
  edits for months.

**And the screen says the rule.** A member whose picture vanished from their
own record the day this shipped is owed the reason in words, on the screen
where they can put it back — not left to guess that it was a bug.


### 2.51 The number belongs on the card, not one tap further in

The reference number was on the full record and nowhere else, so every card in
the portal showed a name and years and left the reader to open it to find out
*which* Dieter Beispiel this was. In a family with more than one of several
names, that is the question the card exists to answer.

So `individualRef` carries `references` now — the same list, from the same
facts, at the same access level the full record uses. **Nothing new is
disclosed by it**: a confidential `REFN` is filtered out of the short shape
exactly as it is out of the long one, which is what the second new test in
`PrivacyTest` pins. What changes is how far a reader has to go to see what was
already theirs to see.

Formatting moved to one place while doing it. Four cards each joining
`type` and `number` their own way would be four subtly different numbers as far
as a reader is concerned; `referenceLabel()` is the one answer, and it puts the
type in front — "SB 4714" is how the family says it, not a bare 4714.

The years and the number share one line and one element, so a person with only
one of them does not leave a blank line where the other would be. Small, and
the sort of thing that looks like a rendering bug when it happens on every
second card.

### 2.52 A subscription is not session state, in both directions

The question was whether notifications survive being signed out. They do —
`knock()` reads a row against a user id and never asks who is signed in, and
the browser's own subscription lives in the service worker, which has no
session to lose. Which is right for the case the feature exists for, and wrong
for the other one, and those two had been treated as one thing.

**An expired session is the case, and it already worked.** A phone that has
been in a pocket for a fortnight has no cookie left; a knock arrives anyway,
the tap opens */messages*, `RequireSession` puts the member on the login screen
carrying that path in `state.from`, and the message is there a moment later.
Nothing to change: this is the whole point of a subscription that outlives a
session.

**Signing out is not that case.** Nobody's cookie expired; somebody pressed
*Abmelden*, which on this portal's audience most often means a shared tablet or
a phone being handed over. The subscription stayed, so the device went on
announcing that something had arrived for the person who had just left — and
the switch that would stop it is in *Einstellungen*, behind the sign-in they
just left. It discloses nothing (the notification carries nothing to disclose,
§2.36), but *„eine neue Nachricht"* on a tablet in the kitchen is still a fact
about somebody who is no longer using it.

So `signOut` forgets the device first, and the order is the whole of it:
`DELETE /push` is authenticated by the session it is deleting itself out of, so
after `Auth::logout()` it would be a 401 and the row would survive. That
ordering is what the test asserts, because it is the part that will look
arbitrary to whoever moves these two lines.

**It is not the same function as the switch in Settings, on purpose.**
`switchOff` reports a failure to a member standing in front of it, having just
tapped it. `forgetThisDevice` swallows everything: nobody asked about
notifications, they asked to be signed out, and that has to happen whatever the
push service is doing. A row that outlives its browser subscription is deleted
by the first knock that gets a 410 (§2.36) — the failure repairs itself, so
there is nothing to report and nothing to hold up a logout for.

**The wait is bounded, which the first version was not.**
`navigator.serviceWorker.ready` is a promise that never settles in a browser
that supports service workers and has none registered — a tab where
registration failed, and every tab in the moment before it finishes. Awaiting
it unbounded put the whole of *Abmelden* behind it: press the button, watch it
go grey, stay signed in. Three seconds and then sign out regardless. Nothing is
lost by giving up, because a push subscription cannot exist without a
registration — a `ready` that does not arrive means there was no device to
forget.

Its test cost more thought than the code. Vitest's fake timers are not
recognised as fake by Testing Library, so `waitFor` and `userEvent` both sit
waiting on a clock that now only moves when asked, and the test hangs rather
than failing. `fireEvent` plus an explicit `advanceTimersByTimeAsync` inside
`act` drives it with no hidden timer of its own. `vi.useRealTimers()` belongs in
`afterEach` and not at the end of the test that turned them on: a test that
times out never reaches its own cleanup, and the fakes then leak into the next
test, which fails somewhere unrelated.

**And the card says so before anybody agrees to it**, in the same spirit as the
lock-screen sentence: *„Wenn Sie sich abmelden, wird das auf diesem Gerät wieder
ausgeschaltet."* A member who finds notifications silently off after signing
back in has been surprised by their own portal.

### 2.53 Angemeldet bleiben, and the two failures that are not theft

§2.52 established that the session dies with the browser (`lifetime => 0` in
webtrees' `Session::start()`), and that the server-side row behind it goes a
few minutes later. For an administrator at a desk that is correct. For the
member this portal exists for — a telephone, opened twice a month, a password
that has to be found again each time — it is most of the reason the portal
feels like a website rather than an app.

**The obvious implementation is the wrong one.** Lengthening webtrees' session
cookie would have been four lines, and it changes the security posture of the
control panel for every editor and administrator on the site to solve a problem
the portal has. PHP's own garbage collection would reap the session anyway, so
the cookie would outlive what it points at and the member would be signed out
at a time nobody could predict. So: a second credential, which is what this is
for.

**Everything about the storage is `portal_invitation` again** (§2.22). A series
and a token in a cookie, a SHA-256 of the token in the table, and the value
itself in one browser and nowhere else. A database is backed up, dumped and
read by more people than were handed the credential.

**The token rotates, which is the whole reason for the series.** A single
long-lived token can be stolen and used for thirty days without anybody ever
finding out; a rotating one cannot, because the thief and the member end up
presenting the same series with different tokens and the second one to arrive
is holding something already spent. Neither can be identified as the member, so
neither is trusted: every remembered device for that account goes, and the
authentication log says why. An occasional unexplained sign-in is the price,
and it is the right way round — the alternative is a silent theft that works
for a month.

**But two ordinary things look exactly like that, and both had to be answered
before this could ship.**

The first is two requests leaving one telephone together. A retry, a double
tap, a connection that dropped halfway: both carry the token that was current
when they were sent, and the second arrives after the first has replaced it.
Punishing that would mean a flaky connection signs a member out of every
device they own. So `previous_hash` and `rotated_at`: the token one step back
stays good for a minute, and only something older is theft. Sixty seconds is
long enough for the requests that genuinely crossed and far too short to be
worth stealing.

The second is subtler and was found by writing the test rather than by
thinking. **A token is spent the moment it is read**, so its replacement has to
reach the browser — and a `Set-Cookie` cannot ride on an exception. Ask for a
record you may not see, get a 404 from `IndividualRead`, and the reply that
carries the refusal was carrying no cookie: the device had just spent its token
and been given nothing back, and its next visit would have been treated as
theft. That is a lockout caused by a 404. `ResumeRememberedSession::answer()`
catches `ApiException` and builds the same reply `ApiEnvelope` would have —
which is safe precisely because the envelope records none of them, an
`ApiException` being a refusal this module worded on purpose. Anything else
still belongs to the envelope, which has a reference to hand the member and a
log to write.

**Where it is honoured is a design decision, not an implementation detail.**
The middleware sits on the module's own route map and nowhere else, so a
remember cookie opens the portal API and cannot become a way into webtrees'
control panel. The Worker rewriting it host-only to the portal's origin
(§2.30) makes the same point from the other end: the webtrees host never sees
it.

**Off by default, and the switch says what it costs.** This is a key left on a
device — whoever picks up an unlocked telephone is that member, with no
password, in a portal about living relatives. That is a judgement about a
family's telephones rather than about software, so an administrator makes it,
the setting is a number of days rather than a boolean, and the login screen
states the number it was given. A switch promising "stay signed in" without
saying until when is a promise the member has no way to check.

**Which forced `remember_days` onto `GET /csrf`.** Everything else a screen
needs to know about what this portal allows arrives with `GET /me`, and the
login screen is the one screen with no session and therefore no `/me`. So the
one endpoint it can already reach carries it. It discloses nothing — a setting
about this portal's own login, identical for every member and every visitor,
on an endpoint that exists to be called before anybody is known — and it warms
the CSRF token that the submit needed anyway. A failure is deliberately silent:
"we could not find out whether you may stay signed in" is not a sentence to put
in front of somebody who came here to type a password.

**Not ticking the box is an instruction.** `remember: false` revokes and clears
whatever cookie the browser is holding, rather than leaving it alone. A member
who ticked it last month and did not this time has said something, and a
sign-in that ignored it would leave a device remembered that its owner has just
declined to have remembered.

**And a password reset ends it everywhere.** The one place `forgetAll` is
right: somebody resetting a password is quite often somebody who believes
another person is in their account, and a new password that leaves a month-old
cookie working on a device they no longer hold would answer that with nothing.
Everywhere else, one device is one device.

**The iPhone was reading a blank space.** §2.33's rule is that silence is right
for something impossible and wrong for something merely harder, and this had
been filed under the wrong one. iOS has no push API in a Safari tab *at all* —
not a refused permission, the objects do not exist — so `permission()` answered
`unsupported` and the whole section returned `null` for the largest single part
of this audience. Nothing on screen said the feature existed, let alone that
one action away it would.

The fix is one sentence, shown for `install === 'apple'` and nothing else. The
distinction is worth the condition: an old iOS that is already `standalone`, a
desktop browser with no push, Android's WebView — installing changes nothing
for any of those, so they keep the silence they had. Only the case where the
way in is real, and one section up the same screen, gets told about it.

Deliberately *not* repeating `install.apple`'s Share-sheet instructions in the
second card. §2.38 prefers the way in to a sentence about where the way in is,
but the way in is already rendered directly above by `InstallPortal` — two
identical instructions on one screen is not §2.38, it is noise. The sentence
names that section instead, and says what installing buys, which is the part
that section does not know.

---

### 2.54 Phase 16: a search is a different disclosure from a walk

Until now every route to a person went through somebody. Your own record, a
relative of a relative, a member in the directory — and each of those is a
reason to be looking. "Wie könnten wir noch eine Möglichkeit schaffen die
genealogische Datenbank zu durchforsten" is a request for the first screen that
has no such reason: type a name, get a list.

That difference is the whole of the design. Everything else about this phase —
two endpoints, a screen with three tabs — is ordinary. The part worth writing
down is that **webtrees' access level was not enough on its own**, and why.

#### The rule, and why it is the directory's rule

A member can already see most living people in the tree; §2.25's setting is
the only thing that narrows it, and it is off by default. So a search built on
the access level alone would have handed every member a complete, searchable
list of every living relative — names, years, archive numbers, one page at a
time. Nobody in that list agreed to be in it. They agreed to nothing; they
appear in a GEDCOM.

The rule is therefore: **the dead are findable, the living are findable only if
they listed themselves in the member directory.** `SearchConsent` is the whole
of it, and it is deliberately not a new consent question. The portal already
has exactly one recorded answer to "may this person be listed to every member",
and asking a second, nearly identical question would mean two switches that can
disagree and a member who has to find both. One switch, one meaning: leaving
the directory now leaves the search too.

Three things follow, and each is a test:

* **It narrows, never widens.** It runs after `canShow()`, so listing a
  confidential record in the directory does not surface it. A rule that could
  only ever hide is the direction worth being wrong in.
* **It is not a hiding rule.** A member who stayed out of the directory is
  still reachable by tapping through the family. What they are not is
  *enumerable*, which is a different property and the one at stake here.
* **The counts inherit it.** The surname and place indexes count people the
  reader may find, not people in the tree. Two members can honestly see
  different numbers, and that is correct rather than a bug.

#### The indexes read the records; the search queries

Two code paths, on purpose. A search has a term, so the database finds the few
rows that match and the work is proportional to the answer. An index has no
term — "which surnames are here, and how many people has each" cannot be asked
in SQL without ignoring both rules above, and a count that ignores them is a
count of people the reader may not see.

So the indexes scan, bounded by `MAX_SCAN`, and the response says `truncated`
when the bound is reached. That is the honest failure: a shorter list that
does not admit it is a list a member will believe.

Both indexes come back from one endpoint because both come from one pass. Two
endpoints would have meant doing the expensive thing twice for a screen that
shows them side by side.

#### The number, written as the family writes it

Searching by archive number nearly shipped as digits-only — strip everything
that is not `0-9`, compare. It finds "4712" and loses "10/1335.21", which is
half of how this archive numbers things. What it requires instead is that the
query contain a digit at all, which is only there to keep every name search
from dragging the GEDCOM through a `LIKE`.

The `LIKE` is a way of not reading the whole tree, not the test. Rows come back
loosely and the number is then compared against the REFN facts *the member may
see*, so a confidential number finds nobody — the same answer the record gives.
`%` in a query is turned into `_` rather than escaped: escaping needs an
`ESCAPE` clause, and SQLite has no default escape character where MySQL does,
so the same pattern would mean two things on two hosts. Loosening a pre-filter
costs nothing when the real comparison happens in PHP.

#### The relationship moved onto the card

A page of search results is names and years — a phone book of strangers who
happen to be relatives. `individualRef` carries `relationship` now, so each
card says "Ihre Großmutter" and the list becomes a list of family.

That made the walk in `RelationshipNamer` twenty-five times as expensive, so it
stopped being a walk per question. The reader's neighbourhood is walked once
and cached for the request; every card is then a lookup. Same algorithm, same
access level, same refusal to name a path through somebody hidden (§2.25) — one
pass instead of twenty-five.

One thing broke, quietly, and a test caught it: `MemberInvitations` built its
candidate rows as `$reference + ['relationship' => …]`, and with `+` the *left*
operand wins a duplicate key. The reference shape had just grown a
`relationship` of its own, so the walk's answer was silently discarded.
`array_merge` where the override is meant to win.

#### There is no fifth tab

§2.32's rule — four destinations, and a fifth does not fit a 320px phone —
still holds, so the way in is on the record: beside *Vorfahren anzeigen*, which
is the other way of going further from a person. It is on Mein Profil too,
because Mein Profil renders the same component. Putting a second, identical
button there as well was written and then removed: two links to one screen,
differing in nothing, is the thing that makes people wonder which is the right
one.

#### The fixture had been lying about living people

Adding the first test that searched for a living member turned up something
older and worse than the feature. webtrees builds its `name` search index with
`Individual::getAllNames()`, which is **privacy filtered**. The test harness
imported the GEDCOM with nobody signed in, so every living person went into the
index under the literal string "Private" — unfindable by name, for ever.

In production the import is done by whoever is signed in to the control panel,
so the index holds real names. The harness was building a different database
from the one under test, and no test had ever asked the question that would
notice. `PortalTestCase` now imports as a site administrator and drops the
account again. Nothing else changed, which is the reassuring part: 364 tests
were green before and after.

---

### 2.55 The number was the answer all along

The archive numbers everybody, and the number turned out not to be a label.
Expanded — the line's prefix, then the rest with its dots dropped —
`24/b521.12` is `7d3b52112`: **one character per generation, each saying which
child.** A complete ancestral path, printed on every card in this portal since
§2.51, and nobody had noticed what it was for.

Which means the relationship between any two members of the family is a
property of two strings. Longest common prefix is the nearest shared ancestor;
the two remaining lengths are the rest of the answer. No tree, no records, no
dates.

The family has known this since 2009 — there is a PHP page that does it. This
phase is that page, ported.

#### Where it earns its place

`RelationshipNamer` walks the GEDCOM and stops at four steps, because the walk
is breadth-first over a graph that can be large and it runs on a screen a
member opens often. So a page of search results — which is exactly where
distant relatives turn up — was mostly blank in the one column that makes a
list of names into a list of family.

The walk still goes first, and that is not deference. **The tree knows things a
number cannot**: a wife, a stepfather, an adopted child. An SB number describes
descent, and describes it perfectly. So the tree answers wherever it can and
the arithmetic fills in what is left, which is nearly always the same case —
two people too far apart for four steps to reach.

#### The rule it is allowed to cross, and why that is not a hole

§2.25 refuses to name a relationship whose path runs through somebody the
reader may not see: the name encodes the shape of the path, so "your cousin"
would betray a shared grandparent the reader was never shown. This crosses that
ground without hesitating.

It is not an exception carved into the rule. It is that **an SB number already
is the path**. Both numbers are on both cards, both were filtered by webtrees'
fact-level privacy before they got there, and anybody holding the two of them
can do this on the back of an envelope. Computing it discloses nothing the two
visible numbers did not.

The line that does still hold: a number the reader may not see is not used.
`facts(['REFN'], …, $access_level)` settles it, so a confidential number is not
in the list, and the answer is silence.

Worth saying plainly somewhere the family will read it: **the numbering system
is more revealing than it looks.** That is a fact about the archive, not about
this portal.

#### The tables are family data

Two things are not in the number. The line prefixes, and the marriages between
two people who both have a number — the archive files such a couple's children
under one parent only, so the other parent's descent is invisible in the
children's numbers and a calculation that ignored it gets one side of the
family right and misses the other.

Both are in the control panel, seeded with what the original used. A new line
or a new marriage is an evening's news, not a release. An empty box means
"whatever the module shipped with", so a later correction still reaches an
installation that never edited them.

#### Ported, and checked against the original

Two things were kept that a rewrite would have argued with: `0` is not a valid
child character, and the marriage fix-up replaces the joining character with
`-` rather than splicing cleanly. Both are load-bearing — the second stops a
re-rooted branch accidentally lining up with one of the other parent's own
children.

The port was checked by running both implementations over every ordered pair
drawn from the marriage table plus a dozen hand-written numbers: **15,876
pairs, no differences.** That is what made it safe to change anything at all.

Three things did change, and each was a bug rather than a difference of
opinion:

* **A path that is all digits becomes an integer array key in PHP.**
  `7243215` is one. The original keyed its marriage table by the number and
  survived on coercion; a typed port cannot, and the harness found it on the
  first run. It is a list now.
* **The prefix belongs to each form, not to the pair.** "Urgroßvater" and
  "Urgroßmutter", not "Urgroßvater/großmutter", which is neither of the two
  things it is trying to say.
* **The sex is known on a card**, so a card says "Schwester" where the
  calculator, given two bare numbers, still says "Bruder/Schwester". Nothing in
  a number says whose it is.

#### The words are a table, not a catalogue

The module has no gettext catalogue of its own, so a string put through
`I18N::translate()` here would reach a German member in English. Two tables,
one per language, keyed by the six shapes. Honest, and it is thirty
family-specific terms rather than something a translator would ever see.

`I18N::languageTag()` returns the full tag, and the first attempt compared it
to `'en'` — so `en-US`, which is what the test harness initialises, was read as
German and every English assertion came back in German. Caught by the tests
that had nothing to do with language.

#### The calculator screen

A fourth tab, and the one screen in the portal that reads no records at all: no
name, no record, and an unissued number is answered exactly like an issued one,
so it cannot be used to find out whether a number belongs to anybody. Nothing
to disclose, therefore no rule beyond being signed in.

The member's own number is filled into the first field. The question somebody
actually has at a family gathering is not "how are these two strangers related"
but "how am I related to *this*" — and the number they are holding is the other
one, read off a card or the back of a photograph.

---

### 2.56 The lines are branches, and not everybody is on one

`GS/7D8`, in the archive, on ancestors. It means what it looks like: no line —
the rest is already the path.

The line table maps 36 numbers to 36 prefixes, and the first version of the
parser treated that table as the whole vocabulary: a number was `NN/` and then
the descent, or it was not a number. Which quietly excluded two kinds of
record. **The ancestors above the lines** have no line to belong to — the lines
*descend* from them — and they are exactly the people a relationship
calculation runs up to. And **a branch that was numbered and then died out**:
`7d8` sits between lines 28 and 29 and is nobody's line.

So `GS` is not a thirty-seventh line. It is the escape hatch that makes the
notation complete — with it every position in the tree can be written down —
and it resolves to the empty prefix, which is why `GS/7d3` and `24/` are the
same person and the tests say so.

Two small things came with it, both of which were already slightly wrong:

* **`GS/` alone now names nobody.** It would otherwise resolve to the root of
  everything, which is not somebody anybody is numbered as. A bare line number
  still names the head of that line, because that *is* a person.
* **A quoted number is matched case-insensitively.** The archive writes
  `24/b521.12` in lower case and `GS/7D8` in upper, and a member typing one of
  them into the search box should not have to remember which.

---

### 2.57 Three things the number was already good for

**A number with no oblique.** "24" and "24/" are both written in the archive
and both mean the head of line 24. The oblique is now optional — but only when
nothing follows it, because a two-digit line makes "24b6" ambiguous: line 24
descent "b6", or line 2 descent "4b6"? Not offered rather than guessed at.

That opens one hazard worth naming. A bare two-digit number is also what an
older, unrelated numbering looks like once it reaches two digits, and a record
carrying one would be read as a line head. So where a record has both, the
number carrying an oblique wins; where it has only the bare form, that is still
used. The fixture now has a bare "9" sitting *above* Dieter's "10/1335.21" in
document order, so reading them in the order they appear makes the test fail.

**A face and a kinship in the address book.** Every list in the portal has
carried a portrait since the galleries went in, except the one list a member
actually comes back to. And a contact's chosen display name, the name on their
record, and how the two of you are related are three different things — the
third was the one missing, and it is the one that makes an address book a
*family's* address book. `ContactLines` renders all four, and the incoming
request card uses it too: "Ihr Cousin 2. Grades möchte sich verbinden" is the
whole of that decision, and it was not being said.

The branch wheel stopped at 34 while doing it. There are 36 lines, so a member
of the last two had to know that typing the whole number into the second field
is the way round. `GS` is on the end of it now as well.

**The relationship before the request.** Connecting by number was "verbinden
mit 24/b6"; it is now "verbinden mit Ihrem Cousin 2. Grades". The calculation
was already there — this is one call to `/relationship` with the member's own
number and the one in the form.

The sentence under it is not a hedge. *"Es sagt nichts darüber, ob diese Nummer
vergeben ist."* Without it a member reads "Ihr Cousin" as confirmation that
somebody is there, which is precisely what `requestByReference()` refuses to
disclose and why a request sent this way comes back with no name. The
arithmetic touches no record and answers an unissued number exactly like an
issued one — that is what makes it safe to show, and the sentence is what keeps
it from being read as something else.

---

### 2.58 The progenitor, the editor, and the name half the family uses

Three corrections, and each of them is a case the design had quietly decided
without noticing it was deciding.

**`GS` is the progenitor.** §2.56 made `GS/` resolve to the empty path and then
refused it — "the root of everything is not a person anybody is numbered as".
That was wrong about this archive: the root *is* a person, the one every number
descends from, and `GS` is the only way to write him. The arithmetic needed no
change — an empty path measures like any other — only the refusal had to go.
One guard came with it: a marriage row whose path is empty would prefix-match
every number there is, so those rows are skipped. The progenitor married nobody
inside his own family.

**An editor is not a member.** `SearchConsent` narrows what a member can
enumerate, and it was being applied to everybody — including the people who
maintain the tree, who then found the search hiding living relatives from them.
That protects nobody. An editor opens the whole tree in webtrees, changes it,
exports the GEDCOM; the rule cost them the one screen that makes the portal
usable for the work they actually do, and bought no privacy at all.

It is also the line this codebase already draws twice — §2.25's "what members
can see" limit never touches these roles, and `IndividualView` offers them the
editing link — so the exemption is the consistent answer rather than a new one.
What did *not* change: a record webtrees hides stays hidden, editor or not,
because this rule only ever narrowed.

**A nickname is part of a name.** GEDCOM lets it be written inside the name —
`Bertha "Betty" /Beispiel/` — or as a `2 NICK` subtag of it. webtrees builds
every name it renders, and the `name` table its search queries, from the name
value alone. So in an archive that uses the subtag, **every nickname the family
has was invisible and unfindable**: not shown on any card, and searching for it
found nobody.

Both halves fixed, and both by treating the two spellings as one thing. The
nickname goes back into the name where the inline spelling would have put it —
after the given names, before the surname — and skipped where the name already
carries it, so a record written both ways does not say it twice. The search
gets a third pass alongside names and reference numbers, confirmed against the
NAME facts the reader may see, so a hidden name does not leak one through its
nickname.

---

### 2.59 The gesture the installed app was missing

A browser tab can reload: drag past the top, or press the button in the
address bar. **A portal opened from the home screen can do neither.** There is
no chrome, and `display: standalone` switches the browser's own pull-to-refresh
off — so a member looking at yesterday's list had no way at all to ask for
today's, and closing the app did not help, because the service worker serves
the shell from cache.

So the gesture is ours now, and **only in the installed app**. Running it in a
tab would put two pull-to-refreshes on one screen — ours firing at 72px and the
browser's at whatever it likes, one reloading the data and the other reloading
the page. That is the first thing the tests assert.

**It refetches, it does not reload.** `refetchQueries({ type: 'active' })` asks
TanStack Query for the queries that are mounted right now and no others: the
person being looked at, the list being read. A page reload would throw away the
shell, the session and the scroll position to answer a question about one
record.

Three details that are the difference between a gesture and a jump:

* **The first 40px follow the finger, the rest are dragged through treacle**
  towards a limit of 110. The screen says "this is as far as it goes" without
  anything having to stop.
* **The move listener is not passive**, because it is the one that has to hold
  the page still — but only once the pull is real. Swallowing the first pixel
  of every downward touch would make a screen that is already at the top feel
  stuck. `overscroll-behavior-y: contain` does the rest, guarded by
  `display-mode` so a tab keeps its own behaviour.
* **The indicator stays until the refetch settles**, and a failure looks
  exactly like a success. What went wrong belongs to the screen underneath,
  which renders its own error; a second one on top of the first says nothing
  new.

A spinner is nothing to a screen reader and neither is the gesture that started
it, so the indicator is a live region that says which of the three things is
happening. `motion-reduce:animate-none` on the spin: a member who asked their
phone to stop moving things asked this too, and the words still arrive.

One collision worth remembering: `Loading` is a `role="status"` as well, so on
a screen still fetching there are two. The tests ask for the gesture's own
words rather than for its role.

#### And a flake the extra component tipped over

CI went red on `Contacts.test.tsx`, on the same commit that had been green in
the push run minutes before and green five times locally. The failure dump had
"Wird geladen" in it: the screen was still fetching when the one-second
`findByRole` gave up.

Nothing here made it slower in any way worth measuring — `useInstallState()` is
a string comparison. What it did was add one more component to a render that
was already finishing at about the deadline on a two-core runner, and
`getByRole` with a name is the most expensive query Testing Library has, re-run
on every mutation while a screen settles.

So the fix is the harness rather than the test: `asyncUtilTimeout` is five
seconds now, in `src/test/setup.ts`. It costs nothing when a test passes, and a
genuinely broken one still fails — four seconds later. Raising it for the one
test that happened to be closest to the line would have left the rest of the
suite exactly as marginal.

---

### 2.60 An editor is not a member, on the other screen too

The same correction as §2.58, one screen over. A member's invitation is hedged
three ways — a distance, a quota, and a switch — because a member is being
*trusted* to decide who is family. An editor is not being trusted to: they
already decide, from the control panel, with no distance and no quota. Applying
either here never stopped them inviting anybody; it only stopped them doing it
from the screen they were already looking at, having just found the person on
it.

So the distance and the quota lift for an editor, and the switch does not. Off
is off for everybody, because that one is the family saying the facility should
not exist.

#### A list is never the rule

This is the part worth remembering. `create()` used to check the posted xref
against the candidate list, which is exactly right while that list is a
member's close family — a dozen people, built in full. For an editor the
eligible set is the whole archive, and the screen was handed the first two
hundred of it (since §2.69, none at all). Checking against *that* would have
refused number two hundred and one for no reason anybody could explain.

So there is now one method, `invitable()`, that answers about one person
without building any list, and both the screen and the endpoint ask it. The
list is a convenience; the rule is the rule.

The same move fixed the offer on a person's own page. It was working the
question out client-side from `GET /invitations`, which for an editor would
have meant shipping thousands of records to answer one question about one of
them — so `invitable` is a field on the record now, and the page asks nobody
else. One request fewer, and the comment about "a second request that must not
break the screen" went with it.

Writing that check found a bug that predated all of this: **the quota was not
part of it.** A member with none left was still offered the button on a
relative's page, pressed it, and was refused by the endpoint. An offer the
server would refuse is worse than no offer.

#### Thousands of names is not a wheel

The invite screen picks from a `<select>`, which is the right control for a
handful of relatives and useless for an archive. So the screen has two shapes,
and the server says which — `scope`, because a client cannot tell them apart
from the list: a short list is also what a small family looks like.

The editor's shape is the search the Stammbaum screen already has, over the
same endpoint. It costs almost nothing, and it composes: that search already
shows an editor the living (§2.58), which is exactly who an invitation is for,
and it already matches nicknames and archive numbers (§2.58 again), so an
editor holding a number can type it.

**The result list makes no promises about who may be invited.** It is the
archive's search, so somebody who already has an account is in it like anybody
else, and the answer comes when the invitation is issued — in the same words as
every other refusal. Filtering it would need the whole eligible set on the
screen, which is the thing this exists to avoid.

### 2.61 An entry that comes and goes is one nobody learns the place of

*Einstellungen → Jemanden einladen* was the only way in. Settings is where a
member goes to change something about themselves, and that is not the frame of
mind in which anybody thinks "my brother is not in here". Mein Profil is: it is
the screen the app opens on, the one with their own family on it, and the one
they are looking at when they notice who is missing.

So the offer stands on both, from one component — `InviteCard` — rather than
the same markup twice, because two copies of one offer drift into being two
different offers.

**Unconditional, and deliberately so.** It does not wait for the record to
load, and it is outside the branch that handles an account with no record at
all — which is, if anything, the account likeliest to want somebody else
brought in. A person's own page keeps its own conditional offer (§2.60), and
that is a different thing: that one is about *them* and is only there when they
can actually be invited. This one is about the facility.

This is §2.33's rule the other way up. Silence is right for something
*impossible* — an editor's button on a screen where nothing can be edited says
nothing useful. It is wrong for something merely **not started yet**: the
invite screen already explains every reason an invitation may not be possible
— the family switched the facility off, the account is not linked, the quota is
spent — and a sentence saying which is worth more than a button that quietly is
not there.

---

### 2.62 A language is a fact about a person

The switcher put the choice in `localStorage` and stopped there, which made it
a fact about a *telephone*. A member who reads English read English on the
phone, German on the tablet, and German again on the phone they bought this
year — and nobody could see why, because nothing was broken.

So the account decides. `PATCH /me/profile` now takes a `language`, and what
it writes is webtrees' own `PREF_LANGUAGE` — the same preference the account's
settings page in webtrees sets. That is deliberate rather than convenient: a
second, portal-only language preference would have meant a member reading a
German portal and getting English mail from the family tree, with two screens
to fix it on.

**Three details make it work rather than merely exist.**

The portal knows two codes, `de` and `en`; a webtrees site has whatever tags
its administrator left enabled, which look like `de` and `en-US`. Getting from
one to the other is exactly what `UsePortalLanguage::negotiate()` already did
for the `Accept-Language` header, so it does it here too — a bare code is a
valid header — and a language the site does not have is **refused** rather
than stored. An unusable preference sits on an account for ever, and every
later reader of it has to guess around it.

The screen changes first and does not wait for the server. The member asked
for English; making them look at German for a round trip would be the wrong
way round. A save that fails is reported, and the portal is already in the
language they asked for while they read the failure.

`localStorage` stays, and its job is now much smaller: it answers for the
moment *before* the portal knows who is reading — the login screen, the first
paint after a reload, an expired session. Signed out there is no account to
save to, and the switcher does not try.

---

### 2.63 An address is four answers

"Musterstraße 12, 29223 Celle" typed into one box is a string. Street,
postcode, town and country are fields, and the difference shows up while it is
being *typed*: a phone offers a numeric keyboard for a postcode field and its
autofill knows what a street is, and neither is true of a box labelled
"Adresse".

**The parts live beside the value rather than instead of it.** `value` is
still the whole address as one readable piece of text, and it is still what
every reader gets — nothing on the disclosure side changed, and it should not
have: somebody looking at a relative's page wants an address to read, not four
fields to reassemble. `parts` is the same address in the shape the member
typed it, which is what lets the form put each answer back in the box it came
out of.

Writing it twice is the price of two things worth having: the reading side did
not have to change at all, and a row written by an older module — one line, no
parts — is still a perfectly good address.

#### Neither half may be the only shape that works

The module ships over SFTP and the portal through CI, so the two are routinely
a version apart, in both directions. That decides the protocol:

* The **server** accepts `parts` *or* `value`. Where parts are sent they also
  decide the text, because two versions of one address with the member's own
  words in only one of them is a disagreement nobody can settle later.
* The **client** sends both — the fields, and the text composed from them by
  `composeAddress()`, which is the same composition the server does. A portal
  one deployment ahead of its module would otherwise send fields to a server
  that ignores them and empty everybody's address.
* Reading back, a missing `parts` is not an error in either direction. The
  server makes the best sense it can of the text (`partsFrom()`); a client
  that gets no parts puts the whole text in the street, which is the one place
  it cannot be wrong.

`partsFrom()` orients itself by the postcode line. "29223 Celle" is
unmistakable and it is the *middle* of a German address, so everything above
it is the street and everything below it is the country — which is what makes
a "c/o" line land with the street instead of shunting every field down by one.
It is a guess and it is allowed to be one: the member sees it in the fields
and their next save replaces it with the truth. What it may **not** do is drop
a line, because that save would then delete it.

---

### 2.64 Read before write

*Meine Kontaktdaten* opened as a live form: three text boxes and twelve radio
buttons, every one of them ready to change something. The errand that brings a
member to that screen is almost always the other one — *what am I actually
sharing?* — and the form answered it in the most awkward possible way, by
making them read the contents of input fields.

So the screen opens on the answer, in sentences: each entry, who may see it,
and "Nicht angegeben" for the ones that are not filled in. The form is behind
*Kontaktdaten ändern*.

Two things this is careful about. **Nothing is hidden**: what is put away is
the machinery for changing the entries, not the entries, and an entry that
does not exist is listed as not existing — leaving it out would make the list
look complete when it is not. And the summary reads from the *server*, not
from the form's state, so an abandoned half-typed change is not quietly
displayed as though it were being shared; *Abbrechen* puts the form back to
what the server says as well.

---

### 2.65 Nobody is an audience, not a delete

`nobody` used to delete the row, and the reasoning was sound as far as it
went: an audience of nobody and no entry at all disclose exactly the same
amount, so keeping one looked like keeping a copy behind a narrower flag —
§2.26's "a way of not listening".

What that missed is that the portal is not the only thing the family does with
an address. The magazine is posted to it, and there is a subscription list
coming. A member who chose *Niemand* meaning "do not show this to my
relatives" was made to say "the family does not have my address" instead —
which is a different sentence, was not what they meant, and could not be taken
back.

So the two are separated. **`nobody` keeps the entry and discloses it to no
one** (`visibleTo()` was already right: it never matched `nobody`, so nothing
about disclosure changed). **An empty value deletes it**, and is now the only
thing that does.

#### The trade only holds if the screen says so

This is the part that would make it a bad change if it were skipped. A stored
entry that a member believes they deleted is worse than either behaviour it
replaces, so the form says both halves out loud before the first field —
what *Niemand* keeps, and that emptying the field is the delete — and the
summary marks such an entry "Gespeichert, aber niemandem gezeigt" rather than
listing it as though it were shared. `openapi.yaml` says the same to any other
client: this is a rule about consent, and a client that hides it is lying on
the server's behalf.

The GDPR reading is the same one: this is data the member entered about
themselves and can delete at any time, in one step, from the screen it was
entered on. What changed is that "show this to nobody" and "erase this" are
now two answers instead of one, which is what a member who wants the magazine
but not the directory actually needs.

### 2.66 Phase 14: three lists in somebody else's cloud

The request was *"wir haben drei Verteilerlisten in Exchange — können wir im
Portal eine Möglichkeit schaffen, sich davon an- und abzumelden?"*, and the
interesting part of the answer was not the switch.

**Microsoft Graph cannot do this, and that decided the shape of it.** Graph is
the documented, supported way to manage groups, and classic distribution lists
are the one kind of group it refuses: they belong to Exchange rather than to
the directory, so `/groups` lists them and will not change their membership.
Three ways round that, and two of them lose something:

* **Convert the lists to Microsoft 365 groups**, which Graph does manage. A 365
  group can only hold directory objects, and most of this family is on
  gmail.com and web.de. Ruled out by the answer to "which addresses are on
  them", which was *überwiegend externe*.
* **A sync job outside the portal** — Azure Automation running the supported
  PowerShell module against a list this portal publishes. Robust, and it keeps
  a tenant credential off the webhost entirely. It was recommended and not
  chosen; it is two systems to operate instead of one, and a change would take
  minutes to land rather than seconds.
* **The REST endpoint the PowerShell module itself calls**, which is what was
  built. `adminapi/beta/{tenant}/InvokeCommand`, an app-only token, a cmdlet
  name in a header. It is **beta and undocumented**, and that is written at the
  top of `Services/ExchangeOnline.php` rather than buried: if a future Exchange
  release moves it, every change goes outstanding and the diagnosis screen says
  so. What is not lost is the members' answers, because of the next paragraph.

**The portal holds the wish; Exchange holds the list.** The decision is written
down before anything is attempted, and the two are allowed to disagree. This is
the whole design and it is what makes the risk above survivable: if the
connector stops working entirely, the portal is still a correct record of who
asked for what, and any replacement can read it. It also means a member
pressing a switch gets an answer that is true — *your answer is taken down* —
rather than an optimistic one that claims a delivery nobody has confirmed.

**A "no" is a row, not a deleted row.** Two reasons, and the second is the
real one. The practical one: an unsubscribe is itself an instruction that has
to reach Exchange, and an instruction with nowhere to live cannot be retried.
The other: "has never been asked" and "was asked and declined" are different
states, and a portal that forgets the second cannot prove a withdrawal it acted
on. Withdrawal is recorded as carefully as consent, which is the point of
recording either.

**A list is identified by the hash of its address.** Not for secrecy — a hash
of a known address is guessable by anybody who knows the address — but because
it lets the portal offer a member *the family news* without putting the
family's distribution addresses into every browser that opens the settings
screen. `MailingListTest` asserts it against the whole response body rather
than against a field, because the way that promise breaks is an address turning
up somewhere nobody was looking.

**Retrying without a cron.** webtrees has no scheduler, and §2.22 already found
that the honest place to hang periodic work is a screen somebody is looking at
anyway. Here it is the member's own settings screen: reading `/me/mailing-lists`
retries what is outstanding, which is convenient because the person opening it
is exactly the one who wants it to have gone through. Two bounds keep that from
turning an Exchange outage into a portal that will not open — at most one row
per request, and not again for ten minutes — and after three attempts a row
waits for an administrator instead. The button that wakes those rows is on the
diagnosis screen, beside the one that tests the connection.

**What a member is never shown** is Exchange's own complaint. It names a
tenant, an application registration and a cmdlet; there is nothing in it a
family member can act on, and quite a lot in it about infrastructure they have
no business seeing. They get *"wir kümmern uns darum"*, which is both kinder and
more accurate, and the administrator gets the sentence.

**Two things in the connector are guesses about somebody else's product**, and
they are called out in §3 rather than left to be discovered: that
`New-MailContact` fails on a duplicate `Name` rather than on something else,
and that a failed add can be checked by reading the membership back. The second
exists so that this module never has to recognise the sentence *"is already a
member of the group"* — matching Exchange's wording in Exchange's language,
subject to Exchange's changes of mind, is the kind of dependency that breaks
quietly in a year.

**What the first live tenant taught, an hour after this was merged.** Both
guesses survived. The recovery built on the second did not, and it failed in
the worst available way: silently, and in the direction of looking fine.

The application had been given *Exchange Recipient Administrator*, on the
strength of a sentence in this repository's own README saying that was enough.
It could read everything and write nothing. Every `Add-DistributionGroupMember`
came back `403` with an empty body — and the read-back that followed said "yes,
this address is on the list", because the administrator testing it was already
a member of the list he was subscribing to. Three subscriptions reported as
applied; none had been. The first *unsubscribe* was what broke the spell, and
only because it is the one case where the wish and the world are obliged to
disagree.

Two things were wrong and each is worth stating on its own.

The narrow one: **a refusal to act is not evidence about the world.** Reading
the state back is a sound way to recover from "you asked for something that was
already true" and a worthless one for "you may not ask" — in the second case
the state says what it says for reasons that have nothing to do with the call.
`ExchangeFailure` now carries `denied`, and a denied write is rethrown without
the read-back. The regression test is the exact live configuration:
`ExchangeConnectorTest`.

The wide one: **the README was wrong about the role, and the code was what made
it wrong.** Every write carries `-BypassSecurityGroupManagerCheck`, because a
service principal can never be in a list's `ManagedBy` — it is not a recipient.
That switch requires Organization Management or *Security Group Creation and
Membership*, and Recipient Administrator is neither. So the least-privilege
recommendation was never achievable *for this design*; it was achievable for a
design that did not need the switch, and no such design exists here. Exchange
Administrator is what it takes, unless a tenant can define its own Exchange role
groups — which this one cannot.

Worth keeping as a rule: **a fallback that turns failures into successes needs
to know which failures it is entitled to forgive.** This one forgave all of
them, and the cost was a feature that reported itself working for as long as
nobody tried to undo anything.

**The switch was answering the wrong question**, which the same afternoon made
plain. Every member with no row was shown "not subscribed", and the family's
lists are older than this portal — so that was wrong about nearly everybody,
and it invited people to subscribe to post they already got. The fix is to read
the membership and show that: a member asking "do I get this?" is asking about
Exchange, and this portal is only the record of having asked for something.

Three things that decides.

**A pending decision still wins.** For the ten seconds between a member moving
a switch and Exchange agreeing, the screen shows what they did — anything else
is a switch springing back under their hand. Only a settled row defers to the
world.

**The answer is cached, and the cache is per list rather than per member.**
There is no cmdlet for "which lists is this address on", so it has to be asked
list by list; one answer then serves everybody who looks in the next ten
minutes. One list is refreshed per request, for the same reason `outstanding()`
applies one row per request — three lists times a ten-second timeout is not a
delay to put in front of a screen on the day Exchange is what is broken. A cold
cache warms over three visits.

**Only hashes are kept.** `portal_list_snapshot` holds SHA-256 of each member
address, which answers "is this address on that list" exactly as well as the
address would and does not leave a second copy of the family's mailing list in
a second database. Same reasoning as the list addresses themselves (§2.66,
above) and the same non-claim: a hash of a known address is guessable by
anybody who knows it. This is not secrecy, it is not keeping what there is no
reason to keep.

The one thing that had to be added rather than discovered: after a change is
applied, the module writes the result into the snapshot itself instead of
waiting for the next read. It knows what it just did, and without that a member
who unsubscribed would be told for ten minutes that they had not.

---

### 2.68 An invitation is for somebody who cannot see the tree yet

**The bug: every invitation link was dead on arrival**, on any tree with
`REQUIRE_AUTHENTICATION` switched on — which is every tree this portal is
built for. The invitee opened the link and read *„Diese Einladung gilt nicht
mehr“*. Withdrawing it and issuing another produced another dead link, because
nothing was wrong with either of them.

Both endpoints on that path began with `PortalTreeService::tree()`, and that
method resolves the portal's tree through webtrees' `TreeService::all()` —
which is filtered by whoever is asking. webtrees' rule for a non-administrator
is that a tree requiring authentication is visible only to somebody who holds a
role on it. A person holding an invitation holds nothing: that is the entire
premise. So the list came back empty, the configured tree looked deleted, and
`POST /invitation/preview` and `POST /invitation/accept` both answered 503
*before either of them looked at the token*.

This class had already been bitten twice by the same filtering — `GET /health`
answered `not_configured` for a healthy installation, and the link out to
webtrees dead-ended for signed-out readers — and both were fixed in place, each
with a paragraph explaining it. The third time it is a method:
`PortalTreeService::configuredTree()` reads the module's configured tree from
the `gedcom` table and hands back the `Tree`, asking nobody's permission. Only
the two invitation endpoints use it. Nothing is granted by it — a `Tree` is an
id, a name and a title, the two callers read no records through it, and what
actually opens an invitation is the token, checked on the very next line.

**Two things came out of the same hole.**

*The test harness was hiding it.* `TreeService::all()` caches its answer for
the request, and `PortalTestCase` kept one cache across every request a test
made — so a visitor's request was answered out of the list built by the
administrator who imports the fixture. `api()` now clears that cache before
each dispatch, which is what a request boundary does in production. The
fixture also leaves `REQUIRE_AUTHENTICATION` off, so the three new tests in
`InvitationTest` turn it on: that is how the portal is actually run.

*The screen was blaming the invitation.* `Invitation.tsx` treated **any**
failed preview as a spent link. A 503 is not an answer about the token, and
saying it is sends the invitee to ask for a replacement that fails identically
— which is exactly the loop this bug produced. Only `invalid_token` now reads
as "no longer valid"; everything else gets the ordinary error notice, with its
retry and its reference number, and the accept form names `not_configured` and
`server_error` rather than shrugging.

---

### 2.69 The list an editor never saw

Two complaints about one screen, and the same sentence answers both: **an
editor's invite screen is a search box, and it was being handed a list.**

*It took a long time to open.* `overview()` built the editor's candidate list
by reading every record in the archive and asking three questions of each — is
this person visible to me, are they living, do they already have an account.
The third went back to the database once per record (`outstanding()`), and the
one behind it walked every user account in the installation (`hasAccount()`).
Then the first two hundred survivors were presented in full — name,
relationship, portrait — and the screen **threw all of it away**, because for
`scope: anyone` it draws a search box and never looks at `candidates`. §2.60
already knew the list was not the rule; what it kept was the list.

So the editor gets no list: `candidates` is empty, and the search is the list.
Nothing is lost by it. The rule lives in `invitable()` and is asked when the
invitation is issued, which is where every refusal has always come from — and
the two tests that used to read the list now ask the endpoint instead, which is
the thing they were really about. `LISTED` is gone; the number an editor is
told is left of their quota is now called `NO_QUOTA` and says why it is 200
rather than a sentinel: a client one deploy behind would print "noch -1
Einladungen". The screen no longer prints it at all where there is no quota —
"Sie können noch 200 Einladungen offen haben" was a number invented to fill a
line.

*And the person was not shown.* Arriving from somebody's own page is the
ordinary way onto this screen and it carries them in `?xref=`. The search
component only recognised a choice that appeared in its own results, and it had
searched for nothing — so the person the editor had just pressed the button for
had to be found again by typing their name. It now fetches that one record when
the choice came from the address bar rather than from a result, and says
*Ausgewählt: …* before anything is typed. One record, and only when somebody is
already chosen.

---

### 2.70 A constructor is not an interface

The fix in §2.68 shipped, and the host answered every invitation with a fatal:

```
Too few arguments to function Fisharebest\Webtrees\Tree::__construct(),
3 passed ... and exactly 9 expected
```

`configuredTree()` had to build a `Tree` webtrees would not hand over, and it
did the obvious thing — `new Tree($id, $name, $title)`, which is what the
three-argument constructor in 2.2.1 takes. **The host runs 2.2.6.** There,
`Tree` takes nine constructor arguments, and its title, media folder,
GEDCOM filename, contact users and members-only flag have moved out of
`gedcom_setting` into columns of `gedcom`. The change is clean and deliberate
on webtrees' part; what was wrong was reaching for the constructor at all.

**Each version's own factory is asked for by name instead.** 2.2.6 and later
have `Tree::fromDB($row)`, which takes the whole `gedcom` row; 2.2.5 and
earlier have `Tree::rowMapper()`, which takes three fields and finds the title
in `gedcom_setting`. `configuredTree()` picks by `method_exists()` and shapes
the row the way that version's own `TreeService::all()` shapes it — because
those two factories exist to serve exactly that query. There is no window
between them: 2.2.6 is the release that swapped one for the other.

It also asks webtrees first now. Where the caller *can* see the tree — a
public tree, an editor, an administrator — `TreeService::all()` answers and
nothing is constructed at all. Only a visitor to a members-only tree, which is
the case §2.68 exists for, reaches the factory.

**The suite ran on the wrong webtrees, which is why nothing caught it.** It is
now pinned to 2.2.6 — `setup-test-env.sh` and both workflow cache keys — and
three things in the harness had to move with it: `TimeoutService` grew a
`PhpService` argument, so services come from the container rather than from
`new`; the "has the import finished" flag became a column whose value a `Tree`
object caches at construction, so it is read from the database instead of from
the object; and `setPreference('REQUIRE_AUTHENTICATION', …)` is now a
compatibility shim that raises a notice *and* writes to every tree in the
table, so `PortalTestCase::requireAuthentication()` writes the column where
there is one. A test that turned that notice into a failure is how the last of
those was found.

The lesson worth keeping: **webtrees' constructors are not its API.** Factories,
services and the container are. Anything this module `new`s from webtrees'
namespace is a version pin that will not announce itself until a host upgrades.

---

---

### 2.71 The number says which branch, and nobody was reading it

A member reads *SB 10/1335.21* on their own record and the portal has told them
nothing they did not already know. The part in front of the oblique is a line,
and the family does not talk in lines: lines 8 to 14 are together the **Zweig
Cleve**, 21 to 31 the **Zweig Rothenhof**, 32 to 35 the Wilhelminische Linie.
Asked where they come from, nobody answers "line 12". The branch was in the
number the whole time, and the portal was printing the number and dropping the
one part of it a member would say out loud.

webtrees already shows it, as a badge from the family's own module. So this is
the portal catching up with the back office rather than a new idea, and the
grouping is ported from there unchanged.

**The branch is derived from the number, not stored on the record.** It is a
reading of `REFN`, in `SackNumbers::branch()`, called from
`RecordPresenter::references()` — which means it stays right when the family
edits the table, and it discloses nothing: the number it reads has already been
through `Fact::canShow()`, and a number the reader may not see is not in the
response to be read. It travels on the `Reference` shape as a third field, so a
record with two numbers can name two branches, which is a thing that happens.

Two services build that shape — `RecordPresenter::references()` and
`Recognition::references()`, the one that lets a number through for a record
the reader may not open — and both carry the branch. A card reading the list
does not know which of the two filled it, and one of them being a field short
is how one shape quietly becomes two.

Four decisions inside it, each of which could have gone the other way.

**Read what is written, not the resolved path.** `path()` turns a number into
an ancestral path and is the basis of the whole calculator — but it only reads
what the calculator can use. `HS/…`, the descendants of Heinrich Sack, is a
numbering it does not read at all, and a member carrying one would have got no
branch from a path-based reading. Taking the head as written gives `GS` and
`HS` a name for free, and the branch is a property of the line anyway: it is
settled before a single character of the descent has been looked at.

**A number without an oblique gets no branch.** This is the one place the
portal is deliberately less helpful than it could be. "24" is how the archive
writes the head of line 24 — and it is also what the older, unrelated numbering
looks like once it reaches two digits (§2.57). The fixture's Dieter carries a
bare "9" and a "10/1335.21", and reading the first as line 9 would print *Zweig
Cleve* on a record that has nothing to do with it. Naming the wrong branch on
somebody's own record is a worse failure than naming none, so the oblique is
required. The 36 line heads written bare are the price, and they are written
"24/" as often as "24".

**The names are family data, and the family writes them in both languages.**
The two tables in `SackNumbers` are already the family's to edit rather than
the software's — a new line in the archive is an evening's news, not a release
— and the branch table is a third of the same kind, `sack_branches`, with its
own box in the preferences.

*This shipped once with the names untranslated*, on the reasoning that
Mansfeld and Georg Sack are places and people rather than words. Which is true
of the middle of the name and false of the rest of it: "Ernestinische Linie –
Zweig Rothenhof" against "Ernestine Line – Rothenhof Branch" is two words of
grammar around one unchanged place, and an English reader was getting a German
sentence on their own record while every fact label and date beside it followed
their language (§2.17). So a row now carries the name in as many languages as
the family cares to write, `Name | en: Name`, and the reader gets theirs.

Three things fall out of it, and each is the safe direction:

* **The untagged name answers everybody who has no name of their own.** A
  branch added on a Tuesday has one name until somebody writes the other, and
  a reader of the second language must get *that* name — a branch missing
  entirely reads as "we do not know where you are from", which is worse than
  reading it in German.
* **A country's English gets the English name.** webtrees has four Englishes
  and one German; `en-GB` takes an `en:` name, because the difference between
  two Englishes is not what this table is for.
* **A second name with no language tag is dropped, not shown.** It would
  otherwise appear as somebody's branch in a language they are not reading,
  which is how a table like this goes quietly wrong.

What the portal owns is still only the word in front: "Zweig der Familie", for
a screen reader. The name itself is quoted, in whichever language it was
quoted in.

**On the record and nowhere else.** The number goes everywhere — every card in
the portal has carried it since §2.51 — but the branch does not. `PersonCard`
already carries a name, a lifespan, a number and a kinship in one line; the
branch is a thing to read about a person you have opened, not a fifth thing to
skim past on the way somewhere. The branch wheel in the connect form (§2.57)
still offers bare numbers for the same reason it always did — that is a control
for somebody holding a number, not a place to learn the family's geography —
and naming the branches there would need the table in the browser, which is a
second decision and not this one.

---

### 2.72 A card said "no record" and meant "not yours to see"

A member opened a connection request and read *Kein verknüpfter Eintrag im
Stammbaum* under a name. The archive had a record for that person. The line
was simply false.

The mechanism is one null doing two jobs. `RecordPresenter::individualRef()`
returns null when the caller may not see the record, and
`Connections::present()` also has nothing to hand over when nobody linked that
account to a record at all. One field, two meanings — and the card picked the
rarer one and stated it as a fact about the archive, out of an answer that was
about the *reader*.

**Which of the two it is cannot be disclosed**, and that is not an oversight:
saying "there is a record here that is not yours to see" is exactly the
sentence webtrees' privacy exists to withhold. So the server keeps both cases
as one null, and the client says the only thing that is true of both —
"Keine Angaben aus dem Stammbaum sichtbar". The member's own page has said it
that way since Phase 2 (`member.private`); the two list rows had invented
their own wording and got it wrong. They now share one string,
`individual.notVisible`, so they cannot drift apart again.

Worth knowing how ordinary the hidden case is: with a path-length limit set,
every living person outside a member's own few steps is hidden from them
(§2.34), so on such an installation *most* incoming requests arrive as a name
with no record. The line was wrong far more often than it was right.

#### The harness was answering privacy questions from the wrong member

Pinning this found a fault in the tests rather than in the module. webtrees
caches `canShow()` in `Registry::cache()->array()` under record, tree and
access level — **not** under user, which is right in production, where that
cache lives and dies inside one request. `PortalTestCase::login()` does not
end a request, so a `true` computed for the member looking at *their own*
record (`GedcomRecord::canShowRecord()` has an explicit exception for it) was
handed to the next member who asked the same question at the same access
level.

The first version of this test said a confidential record travelled with a
connection request. It does not; the harness had cached the subject's own view
of herself. `login()` now installs a fresh `CacheFactory`, which is what a new
request gets, and the test says what it means to say. Any test in this suite
that signs two people in and asks about privacy was until now capable of
proving the opposite of the truth.

---

### 2.73 A name and white space is not an address book

§2.66 fixed what the card *said*. What it showed was still a name and nothing
else, and for a member whose record is closed to the reader that is the
ordinary case rather than the exception: a connection request arrives, and the
person it is from cannot be recognised at all.

The record is closed for a good reason and stays closed. But two things on
that card do not come from the archive's account of the person, and each has
its own permission behind it.

**A photograph they uploaded here themselves.** §2.50 already says a living
person's picture is shown only where they put it — `portal_photo` is that
consent, given to this portal, for exactly this. Withholding it because
webtrees hides the record it hangs on would be honouring a rule about the
*family's* data against the person the data is about. So it crosses, and
nothing else in the gallery does: the family's photographs of them stay behind
the record's privacy where they belong.

**The archive number, if the family says so.** Off by default, and a switch in
the control panel rather than a per-member choice, because the number is the
family's naming scheme rather than anybody's personal data — it comes off a
letterhead. And §2.57 already lets any member *type* a number and reach the
person it belongs to whether or not they may read the record: showing it makes
legible what was already searchable.

A number the record marks confidential is still withheld. `Fact::canShow()`
asks about the `RESN` on the fact rather than the privacy of the record around
it, which is exactly the half that belongs here — the same split the number
search relies on.

**And nothing else.** Not the name on the record, not the years, not the
nickname, not the relationship. `Recognition` is the whole rule and it is two
fields wide.

#### Two traps, and they are the same trap

`GedcomRecord::facts()` hands back **nothing at all** for a record the reader
may not see. So both halves of this walk into it:

* `Media::firstImageFile()` reads `facts(['FILE'])`, and a media object counts
  as private whenever any record it is linked to is
  (`Media::canShowByType()`). The ordinary call therefore answers "this
  photograph has no files in it" for precisely the pictures this exists to
  show.
* The archive number is a `REFN` fact, with the same answer — which is why
  `Connections::memberByReference()` already reads at `PRIV_HIDE` and filters
  fact by fact, and why this does too.

The permission is decided first and the reading is done afterwards, at
`PRIV_HIDE`, in both places.

The third instance of the same trap is the one that would have shipped a
broken image: `MediaRead` gates on `Media::canShow()`, so the URL in the
payload would have answered 404 for every portrait this feature adds. A
consent that is honoured in the JSON and refused at the image is not a feature,
it is a grey box — so the handler knows the rule too, and knows only this one:
a `portal_photo` row, and nothing else, opens that door.

#### Where it appears, and where it does not

Three payloads carry the two fields — the directory row, the member's own
page, and a connection card — **and only where `individual` is null**. Where
the record is readable both live inside it and follow its rules; a second copy
beside it would be two answers to one question, and they would not always
agree (a dead person's family photograph is a portrait, and never a
`portal_photo` row).

Deliberately *not* on the tree screens. `individualRef()` returning null is
what keeps a hidden relative out of a relative list altogether, and that
must not change: this is an address book of people who already have accounts,
not a way around the archive's own privacy.

---

### 2.74 Leaving is not the same as changing your mind

§2.52 made signing out unsubscribe the device, and that was right: a tablet
that goes on buzzing for the account somebody just left announces to the next
person in the kitchen that something arrived for the last one. What it also
did — and nobody noticed until a member said so — was throw away the
*decision*. Switch notifications on, sign out, come back: the switch is off
again, in a place you have to remember, on every visit.

So the two are separated. Signing out still unsubscribes the device. The wish
is remembered, and the next sign-in acts on it.

**The wish is an account id, not a flag.** That is the whole of the design.
A boolean saying "notifications were on in this browser" would switch them on
for whoever signs in next, which on a shared device is precisely what §2.52
refused to do. `portal.notifications` holds the id of the member who switched
them on here, and the restore happens only when that member is the one who
came back.

That id is the third thing this portal keeps in browser storage, after the
language and the install prompt's "asked already". It is not a credential and
unlocks nothing — it says that somebody with that portal id reads this family's
portal in this browser — and switching notifications off clears it, because
*that* is changing your mind.

#### Silent, and permitted to be

The restore asks nobody anything, and it is allowed not to because permission
belongs to the *browser* and outlives the session: a member who granted it
before signing out has already answered. `resume()` is `enable()` without the
question, and it refuses anything short of `granted` rather than prompting —
a permission prompt outside a user gesture is a prompt nobody tapped anything
to get, and browsers are right to treat it as spam.

Which turned up the one trap in the split. `enable()` asks and then subscribes;
when it delegated the subscribing to `resume()`, `resume()` re-read
`Notification.permission` — the very question just answered — and a browser
that has not yet updated that property answered "not granted" to a member who
had just said yes. Both paths now share the part after the decision and neither
re-decides.

Four things have to hold, and each is somebody's decision rather than a
technicality: this member switched it on here, the browser still allows it, the
family still offers the facility, and the portal still has keys. A failure of
any of them is silent — nobody asked for this *now*; it is the standing wish of
somebody who asked once — and the switch in *Einstellungen* goes on saying what
is actually true.

The sentence under the switch says both halves now. The first was already
there; the second is the surprising one, and therefore the one that most needs
saying.

#### And the screen has to be told

Shipped, and a member said the switch still read "off" until they reloaded.
Fair: the restore is a round trip to the browser's push service, the settings
screen is on its feet long before it comes back, and that screen asks whether
this device is subscribed exactly once.

Refetching `/push` is worth doing — the row it counts was created a moment ago
— and it is **not enough**. The question the switch asks is answered by the
*browser*, not the server, so no amount of refetching makes the browser answer
it again; and where the member is already subscribed on another device the
server's answer does not change at all, TanStack keeps the previous object by
structural sharing, and an effect keyed on it never runs.

So the restore says when it has actually subscribed, and the screen listens
(`onSubscriptionChange`). Both halves: the refetch keeps the server's count
honest, the listener keeps the switch honest.

The first test written for this passed against the broken code, which is worth
recording. The stub subscribed instantly, so the restore had finished before
the screen ever asked — the bug needs the *order* it has in life, and the test
only pins anything once the subscription is held until the screen has already
said "off".

---

### 2.75 The pedigree stopped at the living, and the family are the living

A member opened *Vorfahren* on their own record and read four rows: themselves,
their parents, and one grandfather. The archive has that line back to 1780. It
was not a bug, it was §2.20 working exactly as written — a person the member
may not see was absent, and so was everyone above them — and it had quietly
stopped being the right rule the day Phase 8 (§2.25) put a relationship path
length on member accounts. From then on almost every line ran into a living
person within two or three rungs and ended there. A genealogy portal whose
genealogy ends at the reader's grandparents is not a genealogy portal.

**The new rule: the shape is shown, the people in it are not.** Every rung the
walk reaches is in the answer. Where the member may read the record, the entry
is the full `IndividualRef` it always was. Where they may not, it is a
placeholder — a position, `private: true`, and nothing else — and the walk
carries on above it. The dead the archive exists to keep are reachable again,
through the living, without any of the living being named.

The old argument against a placeholder was that "a pedigree with a labelled
hole in it tells the reader what fills the hole". That was true of a *labelled*
hole, and it is the reason this one carries no label. What a placeholder says
is that somebody occupies the position — which is what a pedigree is, and what
the reader can work out anyway from the fact that they have a great-grandmother
at all. What it does not say is anything about that person: no name, no dates,
no picture, no archive number, and **no XREF**, so it is not a link and cannot
be turned into a question to any other endpoint.

**And it never says why.** A living person outside the member's reach and a
record carrying `1 RESN confidential` produce the byte-identical entry. That
was the one thing worth being careful about: "hidden" must not become readable
as "alive", or the placeholder would answer a question about a person that
nobody asked it. It is the same discipline `individualRef()` has kept since
§2.5 — one null, two meanings, and the reader cannot tell which.

**The root is the exception, and stays one.** A record the member may not read
is still a 404 from `/individuals/{xref}/ancestors`, byte for byte the one an
XREF that names nobody gets. Placeholders carry no XREF, so nothing in the
portal can link to such a pedigree; what the refusal is for is somebody trying
XREFs by hand to find out which of them name a real person. The endpoint
answers about somebody the reader already reached and says nothing about
anybody else.

#### The one thing a placeholder may carry is that person's own decision

Where the record belongs to a member who put themselves in the member
directory, the rung carries the name they publish there and the member id
behind it, and the portal links it to their member page.

**That is consent being honoured, not privacy being widened.** Listing oneself
in the directory is a decision that every other member may read that name and
open that page; `Members` has published exactly that since Phase 2. Saying it
again on a rung of a pedigree adds one fact — that this listed member stands at
this position — which is the fact the family asked this screen for.

**No genealogy data crosses.** The record stays shut: `individualRef()` refused
it and nothing goes back to ask it a second question. The name comes from
`portal_member_profile`, which is what `Member` was built to keep apart from
the tree ("appearing in the directory ... must not turn into a way to read
genealogy data that privacy rules would otherwise hide"), and no birth year, no
place and no photograph travels beside it. It is the same switch `SearchConsent`
already reads, so a member who turns the directory off in Settings disappears
from the pedigree and from the search together — one switch, one meaning.

**An explicit restriction on the record outranks it.** `1 RESN confidential`,
`1 RESN privacy`, or a per-record privacy level set in webtrees' control panel
is somebody who keeps the archive saying that *this record* is not to be shown,
and that is a different question from what a person publishes about themselves
in the portal. Such a rung stays a bare placeholder even for a listed member —
who is still in the directory, where their name has been all along.
`RESN locked` is deliberately not among them: it forbids editing, not reading,
and treating it as a privacy notice would hide people the archive never meant
to hide. The test mirrors `GedcomRecord::canShowRecord()` line for line so that
it changes when webtrees does rather than growing a second opinion.

#### Two things about webtrees that the walk had to stop trusting

**The structure is now read at `Auth::PRIV_HIDE`, and that is the absence of a
privacy decision rather than one.** Which family, and which two people are in
it, is what this screen shows in every case; whether either of them may be
*read* is decided one level up, in `rung()`, where the module's single gate
is. Asking webtrees for the structure at the member's own level gives a worse
answer, not a safer one: `Family::spouses()` filters on `canShowName()`, which
turns on the tree's `SHOW_LIVING_NAMES` preference, and `childFamilies()`
silently escalates to `PRIV_HIDE` anyway whenever `SHOW_PRIVATE_RELATIONSHIPS`
is on — which is webtrees' default. The shape of a pedigree would otherwise
move with two settings that have nothing to do with it.

**A `RESN` on the family record still ends the branch.** A restriction on a FAM
is somebody saying that *this connection* is confidential — not these people,
the fact that they are joined — and a placeholder in the position it names
would say the one thing that was asked to stay quiet. This is
`RelationshipNamer::families()`' reasoning, applied to the one place in
`AncestorTree` that walks a connection.

The cost of not stopping is bounded and small: the walk now visits every
position rather than however many happened to be visible, so six generations
is at most 127 records instead of a handful. The screen asks for four, which
is at most 31, each of them a record webtrees has already cached for the
request — and the whole reason this endpoint exists is that 31 records in one
response beat 31 round trips from a phone.

#### What the tests had to be re-pointed at

`TreeTest` used to assert the opposite of all of this — Ida absent, the walk
stopping below her — and those two tests are now the other way round: Ida is a
placeholder that carries nothing, and Otto (X12), her father, dead since 1899
and restricted by nobody, is reachable at position 14. Four cases were added:
a living person hidden by the path length produces the identical entry to a
restricted one; a listed member is named from the directory and not from the
record; a restricted record is not named even for a listed member; and a member
who stayed out of the directory is not named either.

The fixture has no living ancestors and cannot grow one — everybody above Anna
died before 1980, and their dates are what makes them her ancestors — so the
living case is produced the way a real installation produces it, with
`KEEP_ALIVE_YEARS_DEATH` and a per-user relationship path length. That is the
same pair of settings §2.25 is about, which is the point: the test is about the
configuration the family actually runs.

Those three tests carry `#[RunInSeparateProcess]`, and it is not optional.
`Individual::isRelated()` keeps its breadth-first walk in a function-level
`static` keyed by neither user nor tree, and matches with `in_array(…, true)` —
strict identity, against `Individual` objects from whichever test ran first. A
second test in the same process is therefore answered from a stranger's
neighbourhood, against object identities that no longer exist, and *everybody*
comes out unrelated. It passed alone and failed in the suite, which is exactly
how that trap presents; `VisibilityTest` had already been bitten by it and says
so in the same words.

#### The client

One shape on the wire became two, so `Ancestor` is a discriminated union in
TypeScript rather than an interface with everything optional — a screen that
forgets to handle a placeholder now fails to compile instead of rendering
`undefined` as somebody's name. `private` is optional on the placement, because
the module and the portal deploy separately and a server that predates the
field never sends a placeholder either, so its absence reads correctly as
"a person".

A placeholder is not a link and does not look like one: a dashed border, a
muted ground, no hover state, no tap target. The only placeholder that *is* a
link is a listed member's, and it goes to `/members/{id}` — their portal page,
which is what they consented to — with a second line saying so, so that a name
on a rung is not read as the family tree having opened up.

And the note under the list changed with the rule. It used to say that only
people you are allowed to see are shown and that a line might continue beyond
where it ends; both halves are now false. It says what is true instead: the
dead are shown by name, the living stand in the line unnamed unless they listed
themselves, and nothing from the tree is shown for them either way.

---

### 2.76 The other house names them

A member opened a deceased great-aunt's page **in webtrees** and read the names
of the living people in that family — the same people the pedigree in the
portal had just shown as unnamed placeholders. Nothing was broken. Two
programs were answering the same question differently, and neither of them
said so anywhere.

**Why webtrees answers the way it does.** `Individual::canShowName()` is an
**or**:

```php
return (int) $this->tree->getPreference('SHOW_LIVING_NAMES') >= $access_level || $this->canShow($access_level);
```

`SHOW_LIVING_NAMES` defaults to `Auth::PRIV_USER` (`Tree.php:80`), so for a
signed-in member the first half is true and `canShow()` never gets a vote.
`chart-box.phtml:135` then prints `fullName()` in both branches of its own
`canShow()` test — only the *link* is conditional. And with
`SHOW_PRIVATE_RELATIONSHIPS` on, which is also the default,
`RelativesTabModule` reads the family's `HUSB`/`WIFE`/`CHIL` links at
`Auth::PRIV_HIDE`, so every row is listed to begin with.

It is deliberate, and the reasoning is sound on its own terms — webtrees' help
text says "the names (but no other details)", and a chart of forty boxes
reading *Private* is not a chart. The disclosure really does stop at the name:
the thumbnail, the zoom menu and the links menu are all behind `canShow()`, and
`lifespan()` reaches the dates through `facts()`, which returns nothing at all
for a record the reader may not see.

**Why it is this module's business anyway.** `IndividualView` puts a link into
webtrees at the foot of every person's page, for every member — *Stammbaum und
Diagramme öffnen*. So the placeholder discipline of §2.75 is exactly one tap
deep. The portal being stricter than webtrees is not a property of the portal;
it is a property of two tree settings that no screen in either program relates
to the other.

Which is the same shape of problem as §2.25, and gets the same answer: the
state is real, it is invisible, and nobody would think to go and look. So
`Diagnosis::livingNames()` looks.

**What it reports, and what it refuses to.** Both settings, in the third
column, by the names and values webtrees itself gives them — `I18N::translate(
'Show names of private individuals')` and `Auth::accessLevelNames()` — so that
the row can be found on the screen it is about, in the administrator's own
language. Only `SHOW_LIVING_NAMES` drives the status:

* *Show to managers* — **ok**. The two programs agree.
* *Show to members* — **warning**. Members read in webtrees what the portal
  withholds.
* *Show to visitors* — **problem**, but only where the tree can be read without
  signing in. Otherwise a visitor cannot open the tree at all, "visitors"
  behaves as "members", and it is a warning. A diagnosis screen that shouts
  about something disclosing nothing is a screen that gets ignored on the day
  it is right.

`SHOW_PRIVATE_RELATIONSHIPS` is reported and never complained about, and that
is a consequence of §2.75 rather than a shrug: whether a hidden relative's row
is listed as *Private* or left off the page entirely, the portal's own pedigree
now says the first of those — somebody stands here. Either value agrees with
it. It is on the screen as a fact.

**One version fork, in a place that already had one.** Whether the tree can be
read without signing in was `REQUIRE_AUTHENTICATION` in `gedcom_setting` until
**2.2.6**, which moved it into a column behind `Tree::private()` and left a
shim that raises a deprecation notice. So `requiresAuthentication()` asks each
version by name, exactly as `PortalTreeService::treeFromRow()` does for the
constructor that moved in the same release. The harness had already met this
one from the other side: `PortalTestCase::requireAuthentication()` writes the
flag where the running version keeps it, because the shim both raises a notice
— which this suite counts as risky — and writes to *every* tree in the table
rather than to one.

**What this check cannot do is fix it.** Unlike the path length, there is no
button: these are webtrees' own tree settings, the portal has no business
writing them behind an administrator's back, and the screen that owns them is
two clicks away. The advice names the setting, the value to choose and the path
to it, and stops there.

---

## 3. Things that were guessed

Flagging these so they get a second look rather than being inherited as fact.

1. **The tree is a single configured tree.** See §1.2.
2. **`display_name_override` is capped at 128 characters.** Arbitrary; long
   enough for any real name.
3. **Directory page size is 25, capped at 100.** Arbitrary.
4. **Rate limits: 5 failures per username, 30 per IP address, 15-minute
   window.** Ordinary defaults; all three are settings, and a household or an
   office behind one NAT could plausibly need the IP limit raised.
5. **Sorting and filtering the directory happen in PHP, not SQL.** The
   displayed name comes from either `portal_member_profile` or the webtrees
   `user` table, and only one of those is in the query. Fine for a few hundred
   members; if the directory reaches thousands, this becomes the thing to fix
   first.
6. **`role` in the `/me` payload** exposes the member's webtrees role. The
   portal does not currently use it; it is there so the UI can decide whether
   to offer "open in webtrees" affordances without a second request.
7. **The "open in webtrees" link depends on webtrees' `base_url` being right.**
   `webtrees_url` comes from `GedcomRecord::url()`, which builds an absolute
   URL from the `base_url` in the host's `config.ini.php` — *not* from the
   incoming request, so the Pages proxy does not corrupt it. That is the
   behaviour we want here, but it does mean that if `base_url` on the host is
   wrong or unset, these links point somewhere useless. Worth one click to
   confirm after install.
8. **The search stops at 500 matches and the indexes at 5,000 records.** Both
   arbitrary, both reported to the client as `truncated` so the screen can say
   so rather than lie by omission. 500 is well past the point where a member
   should be typing something more specific; 5,000 is several times the family
   this portal serves. A tree that genuinely outgrows the second number needs
   its index built somewhere other than inside a web request.
9. **Places are indexed by their whole GEDCOM name.** "Celle, Niedersachsen,
   Deutschland" is one entry, not three. Grouping by the town alone would
   collide across countries, and grouping by the country alone would be a list
   of two things. If the archive ever wants a hierarchy, webtrees' `places`
   table already has one.
10. **An address is street, postcode, town, country, written in that order.**
   Four fields and German line order, for a German family's portal. A member
   living abroad gets every field they need but not their own country's
   ordering, which matters a great deal less than being able to type the
   address into the right boxes at all. Fields are capped at 120/20/120/80
   characters and the composed text at the column's 255 — arbitrary, and the
   only case where the last one bites is an address longer than an envelope.
11. **The postcode line is what `partsFrom()` orients by.** `[A-Z]{0,3}-?\d{3,6}`
   followed by a town covers German, Austrian, Swiss and Dutch postcodes and
   a good deal else, but not everything — an address it cannot place lands
   whole in the street rather than being torn up. It only ever applies to
   addresses typed before there were fields, and only until the member saves.
12. **The Exchange admin API is beta and undocumented.**
    `adminapi/beta/{tenant}/InvokeCommand` is the endpoint the supported
    PowerShell module calls, and it is the only way to change a distribution
    list from PHP — Graph will not (§2.66). Everything the connector believes
    about its request shape is inferred from that module's behaviour rather
    than from a specification: the `X-CmdletName` header, the `CmdletInput`
    envelope, the `value` array in the answer, the `error.message` in a
    refusal. If Microsoft moves it, subscriptions stop being applied and the
    diagnosis screen says so; nothing is lost but the syncing.
13. **`New-MailContact` is assumed to fail on a duplicate `Name`.** The
    connector retries once with the address appended rather than reading the
    error, on the theory that the only thing likely to collide is a second
    relative of the same name. A different failure therefore costs one wasted
    call before it is reported — which is cheaper than matching Exchange's
    wording, and does not rot when that wording changes.
14. **A failed add or remove is checked by reading the membership back**, and
    a refusal about *permission* deliberately is not. This is how "already a
    member" and "not a member" are treated as successes without recognising
    either sentence. It assumes `Get-DistributionGroupMember` returns the
    address somewhere in each member object — as `PrimarySmtpAddress`, or as an
    `SMTP:`-prefixed `ExternalEmailAddress` — so every string in the answer is
    searched rather than a chosen few. The exclusion for `401` and `403` is not
    a guess: it is what the first live tenant cost. See §2.66.
15. **Three attempts, ten minutes apart, one row per request.** All arbitrary,
    all in `Services/DistributionLists.php`. They exist to keep an Exchange
    outage from being felt as a slow portal, and the numbers matter less than
    that there are some.

---

## 4. Deviations from the handoff

Four, all deliberate.

1. **PHP version.** The handoff says 8.2+; webtrees 2.2 requires 8.3–8.4.
   See §1.1.
2. **Cloudflare Workers, not Pages.** The handoff specifies Pages and a Pages
   Function; the account deploys to Workers. See §2.11. The same-origin
   property the whole design rests on is identical either way.
3. **`GET /csrf` is an endpoint the handoff does not mention.** §5 lists only
   `POST` and `DELETE /session`, but a cookie-based CSRF token has to be
   fetched somehow before the first login, and putting it in the login response
   would be too late. It is unauthenticated and returns nothing but the token.
4. **`/members/{id}` returns both `individual` (a summary) and
   `individual_detail` (the full record).** Slightly redundant, but it keeps
   `MemberSummary` and `MemberDetail` in the same shape, so the list and the
   detail screen share one component and one privacy rule.

---

## 5. Things noticed that are not in scope

Written down rather than acted on, per §2 of the handoff.

* **Linking out to webtrees is the one place the design contradicts itself.**
  Every record carries an "open the family tree and charts" link, per §4 of the
  handoff — which hands a member straight back into the UI the portal exists to
  shield them from, on a phone, where webtrees is at its least usable. It works
  and it stays, but it deserves a decision rather than a default: is that link
  what you want, or should a later phase bring a simple read-only pedigree into
  the portal instead?
* ~~Nothing tells an administrator that an account is not linked to a
  record.~~ **Done in Phase 5.** The invitations screen lists them, and
  invitations link the account at the moment it is created, so the list should
  now only fill up from re-imports and from accounts made by hand.
* **`portal_login_attempt` grows between prunes.** Pruning is opportunistic
  (1 in 20 failed logins). Under a sustained attack, the table could reach
  perhaps tens of thousands of rows before pruning catches up. Indexed and
  small, so not a problem — noted so it is not a surprise.
* ~~There is no plain CI workflow.~~ **Done.** `ci.yml` runs the suites on
  pull requests and on pushes to any branch except `main`, which `deploy.yml`
  already covers. Split by what changed — a portal change does not wait for
  the module's PHP suite, and a module change does not wait for a browser to
  install. `deploy.yml` deliberately still runs its own tests rather than
  depending on this one: what gates a deployment should be the code being
  deployed, checked at that moment, not a green tick from an earlier commit.
* **Serving the SPA over SFTP assumes the domain root.** The API client asks
  for `/api/v1/…`, so a subdirectory install needs Vite's `base` and the
  client's `BASE` changed together. The service worker and the manifest now
  assume the same thing in three more places — `/sw.js`, the shell it caches,
  and the manifest's `start_url` and `scope` — and a service worker cannot
  claim a scope above the path it is served from, so a subdirectory install
  gets a portal that is not installable rather than one that is subtly wrong.
  Fine for Cloudflare Pages, which is the intended path; noted because the
  SFTP option makes the other arrangement possible.
* **No structured audit log for portal reads.** webtrees logs authentication;
  nothing logs "member A viewed member B's record". Phase 11 brought
  connections, and with them the first reads that are only possible because
  two members agreed to something — which is the point at which "who looked at
  what" starts to be a question somebody might ask.
* **The existing note-translation module in this installation was not read.**
  It is on the target host, not in this repository, and no copy was available
  here. §3 of the handoff asks that its conventions for bootstrapping, settings
  and assets be matched — this module follows the patterns in webtrees'
  official `example-module-*` repositories instead. **Worth a look before
  install**: if that module does something differently for a good local reason,
  this one should follow suit.

---

## 6. Test-environment notes

* The module's PHPUnit tests boot a real webtrees against in-memory SQLite and
  dispatch through the module's **actual routes and middleware**, not the
  handlers directly. A handler that returns the right JSON behind middleware
  that lets anyone in is not correct, and this is how that gets caught.
* `module/tests/bootstrap.php` calls `I18N::init()` once before anything else.
  webtrees' own `TestCase` registers GEDCOM tags — which translate their
  labels — *before* it initialises I18N; that works in webtrees' own suite only
  because an earlier test has already set the static translator. Ours is the
  first suite in the process, so it has to do it itself.
* `tools/setup-test-env.sh` runs `php index.php compile-po-files`. The
  distribution ZIP ships compiled translations (`resources/lang/*/messages.php`)
  but a git checkout has only the `.po` sources, and webtrees falls back to
  untranslated English **silently** without them — `Translation` treats a
  missing `.php` file as an empty translation set rather than an error. The
  language tests assert on German output, so they need the compile step.
* `LanguageTest` resets both `I18N` *and* the element factory in `setUp()`, and
  runs its two date assertions in separate processes. webtrees caches
  translated month and day names in function-level `static` variables — free in
  production, where a request is a process, but in one PHPUnit process the
  first language to render a date keeps it.
* PHPUnit reports one deprecation on PHP 8.4, from `oscarotero/middleland`
  inside webtrees' own vendor directory. It is webtrees' dependency, used by
  webtrees in production, and nothing in this repository can or should fix it.
* The Playwright smoke path stubs the API in the browser by default, so it
  runs anywhere. `E2E_BASE_URL` points the same specs at a real deployment.
* `PLAYWRIGHT_CHROMIUM_PATH` skips the browser download where one is already
  installed — in a sandbox that ships Chromium but not Playwright's own
  headless shell, `PLAYWRIGHT_CHROMIUM_PATH=/opt/pw-browsers/chromium-*/chrome-linux/chrome npm run test:e2e`
  runs the whole path without fetching anything. Without it the specs all fail
  in three milliseconds with "Executable doesn't exist", which reads like a
  broken suite rather than a missing binary.
* **`route()` inside webtrees' test harness silently loses the first segment
  of every URL**, and did so until this module generated one and looked at it.
  webtrees' own `TestCase` builds its router with a base path of `'/'` where
  production uses `parse_url($base_url, PHP_URL_PATH)` — `null` for an
  installation at a domain root, which the harness's `https://webtrees.test`
  is. Aura prefixes that base path, so `/login/portal` comes out as
  `//login/portal`; `parse_url()` reads that as host `login` with path
  `/portal`, and the ugly URL is built from the path. `PortalTestCase` corrects
  the base path (and discards Aura's cached generator with it) so that a test
  asserting on a generated URL is asserting on the one production would make.
* The Playwright API stub answers `POST /session` with the *same* payload as
  `GET /me`, because the real handler does and the portal keeps that answer
  rather than asking again. A stub that dropped a field the sign-in response
  really carries makes the feature look broken in the e2e run and nowhere
  else — which is how the unread badge appeared to fail while every unit test
  passed.
* The web server Playwright starts binds to `127.0.0.1` explicitly. Left to
  itself `vite preview` binds to `localhost`, which on a CI runner can resolve
  to `::1` alone while Playwright polls `127.0.0.1`: the server starts, nothing
  answers on the address being watched, and the run dies on the webServer
  timeout with no indication of why. The build was also moved out of the
  webServer command and into `npm run test:e2e`, so a broken build is reported
  as a broken build rather than as a server that never came up, and
  `stdout`/`stderr` are piped so vite's own output reaches the log.
