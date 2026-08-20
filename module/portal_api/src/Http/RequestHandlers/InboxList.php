<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Inbox;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/messages — everything addressed to me.
 *
 * Not only what the portal sent: webtrees' own contact forms and an
 * administrator's broadcast land in the same table, and a member should not
 * have to know which route a message took.
 */
class InboxList implements RequestHandlerInterface
{
    public function __construct(private readonly Inbox $inbox)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = Auth::user();

        return Json::response([
            'messages' => $this->inbox->messages($user),
            'unread'   => $this->inbox->unreadCount($user),
        ]);
    }
}
