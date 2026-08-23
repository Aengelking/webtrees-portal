<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Http\Middleware\CookieJar;
use Engelking\Webtrees\PortalApi\Services\RememberedDevices;
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
 *
 * **And this device stops being remembered.** Signing out has to mean it, or
 * the next request would walk straight back in on the cookie — which is the
 * one way this feature could turn into a member being unable to leave. Only
 * this device: a telephone signing out is not a statement about the tablet.
 */
class SessionDelete implements RequestHandlerInterface
{
    public function __construct(private readonly RememberedDevices $devices)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->devices->forget(CookieJar::read($request, RememberedDevices::COOKIE));

        if (Auth::check()) {
            Log::addAuthenticationLog('Portal logout: ' . Auth::user()->userName());
        }

        Auth::logout();

        // The old session, and with it the old CSRF token, is gone. Hand the
        // portal a usable token so the login screen works immediately — and
        // `remember_days` with it, which is the same `CsrfToken` body that
        // screen would otherwise have to go and ask for.
        return CookieJar::clear(
            Json::response([
                'csrf_token'    => Session::getCsrfToken(),
                'remember_days' => $this->devices->days(),
            ]),
            $request,
            RememberedDevices::COOKIE,
        );
    }
}
