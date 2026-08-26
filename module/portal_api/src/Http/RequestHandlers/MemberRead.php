<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\AncestorTree;
use Engelking\Webtrees\PortalApi\Services\Connections;
use Engelking\Webtrees\PortalApi\Services\ContactDetails;
use Engelking\Webtrees\PortalApi\Services\Member;
use Engelking\Webtrees\PortalApi\Services\MemberInvitations;
use Engelking\Webtrees\PortalApi\Services\MemberMessages;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\Recognition;
use Engelking\Webtrees\PortalApi\Services\RecordPresenter;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/members/{id} — one directory member.
 *
 * `id` is the surrogate primary key of `portal_member_profile`, not a
 * webtrees user id and not an XREF.
 */
class MemberRead implements RequestHandlerInterface
{
    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly RecordPresenter $presenter,
        private readonly MemberService $members,
        private readonly ContactDetails $contacts,
        private readonly MemberMessages $messages,
        private readonly MemberInvitations $invitations,
        private readonly Connections $connections,
        private readonly Recognition $recognition,
        private readonly AncestorTree $ancestors,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);
        $id           = Validator::attributes($request)->integer('id');

        $viewer_user = Auth::user();

        // Listed in the directory, or connected to me. The second is the
        // narrower consent of the two — given by these two people to each
        // other — so a member who stayed out of the directory has a page for
        // the people they connected with and for nobody else.
        $member = $this->members->readableMember($id, $this->connections->disclosableUserIds($viewer_user));

        if (!$member instanceof Member) {
            throw ApiException::notFound();
        }

        $individual = $this->trees->linkedIndividual($tree, $member->user);
        $ref        = null;
        $detail     = null;
        $ancestors  = false;

        if ($individual instanceof Individual) {
            $viewer    = $this->trees->linkedIndividual($tree, $viewer_user);
            $ref       = $this->presenter->individualRef($individual, $access_level, $viewer);
            $detail    = $this->presenter->individualDetail($individual, $access_level, false, $viewer);
            $ancestors = $this->ancestors->hasParents($individual, $access_level);
        }

        // Asked here and nowhere else. Deciding "close family" means walking
        // the tree, so it is answered for one member on their own screen and
        // never once per row of the directory.
        $contact = $this->contacts->visibleTo(
            $member->user,
            $viewer_user,
            $tree,
            $access_level,
            $this->invitations->steps()
        );

        return Json::response([
            'id'                => $member->id,
            'display_name'      => $member->display_name,
            'individual'        => $ref,
            'individual_detail' => $detail,
            // Only where the record is not this reader's to read, and only
            // ever a face its subject uploaded here and a number the family
            // publishes. See `Recognition`.
            ...($ref === null ? $this->recognition->of($member->user, $access_level) : []),
            // Whether `/members/{id}/ancestors` has anything to show, so that
            // a record this reader may not open can still be a way into the
            // family above it — and so that the button is not a door onto an
            // empty room.
            //
            // It does disclose one thing: that this member has a record in the
            // tree. §2.66 keeps that sentence to itself in the general case,
            // and the family chose to spend it here — narrowly, to whoever may
            // already open this page, on a screen that may already be showing
            // this person's archive number. See §2.77.
            'ancestors'         => $ancestors,
            'contact'           => $contact,
            // Whether the *form* is worth showing. The endpoint checks again;
            // this only saves the member from a button that would refuse.
            'can_message'       => $this->messages->enabled() && $member->user->id() !== Auth::id(),
            // Where these two stand, so the page can offer the one button
            // that means something: ask, answer, or nothing at all because
            // they are already in touch.
            'connection'        => $member->user->id() === Auth::id()
                ? ['status' => 'self', 'id' => null]
                : $this->connections->stateWith($viewer_user, $member->user),
            'connections_enabled' => $this->connections->enabled(),
        ]);
    }
}
