<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Inbox;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function array_key_exists;
use function is_bool;

/**
 * PATCH /api/v1/messages/{id} — mark one read, or unread again.
 *
 * Somebody else's message and one that does not exist are both `404`.
 */
class InboxUpdate implements RequestHandlerInterface
{
    public function __construct(private readonly Inbox $inbox)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = Json::body($request);
        $user = Auth::user();
        $id   = Validator::attributes($request)->integer('id', 0);

        if (!array_key_exists('read', $body) || !is_bool($body['read'])) {
            throw ApiException::badRequest();
        }

        if ($body['read']) {
            $this->inbox->markRead($user, $id);
        } else {
            $this->inbox->markUnread($user, $id);
        }

        return Json::response([
            'messages' => $this->inbox->messages($user),
            'unread'   => $this->inbox->unreadCount($user),
        ]);
    }
}
