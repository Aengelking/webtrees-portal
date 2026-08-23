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
| `portal/sw/` | The service worker that makes it installable. Built separately, to `/sw.js`. |
| `portal/public/icons/` | The app's mark, taken from the family arms. Two SVGs; the PNGs beside them are rendered by `portal/tools/build-icons.mjs`. |

## Scope

**Phases 1 to 11 are built.** Members can be invited, read the tree within
their privacy level, change their own portal settings, propose changes to
their own record, reset their own password, share contact details with an
audience they choose per entry, write to each other, read and answer their
messages in the portal, and connect with each other into a contact list of
their own.

**No edit writes to the tree.** A member's change goes to webtrees' pending
changes list with a `CHAN` entry naming them, and an editor approves it in
webtrees exactly as they would any other edit.

Endpoints: `GET /csrf`, `POST|DELETE /session`, `GET /me`,
`PATCH /me/profile`, `PUT /me/individual`, `GET /individuals/{xref}`,
`GET /members`, `GET /members/{id}`, `GET /individuals/{xref}/ancestors`,
`GET /media/{xref}/{fact}/{size}`, `POST /password/request`,
`POST /password/reset`, `POST /invitation/preview`,
`POST /invitation/accept`, `GET|POST /invitations`,
`DELETE /invitations/{id}`, `GET|PATCH /me/contact`,
`POST /members/{id}/message`, `GET /messages`,
`PATCH|DELETE /messages/{id}`, `POST /messages/{id}/reply`,
`GET|POST /connections`, `PATCH|DELETE /connections/{id}`,
`POST|DELETE /me/connection-code`, `POST /me/connection-link`,
`DELETE /me/connection-links/{id}`, `GET /health`.

Screens: login, accept an invitation, forgotten password, set a new password,
My profile, edit my details, person, ancestors, Contacts (with the directory
search in it), the member directory, member detail, Messages, connect (where a
scanned code lands), invite close family, Settings.

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

Members can invite their own close family from inside the portal, within a
relationship distance and a quota the administrator sets — see *Letting members
invite their own family* below for what that does and does not allow.

Members can also connect with each other and keep a contact list: a QR code
held up at a family gathering, or the SB number under somebody's name
plus their confirmation. See *Connecting members with each other* below.

When something breaks for a member, somebody finds out: the failure is
recorded with a short reference the member is shown, and the control panel has
a Diagnosis screen that checks everything the portal needs and says what to
fix. `GET /health` proves the whole chain in one request, and the deployment
uses it.

The portal installs onto a home screen and opens like an app, under its own
icon and without an address bar. What it keeps on the device is the app
itself and nothing about anybody — see *Installing it on a phone* below.

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

### Letting members invite their own family

*Control panel → Modules → Member portal API → preferences.*

A member opens *Einstellungen → Jemanden einladen*, sees a list of their close
relatives named by relationship ("Ihr Bruder — Dieter Beispiel"), picks one and
gets a link to pass on. No administrator is in the loop.

**Handing the link over** is **Teilen** where the browser has a share sheet —
a phone, an installed app — which puts it straight into WhatsApp or a text
message, and **Kopieren** everywhere, because most desktops have no share
sheet and that is where somebody sits when they write the e-mail. The field
stays either way, so a browser that refuses the clipboard costs nothing. The
connection link in *Kontakte* works exactly the same way; it is the same
component.

**The same offer is on the person's own page**, which is where a member
actually notices that somebody is missing: walking the tree, a relative who can
be invited carries an **Einladen** button that opens the invite screen with
them already chosen. It appears only for somebody who is already a candidate on
that screen, so it discloses nothing new — and its absence says nothing either:
dead, already an account holder, already invited and too distant all look the
same, which is the point.

**One thing to understand before switching this on.** The obvious rule for who
counts as close family — the people the portal already shows a member — is not
the rule this uses, because it would be far too wide. webtrees applies
relationship privacy only to accounts that have a per-user
*Relationship path length* set, and that is not set by default. Without it, a
member sees **every living person in the tree**. So the distance is measured by
this module instead, by walking the family tree, and the limit is the setting
below. What a member can *see* is applied on top: a relative they may not see
is never a candidate, whatever the distance.

**The three settings:**

* **Members may invite their own close family** — the off switch. On by
  default.
* **How close a relative must be** — one step (parents, siblings, partners,
  children), two (also grandparents, grandchildren, nieces and nephews,
  parents-in-law) or three (also first cousins and great-grandparents). Two is
  the default.
* **Invitations one member may have outstanding** — three by default. Counts
  only links that have not been used yet; zero has the same effect as switching
  it off.

**What you still see.** Every invitation appears on the Invitations screen with
a *Created by* column, and you can withdraw any of them while they are unused.
The member can withdraw their own, and nobody else's.

**Who is quietly not offered.** Relatives who are dead, who already have an
account, or who already have an invitation outstanding are simply absent from
the member's list, with no explanation. That is deliberate: "your sister
already has an account" would disclose something the portal otherwise treats as
hers to share.

### Contact between members

*Control panel → Modules → Member portal API → preferences → Contact between
members.*

A member enters an email address, a telephone number and a postal address
under *Einstellungen → Meine Kontaktdaten*, and chooses **for each one
separately** whether nobody, only their close family, or every member may see
it. Nothing is shared until they choose it.

**These are not the contact details in the family tree.** Those stay
unpublished, as they have been since Phase 1. Contact data in a GEDCOM record
is maintained by whoever keeps the tree, and nobody can meaningfully consent
to "whatever my record happens to say". What is shared here is what the member
typed about themselves, and clearing the field deletes it.

