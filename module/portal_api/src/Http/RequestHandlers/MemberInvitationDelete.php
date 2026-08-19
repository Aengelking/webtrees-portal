<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\MemberInvitations;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/v1/invitations/{id} — withdraw one I sent.
 *
 * Somebody else's invitation, and one that has already been used, are both
 * "not found". A member has no business learning which.
 */
class MemberInvitationDelete implements RequestHandlerInterface
{
    public function __construct(private readonly MemberInvitations $invitations)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->invitations->revoke(Auth::user(), Validator::attributes($request)->integer('id', 0));

        return Json::response($this->invitations->overview(Auth::user()));
    }
}
