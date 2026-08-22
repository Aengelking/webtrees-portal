<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/me/connection-link — a link to send to somebody not in the room.
 *
 * The same handshake as the code on the screen: whoever follows it and taps
 * is connected. What differs is what a week in somebody else's inbox does to
 * a credential — so it works once, and this endpoint hands out a new one each
 * time rather than repeating the last, because the table holds only a hash
 * and there is nothing to repeat.
 */
class ConnectionLinkCreate implements RequestHandlerInterface
{
    public function __construct(private readonly Connections $connections)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response(
            $this->connections->issueLink(Auth::user()),
            StatusCodeInterface::STATUS_CREATED
        );
    }
}