*Close family* is the same distance as for invitations — one definition, set
once.

**Messages.** A member can write to another member from their directory page.
Delivery is webtrees' own message system, so each person is reached the way
they chose in webtrees, and the portal never learns their address.

One thing to know before switching it on: **the sender's own email address
travels with the message**, as the reply address. webtrees puts it there so a
reply is possible, and there is no way to allow a reply without it. The portal
says so on the form, above the send button. Only members who put themselves in
the directory can be written to, and there is a daily limit per sender.

### Conversations

Members write to each other in a conversation: one thread with one person,
both halves on one screen, no subject line. It sits at the top of
**Nachrichten**; below it, under *Sonstige Nachrichten*, is the inbox that
holds everything arriving from elsewhere — webtrees' own contact form, an
administrator's broadcast.

**Starting one** is a button next to the Gespräche heading, **Neues Gespräch**:
it lists the member's contacts, and tapping a name opens the conversation and
goes to it. Contacts are not everybody who may be written to — anybody listed
in the directory may be — so that screen also points at the directory, and the
other person's page still has **Nachricht schreiben**, which does the same
thing from the other end. Opening is idempotent: picking somebody a member
already talks to lands in the conversation that exists.

**Why there are two lists.** webtrees' `message` table keeps one row per
message, owned by whoever received it. Nothing is stored for the sender — so a
transcript could not be built from it at all, and the portal keeps its own
store (`portal_conversation`, `portal_message`) beside webtrees' rather than
instead of it. Nothing that arrives from webtrees is lost or moved.

**Who may start one** is the rule that already governed writing to a member:
listed in the directory, or connected to you. A member who is neither is
reported as not found, exactly as a member id that never existed. Once a
conversation exists, either side may write in it whatever changes afterwards —
the transcript is proof they know each other.

**Notification carries nothing.** The other side gets an e-mail saying that a
message is waiting in the portal, with a link to it — **no text, no sender's
name, and no reply address.** The transcript is on a screen both people sign
in to, and an inbox is not that screen: it is read by whoever picks up the
phone and stored by whoever runs the mail server. It is the same refusal as
the push notification's, one channel over.

It goes out when a conversation has something new and the other side has
nothing unread from that person already — not once per message: a chat that
e-mails every line is a chat nobody stays in. No copy is filed in webtrees'
inbox either, so a conversation message does not also appear under *Sonstige
Nachrichten*. A member who is reached only through webtrees' internal
messaging gets no e-mail at all, and needs none: the message is in their
conversation list with the count in the navigation.

**Deleting is for yourself.** A message you delete leaves the other person's
copy alone, and the screen says so before you confirm. A message both sides
have deleted is removed from the database. Clearing a conversation empties your
copy; the other side keeps theirs, and a new message brings it back.

**The daily message limit counts conversation messages too**, and switching
member messages off in the module's settings switches conversations off with
them.

### Notifications on a phone

*Setting: **Notify members on their phone when a message arrives**, on by
default. Needs the portal address to be set.*

A member who has installed the portal (see *Installing it on a phone*) can be
told when a message arrives, instead of having to open it and look. They switch
it on for themselves, on each device, under **Einstellungen → Benachrichtigungen**.

**The notification carries nothing.** It says *„Sie haben eine neue
Nachricht."* and nothing else — not who wrote, not a word of what they wrote,
not which conversation it belongs to. That is not a limitation being worked
around; it is the feature. A lock screen is read by whoever picks the phone up,
and in a portal about living people, a name on one is a disclosure nobody
consented to. The screen where a member switches it on says so before they
decide.

Technically this is a **push with no payload**: the module sends an empty
request to the browser's push service, the browser wakes the service worker,
and the service worker shows a sentence it already had. Nothing about the
message leaves the server at all. It also means the portal stores no encryption
keys for it — `portal_push_subscription` holds the device address and nothing
else — because there is no payload to encrypt.

**What the member needs.** An installed app on Android or a desktop browser;
on an iPhone, iOS 16.4 or later *and* the app added to the home screen — Safari
in a tab cannot do this at all. The browser asks its own permission question,
once. A member who says no is not asked again by the portal: the page then says
where the browser's own switch is, rather than offering a button that would do
nothing.

On an iPhone or iPad that is still in a tab, the Benachrichtigungen section
says that the home screen is what is missing and points at *Auf den
Startbildschirm* one section above it. It used to render nothing there, which
left the largest part of the audience looking at a settings screen with no
explanation of why the feature everyone else had was absent.

**Signing out switches it off on that device.** A push subscription is not part
of the session — it is a row against a member's account plus an address held by
the browser's push service — so nothing about signing out would otherwise reach
it, and the phone would go on announcing arrivals for an account nobody is
signed into. The card says so before a member switches it on. An expired
session is *not* the same thing and is left alone: that is the case the feature
exists for, and the member is taken to the login screen and on to the message
from the notification itself.

**Every message knocks**, unlike the e-mail notification above, which stays
quiet while something is already unread. A notification that arrives while the
member is reading costs nothing and replaces nothing; the browser collapses
repeats into one entry.

**Keys.** The module makes a VAPID key pair the first time it boots and keeps
it. It is never replaced automatically — a new key orphans every device that
subscribed under the old one, and each member would have to switch it on again.

**Switching it off** in the module's settings stops all of it and stops
members being offered it. Existing subscriptions stay in the table and start
working again if it is switched back on.

### Reading messages in the portal

