<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\AncestorTree;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/individuals/{xref}/ancestors — several generations at once.
 *
 * The portal could build this by walking `/individuals/{xref}` from parent to
 * parent, but four generations is fifteen requests, which is slow on a phone
 * and unkind to a shared host.
 *
 * Nothing here is visible that tapping through would not also reach: the walk
 * runs at the member's access level and stops at anyone they may not see. A
 * record that does not exist and one they may not see produce the same 404,
 * as everywhere else.
 */
class AncestorsRead implements RequestHandlerInterface
{
    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly AncestorTree $ancestors,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);
        $xref         = Validator::attributes($request)->string('xref');
        $generations  = Validator::queryParams($request)
            ->integer('generations', AncestorTree::DEFAULT_GENERATIONS);

        $individual = Registry::individualFactory()->make($xref, $tree);

        if (!$individual instanceof Individual || !$individual->canShow($access_level)) {
            throw ApiException::notFound();
        }

        $people = $this->ancestors->build($individual, $access_level, $generations);

        return Json::response([
            'generations' => min(max(1, $generations), AncestorTree::MAX_GENERATIONS),
            'people'      => $people,
        ]);
    }
}
