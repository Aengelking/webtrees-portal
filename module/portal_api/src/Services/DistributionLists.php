<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Throwable;

use function array_key_exists;
use function array_map;
use function array_slice;
use function error_log;
use function explode;
use function hash;
use function is_bool;
use function mb_strtolower;
use function mb_substr;
use function preg_split;
use function str_contains;
use function str_starts_with;
use function time;
use function trim;

/**
 * The family's round-robin letters, joined and left from the portal.
 *
 * Three lists live in the family's Exchange tenant. Before this, being on one
 * meant asking whoever administers it, and coming off one meant asking the
 * same person and hoping — which is the arrangement that makes people stop
 * reading rather than unsubscribe. A list somebody cannot leave on their own
 * is a list they will eventually mark as spam.
 *
 * **This portal holds the wish; Exchange holds the list.** The member's
 * decision is recorded here first and applied second, and the two are allowed
 * to disagree for a while. That is not a compromise, it is the only honest
 * arrangement: Exchange is somebody else's service, reached over somebody
 * else's network, and a member pressing a switch is entitled to have their
 * answer kept whether or not Redmond is available this second. What the switch
 * promises is that the decision has been taken down, and the screen says so in
 * those terms when it has not yet gone through.
 *
 * **The address is the account's.** Not the one a member publishes under
 * *contact details* — that one is a choice about what the family may see, has
 * an audience attached, and may be absent. The account address is the one that
 * was verified when the invitation was accepted and the one a password reset
 * goes to, so it is the one the family's post goes to as well. Changing it
 * moves the subscriptions with it; see `outstanding()`.
 *
 * **Which lists exist is an administrator's setting**, one per line, and a
 * member is never shown the addresses — see `Schema/Migration11.php` for why
 * a list is identified by a hash of its address.
 */
class DistributionLists
{
    /**
     * How many times an outstanding change is retried before it waits for a
     * person. Small on purpose: past the third refusal something is wrong that
     * trying again will not mend.
     */
    public const int MAX_ATTEMPTS = 3;

    /** Seconds between retries, so that an Exchange outage is not felt as a slow portal. */
    private const int RETRY_AFTER = 600;