*No setting — this is on whenever the module is.*

**Nachrichten** is the fourth entry in the portal's navigation bar, with a
badge showing how many messages are unread.

The list is webtrees' own mailbox, not a portal one. Everything addressed to
the member appears there whatever route it took: a message another member sent
from the portal, one sent through webtrees' own contact form, an
administrator's broadcast. This is the answer to the awkward case in the
previous section — a member whose webtrees contact method is *internal
messaging only* can now actually read what arrives.

**Answering.** A message can be answered from the card it is read on. Three
things about a reply differ from writing to somebody in the directory:

* **A member who stayed out of the directory can be answered** — they wrote
  first, so nothing is being discovered. They still cannot be approached.
* **The subject is not yours to write.** webtrees' `RE: ` goes on the
  original, so an answer arrives as an answer rather than as a new
  conversation.
* **Your own email address travels with it**, as the reply address, exactly as
  in the previous section. It says so above the button.

A reply is only offered where it can be delivered — see the third point below
— and no copy is kept: webtrees stores the recipient's copy of a message and
nothing for the sender, so there is no sent folder and the portal does not
pretend there is one.

Three more things worth knowing:

* **Deleting deletes.** The portal does not keep a copy. Deleting a message
  here removes it from webtrees as well, and the screen says so under the list.
* **Opening marks read.** There is a *Als ungelesen markieren* button on the
  open message for anyone who wants the badge back.
* **The sender is shown by name where webtrees can work it out.** It stores a
  reply address rather than a link to an account, so a sender who has since
  changed their address — or who has no account at all, having used the public
  contact form — shows as that address instead. Nothing new is disclosed: it
  was already the reply address on the member's email. Those are exactly the
  messages that cannot be answered from the portal, and the screen says why
  rather than quietly leaving the button off.

Read state is the portal's own (webtrees does not track it), so marking
something read here does not change anything in webtrees.

### Connecting members with each other

*Control panel → Modules → Member portal API → preferences → Members may
connect with each other.*

**Kontakte** is the member's own address book: the eight people they actually
know, rather than the two hundred in the directory. It is the second entry in
the bottom bar — the bar stays at four destinations — and the **member
directory now lives inside it**, as a search on the screen's second half.

That is the way round it belongs. The directory is everybody, and a member
looks somebody up now and then; contacts are their own people, and that is the
screen they come back to. Searching for a name goes straight to the results;
an empty search shows everybody. *Mitglieder* keeps its own search box, so
narrowing a result does not mean going back, and a link back to *Kontakte*.

**The screen is two tabs**, because the thing a member comes back to had four
cards of machinery stacked on top of it — a search, a QR code, a link, a
number — and reading the address book began with scrolling past all of them.

* ***Kontakte*** holds the people: a request waiting for an answer at the top,
  then everybody the member is connected to, then their own requests still
  waiting. This is the tab that opens.
* ***Neu verbinden*** holds the ways of adding somebody: the directory search,
  the code, the link and the SB number.

Which tab is open is written into the address (`?tab=new`), so a refresh, the
Back button and a link all keep it. A member whose address book is still empty
is put on the second tab instead — the empty half is not what they came for.
The tabs are real tabs: the arrow keys move between them, and only the open
one is in the tab order.

**Two ways to connect, and they are different on purpose.**

*The code, for when both people are in the same room.* One member taps **Code
anzeigen** and a QR code appears; the other points their telephone's camera at
it and follows the link the camera offers, which opens the portal and asks
once — opening a link is not consent, so the screen says what is about to
happen and waits for the button. There is nothing
to install and no scanner in the portal: what is in the code is a link to the
portal's own `/connect` screen, which every camera app already knows how to
open — including on iPhones, where the browser API for reading barcodes does
not exist at all.

Three things about the code:

* **Showing it is the consent.** Redeeming one connects both members straight
  away. Asking somebody to confirm what they are doing in front of you is a
  step that teaches people to tap *yes* without reading.
* **It is a credential while it lives.** Anybody who can see the screen — or
  photograph it — can connect until it expires, which is a quarter of an hour
  by default. The module stores only a hash of it, exactly as it does for an
  invitation, and it says on the screen how long it lasts.
* **It can be taken back.** There is only ever one live code per member:
  *Neuen Code erzeugen* stops the previous one working, and *Code ungültig
  machen* stops the current one at once. Neither touches connections already
  made.

*A link, for somebody you can already reach.* A member who has an address, a
telephone number or a chat with a relative should not have to arrange a
meeting to connect with them: **Link erzeugen** hands them one to paste into
whatever they were going to write anyway, and whoever opens it and taps is
connected. The portal never learns who it went to — the member sent it
themselves, exactly as they send an invitation.

Two things differ from the code on the screen, and both follow from a week in
somebody else's inbox:

* **It lasts seven days**, because a message sent on Tuesday is read on
  Thursday.
* **It works once.** Forwarded, quoted in a reply, left in an old chat that a
  new telephone still syncs — by then it is spent. The screen says so above
  the link rather than after the fact, and the links a member sent and nobody
  used are listed underneath with the date they run out, each with
  *Zurückziehen*. No names against them: the portal does not know.

*The SB number, for everybody else.* "Meine Nummer ist 10/1335.21" is a thing
that can be said over the telephone or written in a Christmas card. The member
enters it, and the other member gets a request and answers it — this one never
connects anybody by itself.

