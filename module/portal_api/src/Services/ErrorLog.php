<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Illuminate\Support\Collection;
use Throwable;

use function bin2hex;
use function error_log;
use function mb_substr;
use function random_bytes;
use function random_int;
use function strrpos;
use function substr;
use function time;

/**
 * Records the errors a member actually hit, and hands back a reference.
 *
 * The reference is the point of the whole thing. Without one, a report reads
 * "it did not work yesterday, on my phone", and there is no way to connect
 * that to any particular row. With one, the member reads eight characters off
 * their screen and the administrator has the exact request.
 *
 * Two rules this class does not break:
 *
 * **It never throws.** It is called from the middleware that exists to turn
 * exceptions into JSON. An error store that fails while recording an error
 * would replace a handled 500 with an unhandled one, which is the opposite of
 * its job. Every method swallows and falls back to `error_log()`.
 *
 * **It stores no request content** — see Schema/Migration2.php.
 */
class ErrorLog
{
    public const string TABLE = 'portal_error';

    /**
     * How long a recorded error is kept.
     *
     * Long enough that a member who mentions it a fortnight later still has a
     * row to point at; short enough that the table is not an archive. An
     * exception message can quote a value it was choking on, and nothing that
     * might carry personal data should sit around indefinitely.
     */
    private const int RETAIN_DAYS = 30;

    /** Long enough not to collide in practice, short enough to read aloud. */
    private const int REFERENCE_BYTES = 4;

    private const int MAX_MESSAGE_LENGTH = 500;

    /**
     * Record one failure and return its reference.
     *
     * Returns an empty string when nothing could be recorded, which the
     * caller treats as "no reference to show" rather than as a failure.
     */
    public function record(Throwable $exception, int $status, string $route, string $method): string
    {
        try {
            $reference = bin2hex(random_bytes(self::REFERENCE_BYTES));

            DB::table(self::TABLE)->insert([
                'reference'   => $reference,
                'occurred_at' => time(),
                'status'      => $status,
                'route'       => $this->shortName($route),
                'method'      => mb_substr($method, 0, 10),
                'error_class' => mb_substr($exception::class, 0, 191),
                'message'     => mb_substr($exception->getMessage(), 0, self::MAX_MESSAGE_LENGTH),
                'file'        => mb_substr($exception->getFile(), 0, 255),
                'line'        => $exception->getLine(),
                'wt_user_id'  => Auth::id(),
            ]);

            // Opportunistic, like the login limiter's: roughly one write in
            // twenty pays for the cleanup, so there is no cron job to forget.
            if (random_int(1, 20) === 1) {
                $this->prune();
            }

            return $reference;
        } catch (Throwable $failure) {
            error_log('portal_api: could not record an error: ' . $failure->getMessage());

            return '';
        }
    }

    /**
     * The most recent failures, newest first.
     *
     * @return Collection<int,object>
     */
    public function recent(int $limit = 25): Collection
    {
        try {
            return DB::table(self::TABLE)
                ->orderBy('occurred_at', 'desc')
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get();
        } catch (Throwable $failure) {
            error_log('portal_api: could not read the error log: ' . $failure->getMessage());

            return new Collection();
        }
    }

    public function count(): int
    {
        try {
            return DB::table(self::TABLE)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Everything since a given moment — "has anything broken today?". */
    public function countSince(int $timestamp): int
    {
        try {
            return DB::table(self::TABLE)->where('occurred_at', '>=', $timestamp)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public function clear(): void
    {
        try {
            DB::table(self::TABLE)->delete();
        } catch (Throwable $failure) {
            error_log('portal_api: could not clear the error log: ' . $failure->getMessage());
        }
    }

    public function prune(): void
    {
        try {
            DB::table(self::TABLE)
                ->where('occurred_at', '<', time() - self::RETAIN_DAYS * 86400)
                ->delete();
        } catch (Throwable $failure) {
            error_log('portal_api: could not prune the error log: ' . $failure->getMessage());
        }
    }

    /**
     * Route names are handler class names, which are long and all share a
     * prefix. The last segment is the part that says anything.
     */
    private function shortName(string $route): string
    {
        $position = strrpos($route, '\\');
        $short    = $position === false ? $route : substr($route, $position + 1);

        return mb_substr($short, 0, 100);
    }
}
