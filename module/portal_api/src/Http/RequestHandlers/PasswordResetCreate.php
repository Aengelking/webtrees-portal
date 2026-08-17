<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\MeAssembler;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function mb_strlen;
use function time;

/**
 * POST /api/v1/password/reset — set a new password from an emailed token.
 *
 * `UserService::findByToken()` checks both the token and its expiry in one
 * query, so an expired token is simply unknown.
 *
 * Unlike the login endpoint, this one *does* distinguish its failures. A token
 * is not a secret worth protecting once it has expired, and a member who took
 * too long over their coffee needs to be told that rather than left guessing.
 * It reveals nothing about who has an account: the token was already in their
 * hands.
 */
class PasswordResetCreate implements RequestHandlerInterface
{
    /** Matches webtrees' own minimum, so a password set here also works there. */
    private const int MINIMUM_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly UserService $user_service,
        private readonly MeAssembler $me,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body     = Json::body($request);
        $token    = Json::requiredString($body, 'token');
        $password = Json::requiredString($body, 'password');

        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            throw ApiException::badRequest(I18N::translate(
                'The password must be at least %s characters long.',
                I18N::number(self::MINIMUM_PASSWORD_LENGTH)
            ));
        }

        $user = $this->user_service->findByToken($token);

        if (!$user instanceof User) {
            throw new ApiException(
                'invalid_token',
                StatusCodeInterface::STATUS_BAD_REQUEST,
                I18N::translate('This link has expired or has already been used. Please request a new one.')
            );
        }

        // Burn the token before doing anything else, so a resubmitted form
        // cannot use it twice.
        $user->setPreference('password-token', '');
        $user->setPreference('password-token-expire', '');
        $user->setPassword($password);

        Auth::login($user);
        Log::addAuthenticationLog('Portal password reset completed: ' . $user->userName());

        $user->setPreference(UserInterface::PREF_TIMESTAMP_ACTIVE, (string) time());

        return Json::response($this->me->assemble($user));
    }
}
