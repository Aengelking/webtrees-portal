<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function is_string;
use function trim;

/**
 * POST /api/v1/connections — connect with somebody, one of three ways.
 *
 * `code` is the one that was scanned off somebody's screen, and it connects
 * at once: showing it was the consent. `reference` and `member_id` both only
 * *ask* — the other member answers. Exactly one of the three, because "which
 * did you mean" is not a question this endpoint should have to answer.
 *
 * The token arrives in the body rather than in the path for the same reason
 * an invitation's does: a webserver log, a proxy and every outgoing `Referer`
 * keep a URL, and none of them keep a body.
 */
class ConnectionCreate implements RequestHandlerInterface
{
    public function __construct(private readonly Connections $connections)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = Json::body($request);
        $user = Auth::user();

        $code      = $this->string($body['code'] ?? null);
        $reference = $this->string($body['reference'] ?? null);
        $member_id = (int) ($body['member_id'] ?? 0);

        $given = ($code === '' ? 0 : 1) + ($reference === '' ? 0 : 1) + ($member_id === 0 ? 0 : 1);

        if ($given !== 1) {
            throw ApiException::badRequest();
        }

        $result = match (true) {
            $code !== ''      => $this->connections->connectWithCode($user, $code),
            $reference !== '' => $this->connections->requestByReference($user, $reference),
            default           => $this->connections->requestByMember($user, $member_id),
        };

        return Json::response($result, StatusCodeInterface::STATUS_CREATED);
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
