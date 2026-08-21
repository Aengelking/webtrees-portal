<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Conversations;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/conversations — every conversation I am part of.
 *
 * Not the same list as `/messages`. That one is webtrees' inbox and holds
 * everything addressed to this member from anywhere, including its own contact
 * form; this one holds the exchanges the portal itself keeps a transcript of.
 */
class ConversationList implements RequestHandlerInterface
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response(['conversations' => $this->conversations->overview(Auth::user())]);
    }
}
