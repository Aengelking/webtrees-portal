<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\Middleware;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function hash_equals;
use function in_array;
use function is_string;

/**
 * Require webtrees' own CSRF token on every unsafe request.
 *
 * Authentication is a cookie, so `SameSite=Lax` alone is not enough: it does
 * not stop a cross-origin POST from a page the member happens to have open.
 * The token is `Session::getCsrfToken()` — webtrees' mechanism, not a second
 * one — and travels in `X-CSRF-TOKEN`, which a cross-origin form cannot set.
 *
 * Phase 1 is read-only apart from login and logout. This exists now because
 * Phase 2's write endpoints will depend on it.
 *
 * Note: webtrees core runs its own CheckCsrf middleware in front of every
 * POST route, before this one, and answers a bad token with a 302 redirect.
 * So for POST this check is a belt-and-braces second pass that normally
 * cannot fail; for DELETE, PUT and PATCH — which core does not check — it is
 * the only check. See NOTES.md.
 */
class RequireCsrfToken implements MiddlewareInterface
{
    public const string HEADER = 'X-CSRF-TOKEN';

    private const array SAFE_METHODS = [
        RequestMethodInterface::METHOD_GET,
        RequestMethodInterface::METHOD_HEAD,
        RequestMethodInterface::METHOD_OPTIONS,
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $session_token = Session::get('CSRF_TOKEN');
        $client_token  = $request->getHeaderLine(self::HEADER);

        if (!is_string($session_token) || $session_token === '' || !hash_equals($session_token, $client_token)) {
            throw ApiException::csrfTokenInvalid();
        }

        return $handler->handle($request);
    }
}
