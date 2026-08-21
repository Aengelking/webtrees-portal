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
 * POST /api/v1/conversations — open the conversation with a member.
 *
 * Creating and continuing are separate on purpose. This is the step the
 * directory rule guards, because it is the one that amounts to *finding*
 * somebody; writing into a conversation that already exists asks a different
 * question and gets a different answer (see `Conversations`).
 *
 * Idempotent: asking twice returns the same conversation. Two members opening
 * one at the same moment cannot end up with two, because the pair is unique in
 * the table.
 */
class ConversationCreate implements RequestHandlerInterface
{
    public function __construct(private readonly Conversations $conversations)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body      = Json::body($request);
        $member_id = Json::requiredInt($body, 'member_id');

        return Json::response(
            ['conversation' => $this->conversations->with(Auth::user(), $member_id)],
        );
    }
}