**The branch is picked, not typed.** A number is a branch, a slash and the
number within it, and "/" on a telephone keyboard is two taps into a second
layout for a mark whose only job is to separate two numbers the form already
keeps apart. So the form is laid out the way the number is written — a wheel
of the thirty-four branches, a printed slash, a field for the rest — and what
is on screen reads as the number itself. **Every number in this family has a
branch**, so there is no "no branch" on the wheel: the dash at the top means
"not chosen yet" and cannot be sent. A number typed whole into the field,
slash and all, is passed through as typed, which is the way in if the family
ever grows a thirty-fifth branch.

Everything else about the number is read generously, because it is read off
letterheads and out of Christmas cards:

* **The separators are ignored**, not the number. Spaces, full stops, commas
  and hyphens go, so `10 / 1335,21` is the same number as `10/1335.21`.
  Everything else is part of it.
* **Letters count, wherever they sit.** A number is not only digits, and the
  ones that carry letters are read as written.
* **The marker on the end counts most of all.** `!` means the spouse of the
  person carrying the same number without it, so `10/1335.21` and
  `10/1335.21!` are two people. It is never dropped to make a number fit —
  reaching nobody is a better answer than reaching somebody's husband.
* **The "SB" may be there or not.** It is accepted even where the GEDCOM
  record carries no `TYPE` of its own — the usual case, since nothing requires
  one and the family's numbering is called SB either way. Only a run of
  letters on the *front* may fall away, so a number with letters of its own
  keeps them. What is not accepted is a prefix the record *contradicts*: a
  number filed as `TYPE Intern` is a different numbering and "SB 9999" will
  not find it.
* **The slash may be left out**, but only while that picks out one person.
  `10/1335.21` and `101/335.21` are one string once it is gone, and the portal
  says it found nobody rather than guessing which cousin was meant.

**A number that is already a contact says so.** Nothing is sent, and the
answer names them — listed in the directory or not. They are already in this
member's own address book, on the other half of the same screen, so there is
nothing left to keep quiet about. This is the one exception to the silence
below, and it exists because the silence was telling people something untrue:
an unlisted contact typed by number used to be answered "your request is on
its way", and the member then waited for an answer that could not come.

A request still *waiting* for one keeps the quiet answer, though. "You already
asked this person" would say that the number belongs to somebody, which is the
whole of what a member who stayed out of the directory is owed silence about.
The exception is a request that crosses one coming the other way: typing the
number of somebody who has already asked *you* is the answer to their request,
and that is said out loud, because their name was in your own list before you
typed anything.

**A member who is not in the directory can be asked too** — and this is the
one place where the portal deliberately answers vaguely. A number that reaches
somebody unlisted and a number nobody carries get the *same* answer, byte for
byte: "if that number belongs to a member, your request is on its way".
Neither the name nor the fact that the number belongs to anybody reaches the
person asking, and the unanswered request is not in their own list either — a
row that appeared only for real numbers would say the same thing more quietly.
It appears the moment it is accepted, which is the person deciding to be
known.

Without that silence the number search would be a way of asking which
relatives have an account, which is exactly what staying out of the directory
is a decision against. With it, being unlisted still means "nobody finds me by
browsing", and knowing somebody's number still means "I can ask".

A member who *is* listed is answered by name, because they published it.

One limit remains: **a number the record marks confidential is not
searchable.** A `REFN` under a `RESN confidential` is not there to be found.

What is *not* a limit, and used to be: whether the searching member may see
the other person's record. A relationship limit (see *How much of the tree a
member sees*) hides most living people from most members, and the search used
to skip everybody it hid — so a family that switched that on had a number
search that found nobody, while the directory listed those same people by name
one screen away. The number comes off a letterhead rather than out of the
tree, and the person it belongs to is already published in the directory under
their name, so the search now reaches exactly the people the directory does.

**When a number reaches nobody, the Diagnosis screen says why.** From the form
that is unanswerable by design, so the answer lives where an administrator can
see it: *Control panel → Modules → Member portal API → Diagnosis* lists every
member of the directory with the numbers the search can find them by. What it
cannot show is the people who are not listed — that is the whole point of the
silence — but it does separate the two causes an administrator can act on: no
portal account at all, and a record carrying the number somewhere other than a
`REFN`. Somebody who has no account cannot be connected with at all, however
their number is spelled; they need an invitation first.

**Or straight from the member list.** Every row of the directory carries a
**Verbinden** button, and the same request goes off without opening the person
first — the detour bought nothing, because everything needed to decide is on
the row already. A row for somebody who asked *you* offers **Annehmen**
instead; one for somebody already asked, or already a contact, says
*Angefragt* or *Verbunden* rather than offering a button that would do
nothing. Each button is named for its row ("Verbinden mit Dieter Beispiel"),
because twenty-five buttons all called *Verbinden* are a list nobody can
navigate by name. The same offer sits on the member's own page.

This is only affordable because the state is one row of one table: it is read
once for the whole page, not once per row. Contact details are still not in
the list, and for the opposite reason — deciding "close family" walks the
tree.

**What a connection is worth.** Three things, and nothing else:

* **A contact list**, with a link to each person's page.
* **A third audience for contact details** — *Nur meine Kontakte*, beside
  "nobody", "close family" and "all members". It is the only one of the four
  the member built themselves.
* **Reachability.** Two connected members can write to each other and open
  each other's page even if one of them stayed out of the directory. Nothing
  is discovered by writing to somebody who agreed to know you — the same
  argument that already lets a member answer a message from somebody
  unlisted.

**What it is not.** It is not a relationship in the family tree: nothing is
written back to the GEDCOM, no connection is derived from it, and being
connected lifts none of webtrees' privacy rules. A connected relative's record
is exactly as visible as it was before.

