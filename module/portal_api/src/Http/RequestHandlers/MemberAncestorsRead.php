<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\AncestorTree;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Engelking\Webtrees\PortalApi\Services\Member;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/members/{id}/ancestors — the pedigree of somebody in the
 * directory, or of somebody you are connected with.
 *
 * ## Why this exists beside `/individuals/{xref}/ancestors` (§2.77)
 *
 * §2.75 made the pedigree walk *through* people the reader may not read: they
 * become anonymous placeholders and the line carries on above them, so the
 * archive's dead stay reachable through the family's living. What it did not
 * do is give such a person a door of their own. On a member's page — reached
 * from Kontakte, where the reader is looking at somebody who consented to be
 * found — a closed genealogy record meant no record view and therefore no way
 * in at all. The member could see that their cousin exists, and nothing of
 * where she comes from, even though every name in that pedigree would have
 * been either a placeholder or somebody long dead.
 *
 * **The entry is a member id, and that is the whole of the design.** The other
 * endpoint refuses a root the reader may not see, and has to: an XREF is a
 * guessable string, and answering differently for one would turn the endpoint
 * into a way of finding out which XREFs name a real person. A portal member id
 * is not that. It only opens where `readableMember()` opens — the member put
 * themselves in the directory, or the two of them connected, which is the
 * narrower consent of the two because both gave it — and it is the same gate
 * the member's own page already passes through. So this discloses nothing that
 * `GET /members/{id}` did not, except the shape of a family above somebody who
 * agreed to be found.
 *
 * The pedigree itself is `AncestorTree`'s, unchanged and at the reader's own
 * access level. The root is very often a placeholder — it is a living person
 * whose record is closed, which is why this route was needed — and the client
 * already has their directory name from the member page.
 */
class MemberAncestorsRead implements RequestHandlerInterface
{
    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly MemberService $members,
        private readonly Connections $connections,
        private readonly AncestorTree $ancestors,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);
        $id           = Validator::attributes($request)->integer('id');
        $generations  = Validator::queryParams($request)
            ->integer('generations', AncestorTree::DEFAULT_GENERATIONS);

        $viewer_user = Auth::user();

        $member = $this->members->readableMember($id, $this->connections->disclosableUserIds($viewer_user));

        if (!$member instanceof Member) {
            throw ApiException::notFound();
        }

        $individual = $this->trees->linkedIndividual($tree, $member->user);

        // No linked record and a member who is not readable produce the same
        // 404, as they do on every other endpoint: which of the two it is, is
        // exactly the sentence §2.66 keeps to itself.
        if (!$individual instanceof Individual) {
            throw ApiException::notFound();
        }

        $viewer = $this->trees->linkedIndividual($tree, $viewer_user);
        $people = $this->ancestors->build($individual, $access_level, $generations, $viewer);

        return Json::response([
            'generations' => min(max(1, $generations), AncestorTree::MAX_GENERATIONS),
            'people'      => $people,
        ]);
    }
}
