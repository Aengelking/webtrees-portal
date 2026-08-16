<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/v1/session — log out.
 *
 * `Auth::logout()` destroys the session server-side, so the cookie is dead
 * even if the browser keeps it. Logging out when not logged in succeeds
 * quietly; there is nothing to protect and nothing to disclose.
 */
class SessionDelete implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (Auth::check()) {
            Log::addAuthenticationLog('Portal logout: ' . Auth::user()->userName());
        }

        Auth::logout();

        // The old session, and with it the old CSRF token, is gone. Hand the
        // portal a usable token so the login screen works immediately.
        return Json::response(['csrf_token' => Session::getCsrfToken()]);
    }
}