**Either side can end it, at any time, without asking or telling.** Declining
a request, withdrawing one and disconnecting are one operation, and the row is
deleted rather than marked — so nothing is left from which anyone could later
read off who refused whom. Declining and withdrawing are asked for where the
request is, in the contacts screen; ending a connection that exists is asked
for on the other member's page, not from the address-book row.

Switching the feature off in the control panel silences everything a
connection discloses, contact details included, and refuses new ones. The
lists stay, so that members can still see what they agreed to.

### The link back to webtrees, for people who edit

*No setting.*

Every person screen in the portal links out to that person's page in webtrees.
Which link, and where it sits, depends on the role the signed-in account holds
**in the configured tree**:

* **Members** get it at the foot of the record, worded for what they would go
  there for: *Stammbaum und Diagramme öffnen*.
* **Editors, moderators, managers and administrators** get it at the top
  instead, worded for what *they* go there for: *In webtrees öffnen und
  bearbeiten* — with one line saying it is their role showing.

It is the same address either way. Nothing is unlocked by the wording, and
nothing is hidden by it — webtrees decides what the person arriving may do.
What the distinction buys is that a member is not pointed at an editing screen
they have no business on, an editor does not hunt for the tree they maintain,
and neither is shown two links to one page.

**Both links go through `/portal/individual/{xref}` on the webtrees host, not
at the record directly.** The portal and webtrees are separate origins and the
session cookie belongs to the portal, so anybody following a link out arrives
at webtrees signed out. That redirect sends them to the login page with the
record as its destination, or straight to the record if they happen to be
signed in there already — because neither plain link gets both cases right:
webtrees' login page discards the destination for a reader who is already
signed in, and a record address for a signed-out reader either loses the
destination on the way to the login page or, on a tree that does not require
authentication, reports the record as not existing.

This matters most on a tree with **authentication required**, which is the
usual setting for a family portal: there, a signed-out visitor cannot see the
tree at all, so a link straight at a record has nothing to fall back on.

They do still have to sign in on the webtrees side. There is no shared session
across the two origins, and there is no way to have one short of putting all
of webtrees behind the portal's domain. What the redirect fixes is where that
sign-in ends up.

### How much of the tree a member sees

*Control panel → Modules → Member portal API → preferences → What members can
see.*

This is a different control from the invitation distance, and the one most
likely to be wider than you expect. **By default a member sees every living
person in the family tree.** webtrees limits that only for accounts that have
both a linked record and a per-user *relationship path length*, and it sets
neither on its own — there is no site-wide or tree-wide default to inspect,
which is why nothing anywhere tells you.

Setting a limit here writes that per-user value for you. What it does:

* **Living people only.** Everybody webtrees knows has died stays visible to
  everybody. The genealogy is unaffected — this hides living relatives who are
  further away than the limit, and nothing else.

  Worth knowing before you switch it on: *living* is webtrees' own guess.
  `Individual::isDead()` says deceased for a death event, for any dated event
  more than 120 years old (`MAX_ALIVE_AGE`), or by inference from relatives'
  dates. **A record with a name and no dates at all counts as living**,
  however obviously historical the person is — so a limit hides thin records,
  not recent ones. If your tree has many name-only entries, check a few before
  applying the limit to everybody.

  One tree setting can widen this further: `KEEP_ALIVE_YEARS_DEATH` makes
  webtrees go on treating somebody as living for N years after they died, and
  a person "kept alive" is subject to the limit like anybody else. It is empty
  by default.
* **New accounts created by invitation get it automatically.**
* **Existing accounts keep what they have** until you press the button on the
  Diagnosis screen. That button changes what people who are already signed in
  can see, so it does not happen on its own.
* **Editors, moderators, managers and administrators are never restricted.**
  They need the whole tree to maintain it.
* **An account with no linked record cannot be limited at all** — the distance
  is measured from that record. Those accounts show up in the "no linked
  record" list, which is where to fix them.

The steps mean the same thing as in the invitation setting: 1 is parents,
siblings, partners and children; 2 adds grandparents, grandchildren, nieces,
nephews and parents-in-law.

The Diagnosis screen reports the current state under *What a member can see*,
including how many accounts still have no limit.

### When something goes wrong

*Control panel → Modules → Member portal API → Diagnosis.*

Two decisions in this module make failures quiet on purpose: `boot()` swallows
whatever it throws so a broken portal cannot take webtrees down with it, and
every unhandled exception reaches the member as "please try again later"
rather than as an internal message. Both are right, and together they mean an
installation can be broken for one person with nothing anywhere looking wrong.
This screen is where it shows.

**The checks.** Which tree is being served (worth reading even when it is
green — after a rehearsal against a test tree, that is the setting most likely
to be quietly wrong), whether the database tables match the code, whether the
module's routes registered at all, the portal address, the proxy secret,
whether webtrees' own registration page is still open, accounts with no linked
record, and errors in the last 24 hours.

Two of them are hard to notice any other way:

* **API routes — not registered.** The module did not start. webtrees is
  unaffected, which is exactly why nothing else looks wrong. The reason is in
  the server's error log on a line beginning `portal_api:`.
* **Database tables — the code expects a newer version.** The files were
  uploaded but the migrations have not run. From the deployment's point of
  view this looks like success.

**The error list.** Every request that failed for a member, newest first. Each
one showed that member a short reference — if somebody quotes one, search for
it here.

Only the endpoint and the error are recorded. Never the request body, the
query string or the path: those are somebody's date of birth, whom they
searched for, and which record they were reading. Entries are deleted after
30 days.

