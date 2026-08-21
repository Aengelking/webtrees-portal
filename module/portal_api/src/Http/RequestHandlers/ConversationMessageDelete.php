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
 * DELETE /api/v1/conversations/{id}/messages/{message} — for me, not for them.
 *
 * The other side keeps their copy. A message once read cannot be unsaid, and a
 * portal that let one member reach into another's transcript would be offering
 * something it cannot honestly promise.
 */
class ConversationMessageDelete implements RequestHandlerInterface
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = Auth::user();
        $id   = Validator::attributes($request)->integer('id', 0);

        $this->conversations->hideMessage(
            $user,
            $id,
            Validator::attributes($request)->integer('message', 0),
        );

        // The refreshed transcript, as the inbox's delete answers with the
        // refreshed list: the screen that asked is the screen that has to be
        // right afterwards.
        return Json::response($this->conversations->transcript($user, $id));
    }
}
