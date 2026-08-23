<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\RecordPresenter;
use Engelking\Webtrees\PortalApi\Services\TreeSearch;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Validator;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function max;
use function mb_substr;
use function min;
use function trim;

/**
 * GET /api/v1/search — looking through the tree.
 *
 * Three ways of asking, one shape of answer: `q` for a name or a reference
 * number, `surname` for everybody filed under one name, `place` for everybody
 * with an event somewhere. They are alternatives rather than filters that
 * combine — a member arrives here either by typing or by tapping an index
 * entry, never both — and the first one given wins.
 *
 * Every person in the answer has been through `SearchConsent` on top of
 * webtrees' own access level. See that class for why a search needs a second
 * rule when tapping through a family does not.
 */
class SearchList implements RequestHandlerInterface
{
    private const int DEFAULT_PER_PAGE = 25;
    private const int MAX_PER_PAGE     = 100;

    /** Long enough for "Maria Elisabeth von Beispielhausen", short enough not to be a payload. */
    private const int MAX_QUERY = 100;

    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly RecordPresenter $presenter,
        private readonly TreeSearch $search,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);

        $query    = $this->parameter($request, 'q');
        $surname  = $this->parameter($request, 'surname');
        $place    = $this->parameter($request, 'place');
        $page     = max(1, Validator::queryParams($request)->integer('page', 1));
        $per_page = min(
            self::MAX_PER_PAGE,
            max(1, Validator::queryParams($request)->integer('per_page', self::DEFAULT_PER_PAGE))
        );

        $result = match (true) {
            $surname !== '' => $this->search->bySurname($surname, $access_level, $page, $per_page),
            $place !== ''   => $this->search->byPlace($place, $access_level, $page, $per_page),
            default         => $this->search->people($query, $access_level, $page, $per_page),
        };

        // The reader's own record, so every card can say how they stand to the
        // person on it. Fetched here rather than inside the presenter because
        // one page of results is one lookup, not twenty-five.
        $viewer = $this->trees->linkedIndividual($tree, Auth::user());

        return Json::response([
            'items'    => $this->presenter->individualRefs(
                new Collection($result['items']),
                $access_level,
                $viewer
            ),
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $per_page,
            // So the screen can say "showing the first few hundred" rather
            // than letting a member believe a truncated list is the answer.
            'truncated' => $result['truncated'],
        ]);
    }

    private function parameter(ServerRequestInterface $request, string $name): string
    {
        return mb_substr(trim(Validator::queryParams($request)->string($name, '')), 0, self::MAX_QUERY);
    }
}
