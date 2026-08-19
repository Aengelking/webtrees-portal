# NOTES

Decisions taken, things guessed, and the questions that are still yours to
answer. Written for the next person to open this repository, including you in
six months.

---

## 1. Open questions — raised, not resolved

These are §12 of the handoff, plus what turned up while building.

### 1.1 The exact webtrees and PHP version on the target host

**Still open, and it matters.**

Built and tested against **webtrees 2.2.1**. Everything the module uses is
webtrees' documented custom-module surface (`AbstractModule`,
`ModuleCustomInterface`, `Registry::routeFactory()`, `GedcomRecord::canShow()`,
`MigrationService::updateSchema()`), which is stable across 2.2.x — but
internals do shift between minors, so **check the host's version before
installing** and re-run `module/tools/setup-test-env.sh <version>` against it.
The tests will tell you quickly if something moved.

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
  client's `BASE` changed together. Fine for Cloudflare Pages, which is the
  intended path; noted because the SFTP option makes the other arrangement
  possible.
* **No structured audit log for portal reads.** webtrees logs authentication;
  nothing logs "member A viewed member B's record". Phase 3, with connections,
  is probably when that starts to matter.
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
* The web server Playwright starts binds to `127.0.0.1` explicitly. Left to
  itself `vite preview` binds to `localhost`, which on a CI runner can resolve
  to `::1` alone while Playwright polls `127.0.0.1`: the server starts, nothing
  answers on the address being watched, and the run dies on the webServer
  timeout with no indication of why. The build was also moved out of the
  webServer command and into `npm run test:e2e`, so a broken build is reported
  as a broken build rather than as a server that never came up, and
  `stdout`/`stderr` are piped so vite's own output reaches the log.
