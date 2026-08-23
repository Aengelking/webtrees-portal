<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\User;
use stdClass;

use function bin2hex;
use function count;
use function ctype_xdigit;
use function explode;
use function hash;
use function hash_equals;
use function max;
use function min;
use function random_bytes;
use function strlen;
use function time;

/**
 * "Angemeldet bleiben" — the devices a member told the portal to remember.
 *
 * The session cookie webtrees sets dies with the browser, which is right for
 * an administrator at a desk and wrong for the member this portal is for: a
 * telephone, opened for two minutes every few weeks, and a password that has
 * to be found again each time. This class holds the second credential that
 * lets that member back in without one.
 *
 * The whole of it is three rules.
 *
 * **The cookie is two halves, `series:token`.** The series names the device
 * and outlives every request; the token is spent on use and replaced. Neither
 * is stored — only SHA-256 of the token, and of the one before it.
 *
 * **Using a spent token is theft, and is answered by distrusting the lot.** If
 * a series is presented with a token older than the grace period, then two
 * parties hold cookies for one device and only one of them is entitled to. It
 * cannot be told which, so every remembered device belonging to that member is
 * forgotten and everybody signs in again. That is the trade this design
 * exists to make: a stolen cookie is *noticed*, at the price of an occasional
 * unexplained sign-in.
 *
 * **A token is authority to resume a session and nothing else.** It says which
 * `user_id` to look up. Whether that account still exists, is still approved
 * and may still see anything is asked again through webtrees' own `Auth`, on
 * this request as on every other.
 */
class RememberedDevices
{
    public const string TABLE = 'portal_remember_token';

    /** The cookie's name. Deliberately not the shape of a webtrees cookie. */
    public const string COOKIE = 'PORTAL_REMEMBER';

    /**
     * Off by default.
     *
     * A member who is never asked cannot be surprised, and this is a portal
     * about living people: an administrator turns it on, having decided that
     * their family's telephones are their own. `0` means the checkbox is not
     * offered at all, which is different from it being offered and ignored.
     */
    public const int DEFAULT_DAYS = 0;

    /** Thirty days is the offer if the administrator does not say otherwise. */
    public const int SUGGESTED_DAYS = 30;

    public const int MAX_DAYS = 365;

    /** 32 bytes each, hex encoded. */
    private const int SERIES_BYTES = 16;
    private const int TOKEN_BYTES  = 32;

