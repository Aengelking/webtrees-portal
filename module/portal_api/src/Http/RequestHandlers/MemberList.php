<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Member;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\RecordPresenter;
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

        $items = $result['items']
            ->map(fn (Member $member): array => $this->summary($member, $tree, $access_level))
            ->all();

        return Json::response([
            'items'    => $items,
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $per_page,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(Member $member, Tree $tree, int $access_level): array
    {
        $individual = $this->trees->linkedIndividual($tree, $member->user);

        return [
            'id'           => $member->id,
            'display_name' => $member->display_name,
            'individual'   => $individual instanceof Individual
                ? $this->presenter->individualRef($individual, $access_level)
                : null,
        ];
    }
}
