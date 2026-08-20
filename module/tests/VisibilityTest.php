<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InvitationAccept;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\Diagnosis;
use Engelking\Webtrees\PortalApi\Services\DiagnosisCheck;
use Engelking\Webtrees\PortalApi\Services\InvitationService;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Phase 8: how much of the tree a member can see.
 *
 * A different question from "whom may I invite", and the one more likely to
 * be quietly wider than intended. `Individual::canShowByType()` applies
 * relationship privacy only to an account that has *both* a linked record and
 * a `RELATIONSHIP_PATH_LENGTH` above zero; with either missing it falls
 * through to "show living people to members only", which for somebody signed
 * in means everybody alive.
 *
 * Neither is set by default and there is no site-wide or tree-wide default to
 * inspect, so the state is invisible until something reports it. These tests
 * are about the module doing the reporting and the setting.
 */
#[CoversNothing]
class VisibilityTest extends PortalTestCase
{
    private MemberService $members;

    protected function setUp(): void
    {
        parent::setUp();

        $this->members = Registry::container()->get(MemberService::class);
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, 'https://portal.example.test');
    }

    private function pathLength(User $user): int
    {
        return (int) $this->tree->getUserPreference($user, UserInterface::PREF_TREE_PATH_LENGTH);
    }

    /**
     * The `Tree` the services use, which is not the one this test holds.
     *
     * `Tree::getUserPreference()` fetches every preference for a user once
     * and caches them on the instance, and `setUserPreference()` updates only
     * that instance's copy. Two `Tree` objects for the same tree therefore
     * disagree until the next request. Harmless in webtrees, where a request
     * has one of them; a trap here, where a test writes through its own and
     * then asks a service that holds another.
     */
    private function portalTree(): \Fisharebest\Webtrees\Tree
    {
        return Registry::container()->get(PortalTreeService::class)->tree();
    }

    private function check(string $key): DiagnosisCheck
    {
        $check = Registry::container()->get(Diagnosis::class)->run()
            ->first(static fn (DiagnosisCheck $c): bool => $c->key === $key);

        self::assertInstanceOf(DiagnosisCheck::class, $check);

        return $check;
    }

    // -----------------------------------------------------------------
    // Reporting the state
    // -----------------------------------------------------------------

    /**
     * The default, spelled out. Nothing in webtrees says this anywhere, which
     * is the whole reason the check exists.
     */
    public function testUnrestrictedVisibilityIsReported(): void
    {
        $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_PATH_LENGTH, '0');

        $check = $this->check('visibility');

        self::assertSame(Diagnosis::WARNING, $check->status);
        self::assertStringContainsString('living person', $check->detail);
    }

    public function testAnAccountThatStillHasNoLimitIsCounted(): void
    {
        $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_PATH_LENGTH, '2');

        self::assertSame(Diagnosis::WARNING, $this->check('visibility')->status);

        // Written through the same instance the diagnosis will read from —
        // see portalTree().
        $this->members->applyPathLength($this->portalTree(), 2);

        self::assertSame(Diagnosis::OK, $this->check('visibility')->status);
    }

    /**
     * An account with no linked record cannot be limited at all — webtrees
     * measures the distance *from* that record, and its own UserEditAction
     * forces the value back to zero. It stays counted, and the "accounts with
     * no linked record" list is where it gets fixed.
     */
    public function testAnAccountWithNoRecordCannotBeLimited(): void
    {
        $lonely = $this->createUser('lonely', 'Ohne Datensatz', 'irgendwas', UserInterface::ROLE_MEMBER);
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_PATH_LENGTH, '2');

        self::assertSame(0, $this->members->applyPathLength($this->tree, 2));
        self::assertSame(0, $this->pathLength($lonely));
        self::assertSame(Diagnosis::WARNING, $this->check('visibility')->status);
    }

    // -----------------------------------------------------------------
    // Applying it
    // -----------------------------------------------------------------

    public function testApplyingTheLimitTouchesMembersAndNobodyElse(): void
    {
        $member  = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $editor  = $this->createUser('edith', 'Edith Editor', 'irgendwas', UserInterface::ROLE_EDITOR, 'X2');
        $manager = $this->createUser('mona', 'Mona Managerin', 'irgendwas', UserInterface::ROLE_MANAGER, 'X4');

        self::assertSame(1, $this->members->applyPathLength($this->tree, 2));

        self::assertSame(2, $this->pathLength($member));

        // The people who maintain the tree need all of it to do that.
        self::assertSame(0, $this->pathLength($editor));
        self::assertSame(0, $this->pathLength($manager));
    }

    public function testApplyingTwiceChangesNothingTheSecondTime(): void
    {
        $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');

        self::assertSame(1, $this->members->applyPathLength($this->tree, 2));
        self::assertSame(0, $this->members->applyPathLength($this->tree, 2));
    }

    public function testAnAdministratorIsNeverRestricted(): void
    {
        $admin = $this->createUser('chef', 'Die Chefin', 'irgendwas', UserInterface::ROLE_MEMBER, 'X1');
        $admin->setPreference(UserInterface::PREF_IS_ADMINISTRATOR, '1');

        self::assertSame(0, $this->members->applyPathLength($this->tree, 2));
        self::assertSame(0, $this->pathLength($admin));
    }

    // -----------------------------------------------------------------
    // New accounts
    // -----------------------------------------------------------------

    public function testAnInvitedAccountIsLimitedOnArrival(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_PATH_LENGTH, '2');

        $token = (new InvitationService())->create($this->tree, 'X4', 'Dieter Beispiel', '', null);

        $response = $this->api(
            InvitationAccept::class,
            RequestMethodInterface::METHOD_POST,
            body: [
                'token'     => $token,
                'username'  => 'dieter',
                'real_name' => 'Dieter Beispiel',
                'email'     => 'dieter@example.test',
                'password'  => 'correct-horse',
            ],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());

        $user = Registry::container()->get(\Fisharebest\Webtrees\Services\UserService::class)->findByUserName('dieter');

        self::assertInstanceOf(User::class, $user);
        self::assertSame(2, $this->pathLength($user));
    }

    /**
     * Zero means the administrator chose not to restrict. Writing a zero and
     * writing nothing are the same to webtrees, but only one of them reads as
     * a decision — so the module writes nothing.
     */
    public function testAnInvitedAccountIsNotLimitedWhenTheSettingIsZero(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_PATH_LENGTH, '0');

        $token = (new InvitationService())->create($this->tree, 'X4', 'Dieter Beispiel', '', null);

        $this->api(
            InvitationAccept::class,
            RequestMethodInterface::METHOD_POST,
            body: [
                'token'     => $token,
                'username'  => 'dieter',
                'real_name' => 'Dieter Beispiel',
                'email'     => 'dieter@example.test',
                'password'  => 'correct-horse',
            ],
            headers: $this->csrfHeader(),
        );

        $user = Registry::container()->get(\Fisharebest\Webtrees\Services\UserService::class)->findByUserName('dieter');

        self::assertInstanceOf(User::class, $user);
        self::assertSame(0, $this->pathLength($user));
    }
}