    /**
     * How long the token one step back stays usable after being replaced.
     *
     * Long enough to cover two requests that left the same telephone together
     * and arrived apart; far too short to be worth stealing.
     */
    private const int GRACE_SECONDS = 60;

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly UserService $user_service,
    ) {
    }

    /**
     * How many days the family allows, or zero for "do not offer this".
     *
     * The login screen reads this before anybody signs in — it is the only
     * way for a screen to know whether to draw the switch — which is why it
     * is a number of days and not a boolean: the same value that decides
     * whether to offer it is the one the offer has to state.
     */
    public function days(): int
    {
        $days = (int) $this->module->getPreference(
            PortalApiModule::SETTING_REMEMBER_DAYS,
            (string) self::DEFAULT_DAYS
        );

        return $days <= 0 ? 0 : min(self::MAX_DAYS, $days);
    }

    public function available(): bool
    {
        return $this->days() > 0;
    }

    /**
     * Remember this device, and return the cookie value to hand back.
     *
     * Null when the family does not allow it — the caller then sets no cookie,
     * rather than setting one that will never be honoured.
     */
    public function remember(UserInterface $user): string|null
    {
        $days = $this->days();

        if ($days === 0) {
            return null;
        }

        $series = bin2hex(random_bytes(self::SERIES_BYTES));
        $token  = bin2hex(random_bytes(self::TOKEN_BYTES));
        $now    = time();

        DB::table(self::TABLE)->insert([
            'wt_user_id'    => $user->id(),
            'series'        => $series,
            'token_hash'    => $this->hash($token),
            'previous_hash' => null,
            'rotated_at'    => null,
            'created_at'    => $now,
            'expires_at'    => $now + $days * 86400,
            'used_at'       => $now,
        ]);

        return $series . ':' . $token;
    }

    /**
     * Who this cookie is, and the cookie value that replaces it.
     *
     * Returns null for everything that is not a live, unspent token on a live
     * account: nonsense, unknown, expired, spent, or belonging to somebody who
     * has since been deleted. The caller has no way to tell those apart and
     * nothing to say about them — a member whose cookie does not work sees the
     * login screen, which is what they would have seen anyway.
     */
    public function resume(string $cookie): RememberedDevice|null
    {
        if (!$this->available()) {
            return null;
        }

        [$series, $token] = $this->split($cookie);

        if ($series === null || $token === null) {
            return null;
        }

        $row = DB::table(self::TABLE)->where('series', '=', $series)->first();

        if ($row === null) {
            return null;
        }

        if ((int) $row->expires_at <= time()) {
            DB::table(self::TABLE)->where('id', '=', (int) $row->id)->delete();

            return null;
        }

        $offered = $this->hash($token);

        if (!$this->matches($row, $offered)) {
            // Somebody is holding a cookie for this device that is no longer
            // current. Either it was copied, or the copy is the one being used
            // now — there is no telling which of the two is the member.
            $this->betrayed((int) $row->wt_user_id, $series);

            return null;
        }

        $user = $this->user_service->find((int) $row->wt_user_id);

        if ($user === null) {
            DB::table(self::TABLE)->where('id', '=', (int) $row->id)->delete();

            return null;
        }

        return new RememberedDevice($user, $this->rotate((int) $row->id, $series, $offered));
    }

    /**
     * Forget one device — the one signing out.
     *
     * Silent when there was nothing to forget: the member wanted it gone, and
     * whether it was there is not something the answer should turn on.
     */
    public function forget(string $cookie): void
    {
        [$series] = $this->split($cookie);

        if ($series !== null) {
            DB::table(self::TABLE)->where('series', '=', $series)->delete();
        }
    }

    /** Forget every device this member has. Used when a password changes. */
    public function forgetAll(int $user_id): void
    {
        DB::table(self::TABLE)->where('wt_user_id', '=', $user_id)->delete();
    }

    /** Delete what has expired. Cheap, and called where a write already is. */
    public function prune(): void
    {
        DB::table(self::TABLE)->where('expires_at', '<', time())->delete();
    }

    /**
     * Whether the token offered is the current one, or the one just before it.
     *
     * `hash_equals` rather than `===` throughout: these are the comparisons an
     * attacker gets to make repeatedly, and a length-dependent one leaks.
     */
    private function matches(stdClass $row, string $offered): bool
    {
        if (hash_equals((string) $row->token_hash, $offered)) {
            return true;
        }

        $previous = $row->previous_hash;
        $rotated  = (int) ($row->rotated_at ?? 0);

        return $previous !== null
            && $rotated + self::GRACE_SECONDS > time()
            && hash_equals((string) $previous, $offered);
    }

    /** Spend the token, issue the next one, and return the new cookie value. */
    private function rotate(int $id, string $series, string $spent): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        DB::table(self::TABLE)->where('id', '=', $id)->update([
            'token_hash'    => $this->hash($token),
            'previous_hash' => $spent,
            'rotated_at'    => time(),
            'used_at'       => time(),
        ]);

        return $series . ':' . $token;
    }

    /**
     * Two cookies exist for one device. Trust neither, and say so where an
     * administrator will see it.
     *
     * Every series belonging to the member goes, not only this one: a cookie
     * that was copied off a device was probably not the only thing that was.
     */
    private function betrayed(int $user_id, string $series): void
    {
        $this->forgetAll($user_id);

        $user = $this->user_service->find($user_id);
        $who  = $user instanceof User ? $user->userName() : ('user ' . $user_id);

        Log::addAuthenticationLog(
            'Portal: a remembered device was presented with a spent token and every remembered '
            . 'device for ' . $who . ' has been forgotten (series ' . $series . ')'
        );
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function split(string $cookie): array
    {
        $parts = explode(':', $cookie, 2);

        if (count($parts) !== 2) {
            return [null, null];
        }

        [$series, $token] = $parts;

        // Both halves are hex of a known length. Anything else never reached
        // the database in the first place and should not now.
        $ok = strlen($series) === self::SERIES_BYTES * 2
            && strlen($token) === self::TOKEN_BYTES * 2
            && ctype_xdigit($series)
            && ctype_xdigit($token);

        return $ok ? [$series, $token] : [null, null];
    }

    /**
     * Plain SHA-256, as everywhere else here. The input is 256 bits from
     * `random_bytes()`, so there is no dictionary to run and a work factor
     * would only slow down the member.
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** How long a cookie set now should live, in seconds. */
    public function lifetime(): int
    {
        return max(0, $this->days()) * 86400;
    }
}
