<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\TreeSearch;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/index — the surnames and the places, for reading down.
 *
 * A search answers a question the member already had. An index is for the
 * member who has not got one yet: it is the difference between a database and
 * something you can look through. Which surnames does this family have, and
 * where has it been — two lists that between them describe the whole archive
 * without naming a single living person who did not ask to be named.
 *
 * **Both lists in one response**, because both are built from one pass over
 * the records (see `TreeSearch`), and splitting them into two endpoints would
 * mean doing that pass twice for a screen that shows them side by side.
 */
class IndexRead implements RequestHandlerInterface
{
    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly TreeSearch $search,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);

        $surnames = $this->search->surnames($access_level);
        $places   = $this->search->places($access_level);

        return Json::response([
            'surnames' => $surnames['items'],
            'places'   => $places['items'],
            // True when the tree outgrew what one request will read. The
            // counts are then a floor rather than a total, and the screen
            // says so — a number that is quietly wrong is worse than no
            // number.
            'truncated' => $surnames['truncated'] || $places['truncated'],
        ]);
    }
}