### Checking the portal from outside

`GET /api/v1/health` answers `{"status":"ok","version":…,"schema_version":…}`.
Answering it at all takes a request through the Cloudflare Worker, the proxy
secret, whatever URL form webtrees needs, PHP, webtrees' bootstrap, the
module's `boot()`, the database and the tree setting — so one request either
proves all of that or reports the first thing that is wrong.

It needs no sign-in (a health check that needs credentials is a health check
nobody runs), and the payload is deliberately dull. Point an uptime monitor at
it if you have one.

`deploy.yml` calls it after every upload and compares the reported version
against the one in the checkout, which is what turns "the upload reported
success" into "the new code is actually running". Set a repository **variable**
(not a secret) called `PORTAL_URL` to switch that on:

```
PORTAL_URL = https://portal.example.org
```

Without it the step is skipped.

**Bump `CUSTOM_VERSION` in `PortalApiModule` when you change the module**, or
that comparison can only ever confirm that *some* build is running. It is also
the quickest answer to "is the fix actually on the server?" — the version is on
the Diagnosis screen, in webtrees' own module list, and in `/health`.

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

### Installing it on a phone

The portal is a progressive web app. `portal/public/manifest.webmanifest`
makes it installable and `portal/sw/` becomes `/sw.js`, so a member can put it
on their home screen and open it under its own icon, without an address bar,
the way they open everything else on that phone.

**What is cached, and what never is.** The service worker keeps the shell —
`index.html` and the hashed script, stylesheet and icons it names — and
nothing else. Every request under `/api/` is left entirely alone: not answered
from a cache, not put into one, not even intercepted. That is the rule from
[Caching](#caching) one layer further out. A phone is the device most likely
to be handed to somebody else, and a copy of the family's records sitting on
it would outlive the session that fetched them.

`portal/e2e/pwa.spec.ts` checks this in a real browser: it signs in, walks two
screens of family data, then asks the browser what it kept, and fails if a
single URL under `/api/` is in there.

**A button where there can be one, and directions where there cannot.**
Settings offers *Auf den Startbildschirm* at the top of the screen. What it
shows depends on what the browser will say:

| Situation | What Settings shows |
| --- | --- |
| A browser handed over an install prompt | the button — one tap |
| Android, no prompt handed over | where Chrome's own ⋮ menu is |
| iPhone, iPad | where the Share sheet is |
| Inside another app's browser (a link tapped in WhatsApp) | that it has to be opened in a browser first |
| Already on this device | that it is, and nothing else |
| Inside the installed app, or a browser that cannot install | nothing at all |

The Android row is the one that matters most and the one the first version got
wrong. Chrome hands out `beforeinstallprompt` when it feels like it, and not at
all if it thinks the app is installed or the page was opened a moment ago —
which used to leave a member on a screen promising an app with no way to get
one. Only two situations are now silent, and in both of them there is genuinely
nothing to say.

"Already on this device" cannot be worked out from a tab: `display-mode`
describes only the window it is asked in. So the manifest names *itself* under
`related_applications`, which lets `navigator.getInstalledRelatedApps()` answer
the question on Chrome. If it will not answer — another browser, an insecure
context — the member simply gets the ordinary offer, which is a fair thing to
show somebody whose browser will not say.

Chrome's own install bar is suppressed (`preventDefault` on
`beforeinstallprompt`) so the offer appears where it can be explained rather
than at the foot of the screen at a moment of the browser's choosing. There is
no banner and no dismissal to remember — the one bar this portal shows at the
top of a screen is *no connection*, and a member who has learnt to dismiss
what appears there will dismiss that too.

**Offline it says so, rather than looking broken.** With no address bar there
is nothing to tell a member that the portal has not forgotten them — the
network has. So the shell still opens without a connection, and a bar across
the top says there is none. No genealogy is shown, because none was kept.

**Updating happens by itself.** Pages are fetched from the network first and
only fall back to the cached copy, so an online member always gets the current
`index.html` and the assets it names. Each deployment compiles a new build
stamp into `sw.js`, which is both what makes a browser notice there is a new
worker and what names its cache — so activating it throws the previous
deployment's files away rather than piling on top of them.

**Turning it off.** Deleting the file is not how. Deploy a worker that removes
itself instead: replace the body of `portal/sw/service-worker.ts` with

```ts
const worker = self as unknown as ServiceWorkerGlobalScope

worker.addEventListener('install', () => worker.skipWaiting())
worker.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((names) => Promise.all(names.map((name) => caches.delete(name))))
      .then(() => worker.registration.unregister()),
  )
})
```

and deploy that. Every phone that opens the portal once picks it up, empties
its cache and goes back to being an ordinary website. Take
`registerServiceWorker()` out of `portal/src/main.tsx` at the same time, or the
next visit installs it again.

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

**Contact and messages** (`module/tests/ContactTest.php`,
`portal/src/Contact.test.tsx`) — an entry set to *nobody* reaches nobody, one
set to *close family* reaches close family and no one else, and one member can
hold two entries with two different answers; an unrecognised audience shares
nothing; switching the facility off silences entries that already exist;
clearing a value deletes the row rather than hiding it, and the client sends
the empty field so that it does; the directory *list* carries no contact
details at all; a member who stayed out of the directory cannot be written to
and is reported exactly as a member id that never existed; and the member is
warned that their address travels with a message before they send it.

