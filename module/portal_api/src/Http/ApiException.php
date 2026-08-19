<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\I18N;
use RuntimeException;

/**
 * An error that is safe to show a client.
 *
 * Anything else that escapes a handler is turned into a generic 500 by
 * ApiEnvelope, so that internal messages never reach the portal.
 */
class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $error,
        public readonly int $status,
        string $message
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message = ''): self
    {
        return new self(
            'bad_request',
            StatusCodeInterface::STATUS_BAD_REQUEST,
            $message !== '' ? $message : I18N::translate('The request was incomplete.')
        );
    }

    public static function unauthenticated(): self
    {
        return new self(
            'unauthenticated',
            StatusCodeInterface::STATUS_UNAUTHORIZED,
            I18N::translate('Please sign in.')
        );
    }

    public static function invalidCredentials(): self
    {
        return new self(
            'invalid_credentials',
            StatusCodeInterface::STATUS_UNAUTHORIZED,
            I18N::translate('The username or password is incorrect.')
        );
    }

    public static function csrfTokenInvalid(): self
    {
        return new self(
            'csrf_token_invalid',
            StatusCodeInterface::STATUS_FORBIDDEN,
            I18N::translate('This form has expired. Try again.')
        );
    }

    public static function proxySecretInvalid(): self
    {
        return new self(
            'proxy_secret_invalid',
            StatusCodeInterface::STATUS_FORBIDDEN,
            I18N::translate('This request did not come from the portal.')
        );
    }

    /**
     * Used both for "no such record" and for "you may not see this record",
     * so that the response cannot be used to prove a record exists.
     */
    public static function notFound(): self
    {
        return new self(
            'not_found',
            StatusCodeInterface::STATUS_NOT_FOUND,
            I18N::translate('This item does not exist.')
        );
    }

    /**
     * Unknown, expired, spent, revoked, for another tree, or asked for too
     * often — one answer for all of them.
     *
     * Unlike a login, this does not need to hide *which* failure it was in
     * order to avoid saying who has an account: an invitation token names
     * nobody. It is one message because there is one thing the reader can
     * usefully do about any of them, which is to ask for a new invitation.
     */
    public static function invalidInvitation(): self
    {
        return new self(
            'invalid_token',
            StatusCodeInterface::STATUS_BAD_REQUEST,
            I18N::translate('This invitation has expired or has already been used. Please ask for a new one.')
        );
    }

    /**
     * Refusals that a form has to be able to act on.
     *
     * These do name something about the installation — that a username or an
     * address is spoken for — and that is unavoidable: a registration form
     * that cannot say why it will not accept a name is a form nobody can
     * complete. It is reachable only by someone already holding a valid
     * invitation, which is what keeps it from being a way to enumerate
     * accounts.
     */
    public static function conflict(string $error, string $message): self
    {
        return new self($error, StatusCodeInterface::STATUS_CONFLICT, $message);
    }

    public static function notConfigured(string $message): self
    {
        return new self(
            'not_configured',
            StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE,
            $message
        );
    }
}
