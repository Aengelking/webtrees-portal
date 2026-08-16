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

If the answer turns out to be "several trees", say so before Phase 2: the
member→individual link is per-tree, so a member could have a different record
in each, and the directory would need to say which tree an entry refers to.

### 1.3 Do members self-register, or does an editor create accounts?

**Not decided; nothing was built either way.** Phase 1 has no registration
endpoint at all. webtrees' own registration page is untouched and behaves
however it is currently configured on the host.

Recommendation, unchanged from the handoff: invitation-only. It removes the
registration surface, the spam problem, and the "who is this person and are
they really family" question that no software can answer.

### 1.4 Password reset

**Not built.** The login screen says "please contact the family
administrator", in both languages.

The cheap option is to link out to webtrees' existing reset flow — it already
works, and it sends the email. The cost is that it drops the member into the
webtrees UI mid-flow, which is exactly what the portal exists to avoid. A
portal screen over the same backend is maybe a day's work in Phase 2, and it
needs the same rate limiting and the same generic responses as login. Your
call.

### 1.5 Is Google/social login a requirement?

**Assumed no**, following §1 of the handoff. If it becomes one, the cost is an
OIDC module plus an identity-mapping table (`provider`, `subject`,
`wt_user_id`) — the portal's own auth surface (`SessionCreate`,
`RequireAuthentication`) would gain a second way in, not be replaced.

Nothing in Phase 1 makes that harder. It is a real chunk of work, though, so it
is worth answering before Phase 2 rather than after.

### 1.6 Where does webtrees live long-term?

**Still open.** The only thing this repository assumes is that the webtrees
host is reachable over HTTPS from Cloudflare's network and that it can set
cookies for its own hostname. Moving it means changing `WEBTREES_ORIGIN` on
the Pages project.

---

## 2. Decisions taken

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

### 2.11 SFTP deployment swaps rather than overwrites

`module/tools/deploy-sftp.sh` uploads to `portal_api.upload/` beside the target
and swaps it in with two renames, instead of mirroring over the live
directory. The host serves requests throughout an upload, and webtrees loads
`module.php` on every request — so an in-place overwrite has a window in which
the module is half one version and half another.

The staging and rollback directory names contain a dot, which is not
cosmetic: `ModuleService::customModules()` skips any directory under
`modules_v4/` whose name contains `.`, so neither is ever loaded as a module.

Host key verification is required and cannot be turned off — there is no
`StrictHostKeyChecking=no` escape hatch, deliberately. Password authentication
works (via `sshpass`) but keys are the documented path; anything that can write
to `modules_v4/` can install arbitrary code.

The workflow runs the full test suite before it uploads anything, and there is
no input to skip it. The privacy assertions are the reason to trust a release
of this particular thing.

### 2.12 Three navigation destinations, and no component library

My profile, Members, Settings. Tailwind plus about ten local components in
`portal/src/components/`. No MUI, Chakra or Ant: the constraints that actually
matter for this audience — 16px floor, 44px targets, real contrast, plain
language — are easier to hold in a few files we own than to negotiate with a
design system.

Error and empty states are sentences with a next action, in both languages. The
API's `error` code only chooses which sentence; no code is ever shown.

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

Three, all small, all deliberate.

1. **PHP version.** The handoff says 8.2+; webtrees 2.2 requires 8.3–8.4.
   See §1.1.
2. **`GET /csrf` is an endpoint the handoff does not mention.** §5 lists only
   `POST` and `DELETE /session`, but a cookie-based CSRF token has to be
   fetched somehow before the first login, and putting it in the login response
   would be too late. It is unauthenticated and returns nothing but the token.
3. **`/members/{id}` returns both `individual` (a summary) and
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
* **Nothing tells a member their account is not linked to a record.** `/me`
  returns `individual: null` and the portal explains it, but no one tells the
  *administrator*. A "members with no linked record" list in the module's
  admin page would be a small, useful addition.
* **`portal_login_attempt` grows between prunes.** Pruning is opportunistic
  (1 in 20 failed logins). Under a sustained attack, the table could reach
  perhaps tens of thousands of rows before pruning catches up. Indexed and
  small, so not a problem — noted so it is not a surprise.
* **There is no plain CI workflow.** `deploy.yml` runs the whole suite, but
  only on a push to `main` that touches the module, or on a manual run —
  nothing runs the tests on a pull request. Splitting the `test` job into its
  own `ci.yml` and having `deploy.yml` depend on it is a ten-minute change,
  left undone because it was not asked for.
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
* PHPUnit reports one deprecation on PHP 8.4, from `oscarotero/middleland`
  inside webtrees' own vendor directory. It is webtrees' dependency, used by
  webtrees in production, and nothing in this repository can or should fix it.
* The Playwright smoke path stubs the API in the browser by default, so it
  runs anywhere. `E2E_BASE_URL` points the same specs at a real deployment.
