<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Engelking\Webtrees\PortalApi\Services\MemberInvitations;
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
        private readonly MemberInvitations $invitations,
        private readonly Connections $connections,
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

        // Whether this reader could invite this person, asked here rather
        // than worked out on the screen from a list of candidates.
        //
        // It used to be the latter, which was fine while the list was a
        // member's close family and wrong the moment it became an editor's
        // whole tree: the screen would have had to hold thousands of records
        // to answer one question about one of them. And this is the same rule
        // the endpoint that issues the invitation applies, so the offer and
        // the answer cannot disagree.
        //
        // Its absence stays as uninformative as it was: dead, already an
        // account holder, already invited and too distant are all `false`,
        // exactly so that nobody can learn which by looking.
        $payload['invitable'] = $this->invitations->invitable(Auth::user(), $xref) instanceof Individual;

        // And whether this page may offer to connect with them — which is a
        // question about this reader and this record, and deliberately not a
        // question about whether the person has an account. `open` is the
        // answer for a member who stayed out of the directory, for a relative
        // with no account at all, and for a request already sent and not yet
        // answered; the three are indistinguishable here on purpose. See
        // `Connections::recordState`.
        $payload['connection'] = $this->connections->recordState(Auth::user(), $individual);

        return Json::response($payload);
    }
}
