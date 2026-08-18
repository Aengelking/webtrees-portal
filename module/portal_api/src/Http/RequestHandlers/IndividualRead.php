<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\RecordPresenter;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/individuals/{xref} — one record, privacy-filtered.
 *
 * A record that does not exist and a record this member may not see both
 * produce the same 404. Distinguishing them would let anyone with an account
 * enumerate the tree and learn that a hidden person exists.
 */
class IndividualRead implements RequestHandlerInterface
{
    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly RecordPresenter $presenter,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);
        $xref         = Validator::attributes($request)->string('xref');

        $individual = Registry::individualFactory()->make($xref, $tree);

        if (!$individual instanceof Individual) {
            throw ApiException::notFound();
        }

        // The member's own record, so the response can say how they are
        // related to this person. Null when their account is not linked to one.
        $viewer = $this->trees->linkedIndividual($tree, Auth::user());

        $payload = $this->presenter->individualDetail($individual, $access_level, false, $viewer);

        if ($payload === null) {
            throw ApiException::notFound();
        }

        return Json::response($payload);
    }
}
