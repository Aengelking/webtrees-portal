<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\RememberedDevices;
use Fisharebest\Webtrees\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/csrf
 *
 * The login screen calls this first: it establishes the session cookie and
 * hands out the token that the login POST has to carry.
 *
 * `remember_days` rides along because the login screen has no other way to
 * ask. Everything else a screen needs to know about what this portal allows
 * arrives with `GET /me`, and there is no `/me` before signing in — so the
 * one thing a member decides *while* signing in has to be answerable without
 * a session. Zero means the family has not switched it on, and the screen
 * offers nothing rather than a switch that would be ignored.
 *
 * It discloses nothing: a setting about this portal's own login, the same for
 * every member and every visitor, on an endpoint that already exists to be
 * called before anybody is known.
 */
class CsrfTokenRead implements RequestHandlerInterface
{
    public function __construct(private readonly RememberedDevices $devices)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response([
            'csrf_token'    => Session::getCsrfToken(),
            'remember_days' => $this->devices->days(),
        ]);
    }
}
