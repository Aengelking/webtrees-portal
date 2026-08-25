<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\AncestorTree;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fisharebest\Webtrees\Auth;
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
 * The walk does not stop at somebody the member may not read: that rung comes
 * back as an anonymous placeholder and the line carries on above it, so the
 * archive's dead stay reachable through its living. `AncestorTree` is where
 * that rule is written down and argued for.
 *
 * **The root is the exception, and it is not one of the walk's rules.** A
 * record the member may not read is a 404 here, byte for byte the same one a
 * record that does not exist gets — as everywhere else in this API. A
 * placeholder carries no XREF, so no screen in the portal can ask for such a
 * pedigree; what the 404 refuses is somebody trying XREFs by hand to find out
 * which of them name a real person. The endpoint answers about a person the
 * reader already reached, and says nothing about anybody else.
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

        $viewer = $this->trees->linkedIndividual($tree, Auth::user());
        $people = $this->ancestors->build($individual, $access_level, $generations, $viewer);

        return Json::response([
            'generations' => min(max(1, $generations), AncestorTree::MAX_GENERATIONS),
            'people'      => $people,
        ]);
    }
}
