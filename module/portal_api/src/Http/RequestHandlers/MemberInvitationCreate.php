<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\MemberInvitations;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function is_string;
use function trim;

/**
 * POST /api/v1/invitations — a member invites one of their close family.
 *
 * The response carries the link, once. Nothing stores it and nothing can
 * recover it: `portal_invitation` holds only a hash. That is why the screen
 * says so, and why losing it means withdrawing the invitation and issuing
 * another.
 */
class MemberInvitationCreate implements RequestHandlerInterface
{
    public function __construct(private readonly MemberInvitations $invitations)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body  = Json::body($request);
        $xref  = Json::requiredString($body, 'xref');
        $email = $body['email'] ?? '';

        return Json::response(
            $this->invitations->create(Auth::user(), $xref, is_string($email) ? trim($email) : ''),
            StatusCodeInterface::STATUS_CREATED
        );
    }
}