**Visibility** (`module/tests/VisibilityTest.php`) — the default state
(every member sees every living person) is reported rather than left silent;
applying a limit touches member accounts and never editors, managers or
administrators; an account with no linked record is skipped, because webtrees
measures the distance from that record; and an account created by invitation
arrives with the limit already set, or without one when the setting says not
to restrict.

**Member invitations** (`module/tests/MemberInvitationTest.php`,
`portal/src/Invite.test.tsx`) — a member is offered only living relatives
within the configured distance whom they may see; a confidential sister, an
unconnected stranger and every dead ancestor are absent, and so is anybody who
already holds an account; posting an XREF that was never offered is refused,
and every refusal is byte-identical; the quota is enforced; a member cannot
withdraw somebody else's invitation or even see it; and switching the feature
off answers rather than 403s, so the screen can explain itself.

**Operations** (`module/tests/OperationsTest.php`, `portal/src/Errors.test.tsx`)
— an unhandled failure is recorded and answered with a reference that matches
the recorded row, while the member is still told nothing about what broke; the
recorded row names the endpoint and never the request; a refusal the module
meant to give, including the 503 for a portal with no tree, is not recorded at
all; the health endpoint reports the running version and discloses nothing
about the family; and the diagnosis screen reports a missing tree, a schema
behind the code, a database ahead of it, an open registration page and a
missing proxy secret — and survives an installation where nothing is
configured.

**Answering** (`module/tests/InboxTest.php`, `portal/src/Messages.test.tsx`) —
an answer reaches the sender under webtrees' `RE: ` subject, and answering an
answer does not stack a second prefix even across a language switch; a member
who is *not* in the directory can be answered, which is the one rule a reply
lifts and the reason the feature exists; a message whose sender resolves to no
account, and one's own copy of a broadcast, are marked unanswerable and
refused with a reason rather than a 404; somebody else's message id is refused
exactly as an id that never existed; switching messages off switches replies
off, and the daily limit counts them; and on the client the answerer is told
their address travels with the reply *before* the button, and told afterwards
that no copy is kept.

**Delivery** (`module/tests/ContactTest.php`) — a member whose contact
preference was never set still gets their copy. webtrees files an internal
copy only for a contact method it recognises, and the empty string is not one,
so the message went nowhere and the call still reported success. This test was
green before the fix for the wrong reason — nothing was being sent, so nothing
could fail — and now asserts the copy rather than the status code.

**Conversations** (`module/tests/ConversationTest.php`,
`portal/src/Conversations.test.tsx`, `portal/e2e/smoke.spec.ts`) — a
conversation keeps both halves of an exchange, which is the thing webtrees'
table cannot do; opening one twice finds the same one; a member who is neither
listed nor connected cannot be written to first, and is reported as missing
rather than refused; a connection lifts that, and a conversation survives the
other person leaving the directory, name included; somebody else's
conversation and one that never existed give the same 404, for reading,
writing and deleting alike; deleting a message takes it off one screen and
leaves it on the other, and a message both sides deleted is gone from the
database; clearing a conversation does not end it for the other side, and a new
message brings it back; the daily limit and the family's off switch apply. On
the client: both halves are told apart, what was typed is given back when
sending fails, and the sentence about whose copy a deletion removes is on
screen before the button. Starting one from the messages screen picks the
contact who was tapped and lands in their conversation; a contact with no
member page yet is left out rather than offered as a button that fails; a
member with no contacts is sent to the contacts screen rather than a dead end;
a refusal from the server is shown rather than swallowed; and in a real
browser the whole path — messages, pick a name, write, and Back — ends where
it started. A conversation message files nothing in webtrees' inbox, so it
cannot arrive twice; and the announcement e-mail, rendered, names neither the
writer nor a word of what they wrote, in both the text and the HTML version.

**Notifications** (`module/tests/PushTest.php`,
`portal/src/Notifications.test.tsx`) — the columns a payload would need do not
exist in the table, so there is no place to put a name even by accident; a
browser re-subscribing the same device updates its row rather than collecting
them, and a shared tablet that changes hands moves instead of leaving two
people on one address; an endpoint that is not `https://` is refused rather
than stored; a member can only delete their own device; the family's off
switch withholds the key as well as the endpoint, so no browser can subscribe;
a portal that does not know its own address does not pretend it can send. The
signature conversion — DER to the sixty-four raw bytes JWS wants, the step
whose failure mode is a bare 401 from every push service — is checked against a
hundred real signatures. The key pair is made once and the public key is the
private one's point. On the client: the sentence about what a lock screen will
show is on screen before anybody switches it on, a refusal sends nothing and
reports nothing broken, and a browser that is blocking gets an explanation
instead of a dead button.

**Messages** (`module/tests/InboxTest.php`, `portal/src/Messages.test.tsx`) —
a member sees only messages addressed to them, and somebody else's message id
is reported exactly as an id that never existed, whether they try to read it,
mark it or delete it; the email wrapper webtrees stores around a message is
stripped, and a body that has no wrapper is shown whole rather than emptied; a
sender whose address matches no account is shown as that address instead of
vanishing; marking read twice is not an error; deleting a message removes it
from webtrees' own table and takes its read state with it; and on the client
opening a message marks it read, an already-read one is not marked again, the
badge is gone once nothing is unread, and its digit is hidden from screen
readers because the same count is already in the link's name as words.

