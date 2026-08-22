<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\PhotoPresenter;
use Engelking\Webtrees\PortalApi\Services\Photos;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/photos — a member adds a photograph of themselves.
 *
 * **Their own record and no other.** There is no id in this request and there
 * is not going to be one: a photograph is permission, and nobody can give
 * permission on somebody else's behalf. The record is the one the signed-in
 * account is linked to, and an account linked to nobody has nothing to add a
 * photograph to.
 */
class PhotoCreate implements RequestHandlerInterface
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

        $individual = $this->trees->linkedIndividual($tree, $user);

        if (!$individual instanceof Individual) {
            throw new ApiException(
                'no_linked_record',
                403,
                I18N::translate('Your account is not linked to anybody in the family tree.')
            );
        }

        $file = ($request->getUploadedFiles()['photo'] ?? null);

        if (!$file instanceof UploadedFileInterface) {
            throw ApiException::badRequest(I18N::translate('Please choose a photograph.'));
        }

        $result = $this->photos->upload($user, $individual, $file);

        return Json::response([
            // Read back through the presenter, so what the client is handed is
            // the same shape as every other photograph it has ever drawn.
            'photos'  => $this->presenter->gallery($individual, Auth::accessLevel($tree, $user)),
            // True where an unapproved edit was already waiting on this record:
            // the photograph waits with it rather than approving it. See
            // `Photos::record()`.
            'pending' => $result['pending'],
        ], 201);
    }
}
