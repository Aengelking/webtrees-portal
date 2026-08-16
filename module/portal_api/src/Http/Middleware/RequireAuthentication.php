<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\Middleware;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Every endpoint except /csrf and POST /session needs a session.
 *
 * The 401 body carries an error code and nothing else — no record data, no
 * hint about what exists behind it.
 */
class RequireAuthentication implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Auth::check()) {
            throw ApiException::unauthenticated();
        }

        return $handler->handle($request);
    }
}
