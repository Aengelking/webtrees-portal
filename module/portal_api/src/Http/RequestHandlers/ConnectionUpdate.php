<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PATCH /api/v1/connections/{id} — say yes to a request.
 *
 * The only change a member can make to a connection is to accept one that was
 * made to them. Saying no is `DELETE`, because a refusal should leave nothing
 * behind.
 */
class ConnectionUpdate implements RequestHandlerInterface
{
    public function __construct(private readonly Connections $connections)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = Json::body($request);

        if (($body['status'] ?? '') !== Connections::STATUS_ACCEPTED) {
            throw ApiException::badRequest();
        }

        return Json::response(
            $this->connections->accept(Auth::user(), Validator::attributes($request)->integer('id', 0))
        );
    }
}
