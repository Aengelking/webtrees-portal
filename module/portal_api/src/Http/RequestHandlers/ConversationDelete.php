<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Conversations;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/v1/conversations/{id} — clear the whole thing, for me.
 *
 * The conversation itself is not deleted: the other side may still have it,
 * and a new message brings it back. That is the only meaning "delete for me"
 * can honestly have when two people share a transcript.
 */
class ConversationDelete implements RequestHandlerInterface
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = Auth::user();

        $this->conversations->hide($user, Validator::attributes($request)->integer('id', 0));

        return Json::response(['conversations' => $this->conversations->overview($user)]);
    }
}
