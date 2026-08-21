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
 * GET /api/v1/conversations/{id} — the transcript.
 *
 * Reading marks the other side's messages read, which is what opening a
 * conversation means. `?before=` asks for the page before a message id, so a
 * long exchange is walked backwards rather than loaded whole.
 */
class ConversationRead implements RequestHandlerInterface
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id     = Validator::attributes($request)->integer('id', 0);
        $before = Validator::queryParams($request)->integer('before', 0);

        return Json::response(
            $this->conversations->transcript(Auth::user(), $id, $before > 0 ? $before : null),
        );
    }
}
