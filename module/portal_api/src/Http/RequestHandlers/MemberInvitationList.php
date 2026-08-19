<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\MemberInvitations;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/invitations — whom may I invite, and whom have I invited?
 *
 * Everything the invite screen needs in one request: the close relatives who
 * could still be invited, the member's own outstanding invitations, and how
 * many they have left.
 *
 * The candidate list contains only people this member can already see on
 * their own page — it is the same walk, at the same access level, stopping
 * at the same limit. Opening this screen discloses nobody new.
 */
class MemberInvitationList implements RequestHandlerInterface
{
    public function __construct(private readonly MemberInvitations $invitations)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response($this->invitations->overview(Auth::user()));
    }
}
