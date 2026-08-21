<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Engelking\Webtrees\PortalApi\Services\ContactDetails;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/me/contact — what am I sharing, and with whom?
 *
 * The member's own entries, audience and all. Only ever their own: you can
 * always see what you have chosen to share, and this is the only place that
 * ignores the audience.
 */
class ContactRead implements RequestHandlerInterface
{
    public function __construct(
        private readonly ContactDetails $contacts,
        private readonly Connections $connections,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response([
            'enabled' => $this->contacts->enabled(),
            // Whether "only my contacts" is an audience that means anything
            // here. Offering a member a choice that silently shares nothing
            // would be a worse answer than not offering it.
            'connections_enabled' => $this->connections->enabled(),
            'contact' => $this->contacts->forMember(Auth::user()),
        ]);
    }
}