    /** At most one outstanding row per request. The rest wait for the next one. */
    private const int PER_REQUEST = 1;

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly ExchangeOnline $exchange,
    ) {
    }

    /**
     * Whether a member can be offered this at all.
     *
     * Three things: the family has not switched it off, the tenant is
     * configured, and somebody has named at least one list. A screen offering
     * to subscribe to nothing would be worse than no screen.
     */
    public function enabled(): bool
    {
        return $this->module->getPreference(PortalApiModule::SETTING_MAILING_LISTS, '0') === '1'
            && $this->exchange->configured()
            && $this->configured() !== [];
    }

    /**
     * The lists an administrator has named.
     *
     * One per line, `address | name | description`, with the last two
     * optional. Blank lines and lines beginning with `#` are ignored, so the
     * setting can be annotated by whoever maintains it.
     *
     * @return array<string,array{address:string,name:string,description:string}>
     *         keyed by the hash the portal knows a list by
     */
    public function configured(): array
    {
        $lists = [];
        $lines = preg_split('/\R/', $this->module->getPreference(PortalApiModule::SETTING_MAILING_LIST_ADDRESSES, ''));

        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts   = array_map(trim(...), explode('|', $line));
            $address = mb_strtolower($parts[0]);

            // Not validation for its own sake: an entry that is not an address
            // cannot be a list, and silently keeping it would put a row on a
            // member's screen that can never be applied.
            if (!str_contains($address, '@')) {
                continue;
            }

            $name = ($parts[1] ?? '') !== '' ? $parts[1] : explode('@', $address)[0];

            $lists[self::hash($address)] = [
                'address'     => $address,
                'name'        => $name,
                'description' => $parts[2] ?? '',
            ];
        }

        return $lists;
    }

    /**
     * What this member is on, and what is still on its way there.
     *
     * Reading the screen is also when an outstanding change gets another try,
     * because there is no scheduled job in a webtrees installation to hang one
     * on and the member coming back to look is the moment they most want it to
     * be true.
     *
     * @return array<string,mixed>
     */
    public function state(UserInterface $user): array
    {
        if (!$this->enabled()) {
            return ['enabled' => false, 'address' => '', 'lists' => []];
        }

        $this->apply($user);

        $lists = [];
        $rows  = $this->rows($user);

        foreach ($this->configured() as $hash => $list) {
            $row = $rows[$hash] ?? null;

            $lists[] = [
                'key'         => $hash,
                'name'        => $list['name'],
                'description' => $list['description'],
                'subscribed'  => $row !== null && (bool) $row->subscribed,
                'state'       => $this->rowState($row),
            ];
        }

        return [
            'enabled' => true,
            // Shown to the member, because "subscribe me" is a promise to send
            // post somewhere and they should be able to see where.
            'address' => $user->email(),
            'lists'   => $lists,
        ];
    }

    /**
     * Take a decision down, then try to make Exchange agree with it.
     *
     * @param array<string,mixed> $wishes one entry per list the member changed
     *
     * @return array<string,mixed> the state, as `state()` would have returned it
     */
    public function change(UserInterface $user, array $wishes): array
    {
        if (!$this->enabled()) {
            throw new ApiException(
                'not_allowed',
                403,
                I18N::translate('This family tree does not offer mailing lists.')
            );
        }

        if (trim($user->email()) === '') {
            throw new ApiException(
                'not_allowed',
                403,
                I18N::translate('Your account has no email address, so there is nowhere to send the family’s post. Add one first.')
            );
        }

        $configured = $this->configured();
        $now        = time();

        foreach ($wishes as $key => $wanted) {
            if (!is_bool($wanted) || !array_key_exists($key, $configured)) {
                throw ApiException::badRequest();
            }

            $existing = DB::table('portal_list_subscription')
                ->where('wt_user_id', '=', $user->id())
                ->where('list_hash', '=', $key)
                ->first();

            $columns = [
                'list_address' => $configured[$key]['address'],
                'subscribed'   => $wanted,
                'decided_at'   => $now,
                // A fresh decision supersedes whatever the last one was still
                // trying to do, including one that had given up.
                'applied_at'   => null,
                'attempts'     => 0,
                'attempted_at' => null,
                'last_error'   => null,
            ];

            if ($existing === null) {
                DB::table('portal_list_subscription')->insert($columns + [
                    'wt_user_id' => $user->id(),
                    'list_hash'  => $key,
                    'address'    => $user->email(),
                ]);

                continue;
            }

            // `address` is deliberately not among the columns above. It says
            // which address this row was last *applied* under, and a member
            // who has changed their email since is relying on it: it is the
            // only thing that knows where to send the removal. Bringing it up
            // to date is the connector's job, once the move has been made.
            DB::table('portal_list_subscription')
                ->where('id', '=', (int) $existing->id)
                ->update($columns);
        }

        return $this->state($user);
    }

    /**
     * Whatever an administrator would want to see about all of this at once.
     *
     * @return array<string,mixed>
     */
    public function overview(): array
    {
        $rows = DB::table('portal_list_subscription')->get();

        $counts    = [];
        $failed    = [];
        $pending   = 0;

        foreach ($rows as $row) {
            $hash = (string) $row->list_hash;

            $counts[$hash] ??= 0;

            if ((bool) $row->subscribed && $row->applied_at !== null) {
                $counts[$hash]++;
            }

            if ($row->applied_at === null) {
                $pending++;
            }

            if ($row->applied_at === null && (int) $row->attempts >= self::MAX_ATTEMPTS && $row->last_error !== null) {
                $failed[] = ['list' => (string) $row->list_address, 'error' => (string) $row->last_error];
            }
        }

        return ['members' => $counts, 'outstanding' => $pending, 'failed' => $failed];
    }

    /**
     * Let everything that has given up try once more. The admin screen's button.
     *
     * @return int how many rows were woken
     */
    public function retryAll(): int
    {
        return DB::table('portal_list_subscription')
            ->whereNull('applied_at')
            ->update(['attempts' => 0, 'attempted_at' => null]);
    }

    // -----------------------------------------------------------------
    // Making Exchange agree
    // -----------------------------------------------------------------

    /**
     * Apply what is outstanding for one member, within this request's budget.
     *
     * Never throws. A member who has just pressed a switch is told what
     * happened by the state that comes back, not by an error page — and a
     * member who is only reading the screen must not be shown somebody else's
     * infrastructure failing at all.
     */
    private function apply(UserInterface $user): void
    {
        $configured = $this->configured();
        $email      = trim($user->email());

        if ($email === '') {
            return;
        }

        foreach ($this->outstanding($user, $email) as $row) {
            $list = $configured[(string) $row->list_hash] ?? null;

            // A list an administrator has removed from the setting. The row
            // stays — it is still the record of a decision — but there is
            // nothing to apply it to.
            if ($list === null) {
                continue;
            }

            $this->applyRow($row, $list['address'], $email, $user->realName());
        }
    }

    /**
     * Rows with something still to do, oldest decision first.
     *
     * Two kinds. The plain one is a decision Exchange has not been told about
     * yet. The other is subtler and is the reason the applied address is kept
     * per row: a member who changes their account address is subscribed under
     * an address that is no longer theirs, and nothing else in the portal would
     * ever notice. Here it looks exactly like unfinished work, because it is.
     *
     * @return array<int,object>
     */
    private function outstanding(UserInterface $user, string $email): array
    {
        $rows = DB::table('portal_list_subscription')
            ->where('wt_user_id', '=', $user->id())
            ->where(static function ($query) use ($email): void {
                $query->whereNull('applied_at')
                    ->orWhere(static function ($moved) use ($email): void {
                        $moved->where('subscribed', '=', 1)->where('address', '<>', $email);
                    });
            })
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(static function ($query): void {
                $query->whereNull('attempted_at')
                    ->orWhere('attempted_at', '<', time() - self::RETRY_AFTER);
            })
            ->orderBy('decided_at')
            ->get()
            ->all();

        return array_slice($rows, 0, self::PER_REQUEST);
    }

    private function applyRow(object $row, string $list, string $email, string $name): void
    {
        $applied  = (string) $row->address;
        $wanted   = (bool) $row->subscribed;
        $attempts = (int) $row->attempts + 1;

        try {
            // An address that has moved comes off the list under the address
            // it went on under, before it goes back on under the new one.
            // Doing this the other way round would leave the old one behind
            // whenever the second call failed.
            if ($applied !== '' && mb_strtolower($applied) !== mb_strtolower($email)) {
                $this->exchange->unsubscribe($list, $applied);
            }

            if ($wanted) {
                $this->exchange->subscribe($list, $email, $name);
            } elseif ($applied === '' || mb_strtolower($applied) === mb_strtolower($email)) {
                $this->exchange->unsubscribe($list, $email);
            }

            DB::table('portal_list_subscription')
                ->where('id', '=', (int) $row->id)
                ->update([
                    'address'      => $email,
                    'applied_at'   => time(),
                    'attempts'     => 0,
                    // Null rather than now: this column exists to hold a
                    // *failure* off for a few minutes, and a row that has just
                    // succeeded must not be made to wait before it can act on
                    // the next thing — a member changing their address, say.
                    'attempted_at' => null,
                    'last_error'   => null,
                ]);
        } catch (ExchangeFailure $failure) {
            DB::table('portal_list_subscription')
                ->where('id', '=', (int) $row->id)
                ->update([
                    // A refusal that will be refused again is not worth two
                    // more round trips; it goes straight to the state that
                    // waits for an administrator.
                    'attempts'     => $failure->permanent ? self::MAX_ATTEMPTS : $attempts,
                    'attempted_at' => time(),
                    'last_error'   => mb_substr($failure->getMessage(), 0, 500),
                ]);
        } catch (Throwable $exception) {
            // Anything this did not expect is a defect here rather than a
            // refusal there. It is logged where the module's other surprises
            // are logged, and the row is left to be tried again.
            error_log('portal_api: applying a mailing-list subscription failed. ' . $exception::class . ': ' . $exception->getMessage());

            DB::table('portal_list_subscription')
                ->where('id', '=', (int) $row->id)
                ->update(['attempts' => $attempts, 'attempted_at' => time()]);
        }
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * This member's rows, by list.
     *
     * @return array<string,object>
     */
    private function rows(UserInterface $user): array
    {
        $rows = [];

        foreach (DB::table('portal_list_subscription')->where('wt_user_id', '=', $user->id())->get() as $row) {
            $rows[(string) $row->list_hash] = $row;
        }

        return $rows;
    }

    /**
     * Three words a member may be told, and only three.
     *
     * `applied` is the ordinary state and the only one the screen says nothing
     * about. `pending` is "we have your answer and are passing it on".
     * `failed` is "we have your answer and could not pass it on" — which is
     * still a promise, because the row is there and an administrator can see
     * it, and it is very deliberately not Exchange's error message.
     */
    private function rowState(object|null $row): string
    {
        if ($row === null || $row->applied_at !== null) {
            return 'applied';
        }

        return (int) $row->attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
    }

    /** How a list is named to the database and to the portal. Never its address. */
    public static function hash(string $address): string
    {
        return hash('sha256', mb_strtolower(trim($address)));
    }
}
