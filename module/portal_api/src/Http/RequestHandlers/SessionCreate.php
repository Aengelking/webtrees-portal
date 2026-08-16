<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\LoginRateLimiter;
use Engelking\Webtrees\PortalApi\Services\MeAssembler;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\User;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function time;

/**
 * POST /api/v1/session — log in.
 *
 * Password verification goes through webtrees' own `User::checkPassword()`.
 * No hashing or comparison is reimplemented here.
 *
 * Every failure — unknown user, wrong password, unverified email address,
 * account awaiting an administrator, and rate limiting — produces the same
 * 401 body. The real reason goes to webtrees' authentication log, which an
 * administrator can read and an attacker cannot.
 */
class SessionCreate implements RequestHandlerInterface
{
    /** A valid bcrypt hash of a value nobody knows, used only to burn time. */
    private const string DUMMY_HASH = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function __construct(
        private readonly UserService $user_service,
        private readonly LoginRateLimiter $rate_limiter,
        private readonly MeAssembler $me,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body      = Json::body($request);
        $username  = Json::requiredString($body, 'username');
        $password  = Json::requiredString($body, 'password');
        $ip        = Validator::attributes($request)->string('client-ip', '');

        if (!$this->rate_limiter->allows($ip, $username)) {
            Log::addAuthenticationLog('Portal login rate-limited: ' . $username . ' from ' . $ip);

            throw ApiException::invalidCredentials();
        }

        $user = $this->authenticate($username, $password, $ip);

        Auth::login($user);
        Log::addAuthenticationLog('Portal login: ' . $user->userName() . '/' . $user->realName());

        $user->setPreference(UserInterface::PREF_TIMESTAMP_ACTIVE, (string) time());

        // Match what webtrees' own login does, so that a member who moves
        // between the portal and webtrees keeps their language and theme.
        // Unlike core, tolerate an account that has never chosen either:
        // Locale::create('') throws, and a member should not be locked out of
        // the portal by a blank preference.
        $language = $user->getPreference(UserInterface::PREF_LANGUAGE);
        $theme    = $user->getPreference(UserInterface::PREF_THEME);

        if ($theme !== '') {
            Session::put('theme', $theme);
        }

        if ($language !== '') {
            Session::put('language', $language);
            I18N::init($language);
        }

        $this->rate_limiter->clear($ip, $username);

        return Json::response($this->me->assemble($user));
    }

    private function authenticate(string $username, string $password, string $ip): User
    {
        $user   = $this->user_service->findByIdentifier($username);
        $reason = null;

        if ($user === null) {
            // Spend roughly the time a real password check would, so that
            // response time does not disclose whether the account exists.
            password_verify($password, self::DUMMY_HASH);

            $reason = 'no such user/email';
        } elseif (!$user->checkPassword($password)) {
            $reason = 'incorrect password';
        } elseif ($user->getPreference(UserInterface::PREF_IS_EMAIL_VERIFIED) !== '1') {
            $reason = 'not verified by user';
        } elseif ($user->getPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED) !== '1') {
            $reason = 'not approved by admin';
        }

        if ($reason !== null) {
            Log::addAuthenticationLog('Portal login failed (' . $reason . '): ' . $username);
            $this->rate_limiter->recordFailure($ip, $username);

            throw ApiException::invalidCredentials();
        }

        return $user;
    }
}
