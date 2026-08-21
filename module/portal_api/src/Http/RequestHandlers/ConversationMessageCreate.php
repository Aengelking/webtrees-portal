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
 * POST /api/v1/conversations/{id}/messages — say something.
 *
 * No subject: a conversation is one thread with one other person, and asking
 * for a heading on every line would be asking a member on a phone to name what
 * they are about to say.
 */
class ConversationMessageCreate implements RequestHandlerInterface
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $id   = Validator::attributes($request)->integer('id', 0);
        $body = Json::body($request);
        $text = Json::requiredString($body, 'body');
        $ip   = Validator::attributes($request)->string('client-ip', '');

        return Json::response(
            ['message' => $this->conversations->post(Auth::user(), $id, $text, $ip)],
            201,
        );
    }
}
