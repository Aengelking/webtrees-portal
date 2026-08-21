<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/v1/connections/{id} — decline it, withdraw it, or end it.
 *
 * One verb for the three, because they are one act: this row should not exist
 * any more. It works whichever end of the row the member is holding, and it
 * works when the family has switched connections off — a member must always
 * be able to undo this, whatever the administrator has done since.
 */
class ConnectionDelete implements RequestHandlerInterface
{
    public function __construct(private readonly Connections $connections)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response(
            $this->connections->remove(Auth::user(), Validator::attributes($request)->integer('id', 0))
        );
    }
}
