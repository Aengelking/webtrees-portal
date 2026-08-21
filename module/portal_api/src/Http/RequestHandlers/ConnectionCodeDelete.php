<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/v1/me/connection-code — stop the code working now.
 *
 * A code lives for a quarter of an hour whether or not the member is still
 * holding their telephone up. This is the button for "put it away", and it is
 * the answer to somebody having photographed the screen.
 */
class ConnectionCodeDelete implements RequestHandlerInterface
{
    public function __construct(private readonly Connections $connections)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->connections->revokeCode(Auth::user());

        return Json::response(['status' => 'revoked']);
    }
}
