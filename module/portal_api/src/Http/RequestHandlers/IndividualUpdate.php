<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\GedcomEditor;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\RecordPresenter;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PUT /api/v1/me/individual — propose a change to one's own record.
 *
 * The record being edited is **never** taken from the request. It is whichever
 * individual webtrees links to the authenticated account, read fresh on every
 * call. There is no parameter an attacker could point at someone else, which
 * is the whole of the authorisation model and the reason it is short.
 *
 * The change is queued for an editor. Nothing in this path can write to the
 * tree directly.
 */
class IndividualUpdate implements RequestHandlerInterface
{
    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly RecordPresenter $presenter,
        private readonly GedcomEditor $editor,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);
        $user         = Auth::user();

        $individual = $this->trees->linkedIndividual($tree, $user);

        if (!$individual instanceof Individual) {
            throw new ApiException(
                'no_linked_record',
                StatusCodeInterface::STATUS_NOT_FOUND,
                I18N::translate('Your account is not linked to a record in the family tree.')
            );
        }

        $changes = Json::body($request);

        if ($changes === []) {
            throw ApiException::badRequest(I18N::translate('There was nothing to change.'));
        }

        // Photograph the approved record *before* editing it. `updateRecord()`
        // mutates the object in place and sets its pending GEDCOM, and the
        // record factory hands back that same cached instance — so re-reading
        // afterwards would show the member their own proposal as though it
        // were live. The next page load, with a fresh cache, would then
        // disagree with it.
        $approved = $this->presenter->individualDetail($individual, $access_level, true);

        $this->editor->applyToOwnRecord($individual, $changes);

        if ($approved !== null) {
            // True by definition now, and computed before the write.
            $approved['pending_change'] = true;
        }

        return Json::response([
            'status'         => 'pending_approval',
            'pending_change' => true,
            'individual'     => $approved,
        ], StatusCodeInterface::STATUS_ACCEPTED);
    }
}
