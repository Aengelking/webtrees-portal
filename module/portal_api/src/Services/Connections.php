<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\User;

use function array_key_exists;
use function array_values;
use function bin2hex;
use function count;
use function gmdate;
use function hash;
use function max;
use function mb_strtolower;
use function mb_substr;
use function min;
use function preg_replace;
use function random_bytes;
use function rawurlencode;
use function rtrim;
use function str_replace;
use function strtoupper;
use function time;
use function trim;
use function usort;

/**
 * Two members deciding that they know each other.
 *
 * The directory says who is in the family. This says whom a member is
 * actually in touch with, which is the shorter and more useful list — and,
 * unlike the directory, it is a decision two people take together.
 *
 * **Two ways in, and they are different on purpose.**
 *
 * *A code* is for the moment the two are standing in the same room. One shows
 * it, the other's telephone camera opens the link in it, and they are
 * connected without either of them having said the other's name out loud.
 * Showing the code **is** the consent, which is why redeeming one connects
 * straight away rather than raising a request — asking somebody to confirm
 * something they are doing in front of you is a step that teaches people to
 * tap "yes" without reading. It is a credential, so it is short-lived, stored
 * only as a hash, replaceable at any moment, and it can be withdrawn.
 *
 * *A reference number* is for everybody else. It reaches only members who put
 * themselves in the directory — a member who stayed out is not findable by
 * number any more than they are findable by name (§2.7) — and it never
 * connects anybody by itself: it asks, and the other member answers. The
 * number is the one already printed under the name on the person's own screen
 * ("SB 4711"), so it is a thing a relative can read out over the telephone.
 *
 * **Nothing here is derived from the family tree, and nothing here changes
 * it.** Being connected is not being related; the tree is not consulted to
 * decide who may connect with whom, and no connection is written back to it.
 * What a connection unlocks is what its two people chose to unlock: contact
 * details with an audience of "my contacts", the ability to write to each
 * other, and a name on a list.
 *
 * **Either side may end it, at any moment, without telling anybody.** The row
 * is deleted rather than marked, so nothing keeps a record of a member having
 * said no or having changed their mind.
 */
class Connections
{
    public const string TABLE      = 'portal_connection';
    public const string CODE_TABLE = 'portal_connection_code';
    public const string LINK_TABLE = 'portal_connection_link';

    public const string STATUS_PENDING  = 'pending';
    public const string STATUS_ACCEPTED = 'accepted';

    public const string SOURCE_CODE      = 'code';
    public const string SOURCE_LINK      = 'link';
    public const string SOURCE_REFERENCE = 'reference';

    public const int DEFAULT_CODE_MINUTES = 15;
    public const int MAX_CODE_MINUTES     = 240;

    /**
     * How long a link that was *sent* lasts.
     *
     * A week, because a message sent on Tuesday is read on Thursday — where
     * the code on the screen lasts a quarter of an hour, because the person
     * it is shown to is standing there. Not a setting: two numbers that mean
     * "how long is this good for" are already one more than an administrator
     * wants to reason about, and this one has an obvious right answer.
     */
    public const int LINK_DAYS = 7;

    /**
     * How many sent links a member may have outstanding.
     *
     * Not a rate limit: it is the number above which somebody is plainly
     * posting the link somewhere rather than writing to people.
     */
    public const int MAX_OPEN_LINKS = 10;

    /**
     * How many requests a member may have waiting for an answer.
     *
     * Not a rate limit and not meant as one: it is the number above which a
     * member is plainly typing numbers rather than asking people they know.
     */
    public const int MAX_PENDING_REQUESTS = 10;

    /** 32 bytes of randomness, hex encoded — the same as an invitation. */
    private const int TOKEN_BYTES = 32;

    /** Nothing between these two, which is what a caller defaults to. */
    public const array NOWHERE = ['status' => 'none', 'id' => null];

