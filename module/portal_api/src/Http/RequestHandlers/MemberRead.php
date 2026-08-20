<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\ContactDetails;
use Engelking\Webtrees\PortalApi\Services\Member;
use Engelking\Webtrees\PortalApi\Services\MemberInvitations;
use Engelking\Webtrees\PortalApi\Services\MemberMessages;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
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
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);
        $id           = Validator::attributes($request)->integer('id');

        $member = $this->members->visibleMember($id);

        if (!$member instanceof Member) {
            throw ApiException::notFound();
        }

        $individual = $this->trees->linkedIndividual($tree, $member->user);
        $ref        = null;
        $detail     = null;

        if ($individual instanceof Individual) {
            $ref    = $this->presenter->individualRef($individual, $access_level);
            $viewer = $this->trees->linkedIndividual($tree, Auth::user());
            $detail = $this->presenter->individualDetail($individual, $access_level, false, $viewer);
        }

        // Asked here and nowhere else. Deciding "close family" means walking
        // the tree, so it is answered for one member on their own screen and
        // never once per row of the directory.
        $contact = $this->contacts->visibleTo(
            $member->user,
            Auth::user(),
            $tree,
            $access_level,
            $this->invitations->steps()
        );

        return Json::response([
            'id'                => $member->id,
            'display_name'      => $member->display_name,
            'individual'        => $ref,
            'individual_detail' => $detail,
            'contact'           => $contact,
            // Whether the *form* is worth showing. The endpoint checks again;
            // this only saves the member from a button that would refuse.
            'can_message'       => $this->messages->enabled() && $member->user->id() !== Auth::id(),
        ]);
    }
}
