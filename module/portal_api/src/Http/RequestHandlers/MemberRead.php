<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\Member;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Engelking\Webtrees\PortalApi\Services\RecordPresenter;
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
            $detail = $this->presenter->individualDetail($individual, $access_level);
        }

        return Json::response([
            'id'                => $member->id,
            'display_name'      => $member->display_name,
            'individual'        => $ref,
            'individual_detail' => $detail,
        ]);
    }
}
