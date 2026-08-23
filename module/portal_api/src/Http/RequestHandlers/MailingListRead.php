<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\DistributionLists;
use Fisharebest\Webtrees\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/me/mailing-lists — which of the family's letters I get.
 *
 * A read that is quietly also a retry: an outstanding change gets another
 * attempt here, because a webtrees installation has no scheduled job to hang
 * one on and a member opening this screen is exactly the person who wants it
 * to have gone through. Bounded, so that an Exchange outage is never felt as a
 * portal that will not open — see `DistributionLists::outstanding()`.
 *
 * Answers about the account, not about a device or a tree: the addresses
 * behind the lists are never in the payload.
 */
class MailingListRead implements RequestHandlerInterface
{
    public function __construct(private readonly DistributionLists $lists)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Json::response($this->lists->state(Auth::user()));
    }
}
