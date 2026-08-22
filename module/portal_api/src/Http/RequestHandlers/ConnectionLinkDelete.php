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
 * DELETE /api/v1/me/connection-links/{id} — take back a link nobody has used.
 *
 * For the message sent to the wrong address, and for the one that has been
 * sitting in a mailbox longer than it should. Somebody else's link, and one
 * already used, are both "not found": a member has no business learning
 * which, and a link that was used is no longer a link — it is a connection,
 * and ending that is `DELETE /connections/{id}`.
 */
class ConnectionLinkDelete implements RequestHandlerInterface
{
    public function __construct(private readonly Connections $connections)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response(
            $this->connections->revokeLink(Auth::user(), Validator::attributes($request)->integer('id', 0))
        );
    }
}
