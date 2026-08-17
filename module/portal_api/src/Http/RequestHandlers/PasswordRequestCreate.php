<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Http\Exceptions\HttpTooManyRequestsException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\RateLimitService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\SiteUser;
use Fisharebest\Webtrees\User;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function error_log;
use function is_string;
use function random_int;
use function rawurlencode;
use function rtrim;
use function time;
use function usleep;
use function view;

/**
 * POST /api/v1/password/request — send a reset link.
 *
 * Reuses webtrees' own mechanism wholesale: the same `password-token` user
 * preference, the same one-hour validity, the same rate limiter, the same
 * email templates and the site's own mail configuration. The only difference
 * is that the link points at the portal instead of at webtrees.
 *
 * The response is always the same, and takes a broadly similar time whether
 * or not the address belongs to an account. Anything else and this becomes a
 * way to discover who is in the family.
 */
class PasswordRequestCreate implements RequestHandlerInterface
{
    private const int TOKEN_LENGTH = 40;
    private const int TOKEN_VALIDITY_SECONDS = 3600;
    private const int RATE_LIMIT_REQUESTS = 5;
    private const int RATE_LIMIT_SECONDS = 300;

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly UserService $user_service,
        private readonly EmailService $email_service,
        private readonly RateLimitService $rate_limit_service,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body  = Json::body($request);
        $email = $body['email'] ?? null;

        if (is_string($email) && $email !== '') {
            $this->trySend($email);
        }

        // Note the absence of an `else`: a malformed body gets the same answer
        // as a valid one. There is nothing here worth telling anybody.
        return Json::response(['status' => 'accepted'], StatusCodeInterface::STATUS_ACCEPTED);
    }

    private function trySend(string $email): void
    {
        $user = $this->user_service->findByEmail($email);

        if (!$user instanceof User) {
            // Sending mail takes a moment. Without a comparable pause here,
            // response time alone would answer the question the body refuses
            // to. This is webtrees' own approach, and the same reasoning as
            // the dummy password_verify() in SessionCreate.
            usleep(random_int(500_000, 2_000_000));

            return;
        }

        try {
            $this->rate_limit_service->limitRateForUser(
                $user,
                self::RATE_LIMIT_REQUESTS,
                self::RATE_LIMIT_SECONDS,
                'rate-limit-pw-reset'
            );
        } catch (HttpTooManyRequestsException) {
            // Swallowed on purpose. Reporting the limit would tell an attacker
            // that this address has an account — the one thing this endpoint
            // exists not to say.
            Log::addAuthenticationLog('Portal password reset rate-limited: ' . $user->userName());

            return;
        }

        $token = Str::random(self::TOKEN_LENGTH);

        $user->setPreference('password-token', $token);
        $user->setPreference('password-token-expire', (string) (time() + self::TOKEN_VALIDITY_SECONDS));

        try {
            $this->email_service->send(
                new SiteUser(),
                $user,
                new SiteUser(),
                I18N::translate('Request a new password'),
                view('emails/password-request-text', ['url' => $this->resetUrl($token), 'user' => $user]),
                view('emails/password-request-html', ['url' => $this->resetUrl($token), 'user' => $user])
            );

            Log::addAuthenticationLog('Portal password reset requested: ' . $user->userName());
        } catch (Throwable $exception) {
            // A mail server that is down must not produce a different response
            // from an address that does not exist.
            error_log('portal_api: could not send a password reset email: ' . $exception->getMessage());
        }
    }

    /**
     * The link has to land on the portal, not on webtrees — the member is
     * being sent back to where they came from.
     */
    private function resetUrl(string $token): string
    {
        $base = rtrim($this->module->getPreference(PortalApiModule::SETTING_PORTAL_URL, ''), '/');

        return $base . '/password/reset?token=' . rawurlencode($token);
    }
}
