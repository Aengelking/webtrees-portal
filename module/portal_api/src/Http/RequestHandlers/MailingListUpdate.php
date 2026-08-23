<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\DistributionLists;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function is_array;

/**
 * PATCH /api/v1/me/mailing-lists — join one, or leave one.
 *
 * A list that is absent from the body is left alone, which is what makes this
 * a PATCH: the screen sends the one switch that moved, not all three, so two
 * members changing different lists at the same moment cannot undo each other.
 *
 * Only about the signed-in member. There is no endpoint anywhere in this
 * module for subscribing somebody else — putting an address on a family
 * mailing list is a decision that belongs to whoever reads that address.
 */
class MailingListUpdate implements RequestHandlerInterface
{
    public function __construct(private readonly DistributionLists $lists)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body    = Json::body($request);
        $changes = $body['lists'] ?? null;

        if (!is_array($changes) || $changes === []) {
            throw ApiException::badRequest();
        }

        return Json::response($this->lists->change(Auth::user(), $changes));
    }
}