    private const int MAX_REFERENCE_LENGTH = 40;

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly PortalTreeService $trees,
        private readonly MemberService $members,
        private readonly RecordPresenter $presenter,
        private readonly UserService $user_service,
    ) {
    }

    /**
     * Whether the family connects members to each other at all.
     *
     * Checked where a disclosure happens as well as where a connection is
     * made, so that switching it off silences what already exists — the same
     * rule contact details follow.
     */
    public function enabled(): bool
    {
        return $this->module->getPreference(PortalApiModule::SETTING_MEMBER_CONNECTIONS, '1') === '1';
    }

    public function codeMinutes(): int
    {
        return max(1, min(
            self::MAX_CODE_MINUTES,
            (int) $this->module->getPreference(PortalApiModule::SETTING_CONNECTION_CODE_MINUTES, (string) self::DEFAULT_CODE_MINUTES)
        ));
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * Everything the contacts screen needs, in one shape.
     *
     * The lists are shown even when the facility has been switched off, so
     * that a member can still see whom they are connected to and end it. What
     * being off takes away is making new connections and every disclosure
     * that hangs off an old one.
     *
     * @return array<string,mixed>
     */
    public function overview(UserInterface $user): array
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);

        $connections = [];
        $incoming    = [];
        $outgoing    = [];

        foreach ($this->rowsFor($user) as $row) {
            $presented = $this->present($row, $user, $tree, $access_level);

            if ($presented === null) {
                continue;
            }

            if ($presented['status'] === self::STATUS_ACCEPTED) {
                $connections[] = $presented;
            } elseif ((int) $row->requested_of === $user->id()) {
                $incoming[] = $presented;
            } elseif ($this->listed((int) $row->requested_of)) {
                $outgoing[] = $presented;
            }

            // An unanswered request to somebody who is not in the directory
            // is deliberately absent from this list — see
            // `requestByReference()`. It is here as a connection the moment
            // they accept it.
        }

        usort($connections, static fn (array $a, array $b): int => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));

        return [
            'enabled'     => $this->enabled(),
            'code_valid_minutes' => $this->codeMinutes(),
            'link_valid_days'    => self::LINK_DAYS,
            'connections' => $connections,
            'incoming'    => $incoming,
            'outgoing'    => $outgoing,
            // Links sent and not yet used. No name against them: the member
            // wrote to somebody themselves and the portal never learned who.
            'links'       => $this->openLinks($user)
                ->map(static fn (object $row): array => [
                    'id'         => (int) $row->id,
                    'created_at' => gmdate('c', (int) $row->created_at),
                    'expires_at' => gmdate('c', (int) $row->expires_at),
                ])
                ->all(),
        ];
    }

    /**
     * The webtrees user ids this member is connected to.
     *
     * @return array<int,int>
     */
    public function connectedUserIds(UserInterface $user): array
    {
        $ids = [];

        foreach ($this->rowsFor($user) as $row) {
            if ($row->status === self::STATUS_ACCEPTED) {
                $ids[] = (int) $row->requested_by === $user->id()
                    ? (int) $row->requested_of
                    : (int) $row->requested_by;
            }
        }

        return $ids;
    }

    /**
     * The same list, for deciding what somebody may be shown.
     *
     * Empty when the facility is off, so that one switch silences every
     * disclosure a connection carries without any caller having to remember
     * to ask. A member's own list is unaffected: seeing whom you know is not
     * a disclosure.
     *
     * @return array<int,int>
     */
    public function disclosableUserIds(UserInterface $user): array
    {
        return $this->enabled() ? $this->connectedUserIds($user) : [];
    }

    /**
     * How many people are waiting for this member to answer.
     *
     * Zero when the facility is off, because accepting would be refused and
     * a badge counting something a member cannot do is a badge that teaches
     * them to ignore badges. The requests themselves are left alone.
     */
    public function pendingCount(UserInterface $user): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        return DB::table(self::TABLE)
            ->where('requested_of', '=', $user->id())
            ->where('status', '=', self::STATUS_PENDING)
            ->count();
    }

    // -----------------------------------------------------------------
    // Making a connection
    // -----------------------------------------------------------------

    /**
     * Redeem a code somebody showed us.
     *
     * Connects at once, in both directions. A code that is unknown, expired
     * or withdrawn gets one answer, exactly as an invitation does: there is
     * one thing the reader can do about any of them, which is to ask for the
     * code to be shown again.
     *
     * @return array<string,mixed>
     */
    public function connectWithCode(UserInterface $user, string $code): array
    {
        $this->refuseIfDisabled();

        $hash = $this->hash(trim($code));

        // One screen for two kinds of token, because the person who followed
        // the link has no idea which they were given and no reason to care.
        $row  = DB::table(self::CODE_TABLE)
            ->where('token_hash', '=', $hash)
            ->where('expires_at', '>', time())
            ->first();

        $sent = $row === null
            ? DB::table(self::LINK_TABLE)
                ->where('token_hash', '=', $hash)
                ->whereNull('redeemed_at')
                ->where('expires_at', '>', time())
                ->first()
            : null;

        if ($row === null && $sent === null) {
            throw $this->expiredCode();
        }

        $owner = $this->user_service->find((int) ($row->wt_user_id ?? $sent->wt_user_id));

        if (!$owner instanceof User) {
            throw $this->expiredCode();
        }

        if ($owner->id() === $user->id()) {
            throw ApiException::badRequest(I18N::translate('That is your own connection link.'));
        }

        // A sent link works once. Claimed with a conditional `UPDATE` rather
        // than a read and a write, for the same reason an invitation is: two
        // people opening one forwarded link at the same moment must not both
        // get through it.
        if ($sent !== null) {
            $claimed = DB::table(self::LINK_TABLE)
                ->where('id', '=', $sent->id)
                ->whereNull('redeemed_at')
                ->where('expires_at', '>', time())
                ->update(['redeemed_at' => time(), 'redeemed_by' => $user->id()]);

            if ($claimed !== 1) {
                throw $this->expiredCode();
            }
        }

        $status = $this->link($user, $owner, $sent === null ? self::SOURCE_CODE : self::SOURCE_LINK, true);

        return $this->result($user, $status, $this->nameOf($owner));
    }

    /**
     * Unknown, expired and already spent, in one sentence.
     *
     * The three are not told apart on purpose — there is one thing the reader
     * can usefully do about any of them, which is to ask for another.
     */
    private function expiredCode(): ApiException
    {
        return new ApiException(
            'invalid_token',
            StatusCodeInterface::STATUS_BAD_REQUEST,
            I18N::translate('This connection link is no longer valid — it may have expired, or it may already have been used. Please ask for a new one.')
        );
    }

    /**
     * Ask the member who carries this reference number to connect.
     *
     * Only members listed in the directory can be found this way, and only
     * numbers on a record the asking member may see. Both follow from rules
     * that are already there — listing oneself is what makes a member
     * findable at all, and a `RESN` on a `REFN` hides it — and together they
     * mean this cannot become a way to discover who has an account.
     *
     * @return array<string,mixed>
     */
    public function requestByReference(UserInterface $user, string $reference): array
    {
        $this->refuseIfDisabled();

        $reference = mb_substr(trim($reference), 0, self::MAX_REFERENCE_LENGTH);

        if ($reference === '') {
            throw ApiException::badRequest(I18N::translate('Please enter the SB number of the person you want to connect with.'));
        }

        $other = $this->memberByReference($reference);

        if ($other instanceof User && $other->id() === $user->id()) {
            throw ApiException::badRequest(I18N::translate('That is your own SB number.'));
        }

        // A member who is listed in the directory is answered by name: they
        // published it, and being asked by somebody who read their number is
        // no more than the directory already invites.
        if ($other instanceof User && $this->listed($other->id())) {
            $status = $this->link($user, $other, self::SOURCE_REFERENCE, false);

            return $this->result($user, $status, $this->nameOf($other));
        }

        // Everybody else gets one answer, and it is the same answer as for a
        // number nobody carries.
        //
        // This is what lets a member who stayed *out* of the directory be
        // reached at all: the request is made, they decide, and until they
        // accept it the person who asked learns nothing — not a name, not
        // that the number belongs to anybody. An answer that said "sent to
        // Karla" would turn the number search into a way of asking which
        // relatives have an account, which is exactly what staying out of the
        // directory is a decision against.
        //
        // The unanswered request is therefore not in the member's own list
        // either: a row that appeared only for real numbers would say the
        // same thing more quietly. It appears when it is accepted.
        if ($other instanceof User) {
            $this->link($user, $other, self::SOURCE_REFERENCE, false);
        }

        return $this->result($user, self::STATUS_PENDING, null);
    }

    /** Whether this account chose to appear in the member directory. */
    private function listed(int $wt_user_id): bool
    {
        return DB::table(MemberService::TABLE)
            ->where('wt_user_id', '=', $wt_user_id)
            ->where('visible_in_directory', '=', 1)
            ->exists();
    }

    /**
     * Ask a member of the directory to connect, from their own page.
     *
     * The same request as by reference number, reached the short way: the
     * member is already on the screen, so there is nothing to type and
     * nothing to mistype. Only directory members have a page to be asked
     * from, which is the same rule the number search follows.
     *
     * @return array<string,mixed>
     */
    public function requestByMember(UserInterface $user, int $member_id): array
    {
        $this->refuseIfDisabled();

        $member = $this->members->visibleMember($member_id);

        if (!$member instanceof Member) {
            throw ApiException::notFound();
        }

        if ($member->user->id() === $user->id()) {
            throw ApiException::badRequest(I18N::translate('You cannot connect with yourself.'));
        }

        $status = $this->link($user, $member->user, self::SOURCE_REFERENCE, false);

        return $this->result($user, $status, $member->display_name);
    }

    /**
     * Where these two stand, for a screen that has to offer the right button.
     *
     * @return array{status:string,id:int|null}
     */
    public function stateWith(UserInterface $user, UserInterface $other): array
    {
        $row = DB::table(self::TABLE)
            ->where(static function ($query) use ($user, $other): void {
                $query->where('requested_by', '=', $user->id())->where('requested_of', '=', $other->id());
            })
            ->orWhere(static function ($query) use ($user, $other): void {
                $query->where('requested_by', '=', $other->id())->where('requested_of', '=', $user->id());
            })
            ->first();

        return $row === null ? self::NOWHERE : $this->state($row, $user);
    }

    /**
     * The same answer for everybody at once, keyed by webtrees user id.
     *
     * For the directory, which has to decide what to offer on every row and
     * must not ask once per row to find out. One query for the whole page —
     * and for the whole table, which is a member's own handful of rows.
     * Anybody not in the result is `none`, which is what the caller should
     * default to rather than asking again.
     *
     * @return array<int,array{status:string,id:int|null}>
     */
    public function statesFor(UserInterface $user): array
    {
        $states = [];

        foreach ($this->rowsFor($user) as $row) {
            $other = (int) $row->requested_by === $user->id() ? (int) $row->requested_of : (int) $row->requested_by;

            $states[$other] = $this->state($row, $user);
        }

        return $states;
    }

    /**
     * @return array{status:string,id:int|null}
     */
    private function state(object $row, UserInterface $user): array
    {
        if ($row->status === self::STATUS_ACCEPTED) {
            return ['status' => 'connected', 'id' => (int) $row->id];
        }

        return [
            'status' => (int) $row->requested_by === $user->id() ? 'requested' : 'incoming',
            'id'     => (int) $row->id,
        ];
    }

    /**
     * Answer a request that was made to me.
     *
     * @return array<string,mixed>
     */
    public function accept(UserInterface $user, int $id): array
    {
        $this->refuseIfDisabled();

        $row = DB::table(self::TABLE)
            ->where('id', '=', $id)
            ->where('requested_of', '=', $user->id())
            ->where('status', '=', self::STATUS_PENDING)
            ->first();

        // Somebody else's request, one already answered, and one that never
        // existed are the same answer. A member has no business learning
        // which.
        if ($row === null) {
            throw ApiException::notFound();
        }

        $other = $this->user_service->find((int) $row->requested_by);

        if (!$other instanceof User) {
            throw ApiException::notFound();
        }

        $this->markAccepted((int) $row->id, $user, $other);

        return $this->result($user, self::STATUS_ACCEPTED, $this->nameOf($other));
    }

    /**
     * End it, whichever end I am holding.
     *
     * Declining a request, withdrawing one I sent and disconnecting from
     * somebody I know are one operation, because they are one act: this row
     * should not exist any more. Nothing is kept, so nobody can later read
     * off who refused whom.
     *
     * @return array<string,mixed>
     */
    public function remove(UserInterface $user, int $id): array
    {
        $deleted = DB::table(self::TABLE)
            ->where('id', '=', $id)
            ->where(static function ($query) use ($user): void {
                $query->where('requested_by', '=', $user->id())
                    ->orWhere('requested_of', '=', $user->id());
            })
            ->delete();

        if ($deleted === 0) {
            throw ApiException::notFound();
        }

        return $this->overview($user);
    }

    // -----------------------------------------------------------------
    // The member's own code
    // -----------------------------------------------------------------

    /**
     * Issue a code for this member to show, replacing any earlier one.
     *
     * The code itself is returned here and nowhere else — the table holds
     * only a hash — so the screen showing it is the only place it exists.
     * Asking for another invalidates the one before it, which is what makes
     * "somebody photographed my screen" a thing a member can undo.
     *
     * @return array<string,mixed>
     */
    public function issueCode(UserInterface $user): array
    {
        $this->refuseIfDisabled();

        // The code travels as a link inside the QR code, so without the
        // portal's address there is nothing to encode.
        $base = $this->portalAddress();

        $token   = bin2hex(random_bytes(self::TOKEN_BYTES));
        $now     = time();
        $expires = $now + $this->codeMinutes() * 60;

        DB::table(self::CODE_TABLE)->where('wt_user_id', '=', $user->id())->delete();

        DB::table(self::CODE_TABLE)->insert([
            'wt_user_id' => $user->id(),
            'token_hash' => $this->hash($token),
            'created_at' => $now,
            'expires_at' => $expires,
        ]);

        $this->pruneCodes();

        return [
            'url'        => $base . '/connect?code=' . rawurlencode($token),
            'expires_at' => gmdate('c', $expires),
            'valid_minutes' => $this->codeMinutes(),
        ];
    }

    /**
     * A link to send to somebody who is not in the room.
     *
     * The same handshake as the code on the screen and the same consequence —
     * whoever follows it and taps is connected, no confirmation asked — with
     * two differences that follow from travelling through somebody else's
     * inbox: it lasts a week, and it works once. A link that has been
     * forwarded, quoted in a reply or left in an old chat is a link that has
     * already been spent.
     *
     * The member sends it themselves, exactly as they send an invitation
     * (§2.24). Having the module mail it would not be safer — they still type
     * the address — and it would put a mail server between two people who
     * already have each other's telephone number.
     *
     * @return array<string,mixed>
     */
    public function issueLink(UserInterface $user): array
    {
        $this->refuseIfDisabled();

        $base = $this->portalAddress();

        if ($this->openLinks($user)->count() >= self::MAX_OPEN_LINKS) {
            throw new ApiException(
                'quota_reached',
                StatusCodeInterface::STATUS_CONFLICT,
                I18N::translate('You have as many unused links outstanding as you may have at once. Withdraw one, or wait until it is used.')
            );
        }

        $token   = bin2hex(random_bytes(self::TOKEN_BYTES));
        $now     = time();
        $expires = $now + self::LINK_DAYS * 86400;

        DB::table(self::LINK_TABLE)->insert([
            'wt_user_id' => $user->id(),
            'token_hash' => $this->hash($token),
            'created_at' => $now,
            'expires_at' => $expires,
        ]);

        $this->pruneLinks();

        return [
            // Shown once. The table holds a hash, so nothing can hand it out
            // a second time — losing it means withdrawing this one and
            // making another.
            'url'        => $base . '/connect?code=' . rawurlencode($token),
            'expires_at' => gmdate('c', $expires),
            'valid_days' => self::LINK_DAYS,
        ];
    }

    /**
     * Withdraw a link that was sent and not used.
     *
     * Deleted rather than marked spent: a withdrawn link should leave nothing
     * behind that anybody could redeem. A link already used is untouched —
     * the connection it made is a thing between two people now, and ending
     * that is `remove()`.
     *
     * @return array<string,mixed>
     */
    public function revokeLink(UserInterface $user, int $id): array
    {
        $deleted = DB::table(self::LINK_TABLE)
            ->where('id', '=', $id)
            ->where('wt_user_id', '=', $user->id())
            ->whereNull('redeemed_at')
            ->delete();

        if ($deleted === 0) {
            throw ApiException::notFound();
        }

        return $this->overview($user);
    }

    /**
     * The links this member sent and nobody has used yet.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    private function openLinks(UserInterface $user)
    {
        return DB::table(self::LINK_TABLE)
            ->where('wt_user_id', '=', $user->id())
            ->whereNull('redeemed_at')
            ->where('expires_at', '>', time())
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The portal's own address, without which no link can be built.
     *
     * Refused rather than handed over broken: a member cannot tell a link
     * nobody can follow from one that works until the person they sent it to
     * says so.
     */
    private function portalAddress(): string
    {
        $base = rtrim(trim($this->module->getPreference(PortalApiModule::SETTING_PORTAL_URL, '')), '/');

        if ($base === '') {
            throw ApiException::notConfigured(
                I18N::translate('The member portal is not configured correctly. Please contact an administrator.')
            );
        }

        return $base;
    }

    /** Spent and expired links are of no use to anybody after a while. */
    private function pruneLinks(): void
    {
        DB::table(self::LINK_TABLE)->where('expires_at', '<', time() - 30 * 86400)->delete();
    }

    /** Make the code on the screen stop working, now rather than in a quarter of an hour. */
    public function revokeCode(UserInterface $user): void
    {
        DB::table(self::CODE_TABLE)->where('wt_user_id', '=', $user->id())->delete();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Create the connection, or move an existing row towards being one.
     *
     * @return string The status the caller ended up with.
     */
    private function link(UserInterface $user, User $other, string $source, bool $accept_at_once): string
    {
        $existing = DB::table(self::TABLE)
            ->where(static function ($query) use ($user, $other): void {
                $query->where('requested_by', '=', $user->id())->where('requested_of', '=', $other->id());
            })
            ->orWhere(static function ($query) use ($user, $other): void {
                $query->where('requested_by', '=', $other->id())->where('requested_of', '=', $user->id());
            })
            ->first();

        if ($existing !== null) {
            if ($existing->status === self::STATUS_ACCEPTED) {
                // Scanning the same code twice at the same gathering is not
                // an error, and saying so would only be a way of telling
                // somebody off for being careful.
                return self::STATUS_ACCEPTED;
            }

            // A request that crosses one coming the other way is the answer
            // to it: both people have now asked, and there is nothing left to
            // confirm.
            if ($accept_at_once || (int) $existing->requested_of === $user->id()) {
                $this->markAccepted((int) $existing->id, $user, $other);

                return self::STATUS_ACCEPTED;
            }

            return self::STATUS_PENDING;
        }

        if (!$accept_at_once && $this->pendingRequestsBy($user) >= self::MAX_PENDING_REQUESTS) {
            throw new ApiException(
                'quota_reached',
                StatusCodeInterface::STATUS_CONFLICT,
                I18N::translate('You have as many unanswered requests as you may have at once — including any that do not appear in your list. Wait until one of them is answered.')
            );
        }

        $now = time();

        DB::table(self::TABLE)->insert([
            'requested_by' => $user->id(),
            'requested_of' => $other->id(),
            'status'       => $accept_at_once ? self::STATUS_ACCEPTED : self::STATUS_PENDING,
            'source'       => $source,
            'created_at'   => $now,
            'decided_at'   => $accept_at_once ? $now : null,
        ]);

        if ($accept_at_once) {
            $this->ensureProfiles($user, $other);
        }

        return $accept_at_once ? self::STATUS_ACCEPTED : self::STATUS_PENDING;
    }

    private function markAccepted(int $id, UserInterface $user, User $other): void
    {
        DB::table(self::TABLE)
            ->where('id', '=', $id)
            ->update(['status' => self::STATUS_ACCEPTED, 'decided_at' => time()]);

        // Two people asking each other at the same moment can leave a second
        // row behind. One connection is one row, so the loser goes.
        DB::table(self::TABLE)
            ->where('id', '!=', $id)
            ->where(static function ($query) use ($user, $other): void {
                $query->where('requested_by', '=', $other->id())->where('requested_of', '=', $user->id());
            })
            ->delete();

        $this->ensureProfiles($user, $other);
    }

    /**
     * Both sides need a portal member id, because both are now somebody the
     * other can open a page for. The row it creates says nothing and shows
     * nobody anything: `visible_in_directory` stays off, which is the
     * default and the narrower answer.
     */
    private function ensureProfiles(UserInterface $user, UserInterface $other): void
    {
        $this->members->ensureProfile($user);
        $this->members->ensureProfile($other);
    }

    private function pendingRequestsBy(UserInterface $user): int
    {
        return DB::table(self::TABLE)
            ->where('requested_by', '=', $user->id())
            ->where('status', '=', self::STATUS_PENDING)
            ->count();
    }

    /**
     * @return array<int,object>
     */
    private function rowsFor(UserInterface $user): array
    {
        $rows = DB::table(self::TABLE)
            ->where(static function ($query) use ($user): void {
                $query->where('requested_by', '=', $user->id())
                    ->orWhere('requested_of', '=', $user->id());
            })
            ->orderBy('created_at')
            ->get()
            ->all();

        // One counterpart, one row. A crossed pair that survived a race is
        // shown as the connection it is, not as a connection plus a request
        // to somebody the member is already connected to.
        $best = [];

        foreach ($rows as $row) {
            $other = (int) $row->requested_by === $user->id() ? (int) $row->requested_of : (int) $row->requested_by;

            if (!array_key_exists($other, $best) || $row->status === self::STATUS_ACCEPTED) {
                $best[$other] = $row;
            }
        }

        return array_values($best);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function present(object $row, UserInterface $user, Tree $tree, int $access_level): array|null
    {
        $other_id = (int) $row->requested_by === $user->id() ? (int) $row->requested_of : (int) $row->requested_by;
        $other    = $this->user_service->find($other_id);

        // The account is gone. The foreign key deletes the row with it, so
        // this is only reachable in the moment between the two.
        if (!$other instanceof User) {
            return null;
        }

        $individual = $this->trees->linkedIndividual($tree, $other);
        $profile    = $this->members->profileForUser($other);
        $override   = $profile['display_name_override'] ?? null;

        return [
            'id'     => (int) $row->id,
            'status' => (string) $row->status,
            'source' => (string) $row->source,
            // Who asked. Not the same as who may end it — either of them may.
            'requested_by_me' => (int) $row->requested_by === $user->id(),
            // The portal member id, so the contact list can link to the page
            // that shows what this person shares. Null only for the moment
            // before a request is answered, when there may be no profile row
            // yet — and there is nothing to link to until then anyway.
            'member_id' => $profile === null ? null : $profile['id'],
            'name'      => $override === null || $override === '' ? $other->realName() : (string) $override,
            'individual' => $individual instanceof Individual
                ? $this->presenter->individualRef($individual, $access_level)
                : null,
            'since' => gmdate('c', (int) ($row->decided_at ?? $row->created_at)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function result(UserInterface $user, string $status, string|null $name): array
    {
        return [
            'status' => $status === self::STATUS_ACCEPTED ? 'connected' : 'requested',
            'name'   => $name,
        ] + $this->overview($user);
    }

    /**
     * The account carrying this reference number.
     *
     * Walks the accounts rather than the tree — everybody this portal knows
     * that the tree has a record for, listed in the directory or not. Who may
     * be *told* about the answer is decided by the caller; this only finds
     * it. Small by construction, and only walked when somebody actually types
     * a number.
     */
    private function memberByReference(string $reference): User|null
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);
        $wanted       = $this->normalise($reference);

        if ($wanted === '') {
            return null;
        }

        $relaxed = [];

        foreach ($this->members->linkedAccounts($tree) as $account) {
            $individual = $this->trees->linkedIndividual($tree, $account);

            if (!$individual instanceof Individual) {
                continue;
            }

            // Read at `PRIV_HIDE`, on purpose, and then filter each number on
            // its own restriction.
            //
            // `GedcomRecord::facts()` hands back *nothing at all* for a record
            // the reader may not see, so reading at the member's own level
            // silently skipped everybody outside their view of the tree — and
            // a family that set a relationship limit (§2.25) therefore had a
            // number search that found nobody, while the directory listed
            // those same people by name a screen away.
            //
            // Nothing is disclosed by finding them here that the directory
            // does not already publish: the person exists, they are listed,
            // and this is their name. The number itself came off a letterhead
            // rather than out of the tree. What the record *does* still decide
            // is a number it marks confidential — `Fact::canShow()` checks the
            // `RESN` on the fact rather than the privacy of the record around
            // it, which is exactly the half that belongs here.
            foreach ($individual->facts(['REFN'], false, Auth::PRIV_HIDE) as $fact) {
                if (!$fact->canShow($access_level)) {
                    continue;
                }

                if ($this->matches($fact, $wanted, false)) {
                    return $account;
                }

                if ($this->matches($fact, $wanted, true)) {
                    $relaxed[] = $account;
                }
            }
        }

        // Nothing matched as typed. A number typed without its slash is still
        // worth finding — somebody reading one off a letterhead should not
        // have to know that the portal cares — but only while there is
        // nothing to confuse it with. `10/1335.21` and `101/335.21` are one
        // string once the slash is gone, and guessing between two relatives
        // is worse than saying nothing was found.
        return count($relaxed) === 1 ? $relaxed[0] : null;
    }

    /**
     * "SB 4711", "sb4711" and "4711" are the same number typed by three
     * people. Punctuation goes; **the slash stays.**
     *
     * The family's numbers are `10/1335.21` — a branch, then the number
     * within it. Dropping the slash with the rest of the punctuation makes
     * `10/1335.21` and `101/335.21` the same string, which is two different
     * relatives wearing one number. Keeping it as a separator costs nothing
     * and makes that impossible.
     *
     * A number typed without the slash is still accepted, but only on the
     * second pass and only while it picks out exactly one person — see
     * `memberByReference()`.
     *
     * The third case is the one this got wrong at first, and it is the common
     * one: **the record often carries no `TYPE` at all.** GEDCOM does not
     * require one, the family's own numbering is called "SB" whether or not
     * anybody wrote that into the file, and the module that shows the number
     * as a badge in webtrees supplies the prefix itself. So a member reads
     * "SB 4711" off a letterhead, types it, and a strict comparison answers
     * that nobody carries it — while "4711" alone finds them. Telling
     * somebody the prefix their own family uses is wrong would be pedantry
     * with nothing behind it.
     *
     * So a typed prefix is allowed to fall away, but **only where the record
     * does not disagree**. A record that says `TYPE Intern` is a different
     * numbering, and "SB 9999" must not find it.
     */
    private function matches(Fact $fact, string $wanted, bool $relaxed): bool
    {
        $number = $this->normalise($fact->value());

        if ($number === '') {
            return false;
        }

        $type = $this->normalise($fact->attribute('TYPE'));

        if ($relaxed) {
            $wanted = $this->flatten($wanted);
            $number = $this->flatten($number);
        }

        return $this->same($wanted, $number, $type);
    }

    /**
     * One typed form against one stored form, with the type in hand.
     */
    private function same(string $wanted, string $number, string $type): bool
    {
        if ($wanted === $number || $wanted === $type . $number) {
            return true;
        }

        // The record says nothing about which numbering its number belongs
        // to, and the member typed the one the family uses out loud. Only
        // where the record does not disagree: `TYPE Intern` is a different
        // numbering and keeps its numbers to itself.
        if ($type !== '') {
            return false;
        }

        $without_prefix = (string) preg_replace('/^[A-Z]+/', '', $wanted);

        return $without_prefix !== '' && $without_prefix === $number;
    }

    /** The same number with the branch separator gone, for the second pass. */
    private function flatten(string $value): string
    {
        return str_replace('/', '', $value);
    }

    /**
     * Everything but letters, digits and the branch separator goes, so that
     * "10/1335.21", "10 / 1335,21" and "10/133521" are one number.
     */
    private function normalise(string $value): string
    {
        return strtoupper((string) preg_replace('#[^A-Za-z0-9/]#', '', $value));
    }

    private function nameOf(User $user): string
    {
        $profile = $this->members->profileForUser($user);
        $name    = $profile['display_name_override'] ?? null;

        return $name === null || $name === '' ? $user->realName() : (string) $name;
    }

    private function refuseIfDisabled(): void
    {
        if (!$this->enabled()) {
            throw new ApiException(
                'not_allowed',
                StatusCodeInterface::STATUS_FORBIDDEN,
                I18N::translate('Members cannot connect with each other in this family tree.')
            );
        }
    }

    /** Codes that expired long enough ago are of no use to anybody. */
    private function pruneCodes(): void
    {
        DB::table(self::CODE_TABLE)->where('expires_at', '<', time() - 86400)->delete();
    }

    /**
     * Hashed like an invitation token, and for the same reason: the input is
     * 256 bits from `random_bytes()`, so a work factor would buy nothing, and
     * what matters is that a database dump holds no usable code.
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
