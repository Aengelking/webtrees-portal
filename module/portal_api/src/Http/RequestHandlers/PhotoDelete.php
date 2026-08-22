<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\PhotoPresenter;
use Engelking\Webtrees\PortalApi\Services\Photos;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Individual;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/v1/photos/{xref} — a member takes their photograph down.
 *
 * The half that makes the rest of it honest. A permission that cannot be
 * withdrawn is not a permission, so this exists for the same reason the upload
 * does — and refuses in the same way everything else here refuses: somebody
 * else's photograph is reported as not found, never as forbidden.
 */
class PhotoDelete implements RequestHandlerInterface
{
    public function __construct(
        private readonly Photos $photos,
        private readonly PhotoPresenter $presenter,
        private readonly PortalTreeService $trees,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = Auth::user();
        $tree = $this->trees->tree();

        $this->photos->remove($user, (string) $request->getAttribute('xref'));

        $individual = $this->trees->linkedIndividual($tree, $user);

        return Json::response([
            'photos' => $individual instanceof Individual
                ? $this->presenter->gallery($individual, Auth::accessLevel($tree, $user))
                : [],
        ]);
    }
}
