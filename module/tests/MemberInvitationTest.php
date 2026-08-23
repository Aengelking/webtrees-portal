<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberInvitationCreate;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberInvitationDelete;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MemberInvitationList;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\CloseFamily;
use Engelking\Webtrees\PortalApi\Services\InvitationService;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;

use function array_column;
use function strpos;
use function substr;

/**
 * Phase 7: a member invites their own close family.
 *
 * The fixture is doing most of the work here, so it is worth naming. Anna
 * (X1) is the member. One step away: her parents Emil and Bertha, both long
 * dead; her brother Dieter, alive; her sister Clara, alive but marked
 * `RESN confidential` and therefore invisible to a member. Two steps away:
 * four grandparents, all dead, one of them also confidential. Fritz (X6) is
 * alive and in the tree but connected to nobody.
 *
 * So of everyone Anna can reach, exactly one person can be invited — and the
 * three reasons the others cannot are the three rules this phase is made of.
 */
#[CoversNothing]
class MemberInvitationTest extends PortalTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, 'https://portal.example.test');
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_INVITES, '1');
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_INVITE_STEPS, '2');
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_INVITE_QUOTA, '3');

        $this->login($this->anna);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function list(): ResponseInterface
    {
        return $this->api(MemberInvitationList::class);
    }

    private function invite(string $xref, string $email = ''): ResponseInterface
    {
        return $this->api(
            MemberInvitationCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['xref' => $xref, 'email' => $email],
            headers: $this->csrfHeader(),
        );
    }

    /**
     * @return array<int,string>
     */
    private function candidateXrefs(): array
    {
        return array_column($this->json($this->list())['candidates'], 'xref');
    }

    private function closeFamily(): CloseFamily
    {
        return Registry::container()->get(CloseFamily::class);
    }

    // -----------------------------------------------------------------
    // Who is offered
    // -----------------------------------------------------------------

    public function testOnlyTheLivingVisibleRelativeIsOffered(): void
    {
        $candidates = $this->candidateXrefs();

        // Dieter, her brother.
        self::assertSame(['X4'], $candidates);
    }

    /**
     * Clara is Anna's sister and `RESN confidential`. A member cannot see her
     * anywhere else in the portal, and this screen is not the exception.
     */
    public function testARelativeTheMemberMayNotSeeIsNotOffered(): void
    {
        self::assertNotContains('X3', $this->candidateXrefs());
        self::assertStringNotContainsString('Clara', $this->raw($this->list()));
    }

    public function testTheDeadAreNotOffered(): void
    {
        $candidates = $this->candidateXrefs();

        // Her parents and all four grandparents.
        foreach (['X2', 'X5', 'X7', 'X8', 'X9', 'X10'] as $xref) {
            self::assertNotContains($xref, $candidates);
        }
    }

    // -----------------------------------------------------------------
    // Somebody who keeps the tree
    // -----------------------------------------------------------------

    /**
     * Signed in as somebody who keeps the tree, linked to Anna's own record.
     *
     * Linked on purpose: it proves the distance rule is what lifts, rather
     * than the measurement quietly failing for want of a starting point.
     */
    private function signInAsEditor(): void
    {
        Auth::logout();

        $this->login($this->createUser('edith', 'Edith Beispiel', 'geheim', UserInterface::ROLE_EDITOR, 'X1'));
    }

    /**
     * An editor may invite anybody they can see.
     *
     * The three hedges on a member's invitation — a distance, a quota, and the
     * switch — are about somebody being *trusted* to decide who is family. An
     * editor already decides: they open the control panel and invite anybody
     * in the tree. Applying the distance here would not stop them, only stop
     * them doing it from the screen they were already looking at.
     */
    public function testAnEditorIsOfferedEverybodyTheyCanSee(): void
    {
        $this->signInAsEditor();

        $offered = $this->candidateXrefs();

        // Fritz is nowhere near Anna's close family, and living.
        self::assertContains('X6', $offered);
        self::assertContains('X4', $offered);
    }

    public function testAnEditorIsToldTheScreenIsASearch(): void
    {
        $this->signInAsEditor();

        self::assertSame('anyone', $this->json($this->list())['scope']);
    }

    public function testAMemberIsToldTheScreenIsAWheel(): void
    {
        self::assertSame('close_family', $this->json($this->list())['scope']);
    }

    /** The dead, the already-invited and the already-accounted-for are out for everybody. */
    public function testAnEditorIsNotOfferedSomebodyAnInvitationWouldNotHelp(): void
    {
        $this->signInAsEditor();

        $offered = $this->candidateXrefs();

        // Dead.
        self::assertNotContains('X2', $offered);
        // Confidential, so not visible even to this account's access level.
        self::assertNotContains('X3', $offered);
        // Edith's own linked record already has an account: hers.
        self::assertNotContains('X1', $offered);
    }

    public function testAnEditorCanIssueAnInvitationToSomebodyDistant(): void
    {
        $this->signInAsEditor();

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $this->invite('X6', 'fritz@example.test')->getStatusCode());
    }

    /**
     * The quota is a member's, and an editor has none.
     *
     * Set to nothing at all: a member would be refused before naming anybody.
     */
    public function testAnEditorHasNoQuota(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_INVITE_QUOTA, '0');
        $this->signInAsEditor();

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $this->invite('X6', 'fritz@example.test')->getStatusCode());
    }

    /** And the switch is still a switch: off is off for everybody. */
    public function testTheSwitchStillStopsAnEditor(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_INVITES, '0');
        $this->signInAsEditor();

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $this->invite('X6', 'fritz@example.test')->getStatusCode());
    }

    // -----------------------------------------------------------------
    // The offer on a person's own page
    // -----------------------------------------------------------------

    /**
     * The record answers the question, so the screen does not have to hold a
     * list to work it out.
     */
    public function testARecordSaysWhetherThisReaderCouldInviteThem(): void
    {
        self::assertTrue($this->invitable('X4'));

        // Dead, and Anna's mother: near enough, and no use to invite.
        self::assertFalse($this->invitable('X2'));
    }

    public function testARecordMakesNoOfferOnceTheQuotaIsSpent(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_INVITE_QUOTA, '0');

        // A button the endpoint would refuse is worse than no button.
        self::assertFalse($this->invitable('X4'));
    }

    public function testARecordMakesNoOfferWhereTheFamilySwitchedItOff(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_INVITES, '0');

        self::assertFalse($this->invitable('X4'));
    }

    public function testARecordOffersAnEditorSomebodyTooDistantForAMember(): void
    {
        $this->signInAsEditor();

        self::assertTrue($this->invitable('X6'));
    }

    private function invitable(string $xref): bool
    {
        $response = $this->api(IndividualRead::class, attributes: ['xref' => $xref]);

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        return $this->json($response)['invitable'];
    }

    public function testTheRelationshipIsNamedSoTheMemberKnowsWhoTheyArePicking(): void
    {
        $candidate = $this->json($this->list())['candidates'][0];

        self::assertSame('X4', $candidate['xref']);
        self::assertSame('Dieter Beispiel', $candidate['name']);
        self::assertNotNull($candidate['relationship']);
    }

    /**
     * Somebody who already has an account is dropped from the list, and
     * nothing says why.
     *
     * Silence is the deliberate part. "Your brother already has an account"
     * would disclose something the portal otherwise treats as his to share —
     * §2.7's whole point is that appearing in the member directory is consent.
     */
    public function testSomebodyWhoAlreadyHasAnAccountIsSilentlyDropped(): void
    {
        $this->createUser('dieter', 'Dieter Beispiel', 'anderes-pferd', UserInterface::ROLE_MEMBER, 'X4');

        $response = $this->list();

        self::assertSame([], $this->json($response)['candidates']);
        self::assertStringNotContainsString('X4', $this->raw($response));
    }

    public function testSomebodyAlreadyInvitedIsNotOfferedTwice(): void
    {
        $this->invite('X4');

        self::assertSame([], $this->candidateXrefs());
    }

    // -----------------------------------------------------------------
    // How far "close" reaches
    // -----------------------------------------------------------------

    /**
     * The reach is this module's own setting, measured by walking — not
     * webtrees' idea of who is visible.
     *
     * That distinction is the reason `CloseFamily` exists at all:
     * `Individual::canShowByType()` applies relationship privacy only when a
     * user has a per-user `RELATIONSHIP_PATH_LENGTH` *and* a linked record,
     * and otherwise shows every living person to every member. Scoping
     * invitations to "whom can I see" would therefore hand most members the
     * whole tree.
     */
    public function testOneStepReachesParentsAndSiblingsAndNoFurther(): void
    {
        $anna = Registry::individualFactory()->make('X1', $this->tree);

        // Auth::PRIV_USER — a member. Passed explicitly rather than read from
        // the session, because what is under test is the walk, not who is
        // signed in.
        $within = $this->closeFamily()->within($anna, Auth::PRIV_USER, 1);

        self::assertArrayHasKey('X4', $within, 'Her brother is one step away.');
        self::assertArrayHasKey('X2', $within, 'Her mother is one step away.');
        self::assertArrayNotHasKey('X10', $within, 'Her grandfather is two steps away.');
    }

    public function testTwoStepsReachesGrandparents(): void
    {
        $anna = Registry::individualFactory()->make('X1', $this->tree);

        $within = $this->closeFamily()->within($anna, Auth::PRIV_USER, 2);

        self::assertArrayHasKey('X10', $within, 'Her grandfather is two steps away.');
    }

    public function testSomebodyConnectedToNobodyIsNeverReached(): void
    {
        $anna = Registry::individualFactory()->make('X1', $this->tree);

        // Fritz is alive and in the tree, and has no family links at all.
        self::assertArrayNotHasKey('X6', $this->closeFamily()->within($anna, Auth::PRIV_USER, CloseFamily::MAX_STEPS));
    }

    // -----------------------------------------------------------------
    // Issuing one
    // -----------------------------------------------------------------

    public function testInvitingACloseRelativeReturnsALinkOnce(): void
    {
        $response = $this->invite('X4', 'dieter@example.test');
        $body     = $this->json($response);

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());
        self::assertStringStartsWith('https://portal.example.test/invitation?token=', $body['link']);
        self::assertSame('Dieter Beispiel', $body['invitation']['name']);

        // Issued by Anna, and recorded as hers.
        $row = DB::table(InvitationService::TABLE)->first();

        self::assertNotNull($row);
        self::assertSame($this->anna->id(), (int) $row->created_by);
        self::assertSame('X4', $row->xref);
    }

    /**
     * The candidate list is a convenience for the screen; this is the check.
     * A client can post whatever XREF it likes.
     */
    public function testAnXrefThatWasNeverOfferedIsRefused(): void
    {
        foreach (['X3', 'X6', 'X99'] as $xref) {
            $response = $this->invite($xref);

            self::assertSame(
                StatusCodeInterface::STATUS_FORBIDDEN,
                $response->getStatusCode(),
                $xref . ' was accepted.'
            );
            self::assertSame('not_allowed', $this->json($response)['error']);
        }

        self::assertSame(0, DB::table(InvitationService::TABLE)->count());
    }

    /**
     * Hidden, too distant, already invited, already an account holder and not
     * a person at all all produce the same refusal, so posting an XREF is not
     * a way to find out which of those is true.
     */
    public function testEveryRefusalLooksTheSame(): void
    {
        $hidden  = $this->raw($this->invite('X3'));
        $absent  = $this->raw($this->invite('X99'));
        $distant = $this->raw($this->invite('X6'));

        self::assertSame($hidden, $absent);
        self::assertSame($hidden, $distant);
    }

    public function testTheQuotaIsEnforced(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_INVITE_QUOTA, '1');

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $this->invite('X4')->getStatusCode());

        $response = $this->invite('X4');

        self::assertSame(StatusCodeInterface::STATUS_CONFLICT, $response->getStatusCode());
        self::assertSame('quota_reached', $this->json($response)['error']);
        self::assertSame(1, DB::table(InvitationService::TABLE)->count());
    }

    public function testTheRemainingCountFalls(): void
    {
        self::assertSame(3, $this->json($this->list())['remaining']);

        $this->invite('X4');

        self::assertSame(2, $this->json($this->list())['remaining']);
    }

    public function testTheWholeFacilityCanBeSwitchedOff(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_INVITES, '0');

        $overview = $this->json($this->list());

        // Answers rather than refuses: a screen that can say "your family has
        // this switched off" beats a 403 at a member who did nothing wrong.
        self::assertFalse($overview['enabled']);
        self::assertSame([], $overview['candidates']);

        $response = $this->invite('X4');

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $response->getStatusCode());
        self::assertSame(0, DB::table(InvitationService::TABLE)->count());
    }

    public function testAnAccountWithNoRecordOfItsOwnCannotInviteAnybody(): void
    {
        $lonely = $this->createUser('lonely', 'Ohne Datensatz', 'irgendwas', UserInterface::ROLE_MEMBER);
        $this->login($lonely);

        $overview = $this->json($this->list());

        self::assertFalse($overview['linked']);
        self::assertSame([], $overview['candidates']);

        self::assertSame(StatusCodeInterface::STATUS_FORBIDDEN, $this->invite('X4')->getStatusCode());
    }

    public function testAMemberCannotInviteWithoutSigningIn(): void
    {
        Auth::logout();

        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $this->list()->getStatusCode());
        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $this->invite('X4')->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Withdrawing one
    // -----------------------------------------------------------------

    public function testAMemberCanWithdrawTheirOwn(): void
    {
        $id = $this->json($this->invite('X4'))['invitation']['id'];

        $response = $this->api(
            MemberInvitationDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame([], $this->json($response)['invitations']);
        self::assertSame(0, DB::table(InvitationService::TABLE)->count());

        // ...and the relative is on offer again.
        self::assertSame(['X4'], $this->candidateXrefs());
    }

    /**
     * Somebody else's invitation is "not found" — the same answer as one that
     * does not exist. A member has no business learning which.
     */
    public function testAMemberCannotWithdrawSomebodyElses(): void
    {
        $invitations = new InvitationService();
        $admin       = $this->createUser('chef', 'Die Chefin', 'noch-ein-pferd', UserInterface::ROLE_MANAGER);

        $invitations->create($this->tree, 'X4', 'Dieter Beispiel', '', $admin);

        $id = (int) DB::table(InvitationService::TABLE)->value('id');

        $response = $this->api(
            MemberInvitationDelete::class,
            RequestMethodInterface::METHOD_DELETE,
            attributes: ['id' => $id],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
        self::assertSame(1, DB::table(InvitationService::TABLE)->count());
    }

    public function testAMemberOnlySeesTheirOwnInvitations(): void
    {
        $invitations = new InvitationService();
        $admin       = $this->createUser('chef', 'Die Chefin', 'noch-ein-pferd', UserInterface::ROLE_MANAGER);

        $invitations->create($this->tree, 'X4', 'Dieter Beispiel', 'dieter@example.test', $admin);

        self::assertSame([], $this->json($this->list())['invitations']);
    }

    // -----------------------------------------------------------------
    // What the screen discloses
    // -----------------------------------------------------------------

    /**
     * The candidate list is the walk the member's own page already does, at
     * the same access level, stopping at the same limit. Opening this screen
     * must not tell them about a single person they could not already reach.
     */
    public function testTheScreenDisclosesNobodyNew(): void
    {
        $raw = $this->raw($this->list());

        foreach (['Clara', 'Fritz', 'Otto', 'Ida'] as $name) {
            self::assertStringNotContainsString($name, $raw, $name . ' appears in the invite screen.');
        }
    }

    public function testAMemberIsNeverGivenSomebodyElsesInvitationLink(): void
    {
        $token = (new InvitationService())->create($this->tree, 'X4', 'Dieter Beispiel', '', null);

        self::assertStringNotContainsString($token, $this->raw($this->list()));
    }

    public function testTheLinkIsNotRecoverableAfterwards(): void
    {
        $link = $this->json($this->invite('X4'))['link'];
        $token = substr($link, strpos($link, 'token=') + 6);

        // Everything the member can ask for later, and none of it has the
        // token in it. Only a hash of it was stored.
        self::assertStringNotContainsString($token, $this->raw($this->list()));
        self::assertNull(
            DB::table(InvitationService::TABLE)->where('token_hash', '=', $token)->first(),
            'The raw token is stored as though it were the hash.'
        );
    }
}
