<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\DB;
use Throwable;

use function error_log;
use function hash_hmac;
use function mb_strtolower;
use function random_int;
use function time;
use function trim;

/**
 * Rate limiting for POST /session, by IP address and by username.
 *
 * Fails closed: if the attempt store cannot be read or written, logins are
 * refused. An unavailable limiter is not a reason to let an attacker guess
 * passwords as fast as the server can hash them.
 *
 * Usernames are stored as a keyed hash, so that this table does not become a
 * second list of who has an account. The key is derived from the site's own
 * secret, which is unique per installation.
 */
class LoginRateLimiter
{
    public const string TABLE = 'portal_login_attempt';

    /** Roughly one in this many calls also prunes expired rows. */
    private const int PRUNE_ODDS = 20;

    public function __construct(private readonly PortalApiModule $module)
    {
    }

    /**
     * May this IP address / username pair attempt a login right now?
     */
    public function allows(string $ip_address, string $username): bool
    {
        $window   = $this->window();
        $since    = time() - $window;
        $ip_limit = (int) $this->module->getPreference(PortalApiModule::SETTING_RATE_LIMIT_IP, (string) PortalApiModule::DEFAULT_RATE_LIMIT_IP);
        $user_limit = (int) $this->module->getPreference(PortalApiModule::SETTING_RATE_LIMIT_USER, (string) PortalApiModule::DEFAULT_RATE_LIMIT_USER);

        // A limit of zero disables that dimension.
        try {
            if ($ip_limit > 0) {
                $attempts = DB::table(self::TABLE)
                    ->where('ip_address', '=', $ip_address)
                    ->where('attempted_at', '>=', $since)
                    ->count();

                if ($attempts >= $ip_limit) {
                    return false;
                }
            }

            if ($user_limit > 0) {
                $attempts = DB::table(self::TABLE)
                    ->where('username_hash', '=', $this->hash($username))
                    ->where('attempted_at', '>=', $since)
                    ->count();

                if ($attempts >= $user_limit) {
                    return false;
                }
            }
        } catch (Throwable $exception) {
            error_log('portal_api: rate limiter unavailable, refusing login: ' . $exception->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Record a failed attempt. Successful logins are not recorded, so that a
     * member who signs in normally does not lock themselves out.
     */
    public function recordFailure(string $ip_address, string $username): void
    {
        try {
            DB::table(self::TABLE)->insert([
                'ip_address'    => $ip_address,
                'username_hash' => $this->hash($username),
                'attempted_at'  => time(),
            ]);

            if (random_int(1, self::PRUNE_ODDS) === 1) {
                $this->prune();
            }
        } catch (Throwable $exception) {
            error_log('portal_api: could not record login attempt: ' . $exception->getMessage());
        }
    }

    /**
     * Forget this member's failures after they sign in successfully.
     */
    public function clear(string $ip_address, string $username): void
    {
        try {
            DB::table(self::TABLE)
                ->where('username_hash', '=', $this->hash($username))
                ->delete();
        } catch (Throwable $exception) {
            error_log('portal_api: could not clear login attempts: ' . $exception->getMessage());
        }
    }

    public function prune(): void
    {
        DB::table(self::TABLE)
            ->where('attempted_at', '<', time() - $this->window())
            ->delete();
    }

    private function window(): int
    {
        return max(60, (int) $this->module->getPreference(
            PortalApiModule::SETTING_RATE_LIMIT_WINDOW,
            (string) PortalApiModule::DEFAULT_RATE_LIMIT_WINDOW
        ));
    }

    /**
     * Usernames are matched case-insensitively, as webtrees does, so hash the
     * folded form.
     */
    private function hash(string $username): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($username)), $this->key());
    }

    private function key(): string
    {
        $key = $this->module->getPreference('rate_limit_key', '');

        if ($key === '') {
            $key = bin2hex(random_bytes(32));
            $this->module->setPreference('rate_limit_key', $key);
        }

        return $key;
    }
}