**Getting into webtrees** (`module/tests/LinkTest.php`) — a signed-out reader
is sent to the login page with the record as the destination **on a tree that
requires authentication**, which is the case that matters and the one a public
fixture tree hides; the whole way is walked once end to end, through webtrees'
own router, login form and login handler, and ends on the record; and that
destination survives webtrees' own `isLocalUrl()` check, asserted with
webtrees' validator rather than by eye; a reader who is already signed in goes
straight to the record instead of being thrown to their user page; the
redirect takes an XREF and cannot be talked into pointing anywhere else; an
XREF that does not exist is answered exactly as one that does, so this is not
a way to ask what exists without signing in; and the API hands out this
address rather than the bare record one.

**Connections** (`module/tests/ConnectionTest.php`,
`portal/src/Contacts.test.tsx`, `portal/src/QrCode.test.tsx`) — a scanned code
connects both members at once and appears on both lists; the raw code is in no
column of `portal_connection_code`; asking for a new code, withdrawing one and
letting one expire each stop it working, and all three are refused
identically; scanning the same code twice is not an error; a link that was
sent works once and the second person to follow it is told so, lasts a week,
can be withdrawn while it is unused, and cannot be withdrawn by anybody else;
an SB number
*asks* rather than connects, and a request that crosses one coming the other
way is treated as the answer to it; the family's "SB" prefix finds a record
stored without a `TYPE` of its own while a prefix the record contradicts finds
nobody; a number that already belongs to a contact says so by name and writes
nothing, while a request still waiting for an answer keeps the quiet answer;
a branch number is found however it is punctuated, a number carrying
letters is found as written, the slash keeps
`10/1335.21` and `101/335.21` apart, and leaving it out is refused where it
would be a guess between the two; the `!` on the end keeps a couple apart and
is never dropped to make a number fit; a member who stayed out of the directory cannot be found by number,
and a `RESN`-hidden number cannot be searched at all; only the member a request was made to can accept it, and a refusal
deletes the row rather than recording it; a connection unlocks the *my
contacts* audience and lets an unlisted member be opened and written to,
while a third member is told nothing; and switching the feature off silences
all of that while leaving the lists.

Every row of the directory says where the two members stand, one's own row
says `self`, and the whole page costs one query.

On the client, no code is issued until the member asks for one, the QR code
holds the link the *server* issued, ending a connection asks first while
answering a request does not, a request goes off from a directory row without
opening the person, each row's button is named for the person it belongs to,
and the waiting-request count sits on *Kontakte* — the screen the request is
about — rather than on a fifth destination. The screen opens on the address
book, opens on the second tab while there is nothing in it yet, takes the open
tab from the address bar, and moves between the two with the arrow keys. The QR code itself is
rendered to pixels in the test and read back by an independent decoder, which
is the only assertion that means a camera would read it.

**The link to webtrees** (`portal/src/EditorLink.test.tsx`) — an editor,
moderator, manager and administrator each get the editing link, a member gets
the member's link and not the editing one, nobody gets both, and an editor is
told the link is there because of their role.

**Frontend** (`portal/src/**/*.test.ts*`) — the client attaches CSRF only to
unsafe requests and retries once on a stale token; a 401 anywhere resets the
app to the login screen; nothing but the language preference reaches browser
storage.

**The navigation bar** (`portal/e2e/smoke.spec.ts`) — it stays at the bottom of
the screen while the page scrolls under it, on a phone held upright *and* on
one held sideways. The second is the case that was broken: the bar went into
the flow of the page above 640px wide, which a phone on its side is, and
scrolled away on the screen shape with the least room to spare.

**The installed app** (`portal/sw/strategy.test.ts`, `portal/src/Pwa.test.tsx`,
`portal/e2e/pwa.spec.ts`) — the offer to install appears only when a browser
has given the portal a prompt to show, goes away for good once the app is
installed or the prompt is spent, becomes instructions rather than a button on
an iPhone (and on an iPad calling itself a Macintosh), and is nothing at all
in a browser that will not install it; the service worker never touches
anything under `/api/`, including a photograph, which is indistinguishable from an asset
except by its path; it refuses to store the SPA fallback's `index.html` under
the URL of a script that a deployment has removed, which is the one failure
that would leave a portal unable to start and unable to repair itself; it
caches the files the shell names and not only the shell, which is the
difference between an installed app and a blank page; the manifest is linked
and every icon it names exists; and in a real browser, after signing in and
walking two screens of family data, nothing under `/api/` is in the browser's
cache storage — while the portal still opens, and says why it is empty, with
the network switched off.

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

### A member is seeing an old portal

Almost always the service worker, and almost never on a device you have in
front of you. Pages come from the network first, so this should not happen at
all; when it does, it is because `/sw.js` itself is being held somewhere.

Check what the host says about it:

```bash
curl -sI https://your-portal-host/sw.js | grep -i cache-control
```

A long `max-age` there is the fault. Cloudflare Workers does the right thing
on its own; a plain webspace may not, which is what the `sw.js` rule in
`portal/public/.htaccess` is for. Browsers re-check the script whenever a page
is opened and cap it at 24 hours regardless, so a wrong header is a delay of
up to a day, not a portal frozen forever.

On the phone itself: closing every window of the installed app and opening it
again is enough, because a waiting worker takes over once the last one is
gone. If you need to prove what is on a particular device, Chrome's remote
debugging shows the registration and its cache; Safari's Web Inspector does
the same for an iPhone over a cable.

To stop using a service worker altogether, see
[Installing it on a phone](#installing-it-on-a-phone) — deploying one that
unregisters itself is the way, and deleting the file is not.

---

## Open questions

`NOTES.md` lists what was decided, what was assumed, and what still needs an
answer from you — including the tree question and social login. Registration
and password reset are answered there now, in §1.3 and §1.4.
