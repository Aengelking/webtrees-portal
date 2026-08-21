<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Engelking\Webtrees\PortalApi\Services\Member;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\RecordPresenter;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function max;
use function min;
use function mb_substr;
use function trim;

/**
 * GET /api/v1/members — the directory.
 *
 * Only members who set `visible_in_directory` appear. Their display name is
 * portal data they consented to publish; their linked genealogy record is
 * GEDCOM data and is nulled out whenever the caller may not see it.
 */
class MemberList implements RequestHandlerInterface
{
    private const int DEFAULT_PER_PAGE = 25;
    private const int MAX_PER_PAGE     = 100;

    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly RecordPresenter $presenter,
        private readonly MemberService $members,
        private readonly Connections $connections,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);

        $query    = mb_substr(trim(Validator::queryParams($request)->string('q', '')), 0, 100);
        $page     = max(1, Validator::queryParams($request)->integer('page', 1));
        $per_page = min(self::MAX_PER_PAGE, max(1, Validator::queryParams($request)->integer('per_page', self::DEFAULT_PER_PAGE)));

        $result = $this->members->visibleMembers($query, $page, $per_page);

        // Read once for the whole page rather than once per row. It is the
        // reason a "connect" button can sit on every line at all: deciding
        // "close family" walks the tree and is why contact details are not
        // here (see ContactDetails::visibleTo), but where these two stand is
        // one row of one table.
        $states = $this->connections->statesFor(Auth::user());

        $items = $result['items']
            ->map(fn (Member $member): array => $this->summary($member, $tree, $access_level, $states))
            ->all();

        return Json::response([
            'items'    => $items,
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $per_page,
            // So the list can leave the buttons off entirely rather than
            // offering something the endpoint would refuse.
            'connections_enabled' => $this->connections->enabled(),
        ]);
    }

    /**
     * @param array<int,array{status:string,id:int|null}> $states
     *
     * @return array<string,mixed>
     */
    private function summary(Member $member, Tree $tree, int $access_level, array $states): array
    {
        $individual = $this->trees->linkedIndividual($tree, $member->user);

        return [
            'id'           => $member->id,
            'display_name' => $member->display_name,
            'individual'   => $individual instanceof Individual
                ? $this->presenter->individualRef($individual, $access_level)
                : null,
            // Where the reader and this member stand, so the row can offer
            // the one thing that means something — ask, answer, or nothing,
            // because they are already in touch or because it is themselves.
            'connection'   => $member->user->id() === Auth::id()
                ? ['status' => 'self', 'id' => null]
                : ($states[$member->user->id()] ?? Connections::NOWHERE),
        ];
    }
}
