<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\MemberMessages;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/messages/{id}/reply — answer a message in my own inbox.
 *
 * There is no `subject` in the body: a reply carries webtrees' `RE: ` applied
 * to the original, decided on the server. Somebody else's message id is a
 * `404`, the same as an id that never existed.
 */
class ReplyCreate implements RequestHandlerInterface
{
    public function __construct(private readonly MemberMessages $messages)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = Json::body($request);

        $this->messages->reply(
            Auth::user(),
            Validator::attributes($request)->integer('id', 0),
            Json::requiredString($body, 'body'),
            Validator::attributes($request)->string('client-ip', ''),
        );

        return Json::response(['status' => 'sent'], StatusCodeInterface::STATUS_ACCEPTED);
    }
}
