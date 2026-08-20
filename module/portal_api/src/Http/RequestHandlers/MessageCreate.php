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
 * POST /api/v1/members/{id}/message — write to another member.
 *
 * The recipient is a portal member id, and only a member who put themselves
 * in the directory can be one. Somebody who stayed out is reported as not
 * found, the same as an id that never existed.
 */
class MessageCreate implements RequestHandlerInterface
{
    public function __construct(private readonly MemberMessages $messages)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = Json::body($request);

        $this->messages->send(
            Auth::user(),
            Validator::attributes($request)->integer('id', 0),
            Json::requiredString($body, 'subject'),
            Json::requiredString($body, 'body'),
            Validator::attributes($request)->string('client-ip', ''),
        );

        return Json::response(['status' => 'sent'], StatusCodeInterface::STATUS_ACCEPTED);
    }
}
