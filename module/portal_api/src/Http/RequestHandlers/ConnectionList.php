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
 * GET /api/v1/connections — whom I know, who is waiting for an answer, and
 * whom I have asked.
 *
 * Answers even when the family has switched connections off, with
 * `enabled: false` and the lists a member already has. A screen that can say
 * "your family has this switched off" is better than a 403 at a member who
 * did nothing wrong — and they can still end a connection they no longer
 * want.
 */
class ConnectionList implements RequestHandlerInterface
{
    public function __construct(private readonly Connections $connections)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response($this->connections->overview(Auth::user()));
    }
}
