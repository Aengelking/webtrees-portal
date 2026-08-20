<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Inbox;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/v1/messages/{id} — throw one away.
 *
 * Really deleted, from webtrees' own table, because an inbox nobody can clear
 * is a nuisance rather than a feature. The read state follows through the
 * foreign key.
 */
class InboxDelete implements RequestHandlerInterface
{
    public function __construct(private readonly Inbox $inbox)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = Auth::user();

        $this->inbox->delete($user, Validator::attributes($request)->integer('id', 0));

        return Json::response([
            'messages' => $this->inbox->messages($user),
            'unread'   => $this->inbox->unreadCount($user),
        ]);
    }
}
