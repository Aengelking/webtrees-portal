<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;

use function bin2hex;
use function hash;
use function max;
use function min;
use function random_bytes;
use function time;
use function trim;

/**
 * Issuing, finding and burning invitations.
 *
 * The rules this class exists to keep:
 *
 *  - the raw token is returned exactly once, when the invitation is created,
 *    and is never stored;
 *  - an invitation is usable at most once, and the check-and-burn is a single
 *    conditional `UPDATE` so that two requests arriving together cannot both
 *    win;
 *  - an expired invitation is indistinguishable from one that never existed,
 *    as far as the caller is concerned — this class returns `null` for both
 *    and the handler says nothing more.
 */
class InvitationService
{
    public const string TABLE = 'portal_invitation';

    public const int DEFAULT_VALIDITY_DAYS = 14;
    public const int MAX_VALIDITY_DAYS     = 90;

    /**
     * How long a spent or expired invitation is kept before being deleted.
     *
     * Not zero, because "was this person invited, and by whom" is a question
     * an administrator asks after the fact. Not forever, because the answer
     * stops being interesting and the row names an email address.
     */
    private const int RETAIN_DAYS = 90;

    /** 32 bytes of randomness, hex encoded. */
    private const int TOKEN_BYTES = 32;

    /**
     * Issue an invitation and return its token.
     *
     * The token is the return value and is not recoverable afterwards. If the
     * administrator loses it before sending it, the answer is to revoke this
     * invitation and issue another.
     */
    public function create(
        Tree $tree,
        string $xref,
        string $invited_name,
        string $email,
        UserInterface|null $creator,
        int $valid_days = self::DEFAULT_VALIDITY_DAYS
    ): string {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $days  = min(self::MAX_VALIDITY_DAYS, max(1, $valid_days));
        $now   = time();

        DB::table(self::TABLE)->insert([
            'token_hash'   => $this->hash($token),
            'gedcom_id'    => $tree->id(),
            'xref'         => $this->nullIfEmpty($xref),
            'invited_name' => $this->nullIfEmpty($invited_name),
            'email'        => $this->nullIfEmpty($email),
            'created_by'   => $creator?->id(),
            'created_at'   => $now,
            'expires_at'   => $now + $days * 86400,
        ]);

        return $token;
    }

    /**
     * The invitation this token opens, if it is still good for anything.
     *
     * Unknown, expired, already redeemed and belonging-to-another-tree all
     * come back as `null`. The caller has nothing to tell them apart with,
     * which is the point: there is one refusal for all of them.
     */
    public function findUsable(string $token, Tree $tree): Invitation|null
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        $row = DB::table(self::TABLE)
            ->where('token_hash', '=', $this->hash($token))
            ->where('gedcom_id', '=', $tree->id())
            ->whereNull('redeemed_at')
            ->where('expires_at', '>', time())
            ->first();

        return $row === null ? null : Invitation::fromRow($row);
    }

    /**
     * Claim an invitation, returning whether this caller is the one who got it.
     *
     * A conditional update rather than a read followed by a write: two people
     * opening the same link at the same moment must not both end up with an
     * account. Whoever's `UPDATE` matches a row wins; the other is told the
     * invitation is no longer usable, which by then is true.
     */
    public function claim(Invitation $invitation): bool
    {
        $claimed = DB::table(self::TABLE)
            ->where('id', '=', $invitation->id)
            ->whereNull('redeemed_at')
            ->where('expires_at', '>', time())
            ->update(['redeemed_at' => time()]);

        return $claimed === 1;
    }

    /**
     * Undo a claim, when the account could not be created after all.
     *
     * Burning an invitation for an attempt that produced nothing would lock
     * out the very person it was sent to, over a duplicate username or a
     * mail server hiccup.
     */
    public function release(Invitation $invitation): void
    {
        DB::table(self::TABLE)
            ->where('id', '=', $invitation->id)
            ->whereNull('redeemed_user_id')
            ->update(['redeemed_at' => null]);
    }

    /** Record who redeemed it, once the account exists. */
    public function recordRedeemer(Invitation $invitation, UserInterface $user): void
    {
        DB::table(self::TABLE)
            ->where('id', '=', $invitation->id)
            ->update(['redeemed_user_id' => $user->id()]);
    }

    /**
     * Invitations that are still worth chasing: sent, unspent, unexpired.
     *
     * @return Collection<int,Invitation>
     */
    public function outstanding(Tree $tree): Collection
    {
        return DB::table(self::TABLE)
            ->where('gedcom_id', '=', $tree->id())
            ->whereNull('redeemed_at')
            ->where('expires_at', '>', time())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(static fn (object $row): Invitation => Invitation::fromRow($row));
    }

    /**
     * Withdraw an invitation. The row is deleted rather than marked: a
     * revoked invitation should leave nothing behind that could be redeemed.
     */
    public function revoke(int $id, Tree $tree): void
    {
        DB::table(self::TABLE)
            ->where('id', '=', $id)
            ->where('gedcom_id', '=', $tree->id())
            ->whereNull('redeemed_at')
            ->delete();
    }

    /** Forget invitations that were used or expired long enough ago. */
    public function prune(): void
    {
        $cutoff = time() - self::RETAIN_DAYS * 86400;

        DB::table(self::TABLE)
            ->where('expires_at', '<', $cutoff)
            ->orWhere(static function ($query) use ($cutoff): void {
                $query->whereNotNull('redeemed_at')->where('redeemed_at', '<', $cutoff);
            })
            ->delete();
    }

    /**
     * The second dimension the rate limiter counts by.
     *
     * For a login that is the username; here it is the token itself. The
     * limiter hashes whatever it is given before storing it, so its table
     * holds no usable invitation any more than `portal_invitation` does. The
     * prefix keeps the two namespaces apart, so that a member called `abc`
     * and a token spelled `abc` never share a counter.
     */
    public static function limiterKey(string $token): string
    {
        return 'invitation:' . $token;
    }

    /**
     * Tokens are compared by hash, so the table never holds a usable one.
     *
     * A plain SHA-256, not a password hash: the input is 256 bits of
     * randomness from `random_bytes()`, so there is no dictionary to run and
     * nothing for a work factor to buy. What matters is that a database dump
     * contains no credential.
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function nullIfEmpty(string $value): string|null
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
