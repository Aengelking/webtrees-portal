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
 * POST /api/v1/me/connection-code — a code to hold up at the family gathering.
 *
 * A POST rather than a GET, and it is not idempotent on purpose: each call
 * issues a new code and kills the one before it. That is what makes the code
 * on the screen the only one that works, and it is why the response carries
 * the code itself — the table holds a hash, so nothing can hand it out twice.
 */
class ConnectionCodeCreate implements RequestHandlerInterface
{
    public function __construct(private readonly Connections $connections)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response(
            $this->connections->issueCode(Auth::user()),
            StatusCodeInterface::STATUS_CREATED
        );
    }
}
